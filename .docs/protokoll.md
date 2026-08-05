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
| `A2` | Temperatur-Offset, Wert × 2 | `#02` = +1,0 K | ✅ | App-Mitschnitt 05.08.2026; negative Werte noch ungeprüft |
| `A3` | Optionen-Bitfeld — **vollständig entschlüsselt**, siehe unten | `#2182` | ✅ | App-Mitschnitt 05.08.2026, alle Bits beidseitig |
| `A4` | unbekannt, zählt hoch | `#3B11010114` (5 Byte) | ❓ | Erste Bytes ändern sich im Minutentakt, die letzten drei sind bei allen Geräten gleich. Sah nach einer Geräteuhr aus — die Hersteller-App bietet aber gar keine Zeiteinstellung, und die Cloud sendet nie einen Zeit-Rundruf. Deutung offen. |
| `A5` | Fenster-offen-Erkennung | `#040A`, `#800A` | 🟡 | Werte gesehen, Bedeutung offen — die App überträgt die Einstellung nur verzögert |
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
| `A0` | ⚠️ **Wirkt nur mit direkt folgendem `S/AF #FFFFFFFF`** | siehe unten | ✅ |
| `AF` | Datenabruf auslösen | `#0B` aktuelle Temperaturen · `#FFFFFFFF` alle Felder · `#4B` periodisch · `#48000000` Batterie/Sperre/Sommerzeit/Drehung | 🟡 | Foren |
| `XX` | Verbindungstest | `#COMM-TEST` | ✅ | live (Gerät sendet selbst) |

### Sollwertskala: „Aus" und „An" sind Endanschläge

Das Gerät hat keine reine Temperaturskala. Unterhalb von 8,0 °C rastet es auf **Aus**
(Ventil zu), oberhalb von 28,0 °C auf **An** (Ventil auf). Beides sind gültige Sollwerte und
werden wie Temperaturen kodiert:

| Anzeige | Wert | Payload |
|---|---|---|
| Aus | 7,5 | `#0F` |
| 8,0 °C | 8,0 | `#10` |
| … | … | … |
| 28,0 °C | 28,0 | `#38` |
| An | 28,5 | `#39` |

Von Dietmar am Gerät benannt, `#0F` und `#39` beide am Gerät gesetzt und zurückgemeldet.

**Konsequenz für die Auswertung:** Die vielen `#39` an der Anlage sind keine
28,5-°C-Sollwerte, sondern schlicht „An". Eine frühere Deutung als „sommerliche
Offenstellung" war entsprechend falsch.

### Optionen-Bitfeld A3

**Lesen** (`V/A3`) liefert zwei Byte, z. B. `#2182`. Die Schaltbits sitzen im **oberen** Byte;
das untere blieb über alle Tests unverändert und wird nicht gedeutet.

**Schreiben** (`S/A3`) erfolgt **maskiert**: `#<SETZEN><LÖSCHEN>000000` — erstes Byte sind die
zu setzenden, zweites die zu löschenden Bits. Damit lässt sich ein Bit ändern, ohne die
übrigen zu kennen.

| Bit | Funktion | Ein | Aus |
|---|---|---|---|
| `0x01` | Automatische Sommer-/Winterzeit | `#0100000000` | `#0001000000` |
| `0x02` | Anzeige um 180° drehen | `#0200000000` | `#0002000000` |
| `0x04` | Tastensperre | `#0408000000` | `#0004000000` |
| `0x08` | Tastensperre **plus** | `#0804000000` | `#0008000000` |
| `0x20` | Handbetrieb (Zeitplan aus) | `#2000000000` | `#0020000000` |

Alle fünf am Gerät belegt (05.08.2026), jeweils in **beide** Richtungen geschaltet und gegen
die Zustandsmeldung geprüft. `0x04` und `0x08` schließen einander aus — die App löscht beim
Setzen des einen ausdrücklich das andere, es ist also eine dreistufige Auswahl.

**Handbetrieb ist der wichtigste Schalter:** Läuft der Zeitplan, überschreibt das
Wochenprogramm einen von außen gesetzten Sollwert beim nächsten Schaltpunkt. Live beobachtet:
Beim Einschalten des Zeitplans sprang `V/A0` sofort auf den Programmwert.

### Einen Boost gibt es nicht

In der Hersteller-App nicht vorhanden. Die `0x08`-Befehle, die zunächst danach aussahen,
gehören zur Tastensperre.

### Es gibt keine Ventilstellung

