<?php
require '../vendor/autoload.php';
header('Content-Type: application/json');
// Initialize response array
$response = array(
    'success' => false,
    'message' => ''
);

function preventFormulaInjection($value) {
    if (is_string($value) && strlen($value) > 0) {
        $firstChar = substr($value, 0, 1);
        if (in_array($firstChar, ['=', '+', '-', '@'])) {
            return "'" . $value; // Add apostrophe prefix
        }
    }
    return $value;
}

function searchGoogleSheet($phone, $campaign_id) {
    $client = new \Google_Client();
    $client->setApplicationName('Scrubber PHP Search API');
    $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
    $client->setAuthConfig('../credentials.json');

    // Google Sheets API configuration
    $service = new \Google_Service_Sheets($client);
    $spreadsheetId = '1aBklIKSnEQrxHOXjev3AZzhuoRI2MBGU_LIibhEdsrE';
    $range = $campaign_id . '!A:I';

    try {
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();
        
        if (empty($values)) {
            return false;
        }

        // First row contains headers
        $headers = array_shift($values);
        
        // Find the index of the Phone column
        $phoneColumnIndex = array_search('Phone', $headers);
        
        if ($phoneColumnIndex === false) {
            error_log("Phone column not found in headers");
            return false;
        }
        
        // Clean phone number for comparison (remove spaces, dashes, etc)
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Filter rows based on phone number using the found index
        $filtered_rows = array_filter($values, function($row) use ($phone, $phoneColumnIndex) {
            if (!isset($row[$phoneColumnIndex])) return false;
            
            // Clean the phone number from the row
            $rowPhone = preg_replace('/[^0-9]/', '', $row[$phoneColumnIndex]);
            
            // Compare cleaned phone numbers
            return $rowPhone === $phone;
        });
        
        // Combine headers with filtered data
        $result = [
            'headers' => $headers,
            'data' => array_values($filtered_rows) // Re-index array after filtering
        ];
        
        return $result;
    } catch (\Exception $e) {
        error_log("Google Sheets Error: " . $e->getMessage());
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get phone and campaign from POST data
        $phone = $_POST['phone'] ?? '';
        $campaign_id = $_POST['campaign'] ?? '';

        if (empty($phone) || empty($campaign_id)) {
            $response['success'] = false;
            $response['message'] = 'Phone number and campaign are required';
            echo json_encode($response);
            exit;
        }

        // Call the search function
        $result = searchGoogleSheet($phone, $campaign_id);

        if($result) {
            $response['success'] = true;
            $response['data'] = $result;
            $response['message'] = 'Success';
        } else {
            $response['success'] = false;
            $response['message'] = 'No data found';
        }
            
        echo json_encode($response);
        exit;
    } catch (\Exception $e) {
        $response['success'] = false;
        $response['message'] = 'Error: ' . $e->getMessage();
        echo json_encode($response);
        exit;
    }
} else {
    $response['success'] = false;
    $response['message'] = 'Invalid request method.';
    echo json_encode($response);
    exit;
}