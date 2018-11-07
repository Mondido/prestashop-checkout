<?php

class MondidocheckoutValidationModuleFrontController extends ModuleFrontController
{
    public $display_column_left = false;
    public $display_column_right = false;
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        try {
            $transaction_id = Tools::getValue('transaction_id');
            if (empty($transaction_id)) {
                throw new Exception('Invalid transaction ID');
            }

            $payment_ref = Tools::getValue('payment_ref');
            $status = Tools::getValue('status');
            $cart = $this->context->cart;
            $currency  = new Currency($cart->id_currency);

            // Lookup transaction
            $transaction_data = $this->module->api_request('GET', 'https://api.mondido.com/v1/transactions/' . $transaction_id);

            // Verify hash
            $total = number_format($cart->getOrderTotal(true, 3), 2, '.', '');
            $hash = md5(sprintf('%s%s%s%s%s%s%s',
                $this->module->merchant_id,
                $payment_ref,
                (int) $cart->id_customer,
                $total,
                strtolower($currency->iso_code),
                $status,
                $this->module->secret_code
            ));
            if ($hash !== Tools::getValue('hash')) {
                throw new Exception('Hash verification failed');
            }

            // Lookup Order
            $order_id = $this->module->getOrderByCartId((int)$cart->id);
            if ($order_id === false) {
                $order_id = $this->module->placeOrder(
                    (int)$cart->id,
                    Configuration::get('PS_OS_MONDIDOPAY_PENDING'),
                    $transaction_data
                );

                // Update Transaction
                $this->module->updateTransaction($transaction_data['id'], array(
                    'id_order' => $order_id,
                    'transaction_data' => json_encode($transaction_data),
                    'status' => $status
                ));
            }

            $order = new Order($order_id);
            $redirectUrl = $this->context->link->getModuleLink($this->module->name, 'confirmation', array('key' => $order->secure_key, 'id_cart' => (int)$cart->id, 'id_module' => (int)$this->module->id, 'id_order' => (int)$order->id), Tools::usingSecureMode());
            Tools::redirectLink($this->module->getRedirectUrl($redirectUrl));
        } catch (Exception $e) {
            $this->context->smarty->assign(array(
                'message' => $e->getMessage(),
            ));
            return $this->setTemplate('error.tpl');
        }
    }
}