Ein vollständiger Feld-Dump (`S/AF #FFFFFFFF`) liefert `A0`–`AF` und `B0`–`BF`. Jedes Register
ist zugeordnet; keines enthält eine Ventilstellung. Das ist damit kein Rückschluss aus
fehlenden Beobachtungen, sondern eine vollständige Bestandsaufnahme. Die Zigbee-Schwester
SPZB0001 liefert sie, dieses Modell nicht.

### Diagnoseregister B0–BF

Aus dem Voll-Dump, teils direkt lesbar. Die mit ✅ markierten führt das Modul seit 0.5.0 als
lesbare Variable (`Model`, `Firmware`, `IPAddress`, `AccessPoint`, `WifiSecurity`, `Group`) —
sie kommen nur bei einem ohnehin stattfindenden Voll-Dump mit und kosten keine Batterie.

| Reg. | Beispiel | Bedeutung | Status |
|---|---|---|---|
| `B1` | `#436F6D65742057696669205665722E20362E31` | ASCII „Comet Wifi Ver. 6.1" — Firmware | ✅ |
| `B2` | `#322E372E312E30` | ASCII „2.7.1.0" | ✅ |
| `B3` | `#-68` | Signalstärke in dBm | ✅ |
| `B6` | `#00C0A8022A01445F0301` | enthält die IP (`C0A8022A` = 192.168.2.42) | ✅ |
| `BA` | `24:f5:a2:74:7b:ab` | MAC des WLAN-Zugangspunkts | ✅ |
| `BF` | `#5B575041322D50534B2D43434D505D5B4553535D` | ASCII „[WPA2-PSK-CCMP][ESS]" | ✅ |
| `B0` | `#U000000000000` / `#S<MAC>` | Gruppenzuordnung, siehe eigener Abschnitt | ✅ |
| `B4` `B5` `B7` `BB` `BC` | `#00000000` `#FF` `#00` `#00` `#FF` | Konstanten, siehe unten | ❓ |
| `BE` | `#FF6300` / `#FF6400` / `#FF6100` | unbekannt, variiert leicht je Gerät | ❓ |
| `BD` | `#0800` (alle zehn Geräte) | unbekannt. Eine fremde Umsetzung deutet Byte 1 als Batterie (Skala 0–8) — hier widerlegt, siehe Abgleich | ❓ |

**Bestandsaufnahme über zehn Geräte (05.08.2026).** `B4`, `B5`, `B7`, `BB` und `BC` tragen auf
allen zehn Geräten denselben Wert. Sie können damit weder einen Gerätezustand noch eine
Einstellung abbilden — was immer sie bedeuten, sie sind für dieses Modul wertlos. Nicht
weiter verfolgen. `BE` variiert dagegen leicht (Byte 2 zwischen `0x61` und `0x64`), ist also
gerätespezifisch und bleibt einen Blick wert.

### `A4` — Uhr des Geräts ✅

Format `MM HH TT MM JJ`, dieselbe Reihenfolge wie `A7`. Byte 1 ist die Minute, Byte 2 die
Stunde.

**Am Gerät belegt (05.08.2026).** Zwei Messungen desselben Thermostats:

| Empfangen | Payload | ergibt |
|---|---|---|
| 17:51:42 | `#2112010114` | 18:33 |
| 21:11:39 | `#3515010114` | 21:53 |

Verstrichen sind 3 h 19 min 57 s, der Wert nahm um 3 h 20 min zu — auf drei Sekunden genau.
Gegenprobe über zehn Geräte: Jedes lieferte eine gültige Uhrzeit, und die Abweichung zur
echten Zeit blieb je Gerät über mehr als drei Stunden auf ±1 Minute stabil.

Damit ist auch die frühere Rücknahme erledigt: `A4` **ist** eine Uhr. Der damalige Schluss
war falsch, weil er aus einem misslungenen Stellversuch gezogen wurde — dass sich die Uhr
über den erprobten Kanal nicht stellen ließ, sagt nichts darüber, ob es eine ist.

**Die praktische Bedeutung ist erheblich.** Die Uhren laufen richtig, stehen aber falsch:

| Gerät | Abweichung |
|---|---|
| fünf Geräte | +41 bis +43 min |
| zwei Geräte | +24 bis +25 min |
| je eines | +31 min · +60 min |
| eines | **+9 h 24 min** |

Das Wochenprogramm läuft **im Gerät**, nicht in Symcon. Jeder Schaltpunkt feuert um genau
diese Spanne zu früh. Wer die Heizzeiten für falsch hält, sollte deshalb zuerst hierher
sehen. Das Modul führt die Abweichung seit 0.6.0 als eigene Variable.

