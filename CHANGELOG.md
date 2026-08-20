# Änderungsverlauf

Alle nennenswerten Änderungen an diesem Modul. Versionierung nach [SemVer](https://semver.org/lang/de/).

## [0.19.1] – Knopf „Übernehmen erzwingen"

### Neu
- **Panel „🔧 Wartung" in allen fünf Modulen** mit dem Knopf „Übernehmen erzwingen (ohne
  Formularänderung)". Hintergrund: Nach einem Modul-Update übernimmt Symcon die Einstellungen
  einer Instanz nicht zuverlässig, und „Übernehmen" wird erst anklickbar, wenn man im Formular
  etwas ändert und wieder zurückstellt. Der Knopf spart diesen Umweg. Übernommen aus dem
  EMS-Modul (0.22.4), dort als Angebot an den Verbund vorgeschlagen.
- Geprüft, bevor übernommen: `ApplyChanges()` sendet in diesen Modulen **nichts** an die
  Geräte und setzt Abfrage-Zeitgeber nur zurück. An Batteriegeräten wäre ein bequemer Knopf
  mit Sendewirkung sonst genau das falsche Angebot. Der Hinweistext sagt das auch dem Nutzer,
  zusammen mit der ehrlichen Warnung, dass noch nicht übernommene Formulareingaben verloren
  gehen.

### Prüfstand
- `.tools/test-forms.php` unterscheidet jetzt **Symcon-Kernfunktionen von Modul-Wrappern**.
  `IPS_ApplyChanges($id)` sieht aus wie `PREFIX_Methode($id)` und ist etwas ganz anderes.
  Geprüft wird nicht gegen eine Namensliste, sondern gegen den Stub: Was der als echte
  Kernfunktion führt, ist eine. Ein Tippfehler im Funktionsnamen fällt damit weiterhin auf.
- Ein Knopf, der eine Kernfunktion aufruft, hat keine Modulmethode, die Text liefern könnte —
  er muss die Rückmeldung selbst im `onClick` mitbringen. Auch das wird geprüft.
- `IPS_ApplyChanges()` im Stub ergänzt (Attrappe mit Protokoll, siehe Kommentar dort).

## [0.19.0] – Jeder Knopf meldet, was er getan hat

Verbindliche Verbund-Konvention „Sichtbare Rückmeldung bei jeder Aktion" (`SUITE.md`,
20.08.2026, Store-Review-Checkliste Punkt 13). Anlass waren zwei Live-Funde am EMS; die
Prüfung hier ergab: **kein einziger der acht Knöpfe zeigte eine Reaktion.** Ein Knopf, der
nichts sichtbar tut, ist von einem kaputten Knopf nicht zu unterscheiden — der Nutzer klickt
erneut, und bei Batteriegeräten weckt jeder Klick ein Gerät.

### Neu
- **Alle acht Formularknöpfe melden ihr Ergebnis in Klartext** (`echo PREFIX_Methode($id)`),
  mit Uhrzeit und ✅/⚠️/ℹ️.
- **Die Meldungen bleiben ehrlich:** „gesendet" ist nicht „beantwortet". Jede Erfolgsmeldung
  sagt ausdrücklich, dass die Werte erst erscheinen, sobald das Gerät antwortet — sonst hält
  man das Modul für kaputt, wenn nach dem Klick minutenlang nichts passiert.
- **Fehlschläge nennen den Ort:** MAC-Adresse, MQTT-Benutzer, übergeordneter MQTT-Client.
- **Der Raum nennt Zahlen:** „an 4 von 5 Geräten" — die einzige ehrliche Auskunft über ein
  halb geglücktes Sammelkommando, und der Fall, den man am ehesten übersieht.
- **„Geräte derselben Gerätegruppe ergänzen" sagt jetzt, warum nichts passiert ist** (kein
  Thermostat zugeordnet / keine Gruppe bekannt / nichts mehr zu ergänzen). Bisher gab es in
  allen drei Fällen stumm eine 0 zurück.
- `.tools/test-forms.php` erzwingt die Konvention jetzt **für alle Module auf einmal**: Jeder
  Knopf muss entweder mit `echo` eine `string`-Methode aufrufen oder nachweislich ein
  Formularfeld aktualisieren. Ein künftiger stummer Knopf fällt damit vor dem Gerät auf.

