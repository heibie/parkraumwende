# data.parkraumwende.de

Offene Karten und Daten zum Parkraum in München – Parkhäuser, Tiefgaragen, Parklizenzgebiete, Parkseiten, Live-Auslastung, Statistiken und Analysen.

**→ [data.parkraumwende.de](https://data.parkraumwende.de)**

---

## Architektur

Statische Single-Page-Application ohne Build-Pipeline. Alles läuft direkt im Browser.

```
index.html          — SPA mit fünf Tabs: Karte, Innenstadt, Parklizenzgebiete, Parkseiten, Statistiken
embed.html          — Standalone-iFrame für einzelne Diagramme
quellen.html        — Quelldokumentation aller Datensätze
content/            — Redaktionelle Infotexte je Tab (Markdown, im Browser gerendert)
data/               — CSV-Datensätze + datapackage.json + Live-Daten
cron/               — Server-seitige Cron-Scripts (PHP)
scripts/            — Hilfsskripte (Datenaufbereitung)
```

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

Kein Build-Step nötig – einfach einen lokalen HTTP-Server starten (direkt als `file://` funktioniert wegen PapaParse-Requests nicht):

```bash
cd data.parkraumwende.de
python3 -m http.server 8080
# → http://localhost:8080
```

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
- **Rot** = überbucht, **Grün** = nicht überbucht, **Grau** = keine Statistikdaten
- Zahl im Polygon = Differenz Ausweise minus Stellplätze
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

## Neues Diagramm hinzufügen

> ⚠️ **Immer beide Dateien anfassen:** `index.html` UND `embed.html`

### Checkliste

- [ ] **`index.html`**: `stat-card` HTML mit `data-chart="CHART_ID"` und `<button class="embed-btn">` anlegen
- [ ] **`index.html`**: Chart-Initialisierung in `initStatistiken()` implementieren
- [ ] **`embed.html`**: Eintrag in `CHARTS`-Registry (title, sub, source, init)
- [ ] **`embed.html`**: `init`-Funktion implementieren — Canvas-ID ist immer `'chart'`
- [ ] **`quellen.html`**: Quellenkarte in der passenden Sektion ergänzen
- [ ] **`data/datapackage.json`**: Neue Ressource mit Schema dokumentieren
- [ ] **CSV deployen**: `scp data/neue.csv jauchetaucher:…`
- [ ] **Beide HTML-Dateien deployen** und committen

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

| Datensatz | Datei | Quelle | Turnus |
|---|---|---|---|
| PKW-Bestand | `pkw_bestand.csv` | [Statistisches Amt München – Monatszahlenmonitoring](https://mstatistik.muenchen.de/monatszahlenmonitoring/atlas.html) | jährlich |
| Neuzulassungen | `neuzulassungen_fahrzeugtypen.csv` | [Statistisches Amt München – Monatszahlenmonitoring](https://mstatistik.muenchen.de/monatszahlenmonitoring/atlas.html) | jährlich |
| Autobesitz Haushalt | `pkw_haushalt.csv` | [MiD / SrV 2023 – TU Dresden](https://muenchenunterwegs.de/content/3099/download/munchen-steckbrief-tu-dresden.pdf) | alle ~5 Jahre |
| Autobesitz Einkommen | `pkw_einkommen.csv` | [Bevölkerungsbefragung LHM](https://stadt.muenchen.de/infos/bevoelkerungsbefragung.html) | alle ~5 Jahre |
| Bevölkerung | `bevoelkerung_ab_1900_stand_2024.csv` | [OpenData LHM – Bevölkerung](https://opendata.muenchen.de/dataset/bevoelkerung) | jährlich |
| Modal Split | `modal_split.csv` | [MiD / SrV 2023 – TU Dresden](https://muenchenunterwegs.de/content/3099/download/munchen-steckbrief-tu-dresden.pdf) | alle ~5 Jahre |
| ÖPNV-Preise | `preissteigerung_oepnv_parken.csv` | [MVV Tarifbestimmungen](https://www.mvv-muenchen.de) | bei Preisänderung |
| MVV Fahrgäste | `mvv.csv` | [Statistisches Amt München – Statistik Verkehr](https://stadt.muenchen.de/infos/statistik-verkehr.html) | jährlich |
| Verkehrsunfälle | `visionzero_unfaelle.csv` | [Statistisches Amt München – Monatszahlenmonitoring](https://mstatistik.muenchen.de/monatszahlenmonitoring/atlas.html) | jährlich |
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
# 2. Auf Server deployen (per scp)
# 3. Committen
git add data/pkw_bestand.csv
git commit -m "data: PKW-Bestand 2025 aktualisiert"
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

