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

// Echte Übersetzungstabelle verwenden — so prüfen die Captions auch, ob der Eintrag existiert.
IPSTestState::useLocale(__DIR__ . '/../CometWiFiThermostat');

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

/* ------------------------------------------ Urlaub und Wochenprogramm empfangen */

$device = makeDevice();

// Payload aus dem Mitschnitt, Sollwerte aus dem App-Screenshot.
deliver($device, BASE . '/V/A7', '#0C1F071A0C10081A32');
check('Urlaub erkannt', $device->GetValue('Holiday'), true);
check('Urlaubsbeginn', date('d.m.Y H:i', $device->GetValue('HolidayFrom')), '31.07.2026 12:00');
check('Urlaubsende', date('d.m.Y H:i', $device->GetValue('HolidayTo')), '16.08.2026 12:00');
check('Urlaubstemperatur', $device->GetValue('HolidayTemperature'), 25.0);

// Neun Byte FF ist ein gueltiger Zustand ("kein Urlaub"), keine Stoerung.
deliver($device, BASE . '/V/A7', '#FFFFFFFFFFFFFFFFFF');
check('Kein Urlaub gesetzt', $device->GetValue('Holiday'), false);
check('Zeitraum zurueckgesetzt', $device->GetValue('HolidayFrom'), 0);

// Wochenprogramm: je Tag ein Register, zusammengesetzt zu einer lesbaren Uebersicht.
deliver($device, BASE . '/V/A8', '#062C09E4176C1FA4');
deliver($device, BASE . '/V/AD', '#BEACD524');
$plan = $device->GetValue('Schedule');
checkTrue('Montag im Wochenprogramm', strpos($plan, 'Montag: 04:00 → 22,0 °C') !== false);
checkTrue('Samstag im Wochenprogramm', strpos($plan, 'Samstag: 07:00 → 22,0 °C') !== false);
checkTrue('Noch nicht gelesene Tage bleiben leer', strpos($plan, 'Dienstag: –') !== false);

// Urlaub setzen erzeugt genau den Payload der App.
IPSTestState::$sentPackets = [];
checkTrue('Urlaub setzen meldet Erfolg',
    $device->SetHoliday(mktime(12,0,0,7,31,2026), mktime(12,0,0,8,16,2026), 25.0));
check('Urlaub geht auf S/A7', IPSTestState::$sentPackets[0]['Topic'], BASE . '/S/A7');
check('Urlaub-Payload wie in der App',
    IPSTestState::$sentPackets[0]['Payload'], '#0C1F071A0C10081A32');

// Ein Ende vor dem Beginn ist ein Bedienfehler und darf nichts senden.
IPSTestState::$sentPackets = [];
checkTrue('Ende vor Beginn wird abgelehnt',
    !$device->SetHoliday(mktime(12,0,0,8,16,2026), mktime(12,0,0,7,31,2026), 25.0));
check('Bei Ablehnung wird nichts gesendet', count(IPSTestState::$sentPackets), 0);

IPSTestState::$sentPackets = [];
$device->ClearHoliday();
check('Urlaub loeschen sendet neun FF',
    IPSTestState::$sentPackets[0]['Payload'], '#FFFFFFFFFFFFFFFFFF');

/* ================================================== 3. Rohdaten und Unbekanntes */

// BE ist noch nicht gedeutet. Dieser Platzhalter musste schon zweimal weiterziehen — erst
// war es A3, dann A4, beide sind inzwischen entschluesselt. Wer das naechste Register deutet,
// waehlt hier eines aus, das noch offen ist (siehe .docs/protokoll.md).
$device = makeDevice(['RawRegisters' => false]);
deliver($device, BASE . '/V/BE', '#FF6300');
checkTrue('Ohne Rohdatenerfassung keine RAW-Variable', !isset($device->variables['RAW_BE']));

$device = makeDevice(['RawRegisters' => true]);
deliver($device, BASE . '/V/BE', '#FF6300');
checkTrue('Mit Rohdatenerfassung entsteht RAW_BE', isset($device->variables['RAW_BE']));
check('RAW_BE enthält den unveränderten Payload', $device->GetValue('RAW_BE'), '#FF6300');