### Geändert — Bruch für eigene Skripte
Diese Methoden liefern jetzt **Text statt `true`/`false`**:
`CWIFI_RequestUpdate`, `CWIFI_RequestAllFields`, `CWIFI_SetClock`, `CWIFI_RequestSchedule`,
`CWIFIG_RequestUpdate`, `CWIFIG_RequestAllFields`, `CWIFIG_RequestSchedule`, `CWIFIG_SetClock`,
`CWIFIR_RequestUpdate`, `CWIFIC_ClearDiscovery` sowie `CWIFIG_AddGroupMembers` (vorher Anzahl).

**Wer den Rückgabewert auswertet, stellt auf `CWIFI_SendAction($id, $aktion)` um** —
`'Update'`, `'AllFields'`, `'Schedule'` oder `'Clock'`, Rückgabe weiterhin `bool`. Achtung:
Ein `if (CWIFI_SetClock($id))` bleibt scheinbar funktionsfähig, ist aber **immer** wahr, weil
auch der Fehlertext ein nicht leerer String ist.

Genau diese Falle war der Grund, die maschinelle Fassung getrennt zu führen statt Erfolg aus
einem Anzeigetext herauszulesen: Das Raum-Modul wertet je Mitglied aus, und eine geänderte
Formulierung hätte dort still falsche Ergebnisse erzeugt. Die Gegenprobe dazu steht im
Prüfstand.

## [0.18.1] – Kopfzeile altert nicht mehr im offenen Formular

### Behoben
- **Kopfzeile und Fundliste frieren ein, solange das Formular offen steht.**
  `GetConfigurationForm()` läuft nur beim Öffnen; eintreffende Gerätemeldungen wurden zwar
  korrekt verarbeitet, aber nicht angezeigt. Bei einer Zeile, die eine Uhrzeit nennt, ist das
  besonders irreführend — sie behauptet eine Aktualität, die sie nicht hat. Beide werden jetzt
  über `UpdateFormField()` nachgezogen, und zwar **zusammen**: eine Kopfzeile, die zehn Geräte
  meldet, während darunter neun stehen, wäre schlechter als beides veraltet.
- Kommandos (`S/`) blenden nichts auf — sie stammen aus der App oder von uns selbst und sagen
  nichts darüber aus, ob ein Gerät sie je gesehen hat.

Gefunden hat die Fehlerklasse die EMS-Sitzung an ihrem Suchknopf (jetzt Stolperfalle 12 in
`SUITE.md`). Hier trat sie ohne jeden Knopf auf, weil unsere Erkennung passiv nachläuft.

## [0.18.0] – Einheitliche Status-Kopfzeile im Konfigurator

### Neu
- **Der Konfigurator führt die Verbund-Status-Kopfzeile** (`SUITE.md`, 20.08.2026): eine
  Zeile im Muster `✅ 10 Thermostate gefunden (zuletzt 16:25:41 Uhr).` statt eines
  Fließtext-Satzes — `⚠️` wenn die Liste leer ist, obwohl schon einmal etwas ankam, `ℹ️`
  solange noch nie etwas gehört wurde. Sie steht vor der Fundliste; die technische
  Erklärung ist in ein eingeklapptes Unter-Panel gewandert.
- Neues Attribut `LastDiscoveryTs`, bei **jeder** Gerätemeldung fortgeschrieben — nicht nur
  beim ersten Fund einer MAC. Sonst würde die Kopfzeile altern, während die Anlage munter
  sendet.

### Abweichung von der Konvention (bewusst)
- **Kein Knopf „Geräte jetzt suchen".** Die Konvention sieht ihn über der Zeile vor; dieser
  Konfigurator sendet aber grundsätzlich nichts, weil jede Abfrage ein Batteriegerät weckt.
  „Zuletzt" meint hier folglich den Zeitpunkt der letzten Gerätemeldung, nicht den einer
  Suche — genau so steht es auch im Unter-Panel, damit niemand ein fehlendes Bedienelement
  für einen Fehler hält.

## [0.17.0] – „Was ist Neu" nachgezogen

