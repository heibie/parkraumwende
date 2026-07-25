<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/cron/github_api.php';

$cfgFile = __DIR__ . '/cron/_cfg.php';
$cfg = [];
if (file_exists($cfgFile)) {
    require $cfgFile;
}

// Lokale Dev-Umgebung ohne _cfg.php / GitHub-Token: direkt auf die lokale CSV schreiben,
// damit das Formular ohne GitHub-Zugriff testbar ist.
$githubToken = $cfg['github']['token'] ?? '';
$githubRepo  = $cfg['github']['repo']  ?? '';
$isLocal     = $githubToken === '';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Eingabe']);
    exit;
}

$name = trim($input['name'] ?? '');
$lat  = trim($input['lat']  ?? '');
$lon  = trim($input['lon']  ?? '');

if (!$name || !$lat || !$lon) {
    http_response_code(400);
    echo json_encode(['error' => 'Name und Koordinaten sind Pflichtfelder']);
    exit;
}

// Felder bereinigen
$kontakt_name   = trim($input['kontakt_name']   ?? '');
$email          = trim($input['email']          ?? '');
$typ            = trim($input['typ']            ?? '');
$bew            = trim($input['bew']            ?? '');
$strasse        = trim($input['strasse']        ?? '');
$hausnr         = trim($input['hausnr']         ?? '');
$plz            = trim($input['plz']            ?? '');
$ort            = trim($input['ort']            ?? 'München');
$plaetze        = trim($input['plaetze']        ?? '');
$freie_plaetze  = trim($input['freie_plaetze']  ?? '');
$immotyp        = trim($input['immotyp']        ?? '');
$dauerparken    = trim($input['dauerparken']    ?? '');
$kurzzeitparken = trim($input['kurzzeitparken'] ?? '');
$preis_monat    = trim($input['preis_monat']    ?? '');
$preis_tag      = trim($input['preis_tag']      ?? '');
$preis_stunde   = trim($input['preis_stunde']   ?? '');
$oeffnung       = trim($input['oeffnung']       ?? '');
$hoehe          = trim($input['hoehe']          ?? '');
$url            = trim($input['url']            ?? '');
$bemerkung      = trim($input['bemerkung']      ?? '');
$correction_of  = trim($input['correction_of'] ?? '');
$changes        = $input['changes'] ?? [];

$isCorrection = $correction_of !== '';
$adresse = trim("$strasse $hausnr");
$adresseVoll = trim("$adresse, $plz $ort", ', ');

// ─── Korrekturvorschlag: unverändert als GitHub-Issue ──────────────────────────
if ($isCorrection) {
    if ($isLocal) {
        http_response_code(500);
        echo json_encode(['error' => 'Korrekturvorschläge brauchen einen GitHub-Token (cron/_cfg.php) und lassen sich lokal nicht testen.']);
        exit;
    }

    $body = "## Korrekturvorschlag\n\n> Bezieht sich auf Eintrag: **" . $correction_of . "**\n\n";

    if (!empty($changes)) {
        $body .= "### Geänderte Felder\n\n";
        $body .= "| Feld | Vorher | Nachher |\n|---|---|---|\n";
        foreach ($changes as $c) {
            $field = htmlspecialchars($c['field'] ?? '');
            $alt   = htmlspecialchars($c['alt']   ?? '–');
            $neu   = htmlspecialchars($c['neu']   ?? '–');
            $body .= "| **{$field}** | ~~{$alt}~~ | **{$neu}** |\n";
        }
        $body .= "\n### Alle Felder\n\n";
    } else {
        $body .= "> Keine Felder geändert — nur Bemerkung beachten.\n\n";
    }

    $body .= "| Feld | Wert |\n|---|---|\n";
    $body .= "| **Name** | " . $name . " |\n";
    $body .= "| **Typ** | " . $typ . " |\n";
    if ($bew)            $body .= "| **Bewirtschaftung** | " . $bew . " |\n";
    $body .= "| **Adresse** | " . $adresseVoll . " |\n";
    $body .= "| **Koordinaten** | " . $lat . ", " . $lon . " |\n";
    if ($plaetze)        $body .= "| **Stellplätze** | " . $plaetze . " |\n";
    if ($freie_plaetze)  $body .= "| **Davon frei** | " . $freie_plaetze . " |\n";
    if ($immotyp)        $body .= "| **Immobilientyp** | " . $immotyp . " |\n";
    if ($dauerparken)    $body .= "| **Dauerparken** | " . $dauerparken . " |\n";
    if ($kurzzeitparken) $body .= "| **Kurzzeitparken** | " . $kurzzeitparken . " |\n";
    if ($preis_monat)    $body .= "| **Preis/Monat** | " . $preis_monat . " € |\n";
    if ($preis_tag)      $body .= "| **Preis/Tag** | " . $preis_tag . " € |\n";
    if ($preis_stunde)   $body .= "| **Preis/Stunde** | " . $preis_stunde . " € |\n";
    if ($oeffnung)       $body .= "| **Öffnungszeiten** | " . $oeffnung . " |\n";
    if ($hoehe)          $body .= "| **Einfahrtshöhe** | " . $hoehe . " m |\n";
    if ($url)            $body .= "| **URL** | " . $url . " |\n";
    if ($bemerkung)      $body .= "\n**Bemerkung:** " . $bemerkung . "\n";
    $body .= "\n---\n*Eingereicht am " . date('d.m.Y H:i') . " über data.parkraumwende.de*";

    $issue = [
        'title'  => 'Korrektur: ' . $name,
        'body'   => $body,
        'labels' => ['korrektur'],
    ];

    $res = github_request('POST', "https://api.github.com/repos/{$githubRepo}/issues", github_headers($githubToken), json_encode($issue));

    if ($res['code'] === 201) {
        $data     = json_decode($res['body'], true);
        $issueNum = $data['number'] ?? null;
        $issueUrl = $data['html_url'] ?? '';
        notifyByMail($kontakt_name, $email, "Korrektur #$issueNum: $name", "Korrekturvorschlag #$issueNum\n\nParkplatz: $name\nGitHub Issue: $issueUrl\n");
        echo json_encode(['ok' => true, 'issue' => $issueNum]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'GitHub Issue konnte nicht erstellt werden (HTTP ' . $res['code'] . ')']);
    }
    exit;
}