// Gedeutete Register duerfen NICHT zusaetzlich als Rohwert auftauchen.
deliver($device, BASE . '/V/A3', '#2182');
checkTrue('Entschluesseltes A3 landet nicht im Rohpfad', !isset($device->variables['RAW_A3']));
check('A3 setzt stattdessen die Optionen', $device->GetValue('Mode'), true);

deliver($device, BASE . '/V/BD', '#0806');
check('RAW_BD enthält den unveränderten Payload', $device->GetValue('RAW_BD'), '#0806');

// Undekodierte Register dürfen NICHT als gedeutete Variable auftauchen.
foreach (['A4', 'A5', 'A7', 'BB', 'BD'] as $register) {
    checkTrue(
        "Register {$register} wird bewusst nicht gedeutet",
        CWIFI_Registers::byRegister($register) === null
    );
}

/* ==================================================== 4. Sendepfad (Sollwert) */

$device = makeDevice();
IPSTestState::$sentPackets = [];

// Ohne Zwangsumschaltung: genau zwei Pakete - der Sollwert und der Nachzieher. Ohne die
// zweite Nachricht verwirft das Gerät den Sollwert und meldet seinen alten Wert zurück
// (am Gerät belegt, die Hersteller-Cloud macht es genauso).
$device = makeDevice(['ForceManualOnSet' => false]);
IPSTestState::$sentPackets = [];
checkTrue('SetTemperature meldet Erfolg', $device->SetTemperature(21.5));
check('Sollwert und Nachzieher gesendet', count(IPSTestState::$sentPackets), 2);

$packet = IPSTestState::$sentPackets[0];
$follow = IPSTestState::$sentPackets[1];
check('Nachzieher geht auf S/AF', $follow['Topic'], BASE . '/S/AF');
// Gezielt nur A0 statt des kompletten Feld-Dumps — das genügt und weckt das Gerät kürzer.
check('Nachzieher fordert gezielt A0 an', $follow['Payload'], '#01000000');
check('Topic ist das Set-Topic', $packet['Topic'], BASE . '/S/A0');
check('Payload 21,5 °C = #2B', $packet['Payload'], '#2B');
// Ein retaintes Kommando würde bei jedem Reconnect erneut zugestellt und das
// Wochenprogramm des Geräts dauerhaft überschreiben.
check('Retain ist aus', $packet['Retain'], false);
// QoS 0 wie die Hersteller-Cloud selbst. QoS 1 wurde von Symcons MQTT-Instanz abgelehnt,
// das Paket ging nicht hinaus.
check('QoS 0', $packet['QualityOfService'], 0);
check('PacketType PUBLISH', $packet['PacketType'], 3);

check('Variable folgt sofort (optimistisch)', $device->GetValue('Setpoint'), 21.5);

/* ------------------------- Zwangsumschaltung auf Handbetrieb (Standardverhalten) */

// Laeuft der Zeitplan, ueberschreibt er einen gesetzten Sollwert beim naechsten
// Schaltpunkt. Deshalb schaltet das Modul vorher auf Handbetrieb - sonst waere jede
// Automatisierung aus Symcon heraus wirkungslos.
$device = makeDevice(['ForceManualOnSet' => true]);
$device->SetValue('Mode', false);              // Zeitplan laeuft
IPSTestState::$sentPackets = [];
$device->SetTemperature(20.0);

check('Vier Pakete: Umschaltung, Nachzieher, Sollwert, Nachzieher',
    count(IPSTestState::$sentPackets), 4);
check('Zuerst wird auf Handbetrieb geschaltet',
    IPSTestState::$sentPackets[0]['Topic'], BASE . '/S/A3');
check('Handbetrieb setzt Bit 0x20',
    IPSTestState::$sentPackets[0]['Payload'], '#2000000000');
check('Danach folgt der Sollwert',
    IPSTestState::$sentPackets[2]['Topic'], BASE . '/S/A0');
check('Betriebsart-Variable steht auf Handbetrieb', $device->GetValue('Mode'), true);

