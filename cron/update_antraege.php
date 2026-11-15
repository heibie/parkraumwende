<?php
/**
 * Antrags-Tracker: laufende Aktualisierung aus dem RIS
 *
 * Cronjob-URL: https://data.parkraumwende.de/cron/update_antraege.php?token=DEIN_CRON_TOKEN
 * Intervall: täglich (KAS-Cronjob)
 *
 * PHP-Port von scripts/update_antraege.py (All-Inkl hat kein Python). Pro Lauf:
 *
 *   1. Neue Anträge finden: die RIS-RSS-Feeds aus data/antraege-stichworte.json (StR-/BA-Anträge,
 *      BV-Empfehlungen, BV-Anfragen) werden gegen den Stichwortfilter geprüft. Treffer, deren
 *      ris_id noch nicht in data/antraege.csv steht, kommen mit verifiziert=Nein ("Vorschlag")
 *      dazu – öffentlich sichtbar erst nach redaktioneller Prüfung (verifiziert=Ja). Aussortierte
 *      Vorschläge bleiben mit verifiziert=Ausgeschlossen in der CSV, damit sie nicht wiederkommen.
 *   2. Status aktualisieren: offene Anträge (und solche, die in den letzten 60 Tagen erledigt
 *      wurden) werden über die RIS-Detailseite neu eingelesen, verteilt auf die Wochentage
 *      (id % 7) → jeder offene Antrag einmal pro Woche. Nur RIS-Spalten werden geschrieben,
 *      redaktionelle Spalten (Kurzfassung, Ergebnis, Antwort kurz …) nie überschrieben.
 *
 * Commit nur bei echten Änderungen, beides in EINEM Commit (→ ein Deploy-Lauf). Gibt es neue
 * Vorschläge oder frisch beantwortete Anträge, wird zusätzlich ein GitHub-Issue angelegt –
 * Kurzfassung/Ergebnis ergänzt dann eine Claude-Code-Session ("Antrags-Tracker aktualisieren").
 *
 * Parameter: dry=1 (nichts committen), all=1 (alle offenen statt Wochentags-Anteil),
 *            ids=123,456 (nur diese ris_ids), local=1 (CSV vom Server statt von GitHub lesen).
 * Lokal per CLI: php cron/update_antraege.php dry=1 local=1   (ohne Token/_cfg.php)
 *
 * Hinweis: Die RIS-WAF blockt IPv6 und Standard-User-Agents → IPv4 + Browser-User-Agent.
 */

$cli = PHP_SAPI === 'cli';
if ($cli) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

$dry   = !empty($_GET['dry']);
$local = !empty($_GET['local']);

if (!$cli) {
    require __DIR__ . '/_cfg.php';
    $token = $_GET['token'] ?? '';
    if (!$token || $token !== $cfg['cron']['token']) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
} elseif (!$dry) {
    require __DIR__ . '/_cfg.php';
}
if (!$dry || !$local) {
    require __DIR__ . '/github_api.php';
}

set_time_limit(600);
ignore_user_abort(true);

