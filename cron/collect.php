<?php
/**
 * Parkhaus-Daten Collector
 *
 * Cronjob-URL: https://data.parkraumwende.de/cron/collect.php?token=DEIN_CRON_TOKEN
 * Intervall: alle 2 Stunden (KAS-Cronjob)
 *
 * Was dieses Script macht:
 *   1. HTML-Tabelle von pls-muc-z.com scrapen
 *   2. SN 106525 (zwei Einträge) zu einem Parkhaus zusammenfassen
 *   3. details/YYYY-MM.csv auf GitHub anfügen
 *   4. summary/YYYY-MM.csv auf GitHub anfügen
 *   5. latest.json auf GitHub aktualisieren
 */

require __DIR__ . '/_cfg.php';

// Token-Check
$token = $_GET['token'] ?? '';
if (!$token || $token !== $cfg['cron']['token']) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$githubToken = $cfg['github']['token'];
$githubRepo  = $cfg['github']['repo'];
$now         = date('Y-m-d H:i:s');
$yearMonth   = date('Y-m');
$debug       = isset($_GET['debug']);

try {
    // 1. Daten scrapen
    $raw  = scrape_parkhaeuser();

    // 2. SN 106525 zusammenfassen
    $data = merge_sn_106525($raw);

    // Debug-Modus: Tabelle ausgeben, nichts speichern
    if ($debug) {
        render_debug_table($raw, $data, $now);
        exit;
    }

    // 3. Details-CSV: neuen Inhalt (bestehend + Anhang) zusammenbauen
    $detailRows = '';
    foreach ($data as $r) {
        $aktiv = $r['aktiv'] ? 1 : 0;
        $name  = str_replace(',', ' ', $r['parkhaus']); // Komma-Schutz
        $detailRows .= "{$now},{$r['sn']},{$name},{$r['frei']},{$r['kap']},{$aktiv}\n";
    }
    $detailsContent = github_build_append_content(
        "data/details/{$yearMonth}.csv",
        "timestamp,sn,parkhaus,frei,kap,aktiv\n",
        $detailRows,
        $githubToken,
        $githubRepo
    );

    // 4. Summary-CSV: neuen Inhalt zusammenbauen
    $active   = array_filter($data, fn($r) => $r['aktiv']);
    $kapGes   = array_sum(array_column(array_values($active), 'kap'));
    $freiGes  = array_sum(array_column(array_values($active), 'frei'));
    $belegtGs = $kapGes - $freiGes;
    $summaryRow = "{$now},{$freiGes},{$belegtGs},{$kapGes}\n";
    $summaryContent = github_build_append_content(
        "data/summary/{$yearMonth}.csv",
        "timestamp,frei_gesamt,belegt_gesamt,kap_gesamt\n",
        $summaryRow,
        $githubToken,
        $githubRepo
    );

    // 5. latest.json
    $latest = [
        'timestamp'     => $now,
        'frei_gesamt'   => $freiGes,
        'belegt_gesamt' => $belegtGs,
        'kap_gesamt'    => $kapGes,
        'parkhaeuser'   => $data,
    ];
    $latestJson = json_encode($latest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // Direkt auf Festplatte schreiben – sofort sichtbar ohne Deploy-Umweg
    file_put_contents(__DIR__ . '/../data/latest.json', $latestJson);

    // 6. Alle drei Dateien in EINEM Commit auf GitHub schreiben (Git-Data-API),
    //    damit nur EIN Push und damit EIN Deploy-Workflow-Lauf ausgelöst wird
    //    (statt bisher drei fast zeitgleiche, die sich beim rsync-Deploy ins Gehege kamen).
    github_commit_multiple(
        [
            "data/details/{$yearMonth}.csv" => $detailsContent,
            "data/summary/{$yearMonth}.csv" => $summaryContent,
            'data/latest.json'              => $latestJson,
        ],
        "data: update {$now}",
        $githubToken,
        $githubRepo
    );

    http_response_code(200);
    echo json_encode(['ok' => true, 'timestamp' => $now, 'parkhaeuser' => count($data)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function render_debug_table(array $raw, array $merged, string $now): void
{
    $pct = fn($frei, $kap) => $kap > 0 ? max(0, min(100, round((1 - $frei / $kap) * 100))) : 0;
    $bar = fn($p) => str_repeat('█', (int)($p / 5)) . str_repeat('░', 20 - (int)($p / 5));
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Debug – Parkhaus-Scraping</title>
<style>
  body { font-family: monospace; background: #f5f5f0; padding: 24px; }
  h2 { margin: 0 0 4px; font-size: 1rem; }
  p  { color: #666; font-size: 0.8rem; margin: 0 0 16px; }
  table { border-collapse: collapse; width: 100%; margin-bottom: 32px; }
  th { background: #1a3c2e; color: #fff; padding: 6px 10px; text-align: left; font-size: 0.8rem; }
  td { padding: 5px 10px; font-size: 0.85rem; border-bottom: 1px solid #ddd; }
  tr:hover td { background: #eee; }
  .inaktiv td { color: #aaa; }
  .bar { color: #c0392b; letter-spacing: -1px; }
  .num { text-align: right; }
</style>
</head>
<body>
<h2>Rohdaten von pls-muc-z.com (vor Merge)</h2>
<p>Abgerufen: <?= htmlspecialchars($now) ?> &nbsp;·&nbsp; <?= count($raw) ?> Einträge</p>
<table>
  <tr><th>SN</th><th>PH</th><th>Parkhaus</th><th class="num">Frei</th><th class="num">Kap</th><th class="num">Auslastung</th><th>Balken</th><th>Aktiv</th></tr>
  <?php foreach ($raw as $r):
    $p = $pct($r['frei'], $r['kap']);
  ?>
  <tr class="<?= $r['aktiv'] ? '' : 'inaktiv' ?>">
    <td><?= htmlspecialchars($r['sn']) ?></td>
    <td><?= htmlspecialchars($r['ph']) ?></td>
    <td><?= htmlspecialchars($r['parkhaus']) ?></td>
    <td class="num"><?= $r['frei'] ?></td>
    <td class="num"><?= $r['kap'] ?></td>
    <td class="num"><?= $p ?> %</td>
    <td class="bar"><?= $bar($p) ?></td>
    <td><?= $r['aktiv'] ? '✓' : '–' ?></td>
  </tr>
  <?php endforeach ?>
</table>

<h2>Nach Merge (SN 106525 zusammengefasst)</h2>
<p><?= count($merged) ?> Einträge &nbsp;·&nbsp; so werden sie gespeichert</p>
<table>
  <tr><th>SN</th><th>PH</th><th>Parkhaus</th><th class="num">Frei</th><th class="num">Kap</th><th class="num">Auslastung</th><th>Balken</th><th>Aktiv</th></tr>
  <?php foreach ($merged as $r):
    $p = $pct($r['frei'], $r['kap']);
  ?>
  <tr class="<?= $r['aktiv'] ? '' : 'inaktiv' ?>">
    <td><?= htmlspecialchars($r['sn']) ?></td>
    <td><?= htmlspecialchars($r['ph']) ?></td>
    <td><?= htmlspecialchars($r['parkhaus']) ?></td>
    <td class="num"><?= $r['frei'] ?></td>
    <td class="num"><?= $r['kap'] ?></td>
    <td class="num"><?= $p ?> %</td>
    <td class="bar"><?= $bar($p) ?></td>
    <td><?= $r['aktiv'] ? '✓' : '–' ?></td>
  </tr>
  <?php endforeach ?>
</table>
</body>
</html><?php
}

// ─── Scraping ─────────────────────────────────────────────────────────────────

function scrape_parkhaeuser(): array
{
    $ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $html = file_get_contents('https://pls-muc-z.com/pls/info/parkhaus.html', false, $ctx);
    if (!$html) {
        throw new RuntimeException('PLS-Seite nicht erreichbar');
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $tables = $dom->getElementsByTagName('table');
    if (!$tables->length) {
        throw new RuntimeException('Keine Tabelle in PLS-HTML gefunden');
    }

    $rows = $tables->item(0)->getElementsByTagName('tr');
    $data = [];
    foreach ($rows as $i => $tr) {
        if ($i === 0) continue;
        $tds = $tr->getElementsByTagName('td');
        if ($tds->length < 6) continue;
        $data[] = [
            'sn'       => trim($tds->item(0)->textContent),
            'ph'       => trim($tds->item(2)->textContent),
            'parkhaus' => trim($tds->item(3)->textContent),
            'frei'     => (int) trim($tds->item(4)->textContent),
            'kap'      => (int) trim($tds->item(5)->textContent),
            'aktiv'    => $tr->getAttribute('bgcolor') === '' ? true : false,
        ];
    }

    if (empty($data)) {
        throw new RuntimeException('Keine Daten aus PLS-Tabelle extrahiert');
    }

    return $data;
}

function merge_sn_106525(array $data): array
{
    $merged   = [];
    $hbf_rows = [];

    foreach ($data as $row) {
        if ($row['sn'] === '106525') {
            $hbf_rows[] = $row;
        } else {
            $merged[] = $row;
        }
    }

    if (!empty($hbf_rows)) {
        $merged[] = [
            'sn'       => '106525',
            'ph'       => 'P11/P25',
            'parkhaus' => 'Parkhaus am Hauptbahnhof',
            'frei'     => array_sum(array_column($hbf_rows, 'frei')),
            'kap'      => array_sum(array_column($hbf_rows, 'kap')),
            'aktiv'    => (bool) max(array_map(fn($r) => (int)$r['aktiv'], $hbf_rows)),
        ];
    }

    return $merged;
}

// ─── GitHub API ───────────────────────────────────────────────────────────────

function github_request(string $method, string $url, array $headers, ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $response];
}

function github_headers(string $token): array
{
    return [
        'Authorization: token ' . $token,
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
        'User-Agent: parkraumwende-cron',
    ];
}

// Liefert den kompletten neuen Dateiinhalt (bestehender Inhalt + Anhang), OHNE zu schreiben.
// Wird zusammen mit anderen Dateien über github_commit_multiple() in einem Commit gespeichert.
function github_build_append_content(string $path, string $headers, string $newRows, string $token, string $repo): string
{
    $url = "https://api.github.com/repos/{$repo}/contents/{$path}";
    $res = github_request('GET', $url, github_headers($token));

    if ($res['code'] === 404) {
        return $headers . $newRows;
    }
    if ($res['code'] === 200) {
        $data = json_decode($res['body'], true);
        return rtrim(base64_decode($data['content'])) . "\n" . $newRows;
    }
    throw new RuntimeException("GitHub GET {$path} fehlgeschlagen (HTTP {$res['code']})");
}

// Schreibt mehrere Dateien in EINEM Commit (Git-Data-API: Blob → Tree → Commit → Ref-Update),
// damit nur EIN Push-Event und damit EIN Deploy-Workflow-Lauf ausgelöst wird.
// $files: ['pfad/datei.csv' => 'kompletter neuer inhalt', ...]
function github_commit_multiple(array $files, string $message, string $token, string $repo, string $branch = 'main'): void
{
    $headers = github_headers($token);

    // 1. Aktuellen Branch-HEAD holen
    $refRes = github_request('GET', "https://api.github.com/repos/{$repo}/git/refs/heads/{$branch}", $headers);
    if ($refRes['code'] !== 200) {
        throw new RuntimeException("GitHub GET ref fehlgeschlagen (HTTP {$refRes['code']}): {$refRes['body']}");
    }
    $latestCommitSha = json_decode($refRes['body'], true)['object']['sha'];

    // 2. Basis-Tree des aktuellen Commits holen
    $commitRes = github_request('GET', "https://api.github.com/repos/{$repo}/git/commits/{$latestCommitSha}", $headers);
    if ($commitRes['code'] !== 200) {
        throw new RuntimeException("GitHub GET commit fehlgeschlagen (HTTP {$commitRes['code']}): {$commitRes['body']}");
    }
    $baseTreeSha = json_decode($commitRes['body'], true)['tree']['sha'];

    // 3. Für jede Datei einen Blob anlegen
    $treeEntries = [];
    foreach ($files as $path => $content) {
        $blobRes = github_request('POST', "https://api.github.com/repos/{$repo}/git/blobs", $headers, json_encode([
            'content'  => base64_encode($content),
            'encoding' => 'base64',
        ]));
        if ($blobRes['code'] !== 201) {
            throw new RuntimeException("GitHub POST blob {$path} fehlgeschlagen (HTTP {$blobRes['code']}): {$blobRes['body']}");
        }
        $treeEntries[] = [
            'path' => $path,
            'mode' => '100644',
            'type' => 'blob',
            'sha'  => json_decode($blobRes['body'], true)['sha'],
        ];
    }

    // 4. Neuen Tree auf Basis des alten anlegen (nur die geänderten Pfade überschreiben)
    $treeRes = github_request('POST', "https://api.github.com/repos/{$repo}/git/trees", $headers, json_encode([
        'base_tree' => $baseTreeSha,
        'tree'      => $treeEntries,
    ]));
    if ($treeRes['code'] !== 201) {
        throw new RuntimeException("GitHub POST tree fehlgeschlagen (HTTP {$treeRes['code']}): {$treeRes['body']}");
    }
    $newTreeSha = json_decode($treeRes['body'], true)['sha'];

    // 5. Neuen Commit anlegen
    $newCommitRes = github_request('POST', "https://api.github.com/repos/{$repo}/git/commits", $headers, json_encode([
        'message' => $message,
        'tree'    => $newTreeSha,
        'parents' => [$latestCommitSha],
    ]));
    if ($newCommitRes['code'] !== 201) {
        throw new RuntimeException("GitHub POST commit fehlgeschlagen (HTTP {$newCommitRes['code']}): {$newCommitRes['body']}");
    }
    $newCommitSha = json_decode($newCommitRes['body'], true)['sha'];

    // 6. Branch-Ref auf den neuen Commit zeigen lassen → löst genau EINEN Push aus
    $updateRes = github_request('PATCH', "https://api.github.com/repos/{$repo}/git/refs/heads/{$branch}", $headers, json_encode([
        'sha' => $newCommitSha,
    ]));
    if ($updateRes['code'] !== 200) {
        throw new RuntimeException("GitHub PATCH ref fehlgeschlagen (HTTP {$updateRes['code']}): {$updateRes['body']}");
    }
}
