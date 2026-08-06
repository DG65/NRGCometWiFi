<?php

declare(strict_types=1);

/**
 * Prüfstand für die Raumkachel — führt die Klasse wirklich aus.
 *
 *   php .tools/test-roomtile.php     # 0 = alle Prüfungen bestanden
 *
 * Schwerpunkt liegt auf zwei Dingen, die eine Einzelkachel besonders angreifbar machen:
 * der Zuordnung zu genau einer Instanz (was passiert, wenn sie verschwindet oder nie
 * gesetzt war?) und der Bedienung, deren Werte aus dem Browser kommen und deshalb
 * grundsätzlich unglaubwürdig sind.
 */

require_once __DIR__ . '/ips-stub.php';
require_once __DIR__ . '/../CometWiFiRoomTile/module.php';

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

/** json_encode schreibt den Float 24.0 als 24 — in JavaScript gibt es den Unterschied nicht. */
function checkNum(string $label, $actual, ?float $expected): void
{
    if ($expected === null || $actual === null) {
        check($label, $actual, $expected);
        return;
    }
    check($label, is_numeric($actual) && abs((float) $actual - $expected) < 0.001, true);
}

const GUID_DEVICE = '{0F552C16-D685-4C9F-86C0-8D89E4BFD158}';

/* Die Wrapper des Gerätemoduls nachbilden und mitschreiben — so prüft der Stand, welche
   Funktion ein Knopf der vergrößerten Kachel wirklich auslöst. */
$GLOBALS['aufrufe'] = [];
foreach (['SetTemperature', 'SetManualMode', 'RequestUpdate', 'RequestAllFields',
          'RequestSchedule', 'SetClock'] as $name) {
    if (function_exists('CWIFI_' . $name)) {
        continue;
    }
    eval('function CWIFI_' . $name . '(int $id, ...$rest): bool {'
        . '$GLOBALS["aufrufe"][] = ["' . $name . '", $id, $rest]; return true; }');
}

function addThermostat(int $id, string $name, array $values): void
{
    IPSTestState::$instances[$id] = [
        'InstanceID'     => $id,
        'ConnectionID'   => 10,
        'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => GUID_DEVICE],
        'Name'           => $name
    ];
    foreach ($values as $ident => $value) {
        IPSTestState::addObject($id, $ident, $value);
    }
}

function makeTile(array $overrides = []): CometWiFiRoomTile
{
    IPSTestState::$instances[90] = [
        'InstanceID'     => 90,
        'ConnectionID'   => 0,
        'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => '{ROOMTILE}']
    ];
    $tile = new CometWiFiRoomTile(90);
    $tile->Create();
    foreach ($overrides as $name => $value) {
        $tile->TEST_SetProperty($name, $value);
    }
    $tile->ApplyChanges();
    return $tile;
}

function payloadOf(CometWiFiRoomTile $tile): array
{
    $tile->ApplyChanges();
    return json_decode(IPSTestState::$visualization, true);
}

/* ============================================ 1. Zuordnung zu genau einer Instanz */

IPSTestState::reset();
addThermostat(50, 'Thermostat Bad', [
    'Temperature'    => 21.5,
    'Setpoint'       => 22.0,
    'Battery'        => 80,
    'RSSI'           => -60,
    'Reachable'      => true,
    'Mode'           => true,
    'KeyLock'        => 0,
    'Offset'         => 0.0,
    'Holiday'        => false,
    'ClockDeviation' => 0,
    'LastUpdate'     => time() - 60
]);

$payload = payloadOf(makeTile(['DeviceID' => 50]));
checkTrue('Zugeordnetes Gerät liefert eine Nutzlast', $payload['ok']);
check('Name kommt aus der Instanz', $payload['name'], 'Thermostat Bad');
checkNum('Isttemperatur', $payload['temp'], 21.5);
checkNum('Solltemperatur', $payload['setpoint'], 22.0);
check('Ist und Soll sind getrennt', $payload['temp'] === $payload['setpoint'], false);
check('Handbetrieb erkannt', $payload['manual'], true);

