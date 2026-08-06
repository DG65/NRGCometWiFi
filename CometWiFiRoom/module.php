<?php

declare(strict_types=1);

require_once __DIR__ . '/../.libs/CWIFI_Registers.php';
require_once __DIR__ . '/../.libs/CWIFI_Topics.php';

/**
 * Ein Raum aus mehreren Thermostaten — eine Instanz, die man bedient wie ein Gerät.
 *
 * **Warum nicht über den Gruppenkanal des Herstellers.** Die Geräte kennen Gruppen: Ein
 * Mitglied abonniert `+/<benutzer>/<KOPF-MAC>/G/#`, und ein Publish dorthin erreicht alle auf
 * einmal. Dieses Modul benutzt das trotzdem nicht, aus zwei Gründen:
 *
 * 1. Das Nutzdatenformat auf dem Gruppenkanal ist nicht belegt — es wurde nie einer
 *    beobachtet. `S/A0` dagegen ist am Gerät nachgewiesen.
 * 2. Ein Raum ist nicht immer eine Gerätegruppe. Wer zwei Thermostate in einem Zimmer hat,
 *    die in der Hersteller-App gar nicht gekoppelt sind, will sie hier trotzdem zusammen
 *    schalten können.
 *
 * Geschrieben wird deshalb an **jedes Mitglied einzeln**, über dessen Geräteinstanz. Damit
 * gilt auch hier die dort eingestellte Umschaltung auf Handbetrieb, und ein Fehler in diesem
 * Modul kann die MQTT-Anbindung der Geräte nicht beeinträchtigen.
 */
class CometWiFiRoom extends IPSModule
{
    private const DEVICE_MODULE = '{0F552C16-D685-4C9F-86C0-8D89E4BFD158}';

    public const IDENT_TEMPERATURE = 'Temperature';
    public const IDENT_SETPOINT    = 'Setpoint';
    public const IDENT_MIXED       = 'Mixed';
    public const IDENT_MODE        = 'Mode';
    public const IDENT_BATTERY     = 'Battery';
    public const IDENT_BATTERY_LOW = 'BatteryLow';
    public const IDENT_REACHABLE   = 'Reachable';
    public const IDENT_MEMBERS     = 'MemberCount';

    /** Variablen der Mitglieder, deren Änderung den Raum neu rechnen lässt. */
    private const WATCH = [
        CWIFI_Registers::IDENT_TEMPERATURE,
        CWIFI_Registers::IDENT_SETPOINT,
        CWIFI_Registers::IDENT_BATTERY,
        CWIFI_Registers::IDENT_REACHABLE,
        CWIFI_Registers::IDENT_MODE,
        CWIFI_Registers::IDENT_CLOCK_DEV
    ];

    /* ================================================================== Lebenszyklus */

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Members', '[]');
        $this->RegisterPropertyString('Aggregation', 'average');
        $this->RegisterPropertyInteger('BatteryWarnBelow', 25);

