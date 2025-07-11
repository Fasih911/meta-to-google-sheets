<?php
// google-sheets.php

require_once __DIR__ . '/vendor/autoload.php';

use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Dotenv\Dotenv;

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

function append_to_google_sheet($lead)
{
    $client = new Google_Client();
    $client->setAuthConfig(__DIR__ . '/credentials.json');
    $client->addScope(Google_Service_Sheets::SPREADSHEETS);

    $service = new Google_Service_Sheets($client);

    $spreadsheetId = $_ENV['GOOGLE_SHEET_ID'];
    $range = $_ENV['GOOGLE_SHEET_RANGE'];

    $values = [[
        $lead['Full Name'],
        $lead['Email'],
        $lead['Phone Number'],
        $lead['Submitted At']
    ]];

    $body = new Google_Service_Sheets_ValueRange([
        'values' => $values
    ]);

    $params = ['valueInputOption' => 'RAW'];
    $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);
}
