<?php

declare(strict_types=1);

/**
 * Prüfstand für die Zweisprachigkeit (Deutsch/Englisch).
 *
 *   php .tools/test-sprachen.php     # 0 = alle Prüfungen bestanden
 *
 * Quelltexte stehen englisch im Code und in den form.json, die deutsche Fassung in
 * .libs/locale.json (php .tools/locale-bauen.php kopiert sie in alle Modulordner).
 *
 * Geprüft wird, was beim Übersetzen still schiefgeht: ein Text ohne deutschen Eintrag
 * (der Nutzer sähe Englisch), ein Eintrag, den niemand mehr braucht, ein Platzhalter, der
 * in einer Sprache fehlt (sprintf liefert dann Unsinn), deutsche Reste im englischen
 * Formular und Ausweichschreibweisen (ue/ae/oe) im Deutschen.
 */

require_once __DIR__ . '/ips-stub.php';
foreach (['Thermostat', 'Configurator', 'Tile', 'RoomTile', 'Room'] as $modul) {
    require_once __DIR__ . '/../CometWiFi' . $modul . '/module.php';
}

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

const MODULE = ['CometWiFiThermostat', 'CometWiFiConfigurator', 'CometWiFiTile', 'CometWiFiRoomTile', 'CometWiFiRoom'];
const WURZEL = __DIR__ . '/..';

/** Anzeigetexte aus einer form.json — die Konsole übersetzt diese Schlüssel selbst. */
function formTexte($knoten, array &$aus): void
{
    if (!is_array($knoten)) {
        return;
    }
    foreach ($knoten as $schluessel => $wert) {
        if (is_string($wert) && in_array($schluessel, ['caption', 'label', 'confirm', 'title'], true)) {
            if (preg_match('/\pL/u', $wert)) {
                $aus[$wert] = true;
            }
        } elseif (is_array($wert)) {
            formTexte($wert, $aus);
        }
    }
}

/** Wert eines PHP-Zeichenkettenliterals (einfach oder doppelt angeführt). */
function literal(string $roh): string
{
    $q = $roh[0];
    $inhalt = substr($roh, 1, -1);
    if ($q === "'") {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $inhalt);
    }
    return stripcslashes($inhalt);
}

/**
 * Sammelt aus einer PHP-Datei zweierlei: ALLE Zeichenkettenliterale und die Literale, die an
 * Translate()/$t() beziehungsweise als Textargument an die Meldungshelfer gehen.
 *
 * @return array{0:array<string,bool>,1:array<string,bool>}
 */
function phpTexte(string $datei): array
{
    $alle = [];
    $uebersetzt = [];
    $tokens = array_values(array_filter(
        token_get_all((string) file_get_contents($datei)),
        static fn ($t) => !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
    ));
    $anzahl = count($tokens);
    for ($i = 0; $i < $anzahl; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $alle[literal($t[1])] = true;
        }
        $name = null;
        if (is_array($t) && $t[0] === T_STRING && in_array($t[1], ['Translate', 'actionReport', 'memberReport'], true)) {
            $name = $t[1];
        } elseif (is_array($t) && $t[0] === T_VARIABLE && in_array($t[1], ['$t'], true)) {
            $name = 'Translate';
        }
        if ($name === null || ($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }
        // Funktionsdeklaration ("function Translate(") überspringen.
        if (is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_FUNCTION) {
            continue;
        }
        $tiefe = 0;
        $funde = [];
        for ($j = $i + 1; $j < $anzahl; $j++) {
            $u = $tokens[$j];
            if ($u === '(') {
                $tiefe++;
            } elseif ($u === ')') {
                $tiefe--;
                if ($tiefe === 0) {
                    break;
                }
            } elseif (is_array($u) && $u[0] === T_CONSTANT_ENCAPSED_STRING) {
                $funde[] = literal($u[1]);
            }
        }
        if ($name !== 'Translate') {
            // actionReport($ok, 'Text') / memberReport('Aktion', 'Text'): nur das letzte Literal.
            $funde = $funde === [] ? [] : [end($funde)];
        }
        foreach ($funde as $f) {
            $uebersetzt[$f] = true;
        }
    }
    return [$alle, $uebersetzt];
}

/** Platzhalter eines Textes als sortierte Liste. */
function platzhalter(string $text): array
{
    preg_match_all('/%(?:\d+\$)?[sdfu]|%%/', $text, $m);
    sort($m[0]);
    return $m[0];
}

/** Sieht der Text nach Deutsch aus? */
function klingtDeutsch(string $text): bool
{
    if (preg_match('/[äöüßÄÖÜ]/u', $text)) {
        return true;
    }
    return (bool) preg_match(
        '/\b(der|die|das|und|nicht|ist|wird|werden|mit|für|von|zum|zur|dem|den|des|eine|einen|auf|aus|wenn|oder|sich|nur|ohne|bei|noch|kein|keine|Gerät|Geräte|Einstellung|Einstellungen|Wochenprogramm|Urlaub|Batterie|Zeitplan|Handbetrieb|Thermostate|zugeordnet|angefordert|gesendet)\b/u',
        $text
    );
}

