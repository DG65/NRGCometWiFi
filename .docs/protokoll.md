# Comet-WiFi-Protokoll — Stand des Reverse Engineering

**Es gibt keine Herstellerdokumentation.** Alles hier stammt aus eigenen Mitschnitten oder aus
Foren-Beiträgen Dritter. Jede Zeile trägt deshalb einen **Status** und eine **Quelle**. Wer
etwas Neues entschlüsselt, trägt es **zuerst hier** ein und erst dann in den Code.

Statuswerte:

| Status | Bedeutung |
|---|---|
| ✅ verifiziert | Am eigenen Gerät beobachtet und in der Bedeutung bestätigt |
| 🟡 vermutet | Plausible Deutung, aber nicht gegengeprüft — **nicht in dekodierten Code gießen** |
| ❓ unbekannt | Register existiert, Bedeutung offen |

## Verbindung

| Eigenschaft | Wert | Status | Quelle |
|---|---|---|---|
| Broker (ab Werk) | `mqtt.eurotronic.io` … `mqtt3.eurotronic.io`, Port **1883, unverschlüsselt** | ✅ | tcpdump 04.08.2026 |
| Broker-IP (echt) | `172.104.138.107` (alle vier Namen) | ✅ | `dig @1.1.1.1` 04.08.2026 |
| Broker konfigurierbar? | **Nein** — nur per DNS-Umleitung erreichbar | ✅ | Gerätemenü, Foren |
| Protokollversion | MQTT v3.1.1 | ✅ | tcpdump CONNECT |
| Benutzername | `AABBCCDD` (8 Zeichen) — **für alle Geräte identisch** | ✅ | tcpdump CONNECT |
| Passwort | 16 Zeichen — **für alle Geräte identisch** | ✅ | tcpdump CONNECT |
| Client-ID | `da16x02` + Benutzername + letzte 3 MAC-Bytes, z. B. `da16x02AABBCCDDD4E5F6` | ✅ | tcpdump CONNECT, 9 Geräte |
| Keepalive | 600 s | ✅ | tcpdump CONNECT |
| Clean Session | **0** (persistente Sitzung) | ✅ | tcpdump CONNECT |
| QoS der Geräte-Publishes | 2 | ✅ | mosquitto-Log |
| Last Will | Topic `…/V/XX`, Payload `#COMM-LOSS`, QoS 2, Retain 0 | ✅ | tcpdump CONNECT |

Die Foren-Angabe, Benutzername und Passwort ließen sich aus einem mit `7MQTT_` beginnenden
String der App extrahieren (letzte 16 Zeichen Passwort, 8 davor Benutzername), hat sich **nicht
als nötig erwiesen** — beide stehen im Klartext im CONNECT-Paket des Geräts selbst, weil
Port 1883 unverschlüsselt ist. Das ist der deutlich einfachere Weg (kein Handy-Mitschnitt).

## Topic-Aufbau

```
<PREFIX>/<BENUTZER>/<MAC>/<RICHTUNG>/<REGISTER>
   02   / AABBCCDD / A1B2C3D4E5F6 /  V   /   A0
```

| Segment | Bedeutung | Status |
|---|---|---|
| `02` | Protokollpräfix, bei allen Geräten gleich | ✅ |
| `<BENUTZER>` | Kontosegment; `FFFFFFFF` = Broadcast/Dienst | ✅ |
| `<MAC>` | 12 Hex-Zeichen, Großschreibung, ohne Trenner | ✅ |
| `V` | **V**alue — Gerät sendet Zustand | ✅ |
| `S` | **S**et — Kommando an das Gerät | ✅ (Richtung), 🟡 (Kodierung, siehe A0) |
| `G` | **G**roup — Sync zwischen gekoppelten Geräten eines Raums | 🟡 |
| `T` | **T**ime — Zeitsynchronisation (nur Broadcast) | 🟡 |

Beobachtete Abonnements eines Geräts direkt nach CONNECT:

