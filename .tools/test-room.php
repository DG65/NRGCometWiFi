<?php

declare(strict_types=1);

/**
 * Prüfstand für das Raummodul — führt die Klasse wirklich aus.
 *
 *   php .tools/test-room.php     # 0 = alle Prüfungen bestanden
 *
 * Schwerpunkt liegt auf der Zusammenfassung mehrerer Geräte zu einem Wert. Genau dort steckt
 * die Entscheidung, die man leicht falsch trifft: Ein Mittelwert über Sollwerte ist kein
 * Raumsollwert, sondern eine erfundene Zahl.
 */

require_once __DIR__ . '/ips-stub.php';
require_once __DIR__ . '/../CometWiFiRoom/module.php';

IPSTestState::useLocale(__DIR__ . '/../CometWiFiRoom');

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

function checkNum(string $label, $actual, ?float $expected): void
{
    if ($expected === null || $actual === null) {
        check($label, $actual, $expected);
        return;
    }
    check($label, is_numeric($actual) && abs((float) $actual - $expected) < 0.001, true);
}

const GUID_DEVICE = '{0F552C16-D685-4C9F-86C0-8D89E4BFD158}';

/* Die Wrapper des Gerätemoduls nachbilden und mitschreiben. So prüft der Stand nicht nur,
   was der Raum anzeigt, sondern ob jedes Mitglied den Befehl auch wirklich bekommt. */
$GLOBALS['aufrufe'] = [];

function CWIFI_SetTemperature(int $id, float $wert): bool
{
    $GLOBALS['aufrufe'][] = ['SetTemperature', $id, $wert];
    return true;
}

function CWIFI_SetManualMode(int $id, bool $manuell): bool
{
    $GLOBALS['aufrufe'][] = ['SetManualMode', $id, $manuell];
    return true;
}

/**
 * Die maschinelle Fassung, ueber die der Raum seine Mitglieder anspricht.
 *
 * Bewusst NICHT die gleichnamigen Geraetemethoden: Die liefern seit 0.19.0 Anzeigetext, und
 * ein nicht leerer Fehlertext waere `true` — der Raum meldete dann Erfolg fuer jedes Geraet,
 * das gar nichts gesendet hat. Wer diesen Stub auf string umstellt, muss den Raum mit
 * umstellen; die Gegenprobe dazu steht weiter unten.
 */
function CWIFI_SendAction(int $id, string $aktion): bool
{
    $GLOBALS['aufrufe'][] = [$aktion, $id];
    // Pro Instanz steuerbar, damit sich auch der Teilerfolg pruefen laesst.
    if (isset($GLOBALS['sendActionFehlschlag'][$id])) {
        return false;
    }
    return $GLOBALS['sendActionErfolg'] ?? true;
}

function addThermostat(int $id, string $name, array $values, string $mac = ''): void
{
    IPSTestState::$instances[$id] = [
        'InstanceID'     => $id,
        'ConnectionID'   => 10,
        'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => GUID_DEVICE],
        'Properties'     => ['MAC' => $mac],
        'Name'           => $name
    ];
    foreach ($values as $ident => $value) {
        IPSTestState::addObject($id, $ident, $value);
    }
}

function makeRoom(array $mitglieder, array $overrides = []): CometWiFiRoom
{
    IPSTestState::$instances[90] = [
        'InstanceID'     => 90,
        'ConnectionID'   => 0,
        'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => '{ROOM}']
    ];
    $room = new CometWiFiRoom(90);
    $room->Create();
    $room->TEST_SetProperty('Members', json_encode(
        array_map(fn ($id) => ['DeviceID' => $id], $mitglieder)
    ));
    foreach ($overrides as $name => $value) {
        $room->TEST_SetProperty($name, $value);
    }
    $room->ApplyChanges();
    return $room;
}

/* ==================================================== 1. Zusammenfassung der Werte */

IPSTestState::reset();
addThermostat(50, 'Wohnzimmer Links', [
    'Temperature' => 21.0, 'Setpoint' => 22.0, 'Battery' => 80,
    'Reachable' => true, 'Mode' => true
]);
addThermostat(51, 'Wohnzimmer Rechts', [
    'Temperature' => 23.0, 'Setpoint' => 22.0, 'Battery' => 40,
    'Reachable' => true, 'Mode' => true
]);

