#!/usr/bin/env python3
"""
import-issues.py – GitHub Issues → parkraummap.csv

Holt offene Issues mit Titel "Karteneintrag:" aus heibie/parkraumwende,
parst die Markdown-Tabelle und hängt neue Einträge an parkraummap.csv.

Neue Einträge bekommen Verifiziert=Nein → unsichtbar auf der Live-Karte.
Zum Freischalten: Verifiziert auf Ja setzen und deployen.

Bereits importierte Issue-Nummern werden in data/imported-issues.json
gemerkt, damit kein Doppel-Import passiert.

Usage:
    python3 import-issues.py
"""

import csv
import json
import re
import ssl
import urllib.request
from datetime import datetime
from pathlib import Path

REPO      = "heibie/parkraumwende"
API_URL   = f"https://api.github.com/repos/{REPO}/issues"
DATA_DIR  = Path(__file__).parent / "data"
CSV_FILE  = DATA_DIR / "parkraummap.csv"
IMPORTED  = DATA_DIR / "imported-issues.json"


# ── GitHub API ────────────────────────────────────────────────────────────────

def fetch_issues():
    url = f"{API_URL}?state=open&per_page=100"
    req = urllib.request.Request(url, headers={"User-Agent": "parkraumwende-import/1.0"})
    ctx = ssl.create_default_context()
    ctx.load_verify_locations("/etc/ssl/cert.pem")
    with urllib.request.urlopen(req, context=ctx) as resp:
        return json.load(resp)


# ── Markdown-Tabelle parsen ───────────────────────────────────────────────────

def parse_table(body):
    """Liest | **Key** | Wert | Zeilen aus dem Issue-Body."""
    fields = {}
    for line in (body or "").splitlines():
        m = re.match(r'\|\s*\*\*(.+?)\*\*\s*\|\s*(.+?)\s*\|', line)
        if m:
            fields[m.group(1).strip()] = m.group(2).strip()
    return fields


# ── Adresse aufdröseln ────────────────────────────────────────────────────────

def parse_address(addr):
    """'Fürstenstraße 3, 80333 München' → (straße, nr, plz, ort)"""
    parts = addr.split(",", 1)
    street = parts[0].strip()
    city   = parts[1].strip() if len(parts) > 1 else ""

    m = re.match(r"^(.+?)\s+(\d+\w*)$", street)
    strasse, hausnr = (m.group(1), m.group(2)) if m else (street, "")

    m = re.match(r"^(\d{5})\s+(.+)$", city)
    plz, ort = (m.group(1), m.group(2)) if m else ("", city)

    return strasse, hausnr, plz, ort


def parse_coords(s):
    """'48.1461006, 11.5770981' → ('48.1461006', '11.5770981')"""
    parts = s.split(",")
    if len(parts) == 2:
        return parts[0].strip(), parts[1].strip()
    return "", ""


# ── Hauptprogramm ─────────────────────────────────────────────────────────────

def main():
    # Bereits importierte Issues laden
    imported = set(json.loads(IMPORTED.read_text())) if IMPORTED.exists() else set()

    # Bestehende CSV lesen
    with open(CSV_FILE, newline="", encoding="utf-8") as f:
        reader    = csv.DictReader(f, delimiter=";")
        fieldnames = reader.fieldnames
        rows      = list(reader)

    # Neue Spalte "Issue" einfügen falls noch nicht vorhanden
    if "Issue" not in fieldnames:
        fieldnames = list(fieldnames) + ["Issue"]

    # Issues holen
    try:
        issues = fetch_issues()
    except Exception as e:
        print(f"Fehler beim Abrufen der GitHub Issues: {e}")
        return

    new_count    = 0
    new_imported = []

    for issue in issues:
        num   = issue["number"]
        title = issue.get("title", "")

        if num in imported:
            continue
        if not title.startswith("Karteneintrag:"):
            continue

        fields = parse_table(issue.get("body", ""))
        if not fields:
            print(f"  Übersprungen Issue #{num}: kein Tabelleninhalt")
            imported.add(num)
            new_imported.append(num)
            continue

        strasse, hausnr, plz, ort = parse_address(fields.get("Adresse", ""))
        lat, lon                  = parse_coords(fields.get("Koordinaten", ""))

        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

        row = {f: "" for f in fieldnames}
        row.update({
            "Name":             fields.get("Name", ""),
            "Typ":              fields.get("Typ", ""),
            "Bewirtschaftungsart": fields.get("Bewirtschaftung", ""),
            "Immobilientyp":    fields.get("Immobilientyp", ""),
            "Plätze":           fields.get("Stellplätze", ""),
            "Freie Plätze":     fields.get("Davon frei", ""),
            "Dauerparken":      fields.get("Dauerparken", ""),
            "Kurzzeitparken":   fields.get("Kurzzeitparken", ""),
            "Straße":           strasse,
            "Hausnummer":       hausnr,
            "PLZ":              plz,
            "Ort":              ort,
            "Latitude":         lat,
            "Longitude":        lon,
            "Bemerkung":        fields.get("Bemerkung", ""),
            "Quelle":           "Crowdsourcing",
            "Aktiv":            "Ja",
            "Verifiziert":      "Nein",
            "Erstellt am":      now,
            "Aktualisiert am":  now,
            "Issue":            str(num),
        })

        rows.append(row)
        imported.add(num)
        new_imported.append(num)
        new_count += 1

        name_str = fields.get("Name", "?")
        addr_str = f"{strasse} {hausnr}, {plz} {ort}".strip(", ")
        print(f"  Importiert: Issue #{num} – {name_str} ({addr_str})")

    if new_count == 0 and not new_imported:
        print("Keine neuen Issues zum Importieren.")
        return

    # CSV zurückschreiben
    with open(CSV_FILE, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames, delimiter=";", extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)

    # Importierte Issues speichern
    IMPORTED.write_text(json.dumps(sorted(imported)))

    if new_count > 0:
        print(f"\n{new_count} neue Einträge in parkraummap.csv")
        print("Zum Freischalten lokal prüfen (http://data.parkraumwende.test/?preview=1),")
        print("dann Verifiziert=Ja setzen und deployen.")


if __name__ == "__main__":
    main()
