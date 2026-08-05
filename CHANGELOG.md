# Änderungsverlauf

Alle nennenswerten Änderungen an diesem Modul. Versionierung nach [SemVer](https://semver.org/lang/de/).

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
