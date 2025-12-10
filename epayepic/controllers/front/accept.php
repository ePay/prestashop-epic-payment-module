<?php

class EpayepicAcceptModuleFrontController extends ModuleFrontController
{
    /**
     * @return void
     */
    public function postProcess()
    {
        $reference = Tools::getValue('ref');
        $transactionId = Tools::getValue('transactionId');

        if (empty($reference) && empty($transactionId)) {
            $this->abortWithError($this->module->l('Missing transaction reference.'));
            return;
        }

        try {

            $transaction = $this->fetchTransaction($transactionId);

            if (!$transaction) {
                throw new Exception($this->module->l('Transaction not found.'));
            }

            $this->module->updatePaymentState($transaction['reference'], $transaction);

            if ($transaction['state'] !== 'SUCCESS') {
                throw new Exception($this->module->l('The transaction was not approved.'));
            }

            $orderId = $this->module->finalizeOrder($transaction);
            if (!$orderId) {
                throw new Exception($this->module->l('Unable to create order.'));
            }

            $order = new Order((int)$orderId);
            $payment = $this->module->findPaymentByReference($reference);

            Tools::redirect(
                'index.php?controller=order-confirmation&id_cart=' . (int)$payment['id_cart']
                . '&id_module=' . (int)$this->module->id
                . '&id_order=' . (int)$orderId
                . '&key=' . $order->secure_key
            );
        } catch (Exception $exception) {
            EpicHelper::log('Accept error: ' . $exception->getMessage());
            $this->abortWithError($exception->getMessage());
        }
    }

    private function fetchTransaction($transactionId)
    {
        $client = $this->module->getApiClient();

        if ($transactionId) {
            $response = $client->getTransaction($transactionId);

            return isset($response['transaction']) ? $response['transaction'] : null;
        }
    }

    private function abortWithError($message)
    {
        $this->context->smarty->assign(array(
            'paymentError' => $message,
            'link' => $this->context->link,
        ));
        $this->setTemplate('module:epayepic/views/templates/front/error.tpl');
    }
}
