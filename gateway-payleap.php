<?php
/**
 * @wordpress-plugin
 * Plugin Name: 		Woo Payments with PayLeap
 * Plugin URI: 			http://woo.usbswiper.com/product/woo-payment-processing-payleap/
 * Description: 		Process credit cards in WooCommerce with a PayLeap merchant account.
 * Version: 			1.0.0
 * Author: 				USBSwiper
 * Author URI: 			http://woo.usbswiper.com
 *
 * ************
 * Attribution
 * ************
 * Woo Payment Processing - PayLeap is a derivative work of the code from WooThemes / WebDevStudios,
 * which is licensed with GPLv3.  This code is also licensed under the terms
 * of the GNU Public License, version 3.
 */
add_action('plugins_loaded', 'woocommerce_payleap_init', 0);

function woocommerce_payleap_init() {

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    load_plugin_textdomain('wc_usbswiper_payleap', false, dirname(plugin_basename(__FILE__)) . '/languages');

    /**
     * Gateway class
     * */
    class WC_Gateway_USBSwiper_PayLeap extends WC_Payment_Gateway {

        public $logger = false;

        function __construct() {

            // Setup our default vars
            $this->id = 'payleap';
            $this->method_title = __('PayLeap', 'wc_usbswiper_payleap');
            $this->method_description = __('PayLeap works by adding credit card fields on the checkout and then sending the details to PayLeap for verification and processing.', 'wc_usbswiper_payleap');
            $this->icon = plugins_url('/images/cards.png', __FILE__);
            $this->has_fields = true;
            $this->supports = array('products');


            // Load the form fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Get setting values
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = 'yes' === $this->get_option('enabled', 'no');
            $this->testmode = 'yes' === $this->get_option('testmode', 'no');

            if ($this->testmode) {
                $this->api_username = $this->get_option('test_api_username');
                $this->api_password = $this->get_option('test_api_password');
            } else {
                $this->api_username = $this->get_option('api_username');
                $this->api_password = $this->get_option('api_password');
            }

            $this->debug = 'yes' === $this->get_option('debug', 'no');


            // Setup API calls based on testmode status
            $this->api_url = $this->testmode == false ? 'https://secure1.payleap.com/TransactServices.svc/ProcessCreditCard' : 'https://uat.payleap.com/TransactServices.svc/ProcessCreditCard';


            // Hooks
            add_action('admin_notices', array(&$this, 'checks'));
            add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
        }

        /**
         * Check if SSL is enabled and notify the user
         * */
        function checks() {
            if ($this->enabled == false) {
                return;
            }
            // Check testmode
            // Check required fields
            if (empty($this->api_username)) {
                echo '<div class="error"><p>' . sprintf(__('PayLeap error: Please enter your API Username <a href="%s">here</a>', 'wc_usbswiper_payleap'), admin_url('admin.php?page=woocommerce&tab=payment_gateways&subtab=gateway-payleap')) . '</p></div>';
                return;
            } elseif (empty($this->api_password)) {
                echo '<div class="error"><p>' . sprintf(__('PayLeap error: Please enter your API Password <a href="%s">here</a>', 'wc_usbswiper_payleap'), admin_url('admin.php?page=woocommerce&tab=payment_gateways&subtab=gateway-payleap')) . '</p></div>';
                return;
            }
            if ($this->testmode == false) {
                // Show message if enabled and FORCE SSL is disabled and WordpressHTTPS plugin is not detected
                if (get_option('woocommerce_force_ssl_checkout') == false && !class_exists('WordPressHTTPS')) {
                    echo '<div class="error"><p>' . sprintf(__('PayLeap is enabled, but the <a href="%s">force SSL option</a> is disabled; your checkout may not be secure! Please enable SSL and ensure your server has a valid SSL certificate - Until then, PayLeap will only work in test mode.', 'wc_usbswiper_payleap'), admin_url('admin.php?page=woocommerce')) . '</p></div>';
                }
            }
        }

        /**
         * Check if this gateway is enabled and available in the user's country
         */
        function is_available() {
            if ($this->enabled == true) {
                // Currency check
                if (!in_array(get_option('woocommerce_currency'), array('USD'))) {
                    return false;
                }
                // Required fields check
                if (empty($this->api_username) || empty($this->api_password)) {
                    return false;
                }
                if ($this->testmode == false) {
                    if (!is_ssl()) {
                        return false;
                    }
                }
                return true;
            }
            return false;
        }

        /**
         * Initialise Gateway Settings Form Fields
         */
        function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc_usbswiper_payleap'),
                    'label' => __('Enable PayLeap', 'wc_usbswiper_payleap'),
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title', 'wc_usbswiper_payleap'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'wc_usbswiper_payleap'),
                    'default' => __('Credit Card (PayLeap)', 'wc_usbswiper_payleap')
                ),
                'description' => array(
                    'title' => __('Description', 'wc_usbswiper_payleap'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'wc_usbswiper_payleap'),
                    'default' => 'Pay with your credit card via PayLeap.'
                ),
                'testmode' => array(
                    'title' => __('Test mode', 'wc_usbswiper_payleap'),
                    'label' => __('Enable Test Mode', 'wc_usbswiper_payleap'),
                    'type' => 'checkbox',
                    'description' => __('Place the payment gateway in test mode using test account credentials.', 'wc_usbswiper_payleap'),
                    'default' => 'no'
                ),
                'test_api_username' => array(
                    'title' => __('Test API Username', 'wc_usbswiper_payleap'),
                    'type' => 'text',
                    'description' => sprintf(__('Note: You need to specifically request separate <a href="%s">testing credentials</a>.', 'wc_usbswiper_payleap'), 'https://uat.payleap.com/Merchant/Preferences/CreateTransactionKey.aspx'),
                    'default' => ''
                ),
                'test_api_password' => array(
                    'title' => __('Test API Transaction Key', 'wc_usbswiper_payleap'),
                    'type' => 'password',
                    'default' => ''
                ),
                'api_username' => array(
                    'title' => __('API Username', 'wc_usbswiper_payleap'),
                    'type' => 'text',
                    'description' => sprintf(__('Your API credentials can be located in your PayLeap <a href="%s">merchant interface.</a>', 'wc_usbswiper_payleap'), 'https://secure1.payleap.com/Merchant/Preferences/CreateTransactionKey.aspx'),
                    'default' => ''
                ),
                'api_password' => array(
                    'title' => __('API Transaction Key', 'wc_usbswiper_payleap'),
                    'type' => 'password',
                    'description' => __('Your API credentials can be located in your PayLeap merchant interface.', 'wc_usbswiper_payleap'),
                    'default' => ''
                ),
                'debug' => array(
                    'title' => __('Debug Log', 'wc_usbswiper_payleap'),
                    'label' => __('Enable Logging', 'wc_usbswiper_payleap'),
                    'type' => 'checkbox',
                    'description' => sprintf(__('Log PayLeap events inside <code>%s</code>', 'paypal-for-woocommerce'), wc_get_log_file_path('payleap')),
                    'default' => 'no'
                ),
            );
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         */
        function admin_options() {
            ?>
            <h3><?php echo $this->method_title; ?></h3>
            <p><?php echo wpautop($this->method_description); ?></p>
            <?php
            if ('USD' == get_woocommerce_currency()) {
                ?>
                <table class="form-table">
                    <?php $this->generate_settings_html(); ?>
                </table><!--/.form-table-->
                <?php
            } else {
                ?>
                <div class="inline error"><p><strong><?php _e('Gateway Disabled', 'wc_usbswiper_payleap'); ?></strong> <?php _e('Choose US Dollars as your store currency to enable the PayLeap gateway.', 'wc_usbswiper_payleap'); ?></p></div>
                <?php
            } // End check currency
        }

        /**
         * Payment form on checkout page
         */
        function payment_fields() {
            global $woocommerce;
            ?>
            <fieldset>

                <?php if ($this->description) : ?>
                    <p><?php echo $this->description; ?></p>
                    <p><?php if ($this->testmode == true) _e('TEST MODE ENABLED. In test mode, you can use the card number 4111111111111111 with any CCV and a future expiration date.', 'wc_usbswiper_payleap'); ?></p>
                <?php endif; ?>

                <p class="form-row form-row-wide">
                    <label for="payleap_card_number"><?php _e("Credit Card number", 'wc_usbswiper_payleap') ?> <span class="required">*</span></label>
                    <input name="payleap_card_number" id="payleap_card_number" type="text" autocomplete="off" class="input-text card-number" />
                </p>
                <div class="clear"></div>
                <p class="form-row form-row-first">
                    <label for="payleap_card_expire_month"><?php _e("Expiration date", 'wc_usbswiper_payleap') ?> <span class="required">*</span></label>
                    <select name="payleap_card_expire_month" id="cc-expire-month" class="woocommerce-select woocommerce-cc-month card-expiry-month">
                        <option value=""><?php _e('Month', 'wc_usbswiper_payleap') ?></option>
                        <?php
                        $months = array();
                        for ($i = 1; $i <= 12; $i++) :
                            $timestamp = mktime(0, 0, 0, $i, 1);
                            $months[date('m', $timestamp)] = date('m - F', $timestamp);
                        endfor;
                        foreach ($months as $num => $name)
                            printf('<option value="%s">%s</option>', $num, $name);
                        ?>
                    </select>
                    <select name="payleap_card_expire_year" id="cc-expire-year" class="woocommerce-select woocommerce-cc-year card-expiry-year">
                        <option value=""><?php _e('Year', 'wc_usbswiper_payleap') ?></option>
                        <?php
                        for ($i = date('y'); $i <= date('y') + 15; $i++)
                            printf('<option value="%u">20%u</option>', $i, $i);
                        ?>
                    </select>
                </p>
                <p class="form-row form-row-last">
                    <label for="payleap_card_csc"><?php _e("Card security code", 'wc_usbswiper_payleap') ?> <span class="required">*</span></label>
                    <input name="payleap_card_csc" type="text" id="payleap_card_csc" maxlength="4" style="width:4em;" autocomplete="off" class="input-text card-cvc" />
                    <span class="help payleap_card_csc_description"></span>
                </p>
                <div class="clear"></div>
            </fieldset>
            <?php
        }

        /**
         * Process the payment
         */
        function process_payment($order_id) {
            global $woocommerce;

            // Setup our order object
            $order = new WC_Order($order_id);

            // Use PayLeap CURL API for payment
            try {

                // Grab Credit Fields
                $card_number = isset($_POST['payleap_card_number']) ? woocommerce_clean($_POST['payleap_card_number']) : '';
                $card_csc = isset($_POST['payleap_card_csc']) ? woocommerce_clean($_POST['payleap_card_csc']) : '';
                $card_exp_month = isset($_POST['payleap_card_expire_month']) ? woocommerce_clean($_POST['payleap_card_expire_month']) : '';
                $card_exp_year = isset($_POST['payleap_card_expire_year']) ? woocommerce_clean($_POST['payleap_card_expire_year']) : '';

                $billing_first_name = version_compare(WC_VERSION, '3.0', '<') ? $order->billing_first_name : $order->get_billing_first_name();
                $billing_last_name = version_compare(WC_VERSION, '3.0', '<') ? $order->billing_last_name : $order->get_billing_last_name();
                $billing_address_1 = version_compare(WC_VERSION, '3.0', '<') ? $order->billing_address_1 : $order->get_billing_address_1();
                $billing_address_2 = version_compare(WC_VERSION, '3.0', '<') ? $order->billing_address_2 : $order->get_billing_address_2();
                $billtostreet = $billing_address_1 . ' ' . $billing_address_2;
                $billing_city = version_compare(WC_VERSION, '3.0', '<') ? $order->billing_city : $order->get_billing_city();
                $billing_postcode = version_compare(WC_VERSION, '3.0', '<') ? $order->billing_postcode : $order->get_billing_postcode();
                $billing_country = version_compare(WC_VERSION, '3.0', '<') ? $order->billing_country : $order->get_billing_country();
                $billing_state = version_compare(WC_VERSION, '3.0', '<') ? $order->billing_state : $order->get_billing_state();
                $billing_email = version_compare(WC_VERSION, '3.0', '<') ? $order->billing_email : $order->get_billing_email();
                $billing_phone = version_compare(WC_VERSION, '3.0', '<') ? $order->billing_phone : $order->get_billing_phone();

                // Build the HTTP request
                $response = $this->payleap_request(array(
                    'Username' => $this->api_username,
                    'Password' => $this->api_password,
                    'TransType' => 'Sale',
                    'NameOnCard' => $billing_first_name . ' ' . $billing_last_name,
                    'CardNum' => $card_number,
                    'ExpDate' => $card_exp_month . $card_exp_year, // Should be MMYY
                    'CVNum' => $card_csc,
                    'Amount' => $order->get_total(),
                    'ExtData' => '<CertifiedVendorId>USBSWIPER_WOOCOMMERCE</CertifiedVendorId>
									<TrainingMode>F</TrainingMode>
									<Invoice>
										<InvNum>' . $order->get_order_number() . '</InvNum>
										<BillTo>
											<Name>' . $billing_first_name . ' ' . $billing_last_name . '</Name>
											<Address>
												<Street>' . $billtostreet . '</Street>
												<City>' . $billing_city . '</City>
												<State>' . $billing_state . '</State>
												<Zip>' . $billing_postcode . '</Zip>
												<Country>' . $billing_country . '</Country>
											</Address>
											<Email>' . $billing_email . '</Email>
											<Phone>' . $billing_phone . '</Phone>
										</BillTo>
										<Description>' . sprintf(__('%s - Order %s', 'wc_usbswiper_payleap'), esc_html(get_bloginfo('name')), $order->get_order_number()) . '</Description>
									</Invoice>',
                    'PNRef' => '',
                    'MagData' => ''
                ));

                // Handle response
                if (is_wp_error($response)) {
                    throw new Exception(__('There was a problem connecting to the payment gateway.', 'wc_usbswiper_payleap'));
                }

                if (empty($response['body'])) {
                    throw new Exception(__('Empty response from payment gateway.', 'wc_usbswiper_payleap'));
                }

                $parsed_response = simplexml_load_string($response['body']);

                // Handle response
                if (empty($parsed_response->AuthCode)) {

                    if ($parsed_response->RespMSG == 'CVV Fraud transaction')
                        $parsed_response->RespMSG = 'Card security code error.';
                    throw new Exception(sprintf('%s', $parsed_response->RespMSG, $parsed_response->Result));
                } else {

                    // Add order note
                    $order->add_order_note(sprintf(__('PayLeap payment completed (Authorization Code: %s)', 'wc_usbswiper_payleap'), $parsed_response->AuthCode));

                    // Payment complete
                    $order->payment_complete();

                    // Remove cart
                    $woocommerce->cart->empty_cart();

                    // Return thank you page redirect
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order)
                    );
                }
            } catch (Exception $e) {
                // Maybe log the issue
                $this->log($e->getMessage());

                // Add a notice to the checkout screen
                wc_add_notice($e->getMessage(), 'error');

                // Update the order status as failed with the error that was thrown
                $order_note = sprintf(__('<strong>Error:</strong> %1$s', 'wc_usbswiper_payleap'), $e->getMessage());
                $order->update_status('failed', $order_note);

                return;
            }
        }

        /**
         * payleap_request function.
         *
         * @access public
         * @param mixed $post_data
         * @return void
         */
        function payleap_request($request) {
            global $woocommerce;

            // send packet and receive response
            $response = wp_remote_post($this->api_url, array(
                'headers' => array(
                    "MIME-Version: 1.0",
                    "Content-type: application/x-www-form-urlencoded",
                    "Contenttransfer-encoding: text"
                ),
                'body' => $request,
                'timeout' => 70,
                'verbose' => true,
                'user-agent' => 'WooCommerce ' . $woocommerce->version
            ));

            $this->log('Request: ' . print_r($request, true));
            $this->log('Response: ' . print_r($response, true));

            return $response;
        }

        function log($message = '') {
            if (false == $this->debug) {
                return false;
            }

            if (false == $this->logger && class_exists('WC_Logger')) {
                $this->logger = new WC_Logger();
            }

            $this->logger->add('payleap', $message);
        }

    }

    // end woocommerce_payleap

    /**
     * Add the Gateway to WooCommerce
     * */
    function add_payleap_gateway($methods) {
        $methods[] = 'WC_Gateway_USBSwiper_PayLeap';
        return $methods;
    }

    function plugin_action_links($actions, $plugin_file, $plugin_data, $context) {
        $custom_actions = array(
            'configure' => sprintf('<a href="%s">%s</a>', admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_gateway_usbswiper_payleap'), __('Configure', 'wc_usbswiper_payleap')),
            'docs' => sprintf('<a href="%s" target="_blank">%s</a>', 'http://woo.usbswiper.com/category/documentation/woocommerce-payment-processing-payleap/', __('Docs', 'wc_usbswiper_payleap')),
            'support' => sprintf('<a href="%s" target="_blank">%s</a>', 'https://wordpress.org/support/plugin/woo-payment-processing-payleap', __('Support', 'wc_usbswiper_payleap')),
            'review' => sprintf('<a href="%s" target="_blank">%s</a>', 'https://wordpress.org/support/view/plugin-reviews/woo-payment-processing-payleap', __('Write a Review', 'wc_usbswiper_payleap')),
        );

        // add the links to the front of the actions list
        return array_merge($custom_actions, $actions);
    }

    add_filter('woocommerce_payment_gateways', 'add_payleap_gateway');

    $basename = plugin_basename(__FILE__);
    $prefix = is_network_admin() ? 'network_admin_' : '';
    add_filter("{$prefix}plugin_action_links_$basename", 'plugin_action_links', 10, 4);
}
