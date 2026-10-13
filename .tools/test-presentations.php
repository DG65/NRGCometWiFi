<?php

declare(strict_types=1);

/**
 * Prüfstand für Presentation-OPTIONS-Einträge aller Module.
 *
 *   php .tools/test-presentations.php     # 0 = alle Prüfungen bestanden
 *
 * Gültig sind in OPTIONS nur: Value, Caption, IconActive, IconValue, Color
 * (SUITE.md, Stolperstein 9). Unbekannte Schlüssel brechen auf neueren Symcon-Versionen das
 * Anlegen der Instanz ab und lassen die Mobile-App beim Öffnen der Kachel abstürzen.
 * Ältere Versionen tolerieren sie still — deshalb fällt es beim Autor nie auf.
 *
 * Statische Prüfung: Jedes Array-Literal, das einen 'Caption'-Schlüssel führt, ist ein
 * OPTIONS-Eintrag; seine Schlüssel der obersten Ebene werden gegen die Erlaubnisliste geprüft.
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

const ALLOWED = ['Value', 'Caption', 'IconActive', 'IconValue', 'Color'];

/**
 * @return array<int,array{0:int,1:string}> Verstöße als [Zeile, Meldung]
 */
function scanOptions(string $source): array
{
    $problems = [];
    $len = strlen($source);

    // Verbotene Altschlüssel überall (Fehlalarme sind im Modul nicht vorhanden)
    foreach (['ColorActive', 'ColorValue'] as $bad) {
        $o = 0;
        while (($p = strpos($source, "'" . $bad . "'", $o)) !== false) {
            $problems[] = [substr_count($source, "\n", 0, $p) + 1, "Schlüssel '$bad'"];
            $o = $p + 1;
        }
    }

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
        foreach (array_diff($keys, ALLOWED) as $k) {
            $problems[] = [$line, "unbekannter OPTIONS-Schlüssel '$k'"];
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

$good = "['Value' => 1, 'Caption' => 'x', 'IconActive' => false, 'IconValue' => '', 'Color' => -1]";
check('Gegenprobe: gültiger Eintrag bleibt unbeanstandet', scanOptions($good), []);
foreach ([
    "'Icon' => ''"        => "'IconActive' => false, 'Icon' => ''",
    "'ColorActive'"       => "'ColorActive' => false, 'Color' => -1",
    "'ColorValue'"        => "'Color' => -1, 'ColorValue' => -1",
    "'Foo'"               => "'IconActive' => false, 'Foo' => 1",
] as $label => $extra) {
    $bad = "['Value' => 1, 'Caption' => 'x', $extra]";
    check("Gegenprobe: $label wird erkannt", count(scanOptions($bad)) >= 1, true);
}

if ($failed > 0) {
    printf("\n❌  %d von %d Prüfungen fehlgeschlagen.\n", $failed, $passed + $failed);
    exit(1);
}
printf("✅  Alle %d Prüfungen bestanden.\n", $passed);
exit(0);