**Stellen: `S/A4` mit derselben Kodierung.** Naheliegend, seit die Leserichtung belegt ist —
der frühere, wirkungslose Versuch lief über den Rundruf `02/FFFFFFFF/000000000004/T/B7`, also
über ein ganz anderes Register. Das Modul bietet den Befehl ab 0.7.0 als Knopf an und fordert
die Uhr danach zurück; ob das Gerät ihn annimmt, entscheidet allein diese Rückmeldung.

Nimmt es ihn nicht an, bleibt nur, auf das Wochenprogramm im Gerät zu verzichten und aus
Symcon heraus zu schalten — dort stimmt die Uhr.

Die letzten drei Byte sind auf allen Geräten `01 01 14` und bewegen sich nicht, obwohl die
Uhren längst über Mitternacht gelaufen sind. Als Datum gelesen wäre das der 1. Januar 2020 —
bewiesen ist das nicht, und für die Uhrzeit spielt es keine Rolle.

### `A5` — Empfindlichkeit der Lüftungserkennung 🟡

Zwei Byte, das zweite auf allen zehn Geräten `0x0A`. Das erste variiert:

| Wert | Geräte |
|---|---|
| `0x04` | acht |
| `0x0C` | Esszimmer |
| `0x80` | Wohnzimmer Rechts |

Ein Bitfeld also, kein Zahlenwert — `0x04` und `0x0C` unterscheiden sich in einem Bit. Das ist
das einzige noch unbekannte Register mit einer sichtbaren Entsprechung in der App.

**Unabhängig bestätigt:** Die Home-Assistant-Anbindung von MaxXLive führt `A5` als
`REG_WINDOW_OPEN`. Die Zuordnung zur Fenster-/Lüftungserkennung stammt damit aus zwei
Richtungen; die Kodierung der Nutzdaten ist dort aber ebenfalls nicht aufgelöst.

**Weg zur Auflösung:** in der App an einem Gerät die Empfindlichkeit der Lüftungserkennung
über alle Stufen stellen und `A5` dabei mitlesen.

### Wochenprogramm A8–AE ✅

Ein Register je Wochentag: `A8` = Montag bis `AE` = Sonntag. Je Schaltpunkt **zwei Byte**,
big-endian:

| Bits | Inhalt |
|---|---|
| 15–6 | Minuten seit **Montag 00:00**, geteilt durch 10 |
| 5–0 | Solltemperatur × 2 |

Die Zeit zählt über die ganze Woche durch, nicht je Tag — deshalb steigen die Werte von `A8`
bis `AE` gleichmäßig an. Die Registerlänge ergibt sich aus der Zahl der Schaltpunkte: vier
ergeben 8 Byte, zwei ergeben 4.

**Gegen die App geprüft** (05.08.2026). `#062C09E4176C1FA4` ergibt:

```
0x062C → (1580>>6)=24  → 240 min  = Mo 04:00   0x2C&0x3F=44 → 22,0 °C
0x09E4 → 39            → 390 min  = Mo 06:30   0x24        → 18,0 °C
0x176C → 93            → 930 min  = Mo 15:30   0x2C        → 22,0 °C
0x1FA4 → 126           → 1260 min = Mo 21:00   0x24        → 18,0 °C
```

Exakt die im Zeitplan angezeigten Schaltzeiten. Ebenso `AD` (Samstag): `0xBEAC` → 762 → 7620
min = 127 h = **Sa 07:00** bei 22,0 °C, `0xD524` → 852 → 142 h = **Sa 22:00** bei 18,0 °C.

Das Modul liest den Plan nur. Schreiben wäre ein eigener Brocken mit Bedienoberfläche.

### Urlaub A7 ✅

Neun Byte: `HH TT MM JJ` für den Beginn, dasselbe für das Ende, dann Solltemperatur × 2.

**Gegen die App geprüft** (05.08.2026): `#0C1F071A 0C10081A 32` entspricht Zeichen für Zeichen
der Anzeige „Beginn 31.7.2026 12:00 — Ende 16.8.2026 12:00 — 25,0 °C".

Neun Byte `FF` bedeuten **kein Urlaub gesetzt** — ein gültiger Zustand, keine Störung. Die
Hersteller-App schickt den Urlaub an alle Geräte des Kontos gleichzeitig; er ist also eine
kontoweite Einstellung, auch wenn jedes Gerät ihn einzeln speichert.

### Gruppen: Register B0 ✅

`B0` sagt, zu welcher Gruppe ein Gerät gehört:

| Wert | Bedeutung |
|---|---|
| `#S` + MAC | gehört zur Gruppe mit diesem Gruppenkopf |
| `#U000000000000` | ungekoppeltes Einzelgerät |

