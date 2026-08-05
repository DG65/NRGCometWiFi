# Änderungsverlauf

Alle nennenswerten Änderungen an diesem Modul. Versionierung nach [SemVer](https://semver.org/lang/de/).

## [0.4.1] – Ist und Soll auseinanderhalten

### Behoben
- **Die Isttemperatur trug kein Etikett.** Sie stand als große Zahl allein, daneben „Soll An" —
  und weil „An" nicht wie eine Temperatur aussieht, las sich die Karte so, als wäre die große
  Zahl der Sollwert und der echte Sollwert fehle. Beide Werte sind jetzt beschriftet. Die
  Daten waren nie falsch, nur nicht als das erkennbar, was sie sind.
- **Der Sollwert stand doppelt** — einmal in der Kopfzeile, einmal in der Bedienleiste. Er
  erscheint jetzt genau einmal: in der Bedienleiste, oder als eigene Zeile, wenn die
  Bedienung abgeschaltet ist.
- **Ein gemeinsamer Wortanfang aller Gerätenamen wird ausgeblendet.** Heißen alle Instanzen
  „Thermostat …", fraß das wiederholte Wort genau den Platz, der zur Unterscheidung fehlte;
  aus „Thermostat Hauswirt…" wird „Hauswirtschaft". Der volle Name bleibt als Tooltip.
- Karten etwas breiter, damit längere Raumnamen ohne Abschneiden hineinpassen.

### Neu
- Endanschläge erklären sich beim Überfahren: „Ventil ganz auf (28,5)" statt nur „An".
- **Prüfstand für die Kachel** (32 Prüfungen) — prüft Ist und Soll als getrennte Felder und
  fällt durch, wenn sie vertauscht werden. Gegengeprobt durch eingebaute Vertauschung.
- `.tools/ips-stub.php` kennt jetzt auch die Visualisierungs- und Nachrichtenmethoden des SDK
  sowie fremde Variablen anderer Instanzen.

## [0.4.0] – Kachel

### Neu
- **Comet WiFi Kachel** (`CometWiFiTile`): Raumübersicht aller Thermostate mit Ist- und
  Solltemperatur, Batteriestand, Erreichbarkeit und Handbetriebs-Kennzeichnung.
  - **Findet die Geräte selbst.** Eine Auswahl von Hand wäre bei zehn Thermostaten lästig und
    müsste bei jedem neuen Gerät nachgepflegt werden.
  - Solltemperatur direkt verstellbar, in Halbgrad-Schritten. Die Bedienung läuft über die
    Geräteinstanz, nicht an ihr vorbei — damit gilt auch hier die Umschaltung auf Handbetrieb.
  - „Aus" und „An" werden benannt statt als 7,5 bzw. 28,5 °C angezeigt; es sind Endanschläge
    des Ventils, keine Temperaturen.
  - Nicht erreichbare Geräte bleiben ab Werk sichtbar — ausgeblendete Ausfälle fallen nicht auf.
- Von der Datenlogik getrennt (Muster: StromGedachtTile, InverterHubTile): Ein Fehler in der
  Darstellung kann die MQTT-Anbindung der Geräte nicht beeinträchtigen.

## [0.3.0] – Wochenprogramm, Urlaub und Gruppen

Entschlüsselt anhand von Bildschirmfotos der Hersteller-App: Was dort angezeigt wird, ließ
sich Zeichen für Zeichen gegen die mitgeschnittenen Payloads rechnen.

### Neu
- **Wochenprogramm** (`A8`–`AE`) wird gelesen und als Übersicht je Wochentag angezeigt.
  Format: je Schaltpunkt 16 Bit — obere 10 Bit Minuten seit Montag 00:00 geteilt durch 10,
  untere 6 Bit Solltemperatur × 2. Gegen den angezeigten Plan geprüft (04:00, 06:30, 15:30,
  21:00 werktags; 07:00 und 22:00 am Wochenende).
- **Urlaub** (`A7`) lesen und setzen — Beginn, Ende, Temperatur. Format `HH TT MM JJ` je
  Zeitpunkt plus Temperatur × 2, gegen die Anzeige „31.7.2026 12:00 bis 16.8.2026 12:00,
  25,0 °C" geprüft. Neun Byte `FF` heißen „kein Urlaub".
- Knopf **„Wochenprogramm & Urlaub abrufen"** — bewusst manuell, weil beides sich selten
  ändert und jede Abfrage ein Batteriegerät weckt.

### Erkannt und dokumentiert
- **Register `B0` ist die Gruppenzuordnung.** `S` + MAC des Gruppenkopfs bei gekoppelten
  Geräten, `U000000000000` bei Einzelgeräten. Über zehn Geräte gegen die Raumaufteilung
  geprüft. Erklärt die bislang unverstandenen `G/`-Topics und warum sich Thermostate eines
  Raums in der App nicht einzeln schalten lassen.
- **`A4` bleibt unbekannt.** Das Register zählt hoch und sah nach einer Geräteuhr aus; die
  App bietet aber keine Zeiteinstellung, und die Cloud sendet über den dafür vorgesehenen
  Kanal nie etwas. Ein selbst gesendeter Zeit-Rundruf erreichte alle Geräte, blieb aber ohne
  Wirkung. Die frühere Deutung als Uhr ist damit zurückgenommen.

## [0.2.0] – Registerentschlüsselung

Ergebnis einer Mitschnitt-Sitzung an der Hersteller-App: Jede Einstellung wurde einmal
ausgelöst und der resultierende MQTT-Verkehr ausgewertet. Alle neuen Funktionen sind damit
am Gerät belegt, nicht abgeleitet.

### Neu
- **Betriebsart** (Zeitplan / Handbetrieb) schaltbar — Register `A3`, Bit `0x20`
- **Tastensperre** dreistufig (aus / ein / plus) — Bits `0x04` und `0x08`, schließen einander aus
- **Anzeige um 180° drehen** — Bit `0x02`
- **Automatische Sommer-/Winterzeit** — Bit `0x01`
- **Temperatur-Offset** — Register `A2`, Wert × 2
- **Sollwert schaltet auf Wunsch automatisch auf Handbetrieb** (Vorgabe: ein). Ohne das hält
  ein gesetzter Sollwert nur bis zum nächsten Schaltpunkt des Wochenprogramms — die bislang
  größte Einschränkung des Moduls.

### Behoben
- **Sollwertskala war falsch.** Sie läuft nicht von 5 bis 30 °C, sondern von „Aus" (7,5)
  über 8,0–28,0 °C bis „An" (28,5); die Endanschläge sind Ventil zu bzw. auf. Die vielen
  `#39` an einer Testanlage waren daher keine 28,5-°C-Sollwerte, sondern „An".
- **Nachzieher nach dem Sollwert fordert nur noch `A0` an** statt aller Register. `S/AF` ist
  eine Registermaske (Bit n = A(n)) — das erste Byte `#01000000` genügt und weckt das
  Batteriegerät deutlich kürzer. Gegenprobe: Das aus den Foren bekannte `#48000000` ist
  genau `A6｜A3`, wie dort beschrieben.

### Erkannt und dokumentiert
- **Eine Ventilstellung gibt es nicht.** Vollständige Bestandsaufnahme über einen Feld-Dump:
  `A0`–`AF` und `B0`–`BF` sind erfasst, keines enthält sie.
- Diagnoseregister lesbar gemacht: Firmware (`B1`), IP (`B6`), WLAN-Zugangspunkt (`BA`),
  Verschlüsselung (`BF`)
- Wochenprogramm (`A8`–`AE`) und Urlaub (`A7`) in ihrer Struktur erfasst, Format noch offen
- Einen Boost gibt es in der App nicht

## [0.1.0] – noch nicht am Gerät erprobt

Erste Fassung. Die Bibliotheken und beide Modulklassen sind durch Prüfstände abgedeckt
(272 Prüfungen), am echten Thermostat aber noch nicht gelaufen.

### Comet WiFi Thermostat
- Ist- und Solltemperatur, Batteriestand, Signalstärke, Erreichbarkeit und Zeitpunkt der
  letzten Meldung als Variablen
- Solltemperatur setzbar (Schieberegler, 0,5-K-Raster). Der Wert wird sofort übernommen und
  gilt bis zur nächsten Meldung des Geräts, danach gewinnt das Gerät
- Wachhund meldet stumme Geräte als nicht erreichbar — der Last Will des Brokers greift bei
  Keepalive 600 s erst nach etwa 15 Minuten und bei einem Broker-Neustart gar nicht
- Aktive Abfrage ab Werk **aus**; eingeschaltet gilt ein Mindestabstand von 15 Minuten und ein
  aus der MAC abgeleiteter Versatz, damit mehrere Geräte nicht gleichzeitig aufwachen
- Rohdatenerfassung für alle noch nicht entschlüsselten Register (abschaltbar)

### Comet WiFi Konfigurator
- Findet Thermostate durch reines Mithören und legt sie per Klick an
- Zuordnung zu vorhandenen Instanzen über die MAC-Adresse und den gemeinsamen Broker,
  ausdrücklich **nicht** über den Namen
- Optionale Zeitsynchronisation für den Fall, dass die Hersteller-Cloud dauerhaft entfällt
  (ab Werk aus, wirkt auf alle Geräte im Netz)

### Protokoll
- `.docs/protokoll.md` als einzige Quelle der Wahrheit, getrennt nach verifiziert / vermutet /
  unbekannt
- **Schreibkodierung `S/A0` am Gerät belegt** (05.08.2026): `#` + zwei Hex-Ziffern in
  Großschreibung, Wert = Temperatur × 2. Nachgewiesen durch Mitschnitt eines aus der
  Hersteller-App gesetzten Sollwerts von 21,5 °C → `#2B`
- Nur belegte Register werden gedeutet. Wochenprogramm, Fenster-offen-Erkennung,
  Tastensperre und Temperatur-Offset bleiben bewusst außen vor