// Ohne Auswahl darf nichts behauptet werden — und der Status muss es sagen.
$tile = makeTile();
check('Ohne Auswahl keine Nutzlast', json_decode(IPSTestState::$visualization, true)['ok'], false);
check('Ohne Auswahl Status 201', $tile->GetStatus(), 201);

// Eine ID, die auf etwas anderes zeigt, ist genauso wertlos wie gar keine.
IPSTestState::$instances[70] = [
    'InstanceID' => 70, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE,
    'ModuleInfo' => ['ModuleID' => '{FREMD}'], 'Name' => 'Irgendwas'
];
$tile = makeTile(['DeviceID' => 70]);
check('Fremdes Modul wird nicht angenommen', json_decode(IPSTestState::$visualization, true)['ok'], false);
check('Fremdes Modul ergibt Status 201', $tile->GetStatus(), 201);

// Gelöschte Instanz darf die Kachel nicht mitreißen.
$tile = makeTile(['DeviceID' => 50]);
unset(IPSTestState::$instances[50]);
$tile->ApplyChanges();
check('Gelöschtes Gerät ergibt leere Nutzlast', json_decode(IPSTestState::$visualization, true)['ok'], false);

/* ==================================================== 2. Endanschläge und Ringstellung */

IPSTestState::reset();
addThermostat(50, 'Bad', ['Temperature' => 20.0, 'Setpoint' => 28.5, 'Reachable' => true]);
check('Oberer Endanschlag heißt An', payloadOf(makeTile(['DeviceID' => 50]))['endstop'], 'An');

SetValue(IPS_GetObjectIDByIdent('Setpoint', 50), 7.5);
check('Unterer Endanschlag heißt Aus', payloadOf(makeTile(['DeviceID' => 50]))['endstop'], 'Aus');

SetValue(IPS_GetObjectIDByIdent('Setpoint', 50), 22.0);
$payload = payloadOf(makeTile(['DeviceID' => 50]));
check('Dazwischen kein Endanschlag', $payload['endstop'], null);
// Der Ring rechnet auf dieser Spanne — stimmt sie nicht, steht der Zeiger falsch.
checkNum('Skalenanfang', $payload['minTemp'], 7.5);
checkNum('Skalenende', $payload['maxTemp'], 28.5);

/* ======================================================= 3. Heizanzeige ist abgeleitet */

IPSTestState::reset();
addThermostat(50, 'Bad', ['Temperature' => 20.0, 'Setpoint' => 22.0, 'Reachable' => true]);
check('Soll über Ist heißt heizen', payloadOf(makeTile(['DeviceID' => 50]))['heating'], true);

SetValue(IPS_GetObjectIDByIdent('Setpoint', 50), 19.0);
check('Soll unter Ist heißt nicht heizen', payloadOf(makeTile(['DeviceID' => 50]))['heating'], false);

// Ohne Messwert darf nichts behauptet werden — sonst färbt sich der Ring auf Verdacht.
SetValue(IPS_GetObjectIDByIdent('Temperature', 50), 0.0);
SetValue(IPS_GetObjectIDByIdent('Setpoint', 50), 22.0);
$payload = payloadOf(makeTile(['DeviceID' => 50]));
check('Ohne Isttemperatur keine Heizanzeige', $payload['heating'], false);
check('Ohne Isttemperatur null statt 0', $payload['temp'], null);

/* ============================================================ 4. Warnungen */

IPSTestState::reset();
addThermostat(50, 'Bad', [
    'Temperature' => 20.0, 'Setpoint' => 22.0, 'Reachable' => true,
    'Battery' => 15, 'ClockDeviation' => 42, 'KeyLock' => 2, 'Holiday' => true
]);
$payload = payloadOf(makeTile(['DeviceID' => 50, 'BatteryWarnBelow' => 25, 'ClockWarnMinutes' => 15]));
check('Schwache Batterie markiert', $payload['batteryLow'], true);
check('Uhrabweichung markiert', $payload['clockOff'], true);
check('Uhrabweichung durchgereicht', $payload['clockDev'], 42);
check('Tastensperre plus durchgereicht', $payload['keylock'], 2);
check('Urlaub durchgereicht', $payload['holiday'], true);

