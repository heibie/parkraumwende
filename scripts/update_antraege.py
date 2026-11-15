#!/usr/bin/env python3
"""
Aktualisiert data/antraege.csv + data/antraege_verlauf.csv aus den Detailseiten
des Ratsinformationssystems (RIS) der LHM.

Pro Antrag wird https://risi.muenchen.de/risi/antrag/detail/<ris_id> abgerufen
und daraus Vorgangsnummer, Typ, BA, Antragsteller, Referat, Status, Fristen,
Beschlüsse und Antwortschreiben gelesen. Redaktionelle Spalten (Themen,
Richtung, Fraktion, Kurzfassung, Ergebnis …) werden nie überschrieben.

Neue Anträge findet täglich cron/update_antraege.php (PHP-Port dieses Skripts,
läuft auf All-Inkl) über die RIS-RSS-Feeds. Manuell: Zeile mit neuer `id` + `ris_id`
in antraege.csv ergänzen, Skript laufen lassen, redaktionelle Spalten ausfüllen.
Zeilen mit verifiziert=Ausgeschlossen werden übersprungen.

Hinweis: Die RIS-WAF blockt IPv6 und Standard-User-Agents ("Access Denied")
→ Abruf erzwingt IPv4 und schickt einen Browser-User-Agent.

Usage: python3 scripts/update_antraege.py [--dry-run] [ris_id ...]
"""

import csv
import os
import re
import socket
import ssl
import sys
import time
import urllib.request
from datetime import date

from bs4 import BeautifulSoup

CSV_PATH = "data/antraege.csv"
VERLAUF_PATH = "data/antraege_verlauf.csv"
RIS_BASE = "https://risi.muenchen.de/risi/"
DELAY = 2  # Sekunden zwischen Abrufen

FIELDS = [
    "id", "ris_id", "vorgangsnr", "ris_url",
    "typ", "gremium", "ba_nr", "ba_name", "wahlperiode",
    "antragsteller", "fraktion_norm", "initiative",
    "titel", "themen", "richtung", "kurzfassung",
    "datum_gestellt", "referat", "ris_status", "frist", "fristverlaengert_bis", "anzahl_fristverl", "erledigt_am",
    "ergebnis", "beschluss_datum", "beschluss_gremium", "antwort_url", "antwort_kurz",
    "verifiziert", "quelle_fund", "zuletzt_geprueft",
]
# Spalten, die das Skript aus dem RIS befüllt – alle anderen sind redaktionell
AUTO_FIELDS = {
    "vorgangsnr", "ris_url", "typ", "gremium", "ba_nr", "ba_name", "wahlperiode",
    "antragsteller", "initiative", "titel",
    "datum_gestellt", "referat", "ris_status", "frist", "fristverlaengert_bis", "anzahl_fristverl", "erledigt_am",
    "beschluss_datum", "beschluss_gremium", "antwort_url", "zuletzt_geprueft",
}
VERLAUF_FIELDS = ["antrag_id", "datum", "art", "text", "url"]

# Fraktionsnamen im RIS ("CSU mit FREIE WÄHLER", "FDP BAYERNPARTEI Stadtratsfraktion" …) → Tags
PARTEIEN = [("CSU", "CSU"), ("FREIE WÄHLER", "Freie Wähler"), ("Freie Wähler", "Freie Wähler"),
            ("Grüne", "Grüne"), ("GRÜNE", "Grüne"), ("Rosa Liste", "Rosa Liste"), ("SPD", "SPD"),
            ("Volt", "Volt"), ("FDP", "FDP"), ("BAYERNPARTEI", "Bayernpartei"), ("Bayernpartei", "Bayernpartei"),
            ("ÖDP", "ÖDP"), ("LINKE", "Linke"), ("Linke", "Linke"), ("Die PARTEI", "PARTEI"),
            ("AfD", "AfD"), ("München-Liste", "München Liste"), ("München Liste", "München Liste")]


