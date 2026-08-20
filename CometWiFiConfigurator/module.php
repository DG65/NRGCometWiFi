<?php

declare(strict_types=1);

require_once __DIR__ . '/../.libs/CWIFI_Topics.php';
require_once __DIR__ . '/../.libs/CWIFI_Registers.php';
require_once __DIR__ . '/../.libs/CWIFI_MQTT.php';

/**
 * Findet Comet-WiFi-Thermostate durch reines Mithören.
 *
 * Bewusst passiv: Der Konfigurator sendet von sich aus nichts. Ein Sammelaufruf wäre ohnehin
 * nicht adressierbar (die Abruf-Topics brauchen die MAC, die wir vor der Erkennung nicht
 * kennen) — und jede Abfrage weckt Batteriegeräte. Einzige Ausnahme ist die ausdrücklich
 * eingeschaltete Zeitsynchronisation.
 */
class CometWiFiConfigurator extends IPSModule
{
    use CWIFI_MQTT;

    /** GUID des Geräte-Moduls — muss zur module.json des Thermostats passen. */
    private const GUID_THERMOSTAT = '{0F552C16-D685-4C9F-86C0-8D89E4BFD158}';

    private const NEWS_VERSION = '0.16';

    private const ATTR_SEEN_NEWS = 'SeenNews';
    private const ATTR_LAST_DISCOVERY = 'LastDiscoveryTs';
    private const ATTR_DEVICES   = 'Devices';

    private const BUFFER_DEVICES = 'DeviceBuffer';

    /** Register, die in der Fundliste angezeigt werden. */
    private const SHOWN_REGISTERS = ['A0', 'A1', 'A6', 'B3'];

    /* ================================================================== Lebenszyklus */

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('MQTTUser', '');
        $this->RegisterPropertyString('TopicPrefix', '02');
        $this->RegisterPropertyInteger('RetentionHours', 48);
        $this->RegisterPropertyBoolean('ProvideTimeSync', false);
        $this->RegisterPropertyInteger('TimeSyncHours', 24);

        $this->RegisterAttributeString(self::ATTR_SEEN_NEWS, '');
        $this->RegisterAttributeString(self::ATTR_DEVICES, '{}');
        // Verbund-Konvention „Einheitliche Verbund-Status-Kopfzeile" (SUITE.md, 20.08.2026):
        // Zeitpunkt der letzten Gerätemeldung für die Kopfzeile.
        $this->RegisterAttributeInteger(self::ATTR_LAST_DISCOVERY, 0);

        $this->RegisterTimer('CWIFIC_Flush', 0, 'CWIFIC_FlushDiscovery($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CWIFIC_TimeSync', 0, 'CWIFIC_PublishTimeSync($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $user = trim($this->ReadPropertyString('MQTTUser'));
        if ($user === '') {
            $this->SetReceiveDataFilter(CWIFI_Topics::blockingFilter());
            $this->SetTimerInterval('CWIFIC_Flush', 0);
            $this->SetTimerInterval('CWIFIC_TimeSync', 0);
            $this->SetStatus(201);
            return;
        }

        // Anders als beim Gerät bewusst OHNE MAC: Wir wollen alle Geräte dieses Kontos sehen.
        $this->SetReceiveDataFilter(CWIFI_Topics::receiveFilter($user));
        $this->SendDebug('Empfangsfilter', 'auf Kontokennung ' . $user, 0);

        // Gesammeltes wird im Arbeitsspeicher gehalten und nur gelegentlich weggeschrieben —
        // ein Attributschreiben je Nachricht wäre bei mehreren Geräten ein Dauerfeuer.
        $this->SetTimerInterval('CWIFIC_Flush', 60000);

        $this->SetTimerInterval(
            'CWIFIC_TimeSync',
            $this->ReadPropertyBoolean('ProvideTimeSync')
                ? max(1, $this->ReadPropertyInteger('TimeSyncHours')) * 3600 * 1000
                : 0
        );

        $this->refreshStatus();
    }

    /* ==================================================================== Empfangen */