// Abgeschaltete Schwellen dürfen nicht warnen.
$payload = payloadOf(makeTile(['DeviceID' => 50, 'BatteryWarnBelow' => 0, 'ClockWarnMinutes' => 0]));
check('Batterieschwelle 0 warnt nicht', $payload['batteryLow'], false);
check('Uhrschwelle 0 warnt nicht', $payload['clockOff'], false);

/* ================================================= 5. Bedienung prüft ihre Eingaben */

IPSTestState::reset();
addThermostat(50, 'Bad', ['Temperature' => 20.0, 'Setpoint' => 22.0, 'Reachable' => true]);
$tile = makeTile(['DeviceID' => 50, 'AllowControl' => true]);

/* Die Nutzlast kommt aus dem Browser. Werte ausserhalb der Geräteskala duerfen nicht
   durchgereicht werden — CWIFI_SetTemperature gibt es im Prüfstand nicht, ein Aufruf
   wuerde also auffallen. */
IPSTestState::$logMessages = [];
$tile->RequestAction('setpoint', 99.0);
$tile->RequestAction('setpoint', -5.0);
check('Werte ausserhalb der Skala werden abgewiesen', count(IPSTestState::$logMessages), 0);

// Abgeschaltete Bedienung heisst abgeschaltet.
$tile = makeTile(['DeviceID' => 50, 'AllowControl' => false]);
$payload = json_decode(IPSTestState::$visualization, true);
check('Bedienung abschaltbar', $payload['control'], false);

/* ================================================= 6. Name nur als Hinweistext
 *
 * Die Kachel zeichnet keine Namenszeile — Symcon setzt den Instanznamen bereits über die
 * Kachel, eine zweite Überschrift darunter wäre dieselbe Angabe doppelt. Der Name bleibt
 * trotzdem in der Nutzlast, als Hinweistext beim Überfahren des Rings.
 */

IPSTestState::reset();
addThermostat(50, 'Thermostat Bad', ['Temperature' => 20.0, 'Reachable' => true]);
check('Name kommt aus der Instanz', payloadOf(makeTile(['DeviceID' => 50]))['name'], 'Thermostat Bad');

IPSTestState::$instances[50]['Name'] = 'Gäste-WC';
check('Umbenennen schlaegt durch', payloadOf(makeTile(['DeviceID' => 50]))['name'], 'Gäste-WC');


/* ============================================ 7. Vergrößerte Kachel */

IPSTestState::reset();
addThermostat(50, 'Bad', [
    'Temperature' => 21.0, 'Setpoint' => 22.0, 'Reachable' => true,
    'Schedule'    => "Montag: 04:30 → 22,0 °C",
    'HolidayFrom' => mktime(12, 0, 0, 7, 31, 2026),
    'HolidayTo'   => mktime(12, 0, 0, 8, 16, 2026),
    'HolidayTemperature' => 25.0,
    'Model'       => 'Comet Wifi Ver. 6.1',
    'Firmware'    => '2.7.1.0',
    'IPAddress'   => '192.168.2.45',
    'Group'       => 'Einzelgerät',
    'DeviceClock' => '21:53'
]);
$payload = payloadOf(makeTile(['DeviceID' => 50]));

/* Das Aufziehen der Kachel zeigt die KINDER der Instanz — deshalb muessen dort
   Verknuepfungen auf die Geraetevariablen liegen. Ohne sie ist die aufgezogene
   Ansicht leer, und niemand merkt es beim normalen Hinsehen. */
$kinder = IPS_GetChildrenIDs(90);
checkTrue('Verknuepfungen wurden angelegt', count($kinder) >= 5);

$zielIdents = [];
foreach ($kinder as $kindId) {
    $ziel = IPS_GetLink($kindId)['TargetID'];
    foreach (IPSTestState::$objects as $oid => $obj) {
        if ($oid === $ziel) { $zielIdents[] = $obj['ident']; }
    }
}
foreach (['Setpoint', 'Schedule', 'HolidayFrom', 'Model', 'DeviceClock'] as $ident) {
    checkTrue('Verknuepfung auf ' . $ident, in_array($ident, $zielIdents, true));
}

