<?php
/**
 * Kopiert .libs/locale.json (einzige Quelle der deutschen Fassung) in alle fünf Modulordner,
 * denn Symcon liest locale.json nur im Ordner des Moduls.
 * Aufruf: php .tools/locale-bauen.php
 */
declare(strict_types=1);
$quelle  = (string) file_get_contents(__DIR__ . '/../.libs/locale.json');
$tabelle = json_decode($quelle, true, 512, JSON_THROW_ON_ERROR);
foreach (['CometWiFiThermostat', 'CometWiFiConfigurator', 'CometWiFiTile', 'CometWiFiRoomTile', 'CometWiFiRoom'] as $modul) {
    file_put_contents(__DIR__ . '/../' . $modul . '/locale.json', $quelle);
}
echo 'locale.json für 5 Module geschrieben (' . count($tabelle['translations']['de']) . " Texte)\n";
