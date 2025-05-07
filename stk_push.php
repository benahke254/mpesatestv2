<?php
// stk_push.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

function sanitize_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

function log_message($message) {
    $log_dir = 'logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    $log_file = $log_dir . '/transactions.log';
    file_put_contents($log_file, date("Y-m-d H:i:s") . " - " . $message . PHP_EOL, FILE_APPEND);
}

// Get input data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['phone']) || !isset($data['amount'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing phone or amount']);
    log_message('Missing input: ' . json_encode($data));
    exit;
}

$phone = sanitize_input($data['phone']);
$amount = sanitize_input($data['amount']);

// Validate phone and amount
if (!preg_match('/^(\+254|254|0)?7\d{8}$/', $phone) || !is_numeric($amount)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid phone or amount format']);
    log_message("Validation failed - Phone: $phone, Amount: $amount");
    exit;
}

// Normalize phone to 2547XXXXXXXX
$phone = preg_replace('/^0/', '254', $phone);
$phone = preg_replace('/^\+/', '', $phone);

// Generate unique checkout ID
$checkout_id = uniqid('checkout_', true);

// Insert into pending payments
try {
    $stmt = $pdo->prepare("INSERT INTO pendingpayments (checkout_id, phone, amount) VALUES (?, ?, ?)");
    $stmt->execute([$checkout_id, $phone, $amount]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    log_message('DB Insert Error: ' . $e->getMessage());
    exit;
}

// Safaricom API credentials
$shortcode = '174379';
$consumerKey = 'FcrA6bZbGZfm7XGOsuQGMGQQlnNpYUSVuohKN4cbUBOhr7ml';
$consumerSecret = 'p30cG1LMM8AzGptCtk8MdtZrSY9R7KQ17r7ibaU6Q2X7n1XG4ijoWsFH7e8J9BkJ';
$passkey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
$callbackUrl = 'https://mpesatest-mk71.onrender.com/callback.php';

// Generate access token
$timestamp = date('YmdHis');
$password = base64_encode($shortcode . $passkey . $timestamp);
$credentials = base64_encode("$consumerKey:$consumerSecret");

$token_url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);

if (curl_errno($ch)) {
    log_message('Token CURL error: ' . curl_error($ch));
    echo json_encode(['success' => false, 'message' => 'Failed to get access token']);
    exit;
}
curl_close($ch);

$result = json_decode($response, true);

if (!isset($result['access_token'])) {
    log_message('Access token missing: ' . $response);
    echo json_encode(['success' => false, 'message' => 'Access token not received']);
    exit;
}

$access_token = $result['access_token'];

// STK Push Request
$stkPushUrl = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

$payload = [
    'BusinessShortCode' => $shortcode,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => (int)$amount,
    'PartyA' => $phone,
    'PartyB' => $shortcode,
    'PhoneNumber' => $phone,
    'CallBackURL' => $callbackUrl,
    'AccountReference' => 'QuickCoin',
    'TransactionDesc' => 'Hotspot Payment'
];

$ch = curl_init($stkPushUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $access_token
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);

if (curl_errno($ch)) {
    log_message('STK Push CURL error: ' . curl_error($ch));
    echo json_encode(['success' => false, 'message' => 'Failed to send STK Push']);
    exit;
}
curl_close($ch);

$result = json_decode($response, true);

if (isset($result['ResponseCode']) && $result['ResponseCode'] == '0') {
    log_message("STK Push sent to $phone. CheckoutRequestID: " . $result['CheckoutRequestID']);
    echo json_encode([
        'success' => true,
        'message' => 'STK Push sent successfully',
        'checkoutRequestID' => $result['CheckoutRequestID']
    ]);
} else {
    log_message('STK Push failed: ' . json_encode($result));
    echo json_encode([
        'success' => false,
        'message' => 'STK Push failed',
        'error' => isset($result['errorMessage']) ? $result['errorMessage'] : 'Unknown error',
        'details' => $result
    ]);
}
?>
