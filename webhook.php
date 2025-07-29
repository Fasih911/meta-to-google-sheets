<?php
// webhook.php
print_r("Hello World");
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/google-sheets.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// === STEP 1: Verify Webhook ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $env_token = $_ENV['FB_VERIFY_TOKEN'] ?? getenv('FB_VERIFY_TOKEN');
    $hub_token = $_GET['hub_verify_token'] ?? '';
    $hub_challenge = $_GET['hub_challenge'] ?? '';
    file_put_contents('log.txt',
        'FB_VERIFY_TOKEN from env: ' . ($env_token ?: 'NOT SET') . "\n" .
        'hub_verify_token from GET: ' . ($hub_token ?: 'NOT SET') . "\n" .
        'hub_challenge from GET: ' . ($hub_challenge ?: 'NOT SET') . "\n",
        FILE_APPEND);
    if ($hub_token && $env_token && $hub_token === $env_token) {
        echo $hub_challenge;
        file_put_contents('log.txt', "Verification success. Responded with challenge.\n", FILE_APPEND);
    } else {
        echo 'Invalid verify token';
        file_put_contents('log.txt', "Verification failed. Invalid token.\n", FILE_APPEND);
    }
    exit;
}

// === STEP 2: Receive Webhook Payload ===
$input = json_decode(file_get_contents('php://input'), true);
file_put_contents('log.txt', json_encode($input) . PHP_EOL, FILE_APPEND);

$leadgen_id = $input['entry'][0]['changes'][0]['value']['leadgen_id'] ?? null;
file_put_contents('log.txt', "LEADGEN_ID: " . var_export($leadgen_id, true) . "\n", FILE_APPEND);
if (!$leadgen_id) {
    file_put_contents('log.txt', "ERROR: leadgen_id missing or null\n", FILE_APPEND);
    http_response_code(400);
    echo 'Invalid Payload';
    exit;
}

// === STEP 3: Fetch Lead Details from Meta ===
$page_access_token = $_ENV['FB_PAGE_ACCESS_TOKEN'];
$lead_url = "https://graph.facebook.com/v18.0/{$leadgen_id}?access_token={$page_access_token}";
$response = @file_get_contents($lead_url);
if ($response === false) { 
    file_put_contents('log.txt', "ERROR: Failed to fetch lead data from Meta for leadgen_id $leadgen_id\n", FILE_APPEND);
    http_response_code(500);
    echo 'Failed to fetch lead data';
    exit;
}
$lead_data = json_decode($response, true);
file_put_contents('log.txt', "LEAD DATA: " . json_encode($lead_data) . "\n", FILE_APPEND);

$fields = [];
if (isset($lead_data['field_data'])) {
    foreach ($lead_data['field_data'] as $field) {
        $fields[$field['name']] = $field['values'][0];
    }
} else {
    file_put_contents('log.txt', "ERROR: field_data missing in lead_data\n", FILE_APPEND);
}

// === STEP 4: Push to Google Sheet ===
append_to_google_sheet([
    'Full Name' => $fields['full_name'] ?? '',
    'Email' => $fields['email'] ?? '',
    'Phone Number' => $fields['phone_number'] ?? '',
    'Submitted At' => date('Y-m-d H:i:s'),
]);

echo 'Success';
