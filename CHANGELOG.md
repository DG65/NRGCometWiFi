# Änderungsverlauf

Alle nennenswerten Änderungen an diesem Modul. Versionierung nach [SemVer](https://semver.org/lang/de/).

## [0.9.1] – BD durchgemessen

### Erkannt
- **`BD` Bit 6 folgt der einfachen Tastensperre — und nur ihr.** Am Esszimmer-Thermostat alle
  drei Stufen durchgeschaltet, jede mit `A3` als Kontrolle zurückgelesen: „ein" ergibt
  `#0840`, „plus" und „aus" ergeben `#0800`. Kein flüchtiger Merker (nach 20 s unverändert)
  und kein Zeitproblem (bei „plus" auch nach 33 s unverändert).
- **Ein Spiegel der Einstellung ist es damit nicht** — der müsste bei „plus" erst recht
  anschlagen. `BD` bildet etwas Engeres ab als `A3`. Wird bewusst **nicht** als Variable
  geführt: gegenüber `KeyLock` redundant und dabei unvollständig.
- Der Versuch bestätigt nebenbei die Bitbelegung von `A3` vollständig: `0x04` und `0x08`
  schließen einander aus, wie dokumentiert.

## [0.9.0] – AF, BD und BE geklärt, soweit es geht

### Erkannt
- **`V/AF` trägt keine Information.** `#0014` auf allen zehn Geräten. Es wandert damit zu den
  nicht mehr angelegten Registern. **`S/AF` bleibt davon unberührt** — als Kommando ist es die
  Registermaske und das meistgenutzte Register überhaupt; der Prüfstand hält ausdrücklich
  fest, dass die eine Richtung die andere nicht mit abschaltet.
- **Die Registermaske deckt alle vier Byte ab.** Byte 1 = `A0`–`A7`, Byte 2 = `A8`–`AF`,
  Byte 3 = `B0`–`B7`, Byte 4 = `B8`–`BF`. Am Gerät geprüft: `#00000040` fordert gezielt `BE`
  an und liefert genau dieses eine Register. Die bislang nur für `A0`–`A7` belegte Regel gilt
  damit über den gesamten Bereich — nützlich für alles, was künftig gezielt ein einzelnes
  Register aus dem oberen Bereich braucht, statt einen Voll-Dump anzustoßen.
- **`BE` ist ein langsam wandernder Messwert**, kein Kennwert: Über fünf Stunden bewegte er
  sich bei mehreren Geräten um ein bis zwei Zähler im Bereich 97–100.
  - **Als Ventilstellung widerlegt.** Sollwert am WC auf „Aus", 75 Sekunden gewartet, `BE`
    gezielt angefordert: Änderung um eins statt eines Einbruchs. Die Aussage „eine
    Ventilstellung gibt es nicht" bleibt bestehen.
  - **Als Batteriestand widerlegt.** Das Gerät mit 20 % Restladung zeigt den höchsten Wert.
- **`BD`** ist auf allen zehn Geräten `#0800`, solange die Tastensperre überall aus ist. Ob es
  mit ihr wechselt, ist weiterhin nur vermutet.

## [0.8.1] – Rohwerte aufräumen

### Behoben
- **Das Abräumen alter Rohwerte griff erst, wenn das Register wieder ankam.** Bei Geräten,
  die sich von sich aus selten melden, hieß das: gar nicht. Es läuft jetzt bei jedem
  Übernehmen — wer eine Einstellung ändert, sieht die Wirkung sofort und nicht beim nächsten
  Voll-Dump.
- **Die nachweislich leeren Register entstehen nicht mehr.** `B4`, `B5`, `B7`, `BB` und `BC`
  tragen über zehn geprüfte Geräte hinweg denselben Wert und können damit weder Zustand noch
  Einstellung abbilden. Wer sie trotzdem mitschreiben will, schaltet sie im Formular ein.

### Neu
- **Rohwerte mit bekanntem Zweck tragen einen Namen.** `A5` heißt „Lüftungserkennung
  (unentschlüsselt)", `AF` „Registerabruf (unentschlüsselt)". Das ist eine Benennung und
  keine Deutung — was in den Bytes steht, behauptet weiterhin niemand.
- **Der Prüfstand übersetzt jetzt echt.** `Translate()` liest die `locale.json` des Moduls,
  statt den englischen Quellstring durchzureichen. Damit fällt ein fehlender
  Übersetzungseintrag im Prüflauf auf und nicht erst dem Nutzer, der plötzlich englische
  Variablennamen sieht. Gegengeprobt.

## [0.8.0] – Uhr nachführen, Kachel warnt

### Neu
- **Selbsttätige Nachführung der Geräteuhr**, Intervall in Tagen, ab Werk **aus**. Gestellt
  wird nur, wenn die Abweichung über der Hinweisschwelle liegt — für zwei Minuten ein
  Batteriegerät zu wecken wäre genau die Betriebsamkeit, die dieses Modul vermeidet. Der
  Zeitpunkt ist je Gerät aus der MAC versetzt.
- **Die Kachel meldet eine schief stehende Uhr** und zählt sie zu „braucht Aufmerksamkeit".
  Schwelle einstellbar, ab Werk 15 Minuten. Eine falsche Uhr verschiebt jeden Schaltpunkt
  des Wochenprogramms um dieselbe Spanne; das gehört auf die Übersicht und nicht nur ins
  Formular einer einzelnen Instanz.

### Dokumentiert
- **Der Uhrbefehl darf nie mit QoS ≥ 1 gesendet werden.** Sonst hält der Broker ihn für ein
  schlafendes Gerät vor und stellt ihn Stunden später zu — die Uhr ginge dann auf den
  Zeitpunkt des Absendens und wäre schlechter dran als vorher. Beim Stellen von zehn Geräten
  traf das eines: Es schlief, die QoS-0-Nachricht wurde verworfen. Genau richtig so.

## [0.7.1] – Das Stellen ist belegt

### Bestätigt
- **`S/A4` wird vom Gerät angenommen.** Am WC-Thermostat durchgeführt: Der geschriebene Wert
  `#3A1505081A` kam unverändert als `V/A4` zurück, und die Uhrabweichung fiel von +43 Minuten
  auf null. Der Vorbehalt aus 0.7.0 ist damit erledigt und der Hinweis im Formular ersetzt.
- **Auch das Datum ist belegt.** Es stand auf allen zehn Geräten unbewegt auf `01 01 14` und
  galt als ungedeutet. Der geschriebene Wert endet auf `05 08 1A` und kommt so zurück —
  Tag 5, Monat 8, Jahr 2026. Die Reihenfolge `MM HH TT MM JJ` ist damit vollständig
  nachgewiesen.

## [0.7.0] – Die Uhr stellen

### Neu
- **Knopf „Geräteuhr auf Symcon-Zeit stellen"** (`CWIFI_SetClock`). Sendet `S/A4` in der
  Kodierung, die beim Lesen belegt ist, und fordert die Uhr unmittelbar danach zurück —
  erst die Rückmeldung entscheidet.
  - **Ausdrücklich ein Versuch.** Dass `A4` gelesen die Uhr ist, steht fest; ob das Gerät
    dasselbe Register beschreiben lässt, nicht. Der Hinweis steht im Formular, damit
    niemand die Funktion für gesichert hält.
  - Bewusst ein Knopf und kein Zeitgeber: Jeder Schreibvorgang weckt ein Batteriegerät.
- **Hinweis bei schief stehender Uhr** (Status 207, Schwelle einstellbar, ab Werk 15 min).

### Behoben
- **Der Hinweis hätte keine Minute überlebt.** `markAlive()` setzt bei jeder eingehenden
  Gerätemeldung auf „aktiv" zurück — ein dort gesetzter Status wäre von der nächsten
  Temperaturmeldung weggewischt worden. Die Prüfung sitzt jetzt in `refreshStatus()`, wo
  auch die übrigen bleibenden Zustände entschieden werden. Aufgefallen ist es nur, weil der
  erste Prüfstand dazu die eingebaute Sabotage **nicht** bemerkt hat.

## [0.6.1] – Abgleich mit fremden Umsetzungen

### Dokumentiert
- **Zwei weitere Home-Assistant-Anbindungen gefunden und gegengeprüft.** Sie bestätigen
  `A0`–`A3`, `A7`, `AF` und `XX`, und sie führen `A5` als Fenster-/Lüftungserkennung — die
  hiesige Vermutung stammt damit aus zwei unabhängigen Richtungen. Auch die Registermaske
  von `AF` deckt sich: Dort steht `#02000000` für „Temperatur abfragen", also Bit 1 = `A1`,
  genau die hier eigenständig hergeleitete Regel.
- **Zwei fremde Deutungen an zehn Geräten widerlegt.** `BD` soll dort die Batterie auf einer
  Skala 0–8 enthalten — es steht aber auf allen zehn Geräten auf `#0800`, auch bei 20 %
  Restladung. Und `A6` soll die Komforttemperatur sein — Werte bis 100 ergäben 50 °C an
  einem Gerät, dessen Skala bei 28,5 endet. `A6` bleibt der Batteriestand.
- **Die Diagnoseregister stammen vermutlich vom WLAN-Chip.** Die Client-ID beginnt mit
  `da16x` — das ist der Renesas DA16200. `BF` entspricht dem Ausgabeformat von `AT+WFSCAN`,
  und `B6` beginnt mit `00` wie die Schnittstellennummer bei `AT+NWIP`. Das erklärt, warum
  `B4`, `B5`, `B7`, `BB` und `BC` auf allen Geräten gleich sind: Es sind Chip-Zustände, keine
  Thermostatwerte.

## [0.6.0] – Die Uhr im Gerät

### Neu
- **`A4` ist die Echtzeituhr des Geräts** — belegt, nicht vermutet. Zwei Messungen desselben
  Thermostats im Abstand von 3 h 19 min 57 s ergaben eine Zunahme von 3 h 20 min; über zehn
  Geräte blieb die Abweichung zur echten Zeit je Gerät stundenlang auf ±1 Minute stabil.
  Format `MM HH TT MM JJ`, dieselbe Reihenfolge wie beim Urlaub.
- **Geräteuhr und Uhrabweichung als Variablen.** Die Abweichung ist der eigentliche Nutzwert:
  Das Wochenprogramm läuft im Gerät, nicht in Symcon, und schaltet um genau diese Spanne
  versetzt. An einer Anlage mit zehn Geräten lagen die Uhren zwischen 24 Minuten und über
  neun Stunden vor. Wer seine Heizzeiten für falsch hält, sieht jetzt sofort, woran es liegt.
- **Verwaiste Rohwerte verschwinden.** Register, die inzwischen eine belegte Deutung haben,
  räumen ihre `RAW_`-Variable beim nächsten Empfang ab. In gewachsenen Instanzen standen
  `RAW_A7` und `RAW_A8`–`RAW_AE` noch neben dem längst entschlüsselten Urlaub und
  Wochenprogramm — zwei Variablen für denselben Wert, von denen die ältere obendrein
  aktueller aussah.

### Zurückgenommen
- **Die Rücknahme der Uhr-Deutung aus 0.3.0 war selbst falsch.** Sie stützte sich darauf, dass
  ein Zeit-Rundruf ohne Wirkung blieb — das sagt aber nur, dass sich die Uhr über diesen Kanal
  nicht stellen lässt, nicht dass es keine ist. Das Stellen bleibt offen.

## [0.5.0] – Geräteauskunft in Klarschrift

### Neu
- **Modell, Firmware, IP-Adresse, WLAN-Zugangspunkt, Verschlüsselung und Gruppenzuordnung
  als lesbare Variablen** statt als Hex-Kette im Rohpfad. Aus
  `#436F6D65742057696669205665722E20362E31` wird „Comet Wifi Ver. 6.1", aus
  `#00C0A8022D01445F0301` wird „192.168.2.45", aus `#U000000000000` wird „Einzelgerät".
  - Die Variablen entstehen erst, wenn das jeweilige Register ankommt — sonst stünde in
    einer frischen Instanz eine Reihe leerer Felder, die nach einem Fehler aussieht.
  - **Keine zusätzliche Batteriebelastung:** Diese Register kommen nur bei einem ohnehin
    stattfindenden Voll-Dump mit; es wird nichts nachgefragt.
  - Lässt sich ein Register wider Erwarten nicht lesen, fällt es in den Rohpfad zurück,
    statt eine verstümmelte Auskunft anzuzeigen. Die Rohfassung eines entschlüsselten
    Registers wird entfernt, damit nicht beide nebeneinander stehen.

### Behoben
- **Der Änderungsverlauf zu 0.2.0 behauptete zu viel.** „Diagnoseregister lesbar gemacht"
  hieß tatsächlich nur: in `.docs/protokoll.md` dokumentiert. In der Instanz standen sie
  weiter als Hex-Kette. Ab dieser Version stimmt die Aussage.
- **Der Hinweistext im Formular beschrieb den Stand von 0.1.0** — er nannte Wochenprogramm,
  Tastensperre und Offset als „noch nicht entschlüsselt", obwohl das seit 0.2.0 und 0.3.0
  nicht mehr stimmt.

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

### Dokumentiert
- **Retainte Kommandos und wie man sie loswird** (`.docs/protokoll.md`). An zehn Geräten
  durchgeführt: Ein retaintes `S/AF #FFFFFFFF` je Gerät wurde gelöscht. Beobachtet dabei —
  die Geräte bekommen die leere Nachricht zugestellt und ignorieren sie, und bei `both`-
  Brücken geht das Löschen mit Retain-Flag zum Hersteller hinaus (hier erwünscht, sonst
  käme die Kopie beim nächsten Reconnect zurück).
- **Bestandsaufnahme der unbekannten Register über zehn Geräte.** `B4`, `B5`, `B7`, `BB` und
  `BC` tragen auf allen zehn denselben Wert — sie können damit weder Zustand noch Einstellung
  abbilden und werden nicht weiter verfolgt. `A4`, `A5` und `BE` variieren und bleiben offen,
  jeweils mit dokumentiertem Weg zur Auflösung.
- **`B0` stand in der Diagnosetabelle noch unter „unbekannt"**, obwohl es seit 0.3.0 als
  Gruppenzuordnung entschlüsselt ist. Korrigiert.
- **`V/`-Topics werden nicht retained.** Bestandsaufnahme über zehn Geräte: null Treffer.
  Damit ist geklärt, warum eine frisch angelegte Instanz leer bleibt, bis das Gerät das
  nächste Mal von sich aus sendet — das ist kein Fehler.

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
