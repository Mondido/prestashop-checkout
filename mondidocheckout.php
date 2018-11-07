<?php
/**
 * Mondido Payment Module for PrestaShop
 * @author    Mondido
 * @copyright 2018 Mondido
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * @link https://www.mondido.com
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (!class_exists('\League\ISO3166\ISO3166', false)) {
    require_once dirname(__FILE__) . '/vendors/iso3166/vendor/autoload.php';
}

class Mondidocheckout extends PaymentModule
{
    protected $_errors = array();

    protected $_postErrors = array();

    /**
     * Is Active
     * @var string
     */
    public $is_active = '1';

    /**
     * Merchant ID
     * @var string
     */
    public $merchant_id;

    /**
     * Merchant Password
     * @var string
     */
    public $password;

    /**
     * Merchant Secret Code
     * @var string
     */
    public $secret_code;

    /**
     * Test Mode
     * @var string
     */
    public $test_mode = '1';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->name = 'mondidocheckout';
        $this->displayName = $this->l('Mondido Checkout');
        $this->description = $this->l('Checkout module by Mondido');
        $this->author = 'Mondido';
        $this->version = '1.0.0';
        $this->tab = 'payments_gateways';
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
        $this->need_instance = 1;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        // Init Configuration
        $config = Configuration::getMultiple(array(
            'MONDIDO_CHECKOUT_ACTIVE',
            'MONDIDO_CHECKOUT_MERCHANTID',
            'MONDIDO_CHECKOUT_PASSWORD',
            'MONDIDO_CHECKOUT_SECRET',
            'MONDIDO_CHECKOUT_TEST_MODE'
        ));
        $this->is_active = isset($config['MONDIDO_CHECKOUT_ACTIVE']) ? $config['MONDIDO_CHECKOUT_ACTIVE'] : '1';
        $this->merchant_id = isset($config['MONDIDO_CHECKOUT_MERCHANTID']) ? $config['MONDIDO_CHECKOUT_MERCHANTID'] : '';
        $this->password = isset($config['MONDIDO_CHECKOUT_PASSWORD']) ? $config['MONDIDO_CHECKOUT_PASSWORD'] : '';
        $this->secret_code = isset($config['MONDIDO_CHECKOUT_SECRET']) ? $config['MONDIDO_CHECKOUT_SECRET'] : '';
        $this->test_mode = isset($config['MONDIDO_CHECKOUT_TEST_MODE']) ? $config['MONDIDO_CHECKOUT_TEST_MODE'] : '1';

        parent::__construct();

        if (empty($this->merchant_id) || empty($this->password) || empty($this->secret_code)) {
            $this->warning = $this->l('Please configure module');
        }
    }

    /**
     * Install Hook
     * @return bool
     */
    public function install()
    {
        // Install DB Tables
        $this->installDbTables();

        // Install Order statuses
        $this->addOrderStates();

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('DisplayHeader') &&
            $this->registerHook('adminOrder') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('payment') &&
            $this->registerHook('paymentReturn') &&
            $this->registerHook('actionPaymentConfirmation') &&
            $this->registerHook('displayPayment');
    }

    /**
     * UnInstall Hook
     * @return bool
     */
    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }

        Configuration::deleteByName('MONDIDO_CHECKOUT_ACTIVE');
        Configuration::deleteByName('MONDIDO_CHECKOUT_MERCHANTID');
        Configuration::deleteByName('MONDIDO_CHECKOUT_PASSWORD');
        Configuration::deleteByName('MONDIDO_CHECKOUT_SECRET');
        Configuration::deleteByName('MONDIDO_CHECKOUT_TEST_MODE');

        return true;
    }

    /**
     * Install DB Tables
     */
    private function installDbTables()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "mondido_transactions` (
  `id_order` int(11) DEFAULT NULL,
  `id_cart` int(11) DEFAULT NULL,
  `transaction_id` int(11) NOT NULL,
  `transaction_data` text NOT NULL,
  `status` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`transaction_id`),
  KEY `id_order` (`id_order`),
  KEY `id_cart` (`id_cart`)
) ENGINE=InnoDB DEFAULT CHARSET=utf-8
" . (_PS_VERSION_ < '1.5' ? '' : Shop::addSqlRestriction());

        Db::getInstance()->execute($sql);
    }

    /**
     * Add Order Statuses
     */
    private function addOrderStates()
    {
        // Pending
        if (!(Configuration::get('PS_OS_MONDIDOPAY_PENDING') > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = 'Mondido: Pending';
            $OrderState->invoice = false;
            $OrderState->send_email = true;
            $OrderState->module_name = $this->name;
            $OrderState->color = '#4169E1';
            $OrderState->unremovable = true;
            $OrderState->hidden = false;
            $OrderState->logable = true;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = false;
            $OrderState->deleted = false;
            $OrderState->template = 'preparation';
            $OrderState->add();

            Configuration::updateValue('PS_OS_MONDIDOPAY_PENDING', $OrderState->id);

            if (file_exists(dirname(dirname(dirname(__FILE__))) . '/img/os/9.gif')) {
                @copy(dirname(dirname(dirname(__FILE__))) . '/img/os/9.gif',
                    dirname(dirname(dirname(__FILE__))) . '/img/os/' . $OrderState->id . '.gif');
            }
        }

        // Authorized
        if (!(Configuration::get('PS_OS_MONDIDOPAY_AUTHORIZED') > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = 'Mondido: Authorized';
            $OrderState->invoice = false;
            $OrderState->send_email = true;
            $OrderState->module_name = $this->name;
            $OrderState->color = '#FF8C00';
            $OrderState->unremovable = true;
            $OrderState->hidden = false;
            $OrderState->logable = true;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = false;
            $OrderState->deleted = false;
            $OrderState->template = 'order_changed';
            $OrderState->add();

            Configuration::updateValue('PS_OS_MONDIDOPAY_AUTHORIZED', $OrderState->id);

            if (file_exists(dirname(dirname(dirname(__FILE__))) . '/img/os/10.gif')) {
                @copy(dirname(dirname(dirname(__FILE__))) . '/img/os/10.gif',
                    dirname(dirname(dirname(__FILE__))) . '/img/os/' . $OrderState->id . '.gif');
            }
        }

        // Approved
        if (!(Configuration::get('PS_OS_MONDIDOPAY_APPROVED') > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = 'Mondido: Approved';
            $OrderState->invoice = true;
            $OrderState->send_email = true;
            $OrderState->module_name = $this->name;
            $OrderState->color = '#32CD32';
            $OrderState->unremovable = true;
            $OrderState->hidden = false;
            $OrderState->logable = true;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = true;
            $OrderState->deleted = false;
            $OrderState->template = 'payment';
            $OrderState->add();

            Configuration::updateValue('PS_OS_MONDIDOPAY_APPROVED', $OrderState->id);

            if (file_exists(dirname(dirname(dirname(__FILE__))) . '/img/os/10.gif')) {
                @copy(dirname(dirname(dirname(__FILE__))) . '/img/os/10.gif',
                    dirname(dirname(dirname(__FILE__))) . '/img/os/' . $OrderState->id . '.gif');
            }
        }

        // Declined
        if (!(Configuration::get('PS_OS_MONDIDOPAY_DECLINED') > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = 'Mondido: Declined';
            $OrderState->invoice = false;
            $OrderState->send_email = true;
            $OrderState->module_name = $this->name;
            $OrderState->color = '#DC143C';
            $OrderState->unremovable = true;
            $OrderState->hidden = false;
            $OrderState->logable = true;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = false;
            $OrderState->deleted = false;
            $OrderState->template = 'payment_error';
            $OrderState->add();

            Configuration::updateValue('PS_OS_MONDIDOPAY_DECLINED', $OrderState->id);

            if (file_exists(dirname(dirname(dirname(__FILE__))) . '/img/os/9.gif')) {
                @copy(dirname(dirname(dirname(__FILE__))) . '/img/os/9.gif',
                    dirname(dirname(dirname(__FILE__))) . '/img/os/' . $OrderState->id . '.gif');
            }
        }
    }

    /**
     * Configuration Form: Validation
     */
    private function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            if (!Tools::getValue('merchant_id')) {
                $this->_postErrors[] = $this->l('Merchant ID field required.');
            }

            if (!Tools::getValue('secret_code')) {
                $this->_postErrors[] = $this->l('Secret Code field required.');
            }

            if (!Tools::getValue('password')) {
                $this->_postErrors[] = $this->l('API Password field required.');
            }
        }
    }

    /**
     * Configuration Form: Save Settings
     */
    private function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('MONDIDO_CHECKOUT_MERCHANTID', Tools::getValue('merchant_id'));
            Configuration::updateValue('MONDIDO_CHECKOUT_SECRET', Tools::getValue('secret_code'));
            Configuration::updateValue('MONDIDO_CHECKOUT_PASSWORD', Tools::getValue('password'));
            Configuration::updateValue('MONDIDO_CHECKOUT_TEST_MODE', Tools::getValue('test_mode'));
            Configuration::updateValue('MONDIDO_CHECKOUT_ACTIVE', Tools::getValue('is_active'));
        }
        $this->_html .= '<div class="conf confirm"> ' . $this->l('Settings updated') . '</div>';
    }

    /**
     * Configuration Form
     * @return string
     */
    private function _displayForm()
    {
        $html = '<img src="../modules/mondidocheckout/logo.png" style="float:left; margin-right:15px;" width="64" height="64"><b>'
            . $this->l('Mondido, Simple payments, Smart functions') . '</b><br /><br />'
            . html_entity_decode(sprintf(
                $this->l('Please go to <a href="%s" target="_blank">%s</a> to sign up and get hold of your account information that you need to enter here.'),
                'https://admin.mondido.com',
                'https://admin.mondido.com'
            )) . '<br />'
            . $this->l('Do not hesitate to contact support@mondido.com if you have any questions setting up your PrestaShop payment plugin.') . '<br />'
            . html_entity_decode(sprintf(
                $this->l('All settings below can be found at this location: <a href="%s" target="_blank">%s</a> after you have logged in.'),
                'https://admin.mondido.com/en/settings',
                'https://admin.mondido.com/en/settings'
            )) . '<br /><br /><br />';

        $html .=
            '<form action="' . Tools::htmlentitiesUTF8($_SERVER['REQUEST_URI']) . '" method="post">
			<fieldset>
			<legend><img src="../img/admin/contact.gif" />' . $this->l('Settings') . '</legend>
				<table border="0" width="500" cellpadding="0" cellspacing="0" id="form">
					<tr>
					<td colspan="2">' . $this->l('Please specify Mondido account details.') . '<br /><br />
					</td>
					</tr>
					<tr>
					    <td>' . $this->l('Merchant ID') . '</td>
					    <td><input type="text" name="merchant_id" value="' . htmlentities(Tools::getValue('merchant_id',
                $this->merchant_id), ENT_COMPAT, 'UTF-8') . '" /></td>
					</tr>
					<tr>
						<td>' . $this->l('Secret') . '</td>
						<td><input type="text" name="secret_code" value="' . htmlentities(Tools::getValue('secret_code',
                $this->secret_code), ENT_COMPAT, 'UTF-8') . '" /></td>
					</tr>
					<tr>
						<td>' . $this->l('API Password') . '</td>
						<td><input type="text" name="password" value="' . htmlentities(Tools::getValue('password',
                $this->password), ENT_COMPAT, 'UTF-8') . '" /></td>
					</tr>
					<tr>
						<td>' . $this->l('Mode') . '</td>
						<td>
							<select name="test_mode">
                                <option ' . (Tools::getValue('test_mode',
                $this->test_mode) == '1' ? 'selected="selected"' : '') . 'value="1">Test</option>
                                <option ' . (Tools::getValue('test_mode',
                $this->test_mode) == '0' ? 'selected="selected"' : '') . 'value="0">Production</option>
                            </select>
						</td>
					</tr>
					<tr>
						<td>' . $this->l('Use Mondido Checkout') . '</td>
						<td>
							<select name="is_active">
                                <option ' . (Tools::getValue('is_active',
                $this->is_active) == '1' ? 'selected="selected"' : '') . 'value="1">Enable</option>
                                <option ' . (Tools::getValue('is_active',
                $this->is_active) == '0' ? 'selected="selected"' : '') . 'value="0">Disable</option>
                            </select>
						</td>
					</tr>
					<tr><td colspan="2" align="center"><input class="button" name="btnSubmit" value="' . $this->l('Update settings') . '" type="submit" /></td></tr>
				</table>
			</fieldset>
		</form>';

        return $html;
    }

    /**
     * Module Settings
     * @return string
     */
    public function getContent()
    {
        $html = '<h2>' . $this->displayName . '</h2>';

        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $html .= '<div class="alert error">' . $err . '</div>';
                }
            }
        } else {
            $html .= '<br />';
        }

        $html .= $this->_displayForm();

        return $html;
    }

    /**
     * Hook: Header
     */
    public function hookHeader()
    {
        $this->context->controller->addCSS($this->_path . 'views/css/checkout.css', 'all');

        if ((bool)(Configuration::get('MONDIDO_CHECKOUT_ACTIVE'))) {
            Media::addJsDef(array(
                'mondido_checkout_url' => $this->context->link->getModuleLink($this->name, 'checkout', array(),
                    Tools::usingSecureMode())
            ));
            $this->context->controller->addJS($this->getPath(true) . 'views/js/mondidocheckout.js');
        }
    }

    /**
     * Hook: Payment
     * Render payment method in payment methods list
     * @param $params
     */
    public function hookPayment($params)
    {
        if (!$this->active) {
            return;
        }

        $this->smarty->assign(array(
            'payment_url' => $this->context->link->getModuleLink($this->name, 'checkout', array(),
                Tools::usingSecureMode()),
            'this_path' => $this->_path,
            'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/',
        ));

        return $this->display(__FILE__, 'payment.tpl');
    }

    /**
     * Hook: Payment Return
     * @param $params
     * @return bool
     */
    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $message = '';
        $order = $params['objOrder'];
        switch ($order->current_state) {
            case Configuration::get('PS_OS_MONDIDOPAY_APPROVED'):
                $status = 'ok';
                break;
            case Configuration::get('PS_OS_MONDIDOPAY_PENDING');
            case Configuration::get('PS_OS_MONDIDOPAY_AUTHORIZED'):
                $status = 'pending';
                break;
            case Configuration::get('PS_OS_MONDIDOPAY_DECLINED'):
                $status = 'declined';
                $message = $this->l('Payment declined');
                break;
            case Configuration::get('PS_OS_ERROR'):
                $status = 'error';
                $message = $this->l('Payment error');
                break;
            default:
                $status = 'error';
                $message = $this->l('Order error');
        }

        $this->smarty->assign(array(
            'message' => $message,
            'status' => $status,
            'id_order' => $order->id
        ));

        if (property_exists($order, 'reference') && !empty($order->reference)) {
            $this->smarty->assign('reference', $order->reference);
        }

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    /**
     * Get an order by its cart id
     *
     * @param integer $id_cart Cart id
     * @return array Order details
     */
    public function getOrderByCartId($id_cart)
    {
        $sql = 'SELECT `id_order`
				FROM `' . _DB_PREFIX_ . 'orders`
				WHERE `id_cart` = ' . (int)($id_cart)
            . (_PS_VERSION_ < '1.5' ? '' : Shop::addSqlRestriction());
        $result = Db::getInstance()->getRow($sql, false);

        return isset($result['id_order']) ? $result['id_order'] : false;
    }

    /**
     * Get Shop Domain
     * @return string
     */
    public function getShopDomain()
    {
        if (Tools::usingSecureMode()) {
            return Tools::getShopDomainSsl(true);
        }

        return Tools::getShopDomain(true);
    }

    /**
     * Get Module Path
     * @param bool $secure
     *
     * @return string
     */
    public function getPath($secure = false)
    {
        if ($secure) {
            return Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/';
        }

        return $this->_path;
    }

    /**
     * Do API Request
     * @param string $method
     * @param string $url
     * @param array $params
     * @return mixed
     * @throws Exception
     */
    public function api_request($method, $url, $params = array())
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Content-Type: application/json'
            ),
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => "{$this->merchant_id}:{$this->password}"
        ));

        if (count($params) > 0) {
            $data = json_encode($params);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        $code = (int)($info['http_code'] / 100);

        switch ($code) {
            case 0:
                $error = curl_error($ch);
                $errno = curl_errno($ch);
                throw new Exception(sprintf('Error: %s. Code: %s.', $error, $errno));
            case 1:
                throw new Exception(sprintf('Invalid HTTP Code: %s', $info['http_code']));
            case 2:
            case 3:
                return json_decode($body, true);
            case 4:
            case 5:
                throw new Exception(sprintf('API Error: %s. HTTP Code: %s', $body, $info['http_code']));
            default:
                throw new Exception(sprintf('Invalid HTTP Code: %s', $info['http_code']));
        }
    }

    /**
     * Get URL for Redirect
     * @param $url
     * @return mixed
     */
    public function getRedirectUrl($url)
    {
        return $this->context->link->getModuleLink($this->name, 'redirect', array('goto' => $url),
            Tools::usingSecureMode());
    }

    /**
     * Confirm placed order
     * @param $order_id
     * @param $transaction_data
     * @return void
     */
    public function confirmOrder($order_id, $transaction_data)
    {
        $order = new Order($order_id);
        //TODO: update delivery address if it is the case
        if ($transaction_data['transaction_type'] === 'invoice') {
            $pd = $transaction_data['payment_details'];

            $shipping_address = new Address((int)$order->id_address_invoice);
            if (!empty($pd['phone'])) {
                $shipping_address->phone = $pd['phone'];
            }
            if (!empty($pd['last_name'])) {
                $shipping_address->lastname = $pd['last_name'];
            }
            if (!empty($pd['first_name'])) {
                $shipping_address->firstname = $pd['first_name'];
            }
            if (!empty($pd['address_1'])) {
                $shipping_address->address1 = $pd['address_1'];
            }
            if (!empty($pd['address_2'])) {
                $shipping_address->address2 = $pd['address_2'];
            }
            if (!empty($pd['city'])) {
                $shipping_address->city = $pd['city'];
            }
            if (!empty($pd['zip'])) {
                $shipping_address->postcode = $pd['zip'];
            }
            if (!empty($pd['country_code'])) {
                $shipping_address->country = $pd['country_code'];
            }

            $shipping_address->update();
        }

        if (_PS_VERSION_ >= '1.5') {
            $payments = $order->getOrderPaymentCollection();
            if ($payments->count() > 0) {
                $payments[0]->transaction_id = $transaction_data['id'];
                $payments[0]->card_number = $transaction_data['card_number'];
                $payments[0]->card_holder = $transaction_data['card_holder'];
                $payments[0]->card_brand = $transaction_data['card_type'];
                $payments[0]->payment_method = $transaction_data['transaction_type'];
                $payments[0]->update();
            }
        }
    }

    /**
     * Place Order with Custom Checkout
     * @param $id_cart
     * @param $status
     * @param $transaction_data
     * @return mixed
     */
    public function placeOrder($id_cart, $status, $transaction_data)
    {
        $cart = new Cart($id_cart);

        $payment_details = $transaction_data['payment_details'];
        $email = $payment_details['email'];
        $first_name = $payment_details['first_name'];
        $last_name = $payment_details['last_name'];
        $address_1 = $payment_details['address_1'];
        $address_2 = $payment_details['address_2'];
        $city = $payment_details['city'];
        $postcode = $payment_details['zip'];
        $country_code = (new League\ISO3166\ISO3166)->alpha3($payment_details['country_code'])['alpha2'];
        $phone = $payment_details['phone'];

        // Get Customer
        if ($cart->id_customer > 0) {
            $customer = new Customer($cart->id_customer);
        } else {
            // Check Customer by E-Mail
            $id_customer = (int)Customer::customerExists($email, true, true);
            if ($id_customer > 0) {
                $customer = new Customer($id_customer);
            } else {
                // Create Customer
                $password = Tools::passwdGen(8);
                $customer = new Customer();
                $customer->firstname = $first_name;
                $customer->lastname = $last_name;
                $customer->email = $email;
                $customer->passwd = Tools::encrypt($password);
                $customer->is_guest = 0;
                $customer->id_default_group = (int)Configuration::get('PS_CUSTOMER_GROUP', null, $cart->id_shop);
                $customer->newsletter = 1;
                $customer->optin = 0;
                $customer->active = 1;
                $customer->id_gender = 9;
                $customer->add();
                $this->sendConfirmationMail($customer, $cart->id_lang, $password);
            }
        }

        // Check existing address
        $id_country = (string)Country::getByIso($country_code);
        foreach ($customer->getAddresses($cart->id_lang) as $address) {
            if ($address['firstname'] === $first_name
                && $address['lastname'] === $last_name
                && $address['city'] === $city
                && $address['address1'] === $address_1
                && $address['address2'] === $address_2
                && $address['postcode'] === $postcode
                && $address['phone_mobile'] === $phone
                && $address['id_country'] === $id_country) {
                // Set Address
                $cart->id_address_invoice = $address['id_address'];
                $cart->id_address_delivery = $address['id_address'];

                break;
            }
        }

        // Save Address if don't exists
        if (!$cart->id_address_invoice) {
            $address = new Address();
            $address->firstname = $first_name;
            $address->lastname = $last_name;
            $address->address1 = $address_1;
            $address->address2 = $address_2;
            $address->postcode = $postcode;
            $address->phone_mobile = $phone;
            $address->city = $city;
            $address->id_country = $id_country;
            $address->id_customer = $customer->id;
            $address->alias = $this->l('My address');
            $address->add();

            // Set Address
            $cart->id_address_invoice = $address->id;
            $cart->id_address_delivery = $address->id;
        }

        // Update cart
        $cart->getPackageList(true);
        $cart->getDeliveryOptionList(null, true);
        $cart->id_customer = $customer->id;
        $cart->secure_key = $customer->secure_key;
        $cart->delivery_option = '';
        $cart->save();

        $cart = new Cart($cart->id);
        $this->validateOrder(
            $cart->id,
            $status,
            $cart->getOrderTotal(true, 3),
            $this->displayName,
            null,
            array(),
            $cart->id_currency,
            false,
            $customer->secure_key
        );

        return $this->currentOrder;
    }

    /**
     * Add Transaction
     * @param $transaction_id
     * @param $transaction_data
     * @param string $status
     */
    public function addTransaction($transaction_id, $transaction_data, $status = '')
    {
        Db::getInstance()->insert(
            'mondido_transactions',
            array(
                'transaction_id' => (int)$transaction_id,
                'transaction_data' => json_encode($transaction_data),
                'status' => $status
            ),
            false,
            true,
            Db::ON_DUPLICATE_KEY
        );
    }

    /**
     * Update Transaction
     * @param $transaction_id
     * @param $fields
     */
    public function updateTransaction($transaction_id, $fields)
    {
        Db::getInstance()->update(
            'mondido_transactions',
            $fields,
            '`transaction_id` = ' . (int)$transaction_id
        );
    }

    /**
     * Get Transaction
     * @param $transaction_id
     * @return array|bool|null|object
     */
    public function getTransaction($transaction_id)
    {
        $sql = 'SELECT *
				FROM `' . _DB_PREFIX_ . 'mondido_transactions`
				WHERE `transaction_id` = ' . (int)$transaction_id
            . (_PS_VERSION_ < '1.5' ? '' : Shop::addSqlRestriction());
        return Db::getInstance()->getRow($sql, false);
    }
}
