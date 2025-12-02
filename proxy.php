<?php
// proxy.php
// Simple read-only proxy to Airtable for a map

// *** SECURITY NOTE ***
// Restrict this file to your own domain/origin to prevent outside people from loading your airtable data


header('Content-Type: application/json');


// ---- LOCK TO LOCALHOST ONLY ----
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

if ($remoteIp !== '127.0.0.1' && $remoteIp !== '::1') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: localhost only']);
    exit;
}

$airtableToken = '<your token>';
$baseId  = '<your baseid>';
$tableId = '<your baseid>';
$view    = urlencode('Grid View');  

$url = "https://api.airtable.com/v0/{$baseId}/{$tableId}?view={$view}&pageSize=100";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$airtableToken}",
        "Content-Type: application/json"
    ]
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($httpCode);
echo $response !== false ? $response : json_encode(['error' => 'Airtable request failed']);
