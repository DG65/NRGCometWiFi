<?php

declare(strict_types=1);

require_once __DIR__ . '/../.libs/CWIFI_Registers.php';
require_once __DIR__ . '/../.libs/CWIFI_Text.php';

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
    /** Fassung des „Was ist Neu"-Panels — nur bei wichtigen Änderungen hochzählen. */
    private const NEWS_VERSION   = '0.20';
    private const ATTR_SEEN_NEWS = 'SeenNews';

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

        $this->RegisterAttributeString(self::ATTR_SEEN_NEWS, '');

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
        $this->maintainActionChoice();

        // Der Abgleich mit der Geräteinstanz gehört nicht in ApplyChanges: Beim Neuladen ist das
        // Gerätemodul womöglich noch nicht geladen, und syncLinks() räumte dann alle
        // Verknüpfungen ab (Store-Checkliste 27). Deshalb erst bei KR_READY.
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->attachDevice();
    }

    /** Alles, was die Geräteinstanz braucht: Nachrichten, Verknüpfungen, Status, Kachel. */
    private function attachDevice(): void
    {
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
            CWIFI_Registers::IDENT_TEMPERATURE  => ['Current temperature', 10],
            CWIFI_Registers::IDENT_SETPOINT     => ['Target temperature', 20],
            CWIFI_Registers::IDENT_MODE         => ['Operating mode', 30],
            CWIFI_Registers::IDENT_SCHEDULE     => ['Weekly schedule', 40],
            CWIFI_Registers::IDENT_HOLIDAY      => ['Holiday active', 50],
            CWIFI_Registers::IDENT_HOLIDAY_FROM => ['Holiday from', 51],
            CWIFI_Registers::IDENT_HOLIDAY_TO   => ['Holiday until', 52],
            CWIFI_Registers::IDENT_HOLIDAY_TEMP => ['Holiday temperature', 53],
            CWIFI_Registers::IDENT_BATTERY      => ['Battery level', 60],
            CWIFI_Registers::IDENT_RSSI         => ['WiFi signal strength', 61],
            CWIFI_Registers::IDENT_CLOCK        => ['Device clock', 70],
            CWIFI_Registers::IDENT_CLOCK_DEV    => ['Clock deviation', 71],
            CWIFI_Registers::IDENT_MODEL        => ['Model', 80],
            CWIFI_Registers::IDENT_FIRMWARE     => ['Firmware', 81],
            CWIFI_Registers::IDENT_IP           => ['IP address', 82],
            CWIFI_Registers::IDENT_GROUP        => ['Group', 83]
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
            if (isset($vorhanden[$ziel])) {
                continue;   // Name und Position gehören dem Nutzer, sobald die Verknüpfung existiert
            }
            $linkId = IPS_CreateLink();
            IPS_SetParent($linkId, $this->InstanceID);
            IPS_SetLinkTargetID($linkId, $ziel);
            IPS_SetName($linkId, $this->Translate($name));
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
                'IconActive' => false, 'IconValue' => '', 'Color' => -1
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
        if ($SenderID === 0 && $Message === IPS_KERNELMESSAGE && ($Data[0] ?? 0) === KR_READY) {
            $this->attachDevice();
            return;
        }
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
    public function RequestUpdate(): string
    {
        $device = $this->device();
        if ($device <= 0) {
            return $this->Translate('⚠️  No thermostat assigned — please select a device instance above.');
        }

        // Über SendAction, nicht über CWIFI_RequestUpdate: Das liefert seit 0.19.0
        // Anzeigetext, und ein nicht leerer Fehlertext wäre `true`.
        $ok = $this->callDevice('CWIFI_SendAction', $device, 'Update');
        $this->UpdateVisualizationValue($this->buildPayload());

        if (!$ok) {
            return $this->Translate('⚠️  Not sent. Check the MAC address and the MQTT user on the thermostat and whether the parent MQTT client is active.');
        }
        return sprintf(
            $this->Translate('✅  Temperatures requested (%s). The values appear as soon as the device answers — it sleeps and does not respond immediately.'),
            date('H:i:s')
        );
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
            $this->SendDebug('Tile', 'Device module not loaded: ' . $funktion . ' is missing', 0);
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
        $t = $this->t();
        if ($device <= 0) {
            return json_encode(['ok' => false, 'texts' => CWIFI_Text::roomTileTexts($t)]);
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
            'texts'      => CWIFI_Text::roomTileTexts($t),
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
            'lastText'   => $last > 0 ? CWIFI_Text::ago(time() - $last, $t, true) : null,
            'control'    => $this->ReadPropertyBoolean('AllowControl'),
            'details'    => $this->ReadPropertyBoolean('ShowDetails'),
            'minTemp'    => CWIFI_Registers::SETPOINT_OFF,
            'maxTemp'    => CWIFI_Registers::SETPOINT_ON
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

    private function t(): callable
    {
        return CWIFI_Text::translator(fn (string $text): string => $this->Translate($text));
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

        $device = $this->device();
        $text   = $device > 0
            ? sprintf($this->Translate('✅  Assigned: %s'), IPS_GetName($device))
            : $this->Translate('⚠️  No thermostat selected yet — the tile stays empty.');

        foreach ($form['elements'] as &$element) {
            foreach ($element['items'] ?? [] as &$item) {
                if (($item['name'] ?? '') === 'DeviceLabel') {
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
                    'onClick' => 'CWIFIR_AckNews($id);'
                ]
            ]
        ];
    }

}