```
02/AABBCCDD/<eigene MAC>/S/#          eigene Kommandos
02/FFFFFFFF/000000000004/T/B7         Zeitsynchronisation (feste Pseudo-MAC!)
02/FFFFFFFF/<eigene MAC>/S/AF         Datenabruf über Broadcast-Präfix
+/AABBCCDD/<MAC eines Partners>/G/#   nur bei gekoppelten Geräten
```

Die feste Pseudo-MAC `000000000004` im Zeitsync-Topic ist bemerkenswert: Sie ist bei allen
Geräten identisch, also ein echter Broadcast-Kanal.

## Nutzdaten

Alle beobachteten Payloads sind ASCII mit führendem `#`.

| Kodierung | Beispiel | Umrechnung | Status |
|---|---|---|---|
| Temperatur | `#2C` → 22,0 °C · `#39` → 28,5 °C | `hexdec(...) / 2`, Schrittweite 0,5 K | ✅ |
| Signalstärke | `#-45` → −45 dBm | `intval(...)` — **dezimal mit Vorzeichen, nicht hex** | ✅ |
| Textmarken | `#COMM-TEST`, `#COMM-LOSS` | wörtlich | ✅ |
| Zeitstempel | `#23.03.09-09:00` | `JJ.MM.TT-HH:MM`, UTC | 🟡 |

Dass Signalstärke dezimal, Temperatur aber hex kodiert ist, ist **kein Tippfehler** — die
Kodierung ist je Register verschieden und muss einzeln bestimmt werden.

## Register

### Gelesen (`V/`)

| Reg. | Bedeutung | Beispiel | Status | Quelle |
|---|---|---|---|---|
| `A0` | Solltemperatur | `#39` = 28,5 °C | ✅ | live, 9 Geräte |
| `A1` | Isttemperatur | `#2C` = 22,0 °C | ✅ | live |
| `A2` | Temperatur-Offset (Kalibrierung) | — | 🟡 | Foren; Vorzeichenkodierung **ungeprüft** |
| `A3` | Optionen-Bitfeld: Tastensperre / Sommerzeit / Displaydrehung | `#200700` `#220500` `#230400` `#270000` | 🟡 | Foren; Bitzuordnung **nicht reproduziert** |
| `A4` | unbekannt | 11-Byte-Payload | ❓ | live 04.08.2026 |
| `A5` | Fenster-offen-Erkennung + Empfindlichkeit | `#140A` / `#040A`, Nibbles `#X80` `#X08` `#X0C` | 🟡 | Foren; Empfindlichkeits-Schema unstimmig |
| `A6` | Batteriestand in Prozent | `#5F` = 95 % | ✅ | live, 10 Geräte 05.08.2026 |
| `A7` | Urlaubsmodus | — | 🟡 | Foren |
| `A8`–`AE` | Wochenprogramm (7 Tage) | — | ❓ | Foren; Format nicht dekodiert |
| `AF` | Antwort auf Datenabruf | `#0014` | 🟡 | live |
| `B3` | WLAN-Signalstärke | `#-45` | ✅ | live |
| `BB` | unbekannt | 3-Byte-Payload | ❓ | live 04.08.2026 |
| `BD` | unbekannt, wechselt | `#0806` / `#0800` | ❓ | live 04.08.2026 |
| `XX` | Verbindungszustand | `#COMM-TEST`, `#COMM-LOSS` | ✅ | live + Last Will |

### Geschrieben (`S/`)

| Reg. | Bedeutung | Payload | Status |
|---|---|---|---|
| `A0` | Solltemperatur setzen | `#` + **zwei Hex-Ziffern in Großschreibung**, Temp × 2 | ✅ |
| `AF` | Datenabruf auslösen | `#0B` aktuelle Temperaturen · `#FFFFFFFF` alle Felder · `#4B` periodisch · `#48000000` Batterie/Sperre/Sommerzeit/Drehung | 🟡 | Foren |
| `XX` | Verbindungstest | `#COMM-TEST` | ✅ | live (Gerät sendet selbst) |

