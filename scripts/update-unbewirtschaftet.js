#!/usr/bin/env node
/**
 * Berechnet data/muenchen_unbewirtschaftet.geojson neu.
 *
 * Liest:
 *   data/ruhver_prm_gebiete_poly.csv (Parklizenzgebiete, WKT-Polygone, UTM Zone 32N / EPSG:25832)
 *   data/muenchen_grenze.geojson     (München-Umgriff, WGS84 – ändert sich nicht, nur zum Differenzbilden)
 *
 * Schreibt:
 *   data/muenchen_unbewirtschaftet.geojson (München-Umgriff minus Union aller Parklizenzgebiete)
 *
 * Gibt am Ende die Flächenwerte aus (für die hartcodierten Prozent-/km²-Angaben
 * in index.html, Stats-Bar + Legende auf der Parklizenzgebiete-Karte).
 *
 * Aufruf: node scripts/update-unbewirtschaftet.js
 * (Abhängigkeiten: scripts/package.json, node_modules NICHT committen)
 */

const fs = require('fs');
const path = require('path');
const proj4 = require('proj4');
const turf = require('@turf/turf');

const ROOT       = path.join(__dirname, '..');
const CSV_PATH   = path.join(ROOT, 'data/ruhver_prm_gebiete_poly.csv');
const GRENZE_PATH = path.join(ROOT, 'data/muenchen_grenze.geojson');
const OUT_PATH   = path.join(ROOT, 'data/muenchen_unbewirtschaftet.geojson');

const UTM32N = '+proj=utm +zone=32 +ellps=GRS80 +towgs84=0,0,0,0,0,0,0 +units=m +no_defs';

function parseCsvLine(line) {
  // Einfacher CSV-Parser: nur die letzte Spalte (shape) ist gequotet und enthält Kommas
  const firstQuote = line.indexOf('"');
  const head = line.slice(0, firstQuote - 1).split(',');
  const shape = line.slice(firstQuote + 1, line.lastIndexOf('"'));
  return { name: head[3], shape };
}

function wktPolygonToLngLat(wkt) {
  const match = wkt.match(/POLYGON\s*\(\(([^)]+)\)\)/);
  if (!match) return null;
  return match[1].split(',').map(pair => {
    const [x, y] = pair.trim().split(/\s+/).map(Number);
    return proj4(UTM32N, 'WGS84', [x, y]);
  });
}

function main() {
  const csvText = fs.readFileSync(CSV_PATH, 'utf8');
  const lines = csvText.split(/\r\n|\n/).filter(l => l.trim());
  lines.shift(); // Header

  const polygons = [];
  for (const line of lines) {
    const { name, shape } = parseCsvLine(line);
    const ring = wktPolygonToLngLat(shape);
    if (!ring) {
      console.warn(`  WARNUNG: Kein Polygon gefunden für "${name}", übersprungen`);
      continue;
    }
    polygons.push(turf.polygon([ring], { name }));
  }
  console.log(`Parklizenzgebiete geladen: ${polygons.length}`);

  // Union aller Gebiete, schrittweise (turf.union nimmt in v7 eine FeatureCollection)
  let unionAll = polygons[0];
  for (let i = 1; i < polygons.length; i++) {
    try {
      unionAll = turf.union(turf.featureCollection([unionAll, polygons[i]]));
    } catch (e) {
      console.warn(`  WARNUNG: Union mit Gebiet #${i} (${polygons[i].properties.name}) fehlgeschlagen: ${e.message}`);
    }
  }

  const grenze = JSON.parse(fs.readFileSync(GRENZE_PATH, 'utf8'));

  const diff = turf.difference(turf.featureCollection([grenze, unionAll]));
  diff.properties = {};

  fs.writeFileSync(OUT_PATH, JSON.stringify(diff));

  const grenzeArea = turf.area(grenze);
  const diffArea   = turf.area(diff);
  const pct        = (diffArea / grenzeArea) * 100;

  console.log('');
  console.log(`Stadtfläche gesamt:      ${(grenzeArea / 1e6).toFixed(1)} km²`);
  console.log(`Unbewirtschaftet:        ${(diffArea / 1e6).toFixed(1)} km² (${pct.toFixed(1)} %)`);
  console.log(`Bewirtschaftet:          ${((grenzeArea - diffArea) / 1e6).toFixed(1)} km²`);
  console.log('');
  console.log(`Geschrieben: ${path.relative(ROOT, OUT_PATH)}`);
  console.log('-> index.html Stats-Bar + Legende (Prozent/km²) ggf. manuell nachziehen.');
}

main();