Über zehn Geräte gegen die Raumaufteilung in der App geprüft: Genau die beiden Räume mit zwei
Thermostaten zeigen auf denselben Gruppenkopf, die acht Einzelräume tragen `U`. Das erklärt
auch die `G/`-Topics — sie sind der Abgleich innerhalb einer Gruppe.

**Folge für die Bedienung:** Die App steuert Räume, nicht Geräte. Bei einer Gruppe lässt sich
ein einzelnes Thermostat dort gar nicht ansprechen.

### Ein Sollwert braucht zwei Nachrichten

`S/A0` allein ist **wirkungslos**. Das Gerät verwirft den Wert nicht stillschweigend, sondern
**widerspricht**: Unmittelbar nach dem Empfang meldet es per `V/A0` wieder seinen alten
Sollwert. Erst ein direkt anschließendes `S/AF` mit `#FFFFFFFF` bringt es dazu, den neuen Wert
zu übernehmen und zu bestätigen.

Die Hersteller-Cloud macht es genauso — im Broker-Protokoll stehen beide Nachrichten in
derselben Sekunde:

```
1785939860  S/A0  (3 Byte)      ← Sollwert
1785939860  S/AF  #FFFFFFFF     ← unmittelbar danach
1785939862  V/A0               ← Gerät bestätigt den NEUEN Wert
```

Am eigenen Gerät nachgestellt und belegt (05.08.2026): Ein `S/A0 #22` allein wurde mit
`V/A0 #27` (altem Wert) beantwortet; dieselbe Nachricht plus `S/AF #FFFFFFFF` führte zu
`V/A0 #24` — der Wert war übernommen.

Ebenfalls geprüft und **nicht** die Ursache: Payload (byte-identisch zur Cloud), Topic, QoS
(auch mit QoS 2 abgelehnt), Retain, sowie die Frage, ob eine Zwischenstation stört (direkt am
Broker dasselbe Verhalten).

**Reihenfolge zählt.** Ein `S/AF` *vor* dem `S/A0` genügt nicht — das wurde ebenfalls
getestet und blieb wirkungslos.

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
5. ~~**Retained-Verhalten der `V/`-Topics**~~ — **beantwortet (05.08.2026):** Es gibt keine.
   Eine Bestandsaufnahme über `--retained-only` auf `02/+/+/V/#` ergab null Treffer bei zehn
   Geräten. Eine frisch angelegte Instanz bleibt also leer, bis das Gerät das nächste Mal
   von sich aus sendet — das kann Stunden dauern und ist kein Fehler.
6. **Gibt es einen Sammel-Datenabruf?** Analog zu `02/FFFFFFFF/000000000004/T/B7` wäre ein
   `…/000000000004/S/AF` denkbar, mit dem sich alle Geräte auf einmal abfragen ließen. Nicht
   beobachtet, nicht getestet.

## Retainte Kommandos sind schädlich

`S/`-Topics sind Kommandos. Wird eines **retained** abgelegt, stellt der Broker es bei
**jedem** Reconnect erneut zu — und `S/AF #FFFFFFFF` fordert alle Register an. Ein
Batteriegerät, das ohnehin nur selten aufwacht, macht dann bei jedem Verbindungsaufbau
einen vollständigen Registerdump. Deshalb sendet dieses Modul `S/`-Nachrichten
grundsätzlich mit `retain=false`.

**Bestandsaufnahme (nur lesen):**

```bash
mosquitto_sub -h <broker> -u <benutzer> -P <passwort> -V 5 --retained-only   -t '02/+/+/S/#' -t '02/+/+/T/#' -t '02/+/+/G/#' -v -W 6
```

**Löschen** heißt in MQTT: leere Nachricht mit Retain-Flag auf dasselbe Topic.

```bash
mosquitto_pub -h <broker> -u <benutzer> -P <passwort>   -t '02/<benutzer>/<MAC>/S/AF' -r -n
```

Zwei Nebenwirkungen, beide am 05.08.2026 an zehn Geräten beobachtet und für unbedenklich
befunden:

1. **Die Geräte bekommen die leere Nachricht zugestellt** — sie sind Abonnenten ihrer
   eigenen `S/`-Topics. Im Brokerlog: `Sending PUBLISH to da16x02… (r0, 0 bytes)`. Keines
   der zehn Geräte reagierte darauf; kein Registerdump, kein Verbindungsabbruch. Vermutlich
   verwirft die Firmware alles ohne `#`-Präfix — belegt ist nur, dass nichts passiert.
