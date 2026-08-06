<?php

declare(strict_types=1);

require_once __DIR__ . '/../.libs/CWIFI_Registers.php';

/**
 * Ein Thermostat, groß dargestellt und bedienbar.
 *
 * Abgrenzung zur Übersichtskachel: Die zeigt zehn Räume nebeneinander und muss deshalb bei
 * jedem sparsam sein. Hier ist genau ein Gerät im Bild, und der Platz gehört der Bedienung —
 * ein Ring, auf den man tippt, statt zweier Knöpfe von 26 Pixeln.
 *
 * Wie die Übersichtskachel bewusst von der Datenlogik getrennt: Ein Fehler in der Darstellung
 * kann die MQTT-Anbindung des Geräts nicht beeinträchtigen. Gesteuert wird über die
 * Geräteinstanz, nicht an ihr vorbei — damit gilt auch von hier aus die Umschaltung auf
 * Handbetrieb.
 */
class CometWiFiRoomTile extends IPSModule
{
    private const DEVICE_MODULE = '{0F552C16-D685-4C9F-86C0-8D89E4BFD158}';

    /** Variablen, deren Änderung die Kachel auffrischen muss. */
    private const WATCH = [
        CWIFI_Registers::IDENT_TEMPERATURE,
        CWIFI_Registers::IDENT_SETPOINT,
        CWIFI_Registers::IDENT_BATTERY,
        CWIFI_Registers::IDENT_BATTERY_LOW,
        CWIFI_Registers::IDENT_RSSI,
        CWIFI_Registers::IDENT_REACHABLE,
        CWIFI_Registers::IDENT_LAST_UPDATE,
        CWIFI_Registers::IDENT_MODE,
        CWIFI_Registers::IDENT_KEYLOCK,
        CWIFI_Registers::IDENT_OFFSET,
        CWIFI_Registers::IDENT_HOLIDAY,
        CWIFI_Registers::IDENT_CLOCK_DEV
    ];

    /* ================================================================== Lebenszyklus */

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('DeviceID', 0);
        $this->RegisterPropertyBoolean('AllowControl', true);
        $this->RegisterPropertyBoolean('ShowDetails', true);
        $this->RegisterPropertyInteger('BatteryWarnBelow', 25);
        $this->RegisterPropertyInteger('ClockWarnMinutes', 15);
        // Verknüpfungen auf die Gerätevariablen — sie füllen die aufgezogene Ansicht.
        $this->RegisterPropertyBoolean('DetailLinks', true);

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

        $device = $this->device();
        if ($device > 0) {
            foreach (self::WATCH as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $device);
                if ($vid !== false && $vid > 0) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
        }

        $this->maintainActionChoice();
        $this->syncLinks($device);

