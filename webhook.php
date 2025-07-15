<?php
// webhook.php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/google-sheets.php';

use Dotenv\Dotenv;

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

file_put_contents('log.txt', "REQUEST METHOD: " . (
    $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . " | URI: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN') . PHP_EOL, FILE_APPEND);

// === STEP 1: Verify Webhook ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    file_put_contents('log.txt', 'FB_VERIFY_TOKEN from env: ' . ($_ENV['FB_VERIFY_TOKEN'] ?? 'NOT SET') . PHP_EOL, FILE_APPEND);
    file_put_contents('log.txt', 'FB_VERIFY_TOKEN from getenv: ' . getenv('FB_VERIFY_TOKEN') . PHP_EOL, FILE_APPEND);
    file_put_contents('log.txt', 'hub_verify_token from GET: ' . ($_GET['hub_verify_token'] ?? 'NOT SET') . PHP_EOL, FILE_APPEND);
    $env_token = $_ENV['FB_VERIFY_TOKEN'] ?? getenv('FB_VERIFY_TOKEN');
    if ($_GET['hub_verify_token'] === $env_token) {
        echo $_GET['hub_challenge'];
    } else {
        echo 'Invalid verify token';
    }
    exit;
}

// === STEP 2: Receive Webhook Payload ===
$raw_input = file_get_contents('php://input');
file_put_contents('log.txt', "RAW INPUT: " . $raw_input . PHP_EOL, FILE_APPEND);
$input = json_decode($raw_input, true);
file_put_contents('log.txt', "PARSED INPUT: " . json_encode($input) . PHP_EOL, FILE_APPEND);

$leadgen_id = $input['entry'][0]['changes'][0]['value']['leadgen_id'] ?? null;
file_put_contents('log.txt', "LEADGEN_ID: " . var_export($leadgen_id, true) . PHP_EOL, FILE_APPEND);
if (!$leadgen_id) {
    file_put_contents('log.txt', "ERROR: leadgen_id missing or null" . PHP_EOL, FILE_APPEND);
    http_response_code(400);
    echo 'Invalid Payload';
    exit;
}

// === STEP 3: Fetch Lead Details from Meta ===
$page_access_token = $_ENV['FB_PAGE_ACCESS_TOKEN'];
$lead_url = "https://graph.facebook.com/v18.0/{$leadgen_id}?access_token={$page_access_token}";
$response = @file_get_contents($lead_url);
if ($response === false) {
    file_put_contents('log.txt', "ERROR: Failed to fetch lead data from Meta for leadgen_id $leadgen_id" . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo 'Failed to fetch lead data';
    exit;
}
$lead_data = json_decode($response, true);
file_put_contents('log.txt', "LEAD DATA: " . json_encode($lead_data) . PHP_EOL, FILE_APPEND);

// Parse Field Data
$fields = [];   
if (isset($lead_data['field_data'])) {
    foreach ($lead_data['field_data'] as $field) {
        $fields[$field['name']] = $field['values'][0];
    }
} else {
    file_put_contents('log.txt', "ERROR: field_data missing in lead_data" . PHP_EOL, FILE_APPEND);
}

// === STEP 4: Push to Google Sheet ===
append_to_google_sheet([
    'Full Name' => $fields['full_name'] ?? '',
    'Email' => $fields['email'] ?? '',
    'Phone Number' => $fields['phone_number'] ?? '',
    'Submitted At' => date('Y-m-d H:i:s'),
]);

echo 'Success';
