<?php

class EpayepicFailureModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $reference = Tools::getValue('ref');
        $message = $this->module->l('The payment was cancelled or failed.');

        if ($reference) {
            EpicHelper::log('Payment failed for reference ' . $reference);
        }

        $this->errors[] = $message;
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, 'step=1'));
    }
}
