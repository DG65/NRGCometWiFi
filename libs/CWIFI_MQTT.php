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
     * QoS 1 statt 0 ist Absicht: Die Thermostate verbinden mit cleanSession=0, der Broker
     * hält also eine persistente Sitzung für sie vor und stellt Nachrichten ab QoS 1 beim
     * nächsten Verbindungsaufbau nach. Eine QoS-0-Nachricht an ein gerade schlafendes Gerät
     * verwirft er dagegen stillschweigend. Die Kommandos sind idempotent, eine doppelte
     * Zustellung schadet daher nicht.
     */
    private function sendMQTT(string $topic, string $payload, int $qos = 1, bool $retain = false): bool
    {
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

        $result = @$this->SendDataToParent($json);
        if ($result === false) {
            $error = error_get_last();
            $this->SendDebug(__FUNCTION__ . ' Fehler', $error['message'] ?? 'unbekannt', 0);
            return false;
        }
        return true;
    }

    /**
     * Hängt eine aktive MQTT-Instanz über uns?
     *
     * Ohne verbundenen Elternteil läuft zwar alles fehlerfrei durch, es kommt aber nie etwas
     * an — ein Zustand, den der Nutzer sonst nicht erklärt bekommt.
     */
    private function hasActiveParent(): bool
    {
        $parentId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentId === 0) {
            return false;
        }
        return IPS_GetInstance($parentId)['InstanceStatus'] === IS_ACTIVE;
    }
}
