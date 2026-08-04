<?php

declare(strict_types=1);

/**
 * Prüfstand für das Thermostat-Modul — führt die Klasse wirklich aus.
 *
 *   php .tools/test-thermostat.php     # 0 = alle Prüfungen bestanden
 *
 * Deckt den Empfangspfad, den Sendepfad, Wachhund und Statuslogik ab. Alles Fehler, die
 * `php -l` nicht sieht und die am Gerät erst Stunden später auffallen würden.
 */

require_once __DIR__ . '/ips-stub.php';
require_once __DIR__ . '/../CometWiFiThermostat/module.php';

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

const MAC    = 'A1B2C3D4E5F6';
const USER   = 'AABBCCDD';
const BASE   = '02/' . USER . '/' . MAC;

/** Frische, vollständig konfigurierte Instanz mit aktivem MQTT-Elternteil. */
function makeDevice(array $overrides = []): CometWiFiThermostat
{
    IPSTestState::reset();
    IPSTestState::$instances[10] = [
        'InstanceID'     => 10,
        'ConnectionID'   => 0,
        'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => '{MQTT}']
    ];
    IPSTestState::$instances[20] = [
        'InstanceID'     => 20,
        'ConnectionID'   => 10,
        'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => '{DEVICE}']
    ];

    $device = new CometWiFiThermostat(20);
    $device->Create();
    $device->TEST_SetProperty('MAC', MAC);
    $device->TEST_SetProperty('MQTTUser', USER);
    foreach ($overrides as $name => $value) {
        $device->TEST_SetProperty($name, $value);
    }
    $device->ApplyChanges();
    return $device;
}

/** Simuliert eine vom Broker eintreffende Nachricht. */
function deliver(CometWiFiThermostat $device, string $topic, string $payload): void
{
    $device->ReceiveData(json_encode(['Topic' => $topic, 'Payload' => $payload]));
}

/* ================================================== 1. Anlage der Variablen */

$device = makeDevice();

foreach (['Temperature', 'Setpoint', 'Battery', 'BatteryLow', 'RSSI', 'Reachable', 'LastUpdate'] as $ident) {
    checkTrue("Variable {$ident} wird angelegt", isset($device->variables[$ident]));
}
checkTrue('Sollwert ist bedienbar', isset($device->enabledActions['Setpoint']));
checkTrue('Isttemperatur ist NICHT bedienbar', !isset($device->enabledActions['Temperature']));
check('Sollwert nutzt Schieberegler', $device->variables['Setpoint']['presentation']['PRESENTATION'], 'SLIDER');
check('Schrittweite 0,5 K', $device->variables['Setpoint']['presentation']['STEP_SIZE'], 0.5);
check('Letzte Meldung nutzt Zeitstempel-Profil', $device->variables['LastUpdate']['presentation'], '~UnixTimestamp');

// Mehrfaches ApplyChanges darf nichts kaputt machen (Idempotenz von MaintainVariable).
$device->SetValue('Temperature', 21.5);
$device->ApplyChanges();
check('Wert überlebt erneutes ApplyChanges', $device->GetValue('Temperature'), 21.5);
checkTrue('Bedienbarkeit überlebt erneutes ApplyChanges', isset($device->enabledActions['Setpoint']));

/* ============================================================ 2. Empfangspfad */

$device = makeDevice();

deliver($device, BASE . '/V/A1', '#2C');
check('Isttemperatur aus #2C', $device->GetValue('Temperature'), 22.0);

deliver($device, BASE . '/V/A0', '#39');
check('Solltemperatur aus #39', $device->GetValue('Setpoint'), 28.5);

deliver($device, BASE . '/V/B3', '#-45');
check('Signalstärke aus #-45', $device->GetValue('RSSI'), -45);

deliver($device, BASE . '/V/A6', '#64');
check('Batterie aus #64 (hex)', $device->GetValue('Battery'), 100);
check('Batterie-Warnung aus', $device->GetValue('BatteryLow'), false);

deliver($device, BASE . '/V/A6', '#0A');
check('Batterie aus #0A (hex) = 10', $device->GetValue('Battery'), 10);
check('Batterie-Warnung an unter 20 %', $device->GetValue('BatteryLow'), true);

checkTrue('Letzte Meldung wurde gesetzt', $device->GetValue('LastUpdate') > 0);
check('Erreichbar nach Datenempfang', $device->GetValue('Reachable'), true);
check('Status aktiv nach Datenempfang', $device->GetStatus(), IS_ACTIVE);

