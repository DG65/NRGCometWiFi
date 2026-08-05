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

/* ------------------------------------------------ 12. Feldauswahl fuer S/AF */

// Bit n = Register A(n). Am Geraet und gegen die Foren-Angabe geprueft.
check('Feldauswahl A0', CWIFI_Registers::requestFields('A0'), '#01000000');
check('Feldauswahl A1', CWIFI_Registers::requestFields('A1'), '#02000000');
check('Feldauswahl A3', CWIFI_Registers::requestFields('A3'), '#08000000');
check('Feldauswahl A6', CWIFI_Registers::requestFields('A6'), '#40000000');
// Das aus den Foren als "Batterie, Tastensperre, Sommerzeit, Rotation" beschriebene
// #48000000 ist genau A6 plus A3 - unabhaengige Bestaetigung der Deutung.
check('Feldauswahl A3+A6 ergibt #48000000', CWIFI_Registers::requestFields('A6', 'A3'), '#48000000');
check('Feldauswahl A0+A1', CWIFI_Registers::requestFields('A0', 'A1'), '#03000000');
check('Feldauswahl ohne Register', CWIFI_Registers::requestFields(), '#00000000');

/* ------------------------------------------ 13. Optionen-Bitfeld (A3) */

// Alle Werte stammen aus dem Mitschnitt der Hersteller-App, jeweils in beide Richtungen.
check('Optionen lesen #2182', CWIFI_Registers::decodeOptions('#2182'), 0x21);
check('Optionen lesen #0182', CWIFI_Registers::decodeOptions('#0182'), 0x01);
check('Optionen lesen #2582', CWIFI_Registers::decodeOptions('#2582'), 0x25);
check('Optionen lesen Unsinn', CWIFI_Registers::decodeOptions('#ZZ'), null);

// Genau diese Befehle hat die App gesendet.
check('Zeitplan aus (Handbetrieb ein)',
    CWIFI_Registers::encodeOptionSwitch(CWIFI_Registers::OPT_MANUAL, true), '#2000000000');
check('Zeitplan ein (Handbetrieb aus)',
    CWIFI_Registers::encodeOptionSwitch(CWIFI_Registers::OPT_MANUAL, false), '#0020000000');
check('Anzeige drehen ein',
    CWIFI_Registers::encodeOptionSwitch(CWIFI_Registers::OPT_ROTATE, true), '#0200000000');
check('Anzeige drehen aus',
    CWIFI_Registers::encodeOptionSwitch(CWIFI_Registers::OPT_ROTATE, false), '#0002000000');
check('Sommerzeit ein',
    CWIFI_Registers::encodeOptionSwitch(CWIFI_Registers::OPT_DST, true), '#0100000000');
check('Sommerzeit aus',
    CWIFI_Registers::encodeOptionSwitch(CWIFI_Registers::OPT_DST, false), '#0001000000');

// Dreistufige Sperre: das jeweils andere Bit wird ausdruecklich geloescht.
check('Tastensperre ein', CWIFI_Registers::encodeKeyLock(CWIFI_Registers::LOCK_ON), '#0408000000');
check('Tastensperre plus', CWIFI_Registers::encodeKeyLock(CWIFI_Registers::LOCK_PLUS), '#0804000000');
check('Tastensperre aus', CWIFI_Registers::encodeKeyLock(CWIFI_Registers::LOCK_OFF), '#000C000000');

check('Stufe aus 0x21', CWIFI_Registers::keyLockLevel(0x21), CWIFI_Registers::LOCK_OFF);
check('Stufe aus 0x25', CWIFI_Registers::keyLockLevel(0x25), CWIFI_Registers::LOCK_ON);
check('Stufe aus 0x29', CWIFI_Registers::keyLockLevel(0x29), CWIFI_Registers::LOCK_PLUS);

/* ------------------------------------------------- 14. Temperatur-Offset (A2) */

// Die App sendete fuer +1,0 K genau #02 und fuers Zuruecksetzen #00.
check('Offset +1,0 K', CWIFI_Registers::encodeOffset(1.0), '#02');
check('Offset 0', CWIFI_Registers::encodeOffset(0.0), '#00');
check('Offset +2,5 K', CWIFI_Registers::encodeOffset(2.5), '#05');
check('Offset lesen #02', CWIFI_Registers::decodeOffset('#02'), 1.0);
check('Offset lesen #00', CWIFI_Registers::decodeOffset('#00'), 0.0);
// Negative Werte sind bislang NICHT am Geraet geprueft - die Rechnung wird trotzdem
// festgehalten, damit eine spaetere Bestaetigung nur noch das Vorzeichen belegen muss.
check('Offset -1,0 K (unbestaetigt)', CWIFI_Registers::encodeOffset(-1.0), '#FE');
check('Offset lesen #FE (unbestaetigt)', CWIFI_Registers::decodeOffset('#FE'), -1.0);

