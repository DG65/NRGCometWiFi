<!-- AUTO-SYNC aus DG65/NRGEMS (Branch ems-integration) — NICHT HIER EDITIEREN.
     Aenderungen gehoeren in NRGEMS/SUITE.md; diese Kopie wird bei jedem
     Quell-Push ueberschrieben (NRGEMS/.github/workflows/sync-suite.yml). -->
# NRG-Stack (DG65) — Versionsmodell und Kompatibilitäts-Manifest

Der **NRG-Stack** (von DG65) besteht aus eigenständigen IP-Symcon-Modulen, die jeweils ihre eigene Versionsnummer
führen. Dieses Dokument beantwortet die Frage „welche Stände passen zusammen?" — für Nutzer
wie für die Module selbst.

## Gemeinsames Zielbild (27.07.2026)

Der Verbund arbeitet oft ticketgetrieben — reagiert auf einzelne Anfragen, statt aus einem
gemeinsamen Zielbild heraus mitzudenken. Explizite Erwartung von Dietmar: **jede Session soll
von sich aus Lücken/Synergien zu diesen Zielen erkennen, nicht nur auf explizite Anfragen
warten.** Die vier Ziele, aus der bisherigen Arbeit destilliert:

1. **Wirtschaftlichkeit** — möglichst wenig teuren Netzstrom kaufen, möglichst viel echten
   Bedarf statt starrer Prozentwerte decken (Beispiel: Batterie-Ladeziel soll sich künftig an
   der tatsächlich bis zum nächsten PV-Tag benötigten Energiemenge orientieren, nicht an
   einem festen SOC%, siehe offene LFC-Anfrage).
