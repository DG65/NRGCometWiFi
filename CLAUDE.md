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
```

Beide Module sind **Kinder des MQTT Clients**, nicht voneinander.

**Eigene Prefixe je Modul:** `CWIFI` (Thermostat), `CWIFIC` (Konfigurator). Im Verbund gibt es
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

## MQTT-Fallstricke, die hier schon Zeit gekostet haben

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
php .tools/test-libs.php           # Topic-Bau, MAC-Normalisierung, Dekodierung  (124)
php .tools/test-thermostat.php     # Empfangs- und Sendepfad des Geräts           (91)
php .tools/test-configurator.php   # Erkennung, Instanz-Zuordnung, Zeitsync       (57)
```

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
