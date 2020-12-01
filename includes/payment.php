<?php
/**
 * The admin-facing functionality of the plugin.
 *
 * @package    Payment Gateway for LANKAQR on WooCommerce
 * @subpackage Includes
 * @author     Maduka Jayalath
 */

// add Gateway to woocommerce
add_filter('woocommerce_payment_gateways', 'lankaqrwc_woocommerce_payment_add_gateway_class');

function lankaqrwc_woocommerce_payment_add_gateway_class($gateways)
{
    $gateways[] = 'WC_Lanka_QR_Payment_Gateway'; // class name
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'lankaqrwc_payment_gateway_init');

function lankaqrwc_payment_gateway_init()
{

    // If the WooCommerce payment gateway class is not available nothing will return
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Lanka_QR_Payment_Gateway extends WC_Payment_Gateway
    {

        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {

            $this->id = 'wc-mj-lankaqr';
            $this->has_fields = false;
            $this->supports = array('refunds');
            $this->method_title = __('LANKAQR', 'wc-lankaqr-payment-gateway');
            $this->method_description = __('Take payments via LANKAQR to your bank account directly.', 'wc-lankaqr-payment-gateway');
            $this->order_button_text = __('Generate QR Code', 'wc-lankaqr-payment-gateway');

            // API endpoints
            $this->api_url_qr_arr = [
                0 => [
                    0 => '',
                    1 => ''
                ],
                1 => [
                    0 => 'https://enuatqr.eftapme.com/UATCBCQRAPI/Merchant/generateSDKQRCode',
                    1 => 'https://enqr.eftapme.com/CBCQRAPI/Merchant/generateSDKQRCode'
                ],
            ];

            $this->api_url_refund_arr = [
                0 => [
                    0 => '',
                    1 => ''
                ],
                1 => [
                    0 => 'https://enuatqr.eftapme.com/UATCBCQRAPI/Merchant/qrMerRefund',
                    1 => 'https://enqr.eftapme.com/CBCQRAPI/Merchant/qrMerRefund'
                ],
            ];

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->instructions = $this->get_option('instructions');
            $this->thank_you = $this->get_option('thank_you');
            $this->payment_status = $this->get_option('payment_status', 'on-hold');

            $this->debug_mode = $this->get_option('debug_mode') === 'yes' ? true : false;
            $this->test_mode = $this->get_option('test_mode') === 'yes' ? true : false;
            $this->api_provider = $this->get_option('api_provider');
            $this->api_url_qr = $this->test_mode ? $this->api_url_qr_arr[$this->api_provider][0] : $this->api_url_qr_arr[$this->api_provider][1];
            $this->api_url_refund = $this->test_mode ? $this->api_url_refund_arr[$this->api_provider][0] : $this->api_url_refund_arr[$this->api_provider][1];

            $this->institution_id = $this->get_option('institution_id');
            $this->channeluser_id = $this->get_option('channeluser_id');
            $this->channel_pass = $this->get_option('channel_pass');
            $this->merlogin_id = $this->get_option('merlogin_id');
            $this->merlogin_pass = $this->get_option('merlogin_pass');
            $this->mid = $this->get_option('mid');
            $this->tid = $this->get_option('tid');
            $this->check_value = $this->get_option('check_value');
            $this->param1 = $this->get_option('param1');
            $this->param2 = $this->get_option('param2');

            $this->email_enabled = $this->get_option('email_enabled');
            $this->email_subject = $this->get_option('email_subject');
            $this->email_heading = $this->get_option('email_heading');
            $this->additional_content = $this->get_option('additional_content');
            $this->default_status = apply_filters('lankaqrwc_process_payment_order_status', 'pending');

            $this->timeout_duration = $this->get_option('timeout_duration', 3);

            $this->ver = ($this->debug_mode) ? time() : LANKAQR_VERSION;
            $this->icon = apply_filters('woocommerce_lankaqr_icon', LANKAQR_DIR . 'assets/img/lankaqr.png?' . $this->ver);

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // We need custom JavaScript to obtain the transaction number
            add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

            // thank you page output
            add_action('woocommerce_receipt_' . $this->id, array($this, 'generate_qr_code'), 4, 1);

            // verify payment from redirection
            add_action('woocommerce_api_lankaqrwc-payment', array($this, 'capture_payment'));

            // Customize on hold email template subject
            add_filter('woocommerce_email_subject_customer_on_hold_order', array($this, 'email_subject_pending_order'), 10, 3);

            // Customize on hold email template heading
            add_filter('woocommerce_email_heading_customer_on_hold_order', array($this, 'email_heading_pending_order'), 10, 3);

            // Customize on hold email template additional content
            add_filter('woocommerce_email_additional_content_customer_on_hold_order', array($this, 'email_additional_content_pending_order'), 10, 3);

            // Customer Emails
            add_action('woocommerce_email_after_order_table', array($this, 'email_instructions'), 10, 4);

            // add support for payment for on hold orders
            add_action('woocommerce_valid_order_statuses_for_payment', array($this, 'on_hold_payment'), 10, 2);

            // change wc payment link if exists payment method is QR Code
            add_filter('woocommerce_get_checkout_payment_url', array($this, 'custom_checkout_url'), 10, 2);

            // add custom text on thankyou page
            add_filter('woocommerce_thankyou_order_received_text', array($this, 'order_received_text'), 10, 2);

            // Receive Push Notification
            add_action('woocommerce_api_wc_lankaqr', array($this, 'push_notification'));

            // Check Order Status
            add_action('woocommerce_api_check-order', array($this, 'check_order_status'));

            // Filter by _lankaqr_ref_num meta value
            add_filter('woocommerce_order_data_store_cpt_get_orders_query', array($this, 'handle_lankaqr_ref_num_query_var'), 10, 2);

            // Display timeout message in the cart page.
            add_action('woocommerce_after_order_notes', array($this, 'timeout_cart_notice'));

            // Thank you page message
            add_action('woocommerce_before_thankyou', array($this, 'thank_you_message'));

            if (!$this->is_valid_for_use()) {
                $this->enabled = 'no';
            }
        }

        /**
         * Display timeout message in the cart page.
         *
         * @since 1.0.0
         */
        function thank_you_message()
        {
            echo '<p>' . __('Thank you!') . '</p>';
        }

        /**
         * Display timeout message in the cart page.
         *
         * @since 1.0.0
         */
        function timeout_cart_notice()
        {
            if (isset($_REQUEST['timeout']) && $_REQUEST['timeout'] == 'true')
                wc_add_notice(__('Timeout Exceeded, Please try again...'), 'error');
        }

        /**
         * Check if this gateway is enabled and available in the user's country.
         *
         * @return bool
         */
        public function is_valid_for_use()
        {
            if (!in_array(get_woocommerce_currency(), array('LKR', 'SLR', 'RS'))) {
                return false;
            }
            return true;
        }

        /**
         * Admin Panel Options.
         *
         * @since 1.0.0
         */
        public function admin_options()
        {
            if ($this->is_valid_for_use()) {
                parent::admin_options();
            } else {
                ?>
                <div class="inline error">
                    <p>
                        <strong><?php esc_html_e('Gateway disabled', 'wc-lankaqr-payment-gateway'); ?></strong>: <?php _e('LANKAQR doesn\'t support your store currency, set it to Sri Lankan Rupee (Rs)', 'wc-lankaqr-payment-gateway'); ?>
                    </p>
                </div>
                <?php
            }
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc-lankaqr-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable LANKAQR Payment', 'wc-lankaqr-payment-gateway'),
                    'description' => __('Enable this if you want to collect payment via LANKAQR.', 'wc-lankaqr-payment-gateway'),
                    'default' => 'yes',
                    'desc_tip' => true,
                ),
                'title' => array(
                    'title' => __('Title', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'wc-lankaqr-payment-gateway'),
                    'default' => __('Pay with LANKAQR', 'wc-lankaqr-payment-gateway'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'wc-lankaqr-payment-gateway'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'wc-lankaqr-payment-gateway'),
                    'default' => __('Make payment using LANKA QR, VISA QR or Mastercard QR', 'wc-lankaqr-payment-gateway'),
                    'desc_tip' => true,
                ),
                'instructions' => array(
                    'title' => __('Instructions', 'wc-lankaqr-payment-gateway'),
                    'type' => 'textarea',
                    'description' => __('Instructions that will be added to the order pay page and emails.', 'wc-lankaqr-payment-gateway'),
                    'default' => __('Please scan the above QR Code with any of LANKA QR, VISA QR or MasterCard QR mobile apps before the timeout is reached. After successful payment has been made through your QR mobile app you will be redirected to the order completed page automatically.', 'wc-lankaqr-payment-gateway'),
                    'desc_tip' => true,
                ),
                'thank_you' => array(
                    'title' => __('Thank You Message', 'wc-lankaqr-payment-gateway'),
                    'type' => 'textarea',
                    'description' => __('This displays a message to customer after a successful payment is made.', 'wc-lankaqr-payment-gateway'),
                    'default' => __('Thank you for your payment. Your transaction has been completed, and your order has been successfully placed. Please check you Email inbox for details. Please check your bank account statement to view transaction details.', 'wc-lankaqr-payment-gateway'),
                    'desc_tip' => true,
                ),
                'timeout_duration' => array(
                    'title' => __('Timeout Duration', 'wc-lankaqr-payment-gateway'),
                    'type' => 'select',
                    'description' => __('Change this if you think you need to give more or less time for customers to make payments. Recommended value is 3 minutes.', 'wc-lankaqr-payment-gateway'),
                    'desc_tip' => true,
                    'default' => 3,
                    'options' => apply_filters('lankaqrwc_settings_timeout_duration', array(
                        1 => __('1 minute', 'wc-lankaqr-payment-gateway'),
                        2 => __('2 minutes', 'wc-lankaqr-payment-gateway'),
                        3 => __('3 minutes', 'wc-lankaqr-payment-gateway'),
                        4 => __('4 minutes', 'wc-lankaqr-payment-gateway'),
                        5 => __('5 minutes', 'wc-lankaqr-payment-gateway')
                    ))
                ),
                'lankaqr_parameters' => array(
                    'title' => __('LANKAQR Configuration', 'wc-lankaqr-payment-gateway'),
                    'type' => 'title',
                    'description' => __('Merchants should ensure they have below listed parameters before initiating integration. These values will be provided by Bank during initiation of merchant integration / merchant configuration.', 'wc-lankaqr-payment-gateway'),
                ),
                'api_provider' => array(
                    'title' => __('API Provider', 'wc-lankaqr-payment-gateway'),
                    'type' => 'select',
                    'description' => __('Select a bank or financial company where you got the LANKAQR API credentials.', 'wc-lankaqr-payment-gateway'),
                    'desc_tip' => true,
                    'default' => 0,
                    'options' => apply_filters('lankaqrwc_settings_api_provider', array(
                        0 => __('Please Select', 'wc-lankaqr-payment-gateway'),
                        1 => __('Commercial Bank', 'wc-lankaqr-payment-gateway')
                    ))
                ),
                'institution_id' => array(
                    'title' => __('Institution ID', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'description' => __('Enter Institution ID provided by Bank', 'wc-lankaqr-payment-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'channeluser_id' => array(
                    'title' => __('Channel User ID', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'description' => __('Enter Channel User ID provided by Bank', 'wc-lankaqr-payment-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'channel_pass' => array(
                    'title' => __('Channel Password', 'wc-lankaqr-payment-gateway'),
                    'type' => 'textarea',
                    'description' => __('Enter Channel Password provided by Bank', 'wc-lankaqr-payment-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'merlogin_id' => array(
                    'title' => __('Merchant Login ID', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'description' => __('Enter Merchant Login ID provided by Bank', 'wc-lankaqr-payment-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'merlogin_pass' => array(
                    'title' => __('Merchant Password', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'description' => __('Enter Merchant Password provided by Bank', 'wc-lankaqr-payment-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'mid' => array(
                    'title' => __('Merchant ID', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'description' => __('Enter Merchant ID provided by Bank', 'wc-lankaqr-payment-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'tid' => array(
                    'title' => __('Terminal ID', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'description' => __('Enter Terminal ID provided by Bank', 'wc-lankaqr-payment-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'check_value' => array(
                    'title' => __('Checksum Key', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'description' => __('Enter Checksum Key provided by Bank', 'wc-lankaqr-payment-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'push_notification' => array(
                    'title' => __('Push Notification', 'wc-lankaqr-payment-gateway'),
                    'type' => 'title',
                    'description' => sprintf(__('You must share this URL <strong style="background-color:#ddd;">&nbsp;%s&nbsp;</strong> with <strong style="background-color:#ddd;">&nbsp;param1&nbsp;</strong> and <strong style="background-color:#ddd;">&nbsp;param2&nbsp;</strong> values in the below to the bank when you request a merchant account. This required to complete the payment process smoothly. You can change the pre-populated values below as you wish.', 'wc-lankaqr-payment-gateway'), add_query_arg('wc-api', 'wc_lankaqr', trailingslashit(get_home_url()))),
                ),
                'param1' => array(
                    'title' => __('param1 (username)', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'description' => __('User Name for Push Notification', 'wc-lankaqr-payment-gateway'),
                    'default' => $this->generate_random_string(12, false, 'lu'),
                    'desc_tip' => true,
                ),
                'param2' => array(
                    'title' => __('param2 (password)', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'description' => __('Password for Push Notification', 'wc-lankaqr-payment-gateway'),
                    'default' => $this->generate_random_string(16, false, 'luds'),
                    'desc_tip' => true,
                ),
                'testing' => array(
                    'title' => __('Gateway Testing', 'wc-lankaqr-payment-gateway'),
                    'type' => 'title',
                    'description' => __('Place the payment gateway in test mode using test keys provided by the bank.', 'wc-lankaqr-payment-gateway'),
                ),
                'test_mode' => array(
                    'title' => __('Test Mode', 'wc-lankaqr-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable Test Mode', 'wc-lankaqr-payment-gateway'),
                    'default' => 'no',
                    'description' => __('Test mode enables you to test payments before go live. If you ready to start receiving payment on your site, kindly uncheck this.', 'wc-lankaqr-payment-gateway'),
                    'desc_tip' => true,
                ),
                'debug_mode' => array(
                    'title' => __('Debug Mode', 'wc-lankaqr-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable Debug Mode', 'wc-lankaqr-payment-gateway'),
                    'default' => 'no',
                    'description' => __('Debug mode enables you to get more information. Please uncheck this on production sites as there will be sensitive information will be exposed to the outside.', 'wc-lankaqr-payment-gateway'),
                    'desc_tip' => true,
                ),
                'email' => array(
                    'title' => __('Configure Email', 'wc-lankaqr-payment-gateway'),
                    'type' => 'title',
                    'description' => __('Enable this option if you want to send a pending order email notification to customer when they started to paying via LANKAQR.', 'wc-lankaqr-payment-gateway'),
                ),
                'email_enabled' => array(
                    'title' => __('Enable/Disable', 'wc-lankaqr-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable Additional Email Notification', 'wc-lankaqr-payment-gateway'),
                    'description' => __('Enable this option if you want to send payment link to the customer via email after started placing the order.', 'wc-lankaqr-payment-gateway'),
                    'default' => 'yes',
                    'desc_tip' => true,
                ),
                'email_subject' => array(
                    'title' => __('Email Subject', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'desc_tip' => true,
                    'description' => sprintf(__('Available placeholders: %s', 'wc-lankaqr-payment-gateway'), '<code>' . esc_html('{site_title}, {site_address}, {order_date}, {order_number}') . '</code>'),
                    'default' => __('[{site_title}]: Payment pending #{order_number}', 'wc-lankaqr-payment-gateway'),
                ),
                'email_heading' => array(
                    'title' => __('Email Heading', 'wc-lankaqr-payment-gateway'),
                    'type' => 'text',
                    'desc_tip' => true,
                    'description' => sprintf(__('Available placeholders: %s', 'wc-lankaqr-payment-gateway'), '<code>' . esc_html('{site_title}, {site_address}, {order_date}, {order_number}') . '</code>'),
                    'default' => __('Thank you for your order', 'wc-lankaqr-payment-gateway'),
                ),
                'additional_content' => array(
                    'title' => __('Email Body Additional Text', 'wc-lankaqr-payment-gateway'),
                    'type' => 'textarea',
                    'description' => __('This text will be attached to the On Hold email template sent to customer. Use {lanka_qr_pay_link} to add the link of payment page.', 'wc-lankaqr-payment-gateway'),
                    'default' => __('Please complete the payment via LANKAQR by clicking this link: {lanka_qr_pay_link} (ignore if already done).', 'wc-lankaqr-payment-gateway'),
                    'desc_tip' => true,
                )
            );
        }


        /*
         * Custom CSS and JS
         */
        public function payment_scripts()
        {
            // if our payment gateway is disabled, we do not have to enqueue JS too
            if ('no' === $this->enabled) {
                return;
            }

            wp_register_style('lankaqr-css', LANKAQR_DIR . 'assets/css/lankaqr.css', array(), $this->ver);
            wp_register_script('lankaqr-js', LANKAQR_DIR . 'assets/js/lankaqr.js', array('jquery'), $this->ver, true);
        }

        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            // Mark as pending (we're awaiting the payment)
            $order->update_status($this->default_status);

            // add some order notes
            $order->add_order_note(apply_filters('lankaqrwc_process_payment_note', __('Awaiting LANKAQR Payment...', 'wc-lankaqr-payment-gateway'), $order), false);

            // update meta
            update_post_meta($order->get_id(), '_lankaqrwc_order_paid', 'no');

            if (apply_filters('lankaqrwc_payment_empty_cart', false)) {
                // Empty cart
                WC()->cart->empty_cart();
            }

            do_action('lankaqrwc_after_payment_init', $order_id, $order);

            // check plugin settings
            if ('yes' === $this->enabled && 'yes' === $this->email_enabled && $order->has_status('pending')) {
                // Get an instance of the WC_Email_Customer_On_Hold_Order object
                $wc_email = WC()->mailer()->get_emails()['WC_Email_Customer_On_Hold_Order'];

                // Send "New Email" notification
                $wc_email->trigger($order_id);
            }

            // Return redirect
            return array(
                'result' => 'success',
                'redirect' => apply_filters('lankaqrwc_process_payment_redirect', $order->get_checkout_payment_url(true), $order)
            );
        }

        /**
         * Show LANKAQR details as html output
         *
         * @param WC_Order $order_id Order id.
         * @return string
         */
        public function generate_qr_code($order_id)
        {
            // get order object from id
            $order = wc_get_order($order_id);
            $total = apply_filters('lankaqrwc_order_total_amount', $order->get_total(), $order);

            // generate 12 digit random number
            $reqrefno = $this->generate_random_string(12, false, 'd');

            // enqueue required css & js files
            wp_enqueue_style('lankaqr-css');
            wp_enqueue_script('lankaqr-js');

            // add localize scripts
            wp_localize_script('lankaqr-js', 'lankaqr_params',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'order_id' => $order_id,
                    'order_key' => $order->get_order_key(),
                    'processing_text' => apply_filters('lankaqrwc_payment_processing_text', __('Please wait while we are processing your request...', 'wc-lankaqr-payment-gateway')),
                    'callback_url' => add_query_arg(array('wc-api' => 'lankaqrwc-payment'), trailingslashit(get_home_url())),
                    'timeout_url' => add_query_arg(array('timeout' => 'true'), trailingslashit(wc_get_checkout_url())),
                    'cancel_url' => apply_filters('lankaqrwc_payment_cancel_url', wc_get_checkout_url(), $this->get_return_url($order), $order),
                    'return_url' => apply_filters('lankaqrwc_payment_redirect_url', $this->get_return_url($order), $order),
                    'ajax_check_url' => add_query_arg(array('wc-api' => 'check-order'), trailingslashit(get_home_url())),
                    'reqrefno' => $reqrefno,
                    'prevent_reload' => apply_filters('lankaqrwc_enable_payment_reload', true),
                    'app_version' => LANKAQR_VERSION,
                    'timeout_duration' => $this->timeout_duration * 60 * 1000,
                )
            );

            $html = '<h6 class="lankaqr-waiting-text">';
            $html .= __('Please wait and don\'t press back or refresh this page while we are processing your payment.', 'wc-lankaqr-payment-gateway');
            $html .= '</h6>';
            $html .= '<button id="lankaqr-processing" class="btn button" disabled="disabled">';
            $html .= __('Waiting for QR Code...', 'wc-lankaqr-payment-gateway');
            $html .= '</button>';

            $str_array = array(
                'mid' => $this->mid,
                'tid' => $this->tid,
                'merloginId' => $this->merlogin_id,
                'deviceId' => '',
                'type' => 1,
                'txnamount' => $total * 100,
                'billno' => '',
                'mobileno' => ''
            );
            $string = implode('|', $str_array);
            $sig = hash_hmac('sha512', $string, $this->clean_value($this->check_value));
            $sig = base64_encode(strtoupper($sig));

            $response = wp_remote_post($this->api_url_qr, array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'redirection' => 5,
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => array('content-type' => 'application/json'),
                    'body' => json_encode(array(
                        'institutionId' => $this->institution_id,
                        'channeluserId' => $this->channeluser_id,
                        'channelpass' => $this->clean_value($this->channel_pass),
                        'reqrefno' => $reqrefno,
                        'merloginId' => $this->merlogin_id,
                        'merloginpass' => $this->clean_value($this->merlogin_pass),
                        'deviceId' => '',
                        'type' => '1',
                        'mid' => $this->mid,
                        'tid' => $this->tid,
                        'txnamount' => $total * 100,
                        'txncurrency' => '144',
                        'billno' => '',
                        'mobileno' => '',
                        'storelabel' => '',
                        'loyaltynumber' => '',
                        'referencelabel' => $order_id,
                        'customerlabel' => $reqrefno,
                        'terminallabel' => '',
                        'purposeoftxn' => '',
                        'additionalcustdatareq' => '',
                        'sessionId' => '',
                        'ipaddress' => '',
                        'checkvalue' => $sig)),
                    'cookies' => array(),
                    'data_format' => 'body',
                )
            );

            $error_html = '<h6 class="lankaqr-waiting-text">';
            $error_html .= __('Something went wrong...', 'wc-lankaqr-payment-gateway');
            $error_html .= '</h6>';
            $error_html .= '<button id="lankaqr-error-btn" class="btn button">';
            $error_html .= __('Go back', 'wc-lankaqr-payment-gateway');
            $error_html .= '</button>';

            $qr_response = false;

            if (is_wp_error($response)) {
                $html = $error_html;
            } else {
                $response = json_decode($response['body']);

                $res_str_array = array(
                    'reqRefNo' => $reqrefno,
                    'respCode' => $response->respcode,
                );
                $res_string = implode('|', $res_str_array);
                $res_sig = hash_hmac('sha512', $res_string, $this->check_value);
                $res_sig = base64_encode(strtoupper($res_sig));

                if ($response->respcode == 'EN00' && strcmp($response->checkvalue, $res_sig) == 0) {
                    update_post_meta($order->get_id(), '_lankaqr_ref_num', $reqrefno);
                    $qr_response = true;
                    $html = '<div class="lankaqr-logo">';
                    $html .= '<img src="' . apply_filters('woocommerce_lankaqr_logo', LANKAQR_DIR . 'assets/img/lankaqr-logo.jpg?' . $this->ver) . '" />';
                    $html .= '</div>';
                    $html .= '<div id="lankaqr-qrcode">';
                    $html .= '<img class="lankaqr-qrcode-img" src="' . $response->qrcode . '" />';
                    $html .= '</div>';
                } else {
                    $html = $error_html;
                }
            }

            // add html output on payment endpoint
            if ('yes' === $this->enabled && $order->needs_payment() === true && $order->has_status($this->default_status)) {


                ?>
                <section class="woo-lankaqr-section">
                    <div class="lankaqr-info">
                        <?php
                        if ($this->debug_mode) {
                            echo '<pre>' . json_encode($response) . '</pre>';
                        }
                        ?>
                        <?php echo $html; ?>
                        <?php if ($qr_response == true) { ?>
                            <div id="js_qrcode" class="lankaqr-js-qrcode">

                                <?php if (apply_filters('lankaqrwc_show_order_total', true)) { ?>
                                    <div id="lankaqr-order-total" class="lankaqr-order-total">
                                        <?php _e('Amount to be paid:', 'wc-lankaqr-payment-gateway'); ?>
                                        <span id="lankaqr-order-total-amount"><?php echo wc_price($total); ?></span>
                                    </div>
                                <?php } ?>

                                <div id="lankaqr-description"
                                     class="lankaqr-description"><?php echo wptexturize($this->instructions); ?>
                                </div>
                                <div class="lankaqr-counter-content">
                                    <strong><?php _e('Timeout in ', 'wc-lankaqr-payment-gateway'); ?></strong>
                                    <div class="lankaqr-counter" id="lankaqr-timeout">-- : --</div>
                                </div>
                                <div class="lankaqr-buttons">
                                    <button id="lankaqr-cancel-payment"
                                            class="btn button"><?php _e('Cancel', 'wc-lankaqr-payment-gateway'); ?></button>
                                </div>
                                <div class="lankaqr-clear-area"></div>
                                <div class="lankaqr-logo-footer">
                                    <img src="<?php echo apply_filters('woocommerce_lankaqr_logo_footer', LANKAQR_DIR . 'assets/img/logo-footer.png?' . $this->ver) ?>"/>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </section>
                <?php
            }
        }

        /**
         * Process payment verification.
         */
        public function capture_payment()
        {
            // get order id
            if (('POST' !== $_SERVER['REQUEST_METHOD']) || !isset($_GET['wc-api']) || ('lankaqrwc-payment' !== $_GET['wc-api'])) {
                // create redirect
                wp_safe_redirect(home_url());
                exit;
            }

            // generate order
            $order = wc_get_order(wc_get_order_id_by_order_key(sanitize_text_field($_POST['wc_order_key'])));

            // check if it an order
            if (is_a($order, 'WC_Order')) {
                $order->update_status(apply_filters('lankaqrwc_capture_payment_order_status', $this->payment_status));
                //set transaction id
                if (isset($_POST['wc_transaction_id']) && !empty($_POST['wc_transaction_id'])) {
                    /*update_post_meta($order->get_id(), '_transaction_id', sanitize_text_field($_POST['wc_transaction_id']));*/
                }
                // reduce stock level
                /*wc_reduce_stock_levels($order->get_id());*/
                // check order if it actually needs payment
                if (in_array($this->payment_status, apply_filters('lankaqrwc_valid_order_status_for_note', array('pending', 'on-hold')))) {
                    // set order note
                    $order->add_order_note(sprintf(__('Payment has been completed. Need to verify against this LANKAQR Reference Number: %s then change the order status.', 'wc-lankaqr-payment-gateway'), sanitize_text_field($_POST['wc_transaction_id'])), false);
                }
                // update post meta
                update_post_meta($order->get_id(), '_lankaqrwc_order_paid', 'yes');
                // add custom actions
                do_action('lankaqrwc_after_payment_verify', $order->get_id(), $order);
                // create redirect
                wp_safe_redirect(apply_filters('lankaqrwc_payment_redirect_url', $this->get_return_url($order), $order));
                exit;
            } else {
                // create redirect
                $title = __('Order can\'t be found against this Order ID. If the money debited from your account, please Contact the Site Administrator or your Bank for further action.', 'wc-lankaqr-payment-gateway');
                wp_die($title, get_bloginfo('name'));
                exit;
            }
        }

        /**
         * Customize the WC emails template.
         *
         * @access public
         * @param string $formated_subject
         * @param WC_Order $order
         * @param object $object
         */

        public function email_subject_pending_order($formated_subject, $order, $object)
        {
            // We exit for 'order-accepted' custom order status
            if ($this->id === $order->get_payment_method() && 'yes' === $this->enabled && $order->has_status('pending')) {
                return $object->format_string($this->email_subject);
            }

            return $formated_subject;
        }

        /**
         * Customize the WC emails template.
         *
         * @access public
         * @param string $formated_subject
         * @param WC_Order $order
         * @param object $object
         */
        public function email_heading_pending_order($formated_heading, $order, $object)
        {
            // We exit for 'order-accepted' custom order status
            if ($this->id === $order->get_payment_method() && 'yes' === $this->enabled && $order->has_status('pending')) {
                return $object->format_string($this->email_heading);
            }

            return $formated_heading;
        }

        /**
         * Customize the WC emails template.
         *
         * @access public
         * @param string $formated_subject
         * @param WC_Order $order
         * @param object $object
         */
        public function email_additional_content_pending_order($formated_additional_content, $order, $object)
        {
            // We exit for 'order-accepted' custom order status
            if ($this->id === $order->get_payment_method() && 'yes' === $this->enabled && $order->has_status('pending')) {
                return $object->format_string(str_replace('{lanka_qr_pay_link}', $order->get_checkout_payment_url(true), $this->additional_content));
            }

            return $formated_additional_content;
        }

        /**
         * Custom order received text.
         *
         * @param string $text Default text.
         * @param WC_Order $order Order data.
         * @return string
         */
        public function order_received_text($text, $order)
        {
            if ($this->id === $order->get_payment_method() && !empty($this->thank_you)) {
                return esc_html($this->thank_you);
            }

            return $text;
        }

        /**
         * Custom checkout URL.
         *
         * @param string $url Default URL.
         * @param WC_Order $order Order data.
         * @return string
         */
        public function custom_checkout_url($url, $order)
        {
            if ($this->id === $order->get_payment_method() && (($order->has_status('on-hold') && $this->default_status === 'on-hold') || ($order->has_status('pending') && apply_filters('lankaqrwc_custom_checkout_url', false)))) {
                return esc_url(remove_query_arg('pay_for_order', $url));
            }

            return $url;
        }

        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         * @param object $email
         */
        public function email_instructions($order, $sent_to_admin, $plain_text, $email)
        {
            // check LANKAQR gateway name
            if ('yes' === $this->enabled && 'yes' === $this->email_enabled && !empty($this->additional_content) && !$sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status('on-hold')) {
                echo wpautop(wptexturize(str_replace('{lanka_qr_pay_link}', $order->get_checkout_payment_url(true), $this->additional_content))) . PHP_EOL;
            }
        }

        /**
         * Allows payment for orders with on-hold status.
         *
         * @param string $statuses Default status.
         * @param WC_Order $order Order data.
         * @return string
         */
        public function on_hold_payment($statuses, $order)
        {
            if ($this->id === $order->get_payment_method() && $order->has_status('on-hold') && $order->get_meta('_lankaqrwc_order_paid', true) !== 'yes' && $this->default_status === 'on-hold') {
                $statuses[] = 'on-hold';
            }

            return $statuses;
        }

        /**
         * Receive Push Notification.
         *
         * @return array
         */
        public function push_notification()
        {
            $merchantrefnum = $this->generate_random_string(20, false, 'lud');
            $return = array('txnrrn' => '', 'txnstan' => '', 'merchantstatus' => 'E', 'merchantrefnum' => $merchantrefnum);
            if (('POST' !== $_SERVER['REQUEST_METHOD']) || !isset($_GET['wc-api']) || ('wc_lankaqr' !== $_GET['wc-api'])) {
                wp_send_json($return);
                exit;
            }

            $return_body = file_get_contents('php://input');
            $return_data = json_decode($return_body, true);

            if ($this->debug_mode) {
                $body = '<pre><br>';
                $to = get_option('admin_email');
                $subject = 'Push Notification Debug Information - ' . sanitize_text_field($return_data['addtag1value']);
                $body .= $return_body;
                $headers = array('Content-Type: text/html; charset=UTF-8');
                wp_mail($to, $subject, $body, $headers);
            }

            if ((isset($return_data['param1']) && sanitize_text_field($return_data['param1']) == $this->param1) && (isset($return_data['param2']) && sanitize_text_field($return_data['param2']) == $this->clean_value($this->param2))) {
                if (isset($return_data['addtag1value'])) {
                    $order = wc_get_order(sanitize_text_field($return_data['addtag1value']));
                    if ($order) {
                        if (metadata_exists('post', $order->get_id(), '_lankaqr_txnrrn') && metadata_exists('post', $order->get_id(), '_lankaqr_txnstan') && metadata_exists('post', $order->get_id(), '_lankaqr_txnamount')) {
                            $return['merchantstatus'] = 'F';
                            wp_send_json($return);
                            exit;
                        } else {
                            if (isset($return_data['txnrrn'])) {
                                $order->update_meta_data('_lankaqr_txnrrn', sanitize_text_field($return_data['txnrrn']));
                            }
                            if (isset($return_data['txnstan'])) {
                                $order->update_meta_data('_lankaqr_txnstan', sanitize_text_field($return_data['txnstan']));
                            }
                            if (isset($return_data['txnamount'])) {
                                $order->update_meta_data('_lankaqr_txnamount', sanitize_text_field($return_data['txnamount']));
                            }
                            if (isset($return_data['interchange'])) {
                                $order->update_meta_data('_lankaqr_interchange', sanitize_text_field($return_data['interchange']));
                            }
                            if (isset($merchantrefnum)) {
                                $order->update_meta_data('_lankaqr_merchantrefnum', $merchantrefnum);
                            }
                            if (isset($return_data['txnrrn'])) {
                                $order->update_meta_data('_transaction_id', sanitize_text_field($return_data['txnrrn']));
                            }
                            if (isset($return_data['txnrrn'])) {
                                $post = $return_data;
                                unset($post['param1']);
                                unset($post['param2']);
                                $order->update_meta_data('_lankaqr_json', json_encode($post));
                            }
                            $order->update_meta_data('_lankaqrwc_order_paid', 'yes');

                            $order->save();

                            $order->add_order_note(__('Payment has been verified through the LANAKQR Push Notification service.', 'wc-lankaqr-payment-gateway'));
                            $order->add_order_note(sprintf(__('Reference No : %s <br> Stan No : %s <br> Amount : %s', 'wc-lankaqr-payment-gateway'), sanitize_text_field($return_data['txnrrn']), sanitize_text_field($return_data['txnstan']), wc_price(sanitize_text_field($return_data['txnamount']))));
                            $order->update_status(apply_filters('lankaqrwc_capture_payment_order_status', 'processing'));

                            wc_reduce_stock_levels($order->get_id());
                            $return = array('txnrrn' => sanitize_text_field($return_data['txnrrn']), 'txnstan' => sanitize_text_field($return_data['txnstan']), 'merchantstatus' => 'S', 'merchantrefnum' => $merchantrefnum);
                        }
                    }
                }
            }
            wp_send_json($return);
            exit;
        }

        /**
         * Check order status
         * @return array json
         */
        public function check_order_status()
        {
            $return = array('status' => false);

            if (('POST' !== $_SERVER['REQUEST_METHOD']) || !isset($_GET['wc-api']) || ('check-order' !== $_GET['wc-api'])) {
                wp_send_json($return);
                exit;
            } else {
                if (isset($_POST['order_id'])) {
                    $order = wc_get_order(sanitize_text_field($_POST['order_id']));
                    if ($order) {
                        if (metadata_exists('post', $order->get_id(), '_transaction_id')) {
                            $return = array('status' => true);
                        }
                    }
                }
            }
            wp_send_json($return);
            exit;
        }

        /**
         * Process a refund if supported.
         *
         * @param int $order_id Order ID.
         * @param float $amount Refund amount.
         * @param string $reason Refund reason.
         * @return bool|WP_Error
         */
        public function process_refund($order_id, $amount = null, $reason = '')
        {
            $order = wc_get_order($order_id);

            if (!$order) {
                return new WP_Error('error', __('Invalid order.', 'wc-lankaqr-payment-gateway'));
            }

            $str_array = array(
                'merloginId' => $this->merlogin_id,
                'rrn' => $order->get_meta('_lankaqr_txnrrn', true),
                'interchange' => strtoupper($order->get_meta('_lankaqr_interchange', true)),
            );
            $string = implode('|', $str_array);
            $sig = hash_hmac('sha512', $string, $this->clean_value($this->check_value));
            $sig = base64_encode(strtoupper($sig));

            $reqRefNo = $this->generate_random_string(12, false, 'd');

            $response = wp_remote_post($this->api_url_refund, array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'redirection' => 5,
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => array('content-type' => 'application/json'),
                    'body' => json_encode(array(
                        'institutionId' => $this->institution_id,
                        'channelUserId' => $this->channeluser_id,
                        'channelPass' => $this->clean_value($this->channel_pass),
                        'reqRefNo' => $reqRefNo,
                        'loginID' => $this->merlogin_id,
                        'password' => $this->clean_value($this->merlogin_pass),
                        'rrn' => $order->get_meta('_lankaqr_txnrrn', true),
                        'refundAmount' => $amount,
                        'interchange' => strtoupper($order->get_meta('_lankaqr_interchange', true)),
                        'ipAddress' => '',
                        'stan' => $order->get_meta('_lankaqr_txnstan', true),
                        'checkSum' => $sig)),
                    'cookies' => array(),
                    'data_format' => 'body',
                )
            );

            if (is_wp_error($response)) {
                return new WP_Error('error', 'API Server error');
            } else {
                $return_body = $response['body'];
                $return_data = json_decode($return_body, true);

                if ($this->debug_mode) {
                    $body = '<pre><br>';
                    $to = get_option('admin_email');
                    $subject = 'Refund Debug Information - ' . $order->get_id();
                    $body .= $return_body;
                    $headers = array('Content-Type: text/html; charset=UTF-8');
                    wp_mail($to, $subject, $body, $headers);
                }

                if ($return_data['respCode'] == 'EN00' && $return_data['reqRefNo'] == $reqRefNo) {
                    return true;
                } else {
                    return new WP_Error('error', $return_data['respDesc']);
                }

            }

            return false;
        }

        /**
         * Can the order be refunded via LANKAQR?
         *
         * @param WC_Order $order Order object.
         * @return bool
         */
        public function can_refund_order($order)
        {
            return $order && metadata_exists('post', $order->get_id(), '_transaction_id');
        }

        /**
         * Handle a custom 'customvar' query var to get orders with the 'customvar' meta.
         * @param array $query - Args for WP_Query.
         * @param array $query_vars - Query vars from WC_Order_Query.
         * @return array modified $query
         */
        public function handle_lankaqr_ref_num_query_var($query, $query_vars)
        {
            if (!empty($query_vars['lankaqr_ref_num'])) {
                $query['meta_query'][] = array(
                    'key' => '_lankaqr_ref_num',
                    'value' => esc_attr($query_vars['lankaqr_ref_num']),
                );
            }

            return $query;
        }

        /**
         * scape keep Wordpress from changing & into html (&amp;)
         * @param text $value
         * @return text
         * */
        public function clean_value($value = '')
        {
            return str_replace("&amp;", "&", $value);
        }

        /**
         * Generates a strong password of N length containing at least one lower case letter,
         * one uppercase letter, one digit, and one special character. The remaining characters
         * in the password are chosen at random from those four sets.
         * The available characters in each set are user friendly.
         * This, coupled with the $add_dashes option,
         * makes it much easier for users to manually type or speak their passwords.
         * Note: the $add_dashes option will increase the length of the password by
         * floor(sqrt(N)) characters.
         * @param text $length
         * @param text $add_dashes
         * @param text $available_sets
         * @return text
         * */
        public function generate_random_string($length = 9, $add_dashes = false, $available_sets = 'luds')
        {
            $sets = array();
            if (strpos($available_sets, 'l') !== false)
                $sets[] = 'abcdefghijkmnopqrstuvwxyz';
            if (strpos($available_sets, 'u') !== false)
                $sets[] = 'ABCDEFGHIJKMNOPQRSTUVWXYZ';
            if (strpos($available_sets, 'd') !== false)
                $sets[] = '0123456789';
            if (strpos($available_sets, 's') !== false)
                $sets[] = '!@#$%&*?';

            $all = '';
            $password = '';
            foreach ($sets as $set) {
                $password .= $set[array_rand(str_split($set))];
                $all .= $set;
            }

            $all = str_split($all);
            for ($i = 0; $i < $length - count($sets); $i++)
                $password .= $all[array_rand($all)];

            $password = str_shuffle($password);

            if (!$add_dashes)
                return $password;

            $dash_len = floor(sqrt($length));
            $dash_str = '';
            while (strlen($password) > $dash_len) {
                $dash_str .= substr($password, 0, $dash_len) . '-';
                $password = substr($password, $dash_len);
            }
            $dash_str .= $password;
            return $dash_str;
        }


    }
}