// ─── Neuer Karteneintrag: direkt in data/parkraummap.csv, Verifiziert=Nein ─────

$csvRelPath = 'data/parkraummap.csv';
$now        = date('Y-m-d H:i:s');

$buildNewRow = function (array $header, array $existingRows) use (
    $name, $typ, $bew, $immotyp, $plaetze, $freie_plaetze, $dauerparken, $kurzzeitparken,
    $preis_monat, $preis_tag, $preis_stunde, $oeffnung, $strasse, $hausnr, $plz, $ort,
    $hoehe, $lat, $lon, $url, $bemerkung, $now
) {
    $nextId = 0;
    foreach ($existingRows as $r) {
        if (isset($r['ID']) && ctype_digit((string)$r['ID'])) {
            $nextId = max($nextId, (int)$r['ID']);
        }
    }
    $nextId++;

    $row = array_fill_keys($header, '');
    $row = array_merge($row, [
        'ID'                  => (string)$nextId,
        'Name'                => $name,
        'Typ'                 => $typ,
        'Bewirtschaftungsart' => $bew,
        'Immobilientyp'       => $immotyp,
        'Aktiv'               => 'Ja',
        'Verifiziert'         => 'Nein',
        'Plätze'              => $plaetze,
        'Freie Plätze'        => $freie_plaetze,
        'Dauerparken'         => $dauerparken,
        'Kurzzeitparken'      => $kurzzeitparken,
        'Preis/Monat'         => $preis_monat,
        'Preis/Tag'           => $preis_tag,
        'Preis/Stunde'        => $preis_stunde,
        'Öffnungszeiten'      => $oeffnung,
        'Straße'              => $strasse,
        'Hausnummer'          => $hausnr,
        'PLZ'                 => $plz,
        'Ort'                 => $ort,
        'Einfahrtshöhe'       => $hoehe,
        'Latitude'            => $lat,
        'Longitude'           => $lon,
        'URL 1'               => $url,
        'Bemerkung'           => $bemerkung,
        'Quelle'              => 'Formular',
        'Potential'           => 'Nein',
        'Erstellt am'         => $now,
        'Aktualisiert am'     => $now,
    ]);

    return [$row, $nextId];
};

