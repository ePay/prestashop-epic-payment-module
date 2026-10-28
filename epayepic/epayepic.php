<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/lib/EpicHelper.php';
require_once __DIR__ . '/lib/EpicApiClient.php';

class EpayEpic extends PaymentModule
{
    const MODULE_VERSION = '1.0.3';
    const TABLE_PAYMENTS = 'epayepic_payments';

    public function __construct()
    {
        $this->name = 'epayepic';
        $this->tab = 'payments_gateways';
        $this->version = self::MODULE_VERSION;
        $this->author = 'ePay Payment Solutions';
        $this->need_instance = 0;
        $this->controllers = array('payment', 'accept', 'callback', 'failure');
        $this->is_eu_compatible = 1;
        $this->bootstrap = true;

        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('ePay Epic Payments');
        $this->description = $this->l('Accept payments through the new ePay Epic platform.');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        $hooks = array(
            'paymentOptions',
            'paymentReturn',
            'actionOrderStatusPostUpdate',
            'displayAdminOrderMainBottom',
        );

        foreach ($hooks as $hookName) {
            if (!$this->registerHook($hookName)) {
                return false;
            }
        }

        if (!$this->createTables()) {
            return false;
        }

        if (!$this->installTab()) {
            return false;
        }

        Configuration::updateValue('EPAY_EPIC_PAYMENT_TITLE', 'ePay (Epic)');
        Configuration::updateValue('EPAY_EPIC_FEE_PRODUCT_ID', 0);
        Configuration::updateValue('EPAY_EPIC_DEBUG', 0);
        Configuration::updateValue('EPAY_EPIC_SUCCESS_STATE', (int)Configuration::get('PS_OS_PAYMENT'));

        return true;
    }

    public function uninstall()
    {
        $this->uninstallTab();
        Configuration::deleteByName('EPAY_EPIC_API_KEY');
        Configuration::deleteByName('EPAY_EPIC_POINT_OF_SALE_ID');
        Configuration::deleteByName('EPAY_EPIC_WEBHOOK_AUTORISATION');
        Configuration::deleteByName('EPAY_EPIC_AGEVERIFICATIONMODE');
        Configuration::deleteByName('EPAY_EPIC_MINIMUMUSERAGE');
        Configuration::deleteByName('EPAY_EPIC_PAYMENT_TITLE');
        Configuration::deleteByName('EPAY_EPIC_FEE_PRODUCT_ID');
        Configuration::deleteByName('EPAY_EPIC_DEBUG');
        Configuration::deleteByName('EPAY_EPIC_SUCCESS_STATE');

        return parent::uninstall();
    }

