# Hinweise für die Arbeit an diesem Repository

## Was dieses Modul besonders macht: es gibt keine Spezifikation

Eurotronic dokumentiert das MQTT-Protokoll der Comet WiFi nicht. Jede Zeile Dekodierung in
diesem Repo ist entweder selbst gemessen oder aus einem Forenbeitrag übernommen. Daraus folgt
die wichtigste Regel hier:

**Nichts dekodieren, was nicht belegt ist.** Lieber eine Rohvariable mit dem unveränderten
Payload als eine Variable mit einer erfundenen Bedeutung. Ein falsch gedeutetes Bitfeld sperrt
im Zweifel die Tasten eines Thermostats in einem bewohnten Haus, ein falsch gedeuteter Offset
zeigt 20 K daneben — beides ist schlechter als eine ehrliche Lücke.

`.docs/protokoll.md` ist die **einzige** Quelle der Wahrheit über das Protokoll. Wer etwas
Neues entschlüsselt, trägt es dort mit Status (✅ verifiziert / 🟡 vermutet / ❓ unbekannt) und
Quelle ein, **bevor** es in den Code wandert. Beim Statuswechsel 🟡 → ✅ gehört die Begründung
dazu, nicht nur das Häkchen.

## Verwandte Repositories

Teil des **NRG-Stack** (DG65). Verbundweite Konventionen stehen in
`../EMS/SUITE.md` — bei Fragen zu Formularaufbau, Vertragsversionierung, Statuscodes oder
Store-Review **zuerst dort nachsehen**, nicht Code zwischen Modulen vergleichen.

CometWiFi ist derzeit **eigenständig ohne Kopplung**: kein `*_GetFunctions`-Vertrag, kein
Partnermodul. Falls das EMS die Thermostate später einplanen soll, ist das eine eigene
fachliche Entscheidung — die Geräte messen keine Leistung, nur Temperatur.

Strukturell nächstes Vorbild im Verbund ist **HeishaMon**
(`../heishamon-work/HeishaMon-Modul/HeishaMon/module.php`): ebenfalls MQTT-Kind unter Symcons
MQTT Client, ebenfalls Presentation-System statt Variablenprofile.

## Architektur

```
MQTT Client (Symcon-Kernmodul, zeigt auf den lokalen Broker)
├── Comet WiFi Konfigurator   (type 4, passive Erkennung, sendet nichts)
├── Comet WiFi Thermostat     (type 3, eine Instanz je Gerät)
└── …

Comet WiFi Kachel        (type 3, ohne Elternteil — Übersicht aller Geräte)
Comet WiFi Raumkachel    (type 3, ohne Elternteil — ein Gerät, groß und bedienbar)
Comet WiFi Raum          (type 3, ohne Elternteil — mehrere Geräte als eine Instanz)
```

Die beiden Kacheln und das Raummodul hängen an **keinem** Elternteil. Sie lesen ausschließlich die Variablen der
Geräteinstanzen und steuern über deren öffentliche Funktionen — ein Fehler in der Darstellung
kann die MQTT-Anbindung damit nicht beeinträchtigen.

Thermostat und Konfigurator sind **Kinder des MQTT Clients**, nicht voneinander.

**Eigene Prefixe je Modul:** `CWIFI` (Thermostat), `CWIFIC` (Konfigurator), `CWIFIT`
(Übersichtskachel), `CWIFIR` (Raumkachel), `CWIFIG` (Raum). Im Verbund gibt es
beide Muster — ShellyV2 teilt einen Prefix über vier Module, MeterHub vergibt eigene
(`MHUB`/`MHUBD`/`MHUBV`). Hier bewusst die MeterHub-Variante: Bei geteiltem Prefix müsste jeder
neue öffentliche Methodenname gegen das jeweils andere Modul geprüft werden, weil beide
denselben globalen Wrapper `PREFIX_Name()` erzeugen. Eigene Prefixe schließen das strukturell
aus, statt es von Namensdisziplin abhängig zu machen.

Kein `ConnectParent()`: Ab Kernel 8.2 genügen `parentRequirements`/`implemented` in der
`module.json`, und der Nutzer kann selbst wählen, ob MQTT Client oder MQTT Server.

Die beiden MQTT-Datenschnittstellen-GUIDs sind auf Client **und** Server identisch:
`{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}` = TX (senden), `{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}`
= RX (empfangen).

## Batterie ist ein Entwurfskriterium, kein Detail

Die Geräte laufen auf Batterien, und aus dem ioBroker-Umfeld ist nach vergleichbarer Umleitung
Ausfall binnen ~2 Wochen berichtet. Die Ursache ist unbewiesen, das Risiko real. Deshalb gilt
für **jede** neue Funktion die Frage: *weckt sie das Gerät?*