try {
    if ($isLocal) {
        $csvPath = __DIR__ . '/' . $csvRelPath;
        $fh = fopen($csvPath, 'r+');
        if (!$fh) {
            throw new RuntimeException("Lokale CSV nicht lesbar: {$csvPath}");
        }
        flock($fh, LOCK_EX);
        [$header, $rows] = parseCsvStream($fh);
        [$newRow, $newId] = $buildNewRow($header, $rows);
        $rows[] = $newRow;
        $content = buildCsv($header, $rows);
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $content);
        flock($fh, LOCK_UN);
        fclose($fh);
    } else {
        $newId  = null;
        $maxTry = 5;
        for ($attempt = 1; $attempt <= $maxTry; $attempt++) {
            $current = github_get_file($csvRelPath, $githubToken, $githubRepo);
            [$header, $rows] = parseCsvString($current ?? '');
            [$newRow, $newId] = $buildNewRow($header, $rows);
            $rows[] = $newRow;
            $content = buildCsv($header, $rows);

            try {
                github_commit_multiple(
                    [$csvRelPath => $content],
                    "data: Karteneintrag #{$newId} – {$name}",
                    $githubToken,
                    $githubRepo
                );
                break;
            } catch (RuntimeException $e) {
                $isConflict = strpos($e->getMessage(), 'HTTP 422') !== false || strpos($e->getMessage(), 'HTTP 409') !== false;
                if (!$isConflict || $attempt === $maxTry) {
                    throw $e;
                }
                usleep(random_int(200, 600) * 1000);
            }
        }
    }

    notifyByMail(
        $kontakt_name,
        $email,
        "Karteneintrag #$newId: $name",
        "Neuer Karteneintrag #$newId\n\nParkplatz: $name\n" .
        ($adresseVoll ? "Adresse: $adresseVoll\n" : '') .
        "\nZur Prüfung: https://data.parkraumwende.de/?eintrag={$newId}&preview=1#karte\n"
    );

    echo json_encode(['ok' => true, 'id' => $newId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Eintrag konnte nicht gespeichert werden: ' . $e->getMessage()]);
}

// ─── Helfer ─────────────────────────────────────────────────────────────────

function parseCsvString(string $content): array
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $content);
    rewind($fh);
    $result = parseCsvStream($fh);
    fclose($fh);
    return $result;
}

function parseCsvStream($fh): array
{
    rewind($fh);
    $header = fgetcsv($fh, 0, ';', '"', '') ?: [];
    $rows = [];
    while (($line = fgetcsv($fh, 0, ';', '"', '')) !== false) {
        if ($line === [null]) {
            continue;
        }
        $rows[] = array_combine($header, array_pad($line, count($header), ''));
    }
    return [$header, $rows];
}

// Minimales Quoting wie Python csv.QUOTE_MINIMAL (nur bei Trennzeichen/Anführungszeichen/
// Zeilenumbruch) – fputcsv() quotet in PHP zusätzlich Felder mit einfachem Leerzeichen,
// das würde bei jedem Schreibvorgang einen unnötig großen Diff auf praktisch jeder Zeile
// erzeugen (Namen, Adressen und Timestamps enthalten fast immer Leerzeichen).
function csvField(string $v): string
{
    if (preg_match('/[;"\n\r]/', $v)) {
        return '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}

function buildCsv(array $header, array $rows): string
{
    // Bestehende Datei nutzt CRLF-Zeilenenden – beibehalten, sonst würde jede
    // einzelne Zeile im Diff als geändert erscheinen.
    $lines = [implode(';', array_map('csvField', $header))];
    foreach ($rows as $row) {
        $ordered = array_map(fn($h) => (string)($row[$h] ?? ''), $header);
        $lines[] = implode(';', array_map('csvField', $ordered));
    }
    return implode("\r\n", $lines) . "\r\n";
}

function notifyByMail(string $kontakt_name, string $email, string $subjectRaw, string $body): void
{
    // Kontaktdaten privat per Mail – nicht in der öffentlichen CSV/im Issue.
    if (!$kontakt_name && !$email) {
        return;
    }
    if (!function_exists('mail') || getenv('PARKRAUMWENDE_LOCAL_NO_MAIL')) {
        return;
    }
    $mailSubject = '=?UTF-8?B?' . base64_encode($subjectRaw) . '?=';
    $mailBody    = '';
    if ($kontakt_name) $mailBody .= "Name:   $kontakt_name\n";
    if ($email)        $mailBody .= "E-Mail: $email\n";
    $mailBody .= "\n" . $body;
    @mail('heiko@bielinski.de', $mailSubject, $mailBody,
        "From: noreply@data.parkraumwende.de\r\nContent-Type: text/plain; charset=UTF-8");
}