def parteien(text):
    found = []
    for key, tag in PARTEIEN:
        if key in (text or "") and tag not in found:
            found.append(tag)
    return "|".join(found)

UA = ("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/130.0 Safari/537.36")

# IPv4 erzwingen (WAF)
_orig_getaddrinfo = socket.getaddrinfo
socket.getaddrinfo = lambda host, port, family=0, *a, **kw: _orig_getaddrinfo(host, port, socket.AF_INET, *a, **kw)


def ssl_context():
    ctx = ssl.create_default_context()
    if os.path.exists("/etc/ssl/cert.pem"):
        ctx.load_verify_locations("/etc/ssl/cert.pem")
    return ctx


def fetch(url, tries=3):
    req = urllib.request.Request(url, headers={
        "User-Agent": UA, "Accept-Language": "de-DE,de;q=0.9",
        "Accept": "text/html,application/xhtml+xml",
    })
    for i in range(tries):
        try:
            with urllib.request.urlopen(req, context=ssl_context(), timeout=60) as resp:
                return resp.read().decode("utf-8")
        except Exception as e:
            if i == tries - 1:
                raise
            print(f"  Fehler ({e}), neuer Versuch …", file=sys.stderr)
            time.sleep(10 * (i + 1))


def iso(d):
    """'04.08.2025' → '2025-08-04'"""
    m = re.search(r"(\d{2})\.(\d{2})\.(\d{4})", d or "")
    return f"{m.group(3)}-{m.group(2)}-{m.group(1)}" if m else ""


def absurl(href):
    return urllib.request.urljoin(RIS_BASE + "antrag/detail/0", href) if href else ""


def norm_typ(prefix):
    return {
        "TOP mit Vorgang": "BA-Antrag (TOP)",
    }.get(prefix, prefix)


