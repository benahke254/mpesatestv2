<?php
require_once 'db.php'; // Include your database connection

// Log function
function log_message($message) {
    $log_file = __DIR__ . '/logs/callback.log';
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
        if ($item['Name'] == 'MpesaReceiptNumber') {
            $mpesa_receipt_number = $item['Value'];
        } elseif ($item['Name'] == 'PhoneNumber') {
            $phone = $item['Value'];
        } elseif ($item['Name'] == 'Amount') {
            $amount = $item['Value'];
        }
    }

    try {
        // Check if pending payment exists
        $stmt = $pdo->prepare("SELECT * FROM pendingpayments WHERE checkout_id = ?");
        $stmt->execute([$checkout_id]);
        $payment = $stmt->fetch();

        if ($payment) {
            // Insert client data
            $stmt = $pdo->prepare("INSERT INTO clients (phone, amount, mpesa_receipt_number, checkout_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$phone, $amount, $mpesa_receipt_number, $checkout_id]);

            // Update pendingpayments status
            $stmt = $pdo->prepare("UPDATE pendingpayments SET status = 'completed' WHERE checkout_id = ?");
            $stmt->execute([$checkout_id]);

            log_message("Payment success: $phone, $amount, $mpesa_receipt_number");
        } else {
            log_message("Checkout ID not found: $checkout_id");
        }
    } catch (PDOException $e) {
        log_message("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
} else {
    // Payment failed or cancelled
    log_message("Payment failed or cancelled. ResultCode: $result_code, CheckoutID: $checkout_id");
}

// Always return 200 to M-Pesa to avoid repeated callbacks
http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Callback received']);
?>
