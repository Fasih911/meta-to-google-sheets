<?php

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/google-sheets.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($_GET['hub_verify_token'] === $_ENV['FB_VERIFY_TOKEN']) {
        echo $_GET['hub_challenge'];
    } else {
        echo 'Invalid verify token';
    }
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
file_put_contents('log.txt', json_encode($input) . PHP_EOL, FILE_APPEND);

$leadgen_id = $input['entry'][0]['changes'][0]['value']['leadgen_id'] ?? null;
if (!$leadgen_id) {
    http_response_code(400);
    echo 'Invalid Payload';
    exit;
}

$page_access_token = $_ENV['FB_PAGE_ACCESS_TOKEN'];
$lead_url = "https://graph.facebook.com/v18.0/{$leadgen_id}?access_token={$page_access_token}";
$response = file_get_contents($lead_url);
$lead_data = json_decode($response, true);

$fields = [];
foreach ($lead_data['field_data'] as $field) {
    $fields[$field['name']] = $field['values'][0];
}

append_to_google_sheet([
    'Full Name' => $fields['full_name'] ?? '',
    'Email' => $fields['email'] ?? '',
    'Phone Number' => $fields['phone_number'] ?? '',
    'Submitted At' => date('Y-m-d H:i:s'),
]);

echo 'Success';