def parse(html, ris_id):
    s = BeautifulSoup(html, "html.parser")
    out = {"ris_url": f"{RIS_BASE}antrag/detail/{ris_id}"}

    # Kopf: "StR-Antrag 20-26 / A 05827 (Aufgegriffen)"
    h1 = s.find("h1").get_text(" ", strip=True)
    m = re.match(r"(.+?)\s+(\d{2}-\d{2}\s*/\s*[A-Z]\s*\d+)\s*(?:\((.+)\))?$", h1)
    if m:
        out["typ"] = norm_typ(m.group(1).strip())
        out["vorgangsnr"] = re.sub(r"\s+", " ", m.group(2)).replace(" / ", "/").replace("/ ", "/")
        out["ris_status"] = (m.group(3) or "").strip()
    typ = out.get("typ", "")
    out["gremium"] = "Stadtrat" if typ.startswith("StR") else "Bürgerversammlung" if typ.startswith("BV") else "Bezirksausschuss"

    betreff = s.select_one("#sectionheader-betreff")
    if betreff:
        sec = betreff.find_parent("section")
        txt = sec.get_text(" ", strip=True)
        txt = re.sub(r"^Betreff\s*", "", txt)
        txt = re.sub(r"\s*Mehr anzeigen\s*Weniger anzeigen\s*$", "", txt)
        out["titel"] = re.sub(r"\s+", " ", txt)

    kv = {}
    for row in s.select(".keyvalue-row"):
        k, v = row.select_one(".keyvalue-key"), row.select_one(".keyvalue-value")
        if k and v:
            kv[k.get_text(" ", strip=True).rstrip(": ").strip()] = v.get_text(" ", strip=True)

    out["datum_gestellt"] = iso(kv.get("Gestellt am") or kv.get("Beschlossen am") or kv.get("Erfasst am"))
    out["wahlperiode"] = kv.get("Wahlperiode", "")
    out["antragsteller"] = kv.get("Gestellt von", "")
    out["initiative"] = kv.get("Initiative", "")
    out["referat"] = kv.get("Zuständiges Referat", "")
    out["frist"] = iso(kv.get("Bearbeitungs-Frist"))
    out["fristverlaengert_bis"] = iso(kv.get("Aktuelle Fristverl."))
    bisher = kv.get("Bisherige Fristverl.", "")
    out["anzahl_fristverl"] = str(len(re.findall(r"\d{2}\.\d{2}\.\d{4}", bisher))) if bisher else "0"
    out["erledigt_am"] = iso(kv.get("Erledigt am"))
    ba = kv.get("Bezirksausschuss") or kv.get("Stadtbezirk") or ""
    mb = re.match(r"(\d+)\s*-\s*(.+)", ba)
    if mb:
        out["ba_nr"], out["ba_name"] = str(int(mb.group(1))), mb.group(2).strip()

    verlauf = []
    if out["datum_gestellt"]:
        verlauf.append({"datum": out["datum_gestellt"], "art": "gestellt", "text": typ or "Antrag gestellt", "url": ""})

    sections = {}
    for sec in s.find_all("section"):
        h = sec.find(["h2", "h3"])
        if h:
            sections[h.get_text(strip=True)] = sec

    # Dokumente: erstes = Antrag, "... AS.pdf" = Antwortschreiben
    for a in (sections.get("Dokumente") or s.new_tag("x")).find_all("a", href=re.compile(r"dokument/v/")):
        name = a.get_text(strip=True)
        if re.search(r"\bAS\b|Antwort", name):
            out["antwort_url"] = absurl(a["href"])
            verlauf.append({"datum": "", "art": "antwort", "text": "Antwortschreiben: " + name, "url": absurl(a["href"])})

    # Ergebnisse: Sitzungen mit Gremium, Sitzungsvorlage, Beschluss-Dokument
    beschluesse = []
    erg = sections.get("Ergebnisse")
    if erg:
        for item in erg.select(".list-group-item"):
            datum_a = item.find("a", href=re.compile(r"sitzung/detail/"))
            gremium_a = item.find("a", href=re.compile(r"gremium/detail/"))
            sv_a = item.find("a", href=re.compile(r"sitzungsvorlage/detail/"))
            doc_a = item.find("a", class_="downloadlink")
            txt = item.get_text(" ", strip=True)
            mbeschl = re.search(r"Beschluss:\s*([^\n]+?)(?:\s+Entscheidung|\s+Zum Tagesordnungspunkt|$)", txt)
            e = {
                "datum": iso(datum_a.get_text(strip=True)) if datum_a else "",
                "gremium": gremium_a.get_text(" ", strip=True) if gremium_a else "",
                "sv": sv_a.get_text(" ", strip=True) if sv_a else "",
                "url": absurl((doc_a or sv_a)["href"]) if (doc_a or sv_a) else "",
            }
            parts = [e["gremium"]]
            if e["sv"]:
                parts.append(e["sv"])
            if mbeschl:
                parts.append("Beschluss: " + mbeschl.group(1).strip())
            verlauf.append({"datum": e["datum"], "art": "sitzung", "text": " – ".join(p for p in parts if p), "url": e["url"]})
            beschluesse.append(e)
    # Bei Sammelvorlagen (z.B. V 11904 in allen 25 BAs) nur Stadtrats-/Ausschusssitzungen als "Beschluss" werten
    haupt = [b for b in beschluesse if not b["gremium"].startswith("BA ")] or beschluesse
    if haupt:
        last = max(haupt, key=lambda b: b["datum"])
        out["beschluss_datum"] = last["datum"]
        out["beschluss_gremium"] = last["gremium"]
        if not out.get("antwort_url") and last["url"]:
            out["antwort_url"] = last["url"]

    hist = sections.get("Historie")
    if hist:
        for li in reversed(hist.select(".list-group-item")):  # RIS listet neueste zuerst
            txt = li.get_text(" ", strip=True)
            mh = re.match(r"(\d{2}\.\d{2}\.\d{4})\s+(.*)", txt)
            if mh:
                verlauf.append({"datum": iso(mh.group(1)), "art": "status", "text": mh.group(2), "url": ""})

    # Antwortschreiben-Datum = Datum des Status "Antwortschreiben"/"Erledigt" aus der Historie
    for v in verlauf:
        if v["art"] == "antwort" and not v["datum"]:
            cand = [h["datum"] for h in verlauf if h["art"] == "status" and re.search(r"Status: (Antwortschreiben|Erledigt)", h["text"])]
            v["datum"] = min(cand) if cand else out.get("erledigt_am", "")

    # Sitzungen mehrfach (Sammelvorlage in vielen BAs) → zusammenfassen
    seen, dedup = set(), []
    for v in verlauf:
        key = (v["datum"], v["art"], v["text"])
        if key not in seen:
            seen.add(key)
            dedup.append(v)
    sammel = [v for v in dedup if v["art"] == "sitzung" and v["text"].startswith("BA ")]
    if len(sammel) > 3:
        dedup = [v for v in dedup if v not in sammel]
        dedup.append({"datum": min(v["datum"] for v in sammel), "art": "sitzung",
                      "text": f"Behandlung in {len(sammel)} Bezirksausschüssen ({sammel[0]['text'].split(' – ', 1)[-1]})",
                      "url": sammel[0]["url"]})
    dedup.sort(key=lambda v: (v["datum"], {"gestellt": 0, "status": 1, "sitzung": 2, "antwort": 3}[v["art"]]))
    return out, dedup