// Steht der Handbetrieb schon, wird nicht unnoetig umgeschaltet - das waere ein
// zusaetzliches Wecken des Batteriegeraets bei jedem Sollwert.
IPSTestState::$sentPackets = [];
$device->SetTemperature(21.0);
check('Bei bereits aktivem Handbetrieb nur zwei Pakete',
    count(IPSTestState::$sentPackets), 2);

/* ----------------------------------------------- Optionen schalten (Register A3) */

$device = makeDevice();
IPSTestState::$sentPackets = [];
checkTrue('Tastensperre setzen meldet Erfolg', $device->SetKeyLock(CWIFI_Registers::LOCK_ON));
check('Tastensperre auf S/A3', IPSTestState::$sentPackets[0]['Topic'], BASE . '/S/A3');
check('Tastensperre-Befehl wie in der App', IPSTestState::$sentPackets[0]['Payload'], '#0408000000');
check('Nachzieher fordert A3 an', IPSTestState::$sentPackets[1]['Payload'], '#08000000');
check('Variable folgt', $device->GetValue('KeyLock'), CWIFI_Registers::LOCK_ON);

IPSTestState::$sentPackets = [];
$device->SetKeyLock(CWIFI_Registers::LOCK_PLUS);
check('Tastensperre plus wie in der App', IPSTestState::$sentPackets[0]['Payload'], '#0804000000');

IPSTestState::$sentPackets = [];
$device->SetRotateDisplay(true);
check('Anzeige drehen wie in der App', IPSTestState::$sentPackets[0]['Payload'], '#0200000000');

IPSTestState::$sentPackets = [];
$device->SetAutoDST(false);
check('Sommerzeit aus wie in der App', IPSTestState::$sentPackets[0]['Payload'], '#0001000000');

IPSTestState::$sentPackets = [];
$device->SetOffset(1.0);
check('Offset auf S/A2', IPSTestState::$sentPackets[0]['Topic'], BASE . '/S/A2');
check('Offset +1,0 K wie in der App', IPSTestState::$sentPackets[0]['Payload'], '#02');

/* -------------------------------- Optionen empfangen und aufteilen */

$device = makeDevice();
deliver($device, BASE . '/V/A3', '#2182');
check('Handbetrieb aus #2182', $device->GetValue('Mode'), true);
check('Sommerzeit aus #2182', $device->GetValue('AutoDST'), true);
check('Drehung aus #2182', $device->GetValue('RotateDisplay'), false);
check('Sperre aus #2182', $device->GetValue('KeyLock'), CWIFI_Registers::LOCK_OFF);

deliver($device, BASE . '/V/A3', '#0182');
check('Zeitplan aktiv bei #0182', $device->GetValue('Mode'), false);

deliver($device, BASE . '/V/A3', '#2982');
check('Sperre plus bei #2982', $device->GetValue('KeyLock'), CWIFI_Registers::LOCK_PLUS);

deliver($device, BASE . '/V/A2', '#02');
check('Offset aus #02', $device->GetValue('Offset'), 1.0);

// Halbgrad-Raster und Grenzen.
$device->SetTemperature(21.3);
check('21,3 wird auf 21,5 gerundet', $device->GetValue('Setpoint'), 21.5);
// Geklemmt wird jetzt auf die echten Endanschlaege des Geraets, nicht auf geratene Werte.
$device->SetTemperature(2.0);
check('Unter Minimum wird auf „Aus" geklemmt', $device->GetValue('Setpoint'), 7.5);
$device->SetTemperature(99.0);
check('Über Maximum wird auf „An" geklemmt', $device->GetValue('Setpoint'), 28.5);

// RequestAction ist der Weg aus dem WebFront.
IPSTestState::$sentPackets = [];
$device->RequestAction('Setpoint', 19.0);
check('RequestAction sendet Sollwert und Nachzieher', count(IPSTestState::$sentPackets), 2);
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

/* ------------------------------------- Fehlgeschlagenes Senden wird gemeldet */

