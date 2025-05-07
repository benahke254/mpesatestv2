<?php
require_once 'db.php'; // Include your database connection

// Log function
function log_message($message) {
    $log_dir = __DIR__ . '/logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    $log_file = $log_dir . '/callback.log';
    file_put_contents($log_file, date("Y-m-d H:i:s") . " - " . $message . PHP_EOL, FILE_APPEND);
}

// Read incoming callback data
$callbackJSONData = file_get_contents('php://input');
$callbackData = json_decode($callbackJSONData, true);

// Log raw callback
log_message("Raw Callback: " . $callbackJSONData);

// Validate structure
if (!isset($callbackData['Body']['stkCallback'])) {
    log_message("Invalid callback structure.");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid callback structure.']);
    exit;
}

$stkCallback = $callbackData['Body']['stkCallback'];
$checkout_id = $stkCallback['CheckoutRequestID'] ?? '';
$result_code = $stkCallback['ResultCode'] ?? -1;

if ($result_code == 0) {
    // Payment success
    $metadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
    $mpesa_receipt_number = '';
    $phone = '';
    $amount = 0;

    foreach ($metadata as $item) {
        if ($item['Name'] === 'MpesaReceiptNumber') {
            $mpesa_receipt_number = $item['Value'] ?? '';
        } elseif ($item['Name'] === 'PhoneNumber') {
            $phone = $item['Value'] ?? '';
        } elseif ($item['Name'] === 'Amount') {
            $amount = $item['Value'] ?? 0;
        }
    }

    if (!$phone || !$mpesa_receipt_number || $amount <= 0) {
        log_message("Incomplete metadata: phone=$phone, receipt=$mpesa_receipt_number, amount=$amount");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Incomplete payment metadata']);
        exit;
    }

    try {
        // Check if pending payment exists
        $stmt = $pdo->prepare("SELECT * FROM pendingpayments WHERE checkout_id = ?");
        $stmt->execute([$checkout_id]);
        $payment = $stmt->fetch();

        if ($payment) {
            // Insert into clients table
            $stmt = $pdo->prepare("INSERT INTO clients (phone, amount, mpesa_receipt_number, checkout_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$phone, $amount, $mpesa_receipt_number, $checkout_id]);

            // Mark as completed
            $stmt = $pdo->prepare("UPDATE pendingpayments SET status = 'completed' WHERE checkout_id = ?");
            $stmt->execute([$checkout_id]);

            log_message("Payment SUCCESS: Phone=$phone, Amount=$amount, Receipt=$mpesa_receipt_number");
        } else {
            log_message("Checkout ID not found in pendingpayments: $checkout_id");
        }

    } catch (PDOException $e) {
        log_message("DB ERROR during callback: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
} else {
    log_message("Payment failed or cancelled: ResultCode=$result_code, CheckoutID=$checkout_id");
}

// Always return 200 to M-Pesa to prevent retries
http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Callback processed']);
?>
