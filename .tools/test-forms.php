<?php

declare(strict_types=1);

/**
 * Prüfstand für die Formulare aller Module.
 *
 *   php .tools/test-forms.php     # 0 = alle Prüfungen bestanden
 *
 * Anlass: Ein Knopf in der Raumkachel rief `CWIFIR_RequestAction($id, 'refresh', 0)` auf und
 * lief in einen Fatal Error. Symcon erzeugt den globalen Wrapper `PREFIX_Name()` nämlich
 * **nur für Methoden, die die Modulklasse selbst deklariert** — und `RequestAction` ist eine
 * geerbte SDK-Methode. Es gibt also keinen `CWIFIR_RequestAction()`.
 *
 * Das ist eine Fehlerklasse, die kein Syntaxprüfer und kein Laufzeittest im Empfangspfad
 * findet: Sie schlägt erst zu, wenn jemand im Formular klickt. Deshalb prüft dieser Stand
 * jeden `onClick`/`onChange` aller Module gegen die tatsächlich vorhandenen Methoden.
 */

require_once __DIR__ . '/ips-stub.php';

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
 * Von Symcon belegte Methodennamen.
 *
 * Für sie entsteht kein Wrapper, selbst wenn die Modulklasse sie überschreibt — sie sind der
 * Weg des Kerns in das Modul hinein, nicht der Weg von außen.
 */
const SDK_METHODEN = [
    'Create', 'Destroy', 'ApplyChanges', 'MessageSink', 'RequestAction',
    'GetConfigurationForm', 'ReceiveData', 'ForwardData', 'GetVisualizationTile'
];

/* Alle Module einlesen und ihre Klassen laden. */
$module = [];
foreach (glob(__DIR__ . '/../*/module.json') as $datei) {
    $verzeichnis = dirname($datei);
    $meta = json_decode((string) file_get_contents($datei), true);
    if (!isset($meta['prefix'], $meta['name'])) {
        continue;
    }
    require_once $verzeichnis . '/module.php';
    $module[$meta['prefix']] = [
        'klasse'      => $meta['name'],
        'verzeichnis' => $verzeichnis,
        'form'        => $verzeichnis . '/form.json'
    ];
}

// Gegen die Verzeichnisse zaehlen statt gegen eine feste Zahl: Ein neues Modul soll den
// Prüfstand erweitern, nicht brechen — ein vergessenes module.json aber schon auffallen.
$verzeichnisse = glob(__DIR__ . '/../CometWiFi*', GLOB_ONLYDIR);
check('Jedes Modulverzeichnis ist erfasst', count($module), count($verzeichnisse));
check('Es gibt ueberhaupt Module', count($module) >= 4, true);

// Längster Präfix zuerst, sonst schluckt CWIFI die Aufrufe von CWIFIC/CWIFIR/CWIFIT.
$praefixe = array_keys($module);
usort($praefixe, fn ($a, $b) => strlen($b) <=> strlen($a));

/** Sucht rekursiv alle onClick/onChange-Zeichenketten in einem Formular. */
function sammleAufrufe($knoten, array &$treffer): void
{
    if (!is_array($knoten)) {
        return;
    }
    foreach ($knoten as $schluessel => $wert) {
        if (is_string($wert) && in_array($schluessel, ['onClick', 'onChange'], true)) {
            $treffer[] = $wert;
        } elseif (is_array($wert)) {
            sammleAufrufe($wert, $treffer);
        }
    }
}

$geprueft = 0;

