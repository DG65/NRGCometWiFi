<?php

declare(strict_types=1);

/**
 * Prüfstand für die reinen Hilfsbibliotheken (ohne IP-Symcon).
 *
 *   php .tools/test-libs.php     # 0 = alle Prüfungen bestanden
 *
 * Deckt genau die Stellen ab, an denen ein Fehler still bleibt: MAC-Normalisierung
 * (führt sonst zu Nicht-Treffern beim Instanz-Abgleich), Hex-Dekodierung (falsche
 * Temperatur ohne Fehlermeldung) und der Empfangsfilter (kein Datenempfang ohne Hinweis).
 */

// IPS-Konstanten, die die Bibliotheken referenzieren.
define('VARIABLETYPE_BOOLEAN', 0);
define('VARIABLETYPE_INTEGER', 1);
define('VARIABLETYPE_FLOAT', 2);
define('VARIABLETYPE_STRING', 3);

require_once __DIR__ . '/../.libs/CWIFI_Topics.php';
require_once __DIR__ . '/../.libs/CWIFI_Registers.php';

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

/* ------------------------------------------------------- 1. MAC-Normalisierung */

check('MAC bereits kanonisch', CWIFI_Topics::normalizeMac('A1B2C3D4E5F6'), 'A1B2C3D4E5F6');
check('MAC klein geschrieben', CWIFI_Topics::normalizeMac('a1b2c3d4e5f6'), 'A1B2C3D4E5F6');
check('MAC mit Doppelpunkten', CWIFI_Topics::normalizeMac('A1:B2:C3:D4:E5:F6'), 'A1B2C3D4E5F6');
check('MAC mit Bindestrichen', CWIFI_Topics::normalizeMac('a1-b2-c3-d4-e5-f6'), 'A1B2C3D4E5F6');
check('MAC mit Leerzeichen', CWIFI_Topics::normalizeMac(' A1B2C3 D4E5F6 '), 'A1B2C3D4E5F6');
check('MAC zu kurz', CWIFI_Topics::normalizeMac('A1B2C3D4E5'), '');
check('MAC zu lang', CWIFI_Topics::normalizeMac('A1B2C3D4E5F6FF'), '');
check('MAC mit ungültigem Zeichen', CWIFI_Topics::normalizeMac('A1B2C3D4E59Z'), '');
check('MAC leer', CWIFI_Topics::normalizeMac(''), '');
check('MAC formatiert', CWIFI_Topics::formatMac('a1b2c3d4e5f6'), 'A1:B2:C3:D4:E5:F6');
check('MAC formatiert bei Unsinn', CWIFI_Topics::formatMac('xyz'), '');

/* -------------------------------------------------------------- 2. Topic-Bau */

$prefix = '02';
$user   = 'AABBCCDD';
$mac    = 'A1B2C3D4E5F6';

check(
    'Basis-Topic',
    CWIFI_Topics::base($prefix, $user, $mac),
    '02/AABBCCDD/A1B2C3D4E5F6'
);
check(
    'Basis-Topic normalisiert Kleinschreibung',
    CWIFI_Topics::base($prefix, $user, 'a1:b2:c3:d4:e5:f6'),
    '02/AABBCCDD/A1B2C3D4E5F6'
);
check(
    'Value-Topic',
    CWIFI_Topics::value($prefix, $user, $mac, 'a0'),
    '02/AABBCCDD/A1B2C3D4E5F6/V/A0'
);
check(
    'Set-Topic',
    CWIFI_Topics::set($prefix, $user, $mac, 'A0'),
    '02/AABBCCDD/A1B2C3D4E5F6/S/A0'
);
check(
    'Zeitsync-Topic',
    CWIFI_Topics::timeSync($prefix),
    '02/FFFFFFFF/000000000004/T/B7'
);
check(
    'Client-ID (live gegengeprüft)',
    CWIFI_Topics::clientId($user, $mac),
    'da16x02AABBCCDDD4E5F6'
);
check(
    'Client-ID bei ungültiger MAC',
    CWIFI_Topics::clientId($user, 'xyz'),
    ''
);

/* ---------------------------------------------------------- 3. Topic-Zerlegung */

$base = CWIFI_Topics::base($prefix, $user, $mac);

check('Zerlegen V/A0', CWIFI_Topics::split($base . '/V/A0', $base), ['V', 'A0']);
check('Zerlegen S/AF', CWIFI_Topics::split($base . '/S/AF', $base), ['S', 'AF']);
check('Zerlegen G/B5', CWIFI_Topics::split($base . '/G/B5', $base), ['G', 'B5']);
check('Zerlegen normalisiert Kleinschreibung', CWIFI_Topics::split($base . '/v/a0', $base), ['V', 'A0']);
check('Zerlegen: fremdes Gerät', CWIFI_Topics::split('02/AABBCCDD/AABBCCDDEEFF/V/A0', $base), null);
check('Zerlegen: zu wenige Segmente', CWIFI_Topics::split($base . '/V', $base), null);
check('Zerlegen: zu viele Segmente', CWIFI_Topics::split($base . '/V/A0/X', $base), null);
// Präfixkollision: eine MAC, die mit der unseren beginnt, darf nicht zutreffen.
check('Zerlegen: Präfixkollision', CWIFI_Topics::split('02/AABBCCDD/A1B2C3D4E5F6FF/V/A0', $base), null);