/* --------------------------------------------- 15. Sollwertskala mit Endanschlaegen */

// Unterhalb 8,0 rastet das Geraet auf "Aus", oberhalb 28,0 auf "An" - beides gueltige
// Sollwerte, die wie Temperaturen kodiert werden. Am Geraet belegt.
check('Aus ist 7,5', CWIFI_Registers::SETPOINT_OFF, 7.5);
check('An ist 28,5', CWIFI_Registers::SETPOINT_ON, 28.5);
check('Aus kodiert zu #0F', CWIFI_Registers::encodeSetpoint(7.5, 7.5, 28.5)[0], '#0F');
check('An kodiert zu #39', CWIFI_Registers::encodeSetpoint(28.5, 7.5, 28.5)[0], '#39');
check('8,0 Grad kodiert zu #10', CWIFI_Registers::encodeSetpoint(8.0, 7.5, 28.5)[0], '#10');
check('28,0 Grad kodiert zu #38', CWIFI_Registers::encodeSetpoint(28.0, 7.5, 28.5)[0], '#38');
check('7,5 ist Endanschlag', CWIFI_Registers::isEndstop(7.5), true);
check('28,5 ist Endanschlag', CWIFI_Registers::isEndstop(28.5), true);
check('20,0 ist kein Endanschlag', CWIFI_Registers::isEndstop(20.0), false);

/* ---------------------------------------------------- 16. Urlaub (A7) */

// Der Payload stammt aus dem Mitschnitt, die Sollwerte aus dem App-Screenshot:
// "Beginn 31.7.2026 12:00, Ende 16.8.2026 12:00, 25,0 Grad".
$h = CWIFI_Registers::decodeHoliday('#0C1F071A0C10081A32');
check('Urlaub wird gelesen', is_array($h), true);
check('Urlaubsbeginn', date('d.m.Y H:i', $h['start']), '31.07.2026 12:00');
check('Urlaubsende', date('d.m.Y H:i', $h['end']), '16.08.2026 12:00');
check('Urlaubstemperatur', $h['temperature'], 25.0);

// Neun Byte FF heisst ausdruecklich "kein Urlaub" - so stand es im Voll-Dump.
check('Kein Urlaub gesetzt', CWIFI_Registers::decodeHoliday('#FFFFFFFFFFFFFFFFFF'), null);
check('Kein-Urlaub kodieren', CWIFI_Registers::encodeNoHoliday(), '#FFFFFFFFFFFFFFFFFF');
check('Urlaub falsche Laenge', CWIFI_Registers::decodeHoliday('#0C1F07'), null);

// Rundlauf gegen genau den Payload, den die App gesendet hat.
check('Urlaub kodieren ergibt den App-Payload',
    CWIFI_Registers::encodeHoliday(mktime(12,0,0,7,31,2026), mktime(12,0,0,8,16,2026), 25.0),
    '#0C1F071A0C10081A32');

/* ------------------------------------------- 17. Wochenprogramm (A8-AE) */

// Payload aus dem Voll-Dump, Sollwerte aus dem App-Screenshot des Zeitplans.
$mo = CWIFI_Registers::decodeSchedule('#062C09E4176C1FA4', 0);
check('Montag hat vier Schaltpunkte', count($mo), 4);
check('Montag 1. Schaltpunkt', $mo[0]['time'] . '/' . $mo[0]['temperature'], '04:00/22');
check('Montag 2. Schaltpunkt', $mo[1]['time'] . '/' . $mo[1]['temperature'], '06:30/18');
check('Montag 3. Schaltpunkt', $mo[2]['time'] . '/' . $mo[2]['temperature'], '15:30/22');
check('Montag 4. Schaltpunkt', $mo[3]['time'] . '/' . $mo[3]['temperature'], '21:00/18');

// Samstag und Sonntag haben nur zwei Schaltpunkte - daher die kuerzeren Register.
$sa = CWIFI_Registers::decodeSchedule('#BEACD524', 5);
check('Samstag hat zwei Schaltpunkte', count($sa), 2);
check('Samstag 1. Schaltpunkt', $sa[0]['time'] . '/' . $sa[0]['temperature'], '07:00/22');
check('Samstag 2. Schaltpunkt', $sa[1]['time'] . '/' . $sa[1]['temperature'], '22:00/18');

