# NRG-Stack CometWiFi

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.17.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Bindet **Eurotronic Comet WiFi** Heizkörperthermostate in IP-Symcon ein — ohne Umweg über die
Hersteller-Cloud, aber ohne die Hersteller-App zu verlieren.

Teil des **NRG-Stack** — welche Modulstände zusammenpassen, steht im
[Kompatibilitäts-Manifest](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).

> ⚠️ **Reines Reverse Engineering.** Eurotronic dokumentiert das Protokoll nicht. Dieses Modul
> stützt sich auf eigene Mitschnitten und Foren-Wissen. Ein Firmware-Update des Herstellers
> kann es jederzeit brechen. Was genau als gesichert gilt und was nicht, steht in
> [`.docs/protokoll.md`](.docs/protokoll.md).

## Enthaltene Module

| Modul | Zweck |
|---|---|
| **Comet WiFi Thermostat** | Eine Instanz je Thermostat: Ist-/Solltemperatur, Batterie, Signalstärke, Erreichbarkeit |
| **Comet WiFi Konfigurator** | Hört passiv mit und listet alle gefundenen Thermostate zum Anlegen per Klick |

## Voraussetzung: ein lokaler MQTT-Broker

Die Thermostate sprechen **ausschließlich** mit `mqtt.eurotronic.io` — der Broker ist im Gerät
nicht einstellbar. Damit Symcon sie sieht, muss dieser Name im eigenen Netz auf einen eigenen
Broker zeigen. Das Modul selbst richtet das **nicht** ein; es setzt einen funktionierenden
Broker voraus.

### Einrichtung in drei Schritten

**1. Zugangsdaten auslesen.** Alle Comet-WiFi-Geräte eines Kontos nutzen dieselben
MQTT-Zugangsdaten. Sie stehen im Klartext im Verbindungsaufbau, weil Port 1883 unverschlüsselt
ist — ein Mitschnitt genügt:

```bash
sudo tcpdump -i <schnittstelle> -n -w comet.pcap port 1883
# Thermostat kurz stromlos machen (Batterie raus/rein), danach Strg+C
tshark -r comet.pcap -Y "mqtt.msgtype==1" -V | grep -E "Client ID|User Name|Password"
```

**2. Eigenen Broker aufsetzen** (z. B. Mosquitto) mit genau diesen Zugangsdaten in der
Passwortdatei. Damit die Hersteller-App weiter funktioniert, braucht es **pro Gerät** eine
Bridge zur echten Cloud mit der jeweils echten Client-ID:

```
connection eurotronic-bridge-<name>
address mqtt.eurotronic.io:1883
remote_username <BENUTZER>
remote_password <PASSWORT>
remote_clientid da16x02<BENUTZER><letzte-3-MAC-Bytes>
try_private false
cleansession true
bridge_protocol_version mqttv311
topic 02/<BENUTZER>/<MAC>/V/# out 0
topic 02/<BENUTZER>/<MAC>/S/# in 0
topic 02/FFFFFFFF/# in 0
```

Die **Richtungsangaben sind wichtig**: Ohne sie trägt die Bridge auch die von Symcon
gesendeten Kommandos zur Cloud hinaus, und App und Symcon überschreiben sich gegenseitig.

Eine gemeinsame Bridge für alle Geräte reicht **nicht** — Eurotronics Backend bindet den
Online-Status offenbar an die Client-ID des einzelnen Geräts. Mit nur einer Bridge bleibt in
der App genau ein Gerät online und der Rest erscheint offline.

Auf dem Broker-Rechner selbst müssen die echten Cloud-Adressen in `/etc/hosts` stehen,
sonst zeigt die DNS-Umleitung die Bridge auf sich selbst zurück:

```
172.104.138.107   mqtt.eurotronic.io mqtt1.eurotronic.io mqtt2.eurotronic.io mqtt3.eurotronic.io
```