// Der unangenehmste Fehler dieses Moduls ist ein still verworfener Sollwert: Der Nutzer
// stellt einen Wert ein, die Variable übernimmt ihn, und nichts passiert. Genau so blieb
// eine von Symcon abgelehnte QoS-1-Nachricht unbemerkt.
IPSTestState::reset();
IPSTestState::$instances[20] = [
    'InstanceID'     => 20,
    'ConnectionID'   => 0,          // kein Elternteil
    'InstanceStatus' => IS_ACTIVE,
    'ModuleInfo'     => ['ModuleID' => '{DEVICE}']
];
$mute = new CometWiFiThermostat(20);
$mute->Create();
$mute->TEST_SetProperty('MAC', MAC);
$mute->TEST_SetProperty('MQTTUser', USER);
$mute->ApplyChanges();

IPSTestState::$sentPackets = [];
IPSTestState::$logMessages = [];
checkTrue('Ohne Elternteil meldet SetTemperature Misserfolg', !$mute->SetTemperature(20.0));
check('Ohne Elternteil wird nichts gesendet', count(IPSTestState::$sentPackets), 0);
checkTrue(
    'Fehlgeschlagenes Senden landet im Protokoll, nicht nur im Debug',
    (bool) array_filter(
        IPSTestState::$logMessages,
        fn ($m) => is_string($m) && stripos($m, 'Senden fehlgeschlagen') !== false
    )
);

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


/* ==================================================== Geräteauskunft in Klarschrift */

$device = makeDevice(['RawRegisters' => true]);

deliver($device, BASE . '/V/B1', '#436F6D65742057696669205665722E20362E31');
deliver($device, BASE . '/V/B2', '#322E372E312E30');
deliver($device, BASE . '/V/B6', '#00C0A8022D01445F0301');
deliver($device, BASE . '/V/BA', '#24:f5:a2:74:7b:ab');
deliver($device, BASE . '/V/BF', '#5B575041322D50534B2D43434D505D5B4553535D');
deliver($device, BASE . '/V/B0', '#U000000000000');

check('Modell in Klarschrift',           $device->GetValue('Model'),        'Comet Wifi Ver. 6.1');
check('Firmware in Klarschrift',         $device->GetValue('Firmware'),     '2.7.1.0');
check('IP in Klarschrift',               $device->GetValue('IPAddress'),    '192.168.2.45');
check('Zugangspunkt in Klarschrift',     $device->GetValue('AccessPoint'),  '24:f5:a2:74:7b:ab');
check('Verschluesselung in Klarschrift', $device->GetValue('WifiSecurity'), '[WPA2-PSK-CCMP][ESS]');
check('Gruppe in Klarschrift',           $device->GetValue('Group'),        'Einzelgerät');

// Beides nebeneinander waere doppelt gefuehrt und koennte sich widersprechen.
foreach (['B0', 'B1', 'B2', 'B6', 'BA', 'BF'] as $reg) {
    checkTrue('Keine Rohfassung mehr fuer ' . $reg, !isset($device->variables['RAW_' . $reg]));
}

// Auch mit ABGESCHALTETER Rohdatenerfassung muss die Auskunft entstehen: Sie ist kein
// Rohwert, sondern eine belegte Angabe.
$device = makeDevice(['RawRegisters' => false]);
deliver($device, BASE . '/V/B2', '#322E372E312E30');
check('Auskunft auch ohne Rohdatenerfassung', $device->GetValue('Firmware'), '2.7.1.0');

// Unlesbares darf keine halbgare Auskunft erzeugen, sondern faellt in den Rohpfad zurueck.
$device = makeDevice(['RawRegisters' => true]);
deliver($device, BASE . '/V/B1', '#0102');
checkTrue('Unlesbares B1 erzeugt keine Auskunft', !isset($device->variables['Model']));
check('Unlesbares B1 landet im Rohpfad', $device->GetValue('RAW_B1'), '#0102');

// Die Auskunft ist ein Lebenszeichen wie jede andere Gerätemeldung.
$device = makeDevice();
deliver($device, BASE . '/V/B2', '#322E372E312E30');
checkTrue('Auskunft gilt als Lebenszeichen', $device->GetValue('Reachable') === true);


/* ==================================================== Geräteuhr und alte Rohwerte */

$device = makeDevice(['RawRegisters' => true]);
deliver($device, BASE . '/V/A4', '#3515010114');

