<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class EpicApiClient
{
    const BASE_URL = 'https://payments.epay.eu';

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $baseUrl;

    public function __construct($apiKey, $baseUrl = self::BASE_URL)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function createSession(array $payload, $idempotencyKey = null)
    {
        return $this->request('POST', '/public/api/v1/cit', $payload, $idempotencyKey);
    }

    public function getTransaction($transactionId)
    {
        return $this->request('GET', '/public/api/v1/transactions/' . rawurlencode($transactionId));
    }

    public function capture($transactionId, $amount, $voidRemaining = false)
    {
        $payload = array(
            'amount' => (int)$amount,
            'voidRemaining' => (bool)$voidRemaining,
        );

        return $this->request(
            'POST',
            '/public/api/v1/transactions/' . rawurlencode($transactionId) . '/capture',
            $payload
        );
    }

    public function refund($transactionId, $amount)
    {
        $payload = array(
            'amount' => (int)$amount,
        );

        return $this->request(
            'POST',
            '/public/api/v1/transactions/' . rawurlencode($transactionId) . '/refund',
            $payload
        );
    }

    public function void($transactionId)
    {
        return $this->request(
            'POST',
            '/public/api/v1/transactions/' . rawurlencode($transactionId) . '/void'
        );
    }

    private function request($method, $path, array $payload = null, $idempotencyKey = null)
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();

        $headers = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        );

        if ($payload !== null) {
            $body = json_encode($payload);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('ePay Epic API error: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($status >= 400) {
            $message = isset($decoded['message']) ? $decoded['message'] : 'HTTP ' . $status;
            throw new Exception('ePay Epic API error: ' . $message);
        }

        return $decoded;
    }
}
