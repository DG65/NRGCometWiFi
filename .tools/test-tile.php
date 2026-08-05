<?php

declare(strict_types=1);

/**
 * Prüfstand für die Kachel — führt die Klasse wirklich aus.
 *
 *   php .tools/test-tile.php     # 0 = alle Prüfungen bestanden
 *
 * Schwerpunkt liegt auf der Nutzlast, nicht auf dem HTML: Was die Kachel zeichnet, kann ein
 * Prüfstand nicht beurteilen — ob Ist und Soll aber getrennt und richtig herum ankommen,
 * schon. Genau das ist hier einmal schiefgegangen (die Isttemperatur trug kein Etikett und
 * wurde als Sollwert gelesen), deshalb prüft dieser Stand beide Felder einzeln.
 */

require_once __DIR__ . '/ips-stub.php';
require_once __DIR__ . '/../CometWiFiTile/module.php';

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

/**
 * Zahlenvergleich für Werte aus der Nutzlast.
 *
 * `json_encode` schreibt den Float 24.0 als `24` — in JavaScript, dem einzigen Verbraucher
 * dieser Nutzlast, gibt es den Unterschied zwischen Ganzzahl und Bruch nicht. Ein
 * Identitätsvergleich würde hier also einen Unterschied prüfen, den es gar nicht gibt.
 */
function checkNum(string $label, $actual, ?float $expected): void
{
    if ($expected === null || $actual === null) {
        check($label, $actual, $expected);
        return;
    }
    check($label, is_numeric($actual) && abs((float) $actual - $expected) < 0.001, true);
}

const GUID_DEVICE = '{0F552C16-D685-4C9F-86C0-8D89E4BFD158}';

/** Legt eine Thermostat-Instanz samt Variablen an. */
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

function makeTile(array $overrides = []): CometWiFiTile
{
    IPSTestState::$instances[90] = [
        'InstanceID'     => 90,
        'ConnectionID'   => 0,
        'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => '{TILE}']
    ];

    $tile = new CometWiFiTile(90);
    $tile->Create();
    foreach ($overrides as $name => $value) {
        $tile->TEST_SetProperty($name, $value);
    }
    $tile->ApplyChanges();
    return $tile;
}

function payloadOf(CometWiFiTile $tile): array
{
    $tile->ApplyChanges();
    return json_decode(IPSTestState::$visualization, true);
}

/** Findet einen Raum in der Nutzlast über den vollen Namen. */
function roomNamed(array $payload, string $name): ?array
{
    foreach ($payload['rooms'] as $room) {
        if ($room['name'] === $name) {
            return $room;
        }
    }
    return null;
}

/* ================================================== 1. Ist und Soll bleiben getrennt */

IPSTestState::reset();
addThermostat(50, 'Thermostat WC', [
    'Temperature' => 24.0,
    'Setpoint'    => 22.0,
    'Battery'     => 95,
    'RSSI'        => -68,
    'Reachable'   => true,
    'Mode'        => true,
    'LastUpdate'  => time() - 120
]);

$payload = payloadOf(makeTile());
$wc = roomNamed($payload, 'Thermostat WC');

check('Ein Raum in der Nutzlast', count($payload['rooms']), 1);
checkNum('Isttemperatur ist die gemessene', $wc['temp'], 24.0);
checkNum('Solltemperatur ist die eingestellte', $wc['setpoint'], 22.0);
check('Ist und Soll sind nicht dasselbe Feld', $wc['temp'] === $wc['setpoint'], false);
check('Kein Endanschlag bei einem echten Sollwert', $wc['endstop'], null);
check('Batterie durchgereicht', $wc['battery'], 95);
check('Handbetrieb erkannt', $wc['manual'], true);
check('Erreichbar', $wc['reachable'], true);

/* Gegenprobe: vertauscht man die Werte im Gerät, muss sich die Nutzlast mitdrehen.
   Ein Test, der das nicht bemerkt, würde eine Vertauschung im Modul durchlassen. */