check('Geraeteuhr als Text', $device->GetValue('DeviceClock'), '21:53');
checkTrue('Uhrabweichung ist eine Zahl', is_int($device->GetValue('ClockDeviation')));
checkTrue('A4 landet nicht mehr im Rohpfad', !isset($device->variables['RAW_A4']));

// Unlesbares faellt zurueck in den Rohpfad, statt eine falsche Uhrzeit zu behaupten.
$device = makeDevice(['RawRegisters' => true]);
deliver($device, BASE . '/V/A4', '#0018010114');       // Stunde 24
checkTrue('Ungueltige Uhr erzeugt keine Uhrvariable', !isset($device->variables['DeviceClock']));
check('Ungueltige Uhr landet im Rohpfad', $device->GetValue('RAW_A4'), '#0018010114');

/* Verwaiste Rohwerte aus aelteren Versionen muessen verschwinden, sobald das Register
   wieder ankommt. Der Ausgangszustand wird hier kuenstlich hergestellt. */
$device = makeDevice(['RawRegisters' => true]);
foreach (['A7', 'A8', 'AE', 'A3', 'A2', 'B1'] as $reg) {
    $device->TEST_MaintainVariable('RAW_' . $reg, 'Alt', VARIABLETYPE_STRING, '', 100, true);
}
deliver($device, BASE . '/V/A7', '#0C1F071A0C10081A32');
deliver($device, BASE . '/V/A8', '#062C09E4176C1FA4');
deliver($device, BASE . '/V/AE', '#E2ACF924');
deliver($device, BASE . '/V/A3', '#2000000000000000');
deliver($device, BASE . '/V/A2', '#00');
deliver($device, BASE . '/V/B1', '#436F6D65742057696669205665722E20362E31');
foreach (['A7', 'A8', 'AE', 'A3', 'A2', 'B1'] as $reg) {
    checkTrue('Verwaister RAW_' . $reg . ' ist weg', !isset($device->variables['RAW_' . $reg]));
}

// Ein wirklich unbekanntes Register behaelt seine Rohfassung.
deliver($device, BASE . '/V/BE', '#FF6300');
check('Unbekanntes Register behaelt die Rohfassung', $device->GetValue('RAW_BE'), '#FF6300');


/* ==================================================== Uhr stellen */

$device = makeDevice();
IPSTestState::$sentPackets = [];
checkTrue('SetClock meldet Erfolg', $device->SetClock());

$pakete = IPSTestState::$sentPackets;
check('SetClock sendet zwei Nachrichten', count($pakete), 2);
check('erste geht auf S/A4',   $pakete[0]['Topic'], BASE . '/S/A4');
checkTrue('Payload ist eine Uhrzeit', CWIFI_Registers::decodeClock($pakete[0]['Payload']) !== null);
check('Abweichung der gesendeten Zeit ist null',
    CWIFI_Registers::decodeClock($pakete[0]['Payload'])['deviation'], 0);
// Ohne den Nachzieher bestaetigt das Geraet nichts — dasselbe gilt beim Sollwert.
check('zweite fordert A4 zurueck', $pakete[1]['Topic'], BASE . '/S/AF');
checkTrue('Kommandos NIE mit Retain', $pakete[0]['Retain'] === false && $pakete[1]['Retain'] === false);

// Ohne MAC gibt es kein Topic und damit auch keinen Sendeversuch.
$device = makeDevice(['MAC' => '']);
IPSTestState::$sentPackets = [];
checkTrue('Ohne MAC kein Uhrbefehl', !$device->SetClock());
check('Ohne MAC wird nichts gesendet', count(IPSTestState::$sentPackets), 0);

/* Der Hinweis auf eine schief stehende Uhr muss kommen und wieder verschwinden. */
$device = makeDevice(['ClockWarnMinutes' => 15]);
$weit = date('H', time() + 3600 * 3) * 60 + (int) date('i');
deliver($device, BASE . '/V/A4', sprintf('#%02X%02X010114',
    (int) date('i', time() + 10800), (int) date('G', time() + 10800)));
check('Weit abweichende Uhr meldet Status 207', $device->GetStatus(), 207);

