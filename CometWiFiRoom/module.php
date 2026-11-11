<?php

declare(strict_types=1);

require_once __DIR__ . '/../.libs/CWIFI_Registers.php';
require_once __DIR__ . '/../.libs/CWIFI_Text.php';
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
    /** Fassung des „Was ist Neu"-Panels — nur bei wichtigen Änderungen hochzählen. */
    private const NEWS_VERSION   = '0.20';
    private const ATTR_SEEN_NEWS = 'SeenNews';

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

        $this->RegisterAttributeString(self::ATTR_SEEN_NEWS, '');

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
        $this->maintainVariables();

        // Der Abgleich mit den Mitgliedern gehört nicht in ApplyChanges: Beim Neuladen ist das
        // Gerätemodul womöglich noch nicht geladen (Store-Checkliste 27). Deshalb erst bei KR_READY.
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->attachMembers();
    }

    /** Alles, was die Mitglieder braucht: Nachrichten, Status, Raumwerte, Kachel. */
    private function attachMembers(): void
    {
        foreach ($this->GetMessageList() as $senderId => $messages) {
            foreach ($messages as $message) {
                if ($message === VM_UPDATE) {
                    $this->UnregisterMessage($senderId, VM_UPDATE);
                }
            }
        }

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
        if ($SenderID === 0 && $Message === IPS_KERNELMESSAGE && ($Data[0] ?? 0) === KR_READY) {
            $this->attachMembers();
            return;
        }
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

            case 'Action':
                switch ((int) $Value) {
                    case 1: $this->RequestUpdate(); break;
                    case 2: $this->RequestSchedule(); break;
                    case 3: $this->SetClock(); break;
                    case 4: $this->RequestAllFields(); break;
                }
                $this->SetValue('Action', 0);
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
        $t = $this->t();
        if ($mitglieder === []) {
            return json_encode(['ok' => false, 'texts' => CWIFI_Text::roomTileTexts($t)]);
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
            'texts'      => CWIFI_Text::roomTileTexts($t),
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
            'lastText'   => $juengste > 0 ? CWIFI_Text::ago(time() - $juengste, $t, true) : null,
            'control'    => $this->ReadPropertyBoolean('AllowControl'),
            'details'    => $this->ReadPropertyBoolean('ShowDetails'),
            'minTemp'    => $this->ReadPropertyFloat('SetpointMin'),
            'maxTemp'    => $this->ReadPropertyFloat('SetpointMax')
        ]);
    }

    private function endstopLabel(float $setpoint): ?string
    {
        if (abs($setpoint - CWIFI_Registers::SETPOINT_OFF) < 0.01) {
            return 'off';
        }
        if (abs($setpoint - CWIFI_Registers::SETPOINT_ON) < 0.01) {
            return 'on';
        }
        return null;
    }

    private function t(): callable
    {
        return CWIFI_Text::translator(fn (string $text): string => $this->Translate($text));
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
                $this->SendDebug('Room', 'Member ' . $instanceId . ' did not accept the setpoint', 0);
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
    public function RequestUpdate(): string
    {
        return $this->memberReport('Update', 'Temperatures requested');
    }

    /**
     * Fordert bei allen Mitgliedern alle Felder an.
     *
     * Das ist der teuerste Abruf, den es gibt — er weckt jedes Gerät vollständig. Deshalb
     * gibt es ihn nur auf ausdrücklichen Knopfdruck und nie auf einem Zeitgeber.
     */
    public function RequestAllFields(): string
    {
        return $this->memberReport('AllFields', 'All fields requested');
    }

    /** Holt Wochenprogramm und Urlaub aller Mitglieder. */
    public function RequestSchedule(): string
    {
        return $this->memberReport('Schedule', 'Weekly schedule and holiday requested');
    }

    /** Stellt die Uhr aller Mitglieder auf die Symcon-Zeit. */
    public function SetClock(): string
    {
        return $this->memberReport('Clock', 'Time sent');
    }

    /**
     * Ruft dieselbe Aktion an jedem Mitglied auf und zählt die Erfolge.
     *
     * Über `CWIFI_SendAction`, nicht über die gleichnamigen Gerätemethoden: Die liefern seit
     * 0.19.0 Anzeigetext, und ein nicht leerer Fehlertext wäre `true` — der Raum meldete dann
     * Erfolg für jedes Gerät, das gar nichts gesendet hat.
     *
     * @return int[] [erfolgreich, gesamt]
     */
    private function allMembers(string $aktion): array
    {
        $mitglieder = $this->members();
        $ok = 0;
        foreach ($mitglieder as $instanceId) {
            if ($this->callDevice('CWIFI_SendAction', $instanceId, $aktion)) {
                $ok++;
            }
        }
        return [$ok, count($mitglieder)];
    }

    /**
     * Führt eine Aktion an allen Mitgliedern aus und meldet das Ergebnis in Klartext.
     *
     * Der Raum nennt Zahlen, wo das einzelne Gerät nur ja/nein sagen kann: „an 4 von 5
     * Geräten" ist die einzige Meldung, die ein halb geglücktes Sammelkommando ehrlich
     * beschreibt — und genau die ist der Fall, den man sehen will.
     */
    private function memberReport(string $aktion, string $was): string
    {
        [$ok, $gesamt] = $this->allMembers($aktion);

        $was = $this->Translate($was);
        if ($gesamt === 0) {
            return $this->Translate('⚠️  No member selected — please assign thermostats first.');
        }
        if ($ok === 0) {
            return sprintf(
                $this->Translate('⚠️  %s: did not succeed on any of the %d devices. Check the MAC address, the MQTT user and the parent MQTT client.'),
                $was,
                $gesamt
            );
        }
        if ($ok < $gesamt) {
            return sprintf(
                $this->Translate('⚠️  %s on %d of %d devices (%s). The others are configured as unreachable.'),
                $was,
                $ok,
                $gesamt,
                date('H:i:s')
            );
        }
        if ($gesamt === 1) {
            return sprintf(
                $this->Translate('✅  %s on the device (%s). The values appear as soon as the device answers.'),
                $was,
                date('H:i:s')
            );
        }
        return sprintf(
            $this->Translate('✅  %s on all %d devices (%s). The values appear as soon as the devices answer.'),
            $was,
            $gesamt,
            date('H:i:s')
        );
    }

    /**
     * Ergänzt die Mitgliederliste um alle Geräte derselben Gerätegruppe.
     *
     * Die Zugehörigkeit steht in Register `B0` und damit in der Variable `Group` jeder
     * Geräteinstanz. Wer einen Raum anlegt, wählt also ein Thermostat aus und lässt sich den
     * Rest holen — statt MACs zu vergleichen.
     */
    public function AddGroupMembers(): string
    {
        $vorhanden = $this->members();
        if ($vorhanden === []) {
            return $this->Translate('⚠️  Assign a thermostat first, then add — otherwise the room does not know which device group to look for.');
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
            return $this->Translate('⚠️  The assigned devices do not belong to any device group. Group membership is in register B0 — it only appears once the device has reported it ("Request all fields" on the thermostat).');
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
            return $this->Translate('ℹ️  Nothing to add — all devices of this device group are already assigned.');
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
            $this->Translate('➕  Suggestion is in the list — still confirm it with "Apply".')
        );
        return sprintf(
            $this->Translate(count($neu) === 1
                ? '✅  %d device of the same device group added. The list is only a suggestion — confirm with "Apply", otherwise the suggestion is lost.'
                : '✅  %d devices of the same device group added. The list is only a suggestion — confirm with "Apply", otherwise the suggestion is lost.'),
            count($neu)
        );
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
            $this->SendDebug('Room', 'Device module not loaded: ' . $funktion . ' is missing', 0);
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
        // Die Variable trägt Anzeigetext der Sprache, in der sie geschrieben wurde: englischer
        // Quelltext und die aktuelle Übersetzung gelten beide.
        if (!is_string($text) || $text === ''
            || in_array($text, ['Single device', $this->Translate('Single device')], true)) {
            return '';
        }
        if (in_array($text, ['Group head', $this->Translate('Group head')], true)) {
            return CWIFI_Topics::normalizeMac((string) @IPS_GetProperty($instanceId, 'MAC'));
        }
        if (preg_match('/([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})/', $text, $treffer)) {
            return CWIFI_Topics::normalizeMac($treffer[1]);
        }
        // „Group with <name>" — den Namen auf eine Instanz zurückführen.
        $name = $text;
        foreach (['Group with %s', $this->Translate('Group with %s')] as $muster) {
            $vorsatz = substr($muster, 0, (int) strpos($muster, '%s'));
            if ($vorsatz !== '' && strpos($text, $vorsatz) === 0) {
                $name = trim(substr($text, strlen($vorsatz)));
                break;
            }
        }
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

        $optionen = [];
        foreach ([0 => '–', 1 => 'Refresh now', 2 => 'Fetch schedule & holiday',
                  3 => 'Set clock to Symcon time', 4 => 'Request all fields'] as $wert => $text) {
            $optionen[] = [
                'Value' => $wert, 'Caption' => $this->Translate($text),
                'IconActive' => false, 'IconValue' => '', 'Color' => -1
            ];
        }
        // In der aufgezogenen Ansicht bedienbar; wirkt auf ALLE Mitglieder und springt
        // nach dem Ausführen zurück auf „–".
        $this->MaintainVariable('Action', $this->Translate('Action'),
            VARIABLETYPE_INTEGER,
            ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($optionen)],
            70, true);
        $this->EnableAction('Action');
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
                    'IconActive' => false, 'IconValue' => '',
                    'ColorActive' => true,
                    'ColorValue' => $trueIsBad ? $red : $green
                ],
                [
                    'Value' => false, 'Caption' => $this->Translate($captionFalse),
                    'IconActive' => false, 'IconValue' => '',
                    'ColorActive' => true,
                    'ColorValue' => $trueIsBad ? $green : $red
                ]
            ])
        ];
    }

    /* ================================================================== Formular */

    public function GetConfigurationForm()
    {
        // Die Konsole übersetzt caption/label/Optionen selbst (Store-Review, SUITE.md Punkt 25).
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        $mitglieder = $this->members();
        $namen = array_map(fn ($id) => IPS_GetName($id), $mitglieder);
        $text = $mitglieder === []
            ? $this->Translate('⚠️  No thermostat assigned yet — the room stays without values.')
            : sprintf($this->Translate('✅  %d thermostat(s): %s'), count($mitglieder), implode(', ', $namen));

        foreach ($form['elements'] as &$element) {
            foreach ($element['items'] ?? [] as &$item) {
                if (($item['name'] ?? '') === 'MembersHint') {
                    $item['caption'] = $text;
                }
            }
        }
        return json_encode($form);
    }
    /** Bestätigt das „Was ist Neu"-Panel für genau diese Fassung. */
    public function AckNews(): void
    {
        $this->WriteAttributeString(self::ATTR_SEEN_NEWS, self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    /**
     * Das „Was ist Neu"-Panel, oder null wenn diese Fassung schon bestätigt wurde.
     *
     * Wird in GetConfigurationForm() vor alle anderen Elemente gehängt — die
     * Verbund-Konvention (SUITE.md, „Einheitliche Formular-Optik") verlangt es als
     * erstes Element, aufgeklappt, mit eigenem Bestätigungsknopf.
     */
    private function newsBanner(): ?array
    {
        if ($this->ReadAttributeString(self::ATTR_SEEN_NEWS) === self::NEWS_VERSION) {
            return null;
        }
        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'NewsPanel',
            'caption'  => sprintf($this->Translate('🆕  New in version %s'), self::NEWS_VERSION),
            'expanded' => true,
            'items'    => [
                ['type' => 'Label', 'caption' => $this->Translate('• **Now available in German and English.** All texts follow the Symcon language setting; German remains the reference.')],
                ['type' => 'Label', 'caption' => $this->Translate('• Nothing changes for existing instances: settings, identifiers and functions stay as they are — only the displayed texts depend on the language.')],

                [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Understood – do not show again'),
                    'onClick' => 'CWIFIG_AckNews($id);'
                ]
            ]
        ];
    }

}