### Behoben
- **Zwei Module hatten gar kein „Was ist Neu"-Panel.** Raumkachel und Raummodul entstanden in
  einem Zug und bekamen es nie — obwohl `SUITE.md` es als erstes Formularelement verlangt und
  unsere eigene `CLAUDE.md` dasselbe schreibt. Nachgerüstet, samt Bestätigungsknopf.
- **Die vorhandenen Panels standen auf 0.1 bzw. 0.3**, während die Bibliothek bei 0.16.0 war.
  `SUITE.md` nennt die Pflege eine Pflicht bei **jedem** Update; sie ist über ein Dutzend
  Versionen hinweg unterblieben. Alle fünf Panels beschreiben jetzt den aktuellen Stand.

### Prüfstand
- **Der Formular-Prüfstand erzeugt die Formulare jetzt wirklich**, statt nur `form.json` zu
  lesen. Das „Was ist Neu"-Panel und sein Knopf entstehen erst in `GetConfigurationForm()` —
  ein falscher `PREFIX_`-Aufruf dort wäre unbemerkt geblieben und hätte beim Klick einen
  Fatal Error ergeben, genau wie schon einmal in 0.10.1.
- Zusätzlich geprüft: Jedes Modul **hat** ein Neu-Panel, es ist das erste Element, es ist
  aufgeklappt, es trägt die Versionsnummer, und nach dem Bestätigen ist es weg. Drei
  Sabotagen gegengeprobt.

## [0.16.0] – Das Wochenprogramm ist schreibbar

### Neu
- **`CWIFI_SetScheduleDay(Wochentag, "HH:MM=Temp;HH:MM=Temp")`** schreibt das Tagesprogramm
  eines Wochentags — z. B. `CWIFI_SetScheduleDay(id, 6, "07:00=22.0;22:00=18.0")`.
  **Am Gerät belegt:** Ein geänderter Sonntagspunkt wurde geschrieben, vom Gerät bestätigt
  und byte-identisch wiederhergestellt. Auch das Schreiben des Urlaubs (`S/A7`, seit 0.3.0
  eingebaut) ist damit am Gerät nachgewiesen.
  - Angenommen wird nur, was vollständig auf das Gerät passt: 10-Minuten-Raster,
    Halbgrad-Temperaturen von „Aus" bis „An", aufsteigend sortiert, höchstens vier Punkte
    je Tag (mehr wurde nie beobachtet). Sonst wird **gar nichts** gesendet — ein halbgares
    Wochenprogramm in einer Heizung ist schlimmer als keines.
  - Komma und Punkt als Dezimaltrenner sind beide zulässig.
- **Die Registermaske ist auf alle vier Byte erweitert** (`requestFields`), nachdem Byte 2
  am Gerät belegt wurde. `RequestSchedule` fordert Wochenprogramm und Urlaub jetzt gezielt
  an (`#807F0000`) statt über den Voll-Dump — derselbe Abruf, deutlich kürzeres Aufwachen.

## [0.15.0] – Die Details wohnen jetzt im Doppelpfeil

### Geändert
- **Der ⋯-Umschalter aus 0.14.1 ist wieder entfernt** — die Kachel zeigt nur noch den Ring.
  Die Details gehören in die aufgezogene Ansicht, und dafür gibt es jetzt den passenden
  Mechanismus:
- **Die aufgezogene Ansicht (Doppelpfeil) ist gefüllt.** Sie zeigt die *Kinder* der Instanz —
  deshalb legt die Raumkachel Verknüpfungen auf die Variablen ihres Thermostats an:
  Ist/Soll (mit Schieberegler), Betriebsart, Wochenprogramm, Urlaub, Batterie, Signal,
  Geräteuhr samt Abweichung, Modell, Firmware, IP, Gruppe. Verknüpfungen statt Kopien: Die
  Werte bleiben am Gerät, samt Historie, und der Schieberegler bedient das Original.
  Abschaltbar; beim Gerätewechsel räumen sich die Verknüpfungen selbst auf.
- **Aktionsauswahl als Variable** („Aktion": aktualisieren · Wochenprogramm & Urlaub abrufen ·
  Uhr stellen · alle Felder anfordern) — in der aufgezogenen Ansicht bedienbar, springt nach
  dem Ausführen zurück auf „–". Am Raum wirkt sie auf alle Mitglieder.