// Zweimal Uebernehmen darf die Verknuepfungen nicht verdoppeln.
$anzahl = count($kinder);
$tile->ApplyChanges();
check('Kein Wildwuchs beim zweiten Uebernehmen', count(IPS_GetChildrenIDs(90)), $anzahl);

// Geraetewechsel: alte Verknuepfungen weg, neue da.
addThermostat(60, 'Kueche', ['Temperature' => 19.0, 'Setpoint' => 20.0, 'Reachable' => true]);
$tile = makeTile(['DeviceID' => 60]);
foreach (IPS_GetChildrenIDs(90) as $kindId) {
    $ziel = IPS_GetLink($kindId)['TargetID'];
    checkTrue('Nach Geraetewechsel zeigt nichts mehr auf das alte Geraet',
        IPSTestState::$objects[$ziel]['instance'] === 60);
}

// Abschaltbar — dann verschwinden sie wieder.
$tile = makeTile(['DeviceID' => 60, 'DetailLinks' => false]);
check('Abgeschaltet raeumt die Verknuepfungen ab', count(IPS_GetChildrenIDs(90)), 0);

/* Jeder Knopf muss die Funktion ausloesen, die auf ihm steht. Vertauscht man zwei, faellt
   das ohne diesen Test niemandem auf — die Kachel sieht in beiden Faellen gleich aus. */
$tile = makeTile(['DeviceID' => 50]);
foreach ([
    'refresh'         => 'RequestUpdate',
    'requestSchedule' => 'RequestSchedule',
    'setClock'        => 'SetClock',
    'requestAll'      => 'RequestAllFields'
] as $kennung => $erwartet) {
    $GLOBALS['aufrufe'] = [];
    $tile->RequestAction($kennung, 0);
    check('Knopf ' . $kennung . ' loest ' . $erwartet . ' aus',
        $GLOBALS['aufrufe'][0][0] ?? '(nichts)', $erwartet);
    check('Knopf ' . $kennung . ' trifft das richtige Geraet',
        $GLOBALS['aufrufe'][0][1] ?? 0, 50);
}

/* Die Aktionsauswahl — sie ist der bedienbare Teil der aufgezogenen Ansicht. */
$tile = makeTile(['DeviceID' => 50]);
checkTrue('Aktionsvariable existiert', isset($tile->variables['Action']));

$GLOBALS['aufrufe'] = [];
/* Den gewaehlten Wert erst wirklich setzen — sonst steht die Variable ohnehin auf 0 und
   die Ruecksetz-Pruefung prueft nichts. Genau daran ist die erste Fassung dieses Tests
   gescheitert: Die Sabotage "Reset entfernt" blieb unbemerkt. */
$tile->TEST_SetValue('Action', 2);
$tile->RequestAction('Action', 2);
check('Auswahl 2 holt das Wochenprogramm', $GLOBALS['aufrufe'][0][0] ?? '(nichts)', 'RequestSchedule');
check('Auswahl springt zurueck auf Strich', $tile->GetValue('Action'), 0);

$GLOBALS['aufrufe'] = [];
$tile->RequestAction('Action', 4);
check('Auswahl 4 fordert alle Felder an', $GLOBALS['aufrufe'][0][0] ?? '(nichts)', 'RequestAllFields');

// Ohne Bedienrecht darf kein Knopf etwas ausloesen.
$tile = makeTile(['DeviceID' => 50, 'AllowControl' => false]);
$GLOBALS['aufrufe'] = [];
$tile->RequestAction('requestAll', 0);
$tile->RequestAction('setClock', 0);
check('Ohne Bedienrecht bleibt alles ruhig', count($GLOBALS['aufrufe']), 0);

/* ------------------------------------------------------------------ Ergebnis */


if ($failed > 0) {
    printf("\n❌  %d von %d Prüfungen fehlgeschlagen.\n", $failed, $passed + $failed);
    exit(1);
}
printf("✅  Alle %d Prüfungen bestanden.\n", $passed);
exit(0);
