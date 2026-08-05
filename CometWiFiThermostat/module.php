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

    /** Letzte gemeldete Uhrabweichung in Minuten — siehe clockOffLimits(). */
    private const ATTR_CLOCK_DEV = 'LastClockDeviation';

    private const BUFFER_PENDING  = 'PendingSetpoint';
    private const BUFFER_RECENT   = 'RecentMessages';
    private const BUFFER_SCHEDULE = 'ScheduleDays';

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
        // Die Skala des Geräts läuft von „Aus" über 8,0–28,0 °C bis „An". Die beiden
        // Endanschläge sind 7,5 (#0F) und 28,5 (#39) — keine echten Temperaturen, sondern
        // Ventil ganz zu bzw. ganz auf. Am Gerät belegt.
        $this->RegisterPropertyFloat('SetpointMin', CWIFI_Registers::SETPOINT_OFF);
        $this->RegisterPropertyFloat('SetpointMax', CWIFI_Registers::SETPOINT_ON);
        $this->RegisterPropertyInteger('BatteryLowThreshold', 20);
        $this->RegisterPropertyInteger('BatteryDecode', CWIFI_Registers::BATTERY_HEX);
        $this->RegisterPropertyInteger('PollInterval', 0);
        $this->RegisterPropertyInteger('TimeoutMinutes', 180);
        $this->RegisterPropertyBoolean('ForceManualOnSet', true);
        $this->RegisterPropertyBoolean('RawRegisters', false);
        // B4/B5/B7/BB/BC tragen über zehn Geräte hinweg denselben Wert. Wer trotzdem
        // mitschreiben will, kann — aber nicht als Vorgabe.
        $this->RegisterPropertyBoolean('RawSilentRegisters', false);
        // Ab dieser Abweichung meldet die Instanz einen Hinweis. 15 Minuten, weil das
        // Wochenprogramm in Viertelstunden gedacht ist und darunter niemand etwas merkt.
        $this->RegisterPropertyInteger('ClockWarnMinutes', 15);
        $this->RegisterAttributeInteger(self::ATTR_CLOCK_DEV, 0);
        // Ab Werk aus: Schreiben weckt ein Batteriegerät, und ob jemand das will, ist eine
        // Entscheidung und keine Voreinstellung.
        $this->RegisterPropertyInteger('ClockSyncDays', 0);
        $this->RegisterPropertyBoolean('DebugUnknown', true);

        $this->RegisterAttributeString(self::ATTR_SEEN_NEWS, '');
        $this->RegisterAttributeBoolean(self::ATTR_HINT_GONE, false);

        $this->RegisterTimer('CWIFI_Poll', 0, 'CWIFI_Poll($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CWIFI_Alive', 0, 'CWIFI_CheckAlive($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CWIFI_ClockSync', 0, 'CWIFI_SyncClock($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $mac  = CWIFI_Topics::normalizeMac($this->ReadPropertyString('MAC'));
        $user = trim($this->ReadPropertyString('MQTTUser'));

        $this->maintainVariables();
        $this->sweepRawVariables();

        // Ohne vollständige Zuordnung nichts empfangen — ein zu weiter Filter würde sonst
        // fremde Geräte in diese Instanz schreiben.
        if ($mac === '' || $user === '') {
            $this->SetReceiveDataFilter(CWIFI_Topics::blockingFilter());
            $this->SetTimerInterval('CWIFI_Poll', 0);
            $this->SetTimerInterval('CWIFI_Alive', 0);
            $this->SetTimerInterval('CWIFI_ClockSync', 0);
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

        // Muss VOR die Weichen: Urlaub, Wochenprogramm, Optionen und Offset springen unten
        // jeweils mit return heraus. Wird das Register überhaupt gedeutet, ist eine
        // Rohfassung daneben überflüssig — und aus früheren Versionen liegen genau solche
        // verwaisten Variablen in den Instanzen herum.
        $this->dropStaleRaw($register);

        // Optionen und Offset haben ein eigenes Format und stehen deshalb nicht in der
        // allgemeinen Registertabelle.
        if ($register === CWIFI_Registers::REG_OPTIONS) {
            $this->handleOptions($payload);
            return;
        }
        if ($register === CWIFI_Registers::REG_HOLIDAY) {
            $this->handleHoliday($payload);
            return;
        }
        $day = array_search($register, CWIFI_Registers::SCHEDULE_REGISTERS, true);
        if ($day !== false) {
            $this->handleSchedule((int) $day, $payload);
            return;
        }
        if ($register === CWIFI_Registers::REG_OFFSET) {
            $value = CWIFI_Registers::decodeOffset($payload);
            if ($value !== null) {
                $this->SetValue(CWIFI_Registers::IDENT_OFFSET, $value);
            }
            return;
        }

        if (strtoupper($register) === CWIFI_Registers::REG_CLOCK) {
            $this->handleClock($payload);
            return;
        }

        if (isset(CWIFI_Registers::INFO_REGISTERS[strtoupper($register)])) {
            $this->handleInfo(strtoupper($register), $payload);
            return;
        }

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

    /** Zerlegt das Optionen-Bitfeld in die einzelnen Schalter. */
    private function handleOptions(string $payload): void
    {
        $options = CWIFI_Registers::decodeOptions($payload);
        if ($options === null) {
            $this->SendDebug('Optionen unlesbar', $payload, 0);
            return;
        }
        $this->SetValue(CWIFI_Registers::IDENT_MODE, (bool) ($options & CWIFI_Registers::OPT_MANUAL));
        $this->SetValue(CWIFI_Registers::IDENT_ROTATE, (bool) ($options & CWIFI_Registers::OPT_ROTATE));
        $this->SetValue(CWIFI_Registers::IDENT_DST, (bool) ($options & CWIFI_Registers::OPT_DST));
        $this->SetValue(CWIFI_Registers::IDENT_KEYLOCK, CWIFI_Registers::keyLockLevel($options));
    }

    /** Urlaubszeitraum aus Register A7. */
    private function handleHoliday(string $payload): void
    {
        $holiday = CWIFI_Registers::decodeHoliday($payload);
        if ($holiday === null) {
            // Neun Byte FF heißt ausdrücklich „kein Urlaub" — das ist ein gültiger
            // Zustand und keine Störung.
            $this->SetValue(CWIFI_Registers::IDENT_HOLIDAY, false);
            $this->SetValue(CWIFI_Registers::IDENT_HOLIDAY_FROM, 0);
            $this->SetValue(CWIFI_Registers::IDENT_HOLIDAY_TO, 0);
            return;
        }
        $this->SetValue(CWIFI_Registers::IDENT_HOLIDAY, true);
        $this->SetValue(CWIFI_Registers::IDENT_HOLIDAY_FROM, $holiday['start']);
        $this->SetValue(CWIFI_Registers::IDENT_HOLIDAY_TO, $holiday['end']);
        $this->SetValue(CWIFI_Registers::IDENT_HOLIDAY_TEMP, $holiday['temperature']);
    }

    /**
     * Wochenprogramm: je Tag ein Register. Die sieben Tage werden zu einer lesbaren
     * Übersicht zusammengesetzt und in einer Textvariable gehalten.
     *
     * Bewusst nur lesend: Das Schreiben eines Wochenprogramms greift tief in die
     * Heizungssteuerung ein und braucht eine eigene Bedienoberfläche.
     */
    private function handleSchedule(int $weekday, string $payload): void
    {
        $days = json_decode($this->GetBuffer(self::BUFFER_SCHEDULE), true);
        if (!is_array($days)) {
            $days = [];
        }
        $points = CWIFI_Registers::decodeSchedule($payload, $weekday);
        $days[$weekday] = CWIFI_Registers::scheduleToText($points);
        $this->SetBuffer(self::BUFFER_SCHEDULE, json_encode($days));

        $namen = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $zeilen = [];
        foreach ($namen as $i => $name) {
            $zeilen[] = $name . ': ' . ($days[$i] ?? '–');
        }
        $this->SetValue(CWIFI_Registers::IDENT_SCHEDULE, implode("\n", $zeilen));
    }

    /**
     * Entfernt die Rohfassung eines Registers, sobald es eine belegte Deutung hat.
     *
     * Zwei Variablen für denselben Wert können sich widersprechen, und die ältere gewinnt
     * dabei optisch: Sie steht weiter oben und trägt einen Zeitstempel, der nach Aktualität
     * aussieht. Läuft bei jedem Empfang; `MaintainVariable` mit `false` ist auf eine bereits
     * fehlende Variable wirkungslos.
     */
    private function dropStaleRaw(string $register): void
    {
        $reg = strtoupper($register);
        $entschluesselt = $reg === CWIFI_Registers::REG_CLOCK
            || $reg === CWIFI_Registers::REG_OPTIONS
            || $reg === CWIFI_Registers::REG_OFFSET
            || $reg === CWIFI_Registers::REG_HOLIDAY
            || isset(CWIFI_Registers::INFO_REGISTERS[$reg])
            || in_array($reg, CWIFI_Registers::SCHEDULE_REGISTERS, true)
            || CWIFI_Registers::byRegister($reg) !== null;

        if (!$entschluesselt) {
            return;
        }
        $ident = 'RAW_' . preg_replace('/[^0-9A-Z]/', '', $reg);
        if ($ident !== 'RAW_') {
            $this->MaintainVariable($ident, '', VARIABLETYPE_STRING, '', 0, false);
        }
    }

    /**
     * Geräteuhr aus `A4` — und vor allem, wie weit sie danebenliegt.
     *
     * Die Abweichung ist der eigentliche Nutzwert: Das Wochenprogramm läuft im Gerät und
     * schaltet um genau diese Spanne zu früh oder zu spät. Ohne die Anzeige sucht man den
     * Fehler beim Programm statt bei der Uhr.
     */
    private function handleClock(string $payload): void
    {
        $clock = CWIFI_Registers::decodeClock($payload);
        if ($clock === null) {
            $this->SendDebug('Geräteuhr', 'nicht lesbar: ' . $payload, 0);
            $this->handleUnknownRegister(CWIFI_Registers::REG_CLOCK, $payload);
            return;
        }

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_CLOCK,
            $this->Translate('Device clock'),
            VARIABLETYPE_STRING,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            97,
            true
        );
        $this->SetValue(
            CWIFI_Registers::IDENT_CLOCK,
            sprintf('%02d:%02d', $clock['hour'], $clock['minute'])
        );

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_CLOCK_DEV,
            $this->Translate('Clock deviation'),
            VARIABLETYPE_INTEGER,
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' min'
            ],
            98,
            true
        );
        $this->SetValue(CWIFI_Registers::IDENT_CLOCK_DEV, $clock['deviation']);
        $this->WriteAttributeInteger(self::ATTR_CLOCK_DEV, $clock['deviation']);

        if ($this->clockOffLimits()) {
            $this->SendDebug(
                'Geräteuhr',
                sprintf('%02d:%02d — %+d min gegenüber Symcon. Das Wochenprogramm schaltet entsprechend versetzt.',
                    $clock['hour'], $clock['minute'], $clock['deviation']),
                0
            );
        }
        // Weicher Status, den auch die nächste Gerätemeldung nicht wegwischt.
        $this->refreshStatus();
    }

    /**
     * Geräteauskunft: Modell, Firmware, IP, Zugangspunkt, Verschlüsselung, Gruppe.
     *
     * Die Variable entsteht erst, wenn das Register das erste Mal ankommt — sonst stünde in
     * einer frisch angelegten Instanz eine Reihe leerer Felder, die nach einem Fehler
     * aussieht. Diese Register kommen nur bei einem Voll-Dump mit und kosten deshalb keine
     * zusätzliche Batterie.
     */
    private function handleInfo(string $register, string $payload): void
    {
        [$ident, $quelle] = CWIFI_Registers::INFO_REGISTERS[$register];

        $text = CWIFI_Registers::decodeInfo($register, $payload);
        if ($text === null) {
            // Lässt sich das Register wider Erwarten nicht lesen, geht es in den Rohpfad,
            // statt eine leere oder verstümmelte Auskunft anzuzeigen.
            $this->SendDebug('Geräteauskunft', $register . ' nicht lesbar: ' . $payload, 0);
            $this->handleUnknownRegister($register, $payload);
            return;
        }

        $this->MaintainVariable(
            $ident,
            $this->Translate($quelle),
            VARIABLETYPE_STRING,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            90,
            true
        );
        $this->SetValue($ident, $text);

    }

    /** Rohdatenpfad für alles, was (noch) keine belegte Bedeutung hat. */
    private function handleUnknownRegister(string $register, string $payload): void
    {
        $reg = strtoupper($register);

        if ($this->ReadPropertyBoolean('DebugUnknown')) {
            $this->SendDebug('Unbekanntes Register', $reg . ' = ' . $payload, 0);
        }
        if (!$this->ReadPropertyBoolean('RawRegisters')) {
            return;
        }
        if (!$this->rawWanted($reg)) {
            return;
        }

        $ident = 'RAW_' . preg_replace('/[^0-9A-Z]/', '', $reg);
        if ($ident === 'RAW_') {
            return;
        }

        $this->MaintainVariable(
            $ident,
            $this->rawCaption($reg),
            VARIABLETYPE_STRING,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            100,
            true
        );
        $this->SetValue($ident, $payload);
    }

    /** Soll dieses Register überhaupt als Rohwert geführt werden? */
    private function rawWanted(string $register): bool
    {
        if (!in_array($register, CWIFI_Registers::SILENT_REGISTERS, true)) {
            return true;
        }
        return $this->ReadPropertyBoolean('RawSilentRegisters');
    }

    /** Sprechender Name, wo der Zweck bekannt ist — sonst schlicht „Rohwert XY". */
    private function rawCaption(string $register): string
    {
        if (isset(CWIFI_Registers::NAMED_RAW[$register])) {
            return $this->Translate(CWIFI_Registers::NAMED_RAW[$register]);
        }
        return $this->Translate('Raw value') . ' ' . $register;
    }

    /**
     * Räumt Rohwerte ab, die es nicht mehr geben soll.
     *
     * Muss bei jedem Übernehmen laufen und nicht erst beim nächsten Eintreffen des
     * Registers: Diese Geräte melden sich von sich aus selten, und ein Voll-Dump kostet
     * Batterie. Wer eine Einstellung ändert, will die Wirkung sofort sehen und nicht
     * irgendwann.
     */
    private function sweepRawVariables(): void
    {
        foreach (CWIFI_Registers::SILENT_REGISTERS as $reg) {
            if (!$this->rawWanted($reg)) {
                $this->MaintainVariable('RAW_' . $reg, '', VARIABLETYPE_STRING, '', 0, false);
            }
        }

        // Alles, was inzwischen eine belegte Deutung hat, ebenfalls — sonst bleiben nach
        // einer Modulaktualisierung Karteileichen aus älteren Fassungen stehen.
        foreach (array_merge(
            ['A2', 'A3', 'A4', 'A7'],
            CWIFI_Registers::SCHEDULE_REGISTERS,
            array_keys(CWIFI_Registers::INFO_REGISTERS)
        ) as $reg) {
            $this->MaintainVariable('RAW_' . $reg, '', VARIABLETYPE_STRING, '', 0, false);
        }

        if (!$this->ReadPropertyBoolean('RawRegisters')) {
            foreach (array_merge(CWIFI_Registers::SILENT_REGISTERS,
                     array_keys(CWIFI_Registers::NAMED_RAW), ['BD', 'BE']) as $reg) {
                $this->MaintainVariable('RAW_' . $reg, '', VARIABLETYPE_STRING, '', 0, false);
            }
        }
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
        $this->refreshStatus();
    }

    /**
     * Weicht die zuletzt gemeldete Geräteuhr weiter ab als eingestellt?
     *
     * Der Wert kommt aus einem Attribut und nicht aus der Variable: `GetIDForIdent` wirft,
     * solange die Variable noch nicht angelegt ist, und das ist bei einer frischen Instanz
     * bis zum ersten Voll-Dump der Normalfall. Nach einem Modul-Neuladen steht hier eine 0,
     * bis das Gerät die Uhr das nächste Mal meldet — ein zu optimistischer Anfangszustand
     * ist besser als eine Warnung ohne Datengrundlage.
     */
    private function clockOffLimits(): bool
    {
        $grenze = $this->ReadPropertyInteger('ClockWarnMinutes');
        if ($grenze <= 0) {
            return false;
        }
        return abs($this->ReadAttributeInteger(self::ATTR_CLOCK_DEV)) >= $grenze;
    }

    /* ===================================================================== Senden */

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case CWIFI_Registers::IDENT_SETPOINT:
                $this->SetTemperature(floatval($Value));
                return;

            case CWIFI_Registers::IDENT_MODE:
                $this->SetManualMode(boolval($Value));
                return;

            case CWIFI_Registers::IDENT_KEYLOCK:
                $this->SetKeyLock(intval($Value));
                return;

            case CWIFI_Registers::IDENT_ROTATE:
                $this->SetRotateDisplay(boolval($Value));
                return;

            case CWIFI_Registers::IDENT_DST:
                $this->SetAutoDST(boolval($Value));
                return;

            case CWIFI_Registers::IDENT_OFFSET:
                $this->SetOffset(floatval($Value));
                return;
        }
        throw new Exception($this->Translate('Unknown Ident: ') . $Ident);
    }

    /**
     * Handbetrieb ein- oder ausschalten.
     *
     * Eingeschaltet lässt das Gerät das Wochenprogramm ruhen — nur dann bleibt ein von
     * Symcon gesetzter Sollwert dauerhaft stehen. Ausgeschaltet übernimmt der Zeitplan
     * sofort wieder und überschreibt den Sollwert (am Gerät beobachtet).
     */
    public function SetManualMode(bool $Manual): bool
    {
        if (!$this->sendOption(CWIFI_Registers::encodeOptionSwitch(CWIFI_Registers::OPT_MANUAL, $Manual))) {
            return false;
        }
        $this->SetValue(CWIFI_Registers::IDENT_MODE, $Manual);
        return true;
    }

    /** Tastensperre: 0 = aus, 1 = ein, 2 = plus. */
    public function SetKeyLock(int $Level): bool
    {
        if (!$this->sendOption(CWIFI_Registers::encodeKeyLock($Level))) {
            return false;
        }
        $this->SetValue(CWIFI_Registers::IDENT_KEYLOCK, $Level);
        return true;
    }

    public function SetRotateDisplay(bool $Rotate): bool
    {
        if (!$this->sendOption(CWIFI_Registers::encodeOptionSwitch(CWIFI_Registers::OPT_ROTATE, $Rotate))) {
            return false;
        }
        $this->SetValue(CWIFI_Registers::IDENT_ROTATE, $Rotate);
        return true;
    }

    public function SetAutoDST(bool $Enabled): bool
    {
        if (!$this->sendOption(CWIFI_Registers::encodeOptionSwitch(CWIFI_Registers::OPT_DST, $Enabled))) {
            return false;
        }
        $this->SetValue(CWIFI_Registers::IDENT_DST, $Enabled);
        return true;
    }

    /**
     * Temperatur-Offset zur Kalibrierung.
     *
     * ⚠️ Negative Werte sind rechnerisch als Zweierkomplement umgesetzt, aber am Gerät
     *    NICHT geprüft — belegt ist nur `+1,0 K → #02` und `0 → #00`.
     */
    public function SetOffset(float $Kelvin): bool
    {
        $topic = $this->topicFor(CWIFI_Registers::REG_OFFSET);
        if ($topic === '' || !$this->sendMQTT($topic, CWIFI_Registers::encodeOffset($Kelvin))) {
            return false;
        }
        $this->sendRequest(CWIFI_Registers::requestFields(CWIFI_Registers::REG_OFFSET));
        $this->SetValue(CWIFI_Registers::IDENT_OFFSET, round($Kelvin * 2) / 2);
        return true;
    }

    /**
     * Urlaubszeitraum setzen.
     *
     * Der Urlaub gilt geräteübergreifend: Die Hersteller-App schickt denselben Befehl an
     * alle Thermostate des Kontos. Dieses Modul setzt ihn nur am eigenen Gerät — wer den
     * Urlaub für alle will, ruft die Funktion je Instanz auf.
     */
    public function SetHoliday(int $From, int $Until, float $Temperature): bool
    {
        if ($Until <= $From) {
            $this->SendDebug('SetHoliday', 'Ende liegt nicht nach dem Beginn', 0);
            return false;
        }
        $topic = $this->topicFor(CWIFI_Registers::REG_HOLIDAY);
        if ($topic === '' || !$this->sendMQTT($topic, CWIFI_Registers::encodeHoliday($From, $Until, $Temperature))) {
            return false;
        }
        $this->sendRequest(CWIFI_Registers::requestFields(CWIFI_Registers::REG_HOLIDAY));
        return true;
    }

    /** Urlaub löschen. */
    public function ClearHoliday(): bool
    {
        $topic = $this->topicFor(CWIFI_Registers::REG_HOLIDAY);
        if ($topic === '' || !$this->sendMQTT($topic, CWIFI_Registers::encodeNoHoliday())) {
            return false;
        }
        $this->sendRequest(CWIFI_Registers::requestFields(CWIFI_Registers::REG_HOLIDAY));
        return true;
    }

    /**
     * Stellt die Uhr des Geräts auf die Symcon-Zeit.
     *
     * Der Nutzen ist nicht kosmetisch: Das Wochenprogramm läuft im Gerät, und eine falsch
     * stehende Uhr verschiebt jeden Schaltpunkt um dieselbe Spanne.
     *
     * **Ein Versuch, keine gesicherte Funktion.** Dass `A4` gelesen die Uhr ist, ist belegt;
     * ob das Gerät das Register auch beschreiben lässt, ist es nicht. Deshalb wird `A4`
     * unmittelbar danach zurückgefordert — erst die Rückmeldung entscheidet, und die neue
     * Abweichung steht dann in der Variable.
     */
    public function SetClock(): bool
    {
        $topic = $this->topicFor(CWIFI_Registers::REG_CLOCK);
        if ($topic === '') {
            return false;
        }

        /* Bewusst QoS 0 — und hier ausnahmsweise nicht nur, weil Symcons MQTT-Instanz
           nichts anderes annimmt: Eine Uhrzeit verdirbt. Läge der Befehl in der Warteschlange
           des Brokers und erreichte ein schlafendes Gerät erst Stunden später, stellte er
           die Uhr auf den Zeitpunkt des Absendens — und damit schlimmer als vorher. Verloren
           ist besser als veraltet; beim nächsten Anlauf ist das Gerät wach. */
        $payload = CWIFI_Registers::encodeClock(time());
        if (!$this->sendMQTT($topic, $payload)) {
            return false;
        }
        $this->SendDebug('Geräteuhr stellen', $payload . ' -> ' . $topic, 0);

        // A4 liegt in der A0-A7-Maske, ein gezielter Nachzieher genügt.
        $this->sendRequest(CWIFI_Registers::requestFields(CWIFI_Registers::REG_CLOCK));
        return true;
    }

    /**
     * Wochenprogramm und Urlaub vom Gerät abrufen.
     *
     * Eigener Knopf statt automatischer Abfrage: Beides ändert sich selten, und jede
     * Abfrage weckt ein Batteriegerät.
     */
    public function RequestSchedule(): bool
    {
        $topic = $this->topicFor(CWIFI_Registers::REG_REQUEST);
        if ($topic === '') {
            return false;
        }
        // A8-AE liegen ausserhalb der A0-A7-Maske, deshalb der komplette Feld-Dump.
        return $this->sendMQTT($topic, CWIFI_Registers::REQUEST_ALL);
    }

    /**
     * Schickt einen maskierten Optionen-Befehl samt gezieltem Nachzieher.
     * Wie beim Sollwert bestätigt das Gerät erst, wenn das Feld angefordert wird.
     */
    private function sendOption(string $payload): bool
    {
        $topic = $this->topicFor(CWIFI_Registers::REG_OPTIONS);
        if ($topic === '' || !$this->sendMQTT($topic, $payload)) {
            return false;
        }
        $this->sendRequest(CWIFI_Registers::requestFields(CWIFI_Registers::REG_OPTIONS));
        return true;
    }

    /** Set-Topic für ein Register, oder '' bei unvollständiger Konfiguration. */
    private function topicFor(string $register): string
    {
        $mac  = CWIFI_Topics::normalizeMac($this->ReadPropertyString('MAC'));
        $user = trim($this->ReadPropertyString('MQTTUser'));
        if ($mac === '' || $user === '') {
            return '';
        }
        return CWIFI_Topics::set($this->ReadPropertyString('TopicPrefix'), $user, $mac, $register);
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

        // Solange der Zeitplan läuft, hält ein gesetzter Sollwert nur bis zum nächsten
        // Schaltpunkt — das Wochenprogramm im Gerät überschreibt ihn dann. Mit
        // „Handbetrieb erzwingen" schaltet das Modul vorher um, damit der Wert bleibt.
        if ($this->ReadPropertyBoolean('ForceManualOnSet')
            && $this->GetValue(CWIFI_Registers::IDENT_MODE) !== true) {
            $this->SetManualMode(true);
        }

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
     * Zeitgesteuerte Nachführung der Geräteuhr.
     *
     * Stellt nur, wenn es nötig ist: Steht die Uhr innerhalb der Hinweisschwelle, bleibt das
     * Gerät in Ruhe. Ein Batteriegerät für eine Korrektur von zwei Minuten zu wecken, wäre
     * genau die Sorte Betriebsamkeit, die dieses Modul vermeiden soll.
     */
    public function SyncClock(): void
    {
        if ($this->ReadPropertyInteger('ClockSyncDays') <= 0) {
            return;
        }
        if (!$this->clockOffLimits()) {
            $this->SendDebug('Uhr-Nachführung', 'Abweichung im Rahmen, nichts zu tun', 0);
            return;
        }
        $this->SendDebug('Uhr-Nachführung', 'Abweichung zu groß, Uhr wird gestellt', 0);
        $this->SetClock();
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

        // Nachführung der Uhr. Ein Tag ist reichlich: Die Uhren laufen richtig, sie stehen
        // nur falsch — beobachtet wurde über gut drei Stunden kein messbarer Gang. Der
        // Versatz aus der MAC verhindert, dass zehn Geräte gleichzeitig geweckt werden.
        $tage = $this->ReadPropertyInteger('ClockSyncDays');
        if ($tage <= 0) {
            $this->SetTimerInterval('CWIFI_ClockSync', 0);
        } else {
            $intervall = $tage * 86400;
            $this->SetTimerInterval('CWIFI_ClockSync', $intervall * 1000);
            $this->SendDebug(
                'Uhr-Nachführung',
                sprintf('alle %d Tage, Versatz %d s', $tage, crc32($mac) % $intervall),
                0
            );
        }
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

        /* ---------------------------------------------- Optionen (Register A3) */

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_MODE,
            $this->Translate('Operating mode'),
            VARIABLETYPE_BOOLEAN,
            $this->optionPresentation('Schedule', 'Manual'),
            70,
            true
        );
        $this->EnableAction(CWIFI_Registers::IDENT_MODE);

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_KEYLOCK,
            $this->Translate('Key lock'),
            VARIABLETYPE_INTEGER,
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS'      => json_encode([
                    $this->enumOption(CWIFI_Registers::LOCK_OFF, 'Off'),
                    $this->enumOption(CWIFI_Registers::LOCK_ON, 'On'),
                    $this->enumOption(CWIFI_Registers::LOCK_PLUS, 'Plus')
                ])
            ],
            80,
            true
        );
        $this->EnableAction(CWIFI_Registers::IDENT_KEYLOCK);

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_ROTATE,
            $this->Translate('Rotate display by 180°'),
            VARIABLETYPE_BOOLEAN,
            ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH],
            90,
            true
        );
        $this->EnableAction(CWIFI_Registers::IDENT_ROTATE);

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_DST,
            $this->Translate('Automatic daylight saving time'),
            VARIABLETYPE_BOOLEAN,
            ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH],
            95,
            true
        );
        $this->EnableAction(CWIFI_Registers::IDENT_DST);

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_OFFSET,
            $this->Translate('Temperature offset'),
            VARIABLETYPE_FLOAT,
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN'          => -6.0,
                'MAX'          => 6.0,
                'STEP_SIZE'    => 0.5,
                'SUFFIX'       => ' K',
                'DIGITS'       => 1
            ],
            96,
            true
        );
        $this->EnableAction(CWIFI_Registers::IDENT_OFFSET);

        /* ------------------------------------------------ Urlaub (Register A7) */

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_HOLIDAY,
            $this->Translate('Holiday active'),
            VARIABLETYPE_BOOLEAN,
            $this->booleanPresentation('Yes', 'No', false),
            100,
            true
        );
        $this->MaintainVariable(
            CWIFI_Registers::IDENT_HOLIDAY_FROM,
            $this->Translate('Holiday from'),
            VARIABLETYPE_INTEGER,
            '~UnixTimestamp',
            101,
            true
        );
        $this->MaintainVariable(
            CWIFI_Registers::IDENT_HOLIDAY_TO,
            $this->Translate('Holiday until'),
            VARIABLETYPE_INTEGER,
            '~UnixTimestamp',
            102,
            true
        );
        $this->MaintainVariable(
            CWIFI_Registers::IDENT_HOLIDAY_TEMP,
            $this->Translate('Holiday temperature'),
            VARIABLETYPE_FLOAT,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 1],
            103,
            true
        );

        /* --------------------------------- Wochenprogramm (Register A8–AE, nur lesend) */

        $this->MaintainVariable(
            CWIFI_Registers::IDENT_SCHEDULE,
            $this->Translate('Weekly schedule'),
            VARIABLETYPE_STRING,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            110,
            true
        );
    }

    /** Zweiwertige Darstellung ohne Ampelfarbe — für Betriebsarten statt Zuständen. */
    private function optionPresentation(string $captionFalse, string $captionTrue): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'CAPTION_ON'   => $this->Translate($captionTrue),
            'CAPTION_OFF'  => $this->Translate($captionFalse)
        ];
    }

    private function enumOption(int $value, string $caption): array
    {
        return [
            'Value'       => $value,
            'Caption'     => $this->Translate($caption),
            'IconActive'  => false,
            'Icon'        => '',
            'ColorActive' => false,
            'ColorValue'  => -1
        ];
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
        // Muss hier stehen und nicht dort, wo die Uhr ankommt: Sonst wischt die nächste
        // Gerätemeldung über markAlive() den Hinweis wieder weg. Das Gerät ist ja
        // erreichbar — nur seine Schaltzeiten stimmen nicht.
        if ($this->clockOffLimits()) {
            $this->SetStatus(207);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
    }
}
