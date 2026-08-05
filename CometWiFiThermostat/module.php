<?php

declare(strict_types=1);

require_once __DIR__ . '/../.libs/CWIFI_Topics.php';
require_once __DIR__ . '/../.libs/CWIFI_Registers.php';
require_once __DIR__ . '/../.libs/CWIFI_MQTT.php';

/**
 * Eurotronic Comet WiFi — ein Thermostat je Instanz.
 *
 * Hängt als Kind unter einem MQTT Client (oder MQTT Server), der mit dem Broker verbunden
 * ist, auf den die Geräte per DNS umgeleitet wurden. Siehe README für den Aufbau.
 *
 * Grundsatz: Nur belegte Register werden gedeutet. Alles andere landet unverändert im
 * Rohdatenpfad. Siehe docs/protokoll.md.
 */
class CometWiFiThermostat extends IPSModule
{
    use CWIFI_MQTT;

    /** Inhaltsversion des „Neu in Version"-Panels — nicht die Modulversion. */
    private const NEWS_VERSION = '0.1';

    private const ATTR_SEEN_NEWS  = 'SeenNews';
    private const ATTR_HINT_GONE  = 'ReviewHintDismissed';

    private const BUFFER_PENDING  = 'PendingSetpoint';
    private const BUFFER_RECENT   = 'RecentMessages';

    /** So lange nach dem Senden gilt eine Abweichung als „nicht übernommen". */
    private const PENDING_GRACE_SECONDS = 120;

    /** Kleinstes zulässiges Abfrageintervall in Minuten — Batterieschutz. */
    private const MIN_POLL_MINUTES = 15;

    private const RECENT_LIMIT = 30;

    /* ================================================================== Lebenszyklus */

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('MAC', '');
        $this->RegisterPropertyString('MQTTUser', '');
        $this->RegisterPropertyString('TopicPrefix', '02');
        $this->RegisterPropertyFloat('SetpointMin', 5.0);
        $this->RegisterPropertyFloat('SetpointMax', 30.0);
        $this->RegisterPropertyInteger('BatteryLowThreshold', 20);
        $this->RegisterPropertyInteger('BatteryDecode', CWIFI_Registers::BATTERY_HEX);
        $this->RegisterPropertyInteger('PollInterval', 0);
        $this->RegisterPropertyInteger('TimeoutMinutes', 180);
        $this->RegisterPropertyBoolean('RawRegisters', false);
        $this->RegisterPropertyBoolean('DebugUnknown', true);

        $this->RegisterAttributeString(self::ATTR_SEEN_NEWS, '');
        $this->RegisterAttributeBoolean(self::ATTR_HINT_GONE, false);

        $this->RegisterTimer('CWIFI_Poll', 0, 'CWIFI_Poll($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CWIFI_Alive', 0, 'CWIFI_CheckAlive($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $mac  = CWIFI_Topics::normalizeMac($this->ReadPropertyString('MAC'));
        $user = trim($this->ReadPropertyString('MQTTUser'));

        $this->maintainVariables();

        // Ohne vollständige Zuordnung nichts empfangen — ein zu weiter Filter würde sonst
        // fremde Geräte in diese Instanz schreiben.
        if ($mac === '' || $user === '') {
            $this->SetReceiveDataFilter(CWIFI_Topics::blockingFilter());
            $this->SetTimerInterval('CWIFI_Poll', 0);
            $this->SetTimerInterval('CWIFI_Alive', 0);
            $this->SetStatus($mac === '' ? 201 : 202);
            return;
        }

        $base = CWIFI_Topics::base($this->ReadPropertyString('TopicPrefix'), $user, $mac);
        $this->SetReceiveDataFilter(CWIFI_Topics::receiveFilter($mac));
        $this->SendDebug('Empfangsfilter', 'auf MAC ' . $mac . ', erwartetes Topic ' . $base . '/…', 0);

        $this->applyTimers($mac);

        // Bewusst KEIN S/AF an dieser Stelle: ApplyChanges läuft bei jedem Kernelstart und
        // bei jedem Speichern — mal der Anzahl der Geräte. Das wäre genau das Wecken, das
        // die Batterien kostet.
        $this->refreshStatus();
    }