Feste Regeln:
- Aktive Abfrage (`S/AF`) ist ab Werk **aus**, Mindestintervall 15 Minuten.
- **Kein** `S/AF` in `ApplyChanges()` oder `GetConfigurationForm()` — beides läuft bei jedem
  Kernelstart bzw. Formularöffnen, mal neun Geräte.
- Timer-Versatz über `crc32($mac) % $intervall`, damit die Geräte nicht im Gleichtakt aufwachen.
- Der Konfigurator sendet **grundsätzlich nichts** (einzige Ausnahme: die ausdrücklich
  eingeschaltete Zeitsynchronisation).
- `#FFFFFFFF` (alle Felder) nur als bestätigungspflichtiger Knopf, nie auf einem Timer.

## Formularknöpfe: `PREFIX_Name()` gibt es nicht für alles

Symcon erzeugt den globalen Wrapper `PREFIX_Name()` **nur für Methoden, die die Modulklasse
selbst deklariert** — und **nie** für die vom SDK belegten Namen (`Create`, `ApplyChanges`,
`RequestAction`, `ReceiveData`, `MessageSink`, `GetConfigurationForm`, `GetVisualizationTile`
und Verwandte). Ein `onClick` mit `CWIFIR_RequestAction($id, …)` läuft deshalb in einen Fatal
Error, obwohl die Methode existiert und öffentlich ist.

Wer aus einem Formular heraus etwas auslösen will, gibt dem Modul eine **eigene** öffentliche
Methode. `.tools/test-forms.php` prüft das für alle Module auf einmal; diese Fehlerklasse
findet sonst niemand, weil sie erst beim Klicken zuschlägt.

## Keine Methodennamen erfinden, die IPSModule schon führt

**Der teuerste Fehler dieses Repos bisher.** Ein selbst geschriebenes
`private function hasActiveParent()` im MQTT-Trait kollidierte mit dem geerbten
`IPSModule::HasActiveParent()` — PHP vergleicht Methodennamen **ohne Rücksicht auf
Groß-/Kleinschreibung**, und `private` verengt die Sichtbarkeit der geerbten `protected`-Methode.
Das ist ein Fatal Error beim Laden der Bibliothek.

Das Tückische daran war nicht der Fehler, sondern wie er sich zeigte: Symcon verwarf **beide**
Module kommentarlos. Die Bibliothek erschien in der Modulverwaltung mit korrekter Version, die
Dateien lagen vollständig auf der Platte, `php -l` meldete nichts — nur ließ sich im Objektbaum
keine Instanz anlegen. `IPS_GetModuleList()` kannte die Module schlicht nicht.

**Wo die Ursache stand:** ausschließlich in Symcons eigener Logdatei unter
`/…/symcon/log/logfile*.log`. Nicht im Meldungsprotokoll der Konsole, nicht über den
MCP-Connector. Bei „Modul wird nicht registriert" also **zuerst** dort nachsehen — die Datei
ist mehrere hundert MB groß, deshalb mit `grep` statt sie zu laden:

```bash
grep -ai -e comet -e cwifi /Volume1/Docker/symcon/log/logfile*.log | tail -25
```

**Vorbeugung:** `.tools/ips-stub.php` führt die echten SDK-Methoden mit **korrekter
Sichtbarkeit** — auch solche, die dieses Modul gar nicht benutzt. Eine gleichnamige
Eigenbau-Methode lässt damit sofort den Prüfstand scheitern, mit derselben Fehlermeldung wie
auf dem Zielsystem. Wer eine Hilfsmethode ergänzt, prüft vorher gegen diese Liste; wer eine
SDK-Methode vermisst, trägt sie dort nach.

Und die einfachere Lehre: `HasActiveParent()` gab es längst. Vor dem Nachbauen einer
Selbstverständlichkeit erst in der SDK-Referenz nachsehen.

## MQTT-Fallstricke, die hier schon Zeit gekostet haben

**Ein fehlender `PREFIX_`-Wrapper ist ein `Error`, keine Warnung.** `@CWIFI_SetTemperature(...)`
schützt in PHP 8 nicht: Ist das Gerätemodul nicht geladen, reißt der Aufruf das aufrufende
Modul mit. Modulübergreifende Aufrufe deshalb immer über `function_exists()` absichern — siehe
`CometWiFiRoom::callDevice()`.

**Retain bei `S/`-Topics ist verboten.** Ein retaintes Kommando wird bei jedem Reconnect erneut
zugestellt und überschreibt dann dauerhaft jede App- und Programmänderung. Der Kommentar an der
Sendefunktion bleibt stehen.

**QoS 1 statt 0 bei Kommandos.** Die Geräte verbinden mit `cleanSession=0`; der Broker hält
eine persistente Sitzung und stellt QoS-≥1-Nachrichten beim nächsten Reconnect nach.
QoS-0-Nachrichten an ein gerade schlafendes Gerät verwirft er still. `A0` ist idempotent,
Doppelzustellung schadet nicht.