/* ------------------------------------------------------------------ Dateien */

$quelle = (string) file_get_contents(WURZEL . '/.libs/locale.json');
$tabelle = json_decode($quelle, true);
check('.libs/locale.json ist gültiges JSON', is_array($tabelle), true);
check('Es gibt nur die Sprache „de“ (Englisch sind die Quelltexte)', array_keys($tabelle['translations'] ?? []), ['de']);
$de = $tabelle['translations']['de'] ?? [];
check('Die Tabelle ist nicht leer', count($de) > 100, true);

foreach (MODULE as $modul) {
    check($modul . ': locale.json ist die gebaute Kopie (php .tools/locale-bauen.php)',
        (string) @file_get_contents(WURZEL . '/' . $modul . '/locale.json'), $quelle);
}

/* ----------------------------------------------------------- Texte einsammeln */

$formTexte = [];
foreach (MODULE as $modul) {
    $form = json_decode((string) file_get_contents(WURZEL . '/' . $modul . '/form.json'), true);
    check($modul . ': form.json ist gültiges JSON', is_array($form), true);
    formTexte($form, $formTexte);
}

$dateien = array_merge(
    glob(WURZEL . '/CometWiFi*/module.php'),
    glob(WURZEL . '/.libs/*.php')
);
$alleLiterale = [];
$uebersetzteLiterale = [];
foreach ($dateien as $datei) {
    [$alle, $uebersetzt] = phpTexte($datei);
    $alleLiterale += $alle;
    $uebersetzteLiterale += $uebersetzt;
}

// Dynamische Quellen, die über Konstanten statt Literale an Translate() gehen.
foreach (CWIFI_Registers::INFO_REGISTERS as [, $quellText]) {
    $uebersetzteLiterale[$quellText] = true;
}
foreach (CWIFI_Registers::NAMED_RAW as $quellText) {
    $uebersetzteLiterale[$quellText] = true;
}

/* ------------------------------------------------- Jeder Quelltext hat Deutsch */

foreach (array_keys($formTexte) as $text) {
    check('Formulartext hat Deutsch: ' . mb_substr($text, 0, 70), isset($de[$text]), true);
}
foreach (array_keys($uebersetzteLiterale) as $text) {
    if (!preg_match('/\pL/u', $text)) {
        continue;
    }
    check('Code-Text hat Deutsch: ' . mb_substr($text, 0, 70), isset($de[$text]), true);
}

/* ------------------------------------------------- Nichts Verwaistes in der Tabelle */

foreach (array_keys($de) as $schluessel) {
    $gebraucht = isset($formTexte[$schluessel]) || isset($alleLiterale[$schluessel]);
    check('Eintrag wird gebraucht: ' . mb_substr($schluessel, 0, 70), $gebraucht, true);
}

/* ------------------------------------------------------ Deutsch und Englisch passen */

// Ausdrücklich gleich in beiden Sprachen (Eigennamen, Fachwörter, Zeichen).
const GLEICH = ['Firmware', 'Offset', 'Plus', 'Thermostat', 'MAC', 'Signal', 'Minimum', 'Maximum', 'Model'];

/** Text ohne führendes Symbol: „🌡️  Thermostat“ -> „Thermostat“. */
function ohneSymbol(string $text): string
{
    return trim((string) preg_replace('/^[^\pL\pN]+/u', '', $text));
}

foreach ($de as $en => $deutsch) {
    check('Platzhalter gleich: ' . mb_substr($en, 0, 60), platzhalter($deutsch), platzhalter($en));
    check('Deutsch nicht leer: ' . mb_substr($en, 0, 60), trim($deutsch) !== '', true);
    if (!in_array(ohneSymbol($en), GLEICH, true) && preg_match('/\pL{3,}/u', $en)) {
        check('Deutsch weicht vom Englischen ab: ' . mb_substr($en, 0, 60), $deutsch !== $en, true);
    }
    check('Quelltext ist nicht deutsch: ' . mb_substr($en, 0, 60), klingtDeutsch($en), false);
    check('Keine Ausweichschreibweise im Deutschen: ' . mb_substr($deutsch, 0, 60),
        (bool) preg_match('/\b(fuer|ueber|Geraet\w*|koenn\w+|muess\w+|aender\w+|pruef\w+|waehl\w+|loesch\w+|zurueck\w*|Stoerung|natuerlich|Aenderung\w*)\b/iu', $deutsch),
        false);
    check('Kein ISO-Datum im Deutschen: ' . mb_substr($deutsch, 0, 60),
        (bool) preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $deutsch), false);
}
check('Dezimalzeichen: „.“ wird im Deutschen zu „,“', $de['.'] ?? null, ',');
check('Datumsformat: Deutsch ist TT.MM.JJJJ', $de['Y-m-d H:i'] ?? null, 'd.m.Y H:i');