    public function ReceiveData($JSONString)
    {
        $packet = json_decode($JSONString, true);
        if (!is_array($packet) || !array_key_exists('Topic', $packet)) {
            return '';
        }

        $user = trim($this->ReadPropertyString('MQTTUser'));
        if ($user === '') {
            return '';
        }

        $topic  = strval($packet['Topic']);
        $prefix = $this->ReadPropertyString('TopicPrefix');
        $mac    = CWIFI_Topics::macFromTopic($topic, $prefix, $user);
        if ($mac === '') {
            return '';
        }

        $base  = CWIFI_Topics::base($prefix, $user, $mac);
        $split = CWIFI_Topics::split($topic, $base);
        if ($split === null) {
            return '';
        }

        [$direction, $register] = $split;

        // Nur Zustandsmeldungen zählen. Kommandos stammen von der App oder von uns selbst
        // und sagen nichts darüber aus, ob das Gerät sie je gesehen hat.
        if ($direction !== 'V') {
            return '';
        }

        $devices = $this->loadDevices();
        $now     = time();

        $isNew = !isset($devices[$mac]);
        if ($isNew) {
            $devices[$mac] = ['FirstSeen' => $now, 'Registers' => []];
            $this->SendDebug('Neues Gerät', CWIFI_Topics::formatMac($mac), 0);
        }
        $devices[$mac]['LastSeen'] = $now;
        // Nicht nur beim ersten Fund: Die Kopfzeile soll sagen, wann zuletzt ÜBERHAUPT
        // etwas hereinkam — sonst altert sie, obwohl die Anlage munter sendet.
        $this->WriteAttributeInteger(self::ATTR_LAST_DISCOVERY, $now);

        if (!in_array($register, $devices[$mac]['Registers'], true)) {
            $devices[$mac]['Registers'][] = $register;
        }

        $payload = strval($packet['Payload'] ?? '');
        if (in_array($register, self::SHOWN_REGISTERS, true)) {
            $definition = CWIFI_Registers::byRegister($register);
            if ($definition !== null) {
                $value = CWIFI_Registers::decode($definition, $payload);
                if ($value !== null) {
                    $devices[$mac][$definition['ident']] = $value;
                }
            }
        }

        $this->storeDevices($devices);
        $this->refreshOpenForm();

        // Nur beim ersten Auftauchen eines Geräts: Der Status „noch nichts gesehen" wäre
        // sonst dauerhaft zu lesen, obwohl die Liste längst gefüllt ist.
        if ($isNew) {
            $this->refreshStatus();
        }

        return '';
    }

    /* ==================================================================== Formular */

