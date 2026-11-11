<?php

declare(strict_types=1);

/**
 * Übersetzung für Kacheln und Meldungen.
 *
 * Quelltexte stehen englisch im Code und in den form.json, die deutsche Fassung in
 * .libs/locale.json (php .tools/locale-bauen.php kopiert sie in alle Modulordner). Formulare
 * übersetzt die Konsole selbst; hier stehen nur Kacheltexte, Meldungen und Statuszeilen.
 *
 * Symcons Translate() liest bei JEDEM Aufruf die locale.json (SUITE.md, Stolperstein 24).
 * Deshalb fragt translator() Symcon je Text höchstens einmal je Prozess und Sprache.
 */
final class CWIFI_Text
{
    /** Text, der in jeder Fremdsprache übersetzt ist — verrät einen Sprachwechsel. */
    private const CANARY = 'Module version: ';

    /** @var array<string,string> */
    private static array $remembered = [];
    private static ?string $canary = null;

    /** Wie oft Symcon wirklich gefragt wurde — für den Prüfstand. */
    public static int $requests = 0;

    /** Übersetzer mit Merkliste; wechselt die Sprache, wird die Merkliste verworfen. */
    public static function translator(callable $translate): callable
    {
        $probe = (string) $translate(self::CANARY);
        if (self::$canary !== $probe) {
            self::$canary     = $probe;
            self::$remembered = [];
        }
        return static function (string $text) use ($translate): string {
            if (!isset(self::$remembered[$text])) {
                self::$requests++;
                self::$remembered[$text] = (string) $translate($text);
            }
            return self::$remembered[$text];
        };
    }

    /**
     * Texte der Raumkachel (gemeinsame Vorlage .libs/CWIFI_RoomTile.html für Raumkachel und Raum).
     * Die Kachel bekommt sie mit jeder Nutzlast; der Browser übersetzt nichts selbst.
     *
     * @return array<string,string>
     */
    public static function roomTileTexts(callable $t): array
    {
        return [
            'none1'         => $t('No thermostat selected.'),
            'none2'         => $t('Select one in the instance settings.'),
            'unreachable'   => $t('unreachable'),
            'holiday'       => $t('Holiday'),
            'locked'        => $t('locked'),
            'lockedPlus'    => $t('locked +'),
            'clock'         => $t('Clock %s min'),
            'mixed'         => $t('Setpoints differ'),
            'mixedTitle'    => $t('The thermostats of this room are set to different setpoints. The highest is shown. A tap on the dial aligns them.'),
            'actual'        => $t('Current'),
            'target'        => $t('Target'),
            'endOn'         => $t('End stop: valve fully open'),
            'endOff'        => $t('End stop: valve fully closed'),
            'targetTitle'   => $t('Target temperature'),
            'cooler'        => $t('Half a degree cooler'),
            'warmer'        => $t('Half a degree warmer'),
            'off'           => $t('OFF'),
            'on'            => $t('ON'),
            'valveClosed'   => $t('Valve fully closed'),
            'valveOpen'     => $t('Valve fully open'),
            'manual'        => $t('Manual'),
            'schedule'      => $t('Schedule'),
            'manualTitle'   => $t('The schedule is off — the setpoint stays as set. Click to switch it back on.'),
            'scheduleTitle' => $t('The weekly schedule in the device controls and overwrites set setpoints.'),
            'offset'        => $t('Offset'),
            'thermostat1'   => $t('%d thermostat'),
            'thermostatN'   => $t('%d thermostats'),
            'lastTitle'     => $t('Last message from the device'),
            'decimal'       => $t('.')
        ];
    }

    /**
     * Texte der Übersichtskachel (CometWiFiTile).
     *
     * @return array<string,string>
     */
    public static function overviewTexts(callable $t): array
    {
        return [
            'empty'         => $t('No thermostats found. As soon as a device instance has been created, it appears here automatically.'),
            'issue1'        => $t('%d device needs attention'),
            'issueN'        => $t('%d devices need attention'),
            'room1'         => $t('%d room'),
            'roomN'         => $t('%d rooms'),
            'tempTitle'     => $t('Measured room temperature'),
            'actual'        => $t('Current'),
            'target'        => $t('Target'),
            'targetTitle'   => $t('Target temperature'),
            'endOn'         => $t('End stop: valve fully open (%s)'),
            'endOff'        => $t('End stop: valve fully closed (%s)'),
            'cooler'        => $t('Half a degree cooler'),
            'warmer'        => $t('Half a degree warmer'),
            'unreachable'   => $t('unreachable'),
            'manual'        => $t('Manual'),
            'manualTitle'   => $t('The schedule is switched off — the setpoint stays as set.'),
            'clockTitle'    => $t('The device\'s clock is wrong — the weekly schedule in the device switches offset by this amount.'),
            'lastTitle'     => $t('Last message from the device'),
            'off'           => $t('OFF'),
            'on'            => $t('ON'),
            'decimal'       => $t('.')
        ];
    }

    /** „vor 3 Minuten" statt eines Zeitstempels. */
    public static function ago(int $seconds, callable $t, bool $round): string
    {
        $part = static fn (int $by): int => $round ? (int) round($seconds / $by) : intdiv($seconds, $by);
        if ($seconds < 90) {
            return $t('just now');
        }
        if ($seconds < ($round ? 5400 : 3600)) {
            return sprintf($t('%d min ago'), $part(60));
        }
        if ($seconds < 86400) {
            $hours = $part(3600);
            return sprintf($t($hours === 1 ? '%d hour ago' : '%d hours ago'), $hours);
        }
        $days = $part(86400);
        return sprintf($t($days === 1 ? '%d day ago' : '%d days ago'), $days);
    }
}
