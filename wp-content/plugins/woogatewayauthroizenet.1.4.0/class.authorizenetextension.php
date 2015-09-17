<?php
/*
 * Title   : Authorize.net Payment extension for Woo-Commerece
 * Author  : DenonStudio
 * Url     : http://codecanyon.net/user/DenonStudio/portfolio
 * License : http://codecanyon.net/wiki/support/legal-terms/licensing-terms/
 */

class denonstudio_authorizenet extends WC_Payment_Gateway 
{
    protected $GATEWAY_NAME                     = "Authorize.Net";
    protected $AUTHORIZENET_URL_SANDBOX         = "https://test.authorize.net/gateway/transact.dll";
    protected $AUTHORIZENET_URL_LIVE            = "https://secure.authorize.net/gateway/transact.dll";
    protected $AUTHORIZENET_API_VERSION         = "3.1";
    protected $AUTHORIZENET_TRX_TYPE            = "AUTH_CAPTURE";
    protected $AUTHORIZENET_TRX_METHOD          = "CC";
    protected $AUTHORIZENET_RELAY_RESPONSE      = "FALSE";
    protected $AUTHORIZENET_DELIMITED_DATA      = "TRUE";
    protected $AUTHORIZENET_DELIMITED_CHAR      = "|";
    protected $AUTHORIZENET_SUCCESS_ACK         = 1;
    protected $AUTHORIZENET_RESPONSE_TRX_ID_INX = 6;
    protected $AUTHORIZENET_RESPONSE_AUTH_INX   = 4;
    protected $AUTHORIZENET_RESPONSE_ACK_INX    = 0;
    protected $AUTHORIZENET_RESPONSE_REASON_INX = 3;

    protected $authorizeNetApiLoginId     = '';
    protected $authorizeNetTransactionKey = '';

    protected $instructions               = '';
    protected $order                      = null;
    protected $acceptableCards            = null;
    protected $transactionId              = null;
    protected $authorizationCode          = null;
    protected $transactionErrorMessage    = null;
    protected $usesandboxapi              = true;

    public function __construct() 
    { 
        $this->id              = 'AuthorizeNet';
        $this->has_fields      = true;
        
        $this->init_form_fields();
        $this->init_settings();
        
        $this->title                      = $this->settings['title'];
        $this->description                = '';
        $this->icon 		              = WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/images/credits.png';
        $this->usesandboxapi              = strcmp($this->settings['debug'], 'yes') == 0;
        $this->authorizeNetApiLoginId     = $this->settings['authorizenetloginid'       ];
        $this->authorizeNetTransactionKey = $this->settings['authorizenettransactionkey'];
        $this->instructions               = $this->settings['instructions'              ];
        $this->acceptableCards            = $this->settings['cardtypes'                 ];

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('admin_notices'                                           , array($this, 'perform_ssl_check'    ));
        add_action('woocommerce_thankyou'                                    , array($this, 'thankyou_page'        ));
    }