    /**
     * Frischt Kopfzeile und Fundliste in einem BEREITS GEÖFFNETEN Formular auf.
     *
     * `GetConfigurationForm()` läuft nur beim Öffnen. Wer das Formular offen stehen lässt,
     * sähe sonst dauerhaft den Stand von damals — bei uns besonders irreführend, weil die
     * Kopfzeile eine Uhrzeit nennt und damit Aktualität behauptet, die sie nicht hat.
     * (Verbundweit als Stolperfalle 12 in SUITE.md, gefunden am EMS-Suchknopf; hier tritt
     * dieselbe Falle ohne jeden Knopf auf, weil unsere Erkennung passiv nachläuft.)
     *
     * Liste und Kopfzeile werden zusammen aktualisiert, nie einzeln: Eine Kopfzeile, die
     * zehn Geräte meldet, während darunter neun stehen, ist schlechter als beides veraltet.
     *
     * Ist kein Formular offen, verpufft `UpdateFormField` folgenlos — geprüft wird das
     * nicht, weil es das Modul nicht wissen kann.
     */
    private function refreshOpenForm(): void
    {
        $this->UpdateFormField('Devices', 'values', json_encode($this->buildRows()));
        $this->UpdateFormField('DiscoveryLine', 'caption', $this->discoverySummaryLine());
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $this->flushDevices();
        $this->fillVersionLabel($form);

        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'Devices') {
                $action['values'] = $this->buildRows();
                break;
            }
        }
        unset($action);

        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'DiscoveryLine') {
                $action['caption'] = $this->discoverySummaryLine();
                break;
            }
        }
        unset($action);

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        return json_encode($form);
    }

    /**
     * Die Statuskopfzeile nach der Verbund-Konvention (SUITE.md, 20.08.2026).
     *
     * Muster: `<Icon> <Zahl> <Was> gefunden (zuletzt <HH:MM:SS> Uhr).` — eine Zeile, eine
     * Kernzahl, kein Aufzählungssatz. `✅` sobald etwas gefunden wurde, `⚠️` wenn schon
     * einmal etwas ankam, die Liste aber leer ist, `ℹ️` solange noch nie etwas kam.
     *
     * **Abweichung von der Konvention, bewusst:** Sie sieht einen Suchknopf ÜBER der Zeile
     * vor. Den gibt es hier nicht und darf es nicht geben — dieser Konfigurator sendet
     * grundsätzlich nichts, weil jede Abfrage ein Batteriegerät weckt. „Zuletzt" meint
     * deshalb den Zeitpunkt der letzten Gerätemeldung, nicht den einer Suche.
     */
    private function discoverySummaryLine(): string
    {
        $anzahl  = count($this->buildRows());
        $zuletzt = $this->ReadAttributeInteger(self::ATTR_LAST_DISCOVERY);

        if ($zuletzt === 0) {
            return 'ℹ️  Noch kein Thermostat gehört — die Geräte melden sich von selbst.';
        }
        $zeit = date('H:i:s', $zuletzt);

        if ($anzahl === 0) {
            return '⚠️  0 Thermostate in der Liste (zuletzt ' . $zeit . ' Uhr).';
        }
        return sprintf('✅  %d %s gefunden (zuletzt %s Uhr).',
            $anzahl, $anzahl === 1 ? 'Thermostat' : 'Thermostate', $zeit);
    }

    /** Baut die Zeilen der Fundliste. */
    private function buildRows(): array
    {
        $devices = $this->loadDevices();
        $user    = trim($this->ReadPropertyString('MQTTUser'));
        $prefix  = $this->ReadPropertyString('TopicPrefix');
        $cutoff  = time() - max(1, $this->ReadPropertyInteger('RetentionHours')) * 3600;

        $rows = [];
        foreach ($devices as $mac => $data) {
            if (($data['LastSeen'] ?? 0) < $cutoff) {
                continue;
            }

            $instanceId = $this->findInstance($mac);

            $row = [
                'MAC'           => $mac,
                'MACFormatted'  => CWIFI_Topics::formatMac($mac),
                'ClientID'      => CWIFI_Topics::clientId($user, $mac),
                'TemperatureText' => $this->formatNumber($data, CWIFI_Registers::IDENT_TEMPERATURE, ' °C', 1),
                'SetpointText'    => $this->formatNumber($data, CWIFI_Registers::IDENT_SETPOINT, ' °C', 1),
                'BatteryText'     => $this->formatNumber($data, CWIFI_Registers::IDENT_BATTERY, ' %', 0),
                'RSSIText'        => $this->formatNumber($data, CWIFI_Registers::IDENT_RSSI, ' dBm', 0),
                'LastSeenText'    => date('d.m.Y H:i', (int) ($data['LastSeen'] ?? 0)),
                'instanceID'      => $instanceId,
                'create'          => [
                    'moduleID'      => self::GUID_THERMOSTAT,
                    'info'          => 'Comet WiFi ' . substr($mac, 6),
                    'configuration' => [
                        'MAC'         => $mac,
                        'MQTTUser'    => $user,
                        'TopicPrefix' => $prefix
                    ]
                ]
            ];

            $rows[] = $row;
        }

        return $rows;
    }

    private function formatNumber(array $data, string $key, string $suffix, int $digits): string
    {
        if (!array_key_exists($key, $data)) {
            return '–';
        }
        return number_format((float) $data[$key], $digits, ',', '.') . $suffix;
    }

    /**
     * Sucht die vorhandene Instanz zu einer MAC.
     *
     * Ausschließlich über die Property, NIE über den Namen: Im Verbund sind schon zweimal
     * Namensvergleiche danebengegangen (ein Gateway hieß wie ein Wechselrichter, fünf Geräte
     * trugen denselben Namen). Zusätzlich muss der MQTT-Elternteil derselbe sein — sonst
     * gälte bei zwei Brokern die Instanz des falschen Brokers als bereits vorhanden.
     */
    private function findInstance(string $mac): int
    {
        $ownParent = IPS_GetInstance($this->InstanceID)['ConnectionID'];

        foreach (IPS_GetInstanceListByModuleID(self::GUID_THERMOSTAT) as $instanceId) {
            $candidate = CWIFI_Topics::normalizeMac(strval(IPS_GetProperty($instanceId, 'MAC')));
            if ($candidate !== $mac) {
                continue;
            }
            if (IPS_GetInstance($instanceId)['ConnectionID'] !== $ownParent) {
                continue;
            }
            return $instanceId;
        }
        return 0;
    }

    /* ================================================================== Aktionen */

    /** Verwirft die gesammelten Fundstellen. Angelegte Instanzen bleiben unberührt. */
    public function ClearDiscovery(): void
    {
        $this->SetBuffer(self::BUFFER_DEVICES, '{}');
        $this->WriteAttributeString(self::ATTR_DEVICES, '{}');
        $this->SendDebug('Fundliste', 'geleert', 0);
        $this->refreshStatus();
    }

    /** Schreibt den Arbeitsstand ins Attribut (Timer, überlebt den Neustart). */
    public function FlushDiscovery(): void
    {
        $this->flushDevices();
    }

    /**
     * Sendet die Uhrzeit an alle Comet-WiFi-Geräte im Netz.
     *
     * Der Zeitkanal ist ein Rundruf mit fester Pseudo-MAC — er erreicht auch Geräte, die in
     * Symcon gar nicht angelegt sind. Deshalb ab Werk ausgeschaltet.
     */
    public function PublishTimeSync(): bool
    {
        if (!$this->ReadPropertyBoolean('ProvideTimeSync')) {
            return false;
        }
        $topic = CWIFI_Topics::timeSync($this->ReadPropertyString('TopicPrefix'));
        return $this->sendMQTT($topic, CWIFI_Registers::encodeTimestamp(time()));
    }

    /* ===================================================================== Intern */

    /** Fundstellen: bevorzugt aus dem Arbeitsspeicher, sonst aus dem Attribut. */
    private function loadDevices(): array
    {
        $raw = $this->GetBuffer(self::BUFFER_DEVICES);
        if ($raw === '') {
            $raw = $this->ReadAttributeString(self::ATTR_DEVICES);
        }
        $devices = json_decode($raw, true);
        return is_array($devices) ? $devices : [];
    }

    private function storeDevices(array $devices): void
    {
        $this->SetBuffer(self::BUFFER_DEVICES, json_encode($devices));
    }

    private function flushDevices(): void
    {
        $raw = $this->GetBuffer(self::BUFFER_DEVICES);
        if ($raw === '' || $raw === '{}') {
            return;
        }
        if ($raw !== $this->ReadAttributeString(self::ATTR_DEVICES)) {
            $this->WriteAttributeString(self::ATTR_DEVICES, $raw);
        }
    }

    private function refreshStatus(): void
    {
        if (!$this->HasActiveParent()) {
            $this->SetStatus(202);
            return;
        }
        $this->SetStatus(count($this->loadDevices()) === 0 ? 203 : IS_ACTIVE);
    }

    private function fillVersionLabel(array &$form): void
    {
        $version  = '?';
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
                ['type' => 'Label', 'caption' => '• Unverändert in Funktion: Der Konfigurator hört weiterhin nur passiv mit und sendet von sich aus nichts.'],
                ['type' => 'Label', 'caption' => '• Neu im Verbund: Es gibt jetzt eine Übersichtskachel, eine Raumkachel für ein einzelnes Gerät und ein Raummodul, das mehrere Thermostate als eine Instanz bedienbar macht.'],
                [
                    'type'    => 'Button',
                    'caption' => 'Verstanden – nicht mehr anzeigen',
                    'onClick' => 'CWIFIC_AckNews($id);'
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
}
