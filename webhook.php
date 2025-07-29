<?php
// webhook.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/vendor/autoload.php';   
require_once __DIR__ . '/google-sheets.php';

use Dotenv\Dotenv;

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Log every request at the very start
file_put_contents('log.txt', "==== NEW REQUEST ====\n" .
    "DATE: " . date('c') . "\n" .
    "REQUEST METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . " | URI: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN') . "\n" .
    "_ENV: " . json_encode($_ENV) . "\n" .
    "_GET: " . json_encode($_GET) . "\n" .
    "_POST: " . json_encode($_POST) . "\n",
    FILE_APPEND);

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
$raw_input = file_get_contents('php://input');
file_put_contents('log.txt', "RAW INPUT: " . $raw_input . "\n", FILE_APPEND);
$input = json_decode($raw_input, true);
file_put_contents('log.txt', "PARSED INPUT: " . json_encode($input) . "\n", FILE_APPEND);

if (!is_array($input)) {
    file_put_contents('log.txt', "ERROR: Invalid or empty JSON payload\n", FILE_APPEND);
    http_response_code(400);
    echo 'Invalid Payload';
    exit;
}

$leadgen_id = $input['entry'][0]['changes'][0]['value']['leadgen_id'] ?? null;
file_put_contents('log.txt', "LEADGEN_ID: " . var_export($leadgen_id, true) . "\n", FILE_APPEND);
if (!$leadgen_id) {
    file_put_contents('log.txt', "ERROR: leadgen_id missing or null\n", FILE_APPEND);
    http_response_code(400);
    echo 'Invalid Payload';
    exit;
}

// === STEP 3: Fetch Lead Details from Meta ===
$page_access_token = $_ENV['FB_PAGE_ACCESS_TOKEN'] ?? getenv('FB_PAGE_ACCESS_TOKEN');
if (!$page_access_token) {
    file_put_contents('log.txt', "ERROR: FB_PAGE_ACCESS_TOKEN missing in env\n", FILE_APPEND);
    http_response_code(500);
    echo 'Server Misconfiguration';
    exit;
}
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

// Parse Field Data
$fields = [];
if (isset($lead_data['field_data'])) {
    foreach ($lead_data['field_data'] as $field) {
        $fields[$field['name']] = $field['values'][0];
    }
} else {
    file_put_contents('log.txt', "ERROR: field_data missing in lead_data\n", FILE_APPEND);
}

// === STEP 4: Push to Google Sheet ===
try {
    append_to_google_sheet([
        'Full Name' => $fields['full_name'] ?? '',
        'Email' => $fields['email'] ?? '',
        'Phone Number' => $fields['phone_number'] ?? '',
        'Submitted At' => date('Y-m-d H:i:s'),
    ]);
    file_put_contents('log.txt', "Google Sheet append success.\n", FILE_APPEND);
    echo 'Success';
} catch (Exception $e) {
    file_put_contents('log.txt', "ERROR: Google Sheet append failed: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo 'Failed to append to Google Sheet';
}