foreach ($module as $praefix => $info) {
    $klasse = $info['klasse'];
    check('Klasse ' . $klasse . ' existiert', class_exists($klasse), true);

    if (!is_file($info['form'])) {
        continue;
    }
    $form = json_decode((string) file_get_contents($info['form']), true);
    check('form.json von ' . $klasse . ' ist gültiges JSON', is_array($form), true);

    $aufrufe = [];
    sammleAufrufe($form, $aufrufe);

    foreach ($aufrufe as $aufruf) {
        // Alle PREFIX_Name( im Ausdruck herausziehen — ein onChange kann mehrere enthalten.
        if (!preg_match_all('/\b([A-Z][A-Z0-9]*)_([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $aufruf, $funde, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($funde as $fund) {
            [$ganz, $rufPraefix, $methode] = $fund;

            // Den längsten passenden Präfix nehmen: CWIFIR_Foo ist nicht CWIFI + "R_Foo".
            $treffer = null;
            foreach ($praefixe as $kandidat) {
                if ($rufPraefix === $kandidat) {
                    $treffer = $kandidat;
                    break;
                }
            }
            check('Präfix ' . $rufPraefix . ' gehört zu einem Modul (' . $aufruf . ')',
                $treffer !== null, true);
            if ($treffer === null) {
                continue;
            }

            $zielKlasse = $module[$treffer]['klasse'];
            $geprueft++;

            check($rufPraefix . '_' . $methode . ' existiert in ' . $zielKlasse,
                method_exists($zielKlasse, $methode), true);
            if (!method_exists($zielKlasse, $methode)) {
                continue;
            }

            $spiegel = new ReflectionMethod($zielKlasse, $methode);

            check($rufPraefix . '_' . $methode . ' ist öffentlich',
                $spiegel->isPublic(), true);

            /* Der eigentliche Punkt: Symcon erzeugt den Wrapper nur für Methoden, die die
               Modulklasse SELBST deklariert. Eine geerbte tut es nicht. */
            check($rufPraefix . '_' . $methode . ' ist in der Modulklasse deklariert',
                $spiegel->getDeclaringClass()->getName(), $zielKlasse);

            /* Und selbst eine selbst deklarierte bekommt keinen Wrapper, wenn der Name dem
               SDK gehört — genau daran ist die Raumkachel gescheitert. */
            check($rufPraefix . '_' . $methode . ' ist kein belegter SDK-Name',
                in_array($methode, SDK_METHODEN, true), false);
        }
    }
}

check('Es wurden überhaupt Aufrufe geprüft', $geprueft > 0, true);

/* --------------------------------------------------- Gegenprobe der Prüflogik
 *
 * Ein Prüfstand, der nie anschlägt, ist keiner. Hier wird der Fehler von damals künstlich
 * nachgestellt und muss von genau denselben Bedingungen erkannt werden.
 */
$fehlerhaft = 'CWIFIR_RequestAction($id, \'refresh\', 0);';
preg_match('/\b([A-Z][A-Z0-9]*)_([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $fehlerhaft, $f);
$spiegel = new ReflectionMethod($module['CWIFIR']['klasse'], $f[2]);

check('Gegenprobe: der Aufruf wäre erkannt worden',
    in_array($f[2], SDK_METHODEN, true), true);

/* Und hier die eigentliche Lehre, die diese Gegenprobe zutage gefördert hat: Die Prüfung
   „ist in der Modulklasse deklariert" hätte den Fehler NICHT gefunden. Die Raumkachel
   deklariert `RequestAction` sehr wohl selbst — sie ist der Rückkanal aus dem Browser.
   Symcon erzeugt trotzdem keinen Wrapper, weil der Name dem SDK gehört. Ohne die
   Namensliste wäre dieser Prüfstand also blind für genau den Fall, für den es ihn gibt. */
check('Gegenprobe: die Deklarationsprüfung allein hätte nichts gemerkt',
    $spiegel->getDeclaringClass()->getName() === $module['CWIFIR']['klasse'], true);

/* ------------------------------------------------------------------ Ergebnis */

if ($failed > 0) {
    printf("\n❌  %d von %d Prüfungen fehlgeschlagen.\n", $failed, $passed + $failed);
    exit(1);
}
printf("✅  Alle %d Prüfungen bestanden.\n", $passed);
exit(0);