/* -------------------------------------------- Fremde und kaputte Nachrichten */

$device = makeDevice();
deliver($device, BASE . '/V/A1', '#2C');
$before = $device->GetValue('Temperature');

// Anderes Gerät — darf hier nichts verändern.
deliver($device, '02/' . USER . '/AABBCCDDEEFF/V/A1', '#10');
check('Fremdes Gerät verändert nichts', $device->GetValue('Temperature'), $before);

// MAC, die mit unserer beginnt (Präfixkollision).
deliver($device, '02/' . USER . '/' . MAC . 'FF/V/A1', '#10');
check('Präfixkollision verändert nichts', $device->GetValue('Temperature'), $before);

// Unlesbarer Payload darf den alten Wert nicht überschreiben.
deliver($device, BASE . '/V/A1', '#ZZ');
check('Unlesbarer Payload verändert nichts', $device->GetValue('Temperature'), $before);

deliver($device, BASE . '/V/A1', 'ohne Raute');
check('Payload ohne Raute verändert nichts', $device->GetValue('Temperature'), $before);

// Kaputtes Datenpaket darf nicht in einen Fehler laufen.
$device->ReceiveData('kein json');
$device->ReceiveData(json_encode(['Payload' => '#2C']));
check('Kaputte Pakete verändern nichts', $device->GetValue('Temperature'), $before);

/* ------------------------------------------------ Kommandos sind kein Zustand */

$device = makeDevice();
deliver($device, BASE . '/V/A0', '#2C');
check('Sollwert aus V/A0', $device->GetValue('Setpoint'), 22.0);

// Ein auf S/A0 zurückgespiegeltes Kommando darf den Zustand NICHT verändern —
// sonst bestätigte der eigene Echo einen Wert, den das Gerät nie gesehen hat.
deliver($device, BASE . '/S/A0', '#3C');
check('Kommando auf S/A0 verändert den Zustand nicht', $device->GetValue('Setpoint'), 22.0);

// Ebenso wenig darf es als Lebenszeichen zählen.
$device = makeDevice();
$device->SetValue('LastUpdate', 0);
deliver($device, BASE . '/S/A0', '#3C');
check('Kommando gilt nicht als Lebenszeichen', $device->GetValue('LastUpdate'), 0);

/* ------------------------------------------------------- Verbindungszustände */

$device = makeDevice();
deliver($device, BASE . '/V/A1', '#2C');

// Zeitstempel bewusst in die Vergangenheit setzen: Empfang und Prüfung liegen sonst in
// derselben Sekunde, und ein fälschliches Auffrischen bliebe unbemerkt.
$aliveStamp = time() - 3600;
$device->SetValue('LastUpdate', $aliveStamp);

deliver($device, BASE . '/V/XX', '#COMM-LOSS');
check('COMM-LOSS setzt Erreichbarkeit auf falsch', $device->GetValue('Reachable'), false);
check('COMM-LOSS setzt Status 204', $device->GetStatus(), 204);
// Der Last Will kommt vom Broker, nicht vom Gerät — er darf „zuletzt gehört" nicht auffrischen.
check('COMM-LOSS frischt letzte Meldung NICHT auf', $device->GetValue('LastUpdate'), $aliveStamp);

deliver($device, BASE . '/V/XX', '#COMM-TEST');
check('COMM-TEST setzt Erreichbarkeit zurück', $device->GetValue('Reachable'), true);
check('COMM-TEST setzt Status wieder aktiv', $device->GetStatus(), IS_ACTIVE);

/* ================================================== 3. Rohdaten und Unbekanntes */

$device = makeDevice(['RawRegisters' => false]);
deliver($device, BASE . '/V/A3', '#230400');
checkTrue('Ohne Rohdatenerfassung keine RAW-Variable', !isset($device->variables['RAW_A3']));

$device = makeDevice(['RawRegisters' => true]);
deliver($device, BASE . '/V/A3', '#230400');
checkTrue('Mit Rohdatenerfassung entsteht RAW_A3', isset($device->variables['RAW_A3']));
check('RAW_A3 enthält den unveränderten Payload', $device->GetValue('RAW_A3'), '#230400');

deliver($device, BASE . '/V/BD', '#0806');
check('RAW_BD enthält den unveränderten Payload', $device->GetValue('RAW_BD'), '#0806');