2. **Netzdienlichkeit/Rechtskonformität** — §14a, 70%-Regel, Solarspitzengesetz nicht nur
   gerade so einhalten, sondern vorausschauend (siehe „§14a-Lastabwurf-Priorisierung" unten).
3. **Zuverlässigkeit ohne KI-Krücke** — die harte Lektion vom 25./26.07.2026 (InverterHub-
   Marathon): Kein Modul darf sich darauf verlassen, dass "schon jemand live nachschaut,
   wenn's klemmt". Ein Endnutzer hat keine KI-Sitzung, die Bindungsabrisse live repariert.
4. **Einfachheit für den Nutzer** — er soll nicht bei jeder Kleinigkeit manuell eingreifen
   müssen; der Verbund soll erkennbare Zusammenhänge selbst herstellen (Beispiel: EMS fragt
   proaktiv bei Prognose nach einer passenden Kennzahl, statt selbst zu raten oder Dietmar zu
   fragen, welches Modul zuständig wäre).

**How to apply:** Bevor eine Session eine Anfrage nur eng beantwortet, kurz prüfen: passt die
Lösung zu einem oder mehreren dieser vier Ziele, oder gibt es eine bessere, die es tut? Bei
Unsicherheit lieber kurz nachfragen/vorschlagen als eng nach Buchstaben der Anfrage zu bauen.

**Ergänzung, explizit von Dietmar eingefordert (27.07.2026):** "Etwas mehr Engagement" heißt
konkret auch: aktiv außerhalb des eigenen Codes nach Referenzimplementierungen/Vorbildern
suchen (Web-Recherche), bevor man selbst von Grund auf neu entwirft — nicht nur intern
nach Lücken zu den vier Zielen suchen. Beispiele aus dem 27.07.2026: EMS hat OpenEMS/FENECON
(GoodWe-Registersemantik, §14a-Controller, Preisoptimierung) recherchiert, bevor eigene
Architektur gebaut wurde; Dashboard wurde gebeten, vor der eigenen Zeitreihen-Chart-Phase
etablierte Grafana-Referenzdashboards (Solar Flow Plugin, Powerwall-Dashboard) anzuschauen.

## Das Versionsmodell (drei Ebenen)

### 1. Modul-Version (SemVer, je Modul)
Jedes Modul versioniert sich selbst (`library.json`), im eigenen Takt. Diese Nummer sagt
nichts über die Kompatibilität zu anderen Modulen aus — dafür gibt es die Vertragsversion.

### 2. Vertragsversion (`contractVersion`, Major.Minor)
Jede Datenschnittstelle des Verbunds (`MHUB_GetFunctions`, `CHUB_GetFunctions`,
`IHUB_GetFunctions`, `TIBBERGR_GetPriceCurve`, `TIBBERGR_GetTariffConfig`, `SGW_GetState`,
`SBH_GetState`, `HEISHA_GetFunctions`, `TESSIE_GetVehicleState`, `PVF_Get*`/`LFC_Get*`, …)
liefert in ihrer Rückgabe ein Feld:

```php
'contractVersion' => '1.0'   // Major.Minor als String
```

**Platzierung:** `contractVersion` steht auf der obersten Ebene jedes zurückgegebenen
Objekts. Für **Listen-Verträge** (Liste von Einträgen ohne Umschlag, z. B.
`MHUB_/CHUB_/IHUB_/HEISHA_GetFunctions`, `GetPriceCurve`-Slots) heißt das: **in jedem
Eintrag**, alle Einträge tragen denselben Wert. Für **Objekt-Verträge** (eine einzelne
Struktur, z. B. `GetTariffConfig`, `SBH_GetState`, `TESSIE_GetVehicleState`): einmal auf
der obersten Ebene. Kein zusätzlicher Umschlag um bestehende Listen — das bestehende
Rückgabeformat bleibt unangetastet.

- **Major** erhöht sich NUR bei einem Bruch (Feld entfernt/umbenannt/umgedeutet). Volle
  Kompatibilität gilt nur innerhalb derselben Major — das Master/Slave-Prinzip, wie es
  z. B. meteocontrol beim blue'Log verwendet.
- **Minor** erhöht sich bei additiven Erweiterungen (neue Felder). Alte Konsumenten
  ignorieren neue Felder und bleiben unbeeinflusst.
- Da Verträge im Verbund grundsätzlich nur additiv geändert werden, sollte Major=1 sehr
  lange stabil bleiben. Ein Major-Bruch ist die dokumentationspflichtige Ausnahme.
- Fehlt das Feld (ältere Modulstände), gilt konservativ `'1.0'`.

**Muster: unterschiedlich reichhaltige Datenquellen im selben `Type`-Vertrag**
(WPHub/HeishaMon/Dashboard, 12.08.2026, am Beispiel eines Anlagenschemas mit
Pumpen-/Ventil-/Durchfluss-/Vorlauf-Feldern). Wenn mehrere Module denselben
Gerätetyp bedienen, aber unterschiedlich reichhaltige Datenquellen haben (lokal/
MQTT vs. Herstellercloud), gehören zusätzliche Felder **additiv in den bereits
gemeinsamen `Type`-Vertrag** (hier `Type=>'heatpump'`), NICHT in eine
modulspezifische Erweiterung eines einzelnen Anbieters. Jedes Modul füllt nur die
Felder, die seine eigene Datenquelle hergibt, der Rest bleibt leer/fehlt — ein
Konsument (z. B. eine Kachel) muss jedes optionale Feld generisch auf Vorhandensein
prüfen, statt anbieterspezifisch zu wissen "bei Modul X fehlt Feld Y". Grund: Jedes
Modul muss weiterhin allein funktionieren (eine Installation kann nur eines von
mehreren Modulen desselben `Type` haben), und ein `Type`-Vertrag soll für JEDEN
Anbieter dieses Gerätetyps erweiterbar bleiben, nicht nur für den, der die
Erweiterung zuerst vorschlägt.

**Muster: echte Messwerte kommen aus einem separaten Messmodul, nicht aus dem
Erzeuger-Vertrag** (jetzt zweimal beobachtet — ChargerHub/Wallbox-Zuordnung und
WPHub+HeishaMon/heatpump-Leistung, MeterHub, 12.08.2026). Wenn ein Erzeuger-Modul
(Wallbox, Wärmepumpe, ...) selbst keine echte Leistungs-/Energiemessung hat oder nur
schätzen kann, bleibt das entsprechende Feld im eigenen `Type`-Vertrag bewusst leer/0
— **kein Mangel, keine Kopplung an ein anderes Erzeuger-Modul**. Die echte Messung
kommt stattdessen ggf. aus einem separaten Messmodul (MeterHub) über dessen eigenen,
generischen Vertrag mit Funktions-Tag (`assignments[].function`, z. B. `"heatpump"`
oder `"wallbox"`). Ein Konsument (EMS/Dashboard) fragt zusätzlich zu den
Erzeuger-Modulen auch die Messmodule ab und merged selbst über den Funktions-Tag +
`authority`/Messgüte — die Erzeuger-Module referenzieren NIE fremde Variablen-IDs
direkt, das bliebe eine harte Kopplung zwischen zwei unabhängigen Modulen.

**Muster: normalisierte Enum-Felder ueber eine modul-gepflegte, abgeleitete
Variable** (HeishaMon/Dashboard/WPHub, 13.08.2026, am Beispiel `operatingModeID`).
Wenn ein Rohwert-Feld im `Type`-Vertrag ein HERSTELLERSPEZIFISCHES Enum traegt (z. B.
Panasonics 0-8-Betriebsart), muss jeder Konsument dessen Bedeutung selbst kennen --
bei mehreren Herstellern im selben `Type` (hier: HeishaMon + WPHub, beide
`heatpump`) faellt ein falsch uebersetzter Wert still niemandem auf. Loesung: EIN
zusaetzliches Feld (`operatingModeNormID`) zeigt auf eine vom Erzeuger-Modul selbst
GEPFLEGTE, ABGELEITETE Variable mit einem VERBUND-DEFINIERTEN Enum (hier:
0=standby/1=heating/2=cooling/3=dhw/4=heating+dhw/5=cooling+dhw) -- jedes
Erzeuger-Modul mappt seinen eigenen Herstellerwert intern auf diese gemeinsamen
Werte. Praezedenzfall fuer "Erzeuger-Modul pflegt eine abgeleitete Variable
zusaetzlich zu den Rohwerten": `Power_Total`. Wichtig, warum NICHT ein String-Wert
direkt im Vertrag: Vertraege liefern durchgehend Variablen-IDs fuer Live-Lesen, kein
Konsument soll auf einen Wert-Schnappschuss angewiesen sein, der zwischen zwei
`GetFunctions()`-Aufrufen veraltet. Der Rohwert (`operatingModeID`) bleibt
zusaetzlich fuer Diagnose bestehen, wird aber nicht mehr zur eigentlichen
Entscheidungslogik der Konsumenten.

**Muster: neue Konsumenten-Features muessen den bestehenden Discovery-
Mechanismus nutzen, nicht daneben eine manuelle Verknuepfung neu erfinden**
(EMS, 20.08.2026). Konkreter Fund: EMS' `Discover()` findet installierte
Partnermodule (u. a. Tibber Grid Reward) laengst automatisch und ruft ihre
Vertragsfunktionen ab (z. B. `TIBBERGR_GetTariffConfig`) — der neue
Tagesplan (`BuildDayPlan()`, 0.21.0) las die PT15M-Preiskurve aber ueber eine
manuell zu verknuepfende Property, obwohl `TIBBERGR_GetPriceCurve` **1.1**
laengst existiert und automatisch geholt werden koennte. Ergebnis: der
Tagesplan blieb auf einer produktiven Anlage leer, obwohl alle Bausteine
installiert und aktiv waren — Dietmar musste die Luecke selbst im Formular
entdecken. Lehre: **jedes neue Feature, das Daten von einem Partnermodul
braucht, MUSS zuerst pruefen, ob eine automatische Discovery/Get*-Funktion
dafuer schon existiert**, bevor eine neue manuelle `SelectVariable`-Property
angelegt wird — eine manuelle Property ist nur als Fallback fuer Nutzer ohne
das jeweilige Partnermodul gerechtfertigt (siehe Batteriestring-Panel als
korrektes Vorbild: "nur ausfuellen, falls kein Partnermodul automatisch
gefunden"), nicht als alleiniger Weg. Fix: `getPT15MTodayJson()` versucht
zuerst `TIBBERGR_GetPriceCurve()` ueber `getTibberGridRewardInstance()`,
faellt nur bei fehlender Instanz/leerem Ergebnis auf die manuelle Property
zurueck (EMS 0.21.2).

### Kanonisches Feldregister: `Type=>'heatpump'`-Vertrag

**Warum dieses Register existiert (13.08.2026):** HeishaMon fuehrte in 1.10 ein
`outsideTempID` ein, ohne vorher zu pruefen, dass WPHub seit 1.3 bereits
`outdoorTemperatureID` fuer dasselbe Konzept (WP-Aussenfuehler) liefert —
Vokabular-Drift, weil kein Ort existierte, an dem der Gesamtbestand beider
Module sichtbar war. Jede kuenftige Erweiterung des `heatpump`-Vertrags traegt
sich HIER ein, BEVOR sie gebaut wird — nicht nur in den Abstimmungs-Nachrichten
zwischen Sitzungen.

**Aufloesung des `outsideTempID`/`outdoorTemperatureID`-Konflikts:** kanonisch
ist **`outsideTempID`** (Option A, Stilkonsistenz mit den 8+ bereits
etablierten `*TempID`-Kurzform-Feldern). `outdoorTemperatureID` ist der
Langform-Ausreisser und gilt als **deprecated** — WPHub liefert beide Namen
parallel (additiv, kostenlos), bis alle Konsumenten auf `outsideTempID`
umgestellt haben.

| Feld | Seit | Bedeutung | Liefert HeishaMon | Liefert WPHub |
|---|---|---|---|---|
| `Caption`/`PowerID`/`EnergyID`/`Measured`/`unit`/`reachable` | 1.0 | Basisvertrag | ✅ | ✅ (`PowerID`/`EnergyID`=0, siehe Messmodul-Muster oben) |
| `pumpFlowID`/`pumpSpeedID`/`pumpDutyID` | 1.3 | Interne WP-Pumpe: Durchfluss/Drehzahl/Taktverhaeltnis | ✅ | 0 |
| `threeWayValveStateID`/`twoWayValveStateID` | 1.3 | Ventilstellung (Enum) | ✅ | 0 |
| `mainInletTempID`/`mainOutletTempID` | 1.3 | Vor-/Ruecklauftemperatur Haupt­kreis | ✅ | ✅ (seit WPHub 1.6, externe Sensoren-Verknuepfung) |
| `z1WaterTempID`/`z2WaterTempID` | 1.3 | Wassertemperatur Heizzone 1/2 | ✅ | 0 |
| `dhwTempID` | 1.3 | Warmwassertemperatur | ✅ | ✅ |
| `bufferTempID` | 1.3 | Puffertemperatur | ✅ | ✅ (seit WPHub 1.6, externe Sensoren-Verknuepfung) |
| `compressorFreqID` | 1.3 | Verdichterfrequenz | ✅ | 0 |
| `dischargeTempID` | 1.3 | Heissgastemperatur (Verdichteraustritt) | ✅ | 0 |
| `defrostingStateID` | 1.3 | Abtaubetrieb aktiv (bool) | ✅ | 0 |
| `z1PumpID`/`z2PumpID` | 1.4 | Externe Heizkreis-Pumpe An/Aus (2. Steuerplatine) | ✅ (0 ohne Platine) | 0 |
| `z1MixingValveID`/`z2MixingValveID` | 1.4 | Externes Mischventil: Stellrichtung (0=Aus/1=Zu/2=Auf) | ✅ (0 ohne Platine) | 0 |
| `fan1SpeedID`/`fan2SpeedID` | 1.5 | Luefterdrehzahl Aussengeraet (rpm) | ✅ | 0 |
| `suctionTempID` | 1.6 | Sauggasseite; beste verfuegbare Messstelle (bei HeishaMon: Verdampferaustritt) | ✅ | 0 |
| `operatingModeID` | 1.7 | Konfigurierte Betriebsart, HERSTELLER-Rohenum (0-8, Panasonic-spezifisch) — Diagnose only, siehe `operatingModeNormID` | ✅ | ✅ |
| `z1MixingValvePositionID`/`z2MixingValvePositionID` | 1.7 | Mischventil-Position in % (0-100), praeziser als die Stellrichtung | ✅ | 0 |
| `indoorPipeTempID` | 1.7 | Kaeltemittel-Innenrohrtemperatur (im Kuehlbetrieb die kalte Seite) | ✅ | 0 |
| `operatingModeNormID` | 1.8 | Betriebsart, VERBUND-Enum (0=standby/1=heating/2=cooling/3=dhw/4=heating+dhw/5=cooling+dhw) — kanonisch fuer Konsumenten, siehe Enum-Muster oben | ✅ | ✅ |
| `copEstimateID` | 1.9 | COP-Schaetzung aus WP-eigenen Leistungswerten | ✅ | 0 (Cloud liefert keine Wärmeleistung) |
| `copMeasuredID` | 1.9 | COP aus Waermeleistung ÷ externem Stromzaehler; 0 ohne Zaehler | ✅ | 0 |
| `dailyPerformanceFactorID` | 1.9 | Tages-Arbeitszahl; 0 ohne Energiezaehler | ✅ | 0 |
| `heatOutputPowerID` | 1.10 | Thermische Gesamtleistung (Heizen+Kuehlen+WW), W | ✅ | 0 |
| `outsideTempID` | 1.10 | **Kanonisch.** WP-Aussenfuehler | ✅ | ✅ (parallel zu `outdoorTemperatureID` bis Migration) |
| `outdoorTemperatureID` | 1.3 (WPHub) | **Deprecated**, Duplikat von `outsideTempID` | 0 | ✅ (Auslauf) |
| `compressorStartsID` | 1.10 | Kumulierte Verdichter-Starts (Takt-Analyse) | ✅ | 0 |
| `operationsHoursID` | 1.10 | Kumulierte Betriebsstunden | ✅ | 0 |
| `dailyEnergyHeatingID`/`dailyEnergyCoolingID`/`dailyEnergyDHWID`/`dailyEnergyTotalID` | 1.11 | Tages-Energiezaehler je Kategorie; springt taeglich auf 0 (KEIN kumulativer Zaehler, daher eigene Felder statt `EnergyID`, siehe Grundregel bei "Gemeinsame Variablenprofile") | 0 (andere Verbrauchsquellen lokal) | ✅ |

Stets 0/leer = "meine Datenquelle liefert das nicht" (kein Fehler, siehe
Grundregeln oben). Aktueller `contractVersion`-Stand: **1.11**.

### 3. Update-Meldepflicht beim Konsumenten
Ein Konsument (in erster Linie das EMS, aber auch Kacheln) kennt je Partnerschnittstelle
seine benötigte **Mindest-Vertragsversion**. Liefert der Partner eine ältere Major:

1. Das Modul bleibt **eigenständig voll funktionsfähig** (Verbund-Grundregel — kein Modul
   setzt ein anderes voraus).
2. Nur die betroffene **Kopplung wird deaktiviert** (kein Arbeiten mit falsch gedeuteten
   Daten).
3. Der Zustand wird **sichtbar gemeldet** — im Instanzstatus bzw. Konfigurationsformular,
   nicht nur im Log: z. B. „⚠️ Partnermodul MeterHub benötigt eine Aktualisierung
   (Vertrag 2.x benötigt, 1.4 vorhanden)". Der Nutzer erfährt, WAS er aktualisieren muss.

Umgekehrt gilt: Ist der **Konsument** zu alt (Partner liefert eine neuere Major), meldet der
Konsument „dieses Modul benötigt eine Aktualisierung, um Partner X zu nutzen".

### Suite-Release (CalVer, dieses Manifest)
Ein Suite-Release `JJJJ.MM` benennt einen **zusammen getesteten Satz** von Modulständen.
Es ist ein Etikett für Nutzer („diese Kombination passt"), keine technische Prüfgröße —
die technische Prüfung läuft ausschließlich über `contractVersion`.

---

## Gemeinsame Variablenprofile (`NRG.*`)

Physikalische Grundgrößen bekommen EIN gemeinsames Profil statt je Modul ein eigenes
(`GWH.Watt`/`MHB.Watt`/`CHB.Watt` → `NRG.Watt`) — spart Wartung, macht sofort erkennbar,
was zum NRG-Stack gehört. Modul-eigene Status-/Enum-Profile (z. B. ein Ladezustands-Enum
eines bestimmten Wallbox-Herstellers) bleiben dagegen unter dem eigenen Modul-Präfix, weil
sie modulspezifische Bedeutung tragen, keine austauschbare physikalische Einheit.

**Start bewusst klein** (Dietmar, 23.07.2026) — nur diese sechs, weitere erst bei
tatsächlichem Bedarf ergänzen, keine Vorab-Liste für Einheiten, die noch niemand braucht:

| Profil | Größe |
|---|---|
| `NRG.Watt` | Momentanleistung (W) |
| `NRG.kWh` | Energie, kumulativ (kWh) |
| `NRG.Ampere` | Strom (A) |
| `NRG.Volt` | Spannung (V) |
| `NRG.Percent` | Anteil/SoC (%) |
| `NRG.Celsius` | Temperatur (°C) |

**Grundregel: `NRG.kWh`/Energiefelder in `*_GetFunctions` (`EnergyID`) nur aus echten
kumulativen Zählern, nie aus Tages-/Perioden-Werten hochrechnen** (WPHub, 10.08.2026,
beim Panasonic-Comfort-Cloud-Anschluss: die Cloud liefert nur Tageswerte, die auf 0
zurückspringen — kein echter Zähler). Steht kein kumulativer Zähler zur Verfügung,
bleibt `PowerID`/`EnergyID` im Vertrag einfach `0`/leer (kein Fehler, kein Ersatzwert) —
lieber eine fehlende Größe im Verbund als eine, die bei jedem Tageswechsel einen
Sprung/Reset erzeugt, den ein Konsument (EMS, Archiv) falsch als echten Verbrauch liest.

**Anlage idempotent, kein Eigentümer-Modul nötig:** jedes Modul prüft
`IPS_VariableProfileExists('NRG.Watt')` und legt nur an, falls es fehlt — wer zuerst
startet, erzeugt es, alle anderen finden es vor. Gleiches Muster wie bereits in
GleitenderMittelwert verwendet.

**Scope-Klärung (24.07.2026, Rückfrage von HeishaMon):** `NRG.*` gilt nur für Module,
die klassische Variablenprofile führen (Referenz: GleitenderMittelwert). Module, die stattdessen
das neuere Presentation-System nutzen (Presentation-Array statt Profil-String je Variable —
z. B. HeishaMon), sind NICHT betroffen und sollen ihre Presentation (SUFFIX/DIGITS/Slider/Enum)
nicht zugunsten eines Profils aufgeben — IPS erlaubt je Variable ohnehin nur eines von beidem.
Für maschinenlesbare Einheiten ohne Profilzwang steht Presentation-Modulen optional ein additives
`'unit' => 'W'`-Feld im jeweiligen `*_GetFunctions`-Vertrag offen (erhöht nur die Minor).

## Einheitliche Formular-Optik

Jedes Instanz-Konfigurationsformular folgt derselben Grundstruktur (Referenzimplementierung:
InverterHub), von oben nach unten:

1. **"🆕 Neu in Version X.Y"** — ExpansionPanel, standardmäßig **aufgeklappt**, nur bei
   wichtigen Änderungen gepflegt (nicht bei jedem Patch). Eigener Button
   "Verstanden – nicht mehr anzeigen", **pro Version dismissible** (Attribut speichert die
   zuletzt bestätigte Version, taucht bei neuer Version mit Eintrag automatisch wieder auf).
   Die Panel-Caption trägt die Versionsnummer ('🆕 Neu in Version X.Y') — unproblematisch,
   da Dismiss das GESAMTE Panel ausblendet (`visible=false`), nicht nur den Text. Es gibt also
   keinen Zwischenzustand "Panel sichtbar, Versionsnummer weg" (korrigiert 29.07.2026 — der
   vorige Satz hier war veraltet/falsch, sowohl InverterHubTile als auch EMS setzen die Version
   bewusst in die Caption, bestätigt von InverterHub).
2. **"📖 Dokumentation & Hilfe"** — direkt darunter, standardmäßig **eingeklappt**. Enthält
   die Versionsnummer (dauerhaft sichtbar).
3. Fachliche Einstellungs-Panels. Neue/wichtige Felder bekommen ein `🆕`-Präfix im Label,
   vom Maintainer bei der übernächsten Version wieder entfernt.
4. **Symcon-Forum-Hinweis** — nach den Haupteinstellungen, ebenfalls dismissible (einmalig,
   nicht versionsscharf), verlinkt den Modul-Thread im Symcon-Forum.

Details und Begründung: siehe Memory `nrg-stack-formular-konvention`. Bestehende Module
rüsten bei Gelegenheit nach, neue Module (MeterHub/ChargerHub) bauen von Anfang an so.

**Status neben manuellen Fallback-Feldern** (verbundweite Konvention, 20.08.2026 — Dietmars
Einwand: ein leeres `SelectVariable`-Auswahlfeld sagt nicht, ob es gerade durch eine
automatische Discovery ohnehin überholt/unnötig ist, das musste er bislang selbst im Formular
erraten). Jedes `SelectVariable`-Fallback-Feld, das laut "Muster: neue Konsumenten-Features
müssen den bestehenden Discovery-Mechanismus nutzen" (siehe oben) NUR für Installationen ohne
das jeweilige Partnermodul gedacht ist, bekommt in `GetConfigurationForm()` (nicht `form.json`,
da live berechnet) direkt darüber ein `Label` mit einer der vier Ampel-Aussagen:
- ✅ **automatisch verbunden** — nennt Instanz-ID + Name + (wo sinnvoll) eine Kennzahl der
  gelieferten Daten (z. B. Anzahl Slots); das Feld darunter wird ignoriert.
- ⚠️ **Partnerinstanz gefunden, liefert aber gerade nichts Brauchbares** — Fallback-Feld ist
  aktiv, aber das ist wahrscheinlich ein Symptom eines anderen Problems, nicht der Normalfall.
- ℹ️ **keine Partnerinstanz gefunden, Feld optional** — Fallback-Feld wird zwar gebraucht,
  das Fehlen degradiert aber nur eine einzelne Funktion (z. B. der Tagesplan bleibt leer),
  der Rest von EMS funktioniert unverändert weiter.
- ⛔ **PFLICHTFELD, in Rot** (`'color' => 0xFF0000` im Label-Array) — nur wenn das Fehlen
  NICHT nur eine Funktion abschaltet, sondern EMS mit einem STILLSCHWEIGEND FALSCHEN Wert
  weiterrechnet (z. B. Batterie-SOC=0%, wenn weder InverterHub noch das manuelle Feld einen
  Wert liefern — EMS denkt dann faelschlich, die Batterie sei leer, und trifft Entscheidungen
  auf dieser falschen Grundlage). Der Unterschied zu ℹ️ ist bewusst scharf: ℹ️ heisst "eine
  Funktion fehlt einfach", ⛔ heisst "EMS entscheidet aktiv falsch, wenn das hier nicht gefuellt
  wird". Nicht inflationaer verwenden — die meisten Fallback-Felder sind ℹ️, nicht ⛔.

**Praezisierung 20.08.2026:** wirklich JE FELD, nicht ein Pauschalsatz oben im Panel ("schau im
Verbund-Status-Panel nach") — verschiedene Felder im selben Panel koennen unterschiedliche
Automatik-Wege haben (Beispiel Netzmesspunkte-Panel unten: Gesamtleistung hat einen echten
Automatik-Pfad, die Phasenwerte L1-L3/Frequenz/Status aktuell nicht). **Wenn fuer ein Feld gar
kein Automatik-Pfad im Code existiert, muss die Zeile das ehrlich sagen** ("ℹ️ EMS liest diesen
Wert aktuell nicht automatisch von einem Partnermodul") statt so zu tun, als waere das Feld
einfach nur zufaellig leer — sonst wird aus der Konvention selbst wieder eine neue Luecke, die
der Nutzer erraten muss.

Referenzimplementierungen (`module.php`, EMS 0.21.3):
- `getPT15MStatusLine()` — Tibber-Panel, PT15M-Preiskurve, echter Automatik-Pfad (ℹ️-Stufe,
  da nur der Tagesplan betroffen ist, kein ⛔).
- `getGridFieldStatusLine()` — Netzmesspunkte-Panel, gemischt: Gesamtleistung hat einen
  Automatik-Pfad (InverterHub `gridPowerID`), die uebrigen Felder ehrlich ohne.
- `getBatterySocStatusLine()` — Batteriespeicher-Panel, Bat1-SOC-Feld: erste ⛔-Stufe im
  Verbund, weil BuildDayPlan() den SOC bislang komplett am InverterHub-Automatik-Pfad vorbei
  gelesen hatte (`getCurrentBatterySoc()`, gleicher Fehlertyp wie die PT15M-Preise selbst —
  neues Feature nutzte die vorhandene Discovery nicht, EMS 0.21.3-Fix).
- `statusLabel()` — normalisiert String ODER `['caption'=>..,'color'=>..]`-Array zu einem
  fertigen `form.json`-Label-Element, damit jeder Status-Helper nur den Inhalt liefert, nicht
  das Wrapping wiederholt.

Alle ergaenzen (ersetzen nicht) das bestehende "🔗 Verbund-Status"-Panel oben im Formular —
jenes zeigt nur eine grobe Zusammenfassung pro Partnermodul-Typ (`InverterHub=1 MeterHub=1 ...`),
diese Konvention bringt die Aussage bis auf Feldebene runter, direkt neben das betroffene
Formularfeld selbst.

**Pflege-Pflicht:** Jedes Modul prüft bei JEDEM Fix/Update/Upgrade selbst, ob etwas ins
"Was ist Neu?" oder "Dokumentation & Hilfe" gehört — die Prüfung ist Pflicht, das Ergebnis
darf "nichts Relevantes" sein.

**Layout-Qualität:** logische Gruppierung zusammengehöriger Einstellungen, Step-by-Step-Fluss
ohne Hoch-/Runter-Scrollen bei aufeinander aufbauenden Angaben, Feldkanten (Label-/Eingabe-
Spalte) auf einer Linie statt kreuz und quer eingerückt.

**Feld-Hilfestellung (kein natives Mouseover-Tooltip in Symcon):** Geprüft gegen die
offizielle Symcon-Dokumentation, nicht angenommen — `form.json` kennt kein `tooltip`/`hint`-
Attribut, weder für normale Elemente noch für `List`-Spalten (deren komplette Attributliste
ist dokumentiert, "tooltip" fehlt). Für erklärungsbedürftige Einzelfelder statt Mouseover:
- **`PopupButton`** (`caption="?"`, `width="70px"`) direkt neben dem Feld — öffnet bei
  Klick ein Popup mit Hilfetext. Kein Hover, aber nah dran und pro Feld einsetzbar.
  **Nur diese beiden Properties sind bei `PopupButton` überhaupt gestaltbar** (`caption`,
  `width`, dazu enabled/visible/name/onClick/link/download/popup.*) — geprüft direkt gegen
  die SDK-Referenz: keine Icon-Größe, keine Hintergrundfarbe. Die blaugraue Fläche um das
  Icon ist der globale WebFront-Skin, gilt für JEDEN Button im System, nicht modul-spezifisch
  änderbar. `width` unter ~70px hat keinen sichtbaren Effekt (Skin erzwingt eine
  Mindestbreite, empirisch zwischen 70 und 80px ermittelt) — nicht darunter setzen, das ist
  wirkungslose Kosmetik und verschwendet nur Testzeit. `width="70px"` mit kurzer Caption
  (ein Zeichen wie `"?"`) ergibt eine quadratisch wirkende Fläche — "i" wirkt bei 70px
  Breite optisch verloren, "?" füllt die Fläche besser aus (Dietmars finale Entscheidung).
- **`Label`**-Element über/unter dem Feld für kurze, immer sichtbare Erklärungen.
- Für den Gesamtzusammenhang bleibt das bestehende "📖 Dokumentation & Hilfe"-Panel (Punkt 2
  oben) die richtige Stelle, nicht jedes Feld einzeln kommentieren.

**Einheitliche Verbund-Status-Kopfzeile** (verbundweite Konvention, 20.08.2026 —
Dietmars Einwand: jedes Modul baut sein "Gefunden/Verbunden"-Statuspanel gerade
irgendwie anders, EMS' bisheriger technischer Fließtext-Satz — z. B.
"NRG-Stack Partnermodule: InverterHub=1 MeterHub=1 ChargerHub=1 ..." — gefiel
ihm sichtbar am wenigsten im Vergleich zu einer anderen, knapperen Anzeige,
die er bereits in einem Modul gesehen hat). Referenz-Screenshot: ein Panel mit
Button "GERÄTE JETZT SUCHEN" darüber, direkt darunter eine EINZIGE Zeile
`✅ 12 Geräte gefunden (zuletzt 16:25:41 Uhr).` — großes Icon, eine Kernzahl,
Zeitstempel der letzten Suche, KEIN Aufzählungssatz. **Ursprung geklärt
(20.08.2026): Dashboards Tile-Modul, DiscoveryResult-Panel** — dessen Muster
existierte schon vor dieser Konvention genau in dieser Form.

Jedes Modul mit einer Discovery-/Geräte-such-Funktion baut sein Status-Panel
nach demselben Schema:
1. **Button zuerst** ("🔎 Jetzt neu suchen" / "Geräte jetzt suchen" o. ä.),
   danach erst die Statuszeile darunter (nicht umgekehrt).
2. **Eine Kopfzeile**, exakt im Muster `<Icon> <Zahl> <Was> gefunden (zuletzt
   <HH:MM:SS> Uhr).` — `✅` bei Erfolg (mind. 1 gefunden), `⚠️` bei 0 gefunden
   trotz vorheriger Suche, `ℹ️` wenn noch nie gesucht wurde. Erfordert ein
   eigenes `RegisterAttributeInteger('LastDiscoveryTs', 0)`, bei jeder Suche
   mit `time()` aktualisiert.
3. **Technische Detailaufschlüsselung** (je Partnermodul-Typ, Instanz-IDs,
   Verbund-Gesundheit) bleibt erhalten, wandert aber in ein eingeklapptes
   Unter-Panel ("Details je Partnermodul-Typ" o. ä.) statt Teil der Kopfzeile
   zu sein — Diagnosewert bleibt, drängt sich aber nicht mehr vor die
   Kernaussage.

Referenzimplementierung: EMS' `getDiscoverySummaryLine()` + das umgebaute
"🔗 Verbund-Status"-Panel in `GetConfigurationForm()` (`module.php`, 0.21.5).

**Ergänzung fuer passive Erkennung** (CometWiFi, 20.08.2026): nicht jedes Modul hat einen
aktiven "Jetzt suchen"-Knopf — z. B. wenn jede Abfrage ein Batteriegeraet unnoetig weckt,
bleibt die Erkennung rein passiv (wartet auf eingehende Meldungen). In diesem Fall **entfaellt
der Button**, und "zuletzt HH:MM:SS Uhr" bezieht sich auf die letzte EMPFANGENE Meldung, nicht
auf eine Suche — das gehoert als kurzer Halbsatz ins eingeklappte Detail-Panel, damit das
fehlende Bedienelement nicht wie ein Versaeumnis wirkt.

**Stolperstein, verbundweit relevant:** der Zeitstempel muss bei JEDER Meldung/jedem Fund
fortgeschrieben werden, nicht nur beim allerersten — sonst altert die Kopfzeile sichtbar, waehrend
das System eigentlich munter weiterläuft (sieht aus wie ein haengender Empfang, obwohl alles
funktioniert). Mit einem Test/einer Gegenprobe gegen genau diesen Fall absichern (CometWiFi: 7
eigene Pruefungen dafuer).

## Grundregel: keine eigene Anlage als Norm annehmen (27.07.2026)

Ausgelöst durch direktes, wiederholtes Nutzer-Feedback an EMS — dieselbe Fehlerklasse trat an
mehreren Stellen unabhängig auf, deshalb hier als eigene, verbundweite Regel festgehalten
statt nur im jeweiligen Modul-Changelog:

**Der Fehler, in drei Varianten, alle heute live gefunden:**
1. **Ein Feld/Panel stellt ein bestimmtes Fabrikat als "Pflicht" oder alternativlos dar**,
   obwohl es nur EIN Beispiel oder EINE von mehreren unterstützten Optionen ist (EMS:
   "Primärmessung: Goodwe SmartMeter (Pflicht)" — dabei deckt MeterHub praktisch jede
   Zählermarke ab; MeterHub: PAC2200 stand in der manuellen Instanz-Anlage bereits
   vorausgewählt ohne Hinweis, dass das nur Platzhalter ist; ChargerHub: Verbindungsfelder
   wirkten wie Pflicht-Handeingabe, obwohl Discovery sie normalerweise automatisch befüllt).
2. **Ein Formular schweigt dazu, ob ein Wert automatisch (Discovery/Vertrag) kommt oder
   manuell verknüpft werden muss** — der Nutzer kann nicht entscheiden, ob ein leeres Feld
   ein Problem ist oder der Normalzustand.
3. **Eigene, konkrete Installationsdetails des Autors stehen im Formular, als wären sie
   allgemeingültig** — EMS hatte 20 Feld-Captions mit Dietmars eigenen Symcon-Variablen-IDs
   fest im Text (z. B. "Startzeit 1 (ID 53840)"), StromGedacht deckt nur Baden-Württemberg ab
   und das stand nur im README statt im Formular selbst (fiel im eigenen Betrieb nie auf,
   weil die eigene PLZ zufällig im Abdeckungsgebiet liegt), Tibber Grid Reward hatte
   `Paragraph14aEnabled` per Default auf `true` (Dietmars eigene Teilnahme, nicht der
   Normalfall) — das hätte einem neuen Nutzer ohne §14a-Teilnahme still falsche Zahlen
   geliefert.

**Warum das strukturell passiert:** Jedes Modul wird zuerst gegen die eigene, reale Anlage
des jeweiligen Entwicklers gebaut und getestet. Was dort funktioniert/zutrifft, fühlt sich an
wie der Normalfall — bis ein Nutzer mit anderer Hardware, anderem Bundesland oder ohne die
eigene Sonderkonfiguration auf das Formular trifft. Der Fehler ist unsichtbar für den, der ihn
gemacht hat, gerade weil die eigene Anlage ihn nie auslöst.

**How to apply:** Bei jedem neuen Formularfeld und bei jeder Doku-/Hilfetext-Ergänzung aktiv
fragen: "Gilt das für JEDEN Nutzer, oder nur für meine/Dietmars eigene Anlage?" Bei
Beispielwerten/-geräten immer explizit als Beispiel kennzeichnen ("z. B. …"), nie als
Vorgabe/Pflicht. Eigene Variablen-IDs, PLZ-Gebiete, Kampagnen-Rabatte, Tarif-Teilnahmen o. ä.
gehören nicht mit dem eigenen, konkreten Wert ins Formular — und Default-Werte, die eine
eigene Sonderkonfiguration widerspiegeln, verzerren für jeden neuen Nutzer die Ergebnisse.

## Verbund-weiter Arbeitsbranch `ems-integration`

Solange die EMS-Integrationsphase läuft, bekommt JEDES Modul-Repo einen Branch
**`ems-integration`** (von `beta` abgezweigt, identischer Name überall). Zweck: alle
Änderungen, die aus der laufenden EMS-Anbindung entstehen, landen dort — auch scheinbar
sichere, verhaltensneutrale Fixes (verschärft am 25.07.2026, keine Ausnahme mehr) —, damit
während dieser Phase nichts Ungeprüftes bei echten Symcon-Beta-Testern landet, die bereits
`beta` installiert haben. Erst nach Bewährung an Dietmars Live-Instanz Merge nach `beta`.
Regelmäßig mit `beta` synchron halten (Fast-Forward, solange nichts Eigenes committet ist).

## Symcon-Store-Review-Checkliste

Gesammelte Review-Bemerkungen aus allen Modulen mit `main`-Branch/Store-Historie — bei
jedem neuen Modul von Anfang an einhalten:

1. Keine Selbstpersistenz in Formular-Buttons (`IPS_SetProperty`+`ApplyChanges` in `onClick`
   verboten, nur `UpdateFormField`).
2. `vendor` in `module.json` = Hersteller des angebundenen Geräts/Diensts, nicht Modulentwickler
   (bleibt leer bei reinen Software-Modulen; `author` in `library.json` bleibt immer DG65).
3. Listen-Properties nicht mit berechneten Anzeigespalten zurückschreiben — `loadValuesFromConfiguration: false`
   + vollständige `values` in `GetConfigurationForm()` injizieren.
4. "Ablageort"/Kategorie-Auswahlfeld ohne Property, kein Auto-Verschieben in `ApplyChanges` —
   `SelectCategory` liest `IPS_GetParent()`, `onChange` ruft einmalig `IPS_SetParent()`.
5. Custom-Variablenprofile nur bei Erstanlage setzen, nie bei jedem `ApplyChanges` erzwingen.
6. Modulnamen im Store ohne "IPS"/"Symcon".
7. `Stable`-Kanal darf keine neuere Symcon-Version voraussetzen als aktuell stabil.
8. `library.json` akzeptiert NUR: id, author, name, url, compatibility, version, build, date.
   `compatibility` als `{"version":"X.Y"}`, nicht `{"minimum":"X.Y"}`. Trifft schon den
   ersten Beta-Upload (automatischer Validator).
9. `Translate()`-Quellstrings im Code bleiben englisch, Übersetzung in `locale.json`.
10. `onClick`-Handler nutzen `$id`, nicht `$_IPS['TARGET']` (nur in Timer-Skripten verfügbar).
11a. **Presentation-OPTIONS-Arrays vor jeder Veroeffentlichung auch in der Mobile-App
    testen, nicht nur Web/Konsole** (13.08.2026, siehe "IP-Symcon-Stolperfallen"
    Punkt 9). `grep -rn "ColorValue\|ColorActive"` als Schnelltest — beide sind
    keine gueltigen Options-Schluessel und stuerzen nur die Mobile-App ab.
11. **Empfehlung, kein Blocker:** Properties vs. Attribute bei Kachel-editierbarer Konfiguration
    — Attribute wären "sauberer", aber IPS-Listen mit Auto-Speichern funktionieren nur mit
    Properties. Modulweise abzuwägen (StromGedacht/Tessie bewusst bei Properties geblieben).
12. **Neuinstallations-Simulation** (27.07.2026, siehe "Grundregel: keine eigene Anlage als
    Norm annehmen"). Vor jeder Veröffentlichung/jedem beta→main-Wechsel: Formular komplett
    durchgehen, als wäre man ein Nutzer OHNE die eigene Hardware/Region/Sonderkonfiguration
    des Entwicklers. Konkret abhaken, nicht nur pauschal bestätigen:
    - Jedes Feld/Panel, das ein bestimmtes Fabrikat/Gerät nennt: steht dabei "z. B." oder klingt
      es wie eine Voraussetzung? Nur "z. B." ist zulässig, außer das Modul ist tatsächlich fest
      an einen Hersteller gebunden (dann muss das selbst so im Modulnamen/Doku stehen, keine
      Überraschung erst im Formular).
    - Jedes Feld, dessen Wert auch automatisch (Discovery/Vertrag) kommen könnte: steht dabei,
      WANN es überhaupt manuell ausgefüllt werden muss (und wo man sieht, ob Discovery schon
      gereicht hat)?
    - Jeder Default-Wert: würde er für einen Nutzer OHNE die eigene Sonderkonfiguration
      (Region, Tarif-Teilnahme, Kampagne, Testinstallation) ein falsches/verzerrtes Ergebnis
      erzeugen, ohne dass ein Fehler sichtbar wird? Sicherheitsdefault ist immer "aus"/neutral,
      nie "so wie bei mir".
    - Volltextsuche im Formular-Code nach eigenen Symcon-Objekt-/Variablen-IDs, eigenen PLZ/
      Adressen, eigenen Vertrags-/Kampagnennamen — sowas gehört nie in eine Nutzer-sichtbare
      Caption.
    Zweite Person/Session prüfen lassen, wo möglich (heute z. B. Dashboard/InverterHub
    gegenseitig) — wer das Formular selbst gebaut hat, übersieht die eigenen blinden Flecken
    strukturell am ehesten, gerade weil die eigene Anlage den Fehler nie auslöst.
13. **Jede Aktion (Button/`RequestAction`) muss eine sichtbare Rückmeldung geben** (20.08.2026,
    verbindlich für alle Module — siehe eigener Abschnitt "Sichtbare Rückmeldung bei jeder
    Aktion" weiter unten). Vor jeder Veröffentlichung: jeden Button im Formular durchklicken
    und konkret prüfen — ändert sich sichtbar etwas (Text, Popup, Status), oder sieht es aus,
    als wäre nichts passiert?

Details und Quellenzuordnung je Punkt: Memory `nrg-stack-store-review-erkenntnisse`.

## Sichtbare Rückmeldung bei jeder Aktion (verbindlich, 20.08.2026)

Ausgangspunkt: zwei Live-Funde an EMS selbst am selben Tag — der "🔎 Jetzt neu suchen"-Button
aktualisierte serverseitig alles korrekt, aber das offene Formular zeigte weiter "Noch nicht
gesucht" (SUITE.md Stolperfalle 12); der "📅 Tagesplan neu berechnen"-Button gab überhaupt
keine Rückmeldung. Dietmars Formulierung: *"Rückmeldungen bei allen Aktionen, damit man sieht,
dass etwas passiert ist."* Das ist keine Empfehlung mehr, sondern eine **Pflicht für jeden
Button/jede `RequestAction`** in jedem NRG-Stack-Modul, unabhängig davon, ob die Aktion
fehlschlagen kann oder nicht — auch ein Erfolg ohne jede sichtbare Reaktion wirkt wie ein
Fehlschlag.

**Zwei zulässige Muster, je nach Aktionstyp:**

1. **Einmalige Aktion mit einem klaren Ergebnis** (Neuberechnung, Test-Verbindung, manueller
   Trigger) — die aufgerufene Methode gibt einen menschenlesbaren Ergebnistext zurück
   (✅/⚠️/⛔/ℹ️-Präfix), der `onClick` lautet `echo Prefix_Methode($id, ...);` statt nur
   `Prefix_Methode($id, ...);` — erzeugt sofort ein Popup mit dem Ergebnis. Kein neues
   Formularfeld nötig. Referenz: EMS' `BuildDayPlan()` (0.21.11), Vorbild war der schon
   länger bestehende "Status anzeigen"-Button.
2. **Wiederkehrender/dauerhafter Status** (Geräte-Discovery, Verbindungsstatus, laufender
   Prozess) — eigenes benanntes `Label` im Formular plus `UpdateFormField('<Name>', 'caption',
   $neuerText)` am Ende der aufgerufenen Methode (siehe SUITE.md Stolperfalle 12 für Details
   und die `ReloadForm()`-Alternative). Referenz: EMS' `getDiscoverySummaryLine()` (0.21.7).

**Faustregel zur Wahl:** Führt der Button eine einmalige Aktion mit einem Ergebnis aus, das
man einmal lesen und dann wegklicken will → Muster 1 (`echo`). Zeigt ein Formularfeld einen
Zustand, der auch ohne den Button-Klick relevant bleibt (z. B. "wie viele Geräte sind gerade
verbunden") → Muster 2 (`UpdateFormField`).

**Muster 3: WebFront-Kachel/`RequestAction()` ohne Formular-Kanal** (WPHub, 20.08.2026 —
berechtigte Nachfrage zum Geltungsbereich: gilt die Konvention auch für Steuerelemente
AUSSERHALB des Konfigurationsformulars, z. B. Slider/Schalter auf einer WebFront-Kachel, wo es
weder `echo`-Popup noch ein zusätzliches `Label` gibt?). **Ja, die Konvention gilt auch dort**
— der Mechanismus ist zwangsläufig ein anderer, weil WebFront-Kacheln keinen Popup-/Label-Kanal
haben, aber das Prinzip bleibt: der Nutzer muss ohne Nachdenken sehen, ob die Aktion griff.
- **Erfolg:** `SetValue()` auf die tatsächlich bestätigte Variable — der Schalter/Slider zeigt
  danach den wirklich erreichten Zustand, nicht nur den angeforderten.
- **Fehlschlag:** die Variable NICHT auf dem angeforderten (aber nicht erreichten) Wert stehen
  lassen — explizit auf den zuletzt bestätigten Ist-Wert zurücksetzen, damit der Regler/Schalter
  sichtbar "zurückspringt" statt einen nie eingetretenen Erfolg vorzutäuschen. Das sichtbare
  Zurückspringen IST hier die Rückmeldung — kein Popup nötig oder möglich. Zusätzlich wie gehabt
  eine Protokollzeile fürs Debugging, aber die reicht allein NICHT (die sieht der Nutzer im
  WebFront nicht direkt).

**Muster 4: Direktaufruf einer IPS-Kernfunktion (kein eigener Modul-Wrapper)** (CometWiFi,
20.08.2026, am Beispiel des "🔄 Übernehmen erzwingen"-Buttons `IPS_ApplyChanges($id)`). Eine
Kernfunktion wie `IPS_ApplyChanges()` hat keine eigene Modulmethode, die einen Ergebnistext
liefern könnte — die Rückmeldung MUSS deshalb direkt im `onClick` selbst mitgebracht werden,
z. B. `IPS_ApplyChanges($id); echo '✅ ApplyChanges() ausgeführt.';`. Zusätzlich gilt: **bevor
so ein bequemer "spart mir Formular-Anfassen"-Button eingebaut wird, ehrlich dokumentieren, ob
die aufgerufene Funktion in diesem Modul wirklich folgenlos ist** — bei Modulen mit
Aktor-Charakter (z. B. Batteriegeräte, die durch jede Abfrage geweckt werden) lädt ein
bequemer Knopf zum Mehrfachklicken ein. Ein kurzer Hinweistext direkt am Button ("sendet keine
Befehle, wendet nur die gespeicherten Einstellungen an") gehört dazu, nicht nur der Klick
selbst.

**Pflicht-Check vor jeder Veröffentlichung** (siehe auch Store-Review-Checkliste Punkt 13):
jeden Button im Formular klicken und fragen: *sehe ich JETZT, ohne das Formular neu zu öffnen,
dass etwas passiert ist?* Wenn nein — Muster 1 oder 2 nachrüsten, je nach Aktionstyp.

**Drei weitere Praxis-Regeln (CometWiFi, 20.08.2026, an einem Batteriegeraete-Modul gehaertet —
besonders kritisch dort, weil jeder unnoetige Klick ein Geraet unnoetig weckt):**

1. **"Gesendet" ist nicht "beantwortet" — das gehoert in den Text.** Bei allem, was ueber eine
   Warteschlange, ein Funkprotokoll oder eine Cloud laeuft (verzoegerte Antwort, keine
   Garantie), waere ein blosses "✅ Erfolg" formal korrekt aber praktisch irrefuehrend — der
   Nutzer sieht danach unveraenderte Werte und haelt das Modul fuer kaputt. Formulierung
   stattdessen z. B. "gesendet, Werte erscheinen sobald das Geraet antwortet".
2. **Bei Sammelaktionen (mehrere Geraete/Instanzen in einem Klick) Zahlen nennen, nicht nur
   ja/nein.** "An 4 von 5 Geraeten erfolgreich" statt eines pauschalen Hakens — der
   Teilerfolg ist der haeufigste UND am schwersten zu bemerkende Fall; ein reines
   "erfolgreich/fehlgeschlagen" kann ihn gar nicht ausdruecken und versteckt das eine
   Problemgeraet dauerhaft.
3. **KRITISCH — Text-als-`bool`-Falle bei Methoden, die von einem ANDEREN Modul MASCHINELL
   aufgerufen werden** (z. B. `*_GetFunctions`-Vertragsmethoden oder sonst irgendein Aufruf,
   dessen Rueckgabewert programmatisch geprueft wird, nicht nur per `echo` angezeigt). Ein
   Fehlertext ist ein nicht-leerer `string` und damit in PHP immer `true` — wird eine
   bool-liefernde Methode auf einen Klartext-Rueckgabewert umgestellt, meldet jeder
   programmatische Aufrufer ab sofort STILLSCHWEIGEND Erfolg, auch bei einem Fehlschlag. Lösung:
   fuer maschinelle Aufrufer eine GETRENNTE `bool`-liefernde Methode behalten/anlegen, niemals
   Erfolg aus einem Anzeigetext zurueckparsen (bricht bei der naechsten Textumformulierung,
   ohne dass irgendwo etwas rot wird). **Bei EMS besonders relevant**, weil EMS als
   Koordinator selbst Vertragsmethoden anderer Module aufruft und umgekehrt `EMS_GetSpecialEvents()`
   von "lernenden Modulen" maschinell konsumiert wird — vor jeder Rueckgabetyp-Aenderung an
   einer oeffentlichen Methode pruefen, ob sie ausserhalb des eigenen Formulars aufgerufen wird.
   Audit 20.08.2026: EMS' `BuildDayPlan()` (jetzt `string`-Rueckgabe) wird nirgends
   programmatisch als `bool` konsumiert, `GetPartners()`/`GetFederationHealth()`/
   `GetSituation()`/`GetSpecialEvents()` liefern weiterhin strukturierte Daten (keine
   Klartext-Umstellung) — kein Fund, aber der Check selbst gehoert ab jetzt vor jede
   Rueckgabetyp-Aenderung.
4. **Rückmeldung muss auch sagen, ob eine Änderung schon gespeichert ist oder noch
   "Übernehmen" fehlt** (HeishaMon, 20.08.2026). Ein Button, der eine Liste/ein Feld nur in
   der GERADE OFFENEN Maske per `UpdateFormField()` ändert (Vorschau, Sortierung o. ä.), ohne
   dass die Property selbst schon geschrieben ist, muss das im Rückmeldungstext klarstellen —
   sonst wirkt "sichtbar geändert" wie "gespeichert", und der Nutzer schließt das Formular ohne
   zu übernehmen, im Glauben es sei bereits fertig.

## Manifest

### Suite 2026.07 (in Vorbereitung — Beta-Stände, noch kein abgeschlossener Testsatz)

| Modul | Version (Stand 24.07.2026) | Kanal | Verträge (angeboten) |
|---|---|---|---|
| EMS | 0.22.6 (20.08.2026: Tagesplan-Umbau + `EMS_GetDayPlan()` fuer Dashboard-Visualisierung + ct/€-Einheiten-Fix + Export-vs-Entladen-Logikfehler behoben, **noch nicht mehrtägig live verifiziert**, siehe EMS/CLAUDE.md) | ems-integration | konsumiert alle; künftig `EMS_GetSpecialEvents`. Steuerlogik jetzt vorausschauend: `BuildDayPlan()` plant alle 96 Tages-Viertelstunden aus PT15M-Preisen (automatisch via `TIBBERGR_GetPriceCurve`, Fallback manuell, korrekt ct→€ umgerechnet)+PVF+Lastschätzung, sichtbar als Symcon-Wochenplan unter der Instanz (Vorbild: Dietmars Winterskript #55729), `optimize()` fragt nur noch den Plan ab statt live gegen Schwellwerte zu prüfen. Alte `SetECOWindow()`-Planer entfernt. `EMS_GetDayPlan()` **1.0** liefert heute+morgen (Zeit/Op/Preis in ct/kWh/simulierter SOC je Slot, plus `priceUnit`-Feld) fuer externe Visualisierung — der native Kalender bleibt auf "heute" begrenzt (Kalender-Typ-Grenze). |
| InverterHub | 0.74.x-beta.2 (27.07.2026, Commit 2d8228f) | ems-integration | ⚠️ **Bindungsfix vorhanden, Langzeitstabilität noch nicht bestätigt.** `IHUB_GetFunctions` **1.0** live verifiziert, Skript-Schreibzugriff via `IPS_RequestAction($InstanceID, $Ident, $Value)` funktioniert zuverlässig (27.07.2026 live bestätigt: `ctl_work_mode`/`ctl_ems_mode`/`ctl_ems_enable`). Root Cause der wiederholten Bindungsabrisse (4x am 26.07.2026, auch ohne Reload) gefunden und behoben: `EnableAction()` bindet Variablen nur, wenn sie DIREKTES Kind der Instanz sind — die control-Variablen lagen aber in der Unterkategorie "EMS-Steuerung"/`cat_control`. Fix: Variable kurz zur Instanz zurückhängen, binden, zurück in die Kategorie. Vor jedem weiteren Release erneut über mehrere Stunden/Reload-Zyklen verifizieren, bevor die Warnung entfällt. Ident-Tabelle siehe unten. Siehe `nrg-stack-modulverwaltung-instabilitaet`-Memory. |
| MeterHub | 0.18.0-beta.1 (Build 28) | beta | `MHUB_GetFunctions` **1.1**, `MHUBV_GetFunctions` **1.1** (1.1 = latency/authority/pollInterval/energyKind/sourceCount) |
| ChargerHub | 0.9.14-beta.1 | ems-integration | `CHUB_GetFunctions` **1.1** (inkl. `managedBy`), Schreibzugriff via `IPS_RequestAction($InstanceID, $Ident, $Value)` (live verifiziert 25.07.2026, echtes Fahrzeug an WB1: 6A/20W → 10A/4310W) |
| MigrationsHub | 0.1.x-beta | beta | (Werkzeug, kein Datenvertrag — Kompatibilitätsgröße sind die Idents der Zielmodule) |
| SteuerboxHub | in Arbeit (PR #1) | ems-integration | `SBH_GetState` **1.0** (final freigegeben, zwei Achsen: load*/feedIn*; Kontakte-Weg + GZF-Rechenhilfe funktionsfähig, EEBus als Konfigurationsgerüst zurückgebaut, Test gegen echte Instanz steht noch aus) |
| Prognose (PVF/LFC/Bilanz) | 0.20-beta (Build 51) | beta (Store) | `PVF_Get*` **1.0**, `LFC_Get*` **1.0** |
| HeishaMon | 1.1.1 main / 1.3.0 beta, **1.4 auf ems-integration** (Stand 13.08.2026, Commit 3f2fcc2) | Store / ems-integration | `HEISHA_GetFunctions` **1.4** auf ems-integration (Anlagenschema-Felder: 14 Pumpen-/Ventil-/Temperaturfelder in 1.3 + 4 externe Heizkreis-Pumpe/Mischventil-Felder z1/z2 in 1.4, additiv); **1.1** auf beta 1.3.0 (unit-Feld); main liefert keins der Felder → gilt als 1.0 |
| WPHub | 0.1.0 Build 2 (10.08.2026, neu) | ems-integration | `WPHUB_GetFunctions` **1.2** (Type=>'heatpump', konsistent zu HeishaMon 1.2). Panasonic Comfort Cloud, `PowerID`/`EnergyID` bewusst 0 (Cloud liefert keine Momentanleistung/kumulativen Zähler, siehe neue Grundregel bei "Gemeinsame Variablenprofile") — noch nicht am echten Konto verifiziert |
| TibberGridRewards | 2.0.0 main / 2.8.0 beta | Store | `TIBBERGR_GetPriceCurve` **1.1**, `TIBBERGR_GetTariffConfig` **1.1**, `TIBBERGR_GetActiveControls` **1.0**, `TIBBERGR_SetVehicleSetting` (Abfahrtszeit/Mindest-SoC-Präferenz, kein Vertrag mit contractVersion — reverse-engineerte externe API, siehe SUITE.md-Historie) — main ohne Felder → gilt als 1.0 |
| StromGedacht | 1.3 Store / 1.5.0 beta | Store | `SGW_Update`, DataActions; `SGW_GetState`+`SGW_GetForecast` **1.0** (final freigegeben, Empfehlungscharakter, nur Netzampel planungsrelevant) |
| Tessie | 2.3.4 main / 2.22.0 beta | Store | `TESSIE_GetVehicleState` **1.1** (ab beta 2.20.0; main ohne Feld → gilt als 1.0, Zusatzfelder erst nach Promotion) |
| GleitenderMittelwert | 1.7.1 | Store | (Hilfsmodul, kein Verbund-Vertrag) |
| GoodweET | Deprecated (2026-07-25) | — | abgelöst durch InverterHub (Adoption abgeschlossen, siehe GoodweET/README.md) |
| CometWiFi | 0.17.0 (Build 34) | beta + main gleichauf | (Gerätemodul, kein `*_GetFunctions`-Vertrag — Thermostate messen nur Temperatur, keine Leistung). Fünf Instanzen: Thermostat, Konfigurator, Übersichtskachel, Raumkachel, Raum. Anbindung über lokalen MQTT-Broker mit Bridge je Gerät zur Hersteller-Cloud (Hersteller-App bleibt funktionsfähig). Protokoll vollständig reverse engineered, Registerstand in `.docs/protokoll.md`. Schreibrichtungen belegt: Sollwert, Optionen, Urlaub, Wochenprogramm, Geräteuhr. |
| ModbusSlave (NRGModbusSlave) | 1.4.0 | ems-integration | (Export-Endpunkt: Modbus-TCP-Server für externe Master, kein `*_GetFunctions`-Vertrag) — blue'Log-RPC-Emulation als Direktvermarktungs-Andockpunkt; künftig Quelle für `EMS_GetSpecialEvents` (`source: 'marketer'`) |
| NRGDashboard | 0.1.0-beta.1 | beta | (Darstellungsschicht, kein Datenvertrag — konsumiert alle *_GetFunctions/GetState) |
| Szenariorechner | 0.2.0-beta.1 | ems-integration | (Analysewerkzeug, kein Datenvertrag — konsumiert Tibber/Prognose/Archive Control) |

Das erste **abgeschlossene** Suite-Release wird ausgerufen, wenn ein Satz von Ständen
gemeinsam an Dietmars Anlage verifiziert ist; ab dann wird je Release eine neue
Manifest-Tabelle ergänzt (alte bleiben stehen).

**Präzisiert (Dietmar, 24.07.2026):** SteuerboxHub blockiert das erste Suite-Release NICHT —
die Hardware existiert bei Dietmar auf absehbare Zeit nicht, und die Eigenständigkeitsregel
sichert ohnehin einen sauberen Betrieb ohne SteuerboxHub (nur die §14a-Erkennung fehlt dann,
nichts bricht). Voraussetzung bleibt, dass das EMS selbst existiert und die zentralen
Verträge (`GetActiveControls`, `SGW_GetState`/`GetForecast`, `EMS_GetSpecialEvents`) einmal
live durchgespielt sind. **Die beta→main-Promotion-Welle der Module IST der Suite-Release-
Moment**, kein separates nachgelagertes Ereignis — wenn die Module gemeinsam auf `main`
gehoben werden, wird genau das als erstes Suite-Release deklariert.

## IP-Symcon-Stolpersteine (verbundweit, hart erarbeitet 25./26.07.2026)

Diese Punkte kosten sonst jedes Mal Stunden. Für jede Session (auch ohne Zugriff auf externes
Gedächtnis) gilt: bei genau diesen Symptomen zuerst hierher schauen.

**1. `RequestAction()` wird NIE als globale `Prefix_RequestAction()`-Funktion exponiert.**
Anders als jede andere `public function` einer Modulklasse (die automatisch als
`PREFIX_MethodName()` global aufrufbar wird) ist `RequestAction($Ident, $Value)` ein
IPSModule-Kernel-Lifecycle-Name — der Kernel generiert dafür keinen Wrapper. Der korrekte
Einstiegspunkt von außen (Skript, Konsole) ist der Kernel-eigene Aufruf
`IPS_RequestAction($InstanceID, $Ident, $Value)`, der intern die `RequestAction()`-Methode
der Instanz aufruft.

**2. Für modul-eigene Variablen (per `RegisterVariableXXX()` selbst angelegt) ist
`$this->EnableAction($Ident)` die richtige API, NICHT `IPS_SetVariableCustomAction($vid, ...)`.**
Letztere ist für FREMDE Variablen gedacht (von einer anderen Instanz angelegt) und schlägt für
eigene Variablen strukturell fehl — ohne Fehler, ohne Exception, einfach wirkungslos
(`VariableAction` bleibt `0`). Symptom beim Nutzer: WebFront-Klick auf einen Steuer-Schalter
scheitert mit "Action is invalid" (-32603), während `IPS_RequestAction()` aus einem Skript
weiterhin funktioniert (der geht direkt an die Instanz, ohne über die Variablen-Bindung zu
laufen).

**3. `RegisterVariableXXX()` niemals bedingungslos bei jedem `ApplyChanges()` für eine
BEREITS bestehende Variable erneut aufrufen** — kollidiert mit "Ident muss für jede Ebene
eindeutig sein", die ganze Transaktion bricht ab (inkl. aller in derselben Transaktion
gesetzten `EnableAction()`-Bindungen, die dadurch verloren gehen, obwohl sie scheinbar
erfolgreich liefen). Nur bei echter Neuanlage aufrufen: `if (!$this->FindVarByIdent($ident)) { ... }`.
**Einfacher: `MaintainVariable()` statt `RegisterVariableXXX()` verwenden** — der eingebaute
IP-Symcon-SDK-Helfer ist idempotent (create-or-update) und umgeht das Problem strukturell,
ohne eigene Existenzprüfung. Mehrere Module (Tessie, HeishaMon, StromGedacht) nutzen das
bereits durchgängig und sind von diesem Bug nie betroffen gewesen.

**4. Optionale Variablen-Gruppen (`GroupControl` u. ä.) sind ALLES-ODER-NICHTS-Schalter.**
Steht die zugehörige Property auf `false`, verschwindet die komplette Kategorie inkl. aller
Variablen — nicht nur "ausgegraut". Wenn eine ganze Funktionsgruppe (z. B. die komplette
EMS-Steuerung eines Wechselrichters) plötzlich spurlos weg ist, zuerst diese Property prüfen,
bevor man einen komplexeren Bug vermutet.

**5. Modulverwaltung synct getrackten Commit nicht zuverlässig mit dem installierten Code.**
Weder der "Aktualisieren"-Button noch `MC_UpdateModule()`/`MC_ReloadModule()` sind
verlässlich. Einziger zuverlässiger Weg: `MC_DeleteModule()` + `MC_CreateModule()` +
`MC_UpdateModuleRepositoryBranch()`, und zwar **einzeln pro Modul, nicht als Batch-Schleife**
(ein Durchlauf über mehrere Module in einer Schleife führte wiederholt dazu, dass einzelne
Module auf den alten Stand zurückfielen). Nebenwirkung: löscht Instanz-**Attribute** (nicht
Properties) aller Instanzen dieses Moduls — bei Modulen mit Zugangsdaten in Attributen
(z. B. TibberGridRewards' OAuth-Passwort) danach proaktiv um Neueingabe bitten.

**6. Ist ein Modul zusätzlich zur git-getrackten Modulverwaltungs-Kopie auch über den
offiziellen Symcon Module Store gebucht, führt das zu genau diesem Sync-Chaos** — bei jedem
Neustart kann der Store-Stand die Modulverwaltungs-Kopie überschreiben. Bei eigenen,
aktiv entwickelten NRG-Stack-Modulen: nie zusätzlich im Store buchen/installieren.

**7. `IPS_RequestAction($InstanceID, $Ident, $Value)` erwartet als ersten Parameter die
INSTANZ-ID, nicht die Variablen-ID.** Klingt trivial, hat aber am 27.07.2026 stundenlang
einen bereits funktionierenden Fix als "wirkungslos" erscheinen lassen, weil beim
Diagnose-Skript versehentlich die Variablen-ID übergeben wurde — der Aufruf lief ohne
Fehler/Exception durch (!), schrieb aber nichts. Bei jedem "RequestAction läuft ohne Fehler,
aber ohne Wirkung"-Symptom zuerst genau diesen Parameter prüfen, bevor man einen neuen Bug
im Zielmodul vermutet.

**8. PHP-Default-Werte optionaler Parameter gelten NICHT für öffentliche `PREFIX_`-Funktionen
(die, die andere Module per RPC aufrufen — `MHUB_*`, `CHUB_*`, `IHUB_*`, `MIGHUB_*` usw.).**
Live gefunden (MigrationsHub, 31.07.2026): ein 5. Parameter mit PHP-Default `= 0` an
`MIGHUB_FindLegacyCandidates()` sollte additiv sein (klassische Minor-Erweiterung nach
unserem `contractVersion`-Modell) — Aufruf mit den bisherigen 4 Parametern (ChargerHub)
knallte trotzdem mit `ArgumentCountError: Too few arguments ... 4 passed, exactly 5
expected`. Der IPS-Kernel generiert die öffentliche Präfix-Funktion offenbar mit fester
Arität; PHP-Default-Werte gelten nur für rein interne Aufrufe innerhalb derselben Klasse,
nicht für externe RPC-Aufrufer über den generierten Wrapper.
**Konsequenz fürs Vertragsmodell:** Ein neuer Parameter an einer öffentlichen
`PREFIX_`-Funktion ist IMMER ein Breaking Change für externe Aufrufer, AUCH MIT
PHP-Default — das gilt unabhängig vom `contractVersion`-Modell für Rückgabewerte (siehe
oben, Abschnitt "Vertragsversion"), das ausdrücklich nur additive RÜCKGABE-Felder erlaubt.
Ein neuer Parameter braucht denselben Koordinationsaufwand wie ein Major-Bruch: alle
bekannten Aufrufer müssen vorher synchron umgestellt werden, nicht "läuft von selbst mit,
weil er einen Default hat".

**9. Presentation-OPTIONS-Arrays (ENUMERATION/VALUE_PRESENTATION) mit falschem
Farb-Schluessel bringen NUR die Mobile-App zum Absturz, nicht Web/Konsole.**
Live gefunden (HeishaMon, 13.08.2026, ueber eine Forum-Rueckmeldung eines
Beta-Testers auf iPad): gueltige Options-Schluessel sind `Value`, `Caption`,
`IconActive`, `Icon`, `Color` — NICHT `ColorValue` oder `ColorActive` (letztere
existieren nicht, auch wenn sie plausibel klingen). Der IPS-Kernel normalisiert
NICHT und speichert falsche Schluessel unveraendert; Web-Konsole toleriert das
stillschweigend, die Flutter-basierte Mobile-App stuerzt dagegen mit "Invalid
Configuration: type 'Null' is not a subtype of type 'int'" ab, sobald die
betroffene Kachel geoeffnet wird. Von Symcon-Staff im Community-Forum als
bekanntes Muster bestaetigt (Thread 143170). **Praktische Konsequenz: Ein
Presentation-Bug kann sich beliebig lange NUR in der Web-Konsole korrekt
anfuehlen und faellt erst auf, wenn ein Mobile-Nutzer die betroffene Variable
oeffnet** — deshalb bei jedem Store-Review/Neuinstallations-Test (Punkt 12 der
Checkliste unten) auch einmal in der Mobile-App pruefen, nicht nur Web/Konsole.
**Pruefbefehl:** `grep -rn "ColorValue\|ColorActive" <modul>/` — jeder Treffer in
einer Presentation ist ein Mobile-App-Absturz-Kandidat. **Migrations-Hinweis, praezisiert (Tessie, 15.08.2026, empirisch am Live-System
getestet):** Nur relevant, wenn die Presentation per `Register*XXX()` EINMALIG bei
Neuanlage gesetzt wird — dort bekommen Bestandsinstallationen den Fix nie
automatisch, es braucht eine zusaetzliche einmalige Auffrischung in
`ApplyChanges()` (mit Vergleichs-Guard gegen unnoetige Update-Stuerme). Module,
die durchgehend `MaintainVariable()` nutzen (idempotentes create-or-update, siehe
"IP-Symcon-Stolperfallen" Punkt 3), brauchen KEINEN Sonderfall — bestaetigt:
`IPS_SetVariableCustomPresentation()` (das `MaintainVariable()` intern aufruft)
ueberschreibt eine bestehende Presentation bei jedem erneuten Aufruf korrekt, der
Fix zieht also automatisch beim naechsten regulaeren `ApplyChanges()`-Lauf nach.

**10. Eine eigene Kachel "aufziehen" (Doppelpfeil/Vollbild) zeigt NIE das
eigene Kachel-HTML, sondern immer die Standardansicht der Instanz-Kinder
(Variablen/Verknüpfungen).** Live gefunden (CometWiFi, über Dashboard
weitergeleitet, 15.08.2026, auch selbst bei Dashboards eigenem
`NRGDashboardHeatSchema` reingelaufen). Betrifft jedes DG65-Modul mit eigener
Kachel-Visualisierung (`SetVisualizationType(1)`+`GetVisualizationTile`).
**Konsequenz:** Bedienelemente, die auch in der aufgezogenen Ansicht sichtbar
sein sollen, dürfen NICHT nur als Buttons/Schalter im Kachel-HTML existieren
(die erscheinen dort nie) — es braucht echte Instanz-Variablen mit
`EnableAction()` (Boolean-Switch oder Integer+Enumeration für mehrere
Aktionen). Liegen die zugrundeliegenden Daten in einer anderen Instanz,
`IPS_CreateLink()` (Verknüpfung) verwenden, nicht kopieren.

**11. `Sys_GetURLContentEx()` kann kein POST — `Method`/`Content`/`Header`-Schlüssel
werden STILLSCHWEIGEND ignoriert, es geht immer ein GET raus.** Live gefunden
(HeishaMon, 15.08.2026, an Dietmars Live-Test): laut offizieller IPS-Doku
existieren fuer diese Funktion nur Timeout/Auth*/Verify*-Optionen. Symptom: die
Gegenstelle wirkt "nicht erreichbar", obwohl sie z. B. per `curl` einwandfrei
antwortet — der eigentliche POST-Request wurde nie gesendet, nur ein
GET auf dieselbe URL. Traf HeishaMons Taktschutz-Regelwerk-Upload (siehe oben,
Ebene A) in der ersten Fassung (1.18.0). **Loesung:** PHP-Streams
(`file_get_contents()` + `stream_context_create()`) oder `curl` fuer jeden
Aufruf, der POST/PUT/eigene Header braucht; den HTTP-Aufruf in eine eigene
(z. B. `protected`) Methode kapseln, damit sie einzeln testbar bleibt.

**12. Ein Formular-Button, der per `RequestAction`/`onClick` eine PHP-Methode aufruft, aktualisiert
das BEREITS OFFENE Formular im Browser NICHT automatisch — `GetConfigurationForm()` wird nicht
neu ausgefuehrt.** Live gefunden (EMS, 20.08.2026, an Dietmars Live-Anlage): der "🔎 Jetzt neu
suchen"-Button rief `EMS_Discover($id)` korrekt auf, Partnermodule/Verbund-Gesundheit wurden
serverseitig auch korrekt aktualisiert — aber die neu eingefuehrte Status-Kopfzeile (siehe
"Einheitliche Verbund-Status-Kopfzeile" oben) blieb im Formular dauerhaft auf "Noch nicht
gesucht" stehen, weil ihr `Label` beim ersten Formular-Aufbau berechnet wurde und seitdem
eingefroren war. Symptom fuer den Nutzer: es sieht aus, als waere gar nichts passiert, obwohl
die Aktion serverseitig laengst gelaufen ist — besonders tueckisch bei genau den Statuszeilen,
die ueberhaupt erst zeigen sollen, ob etwas passiert ist. **Loesung:** jedem `Label`/Feld, dessen
Inhalt sich durch eine Button-Aktion aendern kann, ein `'name' => '...'` geben, und in der
aufgerufenen Methode `$this->UpdateFormField('<name>', 'caption', $neuerText);` aufrufen
(no-op, wenn gerade kein Formular offen ist — gefahrlos immer aufrufbar). Bereits im Repo als
Muster etabliert (`AckNews()`/`DismissForumHint()` fuer `visible`, jetzt auch fuer `caption` bei
`Discover()`/`StartBatteryBoost()`/`StopBatteryBoost()`) — bei jedem neuen Button mit
Formular-Sichtbarkeit pruefen, ob ein `UpdateFormField()` fehlt. **Gleichwertige Alternative**
(InverterHub, bestaetigt 20.08.2026): `$this->ReloadForm()` am Ende des Handlers erzwingt einen
kompletten `GetConfigurationForm()`-Neuaufbau, dann brauchen ALLE Felder darin kein einzelnes
`UpdateFormField()` mehr — einfacher bei vielen betroffenen Feldern, aber teurer (baut das ganze
Formular neu), `UpdateFormField()` bleibt die gezieltere Wahl bei wenigen Feldern.
**Wichtiger Kaveat gegen `ReloadForm()`** (MeterHub, 20.08.2026): ein kompletter Formular-
Neuaufbau verwirft dabei auch alle vom Nutzer bereits getippten, aber noch nicht via
"Übernehmen" gespeicherten Eingaben in ANDEREN Feldern — z. B. Start-/End-IP, die man gerade
erst eingetippt hat, bevor man auf "Netzwerk durchsuchen" klickt. Genau dort ist `ReloadForm()`
die FALSCHE Wahl, `UpdateFormField()` auf nur das betroffene Statusfeld die richtige. Faustregel:
`ReloadForm()` nur, wenn der Button selbst am ehesten der einzige Ort ist, an dem gerade etwas
eingegeben wird (z. B. reine Aktions-Panels ohne parallele Text-/Zahlen-Eingabefelder).

**Drei Praxis-Ergaenzungen (CometWiFi, 20.08.2026, am eigenen Live-Fund + Pruefstand
gehaertet):**
1. **Kopfzeile und die dazugehoerige Liste/Detailansicht immer GEMEINSAM auffrischen, nie
   einzeln.** Zieht man nur die Kopfzeile nach, kann sie eine andere Zahl zeigen als die Liste
   darunter (z. B. "10 gefunden" ueber einer Liste mit 9 Eintraegen) — das ist schlechter als
   beides veraltet zu lassen, weil es aktiv falsch wirkt statt nur alt. Bei Update()-Feldern in
   EMS gilt das analog: Kopfzeile + Verbund-Gesundheit + Partnerdetails werden deshalb bewusst
   gemeinsam in einem `Discover()`-Aufruf aktualisiert, nicht einzeln je nach Bedarf.
2. **Reihenfolge: erst der Zustand speichern, DANN auffruellen — nie umgekehrt.** Wer vor dem
   Speichern auffrischt, zeigt den Stand von VOR der aktuellen Aktion (bei einer Suche also das
   vorletzte Ergebnis) — ein leicht zu uebersehener Fehler, weil das Auffrischen gedanklich zur
   Aktion gehoert und deshalb gern direkt hinter den Aufruf statt hinter die Zustandsaenderung
   rutscht.
3. **Die Falle greift nur, wo das Modul selbst aktiv mitbekommt, dass sich etwas geaendert hat**
   (ueber einen Button-Handler, einen Timer-Zyklus, oder einen eingehenden Nachrichtenpfad wie
   MQTT). Ein reiner Anzeige-Zaehler, der bei jedem `GetConfigurationForm()`-Aufbau live ueber
   den Objektbaum zaehlt (z. B. `IPS_GetInstanceListByModuleID()` direkt im Formularaufbau, ohne
   eigenen Cache/Zeitstempel), hat keinen sinnvollen Zeitpunkt zum Nachziehen und braucht auch
   keinen — dort zeigt das Formular beim naechsten OEFFNEN ohnehin den frischen Stand.

**13. Der `@`-Fehlerunterdrueckungs-Operator vor einer IPS-API-Funktion kann einen kompletten
Feature-Ausfall lautlos verschwinden lassen.** Live gefunden (EMS, 20.08.2026): `BuildDayPlan()`
lief laut Log erfolgreich und fand echte Preisdaten, aber der WebFront-Kalender ("EMS Tagesplan")
blieb trotzdem leer — Ursache war `@IPS_SetEventScheduleGroupPoint(...)` in `writeDayPlanEvent()`.
Schlaegt der Aufruf fehl (aus welchem Grund auch immer), verschluckt `@` das komplett: kein
Rueckgabewert-Check, kein Log-Eintrag, keine Exception — nur ein leises "es passiert einfach
nichts", das der Nutzer selbst entdecken muss. **Regel: `@` nie vor einer IPS-API-Funktion
verwenden, deren Erfolg fuer eine sichtbare Funktion noetig ist** — stattdessen Rueckgabewert
pruefen (die meisten `IPS_Set*`-Funktionen liefern `bool`) und bei `false` explizit loggen, was
fehlgeschlagen ist. `@` ist nur dort vertretbar, wo ein Fehlschlag wirklich folgenlos und erwartbar
ist (z. B. beim Aufraeumen eines moeglicherweise schon geloeschten Objekts).

**14. `IPS_SetEventScheduleGroup($EventID, $Group, $Days)`: `$Days` ist eine 7-Bit-
Wochentagsmaske (Bit0=Montag..Bit6=Sonntag), gueltiger Bereich 0-127 -- NICHT 65535.**
Live gefunden (EMS, 20.08.2026, direkte Folge des Stolperfalle-13-Fixes: der Aufruf lief
dadurch ueberhaupt erstmals wirklich durch und deckte diesen zweiten, aelteren Fehler sofort
auf). `65535` (16 Bit, "alle Bits gesetzt") war eine naheliegende, aber falsche Annahme fuer
"alle Wochentage" -- IPS quittiert das mit `"Day" ausserhalb des gueltigen Bereichs`. Korrekt
fuer "alle 7 Tage" ist `127`. Gilt fuer jedes Modul, das Wochenplan-Events (`IPS_CreateEvent(2)`)
programmatisch befuellt, nicht nur fuer EMS.

**15. "Keine Daten" und "Wert ist tatsaechlich 0" muessen unterscheidbar bleiben, sonst
interpretiert die Entscheidungslogik eine Datenluecke als echten Extremwert.** Live gefunden
(EMS, 20.08.2026): `parsePT15M()` fuellte Zeitslots ohne echte Tibber-Preisangabe mit `0.0` --
ein Preis von exakt 0ct ist aber immer guenstiger als jede Einspeiseverguetung, was
`BuildDayPlan()` faelschlich dazu brachte, Abendstunden OHNE Preisdaten als "Export" statt
"Automatik" zu planen. Fehlende Daten wurden wie ein reales Sonderangebot behandelt. Regel:
jedes Array/jede Kurve, die aus einer externen Quelle mit LUECKEN befuellt wird (Preise,
Messwerte, Prognosen), MUSS fehlende Eintraege als `null` (oder einen anderen eindeutig
unterscheidbaren Sentinel) fuehren, NIE als `0` oder einen anderen "harmlos wirkenden"
Platzhalter -- jede Entscheidungslogik, die diese Werte konsumiert, muss `null` explizit
abfangen (z. B. "keine Daten -> Automatik/Ueberspringen"), bevor sie in einen Schwellenwert-
Vergleich einfliessen. Gilt fuer jedes Modul mit zeitreihenartigen Daten, nicht nur fuer PT15M-
Preise.

**16. Einheiten zwischen Verbund-Verträgen NIE annehmen, immer explizit verifizieren --
besonders bei Geldbetraegen (ct vs. EUR).** Live gefunden (EMS, 20.08.2026, aufgedeckt durch
Dashboards neues Tagesplan-Diagramm): Tibber Grid Reward liefert `TIBBERGR_GetPriceCurve()`s
`price`-Feld bewusst in **ct/kWh**, EMS' eigene Preisschwellen (`TIB_Threshold_*`,
`VAR_TIB_Feed_Tariff`) sind als **EUR/kWh**-Dezimalzahl konfiguriert -- niemand hatte das
explizit gegengeprueft, sondern beim automatischen Preis-Abruf (0.22.1) stillschweigend
dieselbe Einheit wie bei den bestehenden Properties angenommen. Ergebnis: ein glatter
Faktor-100-Fehler in JEDEM Preisvergleich, unauffaellig genug, um durch alle bisherigen
Pruefungen zu rutschen (Werte blieben plausible Zahlen, nur eben 100x zu gross -- kein Crash,
keine Fehlermeldung, nur systematisch falsche Entscheidungen). Erst eine Visualisierung
(Diagramm mit lesbarer Achsenbeschriftung) machte den Fehler auf einen Blick sichtbar, den
reiner Text/Logs nicht gezeigt haetten. **Regel: bei jeder neuen Automatik-Anbindung an einen
Verbund-Vertrag explizit dokumentieren (im Code-Kommentar UND in SUITE.md), in welcher Einheit
jedes Geld-/Mengenfeld geliefert wird -- im Zweifel beim anbietenden Modul nachfragen, nie
raten.** Gilt fuer jedes Feld mit physikalischer Einheit (ct/EUR, W/kW, Wh/kWh, ...), nicht nur
fuer Preise. **Noch robuster als ein Kommentar** (Dashboard, 20.08.2026): ein selbstdokumentierendes
Einheiten-Feld direkt im Vertrag (z. B. `priceUnit` in `EMS_GetDayPlan()`), das der Konsument
AUSWERTET statt die Einheit fest anzunehmen -- macht den Vertrag robust gegen kuenftige
Einheiten-Aenderungen des Anbieters, ohne dass der Konsument seinen Code manuell nachziehen muss.

## GoodWe-Steuerregister (InverterHub, Stand 27.07.2026)

Ident-Tabelle für `IPS_RequestAction($InstanceID, $Ident, $Value)` auf einer InverterHub-Instanz:

| Ident | Label | Register | Wertebereich |
|---|---|---|---|
| `ctl_work_mode` | Steuermodus | RW 47000 | 0=Selbstverbrauch, 1=Inselbetrieb, 2=Backup, 3=Wirtschaftlich, 4=Peak-Shaving, 5=Erw. Selbstverbrauch |
| `ctl_ems_enable` | EMS-Steuerung aktiv | RW 47505 | bool — Hauptschalter, ohne `true` ignoriert der WR jeden `ctl_ems_mode`/`ctl_ems_power`-Befehl |
| `ctl_ems_mode` | EMS Leistungsmodus | RW 47511 | 0-12, siehe vollständige Tabelle unten |
| `ctl_ems_power` | EMS Leistung (W) | RW 47512 | 0–34500 W |
| `ctl_export_enable` | Einspeisebegrenzung aktiv | RW 47509 | bool |
| `ctl_export_limit` | Einspeisegrenze (W) | RW 47510 | 0–34500 W (nur bei `ctl_export_enable=true`) |
| `ctl_soc_min` | SOC Min. Entladung | RW 45356 | 0–100 % — **bestätigt ohne Wirkung, nicht empfehlen** |
| `ctl_internet` | Cloud-Verbindung | RW 47017 | bool |
| `ctl_restart` | WR Neustart | WO 45220 | bool, write-only |

**Vollständige `ctl_ems_mode`-Tabelle (InverterHub, 12.08.2026, wörtlich aus der
offiziellen GoodWe-Registerdokumentation "Modbus Protocol Hybrid ET/EH/BH/BT",
ARM205-HV v1.7 (2020-02-26), Tabelle 8-16 "EMS Power Mode" — keine Vermutung mehr,
ersetzt eine frühere, teils nur namensbasierte Fassung):**

**Zentraler Mechanismus — Xmax vs. Xset, steht bei jedem Modus einzeln in der
Tabelle, nicht einheitlich:**
- **Xmax** = reine Obergrenze/Deckel. Der WR erreicht sie nur, wenn die Bedingungen
  es hergeben — kein aktives Anstreben.
- **Xset** = aktiver Zielwert, den der WR **aktiv erreichen muss**, notfalls auch
  über Batterie-Einsatz, falls PV allein nicht reicht.

| Wert | Modus | `ctl_ems_power`-Typ | Formel/Wirkung |
|---|---|---|---|
| 0 | Gestoppt | kein Parameter | Systemabschaltung/Standby |
| 1 | Automatik | kein Parameter | `PBattery = PInv − Pmeter − Ppv`, normale Selbstverbrauchslogik, NUR bei normaler Zählerkommunikation |
| 2 | Laden-Solar | **Xmax** (Deckel) | `PBattery = Xmax + PV (Charge)`. Xmax = erlaubter Netzbezug, PV bevorzugt. `0` = nur PV, kein Netzbezug |
| 3 | Entladen+Solar | **Xmax** (Deckel) | `PBattery = Xmax (Discharge)`. Xmax = max. erlaubte Entladeleistung, PV bevorzugt bei begrenzter Einspeisung |
| 4 | AC-Import | **Xset** (aktives Ziel) | `PBattery = Xset + PV (Charge)`. Xset = bewusst aus dem Netz bezogene Leistung, bevorzugt aus dem Netz gedeckt |
| 5 | AC-Export | **Xset** (aktives Ziel, **zapft die Batterie an**) | `PBattery = Xset (Discharge)`. "PV power is preferred. When PV energy is insufficient, the battery WILL discharge." **Keine reine Deckelung** — live bestätigt als Ursache für unbeabsichtigte Batterie-Entladung (EMS-Branch-3b-Vorfall, 03./04.08.2026) |
| 6 | Energiesparen (Conserve) | kein Parameter | `PBattery = PV (Charge)`. Off-Grid-Reservemodus, laedt NUR aus PV, entlaedt NUR im Inselbetrieb |
| 7 | Inselbetrieb (Off-Grid) | kein Parameter | `PBattery = Pbackup − Ppv`. Erzwungene Netztrennung |
| 8 | Batterie-Bereitschaft | kein Parameter | `PBattery = 0`. Kein Laden/Entladen |
| 9 | Stromeinkauf (Buy) | **Xset** | `PBattery = PInv − (Pmeter + Xset) − Ppv`. Netzbezug wird auf Xset geregelt |
| 10 | Stromverkauf (Sell) | **Xset** | `PBattery = PInv − (Pmeter − Xset) − Ppv`. Netzverkauf wird auf Xset geregelt, Batterie entlädt bei PV-Mangel |
| 11 | Batterie-Laden | **Xset** | `PBattery = Xset (Charge)`. PV bevorzugt, bei PV-Mangel Netzbezug, zusätzlich durch Ladestrom-Limit begrenzt |
| 12 | Batterie-Entladen | **Xset**, mit Priorität | `PBattery = Xset (Discharge)`. Entladung hat Vorrang, zusätzlich durch Entladestrom-Limit begrenzt |

**Praktische Konsequenz:** Nur Modi 2/3 (Laden-Solar/Entladen+Solar) sind reine
Deckel, ungefährlich mit einem hohen Wert wie `EMS_Max_Power_W` zu befehligen. Alle
`Xset`-Modi (4/5/9/10/11/12) sind aktive Zielwerte — ein hoher Wert dort befiehlt dem
WR aktiv, diesen Wert zu erreichen, notfalls über die Batterie. **Nie `maxW` als
Xset-Parameter verwenden, wenn nur eine Kappung aufgehoben werden soll** — das war
der Kernfehler in EMS' ursprünglichem Branch-3b-Versuch (AC-Export mit
`power=EMS_Max_Power_W`).

**Referenzmuster (13.08.2026): Xset-Zielwerte aus vorhandener Prognose ableiten,
nicht aus fester Nutzereingabe.** EMS' zweiter Branch-3b-Versuch nutzte eine
manuell einzutragende `PV_Peak_Wp`-Nennleistung als Deckel — funktional sicher
(Batterie kann nie mehr als die reale Anlagenleistung liefern müssen), aber
wetterblind: an einem bewölkten Tag ist die volle Nennleistung als Ziel
unrealistisch hoch, der WR würde die "fehlende" Sonne trotzdem aus der Batterie
holen. Dritter Anlauf: Zielwert kommt jetzt aus dem aktuellen 15-Min-Slot der PV-
Prognose (PVF, p50-Median) — wetterabhängig, keine manuelle Eingabe, nutzt eine
bereits vorhandene Funktion (`getPvfSlotsWatt()`) statt neuer Property. Gilt
allgemein: wo ein Xset-Zielwert eine Erzeugungsschätzung braucht, ist eine
vorhandene Prognose-Quelle einer festen Nutzereingabe vorzuziehen, wenn verfügbar.

**Für normalen Automatikbetrieb:**
```php
IPS_RequestAction($instanceId, 'ctl_work_mode', 0);   // Selbstverbrauch
IPS_RequestAction($instanceId, 'ctl_ems_mode', 1);    // Automatik
IPS_RequestAction($instanceId, 'ctl_ems_enable', true);
```

**Bekannter Rückfall-Effekt:** `ctl_ems_mode` fällt bei den meisten Werten (1, 9, 11, 12)
nach einiger Zeit von selbst auf den Sentinel-Wert 255 zurück — GoodWes eigener interne
"SMART"-Automatikmodus überschreibt einen einmaligen externen Schreibbefehl wieder. Wert 7
(Inselbetrieb) ist bisher die einzige stabile Ausnahme. Bestätigt durch OpenEMS/FENECON
(dieselbe Hardware-Familie): deren Treiber schreibt vergleichbare Register daher NIE
einmalig, sondern hält den Sollwert über eine State-Machine laufend neu geschrieben
(`GoodWeBatteryInverterImpl.java`, `handleStateMachine()`). Wer `ctl_ems_mode` dauerhaft
halten will, muss ihn periodisch neu schreiben — ein einmaliger Befehl reicht nicht.
Referenz: [OpenEMS-Forum](https://community.openems.io/t/set-emspowermode-and-emspowerset-not-working/2115).

## §14a-Lastabwurf-Priorisierung (EMS-Koordinationsebene)

**Reservierter Stellhebel (15.08.2026):** `SetZ1HeatRequestTemperature` mit
Sentinel-Wert **-5** ist bei HeishaMon-Installationen ab v1.18.0 vom
firmwareseitigen Taktschutz (Short-Cycle-Guard, Ebene A des
Energiespar-Konzepts, laeuft autonom auf der Platine) belegt. EMS selbst nutzt
diesen Hebel aktuell nicht (nur Monitoring, siehe oben "Waermepumpe (nur
Monitoring)"-Kommentar in `readState()`), aber falls EMS oder ein anderes
Modul jemals direkt in die WP-Heizanforderung eingreifen will: NIEMALS
denselben Sentinel/Hebel verwenden, ohne das vorher mit HeishaMon
abzustimmen — sonst kollidieren zwei unabhaengige "autonome Schutz"-Schichten
unsichtbar miteinander. Schichtungsprinzip (analog EMS' Situation-A/B):
Platinen-Rules = autonome Schutz-Schicht (Taktschutz, Rampen, nie
ueberstimmen), Planung (wann WW, Pausen, SmartGridMode) = ausschliesslich
EMS/IPS, Vorlagen auf der Platine bewusst ohne Zeitplan-/Pausen-Bloecke.

Bei mehreren §14a-pflichtigen steuerbaren Verbrauchseinrichtungen (sVE) an einem
Netzanschluss (bei Dietmar: Wärmepumpe + Wallbox) braucht EMS als Koordinator eine
Priorisierungs-Heuristik für den Restfall, dass die Batterie den Netzbezug nicht allein
abdecken kann (Leistungsgrenze des WR bei gleichzeitiger WP-Volllast + Schnellladen, oder
Batterie zum Dimmierungszeitpunkt ausnahmsweise nicht ausreichend geladen).

**Rechtsgrundlage (BNetzA-Rahmenkonzept § 14a EnWG, geprüft gegen Primärquelle):** Die
Mindestleistung von 4,2 kW gilt PRO steuerbarer Verbrauchseinrichtung, nicht als ein
gemeinsamer Topf pro Netzanschluss. Bei mehreren sVE an einem Anschluss reduziert der
Gleichzeitigkeitsfaktor (GZF) die Summe:
```
Mindestleistung gesamt = 4,2 kW + (Anzahl sVE − 1) × GZF × 4,2 kW
```
Bei zwei Geräten (z. B. WP + Wallbox) ist das GZF-Rechenhilfe-Ergebnis von SteuerboxHub
bereits exakt diese Formel — kein Korrekturbedarf, nur diese Doku-Präzisierung.

**Empfohlene Priorisierungs-Heuristik** (abgeleitet aus einem OpenEMS-Community-Leitfaden
zu `Controller.Ess.Limiter14a`, von HeishaMon fachlich geprüft) für den Fall, dass die
Batterie den Rest nicht allein abfangen kann:
1. Wärmepumpe: garantierte Mindestleistung nie unterschreiten (Ausfallschutz) — bei
   HeishaMon ergibt sich das bereits automatisch, da deren primärer Hebel der Heizstab ist
   (`SetDHWHeaterState`/`SetRoomHeaterState`), nicht die WP selbst; die Verdichter-Grundlast
   bleibt beim Heizstab-Sperren ohnehin erhalten, ohne dass EMS das extra steuern muss.
2. Batterie-Entladung freigeben, so weit wie möglich.
3. Wallbox: nur wenn Fahrzeug angeschlossen, mindestens 6A (≈4,14 kW).
4. Warmwasser: verschiebbar, niedrigste Priorität.

Referenz: [OpenEMS `io.openems.edge.controller.ess.limiter14a`](https://github.com/OpenEMS/openems/tree/develop/io.openems.edge.controller.ess.limiter14a),
[Entwickler-Leitfaden](https://enerchy.de/pillar/openems-14a-enwg-entwickler-leitfaden).

## OpenEMS-Architekturanalyse (InverterHub, 30./31.07.2026, aus Quellcode/Doku verifiziert)

Auf Dietmars Wunsch systematisch untersucht: Wie regelt OpenEMS den kompletten
Energiemanagement-Prozess (nicht nur GoodWe-Ansteuerung)? Dient als Vergleichsfolie für
unsere eigene Architektur, NICHT als 1:1-Vorbild — die Rahmenbedingungen unterscheiden
sich grundlegend (siehe Fazit unten).

**Cycle/Takt:** 1-Sekunde-Grundtakt, drei Phasen pro Takt: `AfterProcessImage`
(Messwerte einmalig einfrieren, alle Controller arbeiten gegen ein konsistentes Abbild),
`BeforeWrite` (Controller melden Wunsch-Constraints an, siehe unten), `AfterWrite`
(Constraints geloescht, naechster Takt startet frisch). Kein Ereignis-/Aenderungs-Trigger
wie bei uns — jeder aktive Controller laeuft jeden Takt neu.

**Scheduler legt nur die Reihenfolge fest, nicht die Konfliktloesung.** Drei
Implementierungen (`AllAlphabetically`, `FixedOrder`, `Daily` mit "Always Run
Before/After" fuer Controller, die immer laufen muessen, z. B. Backend-Anbindung).

**Die eigentliche Konfliktloesung laeuft zentral ueber einen linearen
Constraint-Solver** (`io.openems.edge.ess.core`, "ESS Power – Power Distribution").
Controller schreiben NICHT direkt auf die Batterie, sondern melden im
`BeforeWrite`-Schritt Bedingungen an (`EQUALS` exakter Wert, `LESS_OR_EQUALS`
Obergrenze, `GREATER_OR_EQUALS` Untergrenze). Ein Solver loest alle gleichzeitig
gemeldeten Bedingungen mathematisch auf, findet bei Widerspruch den naechstgelegenen
erfuellbaren Betriebspunkt, setzt bei echter Unloesbarkeit alle ESS auf 0 + Fehlerkanal
`NotSolved`. Bei mehreren Batterien: SOC-gewichtete Lastverteilung als Standard, alternativ
sanfte 10%-Schrittannaeherung oder volle Ausreizung nacheinander; Lade/Entlade-Richtungswechsel
bewusst traege (100-Takte-Bestaetigung gegen Pendeln), Ausgabefilter (PID) glaettet den
finalen Sollwert.

**Einordnung/Fazit (wichtig, nicht blind uebernehmen):** OpenEMS' Solver-Modell setzt
voraus, dass ALLE Controller kooperativ Teil desselben Systems sind und denselben Solver
respektieren. Bei uns kommen die "Konkurrenten" oft von aussen und unabhaengig (Tibber
Grid Rewards, Sunny Home Manager, §14a-Netzbetreiber-Dimmung) und schreiben direkt am WR
vorbei, ohne unseren Solver zu respektieren. Unser Eigentuemer-Modell (siehe
"EMS Prioritaetshierarchie", Situation A/B) ist fuer diesen Fall angemessener; der
OpenEMS-Ansatz waere nur sinnvoll, wenn wirklich alle Akteure durch EMS selbst laufen.

**Vollstaendige Controller-Liste (Repo-Root `io.openems.edge.controller.*`), gruppiert:**
- **ESS/Batterie** (~20 Module): `balancing` (Standard-Eigenverbrauch), `timeofusetariff`
  (genetischer Optimierer, 24h-Horizont/96 Viertelstunden-Slots, siehe unten),
  `gridoptimizedcharge` (verzoegert Laden nach Prognose, nennt explizit die
  **70%-Einspeisebegrenzung**), `limiter14a` (**hardcodiert -4200 W als §14a-Reduktionswert
  fuer Deutschland** — deckt sich exakt mit unserer BNetzA-4,2kW-Formel oben),
  `emergencycapacityreserve`, `limittotaldischarge`, `standby`, `fixstateofcharge`,
  `delaycharge`, `selltogridlimit`, `peakshaving` (asymmetrisch), `ripplecontrolreceiver`
  (Rundsteuerempfaenger, DE-spezifisch), `sohcycle`, `mindischargeperiod`.
- **Wallbox/E-Auto** (`controller.evcs.*` + `evcs.cluster`): Force-Charge/
  Surplus-Energy-Charging-Modi; `evcs.cluster` verteilt Leistung auf mehrere Wallboxen
  nach Prioritaet, zwei Modi ("Peak Shaving" = Netz-/Speicherkapazitaet als Grenze,
  "Self Consumption" = PV-Ueberschuss als Grenze) — nur INNERHALB der Wallbox-Domaene.
- **Einfache Schaltausgaenge** (`controller.io.*`): `fixdigitaloutput`,
  `channelsinglethreshold`, `heatingelement`, `heatpump.sgready` (SG-Ready-WP ueber zwei
  Relais, 4 Zustaende Lock/Regular/Recommendation/Force-On je nach Ueberschuss+SOC,
  Mindest-Schaltzeit 60s gegen Flattern — Referenz fuer eine kuenftige
  HeishaMon/SG-Ready-Integration), `heating.room`, `alarm`, `analog`.
- **Symmetrisch/generisch**: `peakshaving`, `limitactivepower`, `balancingschedule`,
  `fixreactivepower`, `randompower`, `timeslotpeakshaving`.
- **Sonstige**: `highloadtimeslot`, `chp.soc` (BHKW), `cleverpv`, `generic.jsonlogic`
  (frei konfigurierbare Regeln), `debug.log`/`detailedlog`.

**`Controller.Ess.Time-Of-Use-Tariff` im Detail** (`io.openems.edge.controller.ess.timeofusetariff`):
Kein Schwellenwertvergleich wie unser `optimize()`, sondern ein genetischer/evolutionaerer
Optimierer ueber einen rollierenden 24h-Horizont in 96 Viertelstunden-Slots — bei jedem
Lauf komplette Neuplanung (Preis-/Erzeugungs-/Verbrauchsprognose + aktueller Batteriezustand).
Zwei Betriebsphilosophien je Markt: `CHARGE_CONSUMPTION` (aktives Netzladen erlaubt,
optimiert guenstigste Ladefenster) vs. `DELAY_DISCHARGE` (Netzladen nicht erlaubt/erwuenscht,
nur Entladung in teuren Fenstern verzoegern/begrenzen). State-Machine mit 9 Zustaenden
(`BALANCING, CHARGE_GRID, DELAY_CHARGE, DELAY_DISCHARGE, DISCHARGE_CONSUMPTION,
DISCHARGE_GRID, LIMIT_CHARGE, PEAK_SHAVING, AVOID_GRID_SELL_LIMIT`) statt unserer 2-3.
Vorab werden Tal-/Spitzenwerte der Preiskurve heuristisch erkannt
(`findValleyIndexes`/`findPeakIndexes`) und als Startpopulation fuer die genetische
Optimierung genutzt, danach viele Kandidaten-Tagesplaene gegeneinander per
Fitness-Funktion bewertet (Hauptkriterium: Netzbezugskosten minimieren). Fuer eine
1:1-Uebernahme waere das ein kompletter Neubau, keine kleine Anpassung an `optimize()`.

**Negativbefund zu domaenenuebergreifendem Lastmanagement:** Kein OpenEMS-Modul
gefunden, das Wallbox+Waermepumpe+Batterie GEMEINSAM gegen EIN Netzanschluss-Limit
einplant (analog unserem `enforceGridImportBudget`). Jede Domaene hat ihr eigenes
Limit-Konzept (ESS: Peak-Shaving/§14a-Controller; Wallboxen: `evcs.cluster` nur
untereinander; WP: SG-Ready nur Ampel-Logik ohne Leistungslimit). Unser
`enforceGridImportBudget` deckt also eine Luecke ab, die OpenEMS selbst
(nach dieser Recherche) nicht domaenenuebergreifend loest — nicht als Nachbau von
etwas Bestehendem misszuverstehen.

**Konkret uebernehmenswerte Erkenntnisse fuer NRG-Stack:**
1. §14a-Fixwert -4200 W (`limiter14a`) als Cross-Check gegen unsere eigene Formel.
2. 70%-Einspeisebegrenzung (`gridoptimizedcharge`) als moegliches zukuenftiges Feature.
3. SG-Ready-Zustandsmuster (`heatpump.sgready`, 4 Stufen + 60s-Mindestschaltzeit) als
   Referenz fuer eine spaetere HeishaMon-Integration.
4. Tal-/Spitzenerkennung in der Preiskurve als leichte Zusatzheuristik denkbar, OHNE den
   kompletten genetischen Optimierer nachzubauen.

Quellen: https://github.com/OpenEMS/openems/tree/develop/io.openems.edge.ess.core,
https://github.com/OpenEMS/openems/tree/develop/io.openems.edge.scheduler.*,
https://github.com/OpenEMS/openems/tree/develop/io.openems.edge.controller.*,
https://github.com/OpenEMS/openems/tree/develop/io.openems.edge.evcs.cluster,
https://github.com/OpenEMS/openems/tree/develop/io.openems.edge.controller.ess.gridoptimizedcharge,
https://github.com/OpenEMS/openems/tree/develop/io.openems.edge.controller.io.heatpump.sgready,
https://github.com/OpenEMS/openems/blob/develop/io.openems.edge.goodwe/src/io/openems/edge/goodwe/common/enums/ControlMode.java,
https://github.com/OpenEMS/openems/blob/develop/io.openems.edge.goodwe/src/io/openems/edge/goodwe/common/ApplyPowerHandler.java

## Migrations-Architektur (MigrationsHub, Stand 31.07.2026)

Wie eine Alt-Instanz (z. B. eine gewachsene Handinstallation oder ein natives
Fremdmodul wie ein go-eCharger-Treiber) auf ein NRG-Stack-Hub-Modul übernommen wird —
verbundweite Referenz, damit jedes Modul denselben Ablauf anbietet statt eigener
Ad-hoc-Loesungen.

**Grundmechanismus (MigrationsHub selbst):** Simulieren → Übernehmen zwischen einer
Quell- und einer Ziel-Instanz. Ein eigener, von der reinen Ausfuehrungs-Bestaetigung
getrennter Schalter `RiskAcknowledged` muss die destruktive Prune-Kante (loeschende
Wirkung) ausdruecklich einraeumen — Pflicht bei jedem Lauf, unabhaengig davon ob
Quelle/Ziel beide NRG-Stack-Module sind oder nicht (die Suite-only-Grenze ist bewusst
NICHT technisch erzwungen, eine Preflight-Sonde sichert generisch jedes Zielmodul ab).
Nach einem echten Uebernahme-Lauf prueft MigrationsHub automatisch, ob die
Quell-Instanz jetzt keine Kindvariablen mehr hat, und schlaegt dann selbst "Instanz
analysieren & loeschen" vor, statt dass der Nutzer manuell danach suchen muss.

**Migration als Teil des normalen Geraete-Scans (nicht als separates, erst zu
findendes Werkzeug):** Discovery-Scans (InverterHub, MeterHub, perspektivisch weitere)
rufen nach einem Scan-Treffer zusaetzlich `MIGHUB_FindLegacyCandidates($host, $port=0,
$unitId=0): array` auf (hinter `function_exists()`, reine Additivitaet — ohne
MigrationsHub installiert bleibt alles wie bisher). Bei Treffern zeigt das eigene
Formular ein Panel "Migration von Altinstanzen" mit einem Button "Migration
vorbereiten": legt bei Bedarf die MigrationsHub-Instanz an
(`IPS_CreateInstance('{330717BB-E309-41A2-90A8-FDA3179ED948}')`), ruft
`MIGHUB_PrefillMigration($id, $oldInstanceID, $newInstanceID)` auf (setzt
Source-/TargetInstanceID und wendet an) und zeigt danach einen `OpenObjectButton` zur
MigrationsHub-Instanz, wo der bestehende Simulieren/Uebernehmen-Ablauf greift.

**Zwingende Matching-Regel: NIE ueber Namen, IMMER ueber Host/IP (+ ggf. Unit-ID).**
Sowohl MigrationsHub als auch InverterHub sind unabhaengig voneinander auf
Namens-Fallen reingelaufen (ein ModBus-Gateway hiess "Goodwe Wechselrichter" war aber
keiner; fuenf gleichnamige "Siemens PAC2200" waren voellig unterschiedliche Geraete).
Host/IP steht bei praktisch jedem Geraetemodul in der Konfiguration, unabhaengig vom
frei vergebenen Namen. Bei mehreren Geraeten an einem Host auf unterschiedlichen
Unit-IDs (z. B. WR auf 247, ein separates Zusatzmodul auf anderer ID) ist die Unit-ID
zwingend zusaetzlich noetig, sonst bleibt die Alt-Instanz-Suche mehrdeutig.

**Doku-Redundanz-Konvention:** Sowohl das jeweilige Zielmodul (in seinem eigenen
Doku-Panel: "Falls eine Migration geplant ist, Kommunikation vorerst deaktiviert
lassen") als auch MigrationsHub selbst (als eigener Ablaufschritt) weisen auf diesen
Punkt hin — bewusst doppelt, damit der Hinweis unabhaengig vom Einstiegspunkt des
Nutzers ankommt (mancher liest zuerst die Zielmodul-Doku, mancher steigt direkt bei
MigrationsHub ein).

**Adoptions-Vertrag fuer Zielmodule (final abgestimmt 31.07.2026 zwischen MigrationsHub,
InverterHub, MeterHub, ChargerHub, ausgeloest durch den ersten echten Testlauf
ChargerHub↔go-eCharger):** Loest zwei reale Probleme des ersten Testlaufs — MigrationsHub
musste das gueltige Ident-/Typ-Set eines Fremdmoduls muehsam extern erraten/nachrecherchieren
(fuehrte zu einem falschen Karten-Index-Offset, der erst per Live-Wertevergleich aufgedeckt
wurde), UND manche Zielmodule (z. B. InverterHub, MeterHub) loeschen beim eigenen
`ApplyChanges()` unbekannte Kind-Variablen ersatzlos (`PruneForeignObjects()`), was
MigrationsHub bisher nur per Preflight-Sonde (Wegwerf-Testvariable) indirekt absichern
konnte. Finale Loesung: NUR EINE neue, optionale Funktion pro Zielmodul (ein zwischenzeitlich
diskutiertes zweites `AdoptFromLegacyInstance` wurde wieder verworfen — MeterHub deckte die
Verwechslung mit einem frueheren Zwischenstand auf, die mechanische Umsetzung bleibt
komplett bei MigrationsHub):

`<PREFIX>_GetIdentMapping($id, string $foreignModuleGUID, array $foreignIdents): array` —
reine Auskunft. `$foreignIdents` = tatsaechlich vorhandene Idents der Alt-Instanz (Korrektur
31.07.2026, auf Wunsch von MeterHub: instanzabhaengiges gueltiges Ident-Set und
firmwareabhaengige Feldbenennung bei manchen Altmodulen machten eine reine GUID-Abfrage
nicht praezise genug). Rueckgabe: nur erkannte Treffer, `['altIdent' => ['ident' =>
'neuIdent', 'type' => int (IPS VARIABLETYPE_*)], ...]`. Leeres Array = "kenne diese
Fremdmodul-GUID/-Idents nicht" (kein Fehler).

**Warum keine zweite Funktion noetig ist:** MigrationsHub reparented die Alt-Variablen
per `IPS_SetParent`+`IPS_SetIdent` VOR dem Aufruf von `IPS_ApplyChanges($targetInstanceID)`
— die im Zielmodul ohnehin vorhandene Prune-vor-Register-Sequenz (bestaetigt im eigenen
Code bei InverterHub UND MeterHub) greift dann automatisch korrekt, weil die Variablen zu
dem Zeitpunkt bereits umbenannt sind. Die einzige fehlende Information war das
Vorab-Wissen ueber das gueltige Ident-/Typ-Set — genau das liefert `GetIdentMapping`.

**Rollenteilung bleibt unveraendert zentral:** MigrationsHub bleibt die einzige Stelle, die
IPS-Objekte ueber Modulgrenzen hinweg anfasst, und die einzige Stelle mit Nutzer-Review/
Bestaetigung. Module OHNE diese Funktion (leeres `GetIdentMapping` oder Funktion fehlt)
fallen automatisch auf die bisherige Preflight-Sonde + generischen `AC_ChangeVariableID`-
Fallback zurueck — kein Breaking Change fuer Module, die (noch) nicht mitziehen. **Achtung:**
`GetIdentMapping` ist selbst eine oeffentliche `PREFIX_`-Funktion — Erweiterungen daran
unterliegen der Arity-Falle aus "IP-Symcon-Stolpersteine" Punkt 8.

Datenpunkt-Aequivalenz-Grundsatz bleibt unabhaengig davon gueltig: Vor jeder Migration muss
jemand pruefen, ob sicherheitsrelevante/abrechnungsrelevante Werte ein Gegenstueck haben —
sonst lieber gar nicht migrieren, als Verlaufsdaten stillschweigend zu verlieren. Konkret
geloest beim go-eCharger-Fall: RFID-Kartennamen existierten im Alt-Modul nie als eigene
Variable (kommen bei ChargerHub ohnehin frisch per MQTT nach), und der Karten-Index-Offset
(`energyChargedCard{N}`, 1-basiert → `card{N-1}_energy`, 0-basiert) wurde per Live-Wertevergleich
UND Quellcode-Pruefung bestaetigt — Mapping-Tabelle jetzt in ChargerHubs eigenem README
dokumentiert (Migration vom Community-Modul `IPSCoyote/GO-eCharger`).

## Status-Code-Konvention (Stil, nicht feste Zahlentabelle)

IP-Symcon-Statuscodes (`SetStatus()`) sind nur PRO MODUL gueltig — es gibt keine
uebergreifende "Code X bedeutet immer Y"-Bedeutung ueber Modulgrenzen hinweg. Eine feste
Zahl→Bedeutung-Tabelle macht deshalb keinen Sinn. Stattdessen gilt verbundweit diese
Stil-Regel (vorgeschlagen von Tessie, 27.07.2026, im Kern richtig auch wenn der
urspruengliche Anlassfund bei StromGedacht sich als Verwechslung module.json/form.json
herausstellte — StromGedacht hatte den Eintrag korrekt im form.json, nicht im
module.json, das keinen "status"-Schluessel kennt):

1. **Jeder eigene `SetStatus()`-Code MUSS einen Eintrag in `form.json["status"]`**
   bekommen (deutsche Beschriftung + Icon) — sonst bringt der Code dem Nutzer nichts,
   das ist Stolperstein-4-artig ("nichts sichtbar, obwohl Code korrekt gesetzt").
2. **Nummernbereich**: 1xx = IP-Symcon-Standard (102 aktiv, 104 inaktiv/Konfiguration
   fehlt), 2xx = modulspezifisch frei. Innerhalb der 2xx: niedrigere Zahl = haerterer
   Fehler, hoehere Zahl = weichere Warnung (lose an StromGedachts bestehende Reihenfolge
   angelehnt).
3. **Icon**: `"error"` fuer harte Fehler, `"inactive"` fuer weiche Warnungen — die
   einzigen beiden Werte, die bisher verbundweit bestaetigt in Gebrauch sind.

**How to apply:** Beim naechsten neuen `SetStatus()`-Code in einem beliebigen Modul
diese drei Punkte pruefen, bevor der Code committet wird.

## WebFront-Kachel-Wechsel InverterHubTile → NRGDashboardTile (manuell, nicht automatisierbar)

**Für den Go-Live von NRGDashboard wichtig**: InverterHub hat Stand 27.07.2026 ca. 250
Installationen. Wer bisher InverterHubTile als Kachel auf einem WebFront-Dashboard-Screen
platziert hat und zu NRGDashboardTile wechseln möchte, muss das **manuell** tun — es gibt
keine IPS-API, die eine bereits platzierte Kachel programmatisch auf eine andere Instanz
umbiegt (geprüft, keine Vermutung: eine WebFront-Kachel ist eine reine Platzierungs-Referenz
auf eine Instanz-ID im Dashboard-Editor). Auch `MigrationsHub` deckt das nicht ab — das
migriert Variablen samt Archivhistorie/Referenzen bei Geräte-Ersatz, hat aber keinen Bezug zu
Dashboard-Layouts.

**Nötige Schritte für den Nutzer** (gehören in NRGDashboards eigene "Was ist Neu"/Migrations-
Doku, sobald das Modul veröffentlicht wird):
1. NRGDashboard-Instanz anlegen und konfigurieren (Discovery findet die vorhandenen
   Partnermodule automatisch, siehe eigene Doku).
2. Im WebFront-Editor die alte InverterHubTile-Kachel vom Dashboard-Screen entfernen.
3. Die neue NRGDashboardTile-Instanz an derselben Stelle neu platzieren.

Wenige Klicks, aber ein bewusster Nutzereingriff — sollte in der Release-Kommunikation
(Forum-Post, Doku-Panel) klar benannt werden, nicht stillschweigend vorausgesetzt.

## Repo-Namen und GitHub-Redirects (19.08.2026)

Alle Verbund-Repos wurden auf kanonische `NRG*`-Namen umbenannt (NRGEMS,
NRGChargerHub, NRGInverterHub, NRGMeterHub, NRGGleitenderMittelwert,
NRGSteuerboxHub, NRGTessie, NRGMigrationsHub, NRGPrognose, ...). Die alten
Namen (EMS, ChargerHub, InverterHub, ...) existieren nur noch als
GitHub-Redirects — sie funktionieren, haengen aber an einem zerbrechlichen
Mechanismus.

**Harte Regel: Ein alter Repo-Name wird NIEMALS fuer ein neues Repo
wiederverwendet.** Sobald unter DG65 ein neues Repo mit einem Alt-Namen
angelegt wird, loescht GitHub die Weiterleitung kommentarlos — und jeder
Nutzer, der den alten Pfad noch in seiner Modulverwaltung eingetragen hat,
laedt ab dann ein falsches Repo oder gar Fremd-Code. Vor dem Anlegen JEDES
neuen Repos gegen die Alt-Namen-Liste oben pruefen.

Ergaenzend gilt seit 19.08.2026: Alle Links und `library.json`/`module.json`-
URLs im Verbund zeigen direkt auf die kanonischen `NRG*`-Namen (verbundweiter
Sweep, inkl. der ModulControl-relevanten URLs) — kein neuer Link darf einen
Alt-Namen verwenden, auch nicht "weil der Redirect ja funktioniert".

## Lizenz

Alle NRG-Stack-Module stehen unter der **PolyForm Noncommercial License 1.0.0** (privat/nicht-kommerziell frei, gewerblich lizenzpflichtig — Kontakt DG65; Spenden willkommen: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth)). Kanonischer Text: [`LICENSE`](https://github.com/DG65/NRGEMS/blob/main/LICENSE). Der Wechsel wirkt nur nach vorn: Bei bereits im Store veröffentlichten Modulen gilt PolyForm erst ab dem jeweiligen beta→main-Release; die dort noch gelistete Fassung bleibt MIT, und unter MIT bezogene Altversionen bleiben MIT.

**Kanonische Kurzformel für Forum-Posts/öffentliche Ankündigungen** (18.08.2026,
auf Nachfrage mehrerer Module gleichzeitig entstanden — jedes Modul füllt nur
die Klammer `[Modul-Altversionsstand]` passend, Rest wortgleich übernehmen):

> Lizenz: PolyForm Noncommercial 1.0.0 — private/nicht-kommerzielle Nutzung
> ist frei, gewerbliche Nutzung erfordert eine gesonderte Lizenz vom
> Rechteinhaber (DG65). Der Wechsel wirkt nur nach vorn: bereits unter MIT
> veröffentlichte Altversionen [bis Version X.Y.Z] bleiben MIT. Der
> vollständige Lizenztext liegt im Repo (LICENSE). Spenden sind willkommen:
> [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).

Spendenkanal: **paypal.me/DietmarGureth** (seit 18.08.2026, verbindlich für
alle Module) — ab jetzt in Forum-Posts/READMEs nennen.

## README-Badges (18.08.2026)

Jede Modul-README bekommt direkt unter der H1-Überschrift eine Badge-Zeile
(shields.io-Badges, statisch per Markdown-Bild, nicht generiert). Referenz:
EMS' eigene [README.md](https://github.com/DG65/NRGEMS/blob/main/README.md).
Reihenfolge und Inhalt:

1. **Symcon** — statisch `Symcon | PHPModul`.
2. **Modul Version** — aktueller `library.json→version`-Stand, **manuell bei
   jedem Versions-Bump mitpflegen** (kein Auto-Sync, analog zur restigen
   Versionspflege in `library.json`/`CHANGELOG.md`).
3. **Symcon Version** — Mindest-Kompatibilität aus
   `library.json→compatibility.version`, z. B. `9.0+`.
4. **License** — `PolyForm Noncommercial 1.0.0` (NICHT einfach ein
   shields.io-Lizenz-Preset übernehmen, dafür gibt es keins — als
   eigenständiger `img.shields.io/badge/...`-Text).
5. **Check Style** (optional, nur wenn ein echter GitHub-Actions-Workflow
   existiert) — dynamisches Workflow-Badge
   (`.../actions/workflows/<name>.yml/badge.svg`), verlinkt auf den
   Workflow-Lauf. **Nie ein statisches "passing"-Badge fälschen, wenn keine
   CI läuft** — dann den Badge einfach weglassen, bis ein Workflow existiert.
   EMS' Minimal-Workflow (`.github/workflows/check-style.yml`, `php -l` über
   alle PHP-Dateien bei jedem Push) ist die einfachste Vorlage dafür.
6. **PayPal** — verlinkt auf [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth),
   siehe Abschnitt „Lizenz" oben.

Ein Amazon-Wunschlisten-Badge ist bewusst **kein** Standardbestandteil — nur
falls ein Modul (auf Dietmars ausdrücklichen Wunsch) tatsächlich eine eigene
Wunschliste verlinken soll.

**Falle beim Erweitern von `check-style.yml`** (CometWiFi, 20.08.2026, an
941 echten Prüfungen verifiziert): wer über `php -l` hinaus eigene Prüfstände
in einer Schleife laufen lässt (`for t in ...; do php "$t"; done`), bekommt
KEINEN verlässlichen Job-Status — die Schleife läuft nach einem Fehlschlag
einfach weiter, und `set -e` greift hier nicht (nur der Exit-Code des
*letzten* Laufs zählt). Ein grüner Haken hieße dann "der letzte Prüfstand
war okay", nicht "alle waren okay". Fehlschläge selbst zählen und am Ende
explizit `exit 1` bei mindestens einem Fehlschlag — nicht auf `set -e`
verlassen. Beim einfachen `find ... | xargs -0 -n1 php -l` (EMS' Vorlage)
unproblematisch, da `xargs` selbst bei einem Fehlschlag korrekt nicht-null
zurückgibt — die Falle greift erst, sobald jemand eine eigene Schleife
darum baut.

## Verweis in den Modulen

Jede Modul-README erhält einen kurzen Hinweis:

> Teil des **NRG-Stack** — welche Modulstände zusammenpassen, steht im
> [Kompatibilitäts-Manifest](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).