/* Der eigentliche Fallstrick: markAlive() setzt bei JEDER Meldung auf aktiv zurueck. Ein
   Hinweis, der die naechste Temperaturmeldung nicht ueberlebt, ist praktisch unsichtbar. */
deliver($device, BASE . '/V/A1', '#33');
check('Hinweis ueberlebt eine folgende Gerätemeldung', $device->GetStatus(), 207);

deliver($device, BASE . '/V/A4', sprintf('#%02X%02X010114', (int) date('i'), (int) date('G')));
checkTrue('Richtig stehende Uhr nimmt den Hinweis zurueck', $device->GetStatus() !== 207);
deliver($device, BASE . '/V/A1', '#33');
checkTrue('und er kommt auch nicht zurueck', $device->GetStatus() !== 207);

// Abgeschaltet heisst abgeschaltet.
$device = makeDevice(['ClockWarnMinutes' => 0]);
deliver($device, BASE . '/V/A4', sprintf('#%02X%02X010114',
    (int) date('i', time() + 10800), (int) date('G', time() + 10800)));
checkTrue('Schwelle 0 meldet nichts', $device->GetStatus() !== 207);


/* ==================================================== Rohwerte aufraeumen */

// Die nachweislich leeren Register entstehen gar nicht erst.
$device = makeDevice(['RawRegisters' => true]);
foreach (['AF', 'B4', 'B5', 'B7', 'BB', 'BC'] as $reg) {
    deliver($device, BASE . '/V/' . $reg, '#00');
    checkTrue('Leeres Register ' . $reg . ' wird nicht angelegt', !isset($device->variables['RAW_' . $reg]));
}
// Die uebrigen unbekannten schon — sonst verlöre man die Grundlage zum Entschluesseln.
deliver($device, BASE . '/V/BE', '#FF6300');
check('BE bleibt erhalten', $device->GetValue('RAW_BE'), '#FF6300');

// Wer sie ausdruecklich will, bekommt sie.
$device = makeDevice(['RawRegisters' => true, 'RawSilentRegisters' => true]);
deliver($device, BASE . '/V/B5', '#FF');
check('Auf Wunsch doch erfasst', $device->GetValue('RAW_B5'), '#FF');

/* Das Abraeumen muss beim Uebernehmen greifen und nicht erst, wenn das Register wieder
   ankommt: Diese Geraete melden sich von sich aus selten, und ein Voll-Dump kostet
   Batterie. Genau daran ist die erste Fassung gescheitert. */
$device = makeDevice(['RawRegisters' => true]);
foreach (['B4', 'B5', 'BB', 'A7', 'AA', 'B1', 'A4'] as $reg) {
    $device->TEST_MaintainVariable('RAW_' . $reg, 'Alt', VARIABLETYPE_STRING, '', 100, true);
}
$device->ApplyChanges();
foreach (['B4', 'B5', 'BB', 'A7', 'AA', 'B1', 'A4'] as $reg) {
    checkTrue('Uebernehmen raeumt RAW_' . $reg . ' ab', !isset($device->variables['RAW_' . $reg]));
}

// Ohne Rohdatenerfassung darf gar nichts stehen bleiben.
$device = makeDevice(['RawRegisters' => false]);
foreach (['A5', 'AF', 'BD', 'BE'] as $reg) {
    $device->TEST_MaintainVariable('RAW_' . $reg, 'Alt', VARIABLETYPE_STRING, '', 100, true);
}
$device->ApplyChanges();
foreach (['A5', 'AF', 'BD', 'BE'] as $reg) {
    checkTrue('Abgeschaltet raeumt RAW_' . $reg . ' ab', !isset($device->variables['RAW_' . $reg]));
}

// Wo der Zweck bekannt ist, traegt der Rohwert einen Namen statt "Rohwert XY".
$device = makeDevice(['RawRegisters' => true]);
deliver($device, BASE . '/V/A5', '#0C0A');
check('A5 heisst nach seinem Zweck',
    $device->variables['RAW_A5']['caption'], 'Lüftungserkennung (unentschlüsselt)');
deliver($device, BASE . '/V/BE', '#FF6300');
check('Ohne bekannten Zweck bleibt es beim Rohwert',
    $device->variables['RAW_BE']['caption'], 'Rohwert BE');

