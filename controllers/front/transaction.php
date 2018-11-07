<?php

class MondidocheckoutTransactionModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        try {
            $raw_body = file_get_contents( 'php://input' );
            $data = @json_decode( $raw_body, TRUE );
            if (!$data ) {
                throw new Exception( 'Invalid data' );
            }

            if (empty($data['id'])) {
                throw new Exception( 'Invalid transaction ID' );
            }

            // Prevent duplicate requests
            if (Cache::getInstance()->exists('mondidocheckout_' . md5($raw_body))) {
                http_response_code(200);
                $this->log("Payment confirmation rejected (duplicate request). Transaction ID: {$data['id']}");
                exit("Payment confirmation rejected (duplicate request). Transaction ID: {$data['id']}");
            }
            Cache::getInstance()->set('mondidocheckout_' . md5($raw_body), '1', 2 * 60 * 60);

            // Log transaction details
            $this->log('Incoming Transaction: ' . var_export(json_encode($data, true), true));

            // Lookup transaction
            $transaction_data = $this->module->api_request('GET', 'https://api.mondido.com/v1/transactions/' . $data['id']);
            if (!$transaction_data) {
                throw new Exception('Error: Failed to verify transaction');
            }

            $transaction_id = $data['id'];
            $payment_ref = $data['payment_ref'];
            $status = $data['status'];

            // Extract Cart ID
            $matches = array();
            preg_match('/(\d+)/m', $payment_ref, $matches);
            if (!isset($matches[0])) {
                throw new Exception('Failed to get Cart ID');
            }

            $cart = new Cart((int) $matches[0]);
            if (!Validate::isLoadedObject($cart)) {
                throw new Exception('Error: Failed to load cart with ID ' . $matches[0]);
            }
            $currency =  new Currency((int)$cart->id_currency);

            // Verify hash
            $hash = md5(sprintf('%s%s%s%s%s%s%s',
                $this->module->merchant_id,
                $payment_ref,
                $transaction_data['metadata']['customer_reference'],
                number_format($data['amount'], 2, '.', ''),
                strtolower($currency->iso_code),
                $status,
                $this->module->secret_code
            ));
            if ($hash !== $data['response_hash']) {
                throw new Exception('Hash verification failed');
            }

            // Wait for order placement by customer
            @set_time_limit(0);
            $times = 0;
            $order_id = $this->module->getOrderByCartId($cart->id);
            while (!$order_id) {
                $times++;
                if ($times > 6) {
                    break;
                }
                sleep(10);

                // Lookup Order
                $order_id = $this->module->getOrderByCartId($cart->id);
            }

            // Place order if not placed
            if (!$order_id) {
                $order_id = $this->module->placeOrder(
                    $cart->id,
                    Configuration::get('PS_OS_MONDIDOPAY_PENDING'),
                    $transaction_data
                );
            }

            // Update Order status
            $statuses = array(
                'pending' => Configuration::get('PS_OS_MONDIDOPAY_PENDING'),
                'approved' => Configuration::get('PS_OS_MONDIDOPAY_APPROVED'),
                'authorized' => Configuration::get('PS_OS_MONDIDOPAY_AUTHORIZED'),
                'declined' => Configuration::get('PS_OS_MONDIDOPAY_DECLINED'),
                'failed' => Configuration::get('PS_OS_ERROR')
            );
            $id_order_state = $statuses[$status];

            $order = new Order($order_id);
            if ((int)$order->current_state !== (int)$id_order_state) {
                // Set the order status
                $new_history = new OrderHistory();
                $new_history->id_order = (int)$order->id;
                $new_history->changeIdOrderState((int)$id_order_state, $order, true);
                $new_history->addWithemail(true);

                if (in_array($status, array('approved', 'authorized'))) {
                    $this->module->confirmOrder($order_id, $transaction_data);
                }
            }

            // Update Transaction
            $this->module->updateTransaction($transaction_data['id'], array(
                'id_order' => $order_id,
                'transaction_data' => json_encode($transaction_data),
                'status' => $status
            ));

            http_response_code(200);
            $this->log("Order was placed by WebHook. Order ID: {$order->id}. Transaction status: {$transaction_data['status']}");
            exit('OK');
        } catch (Exception $e) {
            http_response_code(400);
            $this->log('Error: ' . $e->getMessage());
            exit('Error: ' . $e->getMessage());
        }
    }

    /**
     * Debug log
     * @param $message
     */
    public function log($message)
    {
        $file = _PS_ROOT_DIR_ . '/log/mondidocheckout.log';
        if (!is_string($message)) {
            $message = var_export($message, true);
        }

        file_put_contents($file, date('Y/m/d - H:i:s') . ': '. $message . "\r\n", FILE_APPEND);
    }
}
