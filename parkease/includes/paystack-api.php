<?php
/**
 * Paystack API Wrapper
 * Handles all Paystack API calls
 */

require_once 'config/paystack.php';

class PaystackAPI {
    private $secret_key;
    private $api_url;
    
    public function __construct() {
        $this->secret_key = PAYSTACK_SECRET_KEY;
        $this->api_url = PAYSTACK_API_URL;
    }
    
    /**
     * Initialize a transaction
     */
    public function initializeTransaction($email, $amount, $reference, $metadata = []) {
        $url = $this->api_url . "/transaction/initialize";
        
        $data = [
            'email' => $email,
            'amount' => $amount * 100, // Convert to kobo
            'reference' => $reference,
            'callback_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/Park_Ease/parkease/payment-success.php',
            'metadata' => $metadata,
            'channels' => ['card', 'bank', 'ussd', 'qr', 'mobile_money']
        ];
        
        $result = $this->callAPI($url, $data);
        
        if ($result && $result->status) {
            return [
                'status' => true,
                'authorization_url' => $result->data->authorization_url,
                'access_code' => $result->data->access_code,
                'reference' => $result->data->reference
            ];
        }
        
        return [
            'status' => false,
            'message' => $result->message ?? 'Failed to initialize transaction'
        ];
    }
    
    /**
     * Verify a transaction
     */
    public function verifyTransaction($reference) {
        $url = $this->api_url . "/transaction/verify/" . urlencode($reference);
        $result = $this->callAPI($url, null, 'GET');
        
        if ($result && $result->status) {
            return [
                'status' => true,
                'amount' => $result->data->amount / 100,
                'currency' => $result->data->currency,
                'payment_status' => $result->data->status,
                'paid_at' => $result->data->paid_at,
                'channel' => $result->data->channel,
                'card_type' => $result->data->authorization->card_type ?? null,
                'last4' => $result->data->authorization->last4 ?? null,
                'bank' => $result->data->authorization->bank ?? null
            ];
        }
        
        return [
            'status' => false,
            'message' => $result->message ?? 'Verification failed'
        ];
    }
    
    /**
     * Process a refund transaction
     * 
     * @param string $reference The original transaction reference
     * @param float $amount Amount to refund (in Naira)
     * @param string $reason Reason for refund (optional)
     * @return array Response from Paystack
     */
    public function refundTransaction($reference, $amount, $reason = 'Customer cancellation') {
        $url = $this->api_url . "/refund";
        
        // Convert amount to kobo (Paystack uses smallest currency unit)
        $amount_in_kobo = $amount * 100;
        
        $data = [
            'transaction' => $reference,
            'amount' => (int)$amount_in_kobo,
            'currency' => 'NGN',
            'customer_note' => $reason,
            'merchant_note' => $reason
        ];
        
        $result = $this->callAPI($url, $data);
        
        if ($result && $result->status) {
            return [
                'status' => true,
                'message' => 'Refund initiated successfully',
                'refund_reference' => $result->data->reference ?? null,
                'refund_amount' => $result->data->amount / 100 ?? $amount,
                'data' => $result->data
            ];
        }
        
        return [
            'status' => false,
            'message' => $result->message ?? 'Refund initiation failed'
        ];
    }
    
    /**
     * Check refund status
     * 
     * @param string $refund_reference The refund reference
     * @return array Refund status
     */
    public function checkRefundStatus($refund_reference) {
        $url = $this->api_url . "/refund/" . urlencode($refund_reference);
        $result = $this->callAPI($url, null, 'GET');
        
        if ($result && $result->status) {
            return [
                'status' => true,
                'refund_status' => $result->data->status ?? 'unknown',
                'amount' => $result->data->amount / 100 ?? 0,
                'data' => $result->data
            ];
        }
        
        return [
            'status' => false,
            'message' => $result->message ?? 'Failed to check refund status'
        ];
    }
    
    /**
     * Create transfer recipient (for payouts)
     */
    public function createTransferRecipient($name, $account_number, $bank_code) {
        $url = $this->api_url . "/transferrecipient";
        
        $data = [
            'type' => 'nuban',
            'name' => $name,
            'account_number' => $account_number,
            'bank_code' => $bank_code,
            'currency' => 'NGN'
        ];
        
        $result = $this->callAPI($url, $data);
        
        if ($result && $result->status) {
            return [
                'status' => true,
                'recipient_code' => $result->data->recipient_code,
                'details' => $result->data->details
            ];
        }
        
        return [
            'status' => false,
            'message' => $result->message ?? 'Failed to create recipient'
        ];
    }
    
    /**
     * Initiate transfer (payout)
     */
    public function initiateTransfer($amount, $recipient_code, $reason = '') {
        $url = $this->api_url . "/transfer";
        
        $data = [
            'source' => 'balance',
            'amount' => $amount * 100, // Convert to kobo
            'recipient' => $recipient_code,
            'reason' => $reason
        ];
        
        $result = $this->callAPI($url, $data);
        
        if ($result && $result->status) {
            return [
                'status' => true,
                'transfer_code' => $result->data->transfer_code,
                'amount' => $result->data->amount / 100
            ];
        }
        
        return [
            'status' => false,
            'message' => $result->message ?? 'Transfer failed'
        ];
    }
    
    /**
     * Get list of banks
     */
    public function getBanks() {
        $url = $this->api_url . "/bank";
        $result = $this->callAPI($url, null, 'GET');
        
        if ($result && $result->status) {
            return $result->data;
        }
        
        return [];
    }
    
    /**
     * Charge authorization (for saved cards)
     */
    public function chargeAuthorization($email, $amount, $authorization_code) {
        $url = $this->api_url . "/transaction/charge_authorization";
        
        $data = [
            'email' => $email,
            'amount' => $amount * 100,
            'authorization_code' => $authorization_code
        ];
        
        $result = $this->callAPI($url, $data);
        
        return $result && $result->status;
    }
    
    /**
     * Call Paystack API
     */
    private function callAPI($url, $data = null, $method = 'POST') {
        $ch = curl_init();
        
        $headers = [
            'Authorization: Bearer ' . $this->secret_key,
            'Content-Type: application/json'
        ];
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method == 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response);
        }
        
        error_log("Paystack API Error: HTTP $httpCode - $response");
        return null;
    }
}
?>