$room = makeRoom([50, 51]);
checkNum('Isttemperatur ist das Mittel', $room->GetValue('Temperature'), 22.0);
checkNum('Einheitlicher Sollwert wird uebernommen', $room->GetValue('Setpoint'), 22.0);
check('Einheitlich heisst nicht uneinheitlich', $room->GetValue('Mixed'), false);
check('Schwaechste Batterie zaehlt', $room->GetValue('Battery'), 40);
check('Alle erreichbar', $room->GetValue('Reachable'), true);
check('Mitgliederzahl', $room->GetValue('MemberCount'), 2);
check('Betriebsart uebernommen', $room->GetValue('Mode'), true);

// Kälteste Stelle statt Mittel.
$room = makeRoom([50, 51], ['Aggregation' => 'minimum']);
checkNum('Kleinstwert liefert die kaelteste Stelle', $room->GetValue('Temperature'), 21.0);

/* ==================================================== 2. Uneinheitliche Sollwerte */

SetValue(IPS_GetObjectIDByIdent('Setpoint', 50), 20.0);
SetValue(IPS_GetObjectIDByIdent('Setpoint', 51), 24.0);
$room = makeRoom([50, 51]);

/* Der wichtigste Test dieses Standes: Ein Mittelwert waere 22 — eine Zahl, die an keinem
   Geraet steht und den Raum falsch beschreibt. Gezeigt wird der hoechste Wert, und die
   Uneinigkeit wird ausdruecklich gemeldet. */
checkNum('Bei Uneinigkeit kein Mittelwert', $room->GetValue('Setpoint'), 24.0);
check('Uneinigkeit wird gemeldet', $room->GetValue('Mixed'), true);

/* ==================================================== 3. Ausfall eines Mitglieds */

SetValue(IPS_GetObjectIDByIdent('Reachable', 51), false);
$room = makeRoom([50, 51]);
// Ein Raum, in dem ein Ventil nicht antwortet, ist nicht vollstaendig geschaltet.
check('Ein ausgefallenes Mitglied genuegt', $room->GetValue('Reachable'), false);

SetValue(IPS_GetObjectIDByIdent('Reachable', 51), true);
SetValue(IPS_GetObjectIDByIdent('Mode', 51), false);
$room = makeRoom([50, 51]);
check('Betriebsart nur wenn ALLE im Handbetrieb', $room->GetValue('Mode'), false);

/* ==================================================== 4. Mitgliederliste pruefen */

IPSTestState::reset();
addThermostat(50, 'Wohnzimmer Links', ['Temperature' => 21.0, 'Reachable' => true]);
IPSTestState::$instances[70] = [
    'InstanceID' => 70, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE,
    'ModuleInfo' => ['ModuleID' => '{FREMD}'], 'Name' => 'Irgendwas'
];

$room = makeRoom([50, 70, 50, 0]);
// Fremdes Modul, Doppelte und Nullen duerfen nicht mitzaehlen.
check('Nur echte Thermostate zaehlen', $room->GetValue('MemberCount'), 1);

$room = makeRoom([]);
check('Ohne Mitglieder Status 201', $room->GetStatus(), 201);
check('Ohne Mitglieder nicht erreichbar', $room->GetValue('Reachable'), false);

// Ein geloeschtes Mitglied darf den Raum nicht mitreissen.
$room = makeRoom([50]);
unset(IPSTestState::$instances[50]);
$room->ApplyChanges();
check('Geloeschtes Mitglied faellt heraus', $room->GetValue('MemberCount'), 0);

/* ==================================================== 5. Bedienung grenzt Werte ein */

IPSTestState::reset();
addThermostat(50, 'Wohnzimmer Links', ['Temperature' => 21.0, 'Setpoint' => 22.0, 'Reachable' => true]);
$room = makeRoom([50]);

/* Werte ausserhalb der Skala muessen eingegrenzt werden, bevor sie das Geraet erreichen. */
$GLOBALS['aufrufe'] = [];
$room->SetTemperature(99.0);
checkNum('Auch beim Geraet kommt nur der begrenzte Wert an', $GLOBALS['aufrufe'][0][2], 28.5);
checkNum('Zu hoher Wert wird auf das Maximum begrenzt', $room->GetValue('Setpoint'), 28.5);
$room->SetTemperature(-5.0);
checkNum('Zu tiefer Wert wird auf das Minimum begrenzt', $room->GetValue('Setpoint'), 7.5);
$room->SetTemperature(21.3);
checkNum('Auf das Halbgrad-Raster gerundet', $room->GetValue('Setpoint'), 21.5);
check('Nach dem Setzen sind die Mitglieder wieder einig', $room->GetValue('Mixed'), false);