    function perform_ssl_check() {
        if (!$this->usesandboxapi && get_option('woocommerce_force_ssl_checkout') == 'no' && $this->enabled == 'yes') :
            echo '<div class="error"><p>'.sprintf(__('Authorize.net sandbox testing is disabled and can performe live transactions but the <a href="%s">Force secure checkout</a> is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.', 'woocommerce'), admin_url('admin.php?page=woocommerce_settings&tab=general')).'</p></div>';
        endif;
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'type'        => 'checkbox',
                'title'       => __('Enable/Disable', 'woocommerce'),
                'label'       => __('Enable Credit Card Payment', 'woocommerce'),
                'default'     => 'yes'
            ), 
            'debug' => array(
                'type'        => 'checkbox', 
                'title'       => __('Authorize.net Sandbox', 'woocommerce'), 
                'label'       => __('Enable Authorize.net Sandbox', 'woocommerce'),
                'default'     => 'no'
            ),
            'title' => array(
                'type'        => 'text',
                'title'       => __('Title', 'woocommerce'),
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                'default'     => __('Credit Card Payment', 'woocommerce')
            ),
            'instructions' => array(
                'type'        => 'textarea',
                'title'       => __('Customer Message', 'woocommerce'),
                'description' => __('This message is displayed on the buttom of the Order Recieved Page.', 'woocommerce'),
                'default'     => ''
            ),
            'authorizenetloginid' => array(
                'type'        => 'text',
                'title'       => __('Authorize.net API Login Id', 'woocommerce'),
                'default'     => __('', 'woocommerce')
            ),
            'authorizenettransactionkey' => array(
                'type'        => 'text', 
                'title'       => __('Authorize.net Transaction Key', 'woocommerce'),
                'default'     => __('', 'woocommerce')
            ),
            'cardtypes'	=> array(
                'title'       => __( 'Accepted Cards', 'woocommerce' ),
                'type'        => 'multiselect', 
                'description' => __( 'Select which card types to accept.', 'woocommerce' ),
                'default'     => '',
                'options'     => array(
                    'Visa'			   => 'Visa',
                    'MasterCard'	   => 'MasterCard', 
                    'Discover'		   => 'Discover',
                    'American Express' => 'American Express'
                )
            )
       );
    }

    public function admin_options()
    {
        include_once('form.admin.php');
    }

    public function payment_fields()
    {
        include_once('form.payment.php');
    }

    public function thankyou_page($order_id)
    {
        if ($this->instructions) 
            echo wpautop(wptexturize($this->instructions));
    }

    public function validate_fields()
    {
        if (!$this->isCreditCardNumber($_POST['billing_credircard']))
            wc_add_notice(__('<strong>Credit Card Number</strong> is not valid.', 'woocommerce'), 'error');

        if (!$this->isCorrectCardType($_POST['billing_cardtype']))    
            wc_add_notice(__('<strong>Card Type</strong> is not valid.', 'woocommerce'), 'error');

        if (!$this->isCorrectExpireDate($_POST['billing_expdatemonth'], $_POST['billing_expdateyear']))
            wc_add_notice(__('<strong>Card Expire Date</strong> is not valid.', 'woocommerce'), 'error');

        if (!$this->isCCVNumber($_POST['billing_ccvnumber'])) 
            wc_add_notice(__('<strong>Card Verification Number</strong> is not valid.', 'woocommerce'), 'error');
    }

    public function process_payment($order_id) 
    {
        $this->order        = new WC_Order($order_id);
        $gatewayRequestData = $this->getAuthorizeNetRequestData();

        if ($gatewayRequestData AND $this->geAuthorizeNetApproval($gatewayRequestData))
        {
            $this->completeOrder();

            return array(
                'result'   => 'success',
                              'redirect' => $this->get_return_url( $this->order )
            );
        }
        else
        {
            $this->markAsFailedPayment();
            wc_add_notice(__('Something went wrong while performing your request. Please contact website administrator to report this problem.', 'woocommerce'), 'error');
        }
    }

    protected function markAsFailedPayment()
    {
        $this->order->add_order_note(
            sprintf(
                "%s Credit Card Payment Failed with message: '%s'", 
                $this->GATEWAY_NAME, 
                $this->transactionErrorMessage
            )
        );
    }

    protected function completeOrder()
    {
        global $woocommerce;

        if ($this->order->status == 'completed') 
            return;
        
        $this->order->payment_complete();
        $woocommerce->cart->empty_cart();

        $this->order->add_order_note(
            sprintf(
                "%s payment completed with Transaction Id of '%s' and Authorization Id '%s'", 
                $this->GATEWAY_NAME, 
                $this->transactionId, 
                $this->authorizationCode
            )
        );
        
        unset($_SESSION['order_awaiting_payment']);
    }

    protected function geAuthorizeNetApproval($gatewayRequestData)
    {
        $erroMessage = "";
        $api_url     = $this->usesandboxapi ? $this->AUTHORIZENET_URL_SANDBOX : $this->AUTHORIZENET_URL_LIVE;
        $request     = array(
            'method'    => 'POST',
            'timeout'   => 45,
            'blocking'  => true,
            'sslverify' => false,
            'body'      => $gatewayRequestData
        );

        $response = wp_remote_post($api_url, $request);

        if (!is_wp_error($response))
        {
            $parsedResponse = $this->parseAuthorizeNetResponse($response);

            if ($this->AUTHORIZENET_SUCCESS_ACK === (int)$parsedResponse[$this->AUTHORIZENET_RESPONSE_ACK_INX])
            {
                $this->transactionId     = $parsedResponse[$this->AUTHORIZENET_RESPONSE_TRX_ID_INX];
                $this->authorizationCode = $parsedResponse[$this->AUTHORIZENET_RESPONSE_AUTH_INX  ];
                return true;
            }
            else
            {
                $this->transactionErrorMessage = $parsedResponse[$this->AUTHORIZENET_RESPONSE_REASON_INX];
            }
        }
        else
        {
            $this->transactionErrorMessage = print_r($response->errors, true);
        }

        wc_add_notice($erroMessage, 'error');
        return false;
    }

    protected function parseAuthorizeNetResponse($response)
    {
        return explode($this->AUTHORIZENET_DELIMITED_CHAR, $response['body']);
    }

    protected function getAuthorizeNetRequestData()
    {
        if ($this->order AND $this->order != null)
        {
            return array(
                "x_login"          => $this->authorizeNetApiLoginId,
                "x_tran_key"       => $this->authorizeNetTransactionKey,
                "x_version"        => $this->AUTHORIZENET_API_VERSION,
                "x_delim_data"     => $this->AUTHORIZENET_DELIMITED_DATA,
                "x_delim_char"     => $this->AUTHORIZENET_DELIMITED_CHAR,
                "x_relay_response" => $this->AUTHORIZENET_RELAY_RESPONSE,
                "x_type"           => $this->AUTHORIZENET_TRX_TYPE,
                "x_method"         => $this->AUTHORIZENET_TRX_METHOD,
                "x_invoice_num"    => $this->order->id,
                "x_cust_id" 	   => $this->order->user_id,
                "x_amount"         => $this->order->get_total(),
                "x_first_name"     => $this->order->billing_first_name,
                "x_last_name"      => $this->order->billing_last_name,
                "x_state"          => $this->order->billing_state,
                "x_zip"            => $this->order->billing_postcode,
                "x_customer_ip"    => $_SERVER['REMOTE_ADDR'],
                "x_card_typ"       => $_POST['billing_cardtype'  ],
                "x_card_num"       => $_POST['billing_credircard'],
                "x_card_code"      => $_POST['billing_ccvnumber' ],
                "x_exp_date"       => sprintf('%s%s'  , $_POST['billing_expdatemonth'], $_POST['billing_expdateyear']),
                "x_address"        => sprintf('%s, %s', $_POST['billing_address_1'   ], $_POST['billing_address_2'  ])
            );
        }

        return false;
    }

    private function isCreditCardNumber($toCheck)
    {
        if (!is_numeric($toCheck))
            return false;

        $number = preg_replace('/[^0-9]+/', '', $toCheck);
        $strlen = strlen($number);
        $sum    = 0;

        if ($strlen < 13)
            return false; 

        for ($i=0; $i < $strlen; $i++)
        {
            $digit = substr($number, $strlen - $i - 1, 1);
            if($i % 2 == 1)
            {
                $sub_total = $digit * 2;
                if($sub_total > 9)
                {
                    $sub_total = 1 + ($sub_total - 10);
                }
            }
            else
            {
                $sub_total = $digit;
            }
            $sum += $sub_total;
        }

        if ($sum > 0 AND $sum % 10 == 0)
            return true; 

        return false;
    }

    private function isCCVNumber($toCheck)
    {
        $length = strlen($toCheck);
        return is_numeric($toCheck) AND $length > 2 AND $length < 5;
    }

    private function isCorrectCardType($toCheck)
    {
        return $toCheck AND in_array($toCheck, $this->acceptableCards);
    }

    private function isCorrectExpireDate($month, $year)
    {
        $now        = time();
        $thisYear   = (int)date('Y', $now);
        $thisMonth  = (int)date('m', $now);
        
        if (is_numeric($year) && is_numeric($month))
        {
            $thisDate   = mktime(0, 0, 0, $thisMonth, 1, $thisYear);
            $expireDate = mktime(0, 0, 0, $month    , 1, $year    );
            
            return $thisDate <= $expireDate;
        }

        return false;
    }
}

function add_authorizenet_gateway($methods)
{
    array_push($methods, 'denonstudio_authorizenet');
    return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_authorizenet_gateway');