**`S/`-Topics nie als Zustand werten.** Was auf `S/A0` hereinkommt, ist entweder unser eigener
Publish (den der Broker zurückspiegelt) oder ein Kommando aus der App. Als Zustand gewertet
würde der eigene Echo bestätigen, dass ein Wert angekommen ist, den das Gerät nie gesehen hat.
Nur `V/`-Topics sind Wahrheit.

**`#COMM-LOSS` darf `LastUpdate` nicht anfassen.** Das ist der Last Will — er kommt vom
Broker, nicht vom Gerät. Sonderfälle also **vor** der allgemeinen „irgendwas empfangen"-Logik
behandeln.

**Und `#COMM-LOSS` heißt nicht „das Gerät ist weg".** Er heißt „eine Sitzung endete". Er kommt
auch, wenn sich dasselbe Gerät neu anmeldet und dabei seine eigene alte Sitzung verdrängt
(`session taken over`), und gesammelt für alle Geräte, wenn der Broker kurz aussetzt. Wer ihn
als Endzustand behandelt, hat danach dauerhaft falsche Werte: Diese Thermostate senden von
sich aus nichts, es kommt also nie eine Richtigstellung. Deshalb fragt das Modul einmal nach.

**Die Geräte senden von sich aus praktisch nichts.** Sie melden sich beim Verbinden und wenn
man sie fragt. Ohne aktive Abfrage sind die Werte in Symcon regelmäßig viele Stunden alt —
das ist der Preis der Batterieschonung, gehört aber jedem gesagt, der sich über alte Werte
wundert.

**Slashes kommen escaped an.** Der Empfangsfilter arbeitet auf dem rohen JSON, dort steht
`02\/AABBCCDD\/…`. HeishaMons Muster: `str_replace('/', '\\\\/', $topic)`. Zusätzlich auf
`"Topic":"` ankern, damit kein Payload-Inhalt zufällig matcht.

## Verbund-Konventionen, die hier gelten

Vollständig in `../EMS/SUITE.md`, hier nur die für dieses Repo relevanten:

- **Alles Nutzersichtbare auf Deutsch** — Formulare, Meldungen, Variablennamen, README.
  Ausgenommen: Idents, Property-, Klassen- und Methodennamen (das ist API und wird nie
  umbenannt — ein umbenannter Ident erzeugt eine neue Variable und wirft die Historie weg).
  `Translate()`-Quellstrings im Code bleiben englisch, Übersetzung in `locale.json`.
  `form.json` wird direkt deutsch geschrieben.
- **Presentation statt Variablenprofile.** Wie HeishaMon. Eine Variable kann nur eines von
  beidem — deshalb werden die gemeinsamen `NRG.*`-Profile hier bewusst **nicht** registriert
  (Scope-Klärung in `SUITE.md`).
- **`MaintainVariable()` statt `RegisterVariableXXX()`** — idempotent. Ein bedingungsloses
  `RegisterVariableXXX()` bei jedem `ApplyChanges` bricht die ganze Transaktion samt aller
  darin gesetzten `EnableAction()`-Bindungen.
- **Keine Selbstpersistenz in Formular-Buttons.** In `onClick` nur `UpdateFormField` und
  Attribute, **nie** `IPS_SetProperty` + `IPS_ApplyChanges` (Store-Review-Blocker).