// Undekodierte Register dürfen NICHT als gedeutete Variable auftauchen.
foreach (['A2', 'A3', 'A5', 'A7', 'BB', 'BD'] as $register) {
    checkTrue(
        "Register {$register} wird bewusst nicht gedeutet",
        CWIFI_Registers::byRegister($register) === null
    );
}

/* ==================================================== 4. Sendepfad (Sollwert) */

$device = makeDevice();
IPSTestState::$sentPackets = [];

checkTrue('SetTemperature meldet Erfolg', $device->SetTemperature(21.5));
check('Genau ein Paket gesendet', count(IPSTestState::$sentPackets), 1);

$packet = IPSTestState::$sentPackets[0];
check('Topic ist das Set-Topic', $packet['Topic'], BASE . '/S/A0');
check('Payload 21,5 °C = #2B', $packet['Payload'], '#2B');
// Ein retaintes Kommando würde bei jedem Reconnect erneut zugestellt und das
// Wochenprogramm des Geräts dauerhaft überschreiben.
check('Retain ist aus', $packet['Retain'], false);
// QoS 1, weil die Geräte mit cleanSession=0 verbunden sind: QoS 0 an ein schlafendes
// Gerät verwirft der Broker still.
check('QoS 1', $packet['QualityOfService'], 1);
check('PacketType PUBLISH', $packet['PacketType'], 3);

check('Variable folgt sofort (optimistisch)', $device->GetValue('Setpoint'), 21.5);

// Halbgrad-Raster und Grenzen.
$device->SetTemperature(21.3);
check('21,3 wird auf 21,5 gerundet', $device->GetValue('Setpoint'), 21.5);
$device->SetTemperature(2.0);
check('Unter Minimum wird geklemmt', $device->GetValue('Setpoint'), 5.0);
$device->SetTemperature(99.0);
check('Über Maximum wird geklemmt', $device->GetValue('Setpoint'), 30.0);

// RequestAction ist der Weg aus dem WebFront.
IPSTestState::$sentPackets = [];
$device->RequestAction('Setpoint', 19.0);
check('RequestAction sendet', count(IPSTestState::$sentPackets), 1);
check('RequestAction setzt den Wert', $device->GetValue('Setpoint'), 19.0);

$threw = false;
try {
    $device->RequestAction('Temperature', 5.0);
} catch (Exception $e) {
    $threw = true;
}
checkTrue('RequestAction auf nur lesende Variable wirft', $threw);

/* ------------------------------------------------------------- Datenabruf */

$device = makeDevice();
IPSTestState::$sentPackets = [];
$device->RequestUpdate();
check('RequestUpdate sendet auf S/AF', IPSTestState::$sentPackets[0]['Topic'], BASE . '/S/AF');
check('RequestUpdate fordert nur Temperaturen', IPSTestState::$sentPackets[0]['Payload'], '#0B');

IPSTestState::$sentPackets = [];
$device->RequestAllFields();
check('RequestAllFields fordert alles', IPSTestState::$sentPackets[0]['Payload'], '#FFFFFFFF');

/* =============================================== 5. Unvollständige Konfiguration */

IPSTestState::reset();
$broken = new CometWiFiThermostat(20);
$broken->Create();
$broken->ApplyChanges();
check('Ohne MAC: Status 201', $broken->GetStatus(), 201);
check('Ohne MAC: Filter blockiert', $broken->receiveFilter, '(?!)');
check('Ohne MAC: kein Abfragetakt', $broken->timers['CWIFI_Poll']['interval'], 0);

// Ein blockierender Filter darf auch bei passendem Topic nichts durchlassen.
IPSTestState::$sentPackets = [];
checkTrue('Ohne MAC sendet SetTemperature nicht', !$broken->SetTemperature(21.0));
check('Ohne MAC wurde nichts gesendet', count(IPSTestState::$sentPackets), 0);

IPSTestState::reset();
$noUser = new CometWiFiThermostat(20);
$noUser->Create();
$noUser->TEST_SetProperty('MAC', MAC);
$noUser->ApplyChanges();
check('Ohne Benutzer: Status 202', $noUser->GetStatus(), 202);

/* ---------------------------------------------------- Kein aktiver Elternteil */

