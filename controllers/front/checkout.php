<?php

class MondidocheckoutCheckoutModuleFrontController extends ModuleFrontController
{
    public $display_column_left = false;
    public $display_column_right = false;
    public $ssl = true;

    public function setMedia()
    {
        parent::setMedia();

        $this->context->controller->addCSS($this->module->getPath(true) . 'views/css/checkout.css', 'all');
        $this->context->controller->addJS($this->module->getPath(true) . 'views/js/iframe-resizer/iframeResizer.min.js');
    }

    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;
        $payment_ref = Tools::passwdGen(9, 'NO_NUMERIC') . '_' . $cart->id;
        $products = $cart->getProducts();
        $currency = new Currency($cart->id_currency);
        $cart_details = $cart->getSummaryDetails(null, true);
        $total = number_format($cart->getOrderTotal(true, 3), 2, '.', '');
        $subtotal = number_format($cart_details['total_price_without_tax'], 2, '.', '');
        $vat_amount = $total - $subtotal;

        // Process Products
        $items = array();
        foreach ($products as $product) {
            $items[] = array(
                'artno' => $product['reference'],
                'description' => $product['name'],
                'amount' => $product['total_wt'],
                'qty' => $product['quantity'],
                'vat' => number_format($product['rate'], 2, '.', ''),
                'discount' => 0
            );
        }

        // Process Shipping
        $total_shipping_tax_incl = _PS_VERSION_ < '1.5' ? (float)$cart->getOrderShippingCost() : (float)$cart->getTotalShippingCost();
        if ($total_shipping_tax_incl > 0) {
            $carrier = new Carrier((int)$cart->id_carrier);
            $carrier_tax_rate = Tax::getCarrierTaxRate((int)$carrier->id, $cart->id_address_invoice);
            //$total_shipping_tax_excl = $total_shipping_tax_incl / (($carrier_tax_rate / 100) + 1);

            $items[] = array(
                'artno' => 'Shipping',
                'description' => $carrier->name,
                'amount' => $total_shipping_tax_incl,
                'qty' => 1,
                'vat' => number_format($carrier_tax_rate, 2, '.', ''),
                'discount' => 0
            );
        }

        // Process Discounts
        $total_discounts_tax_incl = (float)abs($cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS, $cart->getProducts(), (int)$cart->id_carrier));
        if ($total_discounts_tax_incl > 0) {
            $total_discounts_tax_excl = (float)abs($cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS, $cart->getProducts(), (int)$cart->id_carrier));
            $total_discounts_tax_rate = (($total_discounts_tax_incl / $total_discounts_tax_excl) - 1) * 100;

            $items[] = array(
                'artno' => 'Discount',
                'description' => $this->module->l('Discount'),
                'amount' => -1 * $total_discounts_tax_incl,
                'qty' => 1,
                'vat' => number_format($total_discounts_tax_rate, 2, '.', ''),
                'discount' => 0
            );
        }

        // Prepare Metadata
        $metadata = array(
            'customer_reference' => (int)$cart->id_customer,
            'products' => $items,
            'analytics' => array(),
            'platform' => array(
                'type' => 'prestashop',
                'version' => _PS_VERSION_,
                'language_version' => phpversion(),
                'plugin_version' => $this->module->version
            )
        );

        // Prepare Analytics
        if (isset($_COOKIE['m_ref_str'])) {
            $metadata['analytics']['referrer'] = $_COOKIE['m_ref_str'];
        }
        if (isset($_COOKIE['m_ad_code'])) {
            $metadata['analytics']['google'] = [];
            $metadata['analytics']['google']['ad_code'] = $_COOKIE['m_ad_code'];
        }

        // Prepare WebHook
        $webhook = array(
            'url' => $this->context->link->getModuleLink($this->module->name, 'transaction', array(),  Tools::usingSecureMode()),
            'trigger' => 'payment',
            'http_method' => 'post',
            'data_format' => 'json',
            'type' => 'CustomHttp'
        );

        // Prepare fields
        $fields = array(
            'amount' => $total,
            'vat_amount' => $vat_amount,
            'merchant_id' => $this->module->merchant_id,
            'currency' => strtolower($currency->iso_code),
            'customer_ref' => (int)$this->context->customer->id,
            'payment_ref' => $payment_ref,
            'success_url' => $this->context->link->getModuleLink($this->module->name, 'validation', array(), Tools::usingSecureMode()),
            'error_url' => $this->context->link->getModuleLink($this->module->name, 'error', array(), Tools::usingSecureMode()),
            'metadata' => $metadata,
            'test' => $this->module->test_mode === '1' ? 'true' : 'false',
            //'authorize'    => $this->module->authorize ? 'true' : '',
            'items' => $items,
            'webhook' => $webhook,
            'process' => 'false',
            'hash' => md5(sprintf(
                '%s%s%s%s%s%s%s',
                $this->module->merchant_id,
                $payment_ref,
                (int)$this->context->customer->id,
                $total,
                strtolower($currency->iso_code),
                $this->module->test_mode === '1' ? 'test' : '',
                $this->module->secret_code
            )),
        );

        try {
            $transaction = $this->module->api_request('POST', 'https://api.mondido.com/v1/transactions', $fields);
        } catch (Exception $e) {
            $this->context->smarty->assign(array(
                'message' => $e->getMessage(),
            ));
            return $this->setTemplate('error.tpl');
        }

        $this->module->addTransaction($transaction['id'], $transaction, $transaction['status']);
        $this->module->updateTransaction($transaction['id'], array(
            'id_cart' => $cart->id
        ));

        $this->context->smarty->assign(array(
            'iframe_url' => $transaction['href'],
        ));

        return $this->setTemplate('checkout.tpl');
    }
}