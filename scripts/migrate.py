#!/usr/bin/env python3
"""
Einmalige Migration: MySQL-Dumps → monatliche CSV-Flatfiles

Liest:
  data/innenstadt_data_details.sql  (557k Zeilen, eine pro Parkhaus pro Cron-Run)

Schreibt:
  data/details/YYYY-MM.csv  (eine Datei pro Monat)
  data/summary/YYYY-MM.csv  (Gesamtauslastung pro Timestamp)

SN 106525 (Hauptbahnhof) taucht in den Rohdaten doppelt auf (P11 + P25)
und wird hier zu einem Eintrag zusammengefasst (frei + kap addiert).
"""

import re
import os
import csv
from collections import defaultdict

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SQL_DETAILS = os.path.join(BASE, 'data', 'innenstadt_data_details.sql')
OUT_DETAILS = os.path.join(BASE, 'data', 'details')
OUT_SUMMARY = os.path.join(BASE, 'data', 'summary')

os.makedirs(OUT_DETAILS, exist_ok=True)
os.makedirs(OUT_SUMMARY, exist_ok=True)

# Regex für data_details-Zeilen:
# (id, sn, 'typ', ph, 'parkhaus', frei, kap, aktiv, 'createdAt', 'createdAtHour')
ROW = re.compile(
    r"^\(\d+,\s*(\d+),\s*'[^']*',\s*\d+,\s*'([^']*)',\s*(\d+),\s*(\d+),\s*(\d+),"
    r"\s*'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})',"
)

print("Lese SQL-Dump …")
# Gruppierung: (yearmonth, timestamp) → {sn: row}
# Pro Timestamp werden alle Parkhäuser gesammelt; SN 106525 wird zusammengeführt.
months: dict[str, dict[str, list]] = defaultdict(lambda: defaultdict(list))
# months[yearmonth][timestamp] = [row, ...]

total = 0
with open(SQL_DETAILS, encoding='utf-8', errors='replace') as f:
    for line in f:
        m = ROW.match(line.strip())
        if not m:
            continue
        sn, parkhaus, frei, kap, aktiv, ts = m.groups()
        ym = ts[:7]  # YYYY-MM
        months[ym][ts].append({
            'sn':       sn,
            'parkhaus': parkhaus,
            'frei':     int(frei),
            'kap':      int(kap),
            'aktiv':    int(aktiv),
            'ts':       ts,
        })
        total += 1
        if total % 50000 == 0:
            print(f"  {total} Zeilen gelesen …")

print(f"  {total} Zeilen gesamt, {len(months)} Monate")

DETAIL_HEADERS = ['timestamp', 'sn', 'parkhaus', 'frei', 'kap', 'aktiv']
SUMMARY_HEADERS = ['timestamp', 'frei_gesamt', 'belegt_gesamt', 'kap_gesamt']

for ym in sorted(months.keys()):
    timestamps = months[ym]
    detail_rows = []
    summary_rows = []

    for ts in sorted(timestamps.keys()):
        raw_rows = timestamps[ts]

        # SN 106525 deduplizieren
        rows_106525 = [r for r in raw_rows if r['sn'] == '106525']
        rows_other  = [r for r in raw_rows if r['sn'] != '106525']

        if rows_106525:
            merged = {
                'sn':       '106525',
                'parkhaus': 'Parkhaus am Hauptbahnhof',
                'frei':     sum(r['frei'] for r in rows_106525),
                'kap':      sum(r['kap']  for r in rows_106525),
                'aktiv':    max(r['aktiv'] for r in rows_106525),
                'ts':       ts,
            }
            rows_other.append(merged)

        # Details
        for r in rows_other:
            detail_rows.append([ts, r['sn'], r['parkhaus'], r['frei'], r['kap'], r['aktiv']])

        # Summary (nur aktive Parkhäuser)
        active = [r for r in rows_other if r['aktiv']]
        if active:
            kap_g  = sum(r['kap']  for r in active)
            frei_g = sum(r['frei'] for r in active)
            summary_rows.append([ts, frei_g, kap_g - frei_g, kap_g])

    # Details schreiben
    detail_path = os.path.join(OUT_DETAILS, f'{ym}.csv')
    with open(detail_path, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f)
        w.writerow(DETAIL_HEADERS)
        w.writerows(detail_rows)

    # Summary schreiben
    summary_path = os.path.join(OUT_SUMMARY, f'{ym}.csv')
    with open(summary_path, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f)
        w.writerow(SUMMARY_HEADERS)
        w.writerows(summary_rows)

    print(f"  {ym}: {len(detail_rows)} Detail-Zeilen, {len(summary_rows)} Summary-Zeilen")

print("\nMigration abgeschlossen.")