        $this->RegisterPropertyFloat('SetpointMin', CWIFI_Registers::SETPOINT_OFF);
        $this->RegisterPropertyFloat('SetpointMax', CWIFI_Registers::SETPOINT_ON);
        $this->RegisterPropertyInteger('ClockWarnMinutes', 15);
        $this->RegisterPropertyBoolean('AllowControl', true);
        $this->RegisterPropertyBoolean('ShowDetails', true);

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);

        foreach ($this->GetMessageList() as $senderId => $messages) {
            foreach ($messages as $message) {
                if ($message === VM_UPDATE) {
                    $this->UnregisterMessage($senderId, VM_UPDATE);
                }
            }
        }

        $this->maintainVariables();

        $mitglieder = $this->members();
        foreach ($mitglieder as $instanceId) {
            foreach (self::WATCH as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $instanceId);
                if ($vid !== false && $vid > 0) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
        }

        $this->SetStatus($mitglieder === [] ? 201 : IS_ACTIVE);
        $this->recalculate();
        $this->UpdateVisualizationValue($this->buildPayload());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE) {
            $this->recalculate();
            $this->UpdateVisualizationValue($this->buildPayload());
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case self::IDENT_SETPOINT:
                $this->SetTemperature((float) $Value);
                break;

            case self::IDENT_MODE:
                $this->SetManualMode((bool) $Value);
                break;

            /* Aus der Kachel. Sie schickt kleingeschriebene Kennungen, damit die Vorlage für
               Gerät und Raum dieselbe bleiben kann. */
            case 'setpoint':
                if ($this->ReadPropertyBoolean('AllowControl')) {
                    $this->SetTemperature((float) $Value);
                }
                break;

            case 'mode':
                if ($this->ReadPropertyBoolean('AllowControl')) {
                    $this->SetManualMode((bool) $Value);
                }
                break;

            case 'refresh':
                $this->RequestUpdate();
                break;

            case 'requestAll':
                $this->RequestAllFields();
                break;

            case 'requestSchedule':
                $this->RequestSchedule();
                break;

            case 'setClock':
                $this->SetClock();
                break;
        }
        $this->UpdateVisualizationValue($this->buildPayload());
    }

    /* ================================================================== Darstellung */

    public function GetVisualizationTile()
    {
        // Dieselbe Vorlage wie die Raumkachel — ein Raum soll aussehen und sich bedienen
        // lassen wie ein Gerät, sonst ist die Zusammenfassung nur halb gedacht.
        $html = file_get_contents(__DIR__ . '/../.libs/CWIFI_RoomTile.html');
        return $html . '<script>handleMessage(' . json_encode($this->buildPayload()) . ');</script>';
    }

    /**
     * Nutzlast in genau der Form, die die gemeinsame Kachelvorlage erwartet.
     *
     * Felder, die es nur am Einzelgerät gibt — Signalstärke, Tastensperre, Urlaub — bleiben
     * leer. Die Vorlage lässt sie dann weg, statt Platzhalter zu zeichnen.
     */
    private function buildPayload(): string
    {
        $mitglieder = $this->members();
        if ($mitglieder === []) {
            return json_encode(['ok' => false]);
        }

        $temp     = $this->GetValue(self::IDENT_TEMPERATURE);
        $setpoint = $this->GetValue(self::IDENT_SETPOINT);
        $battery  = $this->GetValue(self::IDENT_BATTERY);

        $abw   = 0;
        $offen = false;
        $grenze = $this->ReadPropertyInteger('ClockWarnMinutes');
        $juengste = 0;
        foreach ($mitglieder as $id) {
            $d = $this->valueOf($id, CWIFI_Registers::IDENT_CLOCK_DEV);
            if (is_numeric($d) && abs((int) $d) > abs($abw)) {
                $abw = (int) $d;
            }
            $l = $this->valueOf($id, CWIFI_Registers::IDENT_LAST_UPDATE);
            if (is_numeric($l) && (int) $l > $juengste) {
                $juengste = (int) $l;
            }
        }
        if ($grenze > 0 && abs($abw) >= $grenze) {
            $offen = true;
        }

        return json_encode([
            'ok'         => true,
            'name'       => IPS_GetName($this->InstanceID),
            'temp'       => is_numeric($temp) && $temp > 0 ? round((float) $temp, 1) : null,
            'setpoint'   => is_numeric($setpoint) ? round((float) $setpoint, 1) : null,
            'endstop'    => is_numeric($setpoint) ? $this->endstopLabel((float) $setpoint) : null,
            'heating'    => is_numeric($temp) && is_numeric($setpoint) && $temp > 0
                            && (float) $setpoint > (float) $temp + 0.2,
            'battery'    => is_numeric($battery) && $battery > 0 ? (int) $battery : null,
            'batteryLow' => (bool) $this->GetValue(self::IDENT_BATTERY_LOW),
            'signal'     => null,
            'reachable'  => (bool) $this->GetValue(self::IDENT_REACHABLE),
            'manual'     => (bool) $this->GetValue(self::IDENT_MODE),
            'keylock'    => 0,
            'offset'     => null,
            'holiday'    => false,
            'clockDev'   => $abw,
            'clockOff'   => $offen,
            'mixed'      => (bool) $this->GetValue(self::IDENT_MIXED),
            'members'    => count($mitglieder),

            /* Wochenprogramm, Urlaub und Geräteauskunft gehören zum einzelnen Thermostat und
               können sich zwischen den Mitgliedern unterscheiden. Ein Raum darf hier nichts
               behaupten — die Kachel lässt die Abschnitte dann weg. */
            'schedule'    => null,
            'holidayFrom' => 0,
            'holidayTo'   => 0,
            'holidayTemp' => null,
            'model'       => null,
            'firmware'    => null,
            'ip'          => null,
            'group'       => null,
            'clock'       => null,
            'lastText'   => $juengste > 0 ? $this->ago($juengste) : null,
            'control'    => $this->ReadPropertyBoolean('AllowControl'),
            'details'    => $this->ReadPropertyBoolean('ShowDetails'),
            'minTemp'    => $this->ReadPropertyFloat('SetpointMin'),
            'maxTemp'    => $this->ReadPropertyFloat('SetpointMax')
        ]);
    }

    private function endstopLabel(float $setpoint): ?string
    {
        if (abs($setpoint - CWIFI_Registers::SETPOINT_OFF) < 0.01) {
            return 'Aus';
        }
        if (abs($setpoint - CWIFI_Registers::SETPOINT_ON) < 0.01) {
            return 'An';
        }
        return null;
    }

    private function ago(int $timestamp): string
    {
        $sekunden = time() - $timestamp;
        if ($sekunden < 90) {
            return 'gerade eben';
        }
        if ($sekunden < 5400) {
            return 'vor ' . (int) round($sekunden / 60) . ' min';
        }
        if ($sekunden < 86400) {
            $stunden = (int) round($sekunden / 3600);
            return 'vor ' . $stunden . ($stunden === 1 ? ' Stunde' : ' Stunden');
        }
        $tage = (int) round($sekunden / 86400);
        return 'vor ' . $tage . ($tage === 1 ? ' Tag' : ' Tagen');
    }

    /* ================================================================== Bedienung */

    /**
     * Setzt den Sollwert im ganzen Raum.
     *
     * Jedes Mitglied bekommt den Wert einzeln über seine Geräteinstanz. Ein nicht
     * erreichbares Mitglied lässt den Rest nicht scheitern — der Rückgabewert sagt nur, ob
     * **alle** erreicht wurden.
     */
    public function SetTemperature(float $Temperature): bool
    {
        $mitglieder = $this->members();
        if ($mitglieder === []) {
            return false;
        }

        $min = $this->ReadPropertyFloat('SetpointMin');
        $max = $this->ReadPropertyFloat('SetpointMax');
        $wert = max($min, min($max, round($Temperature * 2) / 2));

        $alle = true;
        foreach ($mitglieder as $instanceId) {
            if (!$this->callDevice('CWIFI_SetTemperature', $instanceId, $wert)) {
                $alle = false;
                $this->SendDebug('Raum', 'Mitglied ' . $instanceId . ' hat den Sollwert nicht angenommen', 0);
            }
        }

        // Sofort anzeigen — die Geräte bestätigen erst mit Verzögerung, und der Schieberegler
        // soll nicht sichtbar zurückspringen.
        $this->SetValue(self::IDENT_SETPOINT, $wert);
        $this->SetValue(self::IDENT_MIXED, false);
        return $alle;
    }

    /** Schaltet den ganzen Raum zwischen Wochenprogramm und Handbetrieb. */
    public function SetManualMode(bool $Manual): bool
    {
        $mitglieder = $this->members();
        if ($mitglieder === []) {
            return false;
        }
        $alle = true;
        foreach ($mitglieder as $instanceId) {
            if (!$this->callDevice('CWIFI_SetManualMode', $instanceId, $Manual)) {
                $alle = false;
            }
        }
        $this->SetValue(self::IDENT_MODE, $Manual);
        return $alle;
    }

    /** Fordert bei allen Mitgliedern frische Werte an. */
    public function RequestUpdate(): bool
    {
        return $this->allMembers('CWIFI_RequestUpdate');
    }

    /**
     * Fordert bei allen Mitgliedern alle Felder an.
     *
     * Das ist der teuerste Abruf, den es gibt — er weckt jedes Gerät vollständig. Deshalb
     * gibt es ihn nur auf ausdrücklichen Knopfdruck und nie auf einem Zeitgeber.
     */
    public function RequestAllFields(): bool
    {
        return $this->allMembers('CWIFI_RequestAllFields');
    }

    /** Holt Wochenprogramm und Urlaub aller Mitglieder. */
    public function RequestSchedule(): bool
    {
        return $this->allMembers('CWIFI_RequestSchedule');
    }

    /** Stellt die Uhr aller Mitglieder auf die Symcon-Zeit. */
    public function SetClock(): bool
    {
        return $this->allMembers('CWIFI_SetClock');
    }

    /** Ruft dieselbe Funktion an jedem Mitglied auf. */
    private function allMembers(string $funktion): bool
    {
        $mitglieder = $this->members();
        if ($mitglieder === []) {
            return false;
        }
        $alle = true;
        foreach ($mitglieder as $instanceId) {
            if (!$this->callDevice($funktion, $instanceId)) {
                $alle = false;
            }
        }
        return $alle;
    }

    /**
     * Ergänzt die Mitgliederliste um alle Geräte derselben Gerätegruppe.
     *
     * Die Zugehörigkeit steht in Register `B0` und damit in der Variable `Group` jeder
     * Geräteinstanz. Wer einen Raum anlegt, wählt also ein Thermostat aus und lässt sich den
     * Rest holen — statt MACs zu vergleichen.
     */
    public function AddGroupMembers(): int
    {
        $vorhanden = $this->members();
        if ($vorhanden === []) {
            $this->SendDebug('Raum', 'Erst ein Gerät auswählen, dann ergänzen', 0);
            return 0;
        }

        // Gruppenköpfe der bereits gewählten Geräte einsammeln.
        $koepfe = [];
        foreach ($vorhanden as $instanceId) {
            $kopf = $this->groupHeadOf($instanceId);
            if ($kopf !== '') {
                $koepfe[$kopf] = true;
            }
        }
        if ($koepfe === []) {
            $this->SendDebug('Raum', 'Die gewählten Geräte gehören zu keiner Gerätegruppe', 0);
            return 0;
        }

        $neu = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE) as $instanceId) {
            if (in_array($instanceId, $vorhanden, true)) {
                continue;
            }
            $kopf = $this->groupHeadOf($instanceId);
            if ($kopf !== '' && isset($koepfe[$kopf])) {
                $neu[] = $instanceId;
            }
        }

        if ($neu === []) {
            return 0;
        }

        // Keine Selbstpersistenz im Formular — die Liste wird nur vorgeschlagen, übernehmen
        // muss sie der Nutzer selbst (Store-Review-Konvention des Verbunds).
        $liste = [];
        foreach (array_merge($vorhanden, $neu) as $instanceId) {
            $liste[] = ['DeviceID' => $instanceId];
        }
        $this->UpdateFormField('Members', 'values', json_encode($liste));
        $this->UpdateFormField(
            'MembersHint',
            'caption',
            '➕  ' . count($neu) . ' Gerät(e) der Gruppe ergänzt — noch mit „Übernehmen" bestätigen.'
        );
        return count($neu);
    }

    /**
     * Ruft eine Funktion des Gerätemoduls auf, falls es sie gibt.
     *
     * `@` hilft hier nicht: Ein fehlender Funktionsaufruf ist in PHP 8 ein `Error` und kein
     * abschaltbarer Hinweis. Ohne diese Prüfung risse ein nicht geladenes Gerätemodul das
     * ganze Raummodul mit — und zwar mit einem Fatal Error mitten im Setzen, nicht mit einer
     * lesbaren Meldung.
     */
    private function callDevice(string $funktion, int $instanceId, ...$argumente): bool
    {
        if (!function_exists($funktion)) {
            $this->SendDebug('Raum', 'Gerätemodul nicht geladen: ' . $funktion . ' fehlt', 0);
            return false;
        }
        return (bool) $funktion($instanceId, ...$argumente);
    }

    /* ===================================================================== Intern */

    /** Die gültigen Mitglieds-Instanzen, doppelte und fremde herausgefiltert. */
    private function members(): array
    {
        $liste = json_decode($this->ReadPropertyString('Members'), true);
        if (!is_array($liste)) {
            return [];
        }

        $geraete = IPS_GetInstanceListByModuleID(self::DEVICE_MODULE);
        $ids = [];
        foreach ($liste as $zeile) {
            $id = (int) ($zeile['DeviceID'] ?? 0);
            if ($id > 0 && in_array($id, $geraete, true) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** MAC des Gruppenkopfs eines Geräts — leer, wenn es ein Einzelgerät ist. */
    private function groupHeadOf(int $instanceId): string
    {
        $roh = $this->valueOf($instanceId, 'RAW_B0');
        if (!is_string($roh) || $roh === '') {
            // Ist das Register bereits gedeutet, gibt es die Rohfassung nicht mehr. Dann
            // führt der Weg über die eigene MAC des Kopfgeräts, siehe unten.
            $roh = '';
        }
        if ($roh !== '') {
            $mac = CWIFI_Topics::normalizeMac(substr(ltrim($roh, '#'), 1));
            return trim($mac, '0') === '' ? '' : $mac;
        }

        /* Die gedeutete Variable trägt den Namen des Kopfgeräts, nicht dessen MAC. „Gruppenkopf"
           heißt: das Gerät ist selbst der Kopf, also zählt seine eigene MAC. */
        $text = $this->valueOf($instanceId, CWIFI_Registers::IDENT_GROUP);
        if (!is_string($text) || $text === '' || $text === 'Einzelgerät') {
            return '';
        }
        if ($text === 'Gruppenkopf') {
            return CWIFI_Topics::normalizeMac((string) @IPS_GetProperty($instanceId, 'MAC'));
        }
        if (preg_match('/([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})/', $text, $treffer)) {
            return CWIFI_Topics::normalizeMac($treffer[1]);
        }
        // „Gruppe mit <Name>" — den Namen auf eine Instanz zurückführen.
        $name = trim(str_replace('Gruppe mit ', '', $text));
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE) as $id) {
            if (IPS_GetName($id) === $name) {
                return CWIFI_Topics::normalizeMac((string) @IPS_GetProperty($id, 'MAC'));
            }
        }
        return '';
    }

    private function valueOf(int $instanceId, string $ident)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $instanceId);
        return ($vid !== false && $vid > 0) ? @GetValue($vid) : null;
    }

    /**
     * Rechnet die Raumwerte aus den Mitgliedern.
     *
     * Die Entscheidungen hier sind bewusst und nicht beliebig:
     * - **Isttemperatur**: Mittel oder Kleinstwert, je nach Einstellung. Der Kleinstwert ist
     *   für die Heizung die ehrlichere Zahl — er ist die kälteste Ecke des Raums.
     * - **Sollwert**: Sind sich die Mitglieder einig, ist es dieser Wert. Sonst der höchste,
     *   und `Uneinheitlich` wird gesetzt. Einen Mittelwert zu bilden wäre falsch: 20 und 24
     *   ergeben keinen Raum mit 22, sondern einen Raum mit zwei verschiedenen Vorgaben.
     * - **Erreichbar**: nur wenn ALLE erreichbar sind. Ein Raum, in dem ein Ventil nicht
     *   antwortet, ist nicht vollständig geschaltet.
     * - **Batterie**: der schlechteste Wert. Genau der bestimmt, wann jemand hin muss.
     */
    private function recalculate(): void
    {
        $mitglieder = $this->members();
        if ($mitglieder === []) {
            $this->SetValue(self::IDENT_MEMBERS, 0);
            $this->SetValue(self::IDENT_REACHABLE, false);
            return;
        }

        $temperaturen = [];
        $sollwerte    = [];
        $batterien    = [];
        $erreichbar   = true;
        $alleManuell  = true;

        foreach ($mitglieder as $id) {
            $t = $this->valueOf($id, CWIFI_Registers::IDENT_TEMPERATURE);
            if (is_numeric($t) && $t > 0) {
                $temperaturen[] = (float) $t;
            }
            $s = $this->valueOf($id, CWIFI_Registers::IDENT_SETPOINT);
            if (is_numeric($s)) {
                $sollwerte[] = round((float) $s, 1);
            }
            $b = $this->valueOf($id, CWIFI_Registers::IDENT_BATTERY);
            if (is_numeric($b) && $b > 0) {
                $batterien[] = (int) $b;
            }
            if ($this->valueOf($id, CWIFI_Registers::IDENT_REACHABLE) !== true) {
                $erreichbar = false;
            }
            if ($this->valueOf($id, CWIFI_Registers::IDENT_MODE) !== true) {
                $alleManuell = false;
            }
        }

        $this->SetValue(self::IDENT_MEMBERS, count($mitglieder));
        $this->SetValue(self::IDENT_REACHABLE, $erreichbar);
        $this->SetValue(self::IDENT_MODE, $alleManuell);

        if ($temperaturen !== []) {
            $wert = $this->ReadPropertyString('Aggregation') === 'minimum'
                ? min($temperaturen)
                : array_sum($temperaturen) / count($temperaturen);
            $this->SetValue(self::IDENT_TEMPERATURE, round($wert, 1));
        }

        if ($sollwerte !== []) {
            $einheitlich = count(array_unique($sollwerte)) === 1;
            $this->SetValue(self::IDENT_SETPOINT, $einheitlich ? $sollwerte[0] : max($sollwerte));
            $this->SetValue(self::IDENT_MIXED, !$einheitlich);
        }

        if ($batterien !== []) {
            $schwaechste = min($batterien);
            $this->SetValue(self::IDENT_BATTERY, $schwaechste);
            $this->SetValue(
                self::IDENT_BATTERY_LOW,
                $schwaechste < $this->ReadPropertyInteger('BatteryWarnBelow')
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

        $this->MaintainVariable(self::IDENT_TEMPERATURE, $this->Translate('Current temperature'),
            VARIABLETYPE_FLOAT,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 1],
            10, true);

        $this->MaintainVariable(self::IDENT_SETPOINT, $this->Translate('Target temperature'),
            VARIABLETYPE_FLOAT,
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN' => $min, 'MAX' => $max, 'STEP_SIZE' => 0.5,
                'SUFFIX' => ' °C', 'DIGITS' => 1
            ],
            20, true);
        $this->EnableAction(self::IDENT_SETPOINT);

        $this->MaintainVariable(self::IDENT_MIXED, $this->Translate('Members disagree'),
            VARIABLETYPE_BOOLEAN, $this->booleanPresentation('Yes', 'No', true), 25, true);

        $this->MaintainVariable(self::IDENT_MODE, $this->Translate('Operating mode'),
            VARIABLETYPE_BOOLEAN, $this->booleanPresentation('Manual', 'Schedule', false), 30, true);
        $this->EnableAction(self::IDENT_MODE);

        $this->MaintainVariable(self::IDENT_BATTERY, $this->Translate('Weakest battery'),
            VARIABLETYPE_INTEGER,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'DIGITS' => 0],
            40, true);

        $this->MaintainVariable(self::IDENT_BATTERY_LOW, $this->Translate('Battery low'),
            VARIABLETYPE_BOOLEAN, $this->booleanPresentation('Battery low', 'OK', true), 45, true);

        $this->MaintainVariable(self::IDENT_REACHABLE, $this->Translate('All reachable'),
            VARIABLETYPE_BOOLEAN, $this->booleanPresentation('Yes', 'No', false), 50, true);

        $this->MaintainVariable(self::IDENT_MEMBERS, $this->Translate('Members'),
            VARIABLETYPE_INTEGER,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'DIGITS' => 0],
            60, true);
    }

    private function booleanPresentation(string $captionTrue, string $captionFalse, bool $trueIsBad): array
    {
        $red   = 0xFF0000;
        $green = 0x00A000;

        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                [
                    'Value' => true, 'Caption' => $this->Translate($captionTrue),
                    'IconActive' => false, 'Icon' => '',
                    'ColorActive' => true, 'ColorValue' => $trueIsBad ? $red : $green
                ],
                [
                    'Value' => false, 'Caption' => $this->Translate($captionFalse),
                    'IconActive' => false, 'Icon' => '',
                    'ColorActive' => true, 'ColorValue' => $trueIsBad ? $green : $red
                ]
            ])
        ];
    }

    /* ================================================================== Formular */

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $mitglieder = $this->members();
        $namen = array_map(fn ($id) => IPS_GetName($id), $mitglieder);
        $text = $mitglieder === []
            ? '⚠️  Noch kein Thermostat zugeordnet — der Raum bleibt ohne Werte.'
            : '✅  ' . count($mitglieder) . ' Thermostat(e): ' . implode(', ', $namen);

        foreach ($form['elements'] as &$element) {
            foreach ($element['items'] ?? [] as &$item) {
                if (($item['name'] ?? '') === 'MembersHint') {
                    $item['caption'] = $text;
                }
            }
        }
        return json_encode($form);
    }
}