### Gruppe (`G/`)

| Reg. | Bedeutung | Status |
|---|---|---|
| `B5` | Sync zwischen gekoppelten Geräten desselben Raums | 🟡 |

## Offene Fragen

1. ~~**Schreibkodierung `S/A0`**~~ — **erledigt am 05.08.2026.** In der Hersteller-App
   21,5 °C gestellt und das über die Bridge hereinkommende Kommando mitgeschnitten:

   ```
   02/AABBCCDD/A1B2C3D4E5BB/S/A0 #2B
   ```

   21,5 × 2 = 43 = `0x2B`. Damit ist belegt: dieselbe Kodierung wie beim Lesen,
   **Großbuchstaben**, **zwei Stellen** (das Mosquitto-Log zeigte zuvor bereits „3 bytes",
   also `#` + 2 Zeichen), keine Zusatzbytes. Die Cloud sendet mit QoS 0 und `retain=0`.
   Wir senden bewusst mit QoS 1 (die Geräte haben `cleanSession=0`, der Broker hält die
   Nachricht damit für ein schlafendes Gerät vor) — `retain` bleibt wie bei der Cloud aus.
2. ~~**`A6` hex oder dezimal?**~~ — **erledigt am 05.08.2026.** Hex bewiesen: Über zehn
   Geräte hinweg erscheinen Werte wie 85 %, 90 %, 95 % und 100 %. Als Dezimalzahlen gelesen
   wären das die Payloads `#85`/`#90`/`#95` — die Umschaltung im Formular bleibt trotzdem
   stehen, falls eine andere Firmware es anders hält.
3. **Ventilstellung** — in keinem Register gefunden. Die Zigbee-Variante (SPZB0001) liefert
   sie, das WiFi-Modell offenbar nicht. Nicht erfinden.
4. **Realer Sollwertbereich** — `#39` = 28,5 °C wurde live gesehen. Ob es Sonderwerte für
   „Aus"/„Boost" außerhalb des Bereichs gibt, ist offen.
5. **Retained-Verhalten der `V/`-Topics** — unbekannt. Entscheidet, ob eine frisch angelegte
   Instanz sofort Werte sieht oder bis zur nächsten Gerätemeldung wartet.
6. **Gibt es einen Sammel-Datenabruf?** Analog zu `02/FFFFFFFF/000000000004/T/B7` wäre ein
   `…/000000000004/S/AF` denkbar, mit dem sich alle Geräte auf einmal abfragen ließen. Nicht
   beobachtet, nicht getestet.

## Die eigenen Geräte finden

Die MAC-Adressen der eigenen Thermostate stehen im Verbindungsaufbau am Broker — die Client-ID
hat den Aufbau `da16x02` + Benutzername + letzte drei MAC-Bytes. Mit Mosquitto:

```bash
docker logs <broker-container> | grep "New client connected"
```

Der Konfigurator nimmt einem diese Arbeit ab; von Hand braucht man sie nur, wenn man eine
Instanz ohne ihn anlegen möchte.

Alle bislang gesehenen Geräte tragen ein MAC-Präfix von Dialog Semiconductor — das ist der
WLAN-Chip, nicht Eurotronic. Als Erkennungsmerkmal taugt es deshalb nur schwach.

## Fremdquellen

- [ioBroker: „Eurotronic Comet WiFi — funktioniert doch!?!?"](https://forum.iobroker.net/topic/69372/eurotronic-comet-wifi-funktioniert-doch)
- [Home Assistant: „Integrating Comet Wifi basic functionality"](https://community.home-assistant.io/t/integrating-comet-wifi-basic-functionality/493474)
- [comet_wifi_integration (Home Assistant, GitHub)](https://github.com/marcinkordas/comet_wifi_integration)

Die Register `A2`, `A3`, `A5`, `A7`, `A8`–`AE` stammen ausschließlich aus diesen Quellen
(Stand 2023) und sind hier bewusst als 🟡/❓ geführt, bis sie am eigenen Gerät bestätigt sind.