IPSTestState::reset();
IPSTestState::$instances[20] = [
    'InstanceID'     => 20,
    'ConnectionID'   => 0,          // nicht verbunden
    'InstanceStatus' => IS_ACTIVE,
    'ModuleInfo'     => ['ModuleID' => '{DEVICE}']
];
$orphan = new CometWiFiThermostat(20);
$orphan->Create();
$orphan->TEST_SetProperty('MAC', MAC);
$orphan->TEST_SetProperty('MQTTUser', USER);
$orphan->ApplyChanges();
check('Ohne MQTT-Elternteil: Status 203', $orphan->GetStatus(), 203);

/* ======================================================= 6. Abfragetakt/Wachhund */

$device = makeDevice(['PollInterval' => 0]);
check('Abfrage ab Werk aus', $device->timers['CWIFI_Poll']['interval'], 0);
checkTrue('Wachhund läuft trotzdem', $device->timers['CWIFI_Alive']['interval'] > 0);

// Batterieschutz: zu kleine Intervalle werden angehoben, nicht übernommen.
$device = makeDevice(['PollInterval' => 1]);
check('Ein-Minuten-Takt wird auf 15 min angehoben', $device->timers['CWIFI_Poll']['interval'], 15 * 60 * 1000);

$device = makeDevice(['PollInterval' => 60]);
check('Gültiger Takt wird übernommen', $device->timers['CWIFI_Poll']['interval'], 60 * 60 * 1000);

// ApplyChanges darf NICHT von sich aus abfragen — läuft bei jedem Kernelstart.
IPSTestState::$sentPackets = [];
$device->ApplyChanges();
check('ApplyChanges weckt das Gerät nicht', count(IPSTestState::$sentPackets), 0);

// Wachhund.
$device = makeDevice(['TimeoutMinutes' => 60]);
deliver($device, BASE . '/V/A1', '#2C');
$device->CheckAlive();
check('Frisches Gerät bleibt erreichbar', $device->GetValue('Reachable'), true);

$device->SetValue('LastUpdate', time() - 7200);   // zwei Stunden alt
$device->CheckAlive();
check('Stummes Gerät wird als nicht erreichbar gemeldet', $device->GetValue('Reachable'), false);
check('Stummes Gerät setzt Status 206', $device->GetStatus(), 206);

/* ================================================================ 7. Formular */

$device = makeDevice();
$form = json_decode($device->GetConfigurationForm(), true);
checkTrue('Formular ist gültiges JSON', is_array($form));
check('Neu-Panel steht ganz oben', $form['elements'][0]['name'] ?? '', 'NewsPanel');

$device->AckNews();
$form = json_decode($device->GetConfigurationForm(), true);
checkTrue('Neu-Panel verschwindet nach Bestätigung', ($form['elements'][0]['name'] ?? '') !== 'NewsPanel');
// Store-Review: der Handler darf nur das Formularfeld anfassen, nichts persistieren.
checkTrue('AckNews aktualisiert nur das Formularfeld', in_array(['NewsPanel', 'visible', false], $device->formFieldUpdates, true));

// Vorschau des Basis-Topics als Selbstkontrolle beim Tippen.
$device->UpdateTopicPreview(MAC, USER, '02');
$captions = array_column($device->formFieldUpdates, 2);
checkTrue(
    'Vorschau zeigt das Basis-Topic',
    (bool) array_filter($captions, fn ($c) => is_string($c) && strpos($c, BASE) !== false)
);
checkTrue(
    'Vorschau zeigt die erwartete Client-ID',
    (bool) array_filter($captions, fn ($c) => is_string($c) && strpos($c, 'da16x02AABBCCDDD4E5F6') !== false)
);

/* ------------------------------------- Statuscodes sind im Formular hinterlegt */

$formJson = json_decode(file_get_contents(__DIR__ . '/../CometWiFiThermostat/form.json'), true);
$declared = array_column($formJson['status'] ?? [], 'code');
foreach ([201, 202, 203, 204, 205, 206] as $code) {
    checkTrue("Statuscode {$code} ist im Formular beschrieben", in_array($code, $declared, true));
}

/* ------------------------------------------------------------------ Ergebnis */

echo "\n";
if ($failed === 0) {
    echo "✅  Alle {$passed} Prüfungen bestanden.\n";
    exit(0);
}
echo "❌  {$failed} von " . ($passed + $failed) . " Prüfungen fehlgeschlagen.\n";
exit(1);
