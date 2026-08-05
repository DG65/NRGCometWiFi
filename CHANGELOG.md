# Änderungsverlauf

Alle nennenswerten Änderungen an diesem Modul. Versionierung nach [SemVer](https://semver.org/lang/de/).

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