- **Jeder eigene `SetStatus()`-Code braucht einen Eintrag in `form.json["status"]** — sonst
  sieht der Nutzer nichts. 1xx = IPS-Standard, 2xx = modulspezifisch, kleinere Zahl = härter,
  Icon `error` (hart) oder `inactive` (weich).
- **Formularaufbau von oben:** „🆕 Neu in Version X.Y" (aufgeklappt, pro Version dismissible) →
  „📖 Dokumentation & Hilfe" (eingeklappt, enthält die Versionsnummer) → Fachpanels →
  Forum-Hinweis (einmalig dismissible, **erst wenn es einen Thread gibt** — kein
  Platzhalter-Link).
- **Globale Hilfsklassen brauchen den Modul-Präfix** (`CWIFI_Topics`, `CWIFI_Registers`),
  sonst kracht es, sobald mehrere NRG-Stack-Module in einem PHP-Prozess laden. Ausnahme: die
  Modulklassen selbst, deren Name exakt dem `name`-Feld der `module.json` entsprechen muss.
- **`vendor` in `module.json`** = Hersteller des Geräts, hier `Eurotronic`.
- **`library.json` akzeptiert nur** id, author, name, url, compatibility, version, build, date.
- **Keine eigene Anlage als Norm.** Prüffrage bei jedem Formularfeld: „Gilt das für JEDEN
  Nutzer, oder nur für Dietmars Installation?" Konkret hier: Der Benutzername `AABBCCDD` ist
  **Dietmars** Kontokennung, nicht die aller Nutzer — er steht als Vorbelegung im Feld, muss
  aber als „bei Ihnen möglicherweise anders" gekennzeichnet sein. Ebenso die IP-Bereiche und
  MAC-Adressen: die gehören in `.docs/protokoll.md` als Beispiel, nicht ins Formular.

## Prüfstände

```
php .tools/test-libs.php           # Topic-Bau, MAC-Normalisierung, Dekodierung   (242)
php .tools/test-thermostat.php     # Empfangs- und Sendepfad des Geräts          (216)
php .tools/test-configurator.php   # Erkennung, Instanz-Zuordnung, Zeitsync        (57)
php .tools/test-tile.php           # Kachel-Nutzlast: Ist/Soll, Namen, Ausfälle    (32)
php .tools/test-roomtile.php       # Raumkachel: Zuordnung, Ring, Aktionen         (48)
php .tools/test-room.php           # Raum: Zusammenfassung und Kachel              (42)
php .tools/test-forms.php          # Formularaufrufe aller Module gegen die Klassen (70)
```

`IPSTestState::useLocale('<Modulverzeichnis>')` schaltet `Translate()` auf die echte
`locale.json` um. Ohne das reicht der Stub den englischen Quellstring durch, und ein
vergessener Übersetzungseintrag fällt niemandem auf — außer dem Nutzer, der dann englische
Variablennamen sieht.

`.tools/ips-stub.php` bildet so viel IP-Symcon nach (Properties, Attribute, Buffer, Variablen,
Timer, Status, Datenversand), dass die Modulklassen **wirklich ausgeführt** werden. Grund:
`php -l` findet nur Syntaxfehler — die Fehler, die hier wehtun, sind Laufzeitfehler im
Empfangspfad, und die fielen am Gerät erst Stunden später auf.

**Prüfstände gegenprobieren, nicht nur laufen lassen.** Ein Test, der nie fehlschlägt, ist
kein Test. Nach jeder Erweiterung einmal gezielt die Logik kaputt machen und nachsehen, ob es
auffällt. Genau so wurden hier schon drei Lücken gefunden:

- Der Nachweis „`#COMM-LOSS` frischt die letzte Meldung nicht auf" ging durch, obwohl der
  Fehler eingebaut war — Empfang und Prüfung lagen in derselben Sekunde. Seitdem setzt der
  Test den Zeitstempel bewusst in die Vergangenheit.
- Der Konfigurator meldete dauerhaft „noch keine Thermostate gesehen", obwohl die Liste
  gefüllt war: `refreshStatus()` lief nur bei `ApplyChanges()`, nicht beim Fund.
- Rückgabetypen schwankten zwischen `int` und `float`, weil PHPs `/` bei glatt teilbaren
  Ganzzahlen ein `int` liefert.
- Der Platzhalter für „noch unbekanntes Register" musste zweimal weiterziehen: erst `A3`,
  dann `A4` — beide sind inzwischen entschlüsselt, und der Test brach jeweils erst beim
  nächsten Lauf. Wer ein Register deutet, sucht in `.tools/test-thermostat.php` nach dem
  Rohpfad-Abschnitt und wählt dort eines, das laut `.docs/protokoll.md` noch offen ist.

## Testen am Live-System

Zugriff über den MCP-Connector `ips-automation` (dieselbe Symcon-Instanz, die auch die
EMS-Sitzung nutzt).

- `php_eval` **gibt Rückgabewerte nicht aus.** Zuverlässig ist `IPS_LogMessage('TAG', $text)`
  und danach `system_log` lesen, oder `trigger_error(..., E_USER_WARNING)`.
- Nach einem Push: Modul in der Symcon-Modulverwaltung aktualisieren. Der „Aktualisieren"-Knopf
  und `MC_UpdateModule()` sind **nicht zuverlässig**; sicher ist nur `MC_DeleteModule()` +
  `MC_CreateModule()` + `MC_UpdateModuleRepositoryBranch()`, und zwar **einzeln pro Modul**,
  nicht in einer Schleife. Nebenwirkung: löscht Instanz-**Attribute** (nicht Properties).
- Der Broker (Mosquitto) läuft als eigener Container auf demselben Rechner wie Symcon.
  Mitlesen, was die Geräte senden:
  `docker logs -f <broker-container>` bzw.
  `mosquitto_sub -h <broker> -u <benutzer> -P <passwort> -t '#' -v`.
  Die konkreten Adressen der eigenen Installation stehen in `.docs/anlage-dietmar.md`
  (nicht im Repository).
