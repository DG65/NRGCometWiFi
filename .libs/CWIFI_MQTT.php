<?php

declare(strict_types=1);

/**
 * Gemeinsamer MQTT-Sendepfad für Thermostat und Konfigurator.
 *
 * Die beiden GUIDs sind die Datenschnittstellen des MQTT-Kernmoduls und auf MQTT Client wie
 * MQTT Server identisch — deshalb kann der Nutzer frei wählen, unter welchem der beiden die
 * Instanzen hängen.
 */
trait CWIFI_MQTT
{
    /** Datenschnittstelle „senden" (Publish an den Broker). */
    private static $CWIFI_MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    /** Datenschnittstelle „empfangen" (Publish vom Broker). */
    private static $CWIFI_MQTT_RX = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    /**
     * Veröffentlicht eine Nachricht über die übergeordnete MQTT-Instanz.
     *
     * ⚠️ RETAIN MUSS FALSE BLEIBEN — jedenfalls für alles unter S/.
     *    Ein retaintes Kommando stellt der Broker bei JEDEM Reconnect erneut zu. Das Gerät
     *    würde damit dauerhaft auf den alten Sollwert gezwungen und jede Änderung aus der
     *    Hersteller-App oder dem geräteeigenen Wochenprogramm wieder überschrieben.
     *
     * QoS 0, wie es auch die Hersteller-Cloud selbst verwendet (im Broker-Protokoll als `q0`
     * zu sehen). Eine frühere Fassung sendete mit QoS 1 — theoretisch besser, weil die
     * Thermostate mit `cleanSession=0` verbunden sind und der Broker Nachrichten ab QoS 1
     * für ein schlafendes Gerät vorhält. Praktisch nahm Symcons MQTT-Instanz das Paket dann
     * aber nicht an: `SendDataToParent()` lieferte `false`, es ging nichts hinaus, und weil
     * der Fehler nur im Debug-Fenster landete, war davon nirgends etwas zu sehen.
     */
    private function sendMQTT(string $topic, string $payload, int $qos = 0, bool $retain = false): bool
    {
        if (!$this->HasActiveParent()) {
            $this->reportSendFailure($topic, 'keine aktive MQTT-Instanz darüber');
            return false;
        }

        $packet = [
            'DataID'           => self::$CWIFI_MQTT_TX,
            'PacketType'       => 3,           // PUBLISH
            'QualityOfService' => $qos,
            'Retain'           => $retain,
            'Topic'            => $topic,
            'Payload'          => $payload
        ];

        $json = json_encode($packet, JSON_UNESCAPED_SLASHES);
        $this->SendDebug(__FUNCTION__, $json, 0);

        $result = $this->SendDataToParent($json);
        if ($result === false) {
            $this->reportSendFailure($topic, 'die übergeordnete MQTT-Instanz hat das Paket abgelehnt');
            return false;
        }
        return true;
    }

    /**
     * Meldet einen fehlgeschlagenen Sendeversuch sichtbar.
     *
     * Bewusst zusätzlich ins Meldungsprotokoll und nicht nur ins Debug-Fenster: Ein still
     * verworfener Sollwert ist der unangenehmste Fehler dieses Moduls — der Nutzer stellt
     * einen Wert ein, die Variable übernimmt ihn, und nichts passiert. Genau so blieb eine
     * abgelehnte QoS-1-Nachricht unbemerkt.
     */
    private function reportSendFailure(string $topic, string $reason): void
    {
        $message = 'Senden fehlgeschlagen (' . $topic . '): ' . $reason;
        $this->SendDebug('sendMQTT Fehler', $message, 0);
        $this->LogMessage($message, KL_ERROR);
    }

    /*
     * Ob eine aktive MQTT-Instanz darüber hängt, beantwortet IPSModule::HasActiveParent()
     * bereits selbst — hier bewusst NICHT nachgebaut.
     *
     * Ein eigenes hasActiveParent() hat das Modul zunächst unbrauchbar gemacht: PHP
     * behandelt Methodennamen case-insensitiv, die Eigenbau-Fassung kollidierte also mit
     * der geerbten HasActiveParent() und verengte deren Sichtbarkeit von protected auf
     * private. Ergebnis war ein Fatal Error beim Laden der Bibliothek, worauf Symcon beide
     * Module verwarf: Die Bibliothek erschien in der Verwaltung, im Objektbaum ließ sich
     * aber keine Instanz anlegen. Sichtbar war das ausschließlich in Symcons eigener
     * Logdatei, nicht im Meldungsprotokoll der Konsole.
     */
}