    private function createTables()
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s%s` (
                `id_epayepic_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_order` INT UNSIGNED NULL,
                `id_cart` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL,
                `reference` VARCHAR(64) NOT NULL,
                `session_id` VARCHAR(64) NOT NULL,
                `transaction_id` VARCHAR(64) NULL,
                `state` VARCHAR(32) NOT NULL DEFAULT \'pending\',
                `amount` INT UNSIGNED NOT NULL,
                `currency` VARCHAR(3) NOT NULL,
                `payload` LONGTEXT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_epayepic_payment`),
                UNIQUE KEY `epayepic_ref` (`reference`),
                UNIQUE KEY `epayepic_session` (`session_id`),
                KEY `epayepic_cart` (`id_cart`),
                KEY `epayepic_order` (`id_order`)
            ) ENGINE=%s DEFAULT CHARSET=UTF8;',
            _DB_PREFIX_,
            self::TABLE_PAYMENTS,
            _MYSQL_ENGINE_
        );

        return Db::getInstance()->execute($sql);
    }

    private function installTab()
    {
        $tabId = (int)Tab::getIdFromClassName('AdminEpayEpic');
        if ($tabId) {
            return true;
        }

        $tab = new Tab();
        $tab->active = 0;
        $tab->class_name = 'AdminEpayEpic';
        $tab->id_parent = -1;
        $tab->module = $this->name;
        $languages = Language::getLanguages(false);
        foreach ($languages as $language) {
            $tab->name[$language['id_lang']] = 'ePay Epic';
        }

        return (bool)$tab->add();
    }

    private function uninstallTab()
    {
        $tabId = (int)Tab::getIdFromClassName('AdminEpayEpic');
        if ($tabId) {
            $tab = new Tab($tabId);
            return (bool)$tab->delete();
        }

        return true;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitEpayEpic')) {
            $this->postProcess();
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        $statuses = OrderState::getOrderStates($this->context->language->id);
        $statusOptions = array();
        foreach ($statuses as $status) {
            $statusOptions[] = array(
                'id_option' => $status['id_order_state'],
                'name' => $status['name'],
            );
        }

        $ageverification_options = array(
            array('type' => '0', 'name' => 'Disabled'),
            array('type' => '1', 'name' => 'Enabled on all orders'),
            array('type' => '2', 'name' => 'Enabled on DK orders'),
        );

        $minimumuserage_options = array(
            array('type' => '0', 'name' => '0 Years'),
            array('type' => '15', 'name' => '15 Years'),
            array('type' => '16', 'name' => '16 Years'),
            array('type' => '18', 'name' => '18 Years'),
            array('type' => '21', 'name' => '21 Years'),
        );

        $fieldsForm = array();
        $fieldsForm[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('ePay Epic configuration'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Payment title'),
                    'name' => 'EPAY_EPIC_PAYMENT_TITLE',
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Payment fee product ID'),
                    'name' => 'EPAY_EPIC_FEE_PRODUCT_ID',
                    'desc' => $this->l('Optional hidden virtual product used to add the payment fee to completed orders.'),
                    'required' => false,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('API key'),
                    'name' => 'EPAY_EPIC_API_KEY',
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Point of Sale ID'),
                    'name' => 'EPAY_EPIC_POINT_OF_SALE_ID',
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Webhook autorisation'),
                    'name' => 'EPAY_EPIC_WEBHOOK_AUTORISATION',
                    'required' => false,
                ),
                array(
                    'type' => 'select',
                    'label' => 'Ageverification mode',
                    'name' => 'EPAY_EPIC_AGEVERIFICATIONMODE',
                    'required' => true,
                    'options' => array(
                        'query' => $ageverification_options,
                        'id' => 'type',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => 'Minimum user age',
                    'name' => 'EPAY_EPIC_MINIMUMUSERAGE',
                    'required' => true,
                    'options' => array(
                        'query' => $minimumuserage_options,
                        'id' => 'type',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'switch',
                    'label' => $this->l('Enable debug logging'),
                    'name' => 'EPAY_EPIC_DEBUG',
                    'values' => array(
                        array('id' => 'debug_on', 'value' => 1, 'label' => $this->l('Yes')),
                        array('id' => 'debug_off', 'value' => 0, 'label' => $this->l('No')),
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Success order status'),
                    'name' => 'EPAY_EPIC_SUCCESS_STATE',
                    'options' => array(
                        'query' => $statusOptions,
                        'id' => 'id_option',
                        'name' => 'name',
                    ),
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right',
            ),
        );

        $helper = new HelperForm();

        $helper->module          = $this;
        $helper->name_controller = $this->name;

        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;

        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->default_form_language      = (int) $defaultLang;
        $helper->allow_employee_form_lang   = true;

        $helper->title        = $this->displayName;
        $helper->show_toolbar = false;

        $helper->submit_action = 'submitEpayEpic';

        $helper->fields_value = array(
            'EPAY_EPIC_API_KEY'          => Tools::getValue('EPAY_EPIC_API_KEY', Configuration::get('EPAY_EPIC_API_KEY')),
            'EPAY_EPIC_POINT_OF_SALE_ID' => Tools::getValue('EPAY_EPIC_POINT_OF_SALE_ID', Configuration::get('EPAY_EPIC_POINT_OF_SALE_ID')),
            'EPAY_EPIC_WEBHOOK_AUTORISATION' => Tools::getValue('EPAY_EPIC_WEBHOOK_AUTORISATION', Configuration::get('EPAY_EPIC_WEBHOOK_AUTORISATION')),
            'EPAY_EPIC_AGEVERIFICATIONMODE' => Tools::getValue('EPAY_EPIC_AGEVERIFICATIONMODE', Configuration::get('EPAY_EPIC_AGEVERIFICATIONMODE')),
            'EPAY_EPIC_MINIMUMUSERAGE' => Tools::getValue('EPAY_EPIC_MINIMUMUSERAGE', Configuration::get('EPAY_EPIC_MINIMUMUSERAGE')),
            'EPAY_EPIC_PAYMENT_TITLE'    => Tools::getValue('EPAY_EPIC_PAYMENT_TITLE', Configuration::get('EPAY_EPIC_PAYMENT_TITLE')),
            'EPAY_EPIC_FEE_PRODUCT_ID'   => (int) Tools::getValue('EPAY_EPIC_FEE_PRODUCT_ID', (int) Configuration::get('EPAY_EPIC_FEE_PRODUCT_ID')),
            'EPAY_EPIC_DEBUG'            => (int) Tools::getValue('EPAY_EPIC_DEBUG', (int) Configuration::get('EPAY_EPIC_DEBUG')),
            'EPAY_EPIC_SUCCESS_STATE'    => (int) Tools::getValue('EPAY_EPIC_SUCCESS_STATE', (int) Configuration::get('EPAY_EPIC_SUCCESS_STATE')),
        );

        return $helper->generateForm($fieldsForm);
    }

    protected function postProcess()
    {
        Configuration::updateValue('EPAY_EPIC_API_KEY', Tools::getValue('EPAY_EPIC_API_KEY'));
        Configuration::updateValue('EPAY_EPIC_POINT_OF_SALE_ID', Tools::getValue('EPAY_EPIC_POINT_OF_SALE_ID'));
        Configuration::updateValue('EPAY_EPIC_WEBHOOK_AUTORISATION', Tools::getValue('EPAY_EPIC_WEBHOOK_AUTORISATION'));
        Configuration::updateValue('EPAY_EPIC_AGEVERIFICATIONMODE', Tools::getValue('EPAY_EPIC_AGEVERIFICATIONMODE'));
        Configuration::updateValue('EPAY_EPIC_MINIMUMUSERAGE', Tools::getValue('EPAY_EPIC_MINIMUMUSERAGE'));
        Configuration::updateValue('EPAY_EPIC_PAYMENT_TITLE', Tools::getValue('EPAY_EPIC_PAYMENT_TITLE'));
        Configuration::updateValue('EPAY_EPIC_FEE_PRODUCT_ID', (int)Tools::getValue('EPAY_EPIC_FEE_PRODUCT_ID'));
        Configuration::updateValue('EPAY_EPIC_DEBUG', (int)Tools::getValue('EPAY_EPIC_DEBUG'));
        Configuration::updateValue('EPAY_EPIC_SUCCESS_STATE', (int)Tools::getValue('EPAY_EPIC_SUCCESS_STATE'));
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->isConfigured()) {
            return;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return;
        }

        $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setCallToActionText(Configuration::get('EPAY_EPIC_PAYMENT_TITLE'))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true));
            // ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/logo.png'));

        return array($option);
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }
        $order = $params['order'];
        $payment = $this->findPaymentByOrderId((int)$order->id);

        $this->smarty->assign(array(
            'status' => $order->getCurrentState(),
            'payment' => $payment,
        ));

        return $this->display($this->name, 'views/templates/hook/payment_return.tpl');
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        // Reserved for future capture/refund automation.
    }

    public function hookDisplayAdminOrderMainBottom($params)
    {
        $idOrder = 0;

        if (!empty($params['order']) && $params['order'] instanceof Order) {
            $idOrder = (int) $params['order']->id;

        } elseif (!empty($params['id_order'])) {
            $idOrder = (int) $params['id_order'];

        } else {
            $idOrder = (int) Tools::getValue('id_order');

            if (!$idOrder && isset($this->context->controller->id_order)) {
                $idOrder = (int) $this->context->controller->id_order;
            }
        }

        if (!$idOrder) {
            return 'nope';
        }

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $payment = $this->findPaymentByOrderId($idOrder);

        if (!$payment) {
            return '';
        }

        $transactionDetails = null;
        $operations = array();
        $amounts = array();
        $errorMessage = Tools::getValue('epayepic_error', null);

        if (!empty($payment['transaction_id'])) {
            try {
                $response = $this->getApiClient()->getTransaction($payment['transaction_id']);
                $transactionDetails = isset($response['transaction']) ? $response['transaction'] : null;
                $operations = isset($response['operations']) ? $response['operations'] : array();
                if ($transactionDetails) {
                    $amounts = $this->summariseOperations($transactionDetails, $operations, $payment['currency']);
                }
            } catch (Exception $exception) {
                EpicHelper::log('Unable to fetch transaction: ' . $exception->getMessage());
                $errorMessage = $exception->getMessage();
            }
        }

        $this->context->smarty->assign(array(
            'payment' => $payment,
            'transactionDetails' => $transactionDetails,
            'operations' => $operations,
            'amounts' => $amounts,
            'manage_url' => $this->context->link->getAdminLink('AdminEpayEpic'),
            'order_id' => (int)$order->id,
            'return_url' => $this->context->link->getAdminLink(
                'AdminOrders',
                true,
                array(),
                array('id_order' => (int)$order->id, 'vieworder' => 1)
            ),
            'currency' => new Currency((int)$order->id_currency),
            'epayepic_message' => Tools::getValue('epayepic_message'),
            'epayepic_error' => $errorMessage,
        ));

        return $this->display($this->name, 'views/templates/hook/admin_order.tpl');
    }

    private function summariseOperations($transaction, $operations, $currencyIso)
    {
        $authorized = (int)$transaction['amount'];
        $captured = 0;
        $refunded = 0;

        foreach ($operations as $operation) {
            if (!isset($operation['state']) || $operation['state'] !== 'SUCCESS') {
                continue;
            }

            if ($operation['type'] === 'CAPTURE') {
                $captured += (int)$operation['amount'];
            }

            if ($operation['type'] === 'REFUND') {
                $refunded += (int)$operation['amount'];
            }
        }

        $capturable = max(0, $authorized - $captured);
        $refundable = max(0, $captured - $refunded);

        return array(
            'authorized' => EpicHelper::fromMinorUnits($authorized, $currencyIso),
            'captured' => EpicHelper::fromMinorUnits($captured, $currencyIso),
            'refunded' => EpicHelper::fromMinorUnits($refunded, $currencyIso),
            'capturable' => EpicHelper::fromMinorUnits($capturable, $currencyIso),
            'refundable' => EpicHelper::fromMinorUnits($refundable, $currencyIso),
        );
    }

    private function isConfigured()
    {
        return Configuration::get('EPAY_EPIC_API_KEY') && Configuration::get('EPAY_EPIC_POINT_OF_SALE_ID');
    }

    private function checkCurrency($cart)
    {
        $currencyOrder = new Currency((int)$cart->id_currency);
        $currenciesModule = $this->getCurrency($cart->id_currency);
        if (is_array($currenciesModule)) {
            foreach ($currenciesModule as $currency) {
                if ($currency['id_currency'] == $currencyOrder->id) {
                    return true;
                }
            }
        }
        return false;
    }

    private function addPaymentFeeProduct(Cart $cart, $feeIncludingTax)
    {
        $feeProductId = (int) Configuration::get('EPAY_EPIC_FEE_PRODUCT_ID');
        if ($feeProductId <= 0 || $feeIncludingTax <= 0) {
            return false;
        }

        $product = new Product($feeProductId, false, (int) $cart->id_lang, (int) $cart->id_shop);
        if (!Validate::isLoadedObject($product)) {
            throw new Exception('The configured payment fee product could not be loaded.');
        }

        $this->removePaymentFeeProduct($cart, $feeProductId);

        $specificPrice = new SpecificPrice();
        $specificPrice->id_shop = (int) $cart->id_shop;
        $specificPrice->id_shop_group = 0;
        $specificPrice->id_currency = (int) $cart->id_currency;
        $specificPrice->id_country = 0;
        $specificPrice->id_group = 0;
        $specificPrice->id_customer = 0;
        $specificPrice->id_product = $feeProductId;
        $specificPrice->id_product_attribute = 0;
        $specificPrice->id_cart = (int) $cart->id;
        $specificPrice->price = $this->getTaxExcludedFeeAmount($cart, $product, $feeIncludingTax);
        $specificPrice->from_quantity = 1;
        $specificPrice->reduction = 0;
        $specificPrice->reduction_type = 'amount';
        $specificPrice->from = '0000-00-00 00:00:00';
        $specificPrice->to = '0000-00-00 00:00:00';

        if (!$specificPrice->add()) {
            throw new Exception('Unable to set the payment fee price for the cart.');
        }

        if (!$cart->updateQty(1, $feeProductId, 0, false, 'up', (int) $cart->id_address_delivery)) {
            $specificPrice->delete();
            throw new Exception('Unable to add the payment fee product to the cart.');
        }

        return true;
    }

    private function removePaymentFeeProduct(Cart $cart, $feeProductId)
    {
        $cart->deleteProduct((int) $feeProductId, 0, 0, (int) $cart->id_address_delivery);

        Db::getInstance()->delete(
            'specific_price',
            'id_cart = ' . (int) $cart->id . ' AND id_product = ' . (int) $feeProductId
        );

        if (is_callable(array('SpecificPrice', 'flushCache'))) {
            SpecificPrice::flushCache();
        }
    }

    private function getTaxExcludedFeeAmount(Cart $cart, Product $product, $feeIncludingTax)
    {
        $addressId = (int) $cart->id_address_delivery;
        if (!$addressId) {
            $addressId = (int) $cart->id_address_invoice;
        }

        $address = new Address($addressId);
        $taxManager = TaxManagerFactory::getManager($address, (int) $product->id_tax_rules_group);
        $taxRate = (float) $taxManager->getTaxCalculator()->getTotalRate();

        return Tools::ps_round((float) $feeIncludingTax / (1 + ($taxRate / 100)), 6);
    }

    public function createPaymentSession(Cart $cart)
    {
        $currency = new Currency((int)$cart->id_currency);
        $customer = new Customer((int)$cart->id_customer);
        $invoiceAddress = new Address((int)$cart->id_address_invoice);
        $deliveryAddress = new Address((int)$cart->id_address_delivery);
        if (!Validate::isLoadedObject($deliveryAddress)) {
            $deliveryAddress = null;
        }

        $reference = $this->generateReference($cart);
        $amount = $cart->getOrderTotal(true, Cart::BOTH);
        $minorAmount = EpicHelper::toMinorUnits($amount, $currency->iso_code);


        if(Configuration::get('EPAY_EPIC_MINIMUMUSERAGE') > 0 && (Configuration::get('EPAY_EPIC_AGEVERIFICATIONMODE') == 1 || (Configuration::get('EPAY_EPIC_AGEVERIFICATIONMODE') == 2 && $this->getShippingCountryIso() == "DK")))
        {
            $ageVerificationMinimumAge = intval(Configuration::get('EPAY_EPIC_MINIMUMUSERAGE'));
        }

        if ($this->context->customer->isLogged()) {
            $customerId = (string)$cart->id_customer;
        } else {
            $customerId = null;
        }

        $payload = array(
            'pointOfSaleId' => Configuration::get('EPAY_EPIC_POINT_OF_SALE_ID'),
            'reference' => $reference,
            'amount' => $minorAmount,
            'currency' => $currency->iso_code,
            'successUrl' => preg_replace('/__transactionId__/', '${transaction.id}', $this->context->link->getModuleLink($this->name, 'accept', array('ref' => $reference, 'transactionId' => '__transactionId__'), true)),
            'failureUrl' => $this->context->link->getModuleLink($this->name, 'failure', array('ref' => $reference), true),
            'notificationUrl' => $this->context->link->getModuleLink($this->name, 'callback', array('ref' => $reference), true),
            'customerId' => $customerId,
            'customer' => EpicHelper::buildCustomerPayload($customer, $invoiceAddress, $deliveryAddress),
            'attributes' => array(
                'cart_id' => (string)$cart->id,
                'shop_id' => (string)$this->context->shop->id,
            ),
            'ageVerification' => array(
                'minimumAge' => $ageVerificationMinimumAge ?? 0,
                'country' => null,
            ),
        );

        $client = $this->getApiClient();

        $response = $client->createSession($payload, $reference);

        $session = $response['session'];
        $this->persistPayment(
            $cart,
            $reference,
            $session['id'],
            $minorAmount,
            $currency->iso_code
        );

        return $response;
    }

    private function persistPayment(Cart $cart, $reference, $sessionId, $amount, $currency)
    {
        $data = array(
            'id_order' => null,
            'id_cart' => (int)$cart->id,
            'id_shop' => (int)$cart->id_shop,
            'reference' => pSQL($reference),
            'session_id' => pSQL($sessionId),
            'transaction_id' => null,
            'state' => pSQL('pending'),
            'amount' => (int)$amount,
            'currency' => pSQL($currency),
            'payload' => null,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        );

        Db::getInstance()->insert(self::TABLE_PAYMENTS, $data);
    }

    public function updatePaymentState($reference, array $transaction, array $operations = array())
    {
        $payment = $this->findPaymentByReference($reference);
        if (!$payment) {
            return false;
        }

        $payload = json_encode(array(
            'transaction' => $transaction,
            'operations' => $operations,
        ));

        $data = array(
            'transaction_id' => pSQL($transaction['id']),
            'state' => pSQL(Tools::strtolower($transaction['state'])),
            'payload' => pSQL($payload, true),
            'date_upd' => date('Y-m-d H:i:s'),
        );

        return Db::getInstance()->update(
            self::TABLE_PAYMENTS,
            $data,
            'id_epayepic_payment = ' . (int)$payment['id_epayepic_payment']
        );
    }

    public function findPaymentByReference($reference)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE_PAYMENTS . '` WHERE reference = \'' . pSQL($reference) . '\'';
        return Db::getInstance()->getRow($sql);
    }

    public function findPaymentByOrderId($orderId)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE_PAYMENTS . '` WHERE id_order = ' . (int)$orderId;
        return Db::getInstance()->getRow($sql);
    }

    public function attachOrderToPayment($paymentId, $orderId)
    {
        return Db::getInstance()->update(
            self::TABLE_PAYMENTS,
            array(
                'id_order' => (int)$orderId,
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            'id_epayepic_payment = ' . (int)$paymentId
        );
    }

    public function generateReference(Cart $cart)
    {
        $base = sprintf('%06d', (int)$cart->id);
        return Tools::substr($base . '-' . Tools::strtoupper(Tools::passwdGen(8)), 0, 36);
    }

    public function getApiClient()
    {
        return new EpicApiClient(Configuration::get('EPAY_EPIC_API_KEY'));
    }

    public function finalizeOrder(array $transaction)
    {
        if ($transaction['state'] !== 'SUCCESS') {
            return false;
        }

        $reference = $transaction['reference'];
        $payment = $this->findPaymentByReference($reference);
        if (!$payment) {
            return false;
        }

        $cart = new Cart((int)$payment['id_cart']);
        if (!Validate::isLoadedObject($cart)) {
            return false;
        }

        $orderId = (int) $cart->orderExists();

        if (!$orderId) {

            $transactionFee = isset($transaction['fee']) ? (int) $transaction['fee'] : 0;
            $chargedTotal = EpicHelper::fromMinorUnits($transaction['amount'], $payment['currency']);
            $orderFee = EpicHelper::fromMinorUnits($transactionFee, $payment['currency']);
            $feeAddedToCart = $this->addPaymentFeeProduct($cart, $orderFee);
            $orderTotalAmount = $feeAddedToCart
                ? $cart->getOrderTotal(true, Cart::BOTH)
                : EpicHelper::fromMinorUnits($transaction['amount'] - $transactionFee, $payment['currency']);

            $paymentState = (int)Configuration::get('EPAY_EPIC_SUCCESS_STATE');

            $this->validateOrder(
                (int)$cart->id,
                $paymentState,
                $orderTotalAmount,
                $this->displayName,
                null,
                array(
                    'transaction_id' => $transaction['id'],
                    'card_brand' => isset($transaction['paymentInstrument']['brand']) ? $transaction['paymentInstrument']['brand'] : '',
                    'charged_total' => $chargedTotal,
                    'payment_fee' => $orderFee
                ),
                null,
                false,
                $cart->secure_key
            );

            $orderId = $this->currentOrder;
            $this->attachOrderToPayment($payment['id_epayepic_payment'], $orderId);

            $order = new Order($orderId);
            $payments = $order->getOrderPaymentCollection();

            if (count($payments) > 0) {
                /** @var OrderPayment $orderPayment */
                $orderPayment = $payments[0];

                $cardBrand   = $transaction['paymentMethodSubType'] ?? '';
                $cardLast4   = $transaction['paymentMethodDisplayText'] ?? '';
                $dt = new DateTime($transaction['paymentMethodExpiry']);
                $expYear  = $dt->format('Y');
                $expMonth = $dt->format('m');
                $cardHolder  = $transaction['paymentMethodHolderName'] ?? '';

                if ($cardLast4) {
                    $orderPayment->card_number = $cardLast4;
                }

                if ($cardBrand) {
                    $orderPayment->card_brand = $cardBrand;
                }

                if ($expMonth && $expYear) {
                    $orderPayment->card_expiration = sprintf('%02d/%02d', $expMonth, $expYear % 100);
                }

                if ($cardHolder) {
                    $orderPayment->card_holder = $cardHolder;
                }

                $orderPayment->update();
            }

        }

        return $orderId;
    }

    private function getShippingCountryIso()
    {
        $address = new Address($this->context->cart->id_address_delivery);
        $country = new Country($address->id_country);
        return $country->iso_code;
    }
}
