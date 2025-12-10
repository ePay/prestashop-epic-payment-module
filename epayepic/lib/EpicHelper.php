<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class EpicHelper
{
    public static function toMinorUnits($amount, $currencyIso)
    {
        $minorUnits = self::getCurrencyMinorunits($currencyIso);
        return (int)round($amount * pow(10, $minorUnits));
    }

    public static function fromMinorUnits($amount, $currencyIso)
    {
        $minorUnits = self::getCurrencyMinorunits($currencyIso);
        return (float)($amount / pow(10, $minorUnits));
    }

    public static function getCurrencyMinorunits($currencyCode)
    {
        $currencyCode = Tools::strtoupper($currencyCode);
        $zeroMinor = array('TTD','KMF','ADP','TPE','BIF','DJF','MGF','XPF','GNF','BYR','PYG','JPY','CLP','XAF','TRL','VUV','CLF','KRW','XOF','RWF');
        $threeMinor = array('IQD','TND','BHD','JOD','OMR','KWD','LYD');
        if (in_array($currencyCode, $zeroMinor)) {
            return 0;
        }
        if (in_array($currencyCode, $threeMinor)) {
            return 3;
        }
        return 2;
    }

    public static function buildAddressPayload(Address $address)
    {
        $country = new Country((int)$address->id_country);
        $countryIso = (Validate::isLoadedObject($country) && !empty($country->iso_code)) ? Tools::strtoupper($country->iso_code) : null;
        return array(
            'line1' => $address->address1 ?: null,
            'line2' => $address->address2 ?: null,
            'line3' => null,
            'postalCode' => $address->postcode ?: null,
            'city' => $address->city ?: null,
            'countryCode' => $countryIso,
        );
    }

    public static function buildCustomerPayload(Customer $customer, Address $invoiceAddress, Address $deliveryAddress = null)
    {
        $customerData = array(
            'firstName' => $customer->firstname ?: null,
            'lastName' => $customer->lastname ?: null,
            'email' => $customer->email ?: null,
            'phoneNumber' => self::formatPhone($invoiceAddress->phone_mobile ?: $invoiceAddress->phone),
            'billingAddress' => self::buildAddressPayload($invoiceAddress),
        );

        if ($deliveryAddress instanceof Address && Validate::isLoadedObject($deliveryAddress)) {
            $customerData['shippingAddress'] = self::buildAddressPayload($deliveryAddress);
        }

        return $customerData;
    }

    public static function formatPhone($phone)
    {
        if (!$phone) {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (!$digits) {
            return null;
        }
        if (Tools::strlen($digits) <= 3) {
            return null;
        }
        $countryCode = '+' . Tools::substr($digits, 0, 2);
        $number = Tools::substr($digits, 2);
        return trim($countryCode . ' ' . $number);
    }

    public static function log($message, array $context = array())
    {
        if (!Configuration::get('EPAY_EPIC_DEBUG')) {
            return;
        }
        PrestaShopLogger::addLog('[ePay Epic] ' . $message, 1, null, 'EpayEpic', 0, true);
    }
}