def main():
    dry = "--dry-run" in sys.argv
    only = {a for a in sys.argv[1:] if a.isdigit()}

    with open(CSV_PATH, newline="", encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    verlauf_alt = []
    if os.path.exists(VERLAUF_PATH):
        with open(VERLAUF_PATH, newline="", encoding="utf-8") as f:
            verlauf_alt = list(csv.DictReader(f))

    verlauf_neu = {}
    heute = date.today().isoformat()
    for i, row in enumerate(rows):
        rid = (row.get("ris_id") or "").strip()
        if not rid or row.get("verifiziert") == "Ausgeschlossen" or (only and rid not in only):
            continue
        if i:
            time.sleep(DELAY)
        print(f"[{row['id']}] RIS {rid} …")
        try:
            data, verlauf = parse(fetch(f"{RIS_BASE}antrag/detail/{rid}"), rid)
        except Exception as e:
            print(f"  übersprungen: {e}", file=sys.stderr)
            continue
        changed = []
        for k in AUTO_FIELDS:
            v = data.get(k, "")
            if k == "zuletzt_geprueft":
                continue
            if v and v != row.get(k, ""):
                changed.append(f"{k}: {row.get(k, '')!r} → {v!r}")
                row[k] = v
        # Redaktionelle Spalten nur vorbelegen, wenn leer
        if not row.get("fraktion_norm"):
            row["fraktion_norm"] = parteien(row.get("antragsteller")) or parteien(row.get("titel"))
        if not row.get("ergebnis"):
            row["ergebnis"] = "beantwortet" if row.get("erledigt_am") else "Antwort offen"
        row["zuletzt_geprueft"] = heute
        for c in changed:
            print("  " + c)
        verlauf_neu[row["id"]] = [{"antrag_id": row["id"], **v} for v in verlauf]

    if dry:
        print("Dry-run – nichts geschrieben.")
        return

    with open(CSV_PATH, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=FIELDS, extrasaction="ignore", lineterminator="\n")
        w.writeheader()
        for r in rows:
            w.writerow({k: r.get(k, "") for k in FIELDS})

    alle = [v for v in verlauf_alt if v["antrag_id"] not in verlauf_neu]
    for vs in verlauf_neu.values():
        alle.extend(vs)
    alle.sort(key=lambda v: int(v["antrag_id"]))  # stabil: Reihenfolge je Antrag bleibt
    with open(VERLAUF_PATH, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=VERLAUF_FIELDS, lineterminator="\n")
        w.writeheader()
        w.writerows(alle)
    print(f"{CSV_PATH}: {len(rows)} Anträge · {VERLAUF_PATH}: {len(alle)} Einträge")


if __name__ == "__main__":
    main()
