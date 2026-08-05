<?php

declare(strict_types=1);

/**
 * Registertabelle der Comet-WiFi-Thermostate samt Dekodierung.
 *
 * GRUNDREGEL: Hier steht ausschließlich, was in docs/protokoll.md als ✅ verifiziert geführt
 * wird. Register mit Status 🟡 oder ❓ gehören NICHT in diese Tabelle — sie landen über den
 * Rohdatenpfad als unveränderte Zeichenkette in einer RAW_*-Variable. Ein falsch gedeutetes
 * Bitfeld sperrt im Zweifel die Tasten eines Geräts; eine ehrliche Lücke tut das nicht.
 *
 * Wer ein Register entschlüsselt: erst docs/protokoll.md auf ✅ heben (mit Begründung),
 * dann hier ergänzen.
 */
class CWIFI_Registers
{
    /* ---------------------------------------------------------------- Idents */

    public const IDENT_TEMPERATURE = 'Temperature';
    public const IDENT_SETPOINT    = 'Setpoint';
    public const IDENT_BATTERY     = 'Battery';
    public const IDENT_BATTERY_LOW = 'BatteryLow';
    public const IDENT_RSSI        = 'RSSI';
    public const IDENT_REACHABLE   = 'Reachable';
    public const IDENT_LAST_UPDATE = 'LastUpdate';

    /* -------------------------------------------------------------- Register */

    public const REG_SETPOINT    = 'A0';
    public const REG_TEMPERATURE = 'A1';
    public const REG_BATTERY     = 'A6';
    public const REG_RSSI        = 'B3';
    public const REG_COMM        = 'XX';
    public const REG_REQUEST     = 'AF';

    /* -------------------------------------------------------------- Payloads */

    public const PAYLOAD_COMM_TEST = '#COMM-TEST';
    public const PAYLOAD_COMM_LOSS = '#COMM-LOSS';

    /** Datenabruf: nur die aktuellen Temperaturen. Das schonendste Kommando. */
    public const REQUEST_CURRENT = '#0B';

    /** Datenabruf: alle Felder. Weckt das Gerät spürbar — nie auf einem Timer verwenden. */
    public const REQUEST_ALL = '#FFFFFFFF';

    /**
     * Feldauswahl für `S/AF`: Das erste Byte ist eine Bitmaske der Register, Bit n = A(n).
     *
     *   0x01 = A0 (Sollwert) · 0x02 = A1 (Isttemperatur) · 0x04 = A2 (Offset)
     *   0x08 = A3 (Optionen) · 0x10 = A4 · 0x20 = A5 · 0x40 = A6 (Batterie) · 0x80 = A7
     *
     * Gegenprobe: Das aus den Foren als „Batterie, Tastensperre, Sommerzeit, Rotation"
     * beschriebene `#48000000` ist 0x40|0x08 — also genau A6 und A3.
     *
     * @param string ...$registers Register wie 'A0', 'A3'.
     */
    public static function requestFields(string ...$registers): string
    {
        $mask = 0;
        foreach ($registers as $register) {
            $n = hexdec(substr(strtoupper($register), 1));
            if (strtoupper($register)[0] === 'A' && $n >= 0 && $n <= 7) {
                $mask |= (1 << $n);
            }
        }
        return '#' . sprintf('%02X', $mask) . '000000';
    }

    /** Batterie-Dekodierung, umschaltbar solange die Kodierung nicht bewiesen ist. */
    public const BATTERY_HEX     = 0;
    public const BATTERY_DECIMAL = 1;

    /**
     * Register, die als Variable geführt werden.
     *
     * kind:  'temperature' | 'battery' | 'signed'
     * Alles andere wird bewusst nicht gedeutet.
     */
    public static function map(): array
    {
        return [
            self::REG_TEMPERATURE => [
                'ident'    => self::IDENT_TEMPERATURE,
                'caption'  => 'Current temperature',
                'kind'     => 'temperature',
                'type'     => VARIABLETYPE_FLOAT,
                'position' => 10,
                'writable' => false
            ],
            self::REG_SETPOINT => [
                'ident'    => self::IDENT_SETPOINT,
                'caption'  => 'Target temperature',
                'kind'     => 'temperature',
                'type'     => VARIABLETYPE_FLOAT,
                'position' => 20,
                'writable' => true
            ],
            self::REG_BATTERY => [
                'ident'    => self::IDENT_BATTERY,
                'caption'  => 'Battery level',
                'kind'     => 'battery',
                'type'     => VARIABLETYPE_INTEGER,
                'position' => 30,
                'writable' => false
            ],
            self::REG_RSSI => [
                'ident'    => self::IDENT_RSSI,
                'caption'  => 'WiFi signal strength',
                'kind'     => 'signed',
                'type'     => VARIABLETYPE_INTEGER,
                'position' => 40,
                'writable' => false
            ]
        ];
    }