### Prüfstand
- Der SDK-Stub hat eine Namenskollision abgefangen, bevor sie das Live-System erreichte:
  `maintainAction()` kollidierte mit `IPSModule::MaintainAction` — dieselbe Fehlerklasse wie
  der teuerste Fehler dieses Repos, diesmal lokal gefunden statt am Zielsystem.
- Die Rücksetz-Prüfung der Aktionsauswahl war anfangs blind (Variable stand ohnehin auf 0);
  der Prüffall setzt den gewählten Wert jetzt erst wirklich, und die Gegenprobe schlägt an.

## [0.14.1] – Der ⋯-Knopf ersetzt das Aufziehen

### Behoben
- **Die Zusatzangaben aus 0.14.0 waren unerreichbar.** Sie sollten beim Aufziehen der Kachel
  erscheinen — aber das Aufziehen zeigt in Symcon **immer die Standard-Ansicht der Instanz**,
  nie das eigene Kachel-HTML. An der Anlage belegt: Eine Rauminstanz zeigt aufgezogen ihre
  Variablenliste, eine Kachelinstanz ohne Variablen zeigt aufgezogen nichts. Die Annahme
  „vergrößert = dieselbe Kachel mit mehr Platz" war falsch.
- **Neu: ein ⋯-Knopf oben rechts in der Kachel** schaltet zwischen Ring und Detailansicht um —
  Wochenprogramm, Urlaub, Geräteauskunft, Aktionen. Zurück geht es über ‹. Die Detailansicht
  rollt, wenn der Inhalt die Kachelfläche übersteigt.
- Die Größenerkennung bleibt als Zugabe bestehen: Ist eine Kachel wirklich hoch genug
  aufgehängt, erscheinen die Zusatzangaben weiterhin von selbst.

## [0.14.0] – Die vergrößerte Kachel kann mehr

### Neu
- **Wird die Kachel aufgezogen, erscheinen Wochenprogramm, Urlaub, Geräteauskunft und die
  Aktionen.** Symcon meldet nicht „ich bin jetzt groß" — es gibt nur mehr Platz. Der wird
  gemessen; die Schwelle liegt bewusst über der normalen Kachelhöhe, damit ein schmales
  Fenster nichts versehentlich aufklappt.
- **Vier Aktionen direkt aus der Kachel**: aktualisieren, Wochenprogramm & Urlaub abrufen,
  Geräteuhr stellen, alle Felder anfordern. Dieselben Funktionen wie im Formular der
  Geräteinstanz — nur muss man dafür nicht mehr in die Verwaltungskonsole.
  - **„Alle Felder anfordern" fragt einmal nach.** Der Abruf weckt ein Batteriegerät
    vollständig; ein Fehlgriff soll das nicht auslösen. Die Rückfrage verfällt nach sechs
    Sekunden von selbst.
  - Am Raum wirken alle vier auf **jedes Mitglied**.
- Wochenprogramm, Urlaub und Geräteauskunft bleiben am Raum leer: Sie gehören zum einzelnen
  Thermostat und können sich zwischen den Mitgliedern unterscheiden. Die Kachel lässt die
  Abschnitte dann weg, statt etwas zu behaupten.

### Behoben
- **Beide Kacheln hatten denselben Fallstrick wie das Raummodul.** `@CWIFI_SetTemperature(...)`
  schützt in PHP 8 nicht — ein fehlender Wrapper ist ein `Error`. Wäre das Gerätemodul nicht
  geladen, hätte ein Klick die Kachel mitgerissen. Jetzt laufen alle modulübergreifenden
  Aufrufe über `function_exists()`.

### Prüfstand
- Jeder Knopf wird gegen die Funktion geprüft, die er auslösen soll. Vertauscht man zwei,
  sieht die Kachel gleich aus und niemand merkt es — deshalb gegengeprobt, indem genau das
  getan wurde.

## [0.13.1] – Der Raum sieht aus wie ein Gerät