const RIS_BASE   = 'https://risi.muenchen.de/risi/';
const RIS_UA     = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const RIS_DELAY  = 2;  // Sekunden zwischen Abrufen
const CSV_PATH   = 'data/antraege.csv';
const VERL_PATH  = 'data/antraege_verlauf.csv';
const FIELDS = [
    'id', 'ris_id', 'vorgangsnr', 'ris_url',
    'typ', 'gremium', 'ba_nr', 'ba_name', 'wahlperiode',
    'antragsteller', 'fraktion_norm', 'initiative',
    'titel', 'themen', 'richtung', 'kurzfassung',
    'datum_gestellt', 'referat', 'ris_status', 'frist', 'fristverlaengert_bis', 'anzahl_fristverl', 'erledigt_am',
    'ergebnis', 'beschluss_datum', 'beschluss_gremium', 'antwort_url', 'antwort_kurz',
    'verifiziert', 'quelle_fund', 'zuletzt_geprueft',
];
// Spalten, die aus dem RIS befüllt werden – alle anderen sind redaktionell
const AUTO_FIELDS = [
    'vorgangsnr', 'ris_url', 'typ', 'gremium', 'ba_nr', 'ba_name', 'wahlperiode',
    'antragsteller', 'initiative', 'titel',
    'datum_gestellt', 'referat', 'ris_status', 'frist', 'fristverlaengert_bis', 'anzahl_fristverl', 'erledigt_am',
    'beschluss_datum', 'beschluss_gremium', 'antwort_url',
];
const VERL_FIELDS = ['antrag_id', 'datum', 'art', 'text', 'url'];
const PARTEIEN = [
    ['CSU', 'CSU'], ['FREIE WÄHLER', 'Freie Wähler'], ['Freie Wähler', 'Freie Wähler'],
    ['Grüne', 'Grüne'], ['GRÜNE', 'Grüne'], ['Rosa Liste', 'Rosa Liste'], ['SPD', 'SPD'],
    ['Volt', 'Volt'], ['FDP', 'FDP'], ['BAYERNPARTEI', 'Bayernpartei'], ['Bayernpartei', 'Bayernpartei'],
    ['ÖDP', 'ÖDP'], ['LINKE', 'Linke'], ['Linke', 'Linke'], ['Die PARTEI', 'PARTEI'],
    ['AfD', 'AfD'], ['München-Liste', 'München Liste'], ['München Liste', 'München Liste'],
];

$log = [];
function logmsg(string $m): void
{
    global $log, $cli;
    $log[] = $m;
    if ($cli) {
        fwrite(STDERR, $m . "\n");
    }
}

// ── CSV (wie Python csv: nur bei Bedarf quoten, \n als Zeilenende) ───────────

function csv_parse(string $text): array
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $head = fgetcsv($fh, 0, ',', '"', '');
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($r === [null]) {
            continue;
        }
        $rows[] = array_combine($head, array_pad($r, count($head), ''));
    }
    fclose($fh);
    return $rows;
}

function csv_build(array $fields, array $rows): string
{
    $enc = function ($v) {
        $v = (string) $v;
        return preg_match('/[",\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
    };
    $out = implode(',', array_map($enc, $fields)) . "\n";
    foreach ($rows as $r) {
        $out .= implode(',', array_map(function ($f) use ($r, $enc) { return $enc($r[$f] ?? ''); }, $fields)) . "\n";
    }
    return $out;
}

// ── RIS-Abruf + Parser ────────────────────────────────────────────────────────

function ris_fetch(string $url): string
{
    for ($i = 0; $i < 3; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_USERAGENT      => RIS_UA,
            CURLOPT_HTTPHEADER     => ['Accept-Language: de-DE,de;q=0.9', 'Accept: text/html,application/xhtml+xml,application/rss+xml'],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $body) {
            return $body;
        }
        sleep(10 * ($i + 1));
    }
    throw new RuntimeException("RIS-Abruf fehlgeschlagen (HTTP {$code}): {$url}");
}

function iso(?string $d): string
{
    return preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', (string) $d, $m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : '';
}

function absurl(string $href): string
{
    if ($href === '') {
        return '';
    }
    if (preg_match('#^https?://#', $href)) {
        return $href;
    }
    // relativ zu .../risi/antrag/detail/0 auflösen
    $parts = explode('/', 'antrag/detail');
    foreach (explode('/', preg_replace('/;jsessionid=[^?]*/', '', $href)) as $seg) {
        if ($seg === '..') {
            array_pop($parts);
        } elseif ($seg !== '.' && $seg !== '') {
            $parts[] = $seg;
        }
    }
    return RIS_BASE . implode('/', $parts);
}

// Entspricht BeautifulSoup get_text(" ", strip=True)
function txt(?DOMNode $n): string
{
    if (!$n) {
        return '';
    }
    $parts = [];
    $walk = function (DOMNode $n) use (&$walk, &$parts) {
        foreach ($n->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE) {
                $t = trim($c->nodeValue);
                if ($t !== '') {
                    $parts[] = $t;
                }
            } elseif ($c->nodeType === XML_ELEMENT_NODE && !in_array($c->nodeName, ['script', 'style'], true)) {
                $walk($c);
            }
        }
    };
    $walk($n);
    return implode(' ', $parts);
}

function cls(string $c): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' {$c} ')";
}

