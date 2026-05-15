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

try {
    // 1. Daten scrapen
    $data = scrape_parkhaeuser();

    // 2. SN 106525 zusammenfassen
    $data = merge_sn_106525($data);

    // 3. Details-CSV anfügen
    $detailRows = '';
    foreach ($data as $r) {
        $aktiv = $r['aktiv'] ? 1 : 0;
        $name  = str_replace(',', ' ', $r['parkhaus']); // Komma-Schutz
        $detailRows .= "{$now},{$r['sn']},{$name},{$r['frei']},{$r['kap']},{$aktiv}\n";
    }
    github_append_csv(
        "data/details/{$yearMonth}.csv",
        "timestamp,sn,parkhaus,frei,kap,aktiv\n",
        $detailRows,
        "data: details {$now}",
        $githubToken,
        $githubRepo
    );

    // 4. Summary-CSV anfügen
    $active   = array_filter($data, fn($r) => $r['aktiv']);
    $kapGes   = array_sum(array_column(array_values($active), 'kap'));
    $freiGes  = array_sum(array_column(array_values($active), 'frei'));
    $belegtGs = $kapGes - $freiGes;
    $summaryRow = "{$now},{$freiGes},{$belegtGs},{$kapGes}\n";
    github_append_csv(
        "data/summary/{$yearMonth}.csv",
        "timestamp,frei_gesamt,belegt_gesamt,kap_gesamt\n",
        $summaryRow,
        "data: summary {$now}",
        $githubToken,
        $githubRepo
    );

    // 5. latest.json aktualisieren
    $latest = [
        'timestamp'     => $now,
        'frei_gesamt'   => $freiGes,
        'belegt_gesamt' => $belegtGs,
        'kap_gesamt'    => $kapGes,
        'parkhaeuser'   => $data,
    ];
    github_put_file(
        'data/latest.json',
        json_encode($latest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        "data: latest {$now}",
        github_get_sha('data/latest.json', $githubToken, $githubRepo),
        $githubToken,
        $githubRepo
    );

    http_response_code(200);
    echo json_encode(['ok' => true, 'timestamp' => $now, 'parkhaeuser' => count($data)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
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

function github_get_sha(string $path, string $token, string $repo): ?string
{
    $url = "https://api.github.com/repos/{$repo}/contents/{$path}";
    $res = github_request('GET', $url, github_headers($token));
    if ($res['code'] !== 200) return null;
    $data = json_decode($res['body'], true);
    return $data['sha'] ?? null;
}

function github_put_file(string $path, string $content, string $message, ?string $sha, string $token, string $repo): void
{
    $url  = "https://api.github.com/repos/{$repo}/contents/{$path}";
    $body = ['message' => $message, 'content' => base64_encode($content)];
    if ($sha) $body['sha'] = $sha;

    $res = github_request('PUT', $url, github_headers($token), json_encode($body));
    if (!in_array($res['code'], [200, 201])) {
        throw new RuntimeException("GitHub PUT {$path} fehlgeschlagen (HTTP {$res['code']}): {$res['body']}");
    }
}

function github_append_csv(string $path, string $headers, string $newRows, string $message, string $token, string $repo): void
{
    $url = "https://api.github.com/repos/{$repo}/contents/{$path}";
    $res = github_request('GET', $url, github_headers($token));

    if ($res['code'] === 404) {
        $content = $headers . $newRows;
        $sha     = null;
    } elseif ($res['code'] === 200) {
        $data    = json_decode($res['body'], true);
        $sha     = $data['sha'];
        $content = rtrim(base64_decode($data['content'])) . "\n" . $newRows;
    } else {
        throw new RuntimeException("GitHub GET {$path} fehlgeschlagen (HTTP {$res['code']})");
    }

    github_put_file($path, $content, $message, $sha, $token, $repo);
}
