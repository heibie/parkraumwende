# data.parkraumwende.de

Offene Karten und Daten zum Parkraum in München – Parkhäuser, Tiefgaragen, Parklizenzgebiete, Parkseiten, Live-Auslastung, Statistiken und Analysen.

**→ [data.parkraumwende.de](https://data.parkraumwende.de)**

---

## Architektur

Statische Single-Page-Application ohne Build-Pipeline. Alles läuft direkt im Browser.

```
index.html          — SPA mit sieben Tabs: Karte, Innenstadt, Parklizenzgebiete, Parkseiten, Bezirksprofile, Statistiken, Anträge
embed.html          — Standalone-iFrame für einzelne Diagramme
quellen.html        — Quelldokumentation aller Datensätze
content/            — Redaktionelle Infotexte je Tab (Markdown, im Browser gerendert)
data/               — CSV-Datensätze + datapackage.json + Live-Daten
cron/               — Server-seitige Cron-Scripts (PHP)
scripts/            — Hilfsskripte (Datenaufbereitung)
```

**Hauptnavigation auf schmalen Bildschirmen:** Die Tab-Leiste (`#main-nav` in `.nav-wrap`) scrollt horizontal. Gehen Tabs über den Rand hinaus, blenden `updateMainNavMore()` Verlauf + Pfeil-Buttons (`.nav-more-l/-r`, Klassen `more-l`/`more-r`) ein; der rechte Pfeil wippt beim Laden kurz (`.hint`, verschwindet beim ersten Scrollen). `showTab()` scrollt den aktiven Tab per `revealNavTab()` ins Bild (z.B. bei Direktaufruf `#antraege`). Neuer Tab → nur Button in `#main-nav` ergänzen, der Rest passt sich an.

**Libraries (alle per CDN, kein npm):**

| Library | Version | Zweck | Releases |
|---|---|---|---|
| [Chart.js](https://www.chartjs.org) | 4.4.4 | Alle Diagramme | [Releases](https://github.com/chartjs/Chart.js/releases) |
| [chartjs-plugin-datalabels](https://chartjs-plugin-datalabels.netlify.app) | 2.2.0 | Werte in Balken | [Releases](https://github.com/chartjs/chartjs-plugin-datalabels/releases) |
| [PapaParse](https://www.papaparse.com) | 5.4.1 | CSV-Parsing im Browser | [Releases](https://github.com/mholt/PapaParse/releases) |
| [Leaflet](https://leafletjs.com) | 1.9.4 | Interaktive Karte | [Releases](https://github.com/Leaflet/Leaflet/releases) |
| [proj4js](https://proj4js.org) | 2.11.0 | Koordinatentransformation UTM→WGS84 | [Releases](https://github.com/proj4js/proj4js/releases) |

> **Library-Updates (einmal jährlich prüfen):** Versionen sind fest in den CDN-URLs in `index.html` und `embed.html` eingebaut und aktualisieren sich nicht automatisch. Neue Version in der Tabelle oben und in allen `<script src="…@VERSION…">`-Tags eintragen, kurz testen, committen. Security-Risiko ist gering (keine Logins, keine externen APIs), aber bei bekannten Lücken zeitnah updaten.

---

## Lokale Entwicklung

Kein Build-Step nötig. Lokal läuft das Projekt über **MAMP Pro** (Apache + PHP, damit auch die `cron/*.php`-Skripte lokal laufen):

- **https://data.parkraumwende.test:444/** – Host `data.parkraumwende.test`, DocumentRoot `~/Sites/data.parkraumwende.de` (http auf Port 81 leitet dorthin um)
- Änderungen sind nach Neuladen sofort sichtbar, kein Server-Start nötig (MAMP Pro muss laufen)

Direkt als `file://` funktioniert es wegen der PapaParse-Requests nicht. Notfalls ohne MAMP: `python3 -m http.server 8080` im Repo → http://localhost:8080 (ohne PHP).

---

## Deployment

Deployment läuft **vollautomatisch via GitHub Actions** bei jedem Push auf `main`:

```
git push → GitHub Actions → rsync → All-Inkl Webserver
```

Secrets im GitHub-Repo: `SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`. Live-Daten (`data/latest.json`, `data/details/`, `data/summary/`) werden vom rsync ausgeschlossen und nicht überschrieben.

**Manueller Workflow (z. B. nur CSV deployen):**

```bash
git add .
git commit -m "feat: …"
git push
```

---

## Geodaten-Tabs (Parklizenzgebiete & Parkseiten)

Beide Tabs zeigen Geodaten des OpenData-Portals der LHM. Die Koordinaten liegen im Koordinatensystem **UTM Zone 32N (EPSG:25832)** und werden im Browser per **proj4js** nach WGS84 umgerechnet.

### Parklizenzgebiete (`#parklizenz`)

- **Polygone** aus `data/ruhver_prm_gebiete_poly.csv`
- Angereichert mit Statistikdaten aus `data/parklizenzgebiete.csv` (Stellplätze, Parkausweise)
- **Einfärbung nach Überzeichnungsquotient** (= Parkausweise ÷ Stellplätze, seit 2026-11-18) in 3 Stufen (seit 2026-11-19, gleiche Grenzen wie im Diagramm „Überbuchung nach Lizenzgebiet“): bis 1 = unterbucht (grün) · über 1 bis unter 1,35 = überbucht (hellrot) · ab 1,35 = überlastet (dunkelrot), **Grau** = keine Statistikdaten. Kachel „Überlastet“ über der Karte zählt die Gebiete ab 1,35, „Überbucht“ nur die darunter. Im Diagramm: Referenzlinien bei 1,0 und 1,35, Balken ab 1,35 in kräftigem Rot, darunter hellrot. Kein Orange in der Skala (reserviert für „nicht bewirtschaftet“). Stufen in `PLZ_CLASSES` – identisch in `index.html` (`initParklizenz`) und `embed-parklizenz.html` pflegen.
- Zahl im Polygon = Quotient (z. B. „1,76“); Detail-Panel zeigt „Quotient“ + „x Parkausweise auf 100 Stellplätze“ sowie die absolute Überbuchung
- Der Quotient ist eine eigene Ableitung (kein amtlicher Wert) und zeigt den rechnerischen Anspruch, nicht die tatsächliche Belegung (nicht alle Ausweisinhaber parken gleichzeitig, bis zu 3 Kfz pro Ausweis).
- Diagramm „Überbuchung nach Lizenzgebiet“ (Statistiken + `embed.html?chart=lizenzgebiete`): Umschalter Quotient (Standard) / Differenz absolut, Referenzlinie bei 1,0. Embed startet mit `&mode=diff` in der Differenz-Ansicht.
- **Unbewirtschaftete Fläche**: `data/muenchen_unbewirtschaftet.geojson` — vorberechnete Differenzfläche (München gesamt minus Lizenzgebiete, 88 % der Stadtfläche / 273 km²). Neu generieren mit `node scripts/berechne_unbewirtschaftet.js` nach Aktualisierung der Polygon-CSV.
- Klick auf Polygon → Detail-Panel (rechts)
- Infoleiste: `content/parklizenz.md`
- Quelle: [opendata.muenchen.de](https://opendata.muenchen.de/dataset/opendata_ruhver_prm_gebiete_poly), laufend aktualisiert

### Parkseiten (`#parkseiten`)

- **Linien** aus `data/ruhver_parkseiten_line.csv` — 13.244 Segmente, 16 Regelgruppen, 100.432 Stellplätze
- Filter-Sidebar links: Regelgruppen ein-/ausblenden
- Klick auf Linie → Detail-Panel (rechts)
- Infoleiste: `content/parkseiten.md`
- Quelle: [opendata.muenchen.de](https://opendata.muenchen.de/dataset/opendata_ruhver_parkseiten_line), laufend aktualisiert

**Geodaten aktuell halten:** Neue CSV-Versionen vom OpenData-Portal herunterladen, in `data/` ersetzen, committen und pushen.

---

## Antrags-Tracker (`#antraege`)

Stadtrats-/BA-Anträge, Anfragen und BV-Empfehlungen zur Parkraumwende (Parken vom öffentlichen in den privaten Raum verlagern).

- `data/antraege.csv` – ein Vorgang pro Zeile, `id` stabil (nie neu vergeben). `verifiziert`: `Ja` = geprüft, öffentlich · `Nein` = Vorschlag vom Cron, nur mit `?preview` sichtbar (gestrichelt) · `Ausgeschlossen` = aussortiert, nie sichtbar; bleibt in der CSV, damit der Cron ihn nicht erneut vorschlägt.
- `data/antraege-stichworte.json` – RSS-Feeds + Stichwortfilter (Regex auf den Betreff) für neue Vorschläge.
- `data/antraege_verlauf.csv` – Statuswechsel, Sitzungen, Antwortschreiben je Antrag (generiert, nicht von Hand pflegen).
- `scripts/update_antraege.py` – liest pro `ris_id` die RIS-Detailseite (`/risi/antrag/detail/<id>`) und befüllt nur die RIS-Spalten (Vorgangsnr., Typ, BA, Status, Fristen, Beschlüsse, Antwort-Link). **Redaktionelle Spalten** (`fraktion_norm`, `themen`, `richtung`, `kurzfassung`, `ergebnis`, `antwort_kurz`, `verifiziert`, `quelle_fund`) werden nie überschrieben; `antragsteller` nur, wenn das RIS einen Wert liefert (bei BA-Anträgen fehlt er → von Hand).
- `content/antraege.md` – Intro (Beta-Hinweis, KI-Erfassung mit redaktioneller Prüfung, Empfehlung, genutzte Einträge selbst anhand der Originaldokumente zu prüfen, Fehler an kontakt@parkraumwende.de).
- **Frontend** (`initAntraege()`/`renderAntraege()` in `index.html`): Karten per Klick aufklappbar (Chevron), Deep-Link `#antrag-<id>`. Auf Mobil (≤ 900 px) sind die Filter-Selects hinter dem Button „Filter (n)“ eingeklappt (`.ant-filters.open`); n = Zahl aktiver Filter, Button dann farbig. Gegen horizontales Scrollen: Selects `min-width: 0`, `.ant-detail` mit `overflow-wrap: anywhere`, `.ant-body` mit `overflow-x: hidden`. Review-Modus (Keep/Drop-Buttons + Export) ist abgeschaltet: `ANT_ADMIN = false` – zum Reaktivieren auf `new URLSearchParams(location.search).has('admin')` setzen und `#ant-f-pruef` einblenden.
- **RIS-WAF:** blockt IPv6 und Standard-User-Agents („Access Denied“) – das Skript erzwingt IPv4 + Browser-UA, 2 s Pause zwischen Abrufen.

**Laufende Aktualisierung:** `cron/update_antraege.php` (PHP-Port von `scripts/update_antraege.py`, All-Inkl hat kein Python), täglich per KAS-Cronjob:

1. liest die RIS-RSS-Feeds `StR-Antraege`, `BA-Antraege`, `BV-Empfehlungen`, `BV-Anfragen` (StR/BA reichen nur 2–4 Wochen zurück → täglich nötig), filtert per Stichwort und hängt neue Treffer mit `verifiziert=Nein` an,
2. liest die RIS-Detailseiten offener Anträge (und in den letzten 60 Tagen erledigter) neu ein – verteilt per `id % 7` auf die Wochentage, also jeder offene Antrag einmal pro Woche,
3. committet bei Änderungen beide CSVs in **einem** Commit über die GitHub-API (→ ein Deploy) und legt bei neuen Vorschlägen bzw. frisch beantworteten Anträgen ein GitHub-Issue an.

Cronjob-URL: https://data.parkraumwende.de/cron/update_antraege.php?token=DEIN_CRON_TOKEN (Parameter: `dry=1`, `all=1`, `ids=<ris_id>,…`). Lokal testen ohne Commit: `php cron/update_antraege.php dry=1 local=1` (Ergebnis landet im Temp-Verzeichnis). Kurzfassung, Antwort und Ergebnis der neuen bzw. frisch beantworteten Anträge ergänzt danach eine Claude-Code-Session („Antrags-Tracker aktualisieren“), dabei `verifiziert` auf `Ja` oder `Ausgeschlossen` setzen.

Neuen Antrag von Hand aufnehmen: Zeile mit nächster freier `id` + `ris_id` anhängen → `python3 scripts/update_antraege.py <ris_id>` → redaktionelle Spalten ausfüllen. Ist `fraktion_norm` bzw. `ergebnis` leer, belegt das Skript sie vor (Fraktion aus „Gestellt von“, Ergebnis `beantwortet`/`Antwort offen` je nach RIS-Erledigt-Datum).

`ergebnis`-Werte (steuern die Pill-Farbe): `Antwort offen` · `beantwortet` (Antwort/Erledigung ohne Gremiumsbeschluss in der Sache) · `beschlossen` (vom Gremium angenommen) · `abgelehnt` (vom Gremium abgestimmt dagegen). Inhaltliche Nuancen stehen in `antwort_kurz`.

---

## Neues Diagramm hinzufügen

> ⚠️ **Immer beide Dateien anfassen:** `index.html` UND `embed.html`

### Checkliste

- [ ] **`index.html`**: `stat-card` HTML mit `data-chart="CHART_ID"` und `<button class="embed-btn">` anlegen
- [ ] **`index.html`**: Chart-Initialisierung in `initStatistiken()` implementieren
- [ ] **`embed.html`**: Eintrag in `CHARTS`-Registry (title, sub, source, init)
- [ ] **`embed.html`**: `init`-Funktion implementieren — Canvas-ID ist immer `'chart'`
- [ ] **`data/source-registry.json`**: neue Quelle bzw. neuer `used_in`-Eintrag (verlinkt auf `embed.html?chart=...`) — steuert die Tabelle auf `quellen.html`
- [ ] **`data/datapackage.json`**: Neue Ressource mit Schema dokumentieren — immer synchron zur echten CSV halten, siehe Abschnitt „CSV-Daten aktualisieren"
- [ ] **Committen und pushen** (`git add`, `git commit`, `git push`) — löst den GitHub-Actions-Deploy aus, kein `scp` mehr verwenden (wird vom stündlichen Cron-Push wieder überschrieben, siehe Abschnitt „CSV-Daten aktualisieren")

### embed.html – CHARTS-Registry

```js
const CHARTS = {
  'mein-chart': {
    title:  'Titel des Diagramms',
    sub:    'Untertitel / Zeitraum',
    source: 'Quellenangabe',
    init:   initMeinChart,
  },
  // …
};

async function initMeinChart() {
  // Code aus index.html übernehmen, Canvas-ID → 'chart'
  new Chart(document.getElementById('chart'), { … });
}
```

### ChartDataLabels (Werte in Balken)

`Chart.unregister(ChartDataLabels)` ist bereits global gesetzt – verhindert, dass das Plugin auf alle Charts angewendet wird. Aktivierung nur per Chart:

```js
new Chart(el, {
  type: 'bar',
  plugins: [ChartDataLabels],   // ← explizit aktivieren
  // …
});
```

---

## CSV-Daten aktualisieren

Alle manuell gepflegten Datensätze liegen in `data/*.csv`. Jede Datei ist in `data/datapackage.json` mit Schema beschrieben.

**Quellen-Übersicht:** welche Datei in welchem Chart/welcher Karte verwendet wird, wo die Originalquelle liegt
und wann sie zuletzt geprüft wurde, steht jetzt strukturiert in `data/source-registry.json` und wird auf
[`quellen.html`](quellen.html) tabellarisch dargestellt (nicht mehr nur hier als statische Tabelle). Neue
Datenquelle hinzufügen → Eintrag in `data/source-registry.json` ergänzen (Kategorie, `used_in`, `original_source`,
`turnus`, `check.method`).

**Änderungscheck:** `python3 scripts/check_sources.py` prüft alle Quellen aus der Registry und schreibt das
Ergebnis nach `data/source-status.json` (von `quellen.html` per `fetch()` geladen). Zwei Methoden:
- `ckan` – fragt die CKAN-API von opendata.muenchen.de ab. Nutzt bei Geoportal-Datensätzen (Parkseiten,
  Parklizenzgebiete-Geodaten) bevorzugt das Extra-Feld `GeoPortal Last Modified Date` (echtes
  Geodaten-Änderungsdatum), sonst den jüngsten Resource-Zeitstempel. CKANs `metadata_modified` wird bewusst
  nicht verwendet: Geoportal-Datensätze werden täglich neu geharvestet (neue Resource-IDs je Lauf), auch ohne
  echte Datenänderung – laut `activity_diff`-Vergleich reines Harvest-Rauschen.
- `heuristic` – ohne Live-API: vergleicht Git-Datum der letzten lokalen Änderung mit dem bekannten Turnus, markiert überfällige Quellen.

**Automatischer Wochen-Check:** `cron/check_sources.php` ist der serverseitige Port von `scripts/check_sources.py`
(kein Python auf dem All-Inkl-Hosting verfügbar). Läuft als KAS-Cronjob:

```
Cronjob-URL: https://data.parkraumwende.de/cron/check_sources.php?token=DEIN_CRON_TOKEN
Intervall:   wöchentlich
Debug:       ?token=...&debug=1 (gibt den berechneten Status als JSON aus, schreibt/committet nichts)
```

Zweistufiges Verhalten:
1. `data/source-status.json` wird bei jedem Lauf neu berechnet und – falls sich der Inhalt geändert hat – automatisch
   direkt nach `main` committet. Das ist rein informativ (nur die Ampel auf `quellen.html`), daher unkritisch für
   einen Auto-Commit ohne Freigabe.
2. Erkennt eine `ckan`-Quelle eine echte Änderung gegenüber dem letzten bekannten Stand, wird zusätzlich ein
   **GitHub Issue** angelegt ("Quelle geändert: …", alter/neuer Wert). Es wird bewusst **kein** Pull Request mit
   automatisch berechneten CSV-Werten erzeugt – die Umrechnung Portal-Rohdaten → lokales Jahresformat ist pro
   Quelle unterschiedlich (siehe Feldabgleich-Notizen in `data/source-registry.json`, z. B. bei `unfaelle`), und
   fehlerhaft generierte Zahlen sollen nicht ungeprüft in die Diagramme wandern. Die eigentliche CSV-Aktualisierung
   bleibt ein manueller Schritt (siehe oben), das Issue ist nur die Erinnerung dazu und wird danach manuell
   geschlossen.

`heuristic`-Quellen werden ohne Netzwerk-Call geprüft (lokales `local_updated`-Datum aus dem letzten Stand
übernommen, da der Server keinen Git-Zugriff hat, gegen den bekannten Turnus) – das Datum wird nur aktuell
gehalten, wenn `scripts/check_sources.py` lokal läuft (hat Git-Zugriff) und committet wird, z. B. im Rahmen eines
manuellen CSV-Updates.

Der GitHub-Token in `cron/_cfg.php` braucht dafür zusätzlich zu `Contents: Read+Write` auch `Issues: Read+Write`.

Referenztabelle (Datei ↔ Turnus), inhaltlich identisch mit der Registry, hier als schneller Überblick beim CSV-Bearbeiten:

| Datensatz | Datei | Quelle | Turnus |
|---|---|---|---|
| Parkraumkarte | `parkraummap.csv` | [OpenData LHM – Parkhäuser und Tiefgaragen](https://opendata.muenchen.de/dataset/parkhaeuser-munchen) + eigene Erfassung/Crowdsourcing | laufend |
| Parkhäuser Innenstadt Live | `latest.json`, `details/`, `summary/` | [Parkleitsystem München Zentrum](https://pls-muc-z.com/pls/info/parkhaus.html) | alle 2 Stunden (Server-Cron) |
| PKW-Bestand | `pkw_bestand.csv` | [OpenData LHM – Monatszahlen KFZ-Bestand](https://opendata.muenchen.de/dataset/monatszahlen-kfz-bestand) | monatlich (Portal), lokal jährlich |
| Neuzulassungen | `neuzulassungen_fahrzeugtypen.csv` | [OpenData LHM – Monatszahlen KFZ-Erstzulassungen](https://opendata.muenchen.de/dataset/monatszahlen-kfz-neuzulassungen) | monatlich (Portal), lokal jährlich |
| Motorisierungsgrad je Stadtbezirk | `indikat_verkehr_motorisierungsgrad_personenkraftwagen.csv` | [OpenData München – Indikatorenatlas Verkehr](https://opendata.muenchen.de/dataset/indikatorenatlas-verkehr-motorisierungsgrad-personenkraftwagen-83r65mct) | jährlich |
| Autobesitz Haushalt | `pkw_haushalt.csv` | [MiD / SrV 2023 – TU Dresden](https://muenchenunterwegs.de/content/3099/download/munchen-steckbrief-tu-dresden.pdf) | alle ~5 Jahre |
| Autobesitz Einkommen | `pkw_einkommen.csv` | [Bevölkerungsbefragung LHM](https://stadt.muenchen.de/infos/bevoelkerungsbefragung.html) | alle ~5 Jahre |
| Bevölkerung | `bevoelkerung_ab_1900_stand_2024.csv` | [OpenData LHM – Bevölkerung](https://opendata.muenchen.de/dataset/bevoelkerung) | jährlich |
| Modal Split | `modal_split.csv` | [MiD / SrV 2023 – TU Dresden](https://muenchenunterwegs.de/content/3099/download/munchen-steckbrief-tu-dresden.pdf) | alle ~5 Jahre |
| SrV Stadtbezirksübersicht | `stadtbezirksprofile.csv` | [Mobilität in Städten – SrV 2023, Stadtbezirksübersicht](https://muenchenunterwegs.de/content/3536/download/srv-munchen-stadtbezirksubersicht.pdf) | alle ~5 Jahre |
| ÖPNV-Preise | `preissteigerung_oepnv_parken.csv` | [MVV Tarifbestimmungen](https://www.mvv-muenchen.de) | bei Preisänderung |
| MVV Fahrgäste | `mvv.csv` | [Statistisches Amt München – Statistik Verkehr](https://stadt.muenchen.de/infos/statistik-verkehr.html) | jährlich |
| Verkehrsunfälle | `visionzero_unfaelle.csv` | [Statistisches Amt München – Monatszahlenmonitoring](https://mstatistik.muenchen.de/monatszahlenmonitoring/atlas.html) (Original, keine API) – Live-Check läuft ersatzweise über [OpenData LHM](https://opendata.muenchen.de/dataset/monatszahlen-verkehrsunfaelle) (nur Summe, nicht Verletzte/Tote getrennt) | jährlich |
| Schulwegunfälle | `visionzero_schulwegunfaelle.csv` | [Statistisches Amt München – Monatszahlenmonitoring](https://mstatistik.muenchen.de/monatszahlenmonitoring/atlas.html) | jährlich |
| Parklizenzgebiete | `parklizenzgebiete.csv` | [LHM – RISI Dokument 7144556](https://risi.muenchen.de/risi/dokument/v/7144556) | bei Änderung |
| Parkplätze/Lizenzen | `parkplaetze_parklizenzen.csv` | [LHM – RISI Dokument 7144556](https://risi.muenchen.de/risi/dokument/v/7144556) | bei Änderung |
| Parklizenzgebiete Geodaten | `ruhver_prm_gebiete_poly.csv` | [OpenData LHM](https://opendata.muenchen.de/dataset/opendata_ruhver_prm_gebiete_poly) | laufend (OpenData-Portal prüfen) |
| Parkseiten | `ruhver_parkseiten_line.csv` | [OpenData LHM](https://opendata.muenchen.de/dataset/opendata_ruhver_parkseiten_line) | laufend (OpenData-Portal prüfen) |
| IHK/MotelOne | `ihk_motelone.csv` | Eigene Erhebung | bei Bedarf |
| Parkhäuser Samstage | `innenstadt_parkhaus_auslastung_exemplarisch.csv` | [Parkleitsystem München Zentrum](https://pls-muc-z.com/pls/info/parkhaus.html) | bei Bedarf |
| Parklizenzen Europa | `parklizenzen_europa.csv` | Eigene Recherche aus Stadtverwaltungen | bei Änderung |
| Öffentlicher Raum | `oeffentlicher_raum.csv` | LHM / Eigene Recherche | bei Änderung |
| Einnahmepotenzial Parklizenzen | `kalkulation_parklizenz_preise.csv` | Eigene Berechnung (30 €/Jahr vs. 102 €/Jahr × 120.000 Lizenzen) | bei Änderung |
| Einnahmepotenzial Parkscheiben | `kalkulation_parkscheibe_parkschein.csv` | Eigene Berechnung (9.800 Plätze × 14 h × 2,60 €/h) | bei Änderung |

**Workflow CSV-Update:**

```bash
# 1. CSV lokal bearbeiten
# 2. Committen und pushen - löst den GitHub-Actions-Deploy aus, kein scp mehr nötig
git add data/pkw_bestand.csv
git commit -m "data: PKW-Bestand 2025 aktualisiert"
git pull --rebase   # automatische Cron-Commits vorher einsammeln
git push
```

---

## Live-Daten (Innenstadt-Parkhäuser)

Der Cron-Job in `cron/` scrapt alle 2 Stunden die Parkhaus-Auslastung vom Parkleitsystem München Zentrum und schreibt:

- `data/latest.json` — aktueller Stand aller Sensoren
- `data/details/YYYY-MM.csv` — Rohdaten je Monat
- `data/summary/YYYY-MM.csv` — Tagesaggregat je Monat

Quelle: [pls-muc-z.com](https://pls-muc-z.com/pls/info/parkhaus.html) (Setrix AG / Siemens im Auftrag der LHM)

---

## Embed-System

### Diagramme

Jedes Diagramm kann als iFrame eingebettet werden:

```html
<!-- Ohne Rahmen -->
<iframe src="https://data.parkraumwende.de/embed.html?chart=pkw-gesamt"
        width="680" height="420" frameborder="0"
        style="border:0;max-width:100%;" loading="lazy"></iframe>

<!-- Mit Rahmen (card style) -->
<iframe src="https://data.parkraumwende.de/embed.html?chart=pkw-gesamt&style=card"
        width="680" height="420" frameborder="0"
        style="border:1px solid #e2e8f0;border-radius:4px;max-width:100%;" loading="lazy"></iframe>
```

### Karten

Karten haben eigene Embed-Seiten:

```html
<!-- Parklizenzgebiete-Karte -->
<iframe src="https://data.parkraumwende.de/embed-parklizenz.html"
        width="680" height="500" frameborder="0"
        style="border:0;max-width:100%;" loading="lazy"></iframe>
```

Der Embed-Code wird automatisch über den **⟨/⟩ Einbetten**-Button generiert — bei Diagrammen auf der Kachel, bei Karten in der Statistikleiste des jeweiligen Tabs.

---

## Daten & Lizenz

Eigene Datensätze: **CC BY 4.0** — Namensnennung: *Parkraumwende München, data.parkraumwende.de*

Statistische Daten der LHM und öffentlicher Stellen: **Datenlizenz Deutschland – Namensnennung 2.0**

Vollständige Quellenangaben: [data.parkraumwende.de/quellen.html](https://data.parkraumwende.de/quellen.html)

