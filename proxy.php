<?php
// proxy.php
// Airtable helper for HRVH map — include-only

// Block direct web access just in case .htaccess isn't applied
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// Optional: ensure we're inside WordPress
if (!defined('ABSPATH')) {
    exit;
}

function hrvh_get_airtable_map_data() {
    $airtableToken = '<Your Airtable Token>';
    $baseId  = '<Your BaseID>';
    $tableId = '<Your Table ID';
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

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    // Return decoded JSON so PHP can work with it
    return json_decode($response, true);
}
