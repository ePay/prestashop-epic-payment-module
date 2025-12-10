<?php

class AdminEpayEpicController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
    }

    public function postProcess()
    {
        $action = Tools::getValue('epay_epic_action');
        $orderId = (int)Tools::getValue('id_order');
        $returnUrl = Tools::getValue('return_url');

        if (!$action || !$orderId) {
            Tools::redirectAdmin($returnUrl);
            return;
        }

        $payment = $this->module->findPaymentByOrderId($orderId);
        if (!$payment || empty($payment['transaction_id'])) {
            Tools::redirectAdmin($returnUrl . '&epayepic_error=' . urlencode($this->module->l('Transaction not found.')));
            return;
        }

        $client = $this->module->getApiClient();
        $currencyIso = $payment['currency'];

        try {
            switch ($action) {
                case 'capture':
                    $amount = (float)Tools::getValue('amount');
                    $client->capture($payment['transaction_id'], EpicHelper::toMinorUnits($amount, $currencyIso));
                    $message = $this->module->l('Capture request sent.');
                    break;
                case 'refund':
                    $amount = (float)Tools::getValue('amount');
                    $client->refund($payment['transaction_id'], EpicHelper::toMinorUnits($amount, $currencyIso));
                    $message = $this->module->l('Refund request sent.');
                    break;
                case 'void':
                    $client->void($payment['transaction_id']);
                    $message = $this->module->l('Void request sent.');
                    break;
                default:
                    throw new Exception($this->module->l('Unsupported action.'));
            }

            $transactionResponse = $client->getTransaction($payment['transaction_id']);
            if (!empty($transactionResponse['transaction'])) {
                $this->module->updatePaymentState(
                    $transactionResponse['transaction']['reference'],
                    $transactionResponse['transaction'],
                    isset($transactionResponse['operations']) ? $transactionResponse['operations'] : array()
                );
            }

            Tools::redirectAdmin($returnUrl . '&epayepic_message=' . urlencode($message));
        } catch (Exception $exception) {
            Tools::redirectAdmin($returnUrl . '&epayepic_error=' . urlencode($exception->getMessage()));
        }
    }
}
