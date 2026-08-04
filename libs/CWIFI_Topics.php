<?php

declare(strict_types=1);

/**
 * Topic-Aufbau und MAC-Normalisierung für Comet-WiFi-Geräte.
 *
 * Topic-Schema:  <PREFIX>/<BENUTZER>/<MAC>/<RICHTUNG>/<REGISTER>
 * Beispiel:      02/AABBCCDD/A1B2C3D4E5F6/V/A0
 *
 * Siehe docs/protokoll.md für die Herleitung.
 */
class CWIFI_Topics
{
    /** Pseudo-MAC des Broadcast-Kanals für die Zeitsynchronisation (bei allen Geräten gleich). */
    public const TIMESYNC_MAC = '000000000004';

    /** Benutzersegment für Broadcast-/Dienst-Topics. */
    public const BROADCAST_USER = 'FFFFFFFF';

    /**
     * Bringt eine MAC auf die kanonische Form: 12 Hex-Zeichen, Großschreibung, ohne Trenner.
     *
     * MUSS an jeder Grenze angewandt werden (Property lesen, Topic zerlegen, vergleichen) —
     * sonst führt ein eingetippter Doppelpunkt oder Kleinschreibung zu einem stillen
     * Nicht-Treffer beim Instanz-Abgleich.
     *
     * @return string Kanonische MAC oder '' wenn ungültig.
     */
    public static function normalizeMac(string $mac): string
    {
        $clean = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
        return (strlen($clean) === 12) ? $clean : '';
    }

    /** Formatiert eine MAC für die Anzeige: A1:B2:C3:D4:E5:F6 */
    public static function formatMac(string $mac): string
    {
        $clean = self::normalizeMac($mac);
        if ($clean === '') {
            return '';
        }
        return implode(':', str_split($clean, 2));
    }

    /**
     * Basis-Topic eines Geräts (ohne Richtung/Register, ohne abschließenden Schrägstrich).
     * Wird immer abgeleitet, nie als Property gespeichert.
     */
    public static function base(string $prefix, string $user, string $mac): string
    {
        return $prefix . '/' . $user . '/' . self::normalizeMac($mac);
    }

    /** Topic, auf dem das Gerät seine Zustände veröffentlicht. */
    public static function value(string $prefix, string $user, string $mac, string $register): string
    {
        return self::base($prefix, $user, $mac) . '/V/' . strtoupper($register);
    }

    /** Topic, über das das Gerät Kommandos entgegennimmt. */
    public static function set(string $prefix, string $user, string $mac, string $register): string
    {
        return self::base($prefix, $user, $mac) . '/S/' . strtoupper($register);
    }

    /** Broadcast-Topic der Zeitsynchronisation — gilt für ALLE Geräte im Netz. */
    public static function timeSync(string $prefix): string
    {
        return $prefix . '/' . self::BROADCAST_USER . '/' . self::TIMESYNC_MAC . '/T/B7';
    }

    /**
     * Client-ID, mit der sich das Gerät selbst am Broker anmeldet.
     * Aufbau: 'da16x02' + Benutzername + letzte 3 MAC-Bytes (6 Hex-Zeichen).
     *
     * Nur zur Anzeige/Diagnose — erlaubt den direkten Abgleich einer Formularzeile mit dem
     * Broker-Protokoll.
     */
    public static function clientId(string $user, string $mac): string
    {
        $clean = self::normalizeMac($mac);
        if ($clean === '') {
            return '';
        }
        return 'da16x02' . $user . substr($clean, 6);
    }

    /**
     * Zerlegt ein empfangenes Topic relativ zum Basis-Topic.
     *
     * @return array{0:string,1:string}|null [Richtung, Register] oder null, wenn das Topic
     *                                       nicht zu diesem Gerät gehört bzw. nicht dem
     *                                       erwarteten Aufbau entspricht.
     */
    public static function split(string $topic, string $base): ?array
    {
        $prefix = $base . '/';
        if (strncmp($topic, $prefix, strlen($prefix)) !== 0) {
            return null;
        }
        $parts = explode('/', substr($topic, strlen($prefix)));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        return [strtoupper($parts[0]), strtoupper($parts[1])];
    }

    /**
     * Liest das MAC-Segment aus einem Topic mit bekanntem Prefix/Benutzer heraus.
     * Für den Konfigurator, der alle Geräte gleichzeitig mithört.
     *
     * @return string Kanonische MAC oder '' wenn das Topic nicht passt.
     */
    public static function macFromTopic(string $topic, string $prefix, string $user): string
    {
        $head = $prefix . '/' . $user . '/';
        if (strncmp($topic, $head, strlen($head)) !== 0) {
            return '';
        }
        $rest = substr($topic, strlen($head));
        $slash = strpos($rest, '/');
        if ($slash === false) {
            return '';
        }
        return self::normalizeMac(substr($rest, 0, $slash));
    }

    /**
     * Baut den regulären Ausdruck für SetReceiveDataFilter().
     *
     * Zwei Eigenheiten, die schon Zeit gekostet haben:
     *  - Der Filter arbeitet auf dem rohen JSON-Datenpaket, dort sind die Schrägstriche
     *    escaped ("02\/AABBCCDD\/...").
     *  - Ohne den Anker '"Topic":"' könnte auch ein Payload-Inhalt zufällig zutreffen.
     *
     * @param string $topicStart Topic-Anfang, auf den gefiltert werden soll.
     * @param bool   $exactChild true = nur direkte Unter-Topics (Basis-Topic eines Geräts),
     *                           false = alles, was mit diesem Anfang beginnt (Konfigurator).
     */
    public static function receiveFilter(string $topicStart, bool $exactChild): string
    {
        // preg_quote OHNE Trennzeichen-Argument: Schrägstriche bleiben unangetastet und
        // werden anschließend gezielt ersetzt. Mit '/' als Trennzeichen käme '\/' heraus,
        // was die folgende Ersetzung zu '\\\/' verstümmeln würde.
        $quoted = str_replace('/', '\\\\/', preg_quote($topicStart));
        return '.*"Topic":"' . $quoted . ($exactChild ? '\\\\/' : '') . '.*';
    }

    /** Filter, der nie zutrifft — für unvollständig konfigurierte Instanzen. */
    public static function blockingFilter(): string
    {
        return '(?!)';
    }
}