### Neu
- **Das Raummodul zeichnet dieselbe Kachel wie ein einzelnes Thermostat** — Ring, Feinstufen,
  Schnellwahl „Aus"/„An", Betriebsart. Bisher stand dort eine nackte Variablenliste zwischen
  lauter Ringen; eine Zusammenfassung, die man nicht bedienen kann wie ein Gerät, ist nur
  halb gedacht.
- **Die Kachelvorlage liegt jetzt in der Bibliothek** (`.libs/CWIFI_RoomTile.html`) und wird
  von beiden Modulen gelesen. Zwei Kopien wären zwei Kacheln, die auseinanderlaufen — der
  Prüfstand hält ausdrücklich fest, dass keines der Module eine eigene hält.
- **Zwei Kennzeichen nur für Räume:** „Sollwerte uneinheitlich", wenn die Mitglieder
  auseinanderlaufen, und die Zahl der Thermostate in der Fußzeile.
- **Die Uhrabweichung wird über die Mitglieder zusammengefasst** — gemeldet wird die größte.
  Eine falsch gehende Uhr verschiebt die Schaltzeiten dieses einen Geräts, und das ist der
  ganze Raum.
- Die Fußzeile nennt die **jüngste** Meldung der Mitglieder: Sie sagt, wie frisch die Werte
  höchstens sind.

## [0.13.0] – Ein Raum, eine Instanz

### Neu
- **Comet WiFi Raum** (`CometWiFiRoom`): fasst mehrere Thermostate zu einer bedienbaren
  Instanz zusammen. Ein Sollwert, eine Betriebsart, ein Batteriewert — genau das, was die
  Hersteller-App mit ihren Gerätegruppen macht, nur in Symcon und mit vollen Variablen für
  Skripte und Automationen.
  - **Geschrieben wird an jedes Mitglied einzeln**, nicht über den Gruppenkanal des Geräts.
    Zwei Gründe: Das Nutzdatenformat auf `G/` ist nicht belegt, und ein Raum ist nicht immer
    eine Gerätegruppe — wer zwei Thermostate in einem Zimmer hat, die in der App gar nicht
    gekoppelt sind, will sie hier trotzdem zusammen schalten.
  - **Knopf „Geräte derselben Gerätegruppe ergänzen"**: Ein Thermostat auswählen, Knopf
    drücken, den Rest holt sich das Modul über die Gruppenzuordnung aus Register `B0`.
  - **Bei uneinheitlichen Sollwerten kein Mittelwert.** 20 und 24 ergeben keinen Raum mit 22,
    sondern einen Raum mit zwei verschiedenen Vorgaben. Gezeigt wird der höchste Wert, und
    „Mitglieder uneinheitlich" wird gesetzt.
  - **Erreichbar nur, wenn alle erreichbar sind.** Ein Raum, in dem ein Ventil nicht
    antwortet, ist nicht vollständig geschaltet.
  - **Batterie ist der schlechteste Wert** — genau der bestimmt, wann jemand hin muss.
  - Isttemperatur wahlweise als Mittel oder als kälteste Stelle. Der Kleinstwert ist für die
    Heizung die ehrlichere Zahl.

### Prüfstand
- 26 Prüfungen, Schwerpunkt auf der Zusammenfassung mehrerer Geräte zu einem Wert. Der
  Prüfstand bildet die Wrapper des Gerätemoduls nach und schreibt mit — geprüft wird also
  nicht nur, was der Raum anzeigt, sondern ob jedes Mitglied den Befehl bekommt. Vier
  Sabotagen gegengeprobt.
- Der Formular-Prüfstand zählt die Module jetzt gegen die Verzeichnisse statt gegen eine
  feste Zahl: Ein neues Modul soll ihn erweitern, nicht brechen.

## [0.12.0] – Gruppen vollständig verstanden

### Erkannt
- **Die Adressierung liegt vollständig offen.** Aus den Abonnements aller zehn Geräte am
  Broker: Jedes Gerät abonniert seinen eigenen Kommandokanal `…/<eigene MAC>/S/#`, den
  Zeit-Rundruf und eine Abfrage über den Rundruf-Benutzer. **Gruppenmitglieder abonnieren
  zusätzlich `+/<benutzer>/<KOPF-MAC>/G/#`** — den Kanal des Kopfgeräts, das ihn selbst
  ebenfalls abonniert.
