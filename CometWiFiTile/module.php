<?php

declare(strict_types=1);

require_once __DIR__ . '/../.libs/CWIFI_Registers.php';

/**
 * Raumübersicht aller Comet-WiFi-Thermostate als Kachel.
 *
 * Bewusst von der Datenlogik getrennt (Muster: StromGedachtTile, InverterHubTile): Ein Fehler
 * in der Darstellung kann die MQTT-Anbindung der Geräteinstanzen nicht beeinträchtigen.
 *
 * Die Kachel sucht sich die Thermostate selbst — eine Auswahl von Hand wäre bei zehn Geräten
 * lästig und müsste bei jedem neuen Thermostat nachgepflegt werden.
 */
class CometWiFiTile extends IPSModule
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
        CWIFI_Registers::IDENT_MODE
    ];

    private const NEWS_VERSION = '0.3';
    private const ATTR_SEEN_NEWS = 'SeenNews';

    /* ================================================================== Lebenszyklus */

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('ShowBattery', true);
        $this->RegisterPropertyBoolean('ShowSignal', false);
        $this->RegisterPropertyBoolean('ShowOffline', true);
        $this->RegisterPropertyBoolean('AllowControl', true);
        $this->RegisterPropertyInteger('BatteryWarnBelow', 25);
        $this->RegisterPropertyString('SortMode', 'name');

        $this->RegisterAttributeString(self::ATTR_SEEN_NEWS, '');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);

        // Alte Beobachtungen lösen, sonst sammeln sie sich über die Zeit an.
        foreach ($this->GetMessageList() as $senderId => $messages) {
            foreach ($messages as $message) {
                if ($message === VM_UPDATE) {
                    $this->UnregisterMessage($senderId, VM_UPDATE);
                }
            }
        }

        $count = 0;
        foreach ($this->devices() as $instanceId) {
            foreach (self::WATCH as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $instanceId);
                if ($vid !== false && $vid > 0) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
            $count++;
        }

        $this->SetStatus($count > 0 ? IS_ACTIVE : 201);
        $this->UpdateVisualizationValue($this->buildPayload());
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
        $html = file_get_contents(__DIR__ . '/module.html');
        // handleMessage() entsteht erst im HTML — der erste Aufruf muss deshalb dahinter.
        return $html . '<script>handleMessage(' . json_encode($this->buildPayload()) . ');</script>';
    }

    /**
     * Rückkanal aus der Kachel.
     *
     * Alle Werte laufen über die Geräteinstanz, nicht direkt über MQTT — so gilt auch für
     * eine Bedienung aus der Kachel heraus die Zwangsumschaltung auf Handbetrieb.
     */
    public function RequestAction($Ident, $Value)
    {
        if (!$this->ReadPropertyBoolean('AllowControl')) {
            return;
        }

        switch ($Ident) {
            case 'setpoint':
                $data = json_decode((string) $Value, true);
                if (is_array($data) && isset($data['id'], $data['value']) && $this->owns((int) $data['id'])) {
                    @CWIFI_SetTemperature((int) $data['id'], (float) $data['value']);
                }
                break;

            case 'mode':
                $data = json_decode((string) $Value, true);
                if (is_array($data) && isset($data['id'], $data['manual']) && $this->owns((int) $data['id'])) {
                    @CWIFI_SetManualMode((int) $data['id'], (bool) $data['manual']);
                }
                break;

            case 'refresh':
                $id = (int) $Value;
                if ($this->owns($id)) {
                    @CWIFI_RequestUpdate($id);
                }
                break;
        }

        $this->UpdateVisualizationValue($this->buildPayload());
    }

    /* ===================================================================== Intern */

    /** Alle Thermostat-Instanzen, nach Einstellung sortiert. */
    private function devices(): array
    {
        $ids = IPS_GetInstanceListByModuleID(self::DEVICE_MODULE);

        if ($this->ReadPropertyString('SortMode') === 'temperature') {
            usort($ids, function ($a, $b) {
                return $this->valueOf($b, CWIFI_Registers::IDENT_TEMPERATURE)
                     <=> $this->valueOf($a, CWIFI_Registers::IDENT_TEMPERATURE);
            });
        } else {
            usort($ids, fn ($a, $b) => strnatcasecmp(IPS_GetName($a), IPS_GetName($b)));
        }
        return $ids;
    }

    /** Gehört diese Instanz-ID wirklich zu einem unserer Thermostate? */
    private function owns(int $instanceId): bool
    {
        return in_array($instanceId, IPS_GetInstanceListByModuleID(self::DEVICE_MODULE), true);
    }

    private function valueOf(int $instanceId, string $ident)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $instanceId);
        return ($vid !== false && $vid > 0) ? @GetValue($vid) : null;
    }

    private function buildPayload(): string
    {
        $showOffline = $this->ReadPropertyBoolean('ShowOffline');
        $warnBelow   = $this->ReadPropertyInteger('BatteryWarnBelow');

        $rooms   = [];
        $sumTemp = 0.0;
        $counted = 0;
        $issues  = 0;

        foreach ($this->devices() as $id) {
            $reachable = (bool) $this->valueOf($id, CWIFI_Registers::IDENT_REACHABLE);
            if (!$reachable && !$showOffline) {
                continue;
            }

            $temp     = $this->valueOf($id, CWIFI_Registers::IDENT_TEMPERATURE);
            $setpoint = $this->valueOf($id, CWIFI_Registers::IDENT_SETPOINT);
            $battery  = $this->valueOf($id, CWIFI_Registers::IDENT_BATTERY);
            $last     = (int) $this->valueOf($id, CWIFI_Registers::IDENT_LAST_UPDATE);

            $batteryLow = is_numeric($battery) && $battery > 0 && $battery < $warnBelow;
            if (!$reachable || $batteryLow) {
                $issues++;
            }
            if (is_numeric($temp) && $temp > 0) {
                $sumTemp += (float) $temp;
                $counted++;
            }

            $rooms[] = [
                'id'         => $id,
                'name'       => IPS_GetName($id),
                'temp'       => (is_numeric($temp) && $temp > 0) ? round((float) $temp, 1) : null,
                'setpoint'   => is_numeric($setpoint) ? round((float) $setpoint, 1) : null,
                // „Aus" und „An" sind Endanschläge, keine Temperaturen — die Kachel muss das
                // anzeigen, sonst stünde dort ein sinnloses „7,5 °C".
                'endstop'    => is_numeric($setpoint) ? $this->endstopLabel((float) $setpoint) : null,
                'battery'    => is_numeric($battery) ? (int) $battery : null,
                'batteryLow' => $batteryLow,
                'signal'     => $this->valueOf($id, CWIFI_Registers::IDENT_RSSI),
                'reachable'  => $reachable,
                'manual'     => (bool) $this->valueOf($id, CWIFI_Registers::IDENT_MODE),
                'last'       => $last,
                'lastText'   => $last > 0 ? $this->relativeTime($last) : null
            ];
        }

        return json_encode([
            'rooms'    => $rooms,
            'average'  => $counted > 0 ? round($sumTemp / $counted, 1) : null,
            'issues'   => $issues,
            'control'  => $this->ReadPropertyBoolean('AllowControl'),
            'battery'  => $this->ReadPropertyBoolean('ShowBattery'),
            'signal'   => $this->ReadPropertyBoolean('ShowSignal'),
            'minTemp'  => CWIFI_Registers::SETPOINT_OFF,
            'maxTemp'  => CWIFI_Registers::SETPOINT_ON
        ]);
    }

    private function endstopLabel(float $setpoint): ?string
    {
        if ($setpoint <= CWIFI_Registers::SETPOINT_OFF) {
            return 'Aus';
        }
        if ($setpoint >= CWIFI_Registers::SETPOINT_ON) {
            return 'An';
        }
        return null;
    }

    /** „vor 3 Minuten" statt eines Zeitstempels — auf einer Kachel besser lesbar. */
    private function relativeTime(int $timestamp): string
    {
        $diff = time() - $timestamp;
        if ($diff < 90) {
            return 'gerade eben';
        }
        if ($diff < 3600) {
            return 'vor ' . intdiv($diff, 60) . ' min';
        }
        if ($diff < 86400) {
            $h = intdiv($diff, 3600);
            return 'vor ' . $h . ' ' . ($h === 1 ? 'Stunde' : 'Stunden');
        }
        $d = intdiv($diff, 86400);
        return 'vor ' . $d . ' ' . ($d === 1 ? 'Tag' : 'Tagen');
    }

    /* ==================================================================== Formular */

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $count = count(IPS_GetInstanceListByModuleID(self::DEVICE_MODULE));
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'FoundLabel') {
                $element['caption'] = $count === 0
                    ? '⚠️  Es wurde noch kein Comet-WiFi-Thermostat gefunden. Die Kachel bleibt leer, bis mindestens eine Geräteinstanz angelegt ist.'
                    : '✅  ' . $count . ' Thermostat' . ($count === 1 ? '' : 'e') . ' gefunden. Neue Geräte erscheinen automatisch.';
            }
        }
        unset($element);

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }
        return json_encode($form);
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
                ['type' => 'Label', 'caption' => '• Erste Fassung der Kachel: alle Thermostate auf einen Blick.'],
                ['type' => 'Label', 'caption' => '• Die Geräte werden selbst gefunden — neue Thermostate erscheinen ohne Zutun.'],
                ['type' => 'Label', 'caption' => '• Solltemperatur direkt in der Kachel verstellbar. Dabei gilt dieselbe Umschaltung auf Handbetrieb wie in der Geräteinstanz, sonst überschriebe das Wochenprogramm den Wert wieder.'],
                [
                    'type'    => 'Button',
                    'caption' => 'Verstanden – nicht mehr anzeigen',
                    'onClick' => 'CWIFIT_AckNews($id);'
                ]
            ]
        ];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString(self::ATTR_SEEN_NEWS, self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }
}