function ris_parse(string $html, string $risId): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $out = ['ris_url' => RIS_BASE . "antrag/detail/{$risId}"];

    // Kopf: "StR-Antrag 20-26 / A 05827 (Aufgegriffen)"
    $h1 = txt($xp->query('//h1')->item(0));
    $typ = '';
    if (preg_match('/^(.+?)\s+(\d{2}-\d{2}\s*\/\s*[A-Z]\s*\d+)\s*(?:\((.+)\))?$/u', $h1, $m)) {
        $typ = trim($m[1]) === 'TOP mit Vorgang' ? 'BA-Antrag (TOP)' : trim($m[1]);
        $out['typ'] = $typ;
        $out['vorgangsnr'] = str_replace(['/ ', ' /'], '/', preg_replace('/\s+/', ' ', $m[2]));
        $out['ris_status'] = trim($m[3] ?? '');
    }
    $out['gremium'] = strpos($typ, 'StR') === 0 ? 'Stadtrat' : (strpos($typ, 'BV') === 0 ? 'Bürgerversammlung' : 'Bezirksausschuss');

    $betreff = $xp->query('//*[@id="sectionheader-betreff"]/ancestor::section[1]')->item(0);
    if ($betreff) {
        $t = preg_replace('/^Betreff\s*/u', '', txt($betreff));
        $t = preg_replace('/\s*Mehr anzeigen\s*Weniger anzeigen\s*$/u', '', $t);
        $out['titel'] = preg_replace('/\s+/u', ' ', $t);
    }

    $kv = [];
    foreach ($xp->query('//*[' . cls('keyvalue-row') . ']') as $row) {
        $k = $xp->query('.//*[' . cls('keyvalue-key') . ']', $row)->item(0);
        $v = $xp->query('.//*[' . cls('keyvalue-value') . ']', $row)->item(0);
        if ($k && $v) {
            $kv[trim(rtrim(txt($k), ': '))] = txt($v);
        }
    }
    $out['datum_gestellt'] = iso($kv['Gestellt am'] ?? $kv['Beschlossen am'] ?? $kv['Erfasst am'] ?? '');
    $out['wahlperiode']    = $kv['Wahlperiode'] ?? '';
    $out['antragsteller']  = $kv['Gestellt von'] ?? '';
    $out['initiative']     = $kv['Initiative'] ?? '';
    $out['referat']        = $kv['Zuständiges Referat'] ?? '';
    $out['frist']          = iso($kv['Bearbeitungs-Frist'] ?? '');
    $out['fristverlaengert_bis'] = iso($kv['Aktuelle Fristverl.'] ?? '');
    $bisher = $kv['Bisherige Fristverl.'] ?? '';
    $out['anzahl_fristverl'] = $bisher !== '' ? (string) preg_match_all('/\d{2}\.\d{2}\.\d{4}/', $bisher) : '0';
    $out['erledigt_am'] = iso($kv['Erledigt am'] ?? '');
    $ba = $kv['Bezirksausschuss'] ?? $kv['Stadtbezirk'] ?? '';
    if (preg_match('/^(\d+)\s*-\s*(.+)/u', $ba, $mb)) {
        $out['ba_nr']   = (string) (int) $mb[1];
        $out['ba_name'] = trim($mb[2]);
    }

    $verlauf = [];
    if ($out['datum_gestellt']) {
        $verlauf[] = ['datum' => $out['datum_gestellt'], 'art' => 'gestellt', 'text' => $typ ?: 'Antrag gestellt', 'url' => ''];
    }

    $sections = [];
    foreach ($xp->query('//section') as $sec) {
        $h = $xp->query('.//h2|.//h3', $sec)->item(0);
        if ($h) {
            $sections[trim($h->textContent)] = $sections[trim($h->textContent)] ?? $sec;
        }
    }

    // Dokumente: "... AS.pdf" = Antwortschreiben
    if (isset($sections['Dokumente'])) {
        foreach ($xp->query('.//a[contains(@href, "dokument/v/")]', $sections['Dokumente']) as $a) {
            $name = trim($a->textContent);
            if (preg_match('/\bAS\b|Antwort/u', $name)) {
                $out['antwort_url'] = absurl($a->getAttribute('href'));
                $verlauf[] = ['datum' => '', 'art' => 'antwort', 'text' => 'Antwortschreiben: ' . $name, 'url' => $out['antwort_url']];
            }
        }
    }

    // Ergebnisse: Sitzungen mit Gremium, Sitzungsvorlage, Beschluss-Dokument
    $beschluesse = [];
    if (isset($sections['Ergebnisse'])) {
        foreach ($xp->query('.//*[' . cls('list-group-item') . ']', $sections['Ergebnisse']) as $item) {
            $datumA   = $xp->query('.//a[contains(@href, "sitzung/detail/")]', $item)->item(0);
            $gremiumA = $xp->query('.//a[contains(@href, "gremium/detail/")]', $item)->item(0);
            $svA      = $xp->query('.//a[contains(@href, "sitzungsvorlage/detail/")]', $item)->item(0);
            $docA     = $xp->query('.//a[' . cls('downloadlink') . ']', $item)->item(0);
            $t = txt($item);
            $e = [
                'datum'   => $datumA ? iso(trim($datumA->textContent)) : '',
                'gremium' => txt($gremiumA),
                'sv'      => txt($svA),
                'url'     => ($docA ?: $svA) ? absurl(($docA ?: $svA)->getAttribute('href')) : '',
            ];
            $parts = [$e['gremium']];
            if ($e['sv'] !== '') {
                $parts[] = $e['sv'];
            }
            if (preg_match('/Beschluss:\s*(.+?)(?:\s+Entscheidung|\s+Zum Tagesordnungspunkt|$)/u', $t, $mbs)) {
                $parts[] = 'Beschluss: ' . trim($mbs[1]);
            }
            $verlauf[] = ['datum' => $e['datum'], 'art' => 'sitzung', 'text' => implode(' – ', array_filter($parts, 'strlen')), 'url' => $e['url']];
            $beschluesse[] = $e;
        }
    }
    // Bei Sammelvorlagen nur Stadtrats-/Ausschusssitzungen als "Beschluss" werten
    $haupt = array_values(array_filter($beschluesse, function ($b) { return strpos($b['gremium'], 'BA ') !== 0; })) ?: $beschluesse;
    if ($haupt) {
        $last = $haupt[0];
        foreach ($haupt as $b) {
            if ($b['datum'] > $last['datum']) {
                $last = $b;
            }
        }
        $out['beschluss_datum']   = $last['datum'];
        $out['beschluss_gremium'] = $last['gremium'];
        if (empty($out['antwort_url']) && $last['url']) {
            $out['antwort_url'] = $last['url'];
        }
    }

    if (isset($sections['Historie'])) {
        $items = iterator_to_array($xp->query('.//*[' . cls('list-group-item') . ']', $sections['Historie']));
        foreach (array_reverse($items) as $li) {  // RIS listet neueste zuerst
            if (preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+(.*)$/u', txt($li), $mh)) {
                $verlauf[] = ['datum' => iso($mh[1]), 'art' => 'status', 'text' => $mh[2], 'url' => ''];
            }
        }
    }

    // Antwortschreiben-Datum = Datum des Status "Antwortschreiben"/"Erledigt" aus der Historie
    foreach ($verlauf as &$v) {
        if ($v['art'] === 'antwort' && !$v['datum']) {
            $cand = [];
            foreach ($verlauf as $h) {
                if ($h['art'] === 'status' && preg_match('/Status: (Antwortschreiben|Erledigt)/u', $h['text'])) {
                    $cand[] = $h['datum'];
                }
            }
            $v['datum'] = $cand ? min($cand) : ($out['erledigt_am'] ?? '');
        }
    }
    unset($v);

    // Doppelte Einträge raus, Sammelvorlage in vielen BAs zusammenfassen
    $seen = [];
    $dedup = [];
    foreach ($verlauf as $v) {
        $key = $v['datum'] . "\0" . $v['art'] . "\0" . $v['text'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $dedup[] = $v;
        }
    }
    $sammel = array_values(array_filter($dedup, function ($v) { return $v['art'] === 'sitzung' && strpos($v['text'], 'BA ') === 0; }));
    if (count($sammel) > 3) {
        $dedup = array_values(array_filter($dedup, function ($v) use ($sammel) { return !in_array($v, $sammel, true); }));
        $first = explode(' – ', $sammel[0]['text'], 2);
        $dedup[] = [
            'datum' => min(array_column($sammel, 'datum')), 'art' => 'sitzung',
            'text'  => 'Behandlung in ' . count($sammel) . ' Bezirksausschüssen (' . end($first) . ')',
            'url'   => $sammel[0]['url'],
        ];
    }
    $rank = ['gestellt' => 0, 'status' => 1, 'sitzung' => 2, 'antwort' => 3];
    foreach ($dedup as $i => &$v) {
        $v['_i'] = $i;
    }
    unset($v);
    usort($dedup, function ($a, $b) use ($rank) {
        return [$a['datum'], $rank[$a['art']], $a['_i']] <=> [$b['datum'], $rank[$b['art']], $b['_i']];
    });
    foreach ($dedup as &$v) {
        unset($v['_i']);
    }
    unset($v);
    return [$out, $dedup];
}

