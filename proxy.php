<?php
// proxy.php
// Simple read-only proxy to Airtable for HRVH map

// *** SECURITY NOTE ***
// ) Restrict this file to your own domain/origin if you like


header('Content-Type: application/json');

// TODO: put your Airtable PAT here:
$airtableToken = 'YOUR_AIRTABLE_PAT_HERE';
$baseId  = 'YOUR_BASE_ID';
$tableId = 'YOUR_TABLE_ID';
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