- **Der eigene Kommandokanal bleibt jedem Gerät erhalten.** Die Gruppe ist ein *zusätzlicher*
  Kanal, keine Sperre. Dass sich gekoppelte Geräte in der Hersteller-App nicht einzeln
  schalten lassen, ist eine Entscheidung der App — auf Protokollebene ist jedes Gerät einzeln
  adressierbar.

### Neu
- **Die Gruppe wird beim Namen genannt.** Statt `Gruppe D4:3D:39:5E:3E:9C` steht jetzt
  `Gruppe mit Thermostat Wohnzimmer Rechts`, und das Kopfgerät führt sich als `Gruppenkopf`.
  Findet sich zu der MAC keine Instanz, bleibt die MAC stehen — eine erfundene Zuordnung wäre
  schlechter als eine ehrliche Hexfolge.

## [0.11.0] – „Nicht erreichbar" blieb für immer stehen

### Behoben
- **Ein Verbindungsabbruch wurde nie zurückgenommen.** Der Last Will des Brokers setzte
  „nicht erreichbar", und nur eine eigene Meldung des Geräts hätte das aufgehoben — die aber
  kommt nicht, weil diese Thermostate von sich aus praktisch nichts senden. An einer Anlage
  mit zehn Geräten stand deshalb Stunden nach einem Sammelabbruch noch überall „ausgefallen",
  während alle zehn am Broker hingen und Pings beantworteten.
- **Neu: einmaliges Nachfassen nach einem Abbruch** (ab Werk an). Der Last Will sagt nur, dass
  eine *Sitzung* endete — er kommt auch, wenn sich dasselbe Gerät neu anmeldet und dabei seine
  eigene alte Sitzung verdrängt, und gesammelt für alle Geräte, wenn der Broker kurz aussetzt.
  Gefragt werden nur die Temperaturen; hat sich das Gerät inzwischen selbst gemeldet, bleibt
  es schlafen. Ist es wirklich fort, verfällt die Frage und der Zustand bleibt richtig.
  Der Zeitpunkt ist je Gerät aus der MAC versetzt, damit ein Sammelabbruch nicht zehn
  Thermostate gleichzeitig weckt.

### Dokumentiert
- **Die Folge der abgeschalteten Abfrage steht jetzt im Formular.** Diese Geräte melden sich
  beim Verbinden und wenn man sie fragt — sonst nicht. Bei Abfrageintervall 0 können die Werte
  in Symcon deshalb viele Stunden alt sein. Das ist der Preis der Batterieschonung und kein
  Fehler, aber man sollte es wissen, bevor man sich wundert.

## [0.10.2] – Eine Überschrift statt zwei

### Behoben
- **Die Raumkachel trug ihren Namen doppelt.** Symcon setzt den Instanznamen bereits über die
  Kachel; die eigene Namenszeile darunter sagte dasselbe noch einmal. Sie ist entfernt — die
  Kennzeichen (Urlaub, gesperrt, nicht erreichbar, schief stehende Uhr) bleiben und stehen
  jetzt allein in dieser Zeile. Gibt es keine, entfällt die Zeile ganz, statt eine Lücke zu
  hinterlassen.
- Die Eigenschaft „Abweichender Name" ist damit gegenstandslos und wurde entfernt. Die
  Überschrift ist der Name der Instanz — wer den Raum benennen will, benennt die Instanz.
  Ein Hinweis dazu steht jetzt im Formular.
- Der Gerätename bleibt in der Nutzlast und erscheint beim Überfahren des Rings.

## [0.10.1] – Knopf der Raumkachel repariert

### Behoben
- **„Jetzt aktualisieren" in der Raumkachel lief in einen Fatal Error.** Der Knopf rief
  `CWIFIR_RequestAction(...)` auf — den globalen Wrapper `PREFIX_Name()` erzeugt Symcon aber
  **nur für Methoden, die die Modulklasse selbst deklariert, und nie für SDK-Namen**.
  `RequestAction` ist der Weg des Kerns in das Modul hinein, nicht der Weg von außen. Die
  Kachel hat jetzt eine eigene Methode `RequestUpdate()`.