    /* ==================================================================== Empfangen */

    public function ReceiveData($JSONString)
    {
        $packet = json_decode($JSONString, true);
        if (!is_array($packet) || !array_key_exists('Topic', $packet)) {
            return '';
        }

        $mac  = CWIFI_Topics::normalizeMac($this->ReadPropertyString('MAC'));
        $user = trim($this->ReadPropertyString('MQTTUser'));
        if ($mac === '' || $user === '') {
            return '';
        }

        $base  = CWIFI_Topics::base($this->ReadPropertyString('TopicPrefix'), $user, $mac);
        $split = CWIFI_Topics::split(strval($packet['Topic']), $base);
        if ($split === null) {
            return '';
        }

        [$direction, $register] = $split;
        $payload = strval($packet['Payload'] ?? '');

        $this->rememberMessage($direction . '/' . $register, $payload);

        switch ($direction) {
            case 'V':
                $this->handleValue($register, $payload);
                break;

            case 'S':
                // Kommandos sind KEIN Zustand. Was hier ankommt, ist entweder der vom Broker
                // zurückgespiegelte eigene Publish oder ein Befehl aus der Hersteller-App.
                // Als Zustand gewertet würde der eigene Echo einen Wert bestätigen, den das
                // Gerät möglicherweise nie gesehen hat.
                $this->SendDebug('Kommando gesehen', $register . ' = ' . $payload, 0);
                break;

            default:
                // G/ (Gruppensync) und alles Weitere: nur beobachten.
                $this->SendDebug('Sonstiges Topic', $direction . '/' . $register . ' = ' . $payload, 0);
                break;
        }

        return '';
    }

    /** Verarbeitet eine Zustandsmeldung des Geräts. */
    private function handleValue(string $register, string $payload): void
    {
        // Sonderfälle VOR der allgemeinen Behandlung: Der Verbindungsverlust ist der Last
        // Will und kommt vom Broker, nicht vom Gerät — er darf „zuletzt gehört" nicht
        // auffrischen, sonst sieht ein totes Gerät ewig frisch aus.
        if ($register === CWIFI_Registers::REG_COMM) {
            if ($payload === CWIFI_Registers::PAYLOAD_COMM_LOSS) {
                $this->SetValue(CWIFI_Registers::IDENT_REACHABLE, false);
                $this->SetStatus(204);
                $this->SendDebug('Verbindung', 'Gerät hat sich abgemeldet (COMM-LOSS)', 0);
                return;
            }
            if ($payload === CWIFI_Registers::PAYLOAD_COMM_TEST) {
                $this->markAlive();
                $this->SendDebug('Verbindung', 'Verbindungstest des Geräts', 0);
                return;
            }
        }

        $this->markAlive();

        $definition = CWIFI_Registers::byRegister($register);
        if ($definition === null) {
            $this->handleUnknownRegister($register, $payload);
            return;
        }

        $value = CWIFI_Registers::decode(
            $definition,
            $payload,
            $this->ReadPropertyInteger('BatteryDecode')
        );
        if ($value === null) {
            $this->SendDebug('Unlesbar', $register . ' = ' . $payload, 0);
            return;
        }

        $this->SetValue($definition['ident'], $value);

        if ($definition['ident'] === CWIFI_Registers::IDENT_BATTERY) {
            $this->afterBatteryUpdate((int) $value, $payload);
        }
        if ($definition['ident'] === CWIFI_Registers::IDENT_SETPOINT) {
            $this->reconcilePendingSetpoint((float) $value);
        }
    }

    /** Rohdatenpfad für alles, was (noch) keine belegte Bedeutung hat. */
    private function handleUnknownRegister(string $register, string $payload): void
    {
        if ($this->ReadPropertyBoolean('DebugUnknown')) {
            $this->SendDebug('Unbekanntes Register', $register . ' = ' . $payload, 0);
        }
        if (!$this->ReadPropertyBoolean('RawRegisters')) {
            return;
        }

        $ident = 'RAW_' . preg_replace('/[^0-9A-Z]/', '', strtoupper($register));
        if ($ident === 'RAW_') {
            return;
        }

        $this->MaintainVariable(
            $ident,
            $this->Translate('Raw value') . ' ' . strtoupper($register),
            VARIABLETYPE_STRING,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            100,
            true
        );
        $this->SetValue($ident, $payload);
    }