/* ------------------------------------------- Kein deutscher Text mehr im Code */

foreach ($alleLiterale as $text => $_) {
    // Nur Texte, die wie Sätze oder Wörter aussehen; Kennungen und Pfade haben keine Leerzeichen
    // oder Umlaute. Ausgenommen sind die deutschen Schlüsselwörter im Funktionsnamen-Bereich.
    if (klingtDeutsch((string) $text) && !isset($de[$text])) {
        check('Kein deutscher Text im Code: ' . mb_substr((string) $text, 0, 70), true, false);
    }
}

/* ------------------------------------- Das Formular in beiden Sprachen erzeugen */

function formularVon(string $klasse): array
{
    IPSTestState::reset();
    IPSTestState::$instances[95] = [
        'InstanceID' => 95, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo' => ['ModuleID' => '{TEST}'], 'Name' => $klasse
    ];
    $instanz = new $klasse(95);
    $instanz->Create();
    return json_decode($instanz->GetConfigurationForm(), true) ?? [];
}

/** Simuliert die Übersetzung der Konsole: caption/label/confirm/title über die locale.json. */
function konsoleUebersetzt($knoten, array $de)
{
    if (!is_array($knoten)) {
        return $knoten;
    }
    foreach ($knoten as $schluessel => $wert) {
        if (is_string($wert) && in_array($schluessel, ['caption', 'label', 'confirm', 'title'], true)) {
            $knoten[$schluessel] = $de[$wert] ?? $wert;
        } elseif (is_array($wert)) {
            $knoten[$schluessel] = konsoleUebersetzt($wert, $de);
        }
    }
    return $knoten;
}

function sichtbareTexte($knoten, array &$aus): void
{
    if (!is_array($knoten)) {
        return;
    }
    foreach ($knoten as $schluessel => $wert) {
        if (is_string($wert) && in_array($schluessel, ['caption', 'label', 'confirm', 'title'], true)) {
            $aus[] = $wert;
        } elseif (is_array($wert)) {
            sichtbareTexte($wert, $aus);
        }
    }
}

foreach (MODULE as $modul) {
    IPSTestState::$locale = null;
    $englisch = formularVon($modul);
    $texte = [];
    sichtbareTexte($englisch, $texte);
    check($modul . ': englisches Formular hat Texte', count($texte) > 5, true);
    foreach ($texte as $text) {
        if (klingtDeutsch($text)) {
            check($modul . ': englisches Formular ohne deutsche Reste: ' . mb_substr($text, 0, 70), true, false);
        }
    }

    IPSTestState::useLocale(WURZEL . '/' . $modul);
    $deutsch = konsoleUebersetzt(formularVon($modul), $de);
    $texteDe = [];
    sichtbareTexte($deutsch, $texteDe);
    check($modul . ': deutsches Formular hat gleich viele Texte wie das englische', count($texteDe), count($texte));
    $gleich = 0;
    foreach ($texte as $i => $text) {
        if (preg_match('/\pL{4,}/u', $text) && ($texteDe[$i] ?? '') === $text && !in_array(ohneSymbol($text), GLEICH, true)) {
            $gleich++;
        }
    }
    check($modul . ': im deutschen Formular bleibt kein englischer Text stehen', $gleich, 0);
    IPSTestState::$locale = null;
}

/* --------------------- Symcon wird je Text höchstens einmal gefragt (Stolperstein 24) */

IPSTestState::useLocale(WURZEL . '/CometWiFiRoomTile');
IPSTestState::$missing = [];
IPSTestState::reset();
IPSTestState::$instances[90] = [
    'InstanceID' => 90, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE,
    'ModuleInfo' => ['ModuleID' => '{TEST}'], 'Name' => 'Kachel'
];
$kachel = new CometWiFiRoomTile(90);
$kachel->Create();
$kachel->GetVisualizationTile();
$vorher = IPSTestState::$translateCalls;
for ($i = 0; $i < 25; $i++) {
    $kachel->GetVisualizationTile();
}
check('Kachel: 25 weitere Durchläufe fragen Symcon höchstens je Durchlauf einmal (Sprachprobe)',
    IPSTestState::$translateCalls - $vorher <= 25, true);

IPSTestState::$locale = null;

/* ------------------------------------------------------------------ Ergebnis */

if ($failed > 0) {
    printf("\n❌  %d von %d Prüfungen fehlgeschlagen.\n", $failed, $passed + $failed);
    exit(1);
}
printf("✅  Alle %d Prüfungen bestanden.\n", $passed);
exit(0);
