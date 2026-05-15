<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/cron/_cfg.php';

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
$bemerkung      = trim($input['bemerkung']      ?? '');
$correction_of  = trim($input['correction_of'] ?? '');
$changes        = $input['changes'] ?? [];

$isCorrection = $correction_of !== '';
$adresse = trim("$strasse $hausnr");
$adresseVoll = trim("$adresse, $plz $ort", ', ');

$heading = $isCorrection
    ? "## Korrekturvorschlag\n\n> Bezieht sich auf Eintrag: **" . $correction_of . "**\n\n"
    : "## Neuer Karteneintrag\n\n";
$body = $heading;

if ($isCorrection && !empty($changes)) {
    $body .= "### Geänderte Felder\n\n";
    $body .= "| Feld | Vorher | Nachher |\n|---|---|---|\n";
    foreach ($changes as $c) {
        $field = htmlspecialchars($c['field'] ?? '');
        $alt   = htmlspecialchars($c['alt']   ?? '–');
        $neu   = htmlspecialchars($c['neu']   ?? '–');
        $body .= "| **{$field}** | ~~{$alt}~~ | **{$neu}** |\n";
    }
    $body .= "\n### Alle Felder\n\n";
} elseif ($isCorrection) {
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
if ($bemerkung)      $body .= "\n**Bemerkung:** " . $bemerkung . "\n";
$body .= "\n---\n*Eingereicht am " . date('d.m.Y H:i') . " über data.parkraumwende.de*";

$issue = [
    'title'  => ($isCorrection ? 'Korrektur: ' : 'Karteneintrag: ') . $name,
    'body'   => $body,
    'labels' => [$isCorrection ? 'korrektur' : 'submission'],
];

$token = $cfg['github']['token'];
$repo  = $cfg['github']['repo'];
$url_api = "https://api.github.com/repos/{$repo}/issues";

$ch = curl_init($url_api);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($issue),
    CURLOPT_HTTPHEADER     => [
        'Authorization: token ' . $token,
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
        'User-Agent: parkraumwende-submit',
    ],
    CURLOPT_TIMEOUT => 15,
]);

$response = curl_exec($ch);
$code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code === 201) {
    $data       = json_decode($response, true);
    $issueNum   = $data['number'] ?? null;
    $issueUrl   = $data['html_url'] ?? '';

    // Kontaktdaten privat per Mail – nicht im öffentlichen Issue
    if ($kontakt_name || $email) {
        $mailSubject = '=?UTF-8?B?' . base64_encode(($isCorrection ? 'Korrektur' : 'Karteneintrag') . " #$issueNum: $name") . '?=';
        $mailBody    = ($isCorrection ? "Korrekturvorschlag" : "Neuer Karteneintrag") . " #$issueNum\n\n";
        if ($kontakt_name) $mailBody .= "Name:   $kontakt_name\n";
        if ($email)        $mailBody .= "E-Mail: $email\n";
        $mailBody .= "\nParkplatz: $name\n";
        if ($adresseVoll)  $mailBody .= "Adresse: $adresseVoll\n";
        $mailBody .= "\nGitHub Issue: $issueUrl\n";
        mail('heiko@bielinski.de', $mailSubject, $mailBody,
            "From: noreply@data.parkraumwende.de\r\nContent-Type: text/plain; charset=UTF-8");
    }

    echo json_encode(['ok' => true, 'issue' => $issueNum]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'GitHub Issue konnte nicht erstellt werden (HTTP ' . $code . ')']);
}
