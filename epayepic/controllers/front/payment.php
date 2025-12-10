<?php

class EpayepicPaymentModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!$this->module->active || !$cart->id_customer || !$cart->id_address_delivery || !$cart->id_address_invoice) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        try {
            $sessionData = $this->module->createPaymentSession($cart);
            if (!isset($sessionData['paymentWindowUrl'])) {
                throw new Exception($this->module->l('Unable to start payment session.'));
            }
            Tools::redirect($sessionData['paymentWindowUrl']);
        } catch (Exception $exception) {
            EpicHelper::log('Session creation failed: ' . $exception->getMessage());
            $this->errors[] = $exception->getMessage();
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, 'step=1'));
        }
    }
}