$so = CWIFI_Registers::decodeSchedule('#E2ACF924', 6);
check('Sonntag 1. Schaltpunkt', $so[0]['time'] . '/' . $so[0]['temperature'], '07:00/22');

// Die Zeit zaehlt ueber die ganze Woche durch. Ein Payload am falschen Tag ist deshalb ein
// Zeichen dafuer, dass die Deutung nicht stimmt - dann lieber nichts anzeigen als Unsinn.
check('Montags-Payload als Dienstag gelesen wird verworfen',
    CWIFI_Registers::decodeSchedule('#062C09E4176C1FA4', 1), null);
check('Ungerade Laenge wird verworfen', CWIFI_Registers::decodeSchedule('#062C0', 0), null);

check('Sieben Register fuer sieben Tage', count(CWIFI_Registers::SCHEDULE_REGISTERS), 7);
check('Montag ist A8', CWIFI_Registers::SCHEDULE_REGISTERS[0], 'A8');
check('Sonntag ist AE', CWIFI_Registers::SCHEDULE_REGISTERS[6], 'AE');

check('Text fuer einen Tag', CWIFI_Registers::scheduleToText($sa), '07:00 → 22,0 °C · 22:00 → 18,0 °C');
check('Text ohne Daten', CWIFI_Registers::scheduleToText(null), '–');


/* ==================================================== Geräteauskunft B0–BF
 *
 * Alle Beispiele stammen aus echten Voll-Dumps (siehe .docs/protokoll.md), nicht aus
 * konstruierten Zeichenketten — sonst prüft der Stand nur die eigene Erwartung.
 */

check('B1 ergibt das Modell',
    CWIFI_Registers::decodeInfo('B1', '#436F6D65742057696669205665722E20362E31'), 'Comet Wifi Ver. 6.1');
check('B2 ergibt die Firmware',
    CWIFI_Registers::decodeInfo('B2', '#322E372E312E30'), '2.7.1.0');
check('B6 ergibt die IP',
    CWIFI_Registers::decodeInfo('B6', '#00C0A8022D01445F0301'), '192.168.2.45');
check('B6 zweites Geraet, andere IP',
    CWIFI_Registers::decodeInfo('B6', '#00C0A8022A01445F0301'), '192.168.2.42');
check('BA ergibt den Zugangspunkt',
    CWIFI_Registers::decodeInfo('BA', '#24:f5:a2:74:7b:ab'), '24:f5:a2:74:7b:ab');
check('BF ergibt die Verschluesselung',
    CWIFI_Registers::decodeInfo('BF', '#5B575041322D50534B2D43434D505D5B4553535D'), '[WPA2-PSK-CCMP][ESS]');
check('B0 ohne Gruppe',
    CWIFI_Registers::decodeInfo('B0', '#U000000000000'), 'Einzelgerät');
check('B0 mit Gruppenkopf',
    CWIFI_Registers::decodeInfo('B0', '#SD43D395E3C2E'), 'Gruppe D4:3D:39:5E:3C:2E');
// 'S' mit lauter Nullen ist keine Gruppe, sondern dieselbe Aussage wie 'U'.
check('B0 mit S aber ohne MAC gilt als Einzelgeraet',
    CWIFI_Registers::decodeInfo('B0', '#S000000000000'), 'Einzelgerät');

/* Unlesbares muss null ergeben und darf nicht als Buchstabensalat durchgehen —
   sonst stuende in der Instanz eine Auskunft, die keine ist. */
check('Binaerer Inhalt ergibt keine Auskunft', CWIFI_Registers::decodeInfo('B1', '#0102'), null);
check('Kein Hex ergibt keine Auskunft',        CWIFI_Registers::decodeInfo('B2', '#XYZ'), null);
check('Ungerade Laenge ergibt keine Auskunft', CWIFI_Registers::decodeInfo('B1', '#41424'), null);
check('Zu kurzes B6 ergibt keine Auskunft',    CWIFI_Registers::decodeInfo('B6', '#00C0'), null);
check('Leerer Inhalt ergibt keine Auskunft',   CWIFI_Registers::decodeInfo('B1', '#'), null);
check('Unbekanntes Register ergibt keine Auskunft',
    CWIFI_Registers::decodeInfo('A4', '#2112010114'), null);

// Jedes Register der Tabelle muss einen Ident und einen Quellstring haben, sonst legt das
// Modul eine Variable ohne Namen an.
foreach (CWIFI_Registers::INFO_REGISTERS as $reg => $eintrag) {
    check('INFO_REGISTERS ' . $reg . ' vollstaendig',
        count($eintrag) === 2 && $eintrag[0] !== '' && $eintrag[1] !== '', true);
}