/* ------------------------------------------------ 4. MAC aus Topic (Konfigurator) */

check(
    'MAC aus Topic',
    CWIFI_Topics::macFromTopic('02/AABBCCDD/A1B2C3D4E5AA/V/A1', $prefix, $user),
    'A1B2C3D4E5AA'
);
check(
    'MAC aus Topic: falscher Benutzer',
    CWIFI_Topics::macFromTopic('02/FFFFFFFF/A1B2C3D4E5AA/S/AF', $prefix, $user),
    ''
);
check(
    'MAC aus Topic: ohne Richtung',
    CWIFI_Topics::macFromTopic('02/AABBCCDD/A1B2C3D4E5AA', $prefix, $user),
    ''
);

/* -------------------------------------------------------- 5. Empfangsfilter */

// Entscheidend: Der Filter muss unter BEIDEN JSON-Schreibweisen greifen. Ob Symcon die
// Schrägstriche im Datenpaket escaped, ist nicht zugesichert — eine Filterfassung mit
// Schrägstrichen traf am Zielsystem nie zu, und die Instanz empfing schlicht nichts,
// ohne jede Fehlermeldung.
$plain   = json_encode(['Topic' => $base . '/V/A0', 'Payload' => '#2C'], JSON_UNESCAPED_SLASHES);
$escaped = json_encode(['Topic' => $base . '/V/A0', 'Payload' => '#2C']);   // mit \/
$foreign = json_encode(['Topic' => '02/AABBCCDD/AABBCCDDEEFF/V/A0', 'Payload' => '#2C']);

$filter = CWIFI_Topics::receiveFilter($mac);
check('Gerätefilter trifft bei escapten Schrägstrichen', preg_match('~' . $filter . '~', $escaped), 1);
check('Gerätefilter trifft bei nackten Schrägstrichen', preg_match('~' . $filter . '~', $plain), 1);
check('Gerätefilter trifft fremdes Gerät nicht', preg_match('~' . $filter . '~', $foreign), 0);
check('Filter enthält keinen Schrägstrich', strpos($filter, '/'), false);
check('Blockierfilter trifft nie', preg_match('~' . CWIFI_Topics::blockingFilter() . '~', $escaped), 0);

// Konfigurator filtert auf die Kontokennung und sieht damit alle Geräte des Kontos.
$cfgFilter = CWIFI_Topics::receiveFilter($user);
check('Konfiguratorfilter trifft Gerät A (escaped)', preg_match('~' . $cfgFilter . '~', $escaped), 1);
check('Konfiguratorfilter trifft Gerät A (nackt)', preg_match('~' . $cfgFilter . '~', $plain), 1);
check('Konfiguratorfilter trifft Gerät B', preg_match('~' . $cfgFilter . '~', $foreign), 1);

/* ------------------------------------------------------- 6. Temperatur-Dekodierung */

$tempDef = CWIFI_Registers::byRegister('A1');
check('A1 ist bekannt', $tempDef !== null, true);
check('Temperatur #2C = 22.0', CWIFI_Registers::decode($tempDef, '#2C'), 22.0);
check('Temperatur #39 = 28.5 (live gesehen)', CWIFI_Registers::decode($tempDef, '#39'), 28.5);
check('Temperatur #0A = 5.0', CWIFI_Registers::decode($tempDef, '#0A'), 5.0);
check('Temperatur klein geschrieben #2c', CWIFI_Registers::decode($tempDef, '#2c'), 22.0);
check('Temperatur ohne Raute', CWIFI_Registers::decode($tempDef, '2C'), null);
check('Temperatur mit Unsinn', CWIFI_Registers::decode($tempDef, '#ZZ'), null);
check('Temperatur leer', CWIFI_Registers::decode($tempDef, ''), null);

/* ----------------------------------------------------- 7. Signalstärke (dezimal!) */

$rssiDef = CWIFI_Registers::byRegister('B3');
check('RSSI #-45 = -45 (live gesehen)', CWIFI_Registers::decode($rssiDef, '#-45'), -45);
check('RSSI #-80', CWIFI_Registers::decode($rssiDef, '#-80'), -80);
// Der entscheidende Unterschied zur Temperatur: NICHT als Hex lesen.
check('RSSI wird nicht hex gelesen', CWIFI_Registers::decode($rssiDef, '#45'), 45);
check('RSSI mit Hex-Buchstaben ungültig', CWIFI_Registers::decode($rssiDef, '#4A'), null);

/* -------------------------------------------------------- 8. Batterie (Kodierung offen) */