    /**
     * Vergleicht die Rückmeldung des Geräts mit dem zuletzt gesendeten Sollwert.
     * Das Gerät gewinnt immer — hier wird nur protokolliert, nicht korrigiert.
     */
    private function reconcilePendingSetpoint(float $reported): void
    {
        $pending = $this->GetBuffer(self::BUFFER_PENDING);
        if ($pending === '') {
            return;
        }
        [$wanted, $sentAt] = array_pad(explode('|', $pending, 2), 2, '0');

        if ((time() - intval($sentAt)) > self::PENDING_GRACE_SECONDS) {
            $this->SetBuffer(self::BUFFER_PENDING, '');
            return;
        }
        if (abs(floatval($wanted) - $reported) > 0.01) {
            $this->SendDebug(
                'Sollwert nicht übernommen',
                sprintf('gesendet %.1f °C, gemeldet %.1f °C', floatval($wanted), $reported),
                0
            );
        }
        $this->SetBuffer(self::BUFFER_PENDING, '');
    }

    /** Nachgelagerte Auswertung des Batteriewerts. */
    private function afterBatteryUpdate(int $percent, string $payload): void
    {
        $this->SetValue(
            CWIFI_Registers::IDENT_BATTERY_LOW,
            $percent < $this->ReadPropertyInteger('BatteryLowThreshold')
        );

        // Ein Zeichen A–F beweist die Hex-Kodierung; Ziffern allein beweisen nichts.
        if ($this->ReadPropertyInteger('BatteryDecode') === CWIFI_Registers::BATTERY_DECIMAL
            && CWIFI_Registers::provesHexBattery($payload)) {
            $this->SendDebug(
                'Batterie-Kodierung',
                'Payload ' . $payload . ' enthält A–F und kann keine Dezimalzahl sein. '
                . 'Einstellung „Batteriewert lesen als" auf Hexadezimal umstellen.',
                0
            );
        }
    }

    /** Markiert das Gerät als lebendig und schiebt den Wachhund nach hinten. */
    private function markAlive(): void
    {
        $this->SetValue(CWIFI_Registers::IDENT_LAST_UPDATE, time());
        $this->SetValue(CWIFI_Registers::IDENT_REACHABLE, true);
        if ($this->GetStatus() !== IS_ACTIVE) {
            $this->SetStatus(IS_ACTIVE);
        }
    }

