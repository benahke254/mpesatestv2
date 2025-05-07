<?php
// stk_push.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

function sanitize_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

function log_message($message) {
    $log_file = 'logs/transactions.log';
    file_put_contents($log_file, date("Y-m-d H:i:s") . " - " . $message . PHP_EOL, FILE_APPEND);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['phone']) || !isset($data['amount'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    log_message('Invalid input received.');
    exit;
}

$phone = sanitize_input($data['phone']);
$amount = sanitize_input($data['amount']);

if (!preg_match('/^\d{10,12}$/', $phone) || !is_numeric($amount)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid phone number or amount format.']);
    log_message('Invalid phone or amount format: Phone=' . $phone . ', Amount=' . $amount);
    exit;
}

$checkout_id = uniqid('checkout_', true);

try {
    $stmt = $pdo->prepare("INSERT INTO pendingpayments (checkout_id, phone, amount) VALUES (?, ?, ?)");
    $stmt->execute([$checkout_id, $phone, $amount]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    log_message('DB Insert Error: ' . $e->getMessage());
    exit;
}

$shortcode = '174379';
$consumerKey = 'FcrA6bZbGZfm7XGOsuQGMGQQlnNpYUSVuohKN4cbUBOhr7ml';
$consumerSecret = 'p30cG1LMM8AzGptCtk8MdtZrSY9R7KQ17r7ibaU6Q2X7n1XG4ijoWsFH7e8J9BkJ';
$passkey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
$callbackUrl = 'https://mpesatest-mk71.onrender.com/callback.php';

$timestamp = date('YmdHis');
$password = base64_encode($shortcode . $passkey . $timestamp);

$credentials = base64_encode("$consumerKey:$consumerSecret");
$token_url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
if (curl_errno($ch)) {
    log_message('Token request curl error: ' . curl_error($ch));
    echo json_encode(['success' => false, 'message' => 'Token request failed']);
    exit;
}
curl_close($ch);

$result = json_decode($response, true);
if (!isset($result['access_token'])) {
    log_message('Access token missing in response');
    echo json_encode(['success' => false, 'message' => 'Access token generation failed']);
    exit;
}

$access_token = $result['access_token'];
$stkPushUrl = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

$payload = [
    'BusinessShortCode' => $shortcode,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => $amount,
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
    log_message('STK Push curl error: ' . curl_error($ch));
    echo json_encode(['success' => false, 'message' => 'STK Push request failed']);
    exit;
}
curl_close($ch);

$result = json_decode($response, true);

if (isset($result['ResponseCode']) && $result['ResponseCode'] == '0') {
    log_message('STK Push sent successfully for ' . $phone);
    echo json_encode(['success' => true, 'message' => 'STK Push Sent']);
} else {
    log_message('STK Push failed: ' . json_encode($result));
    echo json_encode(['success' => false, 'message' => 'Failed to initiate STK Push']);
}
?>