$battDef = CWIFI_Registers::byRegister('A6');
check('Batterie hex #64 = 100', CWIFI_Registers::decode($battDef, '#64', CWIFI_Registers::BATTERY_HEX), 100);
check('Batterie dezimal #64 = 64', CWIFI_Registers::decode($battDef, '#64', CWIFI_Registers::BATTERY_DECIMAL), 64);
check('Batterie hex über 100 wird geklemmt', CWIFI_Registers::decode($battDef, '#FF', CWIFI_Registers::BATTERY_HEX), 100);
check('Batterie dezimal mit Hex-Ziffer ungültig', CWIFI_Registers::decode($battDef, '#6A', CWIFI_Registers::BATTERY_DECIMAL), null);

// Beweisführung: A–F schließt Dezimal aus, Ziffern allein beweisen nichts.
check('#6A beweist Hex', CWIFI_Registers::provesHexBattery('#6A'), true);
check('#FF beweist Hex', CWIFI_Registers::provesHexBattery('#FF'), true);
check('#64 beweist nichts', CWIFI_Registers::provesHexBattery('#64'), false);
check('#100 beweist nichts', CWIFI_Registers::provesHexBattery('#100'), false);

/* ------------------------------------------------------ 9. Sollwert-Kodierung */

check('Sollwert 22.0', CWIFI_Registers::encodeSetpoint(22.0, 5.0, 30.0), ['#2C', 22.0]);
check('Sollwert 28.5 (live gesehen)', CWIFI_Registers::encodeSetpoint(28.5, 5.0, 30.0), ['#39', 28.5]);
check('Sollwert 5.0 = Untergrenze', CWIFI_Registers::encodeSetpoint(5.0, 5.0, 30.0), ['#0A', 5.0]);
check('Sollwert unter Minimum wird geklemmt', CWIFI_Registers::encodeSetpoint(2.0, 5.0, 30.0), ['#0A', 5.0]);
check('Sollwert über Maximum wird geklemmt', CWIFI_Registers::encodeSetpoint(99.0, 5.0, 30.0), ['#3C', 30.0]);
// Halbgrad-Raster: alles dazwischen wird gerundet, nicht abgeschnitten.
check('Sollwert 21.3 rundet auf 21.5', CWIFI_Registers::encodeSetpoint(21.3, 5.0, 30.0), ['#2B', 21.5]);
check('Sollwert 21.2 rundet auf 21.0', CWIFI_Registers::encodeSetpoint(21.2, 5.0, 30.0), ['#2A', 21.0]);
// Zweistellig mit führender Null — sonst wäre '#A' statt '#0A' möglich.
check('Sollwert immer zweistellig', CWIFI_Registers::encodeSetpoint(5.0, 0.0, 30.0)[0], '#0A');

/* ------------------------------------------ 10. Rundlauf Kodieren → Dekodieren */

$setDef = CWIFI_Registers::byRegister('A0');
for ($half = 10; $half <= 60; $half++) {
    // Ausdrücklich float — sonst liefert PHPs '/' bei geraden Halbschritten ein int
    // und der Vergleich scheiterte an nichts als dem Typ.
    $celsius = (float) ($half / 2);
    [$payload, $applied] = CWIFI_Registers::encodeSetpoint($celsius, 5.0, 30.0);
    $decoded = CWIFI_Registers::decode($setDef, $payload);
    if ($decoded !== $celsius || $applied !== $celsius) {
        check("Rundlauf {$celsius} °C", [$decoded, $applied], [$celsius, $celsius]);
    } else {
        $passed++;
    }
}

/* ------------------------------------------------------------- 11. Sonstiges */

check('Register für Ident Setpoint', CWIFI_Registers::registerForIdent('Setpoint'), 'A0');
check('Register für unbekannten Ident', CWIFI_Registers::registerForIdent('Quatsch'), null);
check('Undekodiertes Register A3 fehlt bewusst', CWIFI_Registers::byRegister('A3'), null);
check('Undekodiertes Register A5 fehlt bewusst', CWIFI_Registers::byRegister('A5'), null);
check('Undekodiertes Register BD fehlt bewusst', CWIFI_Registers::byRegister('BD'), null);
check('Plausibilität: normaler Payload', CWIFI_Registers::isPlausible('#2C'), true);
check('Plausibilität: Textmarke', CWIFI_Registers::isPlausible('#COMM-LOSS'), true);
check('Plausibilität: Zeitstempel', CWIFI_Registers::isPlausible('#23.03.09-09:00'), true);
check('Plausibilität: ohne Raute', CWIFI_Registers::isPlausible('2C'), false);
check('Plausibilität: leer', CWIFI_Registers::isPlausible(''), false);
check('Zeitstempel-Kodierung', CWIFI_Registers::encodeTimestamp(1078822800), '#04.03.09-09:00');

/* ------------------------------------------------------------------ Ergebnis */

echo "\n";
if ($failed === 0) {
    echo "✅  Alle {$passed} Prüfungen bestanden.\n";
    exit(0);
}
echo "❌  {$failed} von " . ($passed + $failed) . " Prüfungen fehlgeschlagen.\n";
exit(1);