/* ==================================================== Geräteuhr A4 ✅
 *
 * Belegt durch zwei Messungen desselben Geräts: 17:51:42 -> #2112010114 (18:33),
 * 21:11:39 -> #3515010114 (21:53). Verstrichen 3 h 19 min 57 s, Zunahme 3 h 20 min.
 */

$bezug = mktime(21, 11, 39, 8, 5, 2026);
$uhr   = CWIFI_Registers::decodeClock('#3515010114', $bezug);
check('A4 Stunde',     $uhr['hour'],   21);
check('A4 Minute',     $uhr['minute'], 53);
check('A4 Abweichung', $uhr['deviation'], 42);

$bezug = mktime(17, 51, 42, 8, 5, 2026);
$uhr   = CWIFI_Registers::decodeClock('#2112010114', $bezug);
check('A4 frueherer Messwert, Stunde', $uhr['hour'],   18);
check('A4 frueherer Messwert, Minute', $uhr['minute'], 33);
// Dieselbe Uhr, dieselbe Abweichung — das ist der eigentliche Nachweis.
check('A4 Abweichung bleibt ueber Stunden gleich', $uhr['deviation'], 42);

// Kind 2 laeuft um mehr als neun Stunden vor.
$bezug = mktime(21, 10, 0, 8, 5, 2026);
check('A4 grosse Abweichung', CWIFI_Registers::decodeClock('#2206010114', $bezug)['deviation'], 564);

/* Ueber Mitternacht muss der kuerzere Weg gewaehlt werden: Eine Uhr, die 23:58 zeigt,
   waehrend es 00:02 ist, geht vier Minuten nach — nicht 23 Stunden 56 Minuten vor. */
$bezug = mktime(0, 2, 0, 8, 5, 2026);
check('A4 Mitternacht rueckwaerts', CWIFI_Registers::decodeClock('#3A17010114', $bezug)['deviation'], -4);
$bezug = mktime(23, 58, 0, 8, 5, 2026);
check('A4 Mitternacht vorwaerts',   CWIFI_Registers::decodeClock('#0200010114', $bezug)['deviation'], 4);

// Was keine Uhrzeit sein kann, darf nicht als eine durchgehen.
check('A4 Stunde 24 ist keine Uhrzeit',  CWIFI_Registers::decodeClock('#0018010114'), null);
check('A4 Minute 60 ist keine Uhrzeit',  CWIFI_Registers::decodeClock('#3C12010114'), null);
check('A4 zu kurz',                      CWIFI_Registers::decodeClock('#21'), null);
check('A4 kein Hex',                     CWIFI_Registers::decodeClock('#ZZ12010114'), null);


/* ------------------------------------------------- Uhr stellen (Gegenstueck) */

// Reihenfolge wie bei A7: Minute, Stunde, Tag, Monat, Jahr.
check('encodeClock baut den Payload',
    CWIFI_Registers::encodeClock(mktime(21, 53, 0, 8, 5, 2026)), '#351505081A');
// mktime(Stunde, Minute, Sekunde, Monat, Tag, Jahr) — hier der 2. Januar 2026, 03:07.
check('encodeClock einstellige Werte gepolstert',
    CWIFI_Registers::encodeClock(mktime(3, 7, 0, 1, 2, 2026)), '#070302011A');
check('encodeClock Mitternacht',
    CWIFI_Registers::encodeClock(mktime(0, 0, 0, 12, 31, 2026)), '#00001F0C1A');

/* Kodieren und wieder lesen muss dieselbe Uhrzeit ergeben — sonst stimmt eine der beiden
   Richtungen nicht, und welche, sieht man erst am Geraet. */
foreach ([[0,0],[7,3],[12,34],[21,53],[23,59]] as [$std, $min]) {
    $t = mktime($std, $min, 0, 8, 5, 2026);
    $zurueck = CWIFI_Registers::decodeClock(CWIFI_Registers::encodeClock($t), $t);
    check(sprintf('Rundreise %02d:%02d Stunde', $std, $min), $zurueck['hour'], $std);
    check(sprintf('Rundreise %02d:%02d Minute', $std, $min), $zurueck['minute'], $min);
    check(sprintf('Rundreise %02d:%02d ohne Abweichung', $std, $min), $zurueck['deviation'], 0);
}

/* ------------------------------------------------------------------ Ergebnis */




echo "\n";
if ($failed === 0) {
    echo "✅  Alle {$passed} Prüfungen bestanden.\n";
    exit(0);
}
echo "❌  {$failed} von " . ($passed + $failed) . " Prüfungen fehlgeschlagen.\n";
exit(1);
