<?php

class EpayepicCallbackModuleFrontController extends ModuleFrontController
{
    /**
     * Webhook endpoint.
     *
     * @return void
     */
    public function postProcess()
    {
        $this->ajax = true;

        try {
            $this->validateWebhookAuth();

            $body = Tools::file_get_contents('php://input');
            $payload = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->renderJson(array(
                    'status' => 'error',
                    'message' => 'Invalid payload',
                ), 400);
                return;
            }

            $transactionId = null;
            if (isset($payload['transactionId'])) {
                $transactionId = $payload['transactionId'];
            } elseif (isset($payload['transaction']['id'])) {
                $transactionId = $payload['transaction']['id'];
            }

            if (!$transactionId) {
                $this->renderJson(array(
                    'status' => 'error',
                    'message' => 'Missing transaction id',
                ), 400);
                return;
            }

            $client = $this->module->getApiClient();
            $response = $client->getTransaction($transactionId);

            if (empty($response['transaction'])) {
                throw new Exception('Unknown transaction');
            }

            $transaction = $response['transaction'];
            $operations = isset($response['operations']) ? $response['operations'] : array();

            $this->module->updatePaymentState($transaction['reference'], $transaction, $operations);

            if (isset($transaction['state']) && $transaction['state'] === 'SUCCESS') {
                $this->module->finalizeOrder($transaction);
            }

            $this->renderJson(array('status' => 'ok'));
        } catch (Exception $exception) {
            EpicHelper::log('Webhook error: ' . $exception->getMessage());

            $status = 500;
            if (in_array($exception->getMessage(), array(
                'Missing Authorization header',
                'Missing bearer token',
                'Invalid webhook token',
            ), true)) {
                $status = 401;
            }

            $this->renderJson(array(
                'status' => 'error',
                'message' => $exception->getMessage(),
            ), $status);
        }
    }

    private function renderJson(array $data, $status = 200)
    {
        header('Content-Type: application/json', true, (int) $status);
        die(json_encode($data));
    }

    /**
     * Validate webhook Authorization header against configured token.
     *
     * Supports both:
     *   Authorization: Bearer <token>
     *   Authorization: <token>
     *
     * @return void
     * @throws Exception
     */
    private function validateWebhookAuth()
    {
        $expectedToken = trim((string) Configuration::get('EPAY_EPIC_WEBHOOK_AUTORISATION'));

        // If no token is configured, skip validation.
        if ($expectedToken === '') {
            return;
        }

        if (stripos($expectedToken, 'Bearer ') === 0) {
            $expectedToken = trim(substr($expectedToken, 7));
        }

        $authHeader = $this->getAuthorizationHeader();
        $authHeader = trim((string) $authHeader);

        if ($authHeader === '') {
            throw new Exception('Missing Authorization header');
        }

        if (stripos($authHeader, 'Bearer ') === 0) {
            $receivedToken = trim(substr($authHeader, 7));
        } else {
            $receivedToken = $authHeader;
        }

        if ($receivedToken === '') {
            throw new Exception('Missing bearer token');
        }

        if (!hash_equals($expectedToken, $receivedToken)) {
            throw new Exception('Invalid webhook token');
        }
    }

    /**
     * Read Authorization header
     *
     * @return string|null
     */
    private function getAuthorizationHeader()
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['HTTP_AUTHORIZATION'];
        }

        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();

            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strtolower($name) === 'authorization') {
                        return (string) $value;
                    }
                }
            }
        }

        return null;
    }
}