function parteien(string $text): string
{
    $found = [];
    foreach (PARTEIEN as [$key, $tag]) {
        if (strpos($text, $key) !== false && !in_array($tag, $found, true)) {
            $found[] = $tag;
        }
    }
    return implode('|', $found);
}

// ── RSS: neue Kandidaten ──────────────────────────────────────────────────────

function rss_items(string $feed): array
{
    $xml = simplexml_load_string(ris_fetch(RIS_BASE . 'rss?feed=' . urlencode($feed)));
    $items = [];
    foreach ($xml->channel->item ?? [] as $it) {
        if (!preg_match('#detail/(\d+)#', (string) $it->link, $m)) {
            continue;
        }
        $desc = html_entity_decode(html_entity_decode((string) $it->description, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $betreff = preg_match('#Betreff:</strong>\s*<br\s*/?>(.*?)<#su', $desc, $mb) ? $mb[1] : strip_tags($desc);
        $items[] = ['ris_id' => $m[1], 'titel' => trim(preg_replace('/\s+/u', ' ', $betreff)), 'kopf' => trim((string) $it->title)];
    }
    return $items;
}

function themen_match(string $titel, array $cfg): array
{
    $low = mb_strtolower($titel, 'UTF-8');
    $rx = function ($p) { return '/' . str_replace('/', '\/', $p) . '/u'; };
    if (preg_match($rx($cfg['ausschluss']), $low) && !preg_match($rx($cfg['ausschluss_ausnahme']), $low)) {
        return [];
    }
    $themen = [];
    foreach ($cfg['themen'] as $thema => $p) {
        if (preg_match($rx($p), $low)) {
            $themen[] = $thema;
        }
    }
    return $themen;
}

// ── Ablauf ────────────────────────────────────────────────────────────────────

try {
    $root = dirname(__DIR__);
    if ($local) {
        $csvText  = file_get_contents("{$root}/" . CSV_PATH);
        $verlText = file_get_contents("{$root}/" . VERL_PATH);
    } else {
        $csvText  = github_get_file(CSV_PATH, $cfg['github']['token'], $cfg['github']['repo']);
        $verlText = github_get_file(VERL_PATH, $cfg['github']['token'], $cfg['github']['repo']);
    }
    if (!$csvText || !$verlText) {
        throw new RuntimeException('antraege.csv / antraege_verlauf.csv nicht lesbar');
    }
    $rows    = csv_parse($csvText);
    $verlauf = csv_parse($verlText);
    $stich   = json_decode(file_get_contents("{$root}/data/antraege-stichworte.json"), true);
    $heute   = date('Y-m-d');

    $byRis = [];
    $maxId = 0;
    foreach ($rows as $i => $r) {
        $byRis[$r['ris_id']] = $i;
        $maxId = max($maxId, (int) $r['id']);
    }

    // 1. Neue Kandidaten aus den RSS-Feeds
    $neu = [];
    foreach ($stich['feeds'] as $feed) {
        try {
            $items = rss_items($feed);
        } catch (Exception $e) {
            logmsg("RSS {$feed}: " . $e->getMessage());
            continue;
        }
        logmsg("RSS {$feed}: " . count($items) . ' Einträge');
        foreach ($items as $it) {
            if (isset($byRis[$it['ris_id']]) || isset($neu[$it['ris_id']])) {
                continue;
            }
            $themen = themen_match($it['titel'], $stich);
            if ($themen) {
                $neu[$it['ris_id']] = $it + ['themen' => implode('|', $themen), 'feed' => $feed];
            }
        }
        sleep(1);
    }
    foreach ($neu as $rid => $it) {
        $r = array_fill_keys(FIELDS, '');
        $r['id'] = (string) ++$maxId;
        $r['ris_id'] = (string) $rid;
        $r['titel'] = $it['titel'];
        $r['themen'] = $it['themen'];
        $r['verifiziert'] = 'Nein';
        $r['quelle_fund'] = "RIS-RSS {$it['feed']} (Stichwortsuche)";
        $rows[] = $r;
        $byRis[(string) $rid] = count($rows) - 1;
        logmsg("Neu: [{$r['id']}] {$it['kopf']} – {$it['titel']}");
    }

    // 2. Welche Anträge heute im RIS nachschlagen?
    $onlyIds = array_filter(explode(',', $_GET['ids'] ?? ''));
    $grenze  = date('Y-m-d', strtotime('-60 days'));
    $wtag    = (int) date('N') % 7;
    $todo    = [];
    foreach ($rows as $i => $r) {
        if (!$r['ris_id'] || $r['verifiziert'] === 'Ausgeschlossen') {
            continue;
        }
        $isNeu = isset($neu[$r['ris_id']]);
        if ($onlyIds) {
            if ($isNeu || in_array($r['ris_id'], $onlyIds, true)) {
                $todo[] = $i;
            }
            continue;
        }
        $aktiv = $r['ris_status'] !== 'Erledigt' || ($r['erledigt_am'] && $r['erledigt_am'] >= $grenze);
        if ($isNeu || ($aktiv && (!empty($_GET['all']) || (int) $r['id'] % 7 === $wtag))) {
            $todo[] = $i;
        }
    }
    logmsg('RIS-Detailabruf: ' . count($todo) . ' Anträge');

    $geaendert = [];
    $beantwortet = [];
    $verlNeu = [];
    foreach ($todo as $n => $i) {
        $r = &$rows[$i];
        if ($n) {
            sleep(RIS_DELAY);
        }
        try {
            [$data, $vl] = ris_parse(ris_fetch(RIS_BASE . "antrag/detail/{$r['ris_id']}"), $r['ris_id']);
        } catch (Exception $e) {
            logmsg("[{$r['id']}] übersprungen: " . $e->getMessage());
            unset($r);
            continue;
        }
        $changes = [];
        $warOffen = $r['erledigt_am'] === '' && $r['antwort_url'] === '';
        foreach (AUTO_FIELDS as $k) {
            $v = $data[$k] ?? '';
            if ($v !== '' && $v !== $r[$k]) {
                $changes[] = $k;
                $r[$k] = $v;
            }
        }
        if ($r['fraktion_norm'] === '') {
            $r['fraktion_norm'] = $r['gremium'] === 'Bürgerversammlung' ? 'Bürgerversammlung' : (parteien($r['antragsteller']) ?: parteien($r['titel']));
        }
        if ($r['ergebnis'] === '' || ($r['ergebnis'] === 'Antwort offen' && $r['erledigt_am'] !== '' && $r['antwort_kurz'] === '')) {
            $r['ergebnis'] = $r['erledigt_am'] !== '' ? 'beantwortet' : 'Antwort offen';
        }
        $alt = array_values(array_map(function ($v) { unset($v['antrag_id']); return $v; },
            array_filter($verlauf, function ($v) use ($r) { return $v['antrag_id'] === $r['id']; })));
        if ($vl != $alt) {
            $changes[] = 'verlauf';
        }
        $verlNeu[$r['id']] = $vl;
        if ($changes) {
            $r['zuletzt_geprueft'] = $heute;
            $geaendert[$r['id']] = $changes;
            if (!isset($neu[$r['ris_id']])) {
                logmsg("[{$r['id']}] geändert: " . implode(', ', $changes));
            }
            if ($warOffen && !isset($neu[$r['ris_id']]) && ($r['erledigt_am'] !== '' || $r['antwort_url'] !== '') && $r['verifiziert'] === 'Ja') {
                $beantwortet[] = $r;
            }
        }
        unset($r);
    }

    if (!$neu && !$geaendert) {
        logmsg('Keine Änderungen.');
        echo json_encode(['ok' => true, 'neu' => 0, 'geaendert' => 0, 'log' => $log], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Verlauf neu zusammensetzen (Reihenfolge je Antrag bleibt, sortiert nach antrag_id)
    $alleVerl = array_values(array_filter($verlauf, function ($v) use ($verlNeu) { return !isset($verlNeu[$v['antrag_id']]); }));
    foreach ($verlNeu as $aid => $vs) {
        foreach ($vs as $v) {
            $alleVerl[] = ['antrag_id' => (string) $aid] + $v;
        }
    }
    foreach ($alleVerl as $i => &$v) {
        $v['_i'] = $i;
    }
    unset($v);
    usort($alleVerl, function ($a, $b) { return [(int) $a['antrag_id'], $a['_i']] <=> [(int) $b['antrag_id'], $b['_i']]; });

    $files = [
        CSV_PATH  => csv_build(FIELDS, $rows),
        VERL_PATH => csv_build(VERL_FIELDS, $alleVerl),
    ];

    $msg = 'data: Anträge aktualisiert (' . count($neu) . ' neu, ' . (count($geaendert) - count($neu)) . ' geändert)';
    if ($dry) {
        if ($local && $cli) {
            file_put_contents(sys_get_temp_dir() . '/antraege.csv', $files[CSV_PATH]);
            file_put_contents(sys_get_temp_dir() . '/antraege_verlauf.csv', $files[VERL_PATH]);
            logmsg('Dry-run: Ergebnis in ' . sys_get_temp_dir() . '/antraege*.csv');
        }
    } else {
        github_commit_multiple($files, $msg, $cfg['github']['token'], $cfg['github']['repo']);
        logmsg('Commit: ' . $msg);

        if ($neu || $beantwortet) {
            $body = "Automatisch angelegt von `cron/update_antraege.php` am {$heute}.\n\n";
            if ($neu) {
                $body .= "## Neue Vorschläge (verifiziert=Nein, noch nicht öffentlich)\n\n";
                foreach ($neu as $rid => $it) {
                    $id = $rows[$byRis[(string) $rid]]['id'];
                    $body .= "- [ ] #{$id} [{$it['kopf']}](" . RIS_BASE . "antrag/detail/{$rid}) – {$it['titel']} _({$it['themen']})_\n";
                }
                $body .= "\nPrüfen und in `data/antraege.csv` `verifiziert` auf `Ja` (übernehmen) oder `Ausgeschlossen` (kommt nicht wieder) setzen.\n\n";
            }
            if ($beantwortet) {
                $body .= "## Neu beantwortet / erledigt\n\n";
                foreach ($beantwortet as $r) {
                    $body .= "- [ ] #{$r['id']} [{$r['vorgangsnr']}]({$r['ris_url']}) – {$r['titel']}\n";
                }
                $body .= "\n`antwort_kurz` und `ergebnis` ergänzen.\n\n";
            }
            $body .= "Tipp: In einer Claude-Code-Session „Antrags-Tracker aktualisieren“ sagen – dann werden Kurzfassung, Antwort und Ergebnis aus den PDFs ergänzt.";
            $titel = 'Antrags-Tracker: ' . implode(', ', array_filter([
                $neu ? count($neu) . ' neue Vorschläge' : '',
                $beantwortet ? count($beantwortet) . ' neu beantwortet' : '',
            ])) . " ({$heute})";
            $issue = github_create_issue($titel, $body, $cfg['github']['token'], $cfg['github']['repo']);
            logmsg('Issue: ' . ($issue['html_url'] ?? '?'));
        }
    }

    echo json_encode(['ok' => true, 'neu' => count($neu), 'geaendert' => count($geaendert), 'beantwortet' => count($beantwortet), 'log' => $log],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'log' => $log], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
