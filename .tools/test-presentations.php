<?php

declare(strict_types=1);

/**
 * Prüfstand für Presentation-OPTIONS-Einträge aller Module.
 *
 *   php .tools/test-presentations.php     # 0 = alle Prüfungen bestanden
 *
 * Gültige OPTIONS-Schlüssel hängen von der Darstellung ab (SUITE.md, Stolperstein 9, Korrektur
 * 18.10.2026): Aufzählung (ENUMERATION) kennt Value, Caption, IconActive, IconValue, Color;
 * Wertanzeige (VALUE_PRESENTATION, auch bei bool) kennt Value, Caption, IconActive, IconValue,
 * ColorActive, ColorValue und NICHT Color (Symcon 9.1 bricht sonst das Anlegen ab). Unbekannte Schlüssel brechen auf neueren Symcon-Versionen das
 * Anlegen der Instanz ab und lassen die Mobile-App beim Öffnen der Kachel abstürzen.
 * Ältere Versionen tolerieren sie still — deshalb fällt es beim Autor nie auf.
 *
 * Statische Prüfung: Jedes Array-Literal, das einen 'Caption'-Schlüssel führt, ist ein
 * OPTIONS-Eintrag; seine Schlüssel der obersten Ebene werden gegen die Erlaubnisliste geprüft.
 * Die Darstellung ergibt sich aus dem VARIABLE_PRESENTATION_*-Token in derselben Funktion
 * (vor oder nach dem Eintrag); Hilfsfunktionen ohne Token zählen als Aufzählung, wenn ihr Name
 * 'enum' enthält. Lässt sich die Darstellung nicht eindeutig bestimmen, ist das ein Befund.
 * Eine Gegenprobe (Mutationstest) stellt sicher, dass die Prüfung wirklich anschlägt.
 */

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

const ALLOWED = [
    'ENUMERATION'        => ['Value', 'Caption', 'IconActive', 'IconValue', 'Color'],
    'VALUE_PRESENTATION' => ['Value', 'Caption', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue'],
];

/** Darstellung des OPTIONS-Eintrags an Position $p: aus der umschließenden Funktion. */
function presentationAt(string $source, int $p): ?string
{
    $fStart = strrpos(substr($source, 0, $p), 'function ');
    $fStart = ($fStart === false) ? 0 : $fStart;
    $fEnd = strpos($source, 'function ', $p);
    $body = substr($source, $fStart, ($fEnd === false ? strlen($source) : $fEnd) - $fStart);
    preg_match_all('/VARIABLE_PRESENTATION_(ENUMERATION|VALUE_PRESENTATION)\b/', $body, $m);
    $kinds = array_values(array_unique($m[1]));
    if (count($kinds) === 1) {
        return $kinds[0];
    }
    if (count($kinds) > 1) {
        // mehrere Variablen in einer Funktion: zuerst die nächste Darstellung NACH dem Eintrag
        // (Muster: Optionen bauen, dann MaintainVariable), sonst die letzte davor
        $rel = $p - $fStart;
        if (preg_match('/VARIABLE_PRESENTATION_(ENUMERATION|VALUE_PRESENTATION)\b/', $body, $n, 0, $rel)) {
            return $n[1];
        }
        preg_match_all('/VARIABLE_PRESENTATION_(ENUMERATION|VALUE_PRESENTATION)\b/', substr($body, 0, $rel), $b);
        return end($b[1]) ?: null;
    }
    if (count($kinds) === 0 && preg_match('/^function\s+\w*enum\w*\s*\(/i', $body)) {
        return 'ENUMERATION';
    }
    return null;
}

/**
 * @return array<int,array{0:int,1:string}> Verstöße als [Zeile, Meldung]
 */
function scanOptions(string $source): array
{
    $problems = [];
    $len = strlen($source);

    $o = 0;
    while (($p = strpos($source, "'Caption'", $o)) !== false) {
        $o = $p + 1;
        // umschließendes '[' suchen
        $depth = 0;
        $start = null;
        for ($i = $p; $i >= 0; $i--) {
            $c = $source[$i];
            if ($c === ']') {
                $depth++;
            } elseif ($c === '[') {
                if ($depth === 0) {
                    $start = $i;
                    break;
                }
                $depth--;
            }
        }
        if ($start === null) {
            continue;
        }
        // passendes ']' und Schlüssel der obersten Ebene einsammeln
        $depth = 0;
        $keys = [];
        for ($i = $start; $i < $len; $i++) {
            $c = $source[$i];
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && $c === "'"
                && preg_match("/\G'([A-Za-z]+)'\s*=>/", $source, $m, 0, $i)) {
                $keys[] = $m[1];
                $i += strlen($m[0]) - 1;
            }
        }
        if (!in_array('Value', $keys, true)) {
            continue; // kein OPTIONS-Eintrag (z. B. Formularelement mit 'caption'-Zweig)
        }
        $line = substr_count($source, "\n", 0, $p) + 1;
        $kind = presentationAt($source, $p);
        if ($kind === null) {
            $problems[] = [$line, 'Darstellung (Aufzählung/Wertanzeige) nicht bestimmbar'];
            continue;
        }
        foreach (array_diff($keys, ALLOWED[$kind]) as $k) {
            $problems[] = [$line, "unbekannter OPTIONS-Schlüssel '$k' für $kind"];
        }
    }
    return $problems;
}

