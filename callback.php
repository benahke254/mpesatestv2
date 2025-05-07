<?php
require_once 'db.php'; // Include your database connection

// Read the incoming callback data
$callbackJSONData = file_get_contents('php://input');
$callbackData = json_decode($callbackJSONData, true);

// Extract necessary information
$checkout_id = $callbackData['Body']['stkCallback']['CheckoutRequestID'];
$result_code = $callbackData['Body']['stkCallback']['ResultCode'];

if ($result_code == 0) {
    // Payment was successful
    $metadata = $callbackData['Body']['stkCallback']['CallbackMetadata']['Item'];
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

    // Check if the checkout_id exists in pendingpayments
    $stmt = $pdo->prepare("SELECT * FROM pendingpayments WHERE checkout_id = ?");
    $stmt->execute([$checkout_id]);
    $payment = $stmt->fetch();

    if ($payment) {
        // Insert into clients
        $stmt = $pdo->prepare("INSERT INTO clients (phone, amount, mpesa_receipt_number, checkout_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$phone, $amount, $mpesa_receipt_number, $checkout_id]);

        // Update pendingpayments status
        $stmt = $pdo->prepare("UPDATE pendingpayments SET status = 'completed' WHERE checkout_id = ?");
        $stmt->execute([$checkout_id]);
    }
}
?>