/* S/AF bleibt das wichtigste Kommando ueberhaupt — nur was auf V/AF zurueckkommt, ist
   wertlos. Das eine darf das andere nicht mit abschalten. */
$device = makeDevice();
IPSTestState::$sentPackets = [];
$device->RequestUpdate();
check('S/AF wird weiterhin gesendet', IPSTestState::$sentPackets[0]['Topic'], BASE . '/S/AF');


/* ============================================ Nachfassen nach Verbindungsabbruch */

/* Der Last Will sagt nur, dass eine Sitzung endete. Er kommt auch bei einer Wiederanmeldung
   desselben Geraets und gesammelt bei einem Broker-Aussetzer. Ohne Nachfassen bliebe
   "nicht erreichbar" fuer immer stehen — genau das ist an zehn Geraeten passiert. */

$device = makeDevice(['ProbeAfterLoss' => true]);
deliver($device, BASE . '/V/A1', '#2C');
checkTrue('Vor dem Abbruch erreichbar', $device->GetValue('Reachable'));
check('Vor dem Abbruch ist nichts eingeplant', $device->timers['CWIFI_Probe']['interval'], 0);

deliver($device, BASE . '/V/XX', '#COMM-LOSS');
checkTrue('Abbruch setzt nicht erreichbar', $device->GetValue('Reachable') === false);
check('Abbruch meldet Status 204', $device->GetStatus(), 204);

/* Der eigentliche Punkt: Der Abbruch muss die Nachfrage EINPLANEN. Ein Test, der nur
   ProbeAfterLoss() von Hand aufruft, merkt nicht, wenn das nie jemand tut. */
checkTrue('Abbruch plant die Nachfrage ein', $device->timers['CWIFI_Probe']['interval'] > 0);
// Versatz aus der MAC, damit ein Sammelabbruch nicht zehn Geräte gleichzeitig weckt.
checkTrue('Nachfrage liegt im erwarteten Zeitfenster',
    $device->timers['CWIFI_Probe']['interval'] >= 90000
    && $device->timers['CWIFI_Probe']['interval'] < 150000);

IPSTestState::$sentPackets = [];
$device->ProbeAfterLoss();
check('Nachfassen entwaffnet seinen Zeitgeber', $device->timers['CWIFI_Probe']['interval'], 0);
check('Nachfassen sendet genau eine Anfrage', count(IPSTestState::$sentPackets), 1);
check('Nachfassen geht auf S/AF', IPSTestState::$sentPackets[0]['Topic'], BASE . '/S/AF');
// Nur die Temperaturen — ein voller Dump waere hier der teuerste Weg zur selben Auskunft.
check('Nachfassen fragt nur die Temperaturen', IPSTestState::$sentPackets[0]['Payload'], '#0B');

/* Hat sich das Geraet in der Zwischenzeit selbst gemeldet, bleibt es schlafen. */
$device = makeDevice(['ProbeAfterLoss' => true]);
deliver($device, BASE . '/V/XX', '#COMM-LOSS');
deliver($device, BASE . '/V/A1', '#2C');
IPSTestState::$sentPackets = [];
$device->ProbeAfterLoss();
check('Wieder erreichbar: kein unnoetiges Wecken', count(IPSTestState::$sentPackets), 0);

/* Abgeschaltet heisst abgeschaltet — dann bleibt der Zustand eben stehen. */
$device = makeDevice(['ProbeAfterLoss' => false]);
deliver($device, BASE . '/V/A1', '#2C');
IPSTestState::$sentPackets = [];
deliver($device, BASE . '/V/XX', '#COMM-LOSS');
check('Abgeschaltet plant nichts ein', $device->timers['CWIFI_Probe']['interval'], 0);
check('Abgeschaltet sendet auch nichts', count(IPSTestState::$sentPackets), 0);

/* ------------------------------------------------------------------ Ergebnis */






echo "\n";
if ($failed === 0) {
    echo "✅  Alle {$passed} Prüfungen bestanden.\n";
    exit(0);
}
echo "❌  {$failed} von " . ($passed + $failed) . " Prüfungen fehlgeschlagen.\n";
exit(1);