$room = makeRoom([]);
checkTrue('Ohne Mitglieder wird nichts gesetzt', $room->SetTemperature(21.0) === false);

/* ==================================================== 6. Aktionsauswahl wirkt auf alle */

IPSTestState::reset();
addThermostat(50, 'Wohnzimmer Links',  ['Temperature' => 21.0, 'Setpoint' => 22.0, 'Reachable' => true]);
addThermostat(51, 'Wohnzimmer Rechts', ['Temperature' => 23.0, 'Setpoint' => 22.0, 'Reachable' => true]);
$room = makeRoom([50, 51]);
checkTrue('Aktionsvariable existiert', isset($room->variables['Action']));

$GLOBALS['aufrufe'] = [];
$room->TEST_SetValue('Action', 3);
$room->RequestAction('Action', 3);
check('Uhr stellen erreicht beide Mitglieder', count($GLOBALS['aufrufe']), 2);
check('… mit der richtigen Aktion', $GLOBALS['aufrufe'][0][0], 'Clock');
check('… und springt zurueck auf Strich', $room->GetValue('Action'), 0);

/* ============================ 6b. Sichtbare Rueckmeldung (SUITE.md, 20.08.2026) */

/* Jeder Knopf muss ohne Formular-Neuoeffnen zeigen, dass etwas passiert ist. Der Raum nennt
   dabei Zahlen: "an 4 von 5" ist die einzige ehrliche Meldung fuer ein halb gegluecktes
   Sammelkommando. */
IPSTestState::reset();
addThermostat(50, 'Wohnzimmer Links',  ['Temperature' => 21.0, 'Reachable' => true]);
addThermostat(51, 'Wohnzimmer Rechts', ['Temperature' => 23.0, 'Reachable' => true]);
$room = makeRoom([50, 51]);

$GLOBALS['sendActionErfolg'] = true;
$text = $room->RequestUpdate();
checkTrue('Erfolg wird gemeldet', str_starts_with($text, '✅'));
checkTrue('… und nennt die Anzahl', str_contains($text, 'alle 2 Geräte'));
checkTrue('… und verspricht keine sofortige Antwort', str_contains($text, 'sobald'));

$GLOBALS['sendActionErfolg'] = false;
$text = $room->SetClock();
checkTrue('Misserfolg wird gemeldet', str_starts_with($text, '⚠️'));
checkTrue('… und bleibt nicht stumm', strlen($text) > 30);

/* Teilerfolg: das am schwersten zu bemerkende Ergebnis — ein Geraet ohne MAC faellt sonst
   unter einem gruenen Haken fuer den ganzen Raum durch. */
$GLOBALS['sendActionErfolg']     = true;
$GLOBALS['sendActionFehlschlag'] = [51 => true];
$text = $room->RequestSchedule();
checkTrue('Teilerfolg wird als solcher gemeldet', str_starts_with($text, '⚠️'));
checkTrue('… und nennt beide Zahlen', str_contains($text, 'an 1 von 2 Geräten'));
$GLOBALS['sendActionFehlschlag'] = [];

/* Ohne Mitglieder darf kein Erfolg gemeldet werden — sonst sieht der Nutzer einen Haken,
   obwohl nichts gesendet wurde. */
$leer = makeRoom([]);
$text = $leer->RequestAllFields();
checkTrue('Ohne Mitglieder kein Haken', str_starts_with($text, '⚠️'));
checkTrue('… mit Hinweis auf die Zuordnung', str_contains($text, 'Mitglied'));

/* AddGroupMembers meldete frueher stumm 0 zurueck. */
$leer = makeRoom([]);
$text = $leer->AddGroupMembers();
checkTrue('Gruppenergaenzung ohne Auswahl meldet sich', str_starts_with($text, '⚠️'));

unset($GLOBALS['sendActionErfolg'], $GLOBALS['sendActionFehlschlag']);

/* ==================================================== 7. Uebersetzungen vorhanden */