### Neu
- **Prüfstand für die Formulare aller Module** (`.tools/test-forms.php`, 57 Prüfungen). Er
  liest jeden `onClick` und `onChange` aus allen vier `form.json` und prüft gegen die echten
  Klassen: Gibt es die Methode? Ist sie öffentlich? Ist sie selbst deklariert? Und trägt sie
  keinen vom SDK belegten Namen?
  - Diese Fehlerklasse findet weder `php -l` noch ein Test des Empfangspfads — sie schlägt
    erst zu, wenn jemand im Formular klickt.
  - Die Gegenprobe brachte eine eigene Lehre: Die Prüfung „selbst deklariert" allein hätte
    den Fehler **nicht** gefunden, denn die Raumkachel deklariert `RequestAction` sehr wohl.
    Erst die Liste der belegten SDK-Namen greift.

## [0.10.0] – Raumkachel für ein einzelnes Thermostat

### Neu
- **Comet WiFi Raumkachel** (`CometWiFiRoomTile`): ein Gerät, groß und bedienbar.
  - **Der Ring ist die Bedienfläche.** Ein Tipp darauf setzt den Sollwert; darunter stellen
    zwei Knöpfe in Halbgrad-Schritten nach. Die Übersichtskachel muss zehn Räume nebeneinander
    unterbringen und kommt deshalb mit 26 Pixel großen Knöpfen aus — hier gehört der Platz
    der Bedienung.
  - **Schnellwahl „Aus" und „An"** für die Endanschläge des Ventils, dazu die Betriebsart
    zum Umschalten zwischen Zeitplan und Handbetrieb.
  - **Kopfzeile mit dem, was Aufmerksamkeit braucht:** nicht erreichbar, Urlaub, Tastensperre,
    schief stehende Geräteuhr.
  - Gesteuert wird über die Geräteinstanz, nicht an ihr vorbei — damit gilt auch von hier aus
    die Umschaltung auf Handbetrieb.
  - **Der Ring färbt sich beim Heizen** — abgeleitet aus Soll und Ist, nicht gemessen. Eine
    Ventilstellung liefert das Gerät nicht (vollständig geprüft), und das steht als Kommentar
    an der Stelle, damit es niemand später für eine Messung hält.
  - Beide Kacheln lassen sich nebeneinander verwenden: die eine für den Überblick, die andere
    für den Raum, den man gerade bedient.

### Prüfstand
- 31 Prüfungen, Schwerpunkt auf den beiden Angriffspunkten einer Einzelkachel: die Zuordnung
  zu genau einer Instanz (fehlend, fremdes Modul, nachträglich gelöscht) und die Bedienung,
  deren Werte aus dem Browser kommen und deshalb grundsätzlich unglaubwürdig sind.
  Gegengeprobt — alle drei eingebauten Sabotagen schlagen an.

## [0.9.2] – A5 halb entschlüsselt, App als Quelle verworfen

### Erkannt
- **`S/A5` ist schreibbar** — zweimal geschrieben, zweimal vom Gerät bestätigt.
- **Aufbau `#<Empfindlichkeit><Dauer>`.** Byte 2 ist die Dauer in Minuten: Die App bietet
  10–60 min in Zehnerschritten an, alle zehn Geräte tragen `0x0A` = 10.
- **Byte 1 ist die Empfindlichkeit**, drei Werte beobachtet (`0x04`, `0x0C`, `0x80`). `0x0C`
  ist „Unempfindlich". Bit 2 und 3 sehen nach einem Stufenfeld aus, Bit 7 nach Abschaltung —
  als Vermutung markiert, es fehlt ein zweiter benannter Wert.

### Gewarnt
- **Die Hersteller-App zeigt nicht den Gerätezustand.** Drei nacheinander in der App
  vorgenommene Änderungen erreichten das Thermostat nicht; die App zeigte sie trotzdem als
  gültig an, auch nach vollständigem Neustart. Zugleich meldete das Gerät nachweislich einen
  anderen Wert. Brücken, Rückrichtung und Wartezeit wurden als Ursache ausgeschlossen — jedes
  Kommando im Mitschnitt stammte aus der eigenen Sitzung.
- Damit ist die App als Gegenprobe für Registerdeutungen unbrauchbar. Das betrifft rückwirkend
  jede Aussage, die sich allein auf ihre Anzeige stützt.

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