SetValue(IPS_GetObjectIDByIdent('Temperature', 50), 22.0);
SetValue(IPS_GetObjectIDByIdent('Setpoint', 50), 24.0);
$wc = roomNamed(payloadOf(makeTile()), 'Thermostat WC');
checkNum('Vertauschung dreht die Isttemperatur mit', $wc['temp'], 22.0);
checkNum('Vertauschung dreht die Solltemperatur mit', $wc['setpoint'], 24.0);

/* ============================================================ 2. Endanschläge */

IPSTestState::reset();
addThermostat(51, 'Thermostat Esszimmer', [
    'Temperature' => 24.5, 'Setpoint' => 28.5, 'Reachable' => true, 'LastUpdate' => time()
]);
addThermostat(52, 'Thermostat Windfang', [
    'Temperature' => 25.0, 'Setpoint' => 7.5, 'Reachable' => true, 'LastUpdate' => time()
]);

$payload = payloadOf(makeTile());
check('Oberer Endanschlag heißt An', roomNamed($payload, 'Thermostat Esszimmer')['endstop'], 'An');
check('Unterer Endanschlag heißt Aus', roomNamed($payload, 'Thermostat Windfang')['endstop'], 'Aus');
// Der Zahlenwert bleibt trotzdem in der Nutzlast — die Bedienung rechnet darauf weiter.
checkNum('Endanschlag behält seinen Zahlenwert', roomNamed($payload, 'Thermostat Esszimmer')['setpoint'], 28.5);

/* ================================================ 3. Gemeinsamer Wortanfang fällt weg */

IPSTestState::reset();
addThermostat(51, 'Thermostat Esszimmer',       ['Temperature' => 24.5, 'Reachable' => true]);
addThermostat(52, 'Thermostat Wohnzimmer Links', ['Temperature' => 25.0, 'Reachable' => true]);
addThermostat(53, 'Thermostat Kind 1',           ['Temperature' => 26.5, 'Reachable' => true]);

$payload = payloadOf(makeTile());
check('Gemeinsames Wort entfällt (ein Wort)',  roomNamed($payload, 'Thermostat Esszimmer')['shortName'], 'Esszimmer');
check('Gemeinsames Wort entfällt (zwei Wörter)', roomNamed($payload, 'Thermostat Wohnzimmer Links')['shortName'], 'Wohnzimmer Links');
check('Gemeinsames Wort entfällt (mit Ziffer)', roomNamed($payload, 'Thermostat Kind 1')['shortName'], 'Kind 1');
check('Voller Name bleibt für den Tooltip erhalten', roomNamed($payload, 'Thermostat Kind 1')['name'], 'Thermostat Kind 1');

// Ohne gemeinsamen Anfang darf nichts wegfallen.
IPSTestState::reset();
addThermostat(51, 'Bad',      ['Temperature' => 24.5, 'Reachable' => true]);
addThermostat(52, 'Küche',    ['Temperature' => 25.0, 'Reachable' => true]);
$payload = payloadOf(makeTile());
check('Ohne gemeinsamen Anfang bleibt der Name ganz', roomNamed($payload, 'Bad')['shortName'], 'Bad');

// Identische Namen dürfen nicht zu einem leeren Kurznamen führen.
IPSTestState::reset();
addThermostat(51, 'Thermostat Bad', ['Temperature' => 24.5, 'Reachable' => true]);
addThermostat(52, 'Thermostat Bad', ['Temperature' => 25.0, 'Reachable' => true]);
$payload = payloadOf(makeTile());
check('Gleiche Namen behalten mindestens ein Wort', $payload['rooms'][0]['shortName'], 'Bad');

// Ein einzelnes Gerät hat keinen Vergleich — der Name bleibt vollständig.
IPSTestState::reset();
addThermostat(51, 'Thermostat Bad', ['Temperature' => 24.5, 'Reachable' => true]);
$payload = payloadOf(makeTile());
check('Einzelnes Gerät behält den vollen Namen', $payload['rooms'][0]['shortName'], 'Thermostat Bad');

