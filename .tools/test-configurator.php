<?php

declare(strict_types=1);

/**
 * Prüfstand für den Konfigurator — führt die Klasse wirklich aus.
 *
 *   php .tools/test-configurator.php     # 0 = alle Prüfungen bestanden
 */

require_once __DIR__ . '/ips-stub.php';
require_once __DIR__ . '/../CometWiFiConfigurator/module.php';

$failed = 0;
$passed = 0;

function check(string $label, $actual, $expected): void
{
    global $failed, $passed;
    if ($actual === $expected) {
        $passed++;
        return;
    }
    $failed++;
    printf(
        "FEHLGESCHLAGEN  %s\n                erwartet: %s\n                erhalten: %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

function checkTrue(string $label, bool $condition): void
{
    check($label, $condition, true);
}

const USER = 'AABBCCDD';
const MAC_A = 'A1B2C3D4E5F6';
const MAC_B = 'A1B2C3D4E5AA';
const GUID_DEVICE = '{0F552C16-D685-4C9F-86C0-8D89E4BFD158}';

function makeConfigurator(array $overrides = []): CometWiFiConfigurator
{
    IPSTestState::reset();
    IPSTestState::$instances[10] = [
        'InstanceID'     => 10,
        'ConnectionID'   => 0,
        'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => '{MQTT}']
    ];
    IPSTestState::$instances[30] = [
        'InstanceID'     => 30,
        'ConnectionID'   => 10,
        'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => '{CONFIGURATOR}']
    ];

    $cfg = new CometWiFiConfigurator(30);
    $cfg->Create();
    $cfg->TEST_SetProperty('MQTTUser', USER);
    foreach ($overrides as $name => $value) {
        $cfg->TEST_SetProperty($name, $value);
    }
    $cfg->ApplyChanges();
    return $cfg;
}

function deliver(CometWiFiConfigurator $cfg, string $topic, string $payload): void
{
    $cfg->ReceiveData(json_encode(['Topic' => $topic, 'Payload' => $payload]));
}

/** Legt eine Thermostat-Instanz im Stub an, damit der Abgleich etwas zu finden hat. */
function registerDeviceInstance(int $id, string $mac, int $parent = 10, string $name = 'Bad'): void
{
    IPSTestState::$instances[$id] = [
        'InstanceID'     => $id,
        'ConnectionID'   => $parent,
        'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => GUID_DEVICE],
        'Properties'     => ['MAC' => $mac],
        'Name'           => $name
    ];
}

function rowsOf(CometWiFiConfigurator $cfg): array
{
    $form = json_decode($cfg->GetConfigurationForm(), true);
    foreach ($form['actions'] as $action) {
        if (($action['name'] ?? '') === 'Devices') {
            return $action['values'];
        }
    }
    return [];
}

/* ============================================================== 1. Erkennung */

$cfg = makeConfigurator();
check('Ohne Funde: Status 203', $cfg->GetStatus(), 203);
check('Ohne Funde: keine Zeilen', count(rowsOf($cfg)), 0);

deliver($cfg, '02/' . USER . '/' . MAC_A . '/V/A1', '#2C');
$rows = rowsOf($cfg);
check('Ein Gerät gefunden', count($rows), 1);
check('MAC in der Zeile', $rows[0]['MAC'], MAC_A);
check('MAC formatiert', $rows[0]['MACFormatted'], 'A1:B2:C3:D4:E5:F6');
check('Isttemperatur dekodiert', $rows[0]['TemperatureText'], '22,0 °C');
check('Client-ID abgeleitet', $rows[0]['ClientID'], 'da16x02AABBCCDDD4E5F6');
check('Noch keine Instanz zugeordnet', $rows[0]['instanceID'], 0);

// Anlegen-Vorlage: genau die Properties, die das Gerätemodul erwartet.
check('Anlegen zeigt auf das Gerätemodul', $rows[0]['create']['moduleID'], GUID_DEVICE);
check('Anlegen übergibt die MAC', $rows[0]['create']['configuration']['MAC'], MAC_A);
check('Anlegen übergibt den Benutzer', $rows[0]['create']['configuration']['MQTTUser'], USER);
check('Anlegen übergibt das Präfix', $rows[0]['create']['configuration']['TopicPrefix'], '02');

// Weitere Register desselben Geräts reichern dieselbe Zeile an.
deliver($cfg, '02/' . USER . '/' . MAC_A . '/V/A0', '#39');
deliver($cfg, '02/' . USER . '/' . MAC_A . '/V/A6', '#64');
deliver($cfg, '02/' . USER . '/' . MAC_A . '/V/B3', '#-45');
$rows = rowsOf($cfg);
check('Immer noch genau ein Gerät', count($rows), 1);
check('Solltemperatur ergänzt', $rows[0]['SetpointText'], '28,5 °C');
check('Batterie ergänzt', $rows[0]['BatteryText'], '100 %');
check('Signal ergänzt', $rows[0]['RSSIText'], '-45 dBm');

// Zweites Gerät.
deliver($cfg, '02/' . USER . '/' . MAC_B . '/V/A1', '#2A');
check('Zwei Geräte gefunden', count(rowsOf($cfg)), 2);
check('Status aktiv sobald etwas gefunden wurde', $cfg->GetStatus(), IS_ACTIVE);

/* -------------------------------------------------- Was NICHT gezählt werden darf */

$cfg = makeConfigurator();

// Kommandos sagen nichts darüber aus, ob ein Gerät existiert oder antwortet.
deliver($cfg, '02/' . USER . '/' . MAC_A . '/S/A0', '#2B');
check('Kommando erzeugt keinen Fund', count(rowsOf($cfg)), 0);

// Fremder Benutzer (Rundruf-Kanal).
deliver($cfg, '02/FFFFFFFF/' . MAC_A . '/S/AF', '#0B');
check('Rundruf erzeugt keinen Fund', count(rowsOf($cfg)), 0);

// Unsinnige MAC im Topic.
deliver($cfg, '02/' . USER . '/NICHTEINEMAC/V/A1', '#2C');
check('Ungültige MAC erzeugt keinen Fund', count(rowsOf($cfg)), 0);

// Kaputte Pakete.
$cfg->ReceiveData('kein json');
$cfg->ReceiveData(json_encode(['Payload' => '#2C']));
check('Kaputte Pakete erzeugen keinen Fund', count(rowsOf($cfg)), 0);

// Unbekanntes Register zählt als Lebenszeichen, liefert aber keinen Messwert.
deliver($cfg, '02/' . USER . '/' . MAC_A . '/V/BD', '#0806');
$rows = rowsOf($cfg);
check('Unbekanntes Register erzeugt trotzdem einen Fund', count($rows), 1);
check('Unbekanntes Register liefert keinen Messwert', $rows[0]['TemperatureText'], '–');

/* ================================================ 2. Zuordnung zu Instanzen */

$cfg = makeConfigurator();
deliver($cfg, '02/' . USER . '/' . MAC_A . '/V/A1', '#2C');

registerDeviceInstance(40, MAC_A);
check('Vorhandene Instanz wird erkannt', rowsOf($cfg)[0]['instanceID'], 40);

// Groß-/Kleinschreibung und Trennzeichen dürfen die Zuordnung nicht verhindern.
IPSTestState::$instances[40]['Properties']['MAC'] = 'a1:b2:c3:d4:e5:f6';
check('Zuordnung trotz abweichender Schreibweise', rowsOf($cfg)[0]['instanceID'], 40);

// Der Name ist irrelevant — im Verbund sind Namensvergleiche schon zweimal danebengegangen.
IPSTestState::$instances[40]['Name'] = 'Völlig anderer Name';
check('Zuordnung ignoriert den Namen', rowsOf($cfg)[0]['instanceID'], 40);

// Instanz mit anderer MAC darf nicht zugeordnet werden.
IPSTestState::$instances[40]['Properties']['MAC'] = MAC_B;
check('Andere MAC wird nicht zugeordnet', rowsOf($cfg)[0]['instanceID'], 0);

// Gleiche MAC, aber an einem anderen Broker: darf nicht als vorhanden gelten.
IPSTestState::$instances[40]['Properties']['MAC'] = MAC_A;
IPSTestState::$instances[40]['ConnectionID'] = 99;
check('Instanz an fremdem Broker zählt nicht', rowsOf($cfg)[0]['instanceID'], 0);

/* ======================================================= 3. Aufbewahrungsdauer */

$cfg = makeConfigurator(['RetentionHours' => 1]);
deliver($cfg, '02/' . USER . '/' . MAC_A . '/V/A1', '#2C');
check('Frischer Fund erscheint', count(rowsOf($cfg)), 1);

// Fund künstlich altern lassen.
$devices = json_decode($cfg->GetBuffer('DeviceBuffer'), true);
$devices[MAC_A]['LastSeen'] = time() - 7200;
$cfg->SetBuffer('DeviceBuffer', json_encode($devices));
check('Alter Fund verschwindet', count(rowsOf($cfg)), 0);

/* ============================================================== 4. Beharrlichkeit */

$cfg = makeConfigurator();
deliver($cfg, '02/' . USER . '/' . MAC_A . '/V/A1', '#2C');

// Im laufenden Betrieb steht der Stand im Arbeitsspeicher …
checkTrue('Arbeitsspeicher gefüllt', $cfg->GetBuffer('DeviceBuffer') !== '');
// … und darf nicht bei jeder Nachricht ins Attribut geschrieben werden.
check('Attribut noch unberührt', $cfg->ReadAttributeString('Devices'), '{}');

$cfg->FlushDiscovery();
checkTrue('Nach dem Wegschreiben steht es im Attribut', $cfg->ReadAttributeString('Devices') !== '{}');

// Das Öffnen des Formulars schreibt ebenfalls weg (Neustart-Sicherheit).
$cfg2 = makeConfigurator();
deliver($cfg2, '02/' . USER . '/' . MAC_B . '/V/A1', '#2C');
rowsOf($cfg2);
checkTrue('Formularaufruf schreibt weg', $cfg2->ReadAttributeString('Devices') !== '{}');

$cfg->ClearDiscovery();
check('Liste leeren wirkt', count(rowsOf($cfg)), 0);
check('Liste leeren räumt auch das Attribut', $cfg->ReadAttributeString('Devices'), '{}');

/* ========================================= 5. Der Konfigurator sendet nichts */

$cfg = makeConfigurator();
IPSTestState::$sentPackets = [];

// Weder beim Anwenden …
$cfg->ApplyChanges();
// … noch beim Öffnen des Formulars …
rowsOf($cfg);
// … noch beim Empfang.
deliver($cfg, '02/' . USER . '/' . MAC_A . '/V/A1', '#2C');
rowsOf($cfg);
check('Konfigurator sendet von sich aus nichts', count(IPSTestState::$sentPackets), 0);

/* ---------------------------------------------------- Zeitsynchronisation */

$cfg = makeConfigurator(['ProvideTimeSync' => false]);
check('Zeitsynchronisation ab Werk aus', $cfg->timers['CWIFIC_TimeSync']['interval'], 0);
IPSTestState::$sentPackets = [];
checkTrue('Ausgeschaltet wird nicht gesendet', !$cfg->PublishTimeSync());
check('Ausgeschaltet bleibt der Kanal still', count(IPSTestState::$sentPackets), 0);

$cfg = makeConfigurator(['ProvideTimeSync' => true, 'TimeSyncHours' => 24]);
check('Eingeschaltet läuft der Zeitgeber', $cfg->timers['CWIFIC_TimeSync']['interval'], 24 * 3600 * 1000);
IPSTestState::$sentPackets = [];
checkTrue('Eingeschaltet wird gesendet', $cfg->PublishTimeSync());
check('Rundruf-Topic', IPSTestState::$sentPackets[0]['Topic'], '02/FFFFFFFF/000000000004/T/B7');
checkTrue(
    'Zeitstempel im erwarteten Format',
    (bool) preg_match('/^#\d{2}\.\d{2}\.\d{2}-\d{2}:\d{2}$/', IPSTestState::$sentPackets[0]['Payload'])
);
check('Zeitsynchronisation ohne Retain', IPSTestState::$sentPackets[0]['Retain'], false);

/* ================================================ 6. Unvollständige Konfiguration */

IPSTestState::reset();
$broken = new CometWiFiConfigurator(30);
$broken->Create();
$broken->ApplyChanges();
check('Ohne Benutzer: Status 201', $broken->GetStatus(), 201);
check('Ohne Benutzer: Filter blockiert', $broken->receiveFilter, '(?!)');

IPSTestState::reset();
IPSTestState::$instances[30] = [
    'InstanceID'     => 30,
    'ConnectionID'   => 0,
    'InstanceStatus' => IS_ACTIVE,
    'ModuleInfo'     => ['ModuleID' => '{CONFIGURATOR}']
];
$orphan = new CometWiFiConfigurator(30);
$orphan->Create();
$orphan->TEST_SetProperty('MQTTUser', USER);
$orphan->ApplyChanges();
check('Ohne MQTT-Elternteil: Status 202', $orphan->GetStatus(), 202);

/* ================================================================ 7. Formular */

$cfg = makeConfigurator();
$form = json_decode($cfg->GetConfigurationForm(), true);
checkTrue('Formular ist gültiges JSON', is_array($form));
check('Neu-Panel steht ganz oben', $form['elements'][0]['name'] ?? '', 'NewsPanel');

$cfg->AckNews();
$form = json_decode($cfg->GetConfigurationForm(), true);
checkTrue('Neu-Panel verschwindet nach Bestätigung', ($form['elements'][0]['name'] ?? '') !== 'NewsPanel');
checkTrue('AckNews aktualisiert nur das Formularfeld', in_array(['NewsPanel', 'visible', false], $cfg->formFieldUpdates, true));

$formJson = json_decode(file_get_contents(__DIR__ . '/../CometWiFiConfigurator/form.json'), true);
$declared = array_column($formJson['status'] ?? [], 'code');
foreach ([201, 202, 203] as $code) {
    checkTrue("Statuscode {$code} ist im Formular beschrieben", in_array($code, $declared, true));
}

// Alle vom Formular gerufenen Handler müssen existieren.
$src = file_get_contents(__DIR__ . '/../CometWiFiConfigurator/module.php');
preg_match_all('/CWIFIC_(\w+)\(/', file_get_contents(__DIR__ . '/../CometWiFiConfigurator/form.json'), $matches);
foreach (array_unique($matches[1]) as $handler) {
    checkTrue("Formular-Handler {$handler} existiert", strpos($src, "public function {$handler}(") !== false);
}

/* ------------------------------------------------------------------ Ergebnis */

echo "\n";
if ($failed === 0) {
    echo "✅  Alle {$passed} Prüfungen bestanden.\n";
    exit(0);
}
echo "❌  {$failed} von " . ($passed + $failed) . " Prüfungen fehlgeschlagen.\n";
exit(1);