**3. DNS umbiegen.** In Pi-hole, AdGuard, dnsmasq oder Unbound je einen lokalen Eintrag für
`mqtt.eurotronic.io`, `mqtt1`, `mqtt2` und `mqtt3` auf die IP des eigenen Brokers. Danach die
Thermostate einmal stromlos machen, damit sie den Namen neu auflösen.

## Einrichtung in IP-Symcon

1. **MQTT Client** (Kernmodul) anlegen, auf den eigenen Broker zeigen lassen, mit denselben
   Zugangsdaten. Die Client-ID darf **nicht** mit `da16x02…` beginnen, sonst wirft der Broker
   das gleichnamige Thermostat hinaus.
2. **Comet WiFi Konfigurator** als Kind dieses MQTT Clients anlegen. Er sammelt die Geräte,
   sobald sie senden.
3. Im Konfigurator je Thermostat auf „Erstellen" klicken.

Geräte erscheinen erst, wenn sie von sich aus senden — das kann Minuten bis Stunden dauern.
Der Konfigurator fragt bewusst **nichts** aktiv ab (siehe Batteriehinweis).

## 🔋 Batterie — bitte lesen

Aus dem ioBroker-Umfeld wird nach einer vergleichbaren Umleitung **Batterieausfall binnen etwa
zwei Wochen** berichtet. Die Ursache ist nicht bewiesen; naheliegend ist, dass häufiges
Abfragen die Geräte aus dem Sparbetrieb weckt.

Dieses Modul geht deshalb defensiv vor:

- Die aktive Abfrage ist **ab Werk ausgeschaltet.** Die Thermostate senden von selbst.
- Wird sie eingeschaltet, gilt ein Mindestabstand von 15 Minuten, und die Abfragen der
  einzelnen Geräte werden gegeneinander versetzt.
- Es wird **nicht** beim Start von Symcon oder beim Öffnen des Formulars abgefragt.
- Der Konfigurator sendet überhaupt nichts.

Der Batteriestand wird als Variable geführt — wer ihn archiviert, sieht nach einigen Wochen
selbst, ob die eigene Einstellung vertretbar ist.

## Was das Modul (noch) nicht kann

Nur Register, deren Bedeutung **belegt** ist, werden als Variable geführt. Für alles andere
gibt es auf Wunsch Rohvariablen, aber keine erfundene Deutung:

- **Wochenprogramm** (`A8`–`AE`) — Format nicht dekodiert
- **Fenster-offen-Erkennung** (`A5`), **Urlaubsmodus** (`A7`) — nur teilweise verstanden
- **Tastensperre / Sommerzeit / Displaydrehung** (`A3`) — Bitzuordnung nicht reproduzierbar;
  ein falsch gesetztes Bit würde die Tasten eines Geräts sperren
- **Temperatur-Offset** (`A2`) — Vorzeichenkodierung ungeprüft
- **Ventilstellung** — existiert bei diesem Modell offenbar nicht

Wer die Rohdatenerfassung einschaltet und ein paar Wochen archiviert, liefert genau das
Material, mit dem sich diese Register nachträglich entschlüsseln lassen.

## Bekannte Einschränkungen

- **Ein gesetzter Sollwert ist nicht dauerhaft.** Das Wochenprogramm läuft im Gerät weiter und
  überschreibt ihn beim nächsten Schaltpunkt — ebenso die Hersteller-App. Das ist kein Fehler
  des Moduls, sondern Verhalten des Geräts.
- **Erreichbarkeit ist grobkörnig.** Das Gerät meldet sich mit Keepalive 600 s; ein Ausfall
  fällt frühestens nach etwa 15 Minuten auf.
- **Ohne Internet keine App und keine Uhrzeit.** Die Cloud-Bridge braucht Internet. Fällt sie
  dauerhaft weg, kann der Konfigurator die Zeitsynchronisation optional selbst übernehmen.

## Lizenz

[PolyForm Noncommercial License 1.0.0](LICENSE) — privat und nicht-kommerziell frei,
gewerbliche Nutzung lizenzpflichtig (Kontakt: DG65). Spenden willkommen.