2. **Bei `topic … both`-Brücken geht das Löschen mit Retain-Flag zur Cloud hinaus**
   (`Sending PUBLISH to local.da16x02… (r1)`). Das ist hier erwünscht: Läge die retainte
   Kopie beim Hersteller, käme sie beim nächsten Brücken-Reconnect sonst zurück.

Der Ursprung der zehn gefundenen `S/AF #FFFFFFFF` ließ sich nicht mehr klären — die
Historie im Brokerlog reichte nicht weit genug zurück. Nach dem Löschen deshalb nach
einigen Tagen die Bestandsaufnahme wiederholen; kommen sie wieder, veröffentlicht sie
jemand fortlaufend und die Ursache muss dort gesucht werden.

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

## Abgleich mit fremden Umsetzungen (05.08.2026)

Zwei Home-Assistant-Anbindungen führen eigene Registertabellen. Der Abgleich bestätigt einiges
und widerlegt zweierlei — Letzteres ist der wichtigere Teil, weil beide Projekte
weiterverbreitet sind als dieses hier.

**Bestätigt:**

| Register | Dort | Hier |
|---|---|---|
| `A0` `A1` `A2` `A3` `A7` `AF` `XX` | gleiche Bedeutung | ✅ |
| `A5` | `REG_WINDOW_OPEN` | Lüftungserkennung 🟡 — Zuordnung damit aus zwei Richtungen |
| `AF`-Maske | `#02000000` heißt „Temperatur abfragen" | Bit 1 = `A1`. Deckt sich mit der hier unabhängig hergeleiteten Regel „Bit n = A(n)" |

**Widerlegt** — beides an zehn Geräten gegengeprüft:

| Behauptung | Befund |
|---|---|
| `BD` Byte 1 = Batterie auf Skala 0–8 | `BD` ist auf **allen zehn** Geräten `#0800`, auch bei 20 % und 45 % Batterie. Wäre es die Batterie, stünde dort eine 2 bzw. eine 4. |
| `A6` = Komforttemperatur | `A6` liefert Werte bis 100. Als Temperatur — ob halbiert oder nicht — ergäbe das 50 bzw. 100 °C an einem Gerät, dessen Skala bei 28,5 endet. `A6` ist der Batteriestand in Prozent; die Werte folgen der Batteriewarnung des Geräts. |

Eine dritte Umsetzung (TechHummel) deutet `A3` als Lüftungserkennung und `B1` als
Softwareversion des Reglers. Beides passt nicht zu den hiesigen Messungen: `A3` wurde
bitweise am Gerät durchgeschaltet, und `B1` enthält im Klartext „Comet Wifi Ver. 6.1".

Keine dieser Abweichungen ist ein Vorwurf — ohne Herstellerunterlagen misst jeder an seiner
eigenen Anlage, und Firmwarestände unterscheiden sich. Sie sind hier festgehalten, damit
niemand die fremde Tabelle für gesichert hält, nur weil sie älter ist.

## Fremdquellen

- [ioBroker: „Eurotronic Comet WiFi — funktioniert doch!?!?"](https://forum.iobroker.net/topic/69372/eurotronic-comet-wifi-funktioniert-doch)
- [Home Assistant: „Integrating Comet Wifi basic functionality"](https://community.home-assistant.io/t/integrating-comet-wifi-basic-functionality/493474)
- [MaxXLive/ha-comet-wifi](https://github.com/MaxXLive/ha-comet-wifi) — eigene Registertabelle, siehe Abgleich oben
- [TechHummel/comet-wifi-homeassistant-integration](https://github.com/TechHummel/comet-wifi-homeassistant-integration)
- [marcinkordas/comet_wifi_integration](https://github.com/marcinkordas/comet_wifi_integration) — arbeitet über die Hersteller-Cloud, keine Registertabelle
- [Renesas DA16200 AT-Kommandos (UM-WI-003)](https://www.renesas.com/en/software-tool/da16200-wi-fi-command-set) — der WLAN-Chip der Geräte; die Client-ID beginnt mit `da16x`. Die Diagnoseregister `B6` (beginnt mit `00` = Schnittstelle WLAN0, wie bei `AT+NWIP`), `BA` und `BF` (`[WPA2-PSK-CCMP][ESS]` ist das Ausgabeformat von `AT+WFSCAN`) sehen nach durchgereichten Antworten dieses Chips aus.
- [comet_wifi_integration (Home Assistant, GitHub)](https://github.com/marcinkordas/comet_wifi_integration)

Die Register `A2`, `A3`, `A5`, `A7`, `A8`–`AE` stammen ausschließlich aus diesen Quellen
(Stand 2023) und sind hier bewusst als 🟡/❓ geführt, bis sie am eigenen Gerät bestätigt sind.