    /** Definition zu einem Register, oder null wenn es (noch) nicht gedeutet wird. */
    public static function byRegister(string $register): ?array
    {
        return self::map()[strtoupper($register)] ?? null;
    }

    /** Register zu einem Ident — für den Schreibpfad. */
    public static function registerForIdent(string $ident): ?string
    {
        foreach (self::map() as $register => $def) {
            if ($def['ident'] === $ident) {
                return $register;
            }
        }
        return null;
    }

    /* ------------------------------------------------------------ Dekodierung */

    /**
     * Prüft, ob ein Payload überhaupt dem beobachteten Format entspricht.
     * Alles, was hier durchfällt, geht nur ins Debug — nicht in eine Variable.
     */
    public static function isPlausible(string $payload): bool
    {
        return (bool) preg_match('/^#[0-9A-Za-z.:\-]{1,64}$/', $payload);
    }

    /**
     * Dekodiert einen Payload gemäß Registerdefinition.
     *
     * @param int $batteryMode Nur für 'battery' relevant, siehe BATTERY_*.
     * @return float|int|null  null = nicht dekodierbar (Aufrufer schreibt dann nichts).
     */
    public static function decode(array $definition, string $payload, int $batteryMode = self::BATTERY_HEX)
    {
        if (!self::isPlausible($payload)) {
            return null;
        }
        $body = substr($payload, 1);

        switch ($definition['kind']) {
            case 'temperature':
                // Halbgrad-Raster: Hex-Wert entspricht dem doppelten Temperaturwert.
                // Ausdrücklich als float zurückgeben — PHPs '/' liefert bei glatt teilbaren
                // Ganzzahlen sonst ein int, und der Typ schwankte je nach Temperatur.
                if (!ctype_xdigit($body)) {
                    return null;
                }
                return (float) (hexdec($body) / 2);

            case 'battery':
                // Kodierung noch nicht bewiesen, siehe docs/protokoll.md.
                if ($batteryMode === self::BATTERY_DECIMAL) {
                    if (!ctype_digit($body)) {
                        return null;
                    }
                    $value = intval($body, 10);
                } else {
                    if (!ctype_xdigit($body)) {
                        return null;
                    }
                    $value = (int) hexdec($body);
                }
                return max(0, min(100, $value));

            case 'signed':
                // Dezimal MIT Vorzeichen — nicht hex. Gilt bewiesen für B3.
                if (!preg_match('/^-?\d+$/', $body)) {
                    return null;
                }
                return intval($body, 10);
        }

        return null;
    }

    /**
     * Beweist ein Batterie-Payload die Hex-Kodierung?
     *
     * Enthält der Wert ein Zeichen A–F, kann es keine Dezimalzahl sein. Der umgekehrte
     * Schluss gilt NICHT — '#64' ist in beiden Kodierungen gültig.
     */
    public static function provesHexBattery(string $payload): bool
    {
        return (bool) preg_match('/^#[0-9]*[A-Fa-f][0-9A-Fa-f]*$/', $payload);
    }

    /**
     * Kodiert eine Solltemperatur für das S/A0-Kommando.
     *
     * ⚠️ Die Schreibkodierung ist bislang aus der Leserichtung abgeleitet, nicht am Gerät
     *    bewiesen (docs/protokoll.md, offene Frage 1). Bis zum Gegenbeweis: gleiches
     *    Halbgrad-Raster, Großbuchstaben, mindestens zwei Stellen.
     *
     * @param float $celsius Sollwert; wird geklemmt und auf 0,5 K gerundet.
     * @return array{0:string,1:float} [Payload, tatsächlich gesetzter Wert]
     */
    public static function encodeSetpoint(float $celsius, float $min, float $max): array
    {
        $clamped = max($min, min($max, $celsius));
        $halves  = (int) round($clamped * 2);
        return ['#' . sprintf('%02X', $halves), (float) ($halves / 2)];
    }

    /** Zeitstempel für die Zeitsynchronisation: #JJ.MM.TT-HH:MM in UTC. */
    public static function encodeTimestamp(int $timestamp): string
    {
        return '#' . gmdate('y.m.d-H:i', $timestamp);
    }
}