    /* ===================================================================== Senden */

    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== CWIFI_Registers::IDENT_SETPOINT) {
            throw new Exception($this->Translate('Unknown Ident: ') . $Ident);
        }
        $this->SetTemperature(floatval($Value));
    }

    /**
     * Setzt die Solltemperatur.
     *
     * ⚠️ Ein `S/A0` ALLEIN bleibt wirkungslos. Das Gerät nimmt den Wert nicht an und meldet
     *    unmittelbar danach per `V/A0` wieder seinen alten Sollwert zurück — es widerspricht
     *    also aktiv, statt die Nachricht bloß zu verschlucken. Erst ein direkt folgendes
     *    `S/AF #FFFFFFFF` bringt es dazu, den Wert zu übernehmen und zu bestätigen. Genau so
     *    macht es auch die Hersteller-Cloud; im Broker-Protokoll stehen beide Nachrichten in
     *    derselben Sekunde. Am Gerät belegt (05.08.2026), siehe .docs/protokoll.md.
     *
     * Der Wert wird sofort in die Variable geschrieben, ohne auf die Bestätigung des Geräts
     * zu warten: Das Thermostat meldet sich erst, wenn es aufwacht — das kann Minuten
     * dauern, und der Schieberegler spränge in der Zwischenzeit sichtbar zurück. Sobald
     * das Gerät seinen Wert meldet, gewinnt dieser.
     */
    public function SetTemperature(float $Temperature): bool
    {
        $mac  = CWIFI_Topics::normalizeMac($this->ReadPropertyString('MAC'));
        $user = trim($this->ReadPropertyString('MQTTUser'));
        if ($mac === '' || $user === '') {
            $this->SendDebug('SetTemperature', 'Instanz unvollständig konfiguriert', 0);
            return false;
        }

        [$payload, $applied] = CWIFI_Registers::encodeSetpoint(
            $Temperature,
            $this->ReadPropertyFloat('SetpointMin'),
            $this->ReadPropertyFloat('SetpointMax')
        );

        $topic = CWIFI_Topics::set(
            $this->ReadPropertyString('TopicPrefix'),
            $user,
            $mac,
            CWIFI_Registers::REG_SETPOINT
        );

        if (!$this->sendMQTT($topic, $payload)) {
            return false;
        }

        // Ohne diese zweite Nachricht verwirft das Gerät den Sollwert wieder — siehe oben.
        // Angefordert wird gezielt NUR A0, nicht der komplette Feld-Dump: Das genügt (am
        // Gerät geprüft) und weckt es deutlich kürzer. Die Hersteller-App macht es ebenso.
        // Der Rückgabewert wird bewusst nicht geprüft: Der Sollwert ist raus, und ein
        // fehlgeschlagener Nachzieher wird von sendMQTT() bereits protokolliert.
        $this->sendRequest(CWIFI_Registers::requestFields(CWIFI_Registers::REG_SETPOINT));

        $this->SetValue(CWIFI_Registers::IDENT_SETPOINT, $applied);
        $this->SetBuffer(self::BUFFER_PENDING, $applied . '|' . time());
        return true;
    }

    /** Fordert die aktuellen Temperaturen an — das schonendste Kommando. */
    public function RequestUpdate(): bool
    {
        return $this->sendRequest(CWIFI_Registers::REQUEST_CURRENT);
    }

    /** Fordert sämtliche Register an. Weckt das Gerät spürbar — nie auf einem Timer. */
    public function RequestAllFields(): bool
    {
        return $this->sendRequest(CWIFI_Registers::REQUEST_ALL);
    }

    private function sendRequest(string $payload): bool
    {
        $mac  = CWIFI_Topics::normalizeMac($this->ReadPropertyString('MAC'));
        $user = trim($this->ReadPropertyString('MQTTUser'));
        if ($mac === '' || $user === '') {
            return false;
        }
        $topic = CWIFI_Topics::set(
            $this->ReadPropertyString('TopicPrefix'),
            $user,
            $mac,
            CWIFI_Registers::REG_REQUEST
        );
        return $this->sendMQTT($topic, $payload);
    }

    /* ====================================================================== Timer */

    public function Poll(): void
    {
        $this->RequestUpdate();
    }

    /**
     * Wachhund gegen stumme Geräte.
     *
     * Der Last Will greift erst nach Ablauf des Keepalive (600 s) und bei einem
     * Broker-Neustart gar nicht — ohne diese Prüfung bliebe ein totes Gerät „erreichbar".
     */
    public function CheckAlive(): void
    {
        $last = $this->GetValue(CWIFI_Registers::IDENT_LAST_UPDATE);
        if (!is_int($last) || $last === 0) {
            return;
        }
        $timeout = $this->ReadPropertyInteger('TimeoutMinutes') * 60;
        if ((time() - $last) <= $timeout) {
            return;
        }
        if ($this->GetValue(CWIFI_Registers::IDENT_REACHABLE) !== false) {
            $this->SetValue(CWIFI_Registers::IDENT_REACHABLE, false);
        }
        if ($this->GetStatus() !== 204) {
            $this->SetStatus(206);
        }
    }

    /**
     * Stellt die Timer.
     *
     * Der Versatz aus der MAC sorgt dafür, dass mehrere Thermostate nicht sekundengleich
     * aufwachen — sonst käme die Last in Wellen auf Broker und Bridge.
     */
    private function applyTimers(string $mac): void
    {
        $minutes = $this->ReadPropertyInteger('PollInterval');
        if ($minutes <= 0) {
            $this->SetTimerInterval('CWIFI_Poll', 0);
        } else {
            $minutes  = max(self::MIN_POLL_MINUTES, $minutes);
            $interval = $minutes * 60;
            $offset   = crc32($mac) % $interval;
            $this->SetTimerInterval('CWIFI_Poll', $interval * 1000);
            $this->SendDebug(
                'Abfragetakt',
                sprintf('alle %d min, Versatz %d s', $minutes, $offset),
                0
            );
        }

        $this->SetTimerInterval('CWIFI_Alive', 300000);   // 5 Minuten
    }

    /* ================================================================== Variablen */

    private function maintainVariables(): void
    {
        $min = $this->ReadPropertyFloat('SetpointMin');
        $max = $this->ReadPropertyFloat('SetpointMax');
        if ($max <= $min) {
            $max = $min + 0.5;
        }

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_TEMPERATURE,
            $this->Translate('Current temperature'),
            VARIABLETYPE_FLOAT,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 1],
            10,
            true
        );

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_SETPOINT,
            $this->Translate('Target temperature'),
            VARIABLETYPE_FLOAT,
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN'          => $min,
                'MAX'          => $max,
                'STEP_SIZE'    => 0.5,
                'SUFFIX'       => ' °C',
                'DIGITS'       => 1
            ],
            20,
            true
        );
        $this->EnableAction(CWIFI_Registers::IDENT_SETPOINT);

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_BATTERY,
            $this->Translate('Battery level'),
            VARIABLETYPE_INTEGER,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'DIGITS' => 0],
            30,
            true
        );

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_BATTERY_LOW,
            $this->Translate('Battery low'),
            VARIABLETYPE_BOOLEAN,
            $this->booleanPresentation('Battery low', 'OK', true),
            35,
            true
        );

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_RSSI,
            $this->Translate('WiFi signal strength'),
            VARIABLETYPE_INTEGER,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' dBm', 'DIGITS' => 0],
            40,
            true
        );

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_REACHABLE,
            $this->Translate('Reachable'),
            VARIABLETYPE_BOOLEAN,
            $this->booleanPresentation('Yes', 'No', false),
            50,
            true
        );

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_LAST_UPDATE,
            $this->Translate('Last message'),
            VARIABLETYPE_INTEGER,
            '~UnixTimestamp',
            60,
            true
        );
    }

    /**
     * Zweiwertige Darstellung mit Ampelfarbe.
     *
     * @param bool $trueIsBad true = der Wahr-Zustand ist der Warnzustand (rot).
     */
    private function booleanPresentation(string $captionTrue, string $captionFalse, bool $trueIsBad): array
    {
        $red   = 0xFF0000;
        $green = 0x00A000;

        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                [
                    'Value'       => true,
                    'Caption'     => $this->Translate($captionTrue),
                    'IconActive'  => false,
                    'Icon'        => '',
                    'ColorActive' => true,
                    'ColorValue'  => $trueIsBad ? $red : $green
                ],
                [
                    'Value'       => false,
                    'Caption'     => $this->Translate($captionFalse),
                    'IconActive'  => false,
                    'Icon'        => '',
                    'ColorActive' => true,
                    'ColorValue'  => $trueIsBad ? $green : $red
                ]
            ])
        ];
    }

    /* ==================================================================== Formular */

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $this->fillVersionLabel($form);
        $this->fillTopicPreview($form);

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        return json_encode($form);
    }

    /** Trägt Basis-Topic und erwartete Client-ID live ein — Selbstkontrolle beim Tippen. */
    public function UpdateTopicPreview(string $MAC, string $MQTTUser, string $TopicPrefix): void
    {
        $this->UpdateFormField('TopicPreview', 'caption', $this->topicPreviewText($MAC, $MQTTUser, $TopicPrefix));
        $this->UpdateFormField('ClientIdPreview', 'caption', $this->clientIdPreviewText($MAC, $MQTTUser));
    }

    private function topicPreviewText(string $mac, string $user, string $prefix): string
    {
        $clean = CWIFI_Topics::normalizeMac($mac);
        if ($clean === '' || trim($user) === '') {
            return $this->Translate('Base topic: (please enter MAC address and MQTT user)');
        }
        return $this->Translate('Base topic: ') . CWIFI_Topics::base($prefix, trim($user), $clean) . '/…';
    }

    private function clientIdPreviewText(string $mac, string $user): string
    {
        $clientId = CWIFI_Topics::clientId(trim($user), $mac);
        if ($clientId === '') {
            return ' ';
        }
        return $this->Translate('Expected client ID of the device: ') . $clientId;
    }

    private function fillTopicPreview(array &$form): void
    {
        $mac    = $this->ReadPropertyString('MAC');
        $user   = $this->ReadPropertyString('MQTTUser');
        $prefix = $this->ReadPropertyString('TopicPrefix');

        $this->setElementCaption($form['elements'], 'TopicPreview', $this->topicPreviewText($mac, $user, $prefix));
        $this->setElementCaption($form['elements'], 'ClientIdPreview', $this->clientIdPreviewText($mac, $user));
    }

    private function fillVersionLabel(array &$form): void
    {
        $version = '?';
        $moduleId = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'] ?? '';
        if ($moduleId !== '') {
            $libraryId = IPS_GetModule($moduleId)['LibraryID'] ?? '';
            if ($libraryId !== '') {
                $library = IPS_GetLibrary($libraryId);
                $version = $library['Version'] . ' (Build ' . $library['Build'] . ')';
            }
        }
        $this->setElementCaption(
            $form['elements'],
            'VersionLabel',
            $this->Translate('Module version: ') . $version
        );
    }

    /** Setzt die Beschriftung eines benannten Elements, auch verschachtelt. */
    private function setElementCaption(array &$elements, string $name, string $caption): bool
    {
        foreach ($elements as &$element) {
            if (($element['name'] ?? '') === $name) {
                $element['caption'] = $caption;
                return true;
            }
            if (isset($element['items']) && is_array($element['items'])
                && $this->setElementCaption($element['items'], $name, $caption)) {
                return true;
            }
        }
        return false;
    }

    private function newsBanner(): ?array
    {
        if ($this->ReadAttributeString(self::ATTR_SEEN_NEWS) === self::NEWS_VERSION) {
            return null;
        }
        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'NewsPanel',
            'caption'  => '🆕  Neu in Version ' . self::NEWS_VERSION,
            'expanded' => true,
            'items'    => [
                ['type' => 'Label', 'caption' => '• Erste Fassung: Ist- und Solltemperatur, Batteriestand, Signalstärke und Erreichbarkeit.'],
                ['type' => 'Label', 'caption' => '• Die Solltemperatur lässt sich setzen. Das Wochenprogramm im Gerät überschreibt sie beim nächsten Schaltpunkt — das ist Verhalten des Geräts.'],
                ['type' => 'Label', 'caption' => '• Die aktive Abfrage ist ab Werk ausgeschaltet, um die Batterien zu schonen. Die Geräte melden sich von selbst.'],
                ['type' => 'Label', 'caption' => '• Noch nicht entschlüsselte Register (Wochenprogramm, Fenstererkennung, Tastensperre) lassen sich als Rohdaten mitschreiben.'],
                [
                    'type'    => 'Button',
                    'caption' => 'Verstanden – nicht mehr anzeigen',
                    'onClick' => 'CWIFI_AckNews($id);'
                ]
            ]
        ];
    }

    /** Nur Attribut + UpdateFormField — kein IPS_SetProperty/ApplyChanges (Store-Review). */
    public function AckNews(): void
    {
        $this->WriteAttributeString(self::ATTR_SEEN_NEWS, self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    /* ===================================================================== Intern */

    /** Ringpuffer der letzten Nachrichten für die Fehlersuche. */
    private function rememberMessage(string $topic, string $payload): void
    {
        $recent = json_decode($this->GetBuffer(self::BUFFER_RECENT), true);
        if (!is_array($recent)) {
            $recent = [];
        }
        $recent[] = date('H:i:s') . '  ' . $topic . ' = ' . $payload;
        if (count($recent) > self::RECENT_LIMIT) {
            $recent = array_slice($recent, -self::RECENT_LIMIT);
        }
        $this->SetBuffer(self::BUFFER_RECENT, json_encode($recent));
    }

    private function refreshStatus(): void
    {
        if (!$this->HasActiveParent()) {
            $this->SetStatus(203);
            return;
        }
        $last = $this->GetValue(CWIFI_Registers::IDENT_LAST_UPDATE);
        if (!is_int($last) || $last === 0) {
            $this->SetStatus(205);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
    }
}