$files = glob(__DIR__ . '/../CometWiFi*/module.php');
check('Modul-Dateien gefunden', count($files) >= 5, true);

$entries = 0;
foreach ($files as $file) {
    $src = file_get_contents($file);
    $name = basename(dirname($file));
    $entries += preg_match_all("/'Caption'\s*=>/", $src);
    $problems = scanOptions($src);
    check("$name: keine ungültigen OPTIONS-Schlüssel", $problems, []);
}
check('Es wurden OPTIONS-Einträge geprüft', $entries >= 5, true);

/* ------------------------------------------------- Gegenprobe (Mutationstest) */

$enum = fn(string $e) => "function f() { \$o = [['Value' => 1, 'Caption' => 'x', $e]]; \$p = ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION]; }";
$val  = fn(string $e) => "function g() { return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'OPTIONS' => [['Value' => true, 'Caption' => 'x', $e]]]; }";
check('Gegenprobe: gültige Aufzählung bleibt unbeanstandet', scanOptions($enum("'IconActive' => false, 'IconValue' => '', 'Color' => -1")), []);
check('Gegenprobe: gültige Wertanzeige bleibt unbeanstandet', scanOptions($val("'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 255")), []);
check("Gegenprobe: Hilfsfunktion enumOption ohne Token zählt als Aufzählung", scanOptions("function enumOption() { return ['Value' => 1, 'Caption' => 'x', 'Color' => -1]; }"), []);
foreach ([
    "Aufzählung mit 'Icon'"         => $enum("'IconActive' => false, 'Icon' => ''"),
    "Aufzählung mit 'ColorActive'"  => $enum("'ColorActive' => false, 'Color' => -1"),
    "Aufzählung mit 'ColorValue'"   => $enum("'Color' => -1, 'ColorValue' => -1"),
    "Aufzählung mit 'Foo'"          => $enum("'IconActive' => false, 'Foo' => 1"),
    "Wertanzeige mit 'Color'"       => $val("'IconActive' => false, 'Color' => 255"),
    'Darstellung nicht bestimmbar'  => "function h() { return ['Value' => 1, 'Caption' => 'x']; }",
] as $label => $bad) {
    check("Gegenprobe: $label wird erkannt", count(scanOptions($bad)) >= 1, true);
}

if ($failed > 0) {
    printf("\n❌  %d von %d Prüfungen fehlgeschlagen.\n", $failed, $passed + $failed);
    exit(1);
}
printf("✅  Alle %d Prüfungen bestanden.\n", $passed);
exit(0);
