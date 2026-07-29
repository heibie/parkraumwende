#!/usr/bin/env python3
"""
Aktualisiert data/ruhver_parkseiten_line.csv vom OpenData-WFS-Dienst der LHM,
mit möglichst minimalem Diff.

Hintergrund: Der WFS-Dienst vergibt bei jedem Abruf neue, willkürliche FIDs
(kein stabiler Schlüssel) und liefert Zeilen in wechselnder Reihenfolge -
ein einfaches Überschreiben würde bei jedem Sync fast die komplette Datei
als "geändert" zeigen, obwohl nur ein Bruchteil der Segmente sich wirklich
ändert. Stattdessen: Segmente werden über ihre Geometrie (shape, WKT) als
stabilen Schlüssel gematcht, bestehende FIDs bleiben erhalten, nur wirklich
neue/geänderte/entfernte Zeilen werden angefasst.

Quelle: https://geoportal.muenchen.de/geoserver/mor_wfs/ows?service=WFS&version=1.0.0&request=GetFeature&typeName=mor_wfs:ruhver_parkseiten_line&outputFormat=csv

Usage: python3 scripts/sync-parkseiten.py [--dry-run]
"""

import csv
import io
import sys
import urllib.request
import ssl

CSV_PATH = "data/ruhver_parkseiten_line.csv"
WFS_URL = (
    "https://geoportal.muenchen.de/geoserver/mor_wfs/ows"
    "?service=WFS&version=1.0.0&request=GetFeature"
    "&typeName=mor_wfs:ruhver_parkseiten_line&outputFormat=csv"
)


def fetch_live():
    ctx = ssl.create_default_context()
    ctx.load_verify_locations("/etc/ssl/cert.pem")
    with urllib.request.urlopen(WFS_URL, context=ctx, timeout=60) as resp:
        return resp.read().decode("utf-8")


def parse(text):
    reader = csv.DictReader(io.StringIO(text))
    return reader.fieldnames, list(reader)


def csv_field(v):
    v = "" if v is None else str(v)
    if any(c in v for c in (",", '"', "\n", "\r")):
        return '"' + v.replace('"', '""') + '"'
    return v


def row_line(header, row):
    return ",".join(csv_field(row.get(k, "")) for k in header) + "\r\n"


def main():
    dry_run = "--dry-run" in sys.argv

    with open(CSV_PATH, "r", encoding="utf-8", newline="") as f:
        old_text = f.read()
    old_header, old_rows = parse(old_text)

    print("Hole aktuelle Daten vom WFS-Dienst ...")
    live_text = fetch_live()
    new_header, new_rows = parse(live_text)

    if old_header != new_header:
        print("FEHLER: Spalten haben sich geändert, Skript prüfen. Abbruch.")
        print("  alt: ", old_header)
        print("  neu: ", new_header)
        sys.exit(1)

    other_cols = [c for c in old_header if c not in ("FID", "shape")]

    new_by_shape = {}
    for r in new_rows:
        new_by_shape.setdefault(r["shape"], []).append(r)

    max_fid = 0
    for r in old_rows:
        try:
            max_fid = max(max_fid, int(r["FID"].rsplit(".", 1)[-1]))
        except ValueError:
            pass

    kept, changed, removed = 0, 0, 0
    result_rows = []
    matched_shapes = set()

    for old_row in old_rows:
        shape = old_row["shape"]
        candidates = new_by_shape.get(shape, [])
        if not candidates:
            removed += 1
            continue
        # Falls die Geometrie mehrfach vorkommt: erstes noch nicht verwendetes Match nehmen
        new_row = candidates.pop(0)
        matched_shapes.add(shape)

        if all(old_row.get(c, "") == new_row.get(c, "") for c in other_cols):
            result_rows.append(old_row)
            kept += 1
        else:
            merged = dict(new_row)
            merged["FID"] = old_row["FID"]  # eigene FID beibehalten
            result_rows.append(merged)
            changed += 1

    added_rows = []
    for shape, candidates in new_by_shape.items():
        for r in candidates:
            max_fid += 1
            merged = dict(r)
            merged["FID"] = f"ruhver_parkseiten_line.{max_fid}"
            added_rows.append(merged)

    result_rows.extend(added_rows)

    print(f"Unverändert: {kept}")
    print(f"Geändert (gleiche Geometrie, andere Felder): {changed}")
    print(f"Entfernt: {removed}")
    print(f"Neu: {len(added_rows)}")
    print(f"Gesamt neu: {len(result_rows)} (vorher {len(old_rows)})")

    if dry_run:
        print("\n--dry-run: nichts geschrieben.")
        return

    out = [",".join(csv_field(h) for h in old_header) + "\r\n"]
    out.extend(row_line(old_header, r) for r in result_rows)

    with open(CSV_PATH, "w", encoding="utf-8", newline="") as f:
        f.write("".join(out))

    print(f"\nGeschrieben: {CSV_PATH}")


if __name__ == "__main__":
    main()