IPSTestState::reset();
addThermostat(50, 'Wohnzimmer Links', ['Temperature' => 21.0, 'Reachable' => true]);
$room = makeRoom([50]);
check('Variablenname uebersetzt', $room->variables['Temperature']['caption'], 'Isttemperatur');
check('Sollwert uebersetzt', $room->variables['Setpoint']['caption'], 'Solltemperatur');
check('Uneinheitlich uebersetzt', $room->variables['Mixed']['caption'], 'Mitglieder uneinheitlich');
check('Batterie uebersetzt', $room->variables['Battery']['caption'], 'Schwächste Batterie');


/* ==================================================== 7. Kachel des Raums */

IPSTestState::reset();
addThermostat(50, 'Wohnzimmer Links', [
    'Temperature' => 21.0, 'Setpoint' => 22.0, 'Battery' => 80, 'Reachable' => true,
    'Mode' => true, 'ClockDeviation' => 3, 'LastUpdate' => time() - 300
]);
addThermostat(51, 'Wohnzimmer Rechts', [
    'Temperature' => 23.0, 'Setpoint' => 22.0, 'Battery' => 40, 'Reachable' => true,
    'Mode' => true, 'ClockDeviation' => 42, 'LastUpdate' => time() - 180
]);
$room = makeRoom([50, 51], ['ClockWarnMinutes' => 15]);

$payload = json_decode(IPSTestState::$visualization, true);
checkTrue('Der Raum liefert eine Kachel-Nutzlast', $payload['ok']);
checkNum('Kachel zeigt die Raumtemperatur', $payload['temp'], 22.0);
checkNum('Kachel zeigt den Raumsollwert', $payload['setpoint'], 22.0);
check('Kachel kennt die Mitgliederzahl', $payload['members'], 2);
check('Kachel meldet Einigkeit', $payload['mixed'], false);

/* Die groesste Abweichung unter den Mitgliedern zaehlt — eine falsch gehende Uhr verschiebt
   die Schaltzeiten dieses einen Geraets, und das ist der ganze Raum. */
check('Kachel meldet die groesste Uhrabweichung', $payload['clockDev'], 42);
check('Uhrabweichung schlaegt an', $payload['clockOff'], true);
// Die juengste Meldung, nicht die aelteste: Sie sagt, wie frisch die Daten hoechstens sind.
check('Kachel nennt die juengste Meldung', $payload['lastText'], 'vor 3 min');

/* Felder, die es nur am Einzelgeraet gibt, muessen leer bleiben — sonst zeichnet die
   gemeinsame Vorlage Platzhalter. */
check('Kein Signalwert am Raum', $payload['signal'], null);
check('Keine Tastensperre am Raum', $payload['keylock'], 0);
check('Kein Urlaub am Raum', $payload['holiday'], false);

// Uneinheitliche Sollwerte muessen bis in die Kachel durchschlagen.
SetValue(IPS_GetObjectIDByIdent('Setpoint', 50), 20.0);
SetValue(IPS_GetObjectIDByIdent('Setpoint', 51), 24.0);
$room = makeRoom([50, 51]);
check('Uneinigkeit erreicht die Kachel',
    json_decode(IPSTestState::$visualization, true)['mixed'], true);

// Ohne Mitglieder darf die Kachel nichts behaupten.
$room = makeRoom([]);
check('Ohne Mitglieder keine Kachel-Nutzlast',
    json_decode(IPSTestState::$visualization, true)['ok'], false);

/* Beide Module muessen DIESELBE Vorlage benutzen. Zwei Kopien waeren zwei Kacheln, die
   auseinanderlaufen — und genau das war der Anlass, sie in die Bibliothek zu legen. */
$vorlage = __DIR__ . '/../.libs/CWIFI_RoomTile.html';
checkTrue('Die gemeinsame Vorlage liegt in der Bibliothek', is_file($vorlage));
checkTrue('Das Raummodul haelt keine eigene Kopie',
    !is_file(__DIR__ . '/../CometWiFiRoom/module.html'));
checkTrue('Die Raumkachel haelt keine eigene Kopie',
    !is_file(__DIR__ . '/../CometWiFiRoomTile/module.html'));

/* ------------------------------------------------------------------ Ergebnis */


if ($failed > 0) {
    printf("\n❌  %d von %d Prüfungen fehlgeschlagen.\n", $failed, $passed + $failed);
    exit(1);
}
printf("✅  Alle %d Prüfungen bestanden.\n", $passed);
exit(0);
