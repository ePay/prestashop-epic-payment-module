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
        $body = Tools::file_get_contents('php://input');
        $payload = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->renderJson(array('status' => 'error', 'message' => 'Invalid payload'), 400);
            return;
        }

        $transactionId = null;
        if (isset($payload['transactionId'])) {
            $transactionId = $payload['transactionId'];
        } elseif (isset($payload['transaction']['id'])) {
            $transactionId = $payload['transaction']['id'];
        }

        if (!$transactionId) {
            $this->renderJson(array('status' => 'error', 'message' => 'Missing transaction id'), 400);
            return;
        }

        try {
            $client = $this->module->getApiClient();
            $response = $client->getTransaction($transactionId);
            if (empty($response['transaction'])) {
                throw new Exception('Unknown transaction');
            }

            $transaction = $response['transaction'];
            $operations = isset($response['operations']) ? $response['operations'] : array();

            $this->module->updatePaymentState($transaction['reference'], $transaction, $operations);

            if ($transaction['state'] === 'SUCCESS') {
                $this->module->finalizeOrder($transaction);
            }

            $this->renderJson(array('status' => 'ok'));
        } catch (Exception $exception) {
            EpicHelper::log('Webhook error: ' . $exception->getMessage());
            $this->renderJson(array('status' => 'error', 'message' => $exception->getMessage()), 500);
        }
    }

    private function renderJson(array $data, $status = 200)
    {
        header('Content-Type: application/json', true, $status);
        die(json_encode($data));
    }
}
