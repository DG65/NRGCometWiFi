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
    public const REG_OFFSET      = 'A2';
    public const REG_OPTIONS     = 'A3';
    public const REG_HOLIDAY     = 'A7';
    public const REG_BATTERY     = 'A6';
    public const REG_RSSI        = 'B3';
    public const REG_COMM        = 'XX';
    public const REG_REQUEST     = 'AF';

    /* ------------------------------------------------- Optionen-Bitfeld (A3)
     *
     * Alle Bits am Gerät belegt (05.08.2026), jeweils in BEIDE Richtungen geschaltet und
     * gegen die Zustandsmeldung geprüft. Sie sitzen im OBEREN Byte des Zustands: Der
     * Zustand `#2182` hat also Sommerzeit (0x01) und Handbetrieb (0x20) gesetzt.
     */

    /** Automatische Sommer-/Winterzeitumstellung. */
    public const OPT_DST = 0x01;

    /** Anzeige um 180° drehen. */
    public const OPT_ROTATE = 0x02;

    /** Tastensperre. Schließt OPT_LOCK_PLUS aus. */
    public const OPT_LOCK = 0x04;

    /** Tastensperre plus. Schließt OPT_LOCK aus. */
    public const OPT_LOCK_PLUS = 0x08;

    /** Handbetrieb: gesetzt = Zeitplan aus, gelöscht = Wochenprogramm regelt. */
    public const OPT_MANUAL = 0x20;

    public const IDENT_MODE       = 'Mode';
    public const IDENT_KEYLOCK    = 'KeyLock';
    public const IDENT_ROTATE     = 'RotateDisplay';
    public const IDENT_DST        = 'AutoDST';
    public const IDENT_OFFSET     = 'Offset';
    public const IDENT_HOLIDAY      = 'Holiday';
    public const IDENT_HOLIDAY_FROM = 'HolidayFrom';
    public const IDENT_HOLIDAY_TO   = 'HolidayTo';
    public const IDENT_HOLIDAY_TEMP = 'HolidayTemperature';
    public const IDENT_SCHEDULE     = 'Schedule';

    /* ------------------------------------------------------------- Geräteauskunft
     *
     * Register, die das Gerät über sich selbst führt. Sie ändern sich praktisch nie und
     * kommen nur bei einem Voll-Dump mit — deshalb kosten sie keine zusätzliche Batterie,
     * sie fallen bei ohnehin stattfindenden Abrufen mit ab.
     */
    /**
     * Echtzeituhr des Geräts. Format `MM HH TT MM JJ`, dieselbe Reihenfolge wie `A7`.
     *
     * Am Gerät belegt (05.08.2026): Zwei Messungen desselben Thermostats im Abstand von
     * 3 h 19 min 57 s ergaben 18:33 und 21:53 — eine Zunahme von 3 h 20 min, auf drei
     * Sekunden genau. Gegenprobe über zehn Geräte: Jedes lieferte eine gültige Uhrzeit,
     * und die Abweichung zur echten Zeit blieb je Gerät über Stunden auf ±1 Minute stabil.
     *
     * Die Uhren laufen also richtig, stehen aber falsch — zwischen 24 und 60 Minuten vor,
     * eines um über neun Stunden. Das ist keine Kosmetik: Das Wochenprogramm läuft im
     * Gerät, nicht in Symcon, und schaltet entsprechend zu früh.
     *
     * Die letzten drei Byte sind auf allen Geräten `01 01 14` und bewegen sich nicht,
     * obwohl die Uhr längst über Mitternacht gelaufen ist. Als Datum gelesen wäre das der
     * 1. Januar 2020; bewiesen ist das nicht, und für die Uhrzeit spielt es keine Rolle.
     */
    public const REG_CLOCK = 'A4';

    public const IDENT_CLOCK     = 'DeviceClock';
    public const IDENT_CLOCK_DEV = 'ClockDeviation';

    public const REG_GROUP    = 'B0';
    public const REG_MODEL    = 'B1';
    public const REG_FIRMWARE = 'B2';
    public const REG_IP       = 'B6';
    public const REG_AP       = 'BA';
    public const REG_SECURITY = 'BF';

    public const IDENT_GROUP    = 'Group';
    public const IDENT_MODEL    = 'Model';
    public const IDENT_FIRMWARE = 'Firmware';
    public const IDENT_IP       = 'IPAddress';
    public const IDENT_AP       = 'AccessPoint';
    public const IDENT_SECURITY = 'WifiSecurity';

    /** Register → [Ident, englischer Quellstring für Translate()]. */
    public const INFO_REGISTERS = [
        self::REG_GROUP    => [self::IDENT_GROUP,    'Group'],
        self::REG_MODEL    => [self::IDENT_MODEL,    'Model'],
        self::REG_FIRMWARE => [self::IDENT_FIRMWARE, 'Firmware'],
        self::REG_IP       => [self::IDENT_IP,       'IP address'],
        self::REG_AP       => [self::IDENT_AP,       'Wi-Fi access point'],
        self::REG_SECURITY => [self::IDENT_SECURITY, 'Wi-Fi encryption']
    ];

    /**
     * Liest die Geräteuhr aus `A4`.
     *
     * Gibt `['hour','minute','deviation']` zurück, die Abweichung in Minuten gegenüber
     * `$now`. Ungültige Werte ergeben `null` — eine Stunde über 23 oder eine Minute über 59
     * ist keine Uhrzeit, und dann ist die Deutung falsch und nicht die Uhr.
     */
    public static function decodeClock(string $payload, ?int $now = null): ?array
    {
        $body = ltrim($payload, '#');
        if (strlen($body) < 4 || !ctype_xdigit(substr($body, 0, 4))) {
            return null;
        }
        $minute = hexdec(substr($body, 0, 2));
        $hour   = hexdec(substr($body, 2, 2));
        if ($minute > 59 || $hour > 23) {
            return null;
        }

        $now   = $now ?? time();
        $istMin = (int) date('H', $now) * 60 + (int) date('i', $now);
        $abw    = ($hour * 60 + $minute) - $istMin;
        // Über Mitternacht auf den kürzeren Weg normieren: +23 h heißt in Wahrheit -1 h.
        if ($abw < -720) { $abw += 1440; }
        if ($abw >  720) { $abw -= 1440; }

        return ['hour' => $hour, 'minute' => $minute, 'deviation' => $abw];
    }

    /**
     * Wandelt eine Geräteauskunft in lesbaren Text.
     *
     * Gibt `null` zurück, wenn sich nichts Sinnvolles ergibt — dann bleibt die Variable
     * unverändert, statt eine halbgare Zeichenkette anzuzeigen. Nichts hier ist geraten:
     * Jede Zuordnung stammt aus einem Voll-Dump und ist in `.docs/protokoll.md` belegt.
     */
    public static function decodeInfo(string $register, string $payload): ?string
    {
        $body = ltrim($payload, '#');
        if ($body === '') {
            return null;
        }

        switch (strtoupper($register)) {
            case self::REG_GROUP:
                // 'U' + Nullen = Einzelgerät, 'S' + MAC = an einen Gruppenkopf gekoppelt.
                $kopf = strtoupper(substr($body, 0, 1));
                $mac  = substr($body, 1);
                if ($kopf === 'U' || trim($mac, '0') === '') {
                    return 'Einzelgerät';
                }
                return 'Gruppe ' . self::formatMac($mac);

            case self::REG_MODEL:
            case self::REG_FIRMWARE:
            case self::REG_SECURITY:
                return self::hexToText($body);

            case self::REG_IP:
                // Aufbau #00 <IP: 4 Byte> <Rest, ungedeutet>. Nur die IP wird gelesen.
                if (strlen($body) < 10) {
                    return null;
                }
                $teile = [];
                for ($i = 0; $i < 4; $i++) {
                    $teile[] = hexdec(substr($body, 2 + $i * 2, 2));
                }
                return implode('.', $teile);

            case self::REG_AP:
                // Kommt bereits als Klartext-MAC, nur das Rautezeichen fällt weg.
                return strtolower($body);
        }
        return null;
    }

    /**
     * Hex-Ziffernpaare als ASCII lesen.
     *
     * Bricht ab, sobald ein Byte auftaucht, das kein druckbares Zeichen ist: Ein Register,
     * das wider Erwarten binär ist, soll als unlesbar auffallen und nicht als Buchstabensalat
     * durchgehen.
     */
    public static function hexToText(string $hex): ?string
    {
        if ($hex === '' || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
            return null;
        }
        $text = '';
        foreach (str_split($hex, 2) as $paar) {
            $code = hexdec($paar);
            if ($code < 0x20 || $code > 0x7E) {
                return null;
            }
            $text .= chr($code);
        }
        return trim($text);
    }

    /** MAC in die übliche Doppelpunktschreibweise bringen. */
    public static function formatMac(string $mac): string
    {
        $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
        return strlen($mac) === 12 ? implode(':', str_split($mac, 2)) : $mac;
    }

    /* --------------------------------------------------------- Sollwertskala
     *
     * Das Gerät kennt keine reine Temperaturskala: Unterhalb von 8,0 °C rastet es auf
     * „Aus" (Ventil zu), oberhalb von 28,0 °C auf „An" (Ventil auf). Beides sind gültige
     * Sollwerte und werden wie Temperaturen kodiert — 7,5 bzw. 28,5. Am Gerät belegt.
     *
     * Praktisch heißt das: Wer „Aus" meint, sendet 7,5; wer dauerhaft heizen will, 28,5.
     * Die vielen `#39` an Dietmars Anlage waren entsprechend keine 28,5-°C-Sollwerte,
     * sondern schlicht „An".
     */
    public const SETPOINT_OFF = 7.5;
    public const SETPOINT_ON  = 28.5;

    /** Kleinster bzw. größter Wert, der als echte Temperatur gemeint ist. */
    public const SETPOINT_MIN_REAL = 8.0;
    public const SETPOINT_MAX_REAL = 28.0;

    /** Ist dieser Sollwert einer der beiden Endanschläge? */
    public static function isEndstop(float $celsius): bool
    {
        return $celsius <= self::SETPOINT_OFF || $celsius >= self::SETPOINT_ON;
    }

    /** Tastensperre als dreistufige Auswahl — die Bits schließen einander aus. */
    public const LOCK_OFF  = 0;
    public const LOCK_ON   = 1;
    public const LOCK_PLUS = 2;

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

    /* -------------------------------------------------------- Urlaub (A7)
     *
     * Neun Byte: `HH TT MM JJ` für den Beginn, dasselbe für das Ende, dann die
     * Solltemperatur × 2. Am Gerät gegen die Hersteller-App geprüft (05.08.2026):
     * `#0C1F071A0C10081A32` entspricht exakt „31.7.2026 12:00 bis 16.8.2026 12:00, 25,0 °C".
     *
     * Neun Byte `FF` bedeuten: kein Urlaub gesetzt.
     */

    /** Urlaub lesen. @return array{start:int,end:int,temperature:float}|null null = nicht gesetzt */
    public static function decodeHoliday(string $payload): ?array
    {
        if (!self::isPlausible($payload)) {
            return null;
        }
        $body = substr($payload, 1);
        if (strlen($body) !== 18 || !ctype_xdigit($body)) {
            return null;
        }
        if (strtoupper($body) === str_repeat('F', 18)) {
            return null;                       // ausdrücklich „kein Urlaub"
        }
        $b = array_map('hexdec', str_split($body, 2));

        // Zweistellige Jahre: das Gerät kennt nur 20xx.
        $start = @mktime($b[0], 0, 0, $b[2], $b[1], 2000 + $b[3]);
        $end   = @mktime($b[4], 0, 0, $b[6], $b[5], 2000 + $b[7]);
        if ($start === false || $end === false) {
            return null;
        }
        return ['start' => $start, 'end' => $end, 'temperature' => (float) ($b[8] / 2)];
    }

    /** Urlaub schreiben. Beide Zeitpunkte werden auf die volle Stunde abgeschnitten. */
    public static function encodeHoliday(int $start, int $end, float $temperature): string
    {
        return '#' . sprintf(
            '%02X%02X%02X%02X%02X%02X%02X%02X%02X',
            (int) date('H', $start), (int) date('j', $start),
            (int) date('n', $start), (int) date('y', $start),
            (int) date('H', $end),   (int) date('j', $end),
            (int) date('n', $end),   (int) date('y', $end),
            (int) round(max(0.0, min(30.0, $temperature)) * 2)
        );
    }

    /** „Kein Urlaub" — neun Byte FF. */
    public static function encodeNoHoliday(): string
    {
        return '#' . str_repeat('FF', 9);
    }

    /* ----------------------------------------------- Wochenprogramm (A8–AE)
     *
     * Ein Register je Wochentag, `A8` = Montag bis `AE` = Sonntag. Je Schaltpunkt zwei
     * Byte, big-endian:
     *
     *   Bit 15–6   Minuten seit Montag 00:00, geteilt durch 10
     *   Bit  5–0   Solltemperatur × 2
     *
     * Die Zeit zählt also über die ganze Woche durch, nicht je Tag — deshalb steigen die
     * Werte von `A8` bis `AE` gleichmäßig an. Die Registerlänge ergibt sich aus der Anzahl
     * der Schaltpunkte: vier ergeben acht Byte, zwei ergeben vier.
     *
     * Am Gerät gegen die Hersteller-App geprüft (05.08.2026): `#062C09E4176C1FA4` ergibt
     * 04:00→22,0 · 06:30→18,0 · 15:30→22,0 · 21:00→18,0 — exakt der angezeigte Plan.
     */

    /** Register je Wochentag, Montag zuerst. */
    public const SCHEDULE_REGISTERS = ['A8', 'A9', 'AA', 'AB', 'AC', 'AD', 'AE'];

    /**
     * Einen Wochentag lesen.
     *
     * @param int $weekday 0 = Montag … 6 = Sonntag (für die Plausibilitätsprüfung).
     * @return array<int,array{time:string,minutes:int,temperature:float}>|null
     */
    public static function decodeSchedule(string $payload, int $weekday): ?array
    {
        if (!self::isPlausible($payload)) {
            return null;
        }
        $body = substr($payload, 1);
        if ($body === '' || strlen($body) % 4 !== 0 || !ctype_xdigit($body)) {
            return null;
        }

        $points = [];
        foreach (str_split($body, 4) as $word) {
            $value   = (int) hexdec($word);
            $minutes = ($value >> 6) * 10;          // seit Montag 00:00
            $temp    = ($value & 0x3F) / 2;

            // Der Schaltpunkt muss in den erwarteten Wochentag fallen — sonst stimmt die
            // Deutung nicht, und dann soll lieber gar nichts angezeigt werden.
            if (intdiv($minutes, 1440) !== $weekday) {
                return null;
            }
            $inDay = $minutes % 1440;
            $points[] = [
                'time'        => sprintf('%02d:%02d', intdiv($inDay, 60), $inDay % 60),
                'minutes'     => $inDay,
                'temperature' => (float) $temp
            ];
        }
        return $points;
    }

    /** Einen Wochentag als lesbaren Text. */
    public static function scheduleToText(?array $points): string
    {
        if ($points === null) {
            return '–';
        }
        if ($points === []) {
            return 'keine Schaltzeiten';
        }
        $parts = [];
        foreach ($points as $p) {
            $parts[] = $p['time'] . ' → ' . number_format($p['temperature'], 1, ',', '') . ' °C';
        }
        return implode(' · ', $parts);
    }

    /* ------------------------------------------------------ Optionen (A3) */

    /**
     * Liest das Optionen-Bitfeld aus einer `V/A3`-Meldung.
     *
     * Der Zustand ist zwei Byte (`#2182`); die Schaltbits sitzen im oberen.
     * Das untere Byte blieb über alle Tests unverändert und wird nicht gedeutet.
     *
     * @return int|null Oberes Byte, oder null wenn unlesbar.
     */
    public static function decodeOptions(string $payload): ?int
    {
        if (!self::isPlausible($payload)) {
            return null;
        }
        $body = substr($payload, 1);
        if (strlen($body) < 2 || !ctype_xdigit($body)) {
            return null;
        }
        return (int) hexdec(substr($body, 0, 2));
    }

    /**
     * Baut einen maskierten Schreibbefehl für `S/A3`.
     *
     * Format `#<SETZEN><LÖSCHEN>000000` — erstes Byte sind die zu setzenden, zweites die zu
     * löschenden Bits. Damit lässt sich ein einzelnes Bit ändern, ohne die übrigen zu kennen.
     * Am Gerät für alle fünf Bits in beide Richtungen belegt.
     */
    public static function encodeOptions(int $set, int $clear = 0): string
    {
        return '#' . sprintf('%02X%02X', $set & 0xFF, $clear & 0xFF) . '000000';
    }

    /** Bequemer Schalter für ein einzelnes Bit. */
    public static function encodeOptionSwitch(int $bit, bool $on): string
    {
        return $on ? self::encodeOptions($bit, 0) : self::encodeOptions(0, $bit);
    }

    /**
     * Tastensperre: dreistufig, die beiden Bits schließen einander aus.
     * Beim Setzen der einen Stufe wird die andere ausdrücklich gelöscht — genau so macht
     * es die Hersteller-App (`#0804000000` für „plus").
     */
    public static function encodeKeyLock(int $level): string
    {
        switch ($level) {
            case self::LOCK_ON:
                return self::encodeOptions(self::OPT_LOCK, self::OPT_LOCK_PLUS);
            case self::LOCK_PLUS:
                return self::encodeOptions(self::OPT_LOCK_PLUS, self::OPT_LOCK);
            default:
                return self::encodeOptions(0, self::OPT_LOCK | self::OPT_LOCK_PLUS);
        }
    }

    /** Leitet die Sperrstufe aus dem Optionen-Byte ab. */
    public static function keyLockLevel(int $options): int
    {
        if ($options & self::OPT_LOCK_PLUS) {
            return self::LOCK_PLUS;
        }
        return ($options & self::OPT_LOCK) ? self::LOCK_ON : self::LOCK_OFF;
    }

    /**
     * Temperatur-Offset kodieren. Gleiche Halbierung wie bei Temperaturen, mit Vorzeichen
     * im Zweierkomplement — negative Werte sind bislang NICHT am Gerät geprüft.
     */
    public static function encodeOffset(float $kelvin): string
    {
        $halves = (int) round(max(-6.0, min(6.0, $kelvin)) * 2);
        return '#' . sprintf('%02X', $halves & 0xFF);
    }

    /** Temperatur-Offset lesen. */
    public static function decodeOffset(string $payload): ?float
    {
        if (!self::isPlausible($payload)) {
            return null;
        }
        $body = substr($payload, 1);
        if (!ctype_xdigit($body)) {
            return null;
        }
        $raw = (int) hexdec($body);
        if ($raw > 127) {          // Zweierkomplement
            $raw -= 256;
        }
        return (float) ($raw / 2);
    }
}