        $this->SetStatus($device > 0 ? IS_ACTIVE : 201);
        $this->UpdateVisualizationValue($this->buildPayload());
    }

    /**
     * Verknüpfungen für die aufgezogene Ansicht.
     *
     * Das Aufziehen einer Kachel zeigt die **Kinder** der Instanz — nicht ihr Kachel-HTML.
     * Eine Instanz ohne Kinder zeigt aufgezogen schlicht nichts (an der Anlage belegt).
     * Deshalb bekommt die Kachel Verknüpfungen auf die Variablen ihres Thermostats:
     * Wochenprogramm, Urlaub, Uhr, Geräteauskunft. Verknüpfungen statt Kopien — die Werte
     * bleiben am Gerät, samt Historie, und der Schieberegler bedient direkt das Original.
     */
    private function syncLinks(int $device): void
    {
        // Reihenfolge = Anzeigereihenfolge in der aufgezogenen Ansicht.
        $wunsch = [
            CWIFI_Registers::IDENT_TEMPERATURE  => ['Isttemperatur', 10],
            CWIFI_Registers::IDENT_SETPOINT     => ['Solltemperatur', 20],
            CWIFI_Registers::IDENT_MODE         => ['Betriebsart', 30],
            CWIFI_Registers::IDENT_SCHEDULE     => ['Wochenprogramm', 40],
            CWIFI_Registers::IDENT_HOLIDAY      => ['Urlaub aktiv', 50],
            CWIFI_Registers::IDENT_HOLIDAY_FROM => ['Urlaub von', 51],
            CWIFI_Registers::IDENT_HOLIDAY_TO   => ['Urlaub bis', 52],
            CWIFI_Registers::IDENT_HOLIDAY_TEMP => ['Urlaubstemperatur', 53],
            CWIFI_Registers::IDENT_BATTERY      => ['Batteriestand', 60],
            CWIFI_Registers::IDENT_RSSI         => ['WLAN-Signalstärke', 61],
            CWIFI_Registers::IDENT_CLOCK        => ['Geräteuhr', 70],
            CWIFI_Registers::IDENT_CLOCK_DEV    => ['Uhrabweichung', 71],
            CWIFI_Registers::IDENT_MODEL        => ['Modell', 80],
            CWIFI_Registers::IDENT_FIRMWARE     => ['Firmware', 81],
            CWIFI_Registers::IDENT_IP           => ['IP-Adresse', 82],
            CWIFI_Registers::IDENT_GROUP        => ['Gruppe', 83]
        ];

        $ziele = [];
        if ($device > 0 && $this->ReadPropertyBoolean('DetailLinks')) {
            foreach ($wunsch as $ident => $eintrag) {
                $vid = @IPS_GetObjectIDByIdent($ident, $device);
                if ($vid !== false && $vid > 0) {
                    $ziele[$vid] = $eintrag;
                }
            }
        }

        // Bestand einsammeln; was auf ein fremdes Ziel zeigt, fliegt (Gerätewechsel).
        $vorhanden = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $kindId) {
            if (IPS_GetObject($kindId)['ObjectType'] !== 6) {
                continue;
            }
            $ziel = IPS_GetLink($kindId)['TargetID'];
            if (isset($ziele[$ziel])) {
                $vorhanden[$ziel] = $kindId;
            } else {
                IPS_DeleteLink($kindId);
            }
        }

        foreach ($ziele as $ziel => [$name, $position]) {
            $linkId = $vorhanden[$ziel] ?? 0;
            if ($linkId === 0) {
                $linkId = IPS_CreateLink();
                IPS_SetParent($linkId, $this->InstanceID);
                IPS_SetLinkTargetID($linkId, $ziel);
            }
            IPS_SetName($linkId, $name);
            IPS_SetPosition($linkId, $position);
        }
    }

    /**
     * Die Aktionsauswahl — eine Variable, damit sie in der aufgezogenen Ansicht bedienbar
     * ist. Knöpfe gibt es dort nicht; eine Auswahl, die nach dem Ausführen auf „–"
     * zurückspringt, kommt dem am nächsten.
     */
    private function maintainActionChoice(): void
    {
        $optionen = [];
        foreach ([0 => '–', 1 => 'Refresh now', 2 => 'Fetch schedule & holiday',
                  3 => 'Set clock to Symcon time', 4 => 'Request all fields'] as $wert => $text) {
            $optionen[] = [
                'Value' => $wert, 'Caption' => $this->Translate($text),
                'IconActive' => false, 'Icon' => '', 'ColorActive' => false, 'ColorValue' => -1
            ];
        }
        $this->MaintainVariable(
            'Action',
            $this->Translate('Action'),
            VARIABLETYPE_INTEGER,
            ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($optionen)],
            90,
            true
        );
        $this->EnableAction('Action');
    }

    /** Führt eine gewählte Aktion aus und stellt die Auswahl zurück auf „–". */
    private function runAction(int $device, int $aktion): void
    {
        switch ($aktion) {
            case 1: $this->callDevice('CWIFI_RequestUpdate', $device); break;
            case 2: $this->callDevice('CWIFI_RequestSchedule', $device); break;
            case 3: $this->callDevice('CWIFI_SetClock', $device); break;
            case 4: $this->callDevice('CWIFI_RequestAllFields', $device); break;
        }
        $this->SetValue('Action', 0);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE) {
            $this->UpdateVisualizationValue($this->buildPayload());
        }
    }

    /* ================================================================== Darstellung */

    public function GetVisualizationTile()
    {
        // Gemeinsame Vorlage: Das Raummodul zeichnet dieselbe Kachel. Zwei Kopien wären
        // zwei Kacheln, die auseinanderlaufen.
        $html = file_get_contents(__DIR__ . '/../.libs/CWIFI_RoomTile.html');
        // handleMessage() entsteht erst im HTML — der erste Aufruf muss deshalb dahinter.
        return $html . '<script>handleMessage(' . json_encode($this->buildPayload()) . ');</script>';
    }

    /**
     * Rückkanal aus der Kachel.
     *
     * Alles läuft über die Geräteinstanz. Die Nutzlast kommt aus dem Browser und ist damit
     * nichts, worauf man sich verlassen kann — deshalb wird jeder Wert hier neu geprüft,
     * statt ihn durchzureichen.
     */
    public function RequestAction($Ident, $Value)
    {
        $device = $this->device();
        if ($device <= 0 || !$this->ReadPropertyBoolean('AllowControl')) {
            return;
        }

        switch ($Ident) {
            case 'setpoint':
                $wert = (float) $Value;
                if ($wert < CWIFI_Registers::SETPOINT_OFF || $wert > CWIFI_Registers::SETPOINT_ON) {
                    return;
                }
                $this->callDevice('CWIFI_SetTemperature', $device, $wert);
                break;

            case 'mode':
                $this->callDevice('CWIFI_SetManualMode', $device, (bool) $Value);
                break;

            case 'refresh':
                $this->callDevice('CWIFI_RequestUpdate', $device);
                break;

            /* Die Knöpfe der vergrößerten Kachel. Sie tun dasselbe wie im Formular der
               Geräteinstanz — nur muss man dafür nicht mehr in die Verwaltungskonsole. */
            case 'requestAll':
                $this->callDevice('CWIFI_RequestAllFields', $device);
                break;

            case 'requestSchedule':
                $this->callDevice('CWIFI_RequestSchedule', $device);
                break;

            case 'setClock':
                $this->callDevice('CWIFI_SetClock', $device);
                break;

            case 'Action':
                $this->runAction($device, (int) $Value);
                break;
        }

        $this->UpdateVisualizationValue($this->buildPayload());
    }

    /**
     * Frische Werte beim Gerät anfordern.
     *
     * Eigene öffentliche Methode, weil Symcon nur für solche einen `CWIFIR_…`-Wrapper
     * erzeugt. Für geerbte SDK-Methoden wie `RequestAction` gibt es keinen — ein
     * Formularknopf, der `CWIFIR_RequestAction(...)` aufruft, läuft in einen Fatal Error.
     */
    public function RequestUpdate(): bool
    {
        $device = $this->device();
        if ($device <= 0) {
            return false;
        }
        $ok = (bool) $this->callDevice('CWIFI_RequestUpdate', $device);
        $this->UpdateVisualizationValue($this->buildPayload());
        return $ok;
    }


    /**
     * Ruft eine Funktion des Gerätemoduls auf, falls es sie gibt.
     *
     * `@` hilft nicht: Ein fehlender Funktionsaufruf ist in PHP 8 ein `Error` und kein
     * abschaltbarer Hinweis. Ohne diese Prüfung risse ein nicht geladenes Gerätemodul die
     * Kachel mit — und zwar mitten im Klick des Nutzers.
     */
    private function callDevice(string $funktion, int $instanceId, ...$argumente): bool
    {
        if (!function_exists($funktion)) {
            $this->SendDebug('Kachel', 'Gerätemodul nicht geladen: ' . $funktion . ' fehlt', 0);
            return false;
        }
        return (bool) $funktion($instanceId, ...$argumente);
    }

    /* ===================================================================== Intern */

    /** Die eingestellte Geräteinstanz — oder 0, wenn sie fehlt oder keine mehr ist. */
    private function device(): int
    {
        $id = $this->ReadPropertyInteger('DeviceID');
        if ($id <= 0 || !@IPS_InstanceExists($id)) {
            return 0;
        }
        return in_array($id, IPS_GetInstanceListByModuleID(self::DEVICE_MODULE), true) ? $id : 0;
    }

    private function valueOf(int $instanceId, string $ident)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $instanceId);
        return ($vid !== false && $vid > 0) ? @GetValue($vid) : null;
    }

    private function buildPayload(): string
    {
        $device = $this->device();
        if ($device <= 0) {
            return json_encode(['ok' => false]);
        }

        $temp      = $this->valueOf($device, CWIFI_Registers::IDENT_TEMPERATURE);
        $setpoint  = $this->valueOf($device, CWIFI_Registers::IDENT_SETPOINT);
        $battery   = $this->valueOf($device, CWIFI_Registers::IDENT_BATTERY);
        $reachable = (bool) $this->valueOf($device, CWIFI_Registers::IDENT_REACHABLE);
        $last      = (int) $this->valueOf($device, CWIFI_Registers::IDENT_LAST_UPDATE);
        $abw       = $this->valueOf($device, CWIFI_Registers::IDENT_CLOCK_DEV);

        $warnBelow = $this->ReadPropertyInteger('BatteryWarnBelow');
        $clockWarn = $this->ReadPropertyInteger('ClockWarnMinutes');

        return json_encode([
            'ok'         => true,
            // Wird nicht als Überschrift gezeichnet — Symcon setzt den Instanznamen bereits
            // über die Kachel. Dient nur als Hinweistext beim Überfahren des Rings.
            'name'       => IPS_GetName($device),
            'temp'       => (is_numeric($temp) && $temp > 0) ? round((float) $temp, 1) : null,
            'setpoint'   => is_numeric($setpoint) ? round((float) $setpoint, 1) : null,
            // „Aus" und „An" sind Endanschläge des Ventils, keine Temperaturen. Die Kachel
            // benennt sie, statt 7,5 bzw. 28,5 °C zu behaupten.
            'endstop'    => is_numeric($setpoint) ? $this->endstopLabel((float) $setpoint) : null,
            'heating'    => $this->isHeating($temp, $setpoint),
            'battery'    => is_numeric($battery) ? (int) $battery : null,
            'batteryLow' => is_numeric($battery) && $battery > 0 && $battery < $warnBelow,
            'signal'     => $this->valueOf($device, CWIFI_Registers::IDENT_RSSI),
            'reachable'  => $reachable,
            'manual'     => (bool) $this->valueOf($device, CWIFI_Registers::IDENT_MODE),
            'keylock'    => (int) $this->valueOf($device, CWIFI_Registers::IDENT_KEYLOCK),
            'offset'     => $this->valueOf($device, CWIFI_Registers::IDENT_OFFSET),
            'holiday'    => (bool) $this->valueOf($device, CWIFI_Registers::IDENT_HOLIDAY),
            'clockDev'   => is_numeric($abw) ? (int) $abw : null,
            'clockOff'   => $clockWarn > 0 && is_numeric($abw) && abs((int) $abw) >= $clockWarn,
            // Nur für Räume von Belang — hier ausdrücklich leer, damit die gemeinsame
            // Vorlage nicht raten muss.
            'mixed'      => false,
            'members'    => null,
            'lastText'   => $last > 0 ? $this->ago($last) : null,
            'control'    => $this->ReadPropertyBoolean('AllowControl'),
            'details'    => $this->ReadPropertyBoolean('ShowDetails'),
            'minTemp'    => CWIFI_Registers::SETPOINT_OFF,
            'maxTemp'    => CWIFI_Registers::SETPOINT_ON
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

    /**
     * Heizt das Ventil gerade?
     *
     * Eine echte Ventilstellung liefert das Gerät nicht (vollständig geprüft, siehe
     * `.docs/protokoll.md`). Das hier ist deshalb ausdrücklich eine Ableitung aus Soll und
     * Ist und keine Messung — sie färbt nur den Ring und steuert nichts.
     */
    private function isHeating($temp, $setpoint): bool
    {
        if (!is_numeric($temp) || !is_numeric($setpoint) || $temp <= 0) {
            return false;
        }
        return (float) $setpoint > (float) $temp + 0.2;
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

    /* ================================================================== Formular */

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $device = $this->device();
        $text   = $device > 0
            ? '✅  Zugeordnet: ' . IPS_GetName($device)
            : '⚠️  Noch kein Thermostat ausgewählt — die Kachel bleibt leer.';

        foreach ($form['elements'] as &$element) {
            foreach ($element['items'] ?? [] as &$item) {
                if (($item['name'] ?? '') === 'DeviceLabel') {
                    $item['caption'] = $text;
                }
            }
        }
        return json_encode($form);
    }
}