/* ============================================================ 4. Ausfälle */

IPSTestState::reset();
addThermostat(51, 'Thermostat Esszimmer', ['Temperature' => 24.5, 'Setpoint' => 22.0, 'Reachable' => true,  'Battery' => 90]);
addThermostat(52, 'Thermostat Windfang',  ['Temperature' => 25.0, 'Setpoint' => 22.0, 'Reachable' => false, 'Battery' => 100]);
addThermostat(53, 'Thermostat Kind 1',    ['Temperature' => 26.5, 'Setpoint' => 22.0, 'Reachable' => true,  'Battery' => 20]);

$payload = payloadOf(makeTile(['BatteryWarnBelow' => 25]));
check('Ausfall und schwache Batterie zusammen gezählt', $payload['issues'], 2);
check('Schwache Batterie markiert', roomNamed($payload, 'Thermostat Kind 1')['batteryLow'], true);
check('Volle Batterie nicht markiert', roomNamed($payload, 'Thermostat Esszimmer')['batteryLow'], false);
check('Nicht erreichbares Gerät bleibt sichtbar', roomNamed($payload, 'Thermostat Windfang') !== null, true);

// Ausgeblendet zählt es auch nicht mehr als Problem — sonst zeigte die Kachel eine
// Warnung, deren Ursache nirgends zu sehen ist.
$payload = payloadOf(makeTile(['BatteryWarnBelow' => 25, 'ShowOffline' => false]));
check('Ausgeblendetes Gerät verschwindet', roomNamed($payload, 'Thermostat Windfang'), null);
check('Ausgeblendetes Gerät zählt nicht als Problem', $payload['issues'], 1);

/* ==================================================== 5. Mittelwert und Zählung */

IPSTestState::reset();
addThermostat(51, 'Thermostat A', ['Temperature' => 24.0, 'Reachable' => true]);
addThermostat(52, 'Thermostat B', ['Temperature' => 26.0, 'Reachable' => true]);
addThermostat(53, 'Thermostat C', ['Temperature' => 0.0,  'Reachable' => true]);  // noch nichts empfangen

$payload = payloadOf(makeTile());
checkNum('Mittelwert über die gemessenen Räume', $payload['average'], 25.0);
check('Gerät ohne Messwert liefert null statt 0', roomNamed($payload, 'Thermostat C')['temp'], null);

/* ==================================================== 6. Bedienung nur auf eigene Geräte */

IPSTestState::reset();
addThermostat(51, 'Thermostat A', ['Temperature' => 24.0, 'Setpoint' => 22.0, 'Reachable' => true]);
IPSTestState::$instances[70] = [        // fremdes Modul, kein Thermostat
    'InstanceID' => 70, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE,
    'ModuleInfo' => ['ModuleID' => '{FREMD}'], 'Name' => 'Irgendwas'
];

$tile = makeTile();
// Eine fremde Instanz-ID aus der Kachel heraus darf nicht durchgereicht werden: Die Nutzlast
// kommt aus dem Browser und ist damit nichts, worauf man sich verlassen kann.
$tile->RequestAction('setpoint', json_encode(['id' => 70, 'value' => 21.0]));
check('Fremde Instanz-ID wird abgewiesen', count(IPSTestState::$logMessages), 0);

/* ============================================================ 7. Keine Geräte */

IPSTestState::reset();
$tile = makeTile();
$payload = json_decode(IPSTestState::$visualization, true);
check('Ohne Geräte keine Räume', count($payload['rooms']), 0);
check('Ohne Geräte kein Mittelwert', $payload['average'], null);
check('Ohne Geräte Status 201', $tile->GetStatus(), 201);

/* ============================================================ Ergebnis */

if ($failed > 0) {
    printf("\n❌  %d von %d Prüfungen fehlgeschlagen.\n", $failed, $passed + $failed);
    exit(1);
}
printf("✅  Alle %d Prüfungen bestanden.\n", $passed);
exit(0);
