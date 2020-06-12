<?php

use Checkout\CheckoutApi;
use Checkout\Models\Address;
use Checkout\Models\Payments\Capture;
use Checkout\Models\Payments\Payment;
use Checkout\Models\Payments\Refund;
use Checkout\Models\Payments\Shipping;
use Checkout\Models\Payments\ThreeDs;
use Checkout\Models\Payments\Risk;
use Checkout\Models\Payments\TokenSource;
use Checkout\Models\Payments\Voids;
use Checkout\Models\Payments\BillingDescriptor;
use Checkout\Models\Phone;
use Checkout\Models\Payments\IdSource;
use Checkout\Library\Exceptions\CheckoutHttpException;
use Checkout\Library\Exceptions\CheckoutModelException;


/**
 * Checkoutcom_Ckopayment_Model_CheckoutcomCards
 */
class Checkoutcom_Ckopayment_Model_CheckoutcomCards extends Mage_Payment_Model_Method_Cc
{
    protected $_code = Checkoutcom_Ckopayment_Helper_Data::CODE_CHECKOUT_COM_CARDS;
    protected $_formBlockType = 'ckopayment/form_checkoutcomCards';
    protected $_infoBlockType = 'ckopayment/info_checkoutcomCards';

    protected $_isGateway = true;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canVoid = true;
    protected $_canOrder = true;
    protected $_canSaveCc = false;
    protected $_canCaptureOnce = true;
    protected $_canCapturePartial = true;

    protected $_canRefundInvoicePartial = true;

    /**
     * @return $this|Mage_Payment_Model_Abstract
     */
    public function validate()
    {
        return $this;
    }

    /**
     * Used when there is redirection.
     * Example 3Ds and APMs
     * @return bool
     */
    public function getOrderPlaceRedirectUrl()
    {
        $session = Mage::getSingleton('ckopayment/session_quote');
        $is3d = $session->getIs3d();
        $is3dUrl = $session->getPaymentRedirectUrl();

        $session
            ->setIs3d(false)
            ->setPaymentRedirectUrl(false);

        if ($is3d && $is3dUrl) {
            return $is3dUrl;
        }

        return false;
    }

    /**
     * Used to process auth payment
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return $this|Mage_Payment_Model_Abstract
     * @throws Mage_Core_Exception
     * @throws Mage_Core_Model_Store_Exception
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        $requestData = Mage::app()->getRequest()->getParam('payment');
        $session = Mage::getSingleton('ckopayment/session_quote');
        $isSaveCard = false;
        $identification = "";
        $billingName = "";

        // Check if card token exist
        if (empty($requestData['cko_card_token'])) {
            if (isset($requestData['customer_card'])) {
                if ($requestData['customer_card'] != 'new_card') {
                    $isSaveCard = true;
                    $source = $requestData;
                }
            } else {
                $message = "There was an issue completing the payment.";

                // Log error in var/log/checkoutcomframes.log
                Mage::log($message, null, $this->_code . '.log');

                //Throw exception and stop order process
                Mage::throwException($message);
            }
        }

        if(empty($requestData['cko_dlocal_id'])) {
            $message = "The ID Document can not empty.";

            // Log error in var/log/checkoutcomframes.log
            Mage::log($message, null, $this->_code . '.log');

            //Throw exception and stop order process
            Mage::throwException($message);
        } else {
           $identification =  $requestData['cko_dlocal_id'];
        }

        $isSaveCardCheck = false;

        // Check if save card checkbox was checked in front end.
        if (isset($requestData['save_card_check']) && $requestData['save_card_check'] == 1) {
            $isSaveCardCheck = true;
        }

        // Order information
        $order = $payment->getOrder();
        $quoteId = $order->getQuoteId();
        $orderId = $payment->getOrder()->getIncrementId();

        // Check if order has remote ip
        // If empty, then order comes from admin
        if(!$order->getRemoteIp()){
            // Get posted param from admin order creation
            $adminOrderParams = Mage::app()->getRequest()->getParam('order');
            $currencyCode = $adminOrderParams['currency'];
        } else {
            $currencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();
        }

        if(!empty($requestData['cko_customer_name'])) {
            $billingName = $requestData['cko_customer_name'];
        }

        // Format amount to cent
        $amountCent = Mage::getModel('ckopayment/checkoutcomUtils')->valueToDecimal($order->getGrandTotal(), $currencyCode);

        if ($isSaveCard) {
            // Charge request params with source id
            $requestParam = $this->_getRequestParam($requestData, $amountCent, $currencyCode, $quoteId, $orderId, $isSaveCard, $identification, $billingName);
        } else {
            // Charge request params with card token
            $requestParam = $this->_getRequestParam($requestData, $amountCent, $currencyCode, $quoteId, $orderId, null, $identification, $billingName);
        }

        $environment =  Mage::getModel('ckopayment/checkoutcomConfig')->getEnvironment() == 'sandbox' ? true : false;
        // Initialize the Checkout Api
        $checkout = new CheckoutApi($this->_getSecretKey(), $environment);

        try {
            // Call to create charge
            $response = $checkout->payments()->request($requestParam);

            // Check if payment successful
            if ($response->isSuccessful()) {
                // Check if payment is 3Dsecure
                if ($response->isPending()) {
                    // Check if redirection link exist
                    if ($response->getRedirection()) {
                        // do redirection to 3D page
                        // Update order payment information with payment id from checkout.com
                        $payment->setAdditionalInformation('is3d', true);
                        $payment->setIsTransactionPending(true);

                        $session
                            ->setIs3d(true)
                            ->setPaymentRedirectUrl($response->getRedirection())
                            ->setIsSaveCardCheck($isSaveCardCheck);
                    } else {
                        $errorMessage = "An error has occurred while processing your payment. Redirection link not found";

                        // Log error in var/log/checkoutcomframes.log
                        Mage::log($errorMessage, null, $this->_code . '.log');

                        //Throw exception and stop order process
                        Mage::throwException($errorMessage);
                    }
                } else {
                    // Payment successful continue order process

                    // check if payment was flagged
                    if ($response->risk['flagged']) {
                        // Payment Flagged status from config
                        $flagStatus = Mage::getModel('ckopayment/checkoutcomConfig')->getFlaggedOrderStatus();

                        if ($flagStatus == "suspected_fraud") {
                            $payment->setIsFraudDetected(true);
                        }

                        $order->addStatusHistoryComment('Payment flagged on Checkout.com ', false);
                    }

                    $source = $response->source;

                    switch ($source['type']) {
                        case 'card':
                            $order->getPayment()->setCcLast4($source['last4'])->setCcType($source['scheme']);
                            break;
                        default:
                            break;
                    }

                    //check if saved card checkbox was check to save source id in db
                    if ($isSaveCardCheck) {
                        Mage::getModel('ckopayment/customerCard')->saveCard($response, $isSaveCardCheck, $order->getCustomerId());
                    }

                    // Update order payment information with payment id from checkout.com
                    $payment->setTransactionId($response->action_id);
                    $payment->setAdditionalInformation('ckoPaymentId', $response->id);

                    $payment->setIsTransactionClosed(0);
                }
            } else {
                $errorMessage = "An error has occurred while processing your payment. Please check your card details and try again.";

                // Log error in var/log/checkoutcomframes.log
                Mage::log($errorMessage, null, $this->_code . '.log');
                Mage::log($response, Zend_Log::DEBUG, $this->_code . '.log', true);

                //Throw exception and stop order process
                Mage::throwException($errorMessage);
            }
        } catch (CheckoutModelException $ex) {
            $errorMessage = "An error has occurred while processing your payment. ";
            Mage::log($errorMessage, null, $this->_code . '.log');
            Mage::log($ex->getBody(), Zend_Log::DEBUG, $this->_code . '.log', true);
            Mage::throwException($errorMessage);
        } catch (CheckoutHttpException $ex) {
            Mage::log($ex->getBody(), Zend_Log::DEBUG, $this->_code . '.log', true);
            $errorMessage = "An error has occurred while processing your payment. ";
            Mage::throwException($errorMessage);
        }

        return $this;
    }

    /**
     * Used to process capture from backend
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return $this|Mage_Payment_Model_Abstract
     * @throws Mage_Core_Exception
     */
    public function capture(Varien_Object $payment, $amount)
    {
        $ckoPaymentId = $payment->getAdditionalInformation('ckoPaymentId');
        $order = $payment->getOrder();
        $orderId = $order->getIncrementId();

        // Check if CKO payment id exist in order
        if (empty($ckoPaymentId)) {
            $errorMessage = 'CKO PaymentId not found for order Id : ' . $orderId;

            Mage::log($errorMessage, null, $this->_code . '.log');
            Mage::throwException($errorMessage);
        }

        $currencyCode = $order->getOrderCurrencyCode();
        $grandTotals = $order->getGrandTotal();
        $grandTotalsCents = Mage::getModel('ckopayment/checkoutcomUtils')->valueToDecimal($grandTotals, $currencyCode);
        $amountCents = Mage::getModel('ckopayment/checkoutcomUtils')->valueToDecimal($amount, $currencyCode);

        $amountLessThanGrandTotal = $amountCents < $grandTotalsCents ? true : false;
        $environment =  Mage::getModel('ckopayment/checkoutcomConfig')->getEnvironment() == 'sandbox' ? true : false;
        // Initialize the Checkout Api
        $checkout = new CheckoutApi($this->_getSecretKey(), $environment);

        try {

            // Check if payment is already voided or captured on checkout.com hub
            $details = $checkout->payments()->details($ckoPaymentId);

            if ($details->status == 'Voided' || $details->status == 'Captured' && !$amountLessThanGrandTotal) {
                $errorMessage = 'Payment has already been voided or captured on Checkout.com hub for order Id : ' . $orderId;

                Mage::log($errorMessage, null, $this->_code . '.log');
                Mage::throwException($errorMessage);

                return $this;
            }

            $ckoPayment = new Capture($ckoPaymentId);

            // Process partial capture if amount is less than grand total
            if ($amountLessThanGrandTotal) {
                $ckoPayment->amount = $amountCents;
                $ckoPayment->reference = $orderId;
            }

            $response = $checkout->payments()->capture($ckoPayment);

            if (!$response->isSuccessful()) {
                $errorMessage = 'An error has occurred while processing your capture payment on Checkout.com hub. Order Id : ' . $orderId;

                Mage::log($errorMessage, null, $this->_code . '.log');
                Mage::log($response, Zend_Log::DEBUG, $this->_code . '.log', true);

                Mage::throwException($errorMessage);
            } else {

                $parentTransactionId = $this->getCkoParentTransId('Authorization', $ckoPaymentId);

                $payment->setTransactionId($response->action_id);
                $payment->setParentTransactionId($parentTransactionId);
                $payment->setIsTransactionClosed(0);

                $order->setPaymentIsCaptured(1);
                $order->save();
            }
        } catch (CheckoutModelException $ex) {
            $errorMessage = "An error has occurred while processing your capture request. ";
            Mage::log($errorMessage, null, $this->_code . '.log');
            Mage::log($ex->getBody(), Zend_Log::DEBUG, $this->_code . '.log', true);
            Mage::throwException($errorMessage);
        } catch (CheckoutHttpException $ex) {
            Mage::log($ex->getMessage(), Zend_Log::DEBUG, $this->_code . '.log', true);
            $errorMessage = "An error has occurred while processing your capture request. ";
            Mage::throwException($errorMessage);
        }

        return $this;
    }
        
    /**
     * Used to process refund from backend
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return $this|Mage_Payment_Model_Abstract
     * @throws Mage_Core_Exception
     */
    public function refund(Varien_Object $payment, $amount)
    {
        $ckoPaymentId = $payment->getAdditionalInformation('ckoPaymentId');
        $order = $payment->getOrder();
        $orderId = $order->getIncrementId();

        // Check if CKO payment id exist in order
        if (empty($ckoPaymentId)) {
            $errorMessage = 'CKO PaymentId not found for order Id : ' . $orderId;

            Mage::log($errorMessage, null, $this->_code . '.log');
            Mage::throwException($errorMessage);
        }

        $currencyCode = $order->getOrderCurrencyCode();
        $grandTotals = $order->getGrandTotal();
        $grandTotalsCents = Mage::getModel('ckopayment/checkoutcomUtils')->valueToDecimal($grandTotals, $currencyCode);
        $amountCents = Mage::getModel('ckopayment/checkoutcomUtils')->valueToDecimal($amount, $currencyCode);

        $amountLessThanGrandTotal = $amountCents < $grandTotalsCents ? true : false;

        $environment =  Mage::getModel('ckopayment/checkoutcomConfig')->getEnvironment() == 'sandbox' ? true : false;
        // Initialize the Checkout Api
        $checkout = new CheckoutApi($this->_getSecretKey(), $environment);

        try {
            // Check if payment is already voided or captured on checkout.com hub
            $details = $checkout->payments()->details($ckoPaymentId);

            if ($details->status == 'Refunded' && !$amountLessThanGrandTotal) {
                $errorMessage = 'Payment has already been refunded on Checkout.com hub for order Id : ' . $orderId;

                Mage::log($errorMessage, null, $this->_code . '.log');
                Mage::throwException($errorMessage);

                return $this;
            }

            $ckoPayment = new Refund($ckoPaymentId);

            // Process partial refund if amount is less than grand total
            if ($amountLessThanGrandTotal) {
                $ckoPayment->amount = $amountCents;
                $ckoPayment->reference = $orderId;
            }

            $response = $checkout->payments()->refund($ckoPayment);

            if (!$response->isSuccessful()) {
                $errorMessage = 'An error has occurred while processing your refund payment on Checkout.com hub. Order Id : ' . $orderId;

                Mage::log($errorMessage, null, $this->_code . '.log');
                Mage::log($response, Zend_Log::DEBUG, $this->_code . '.log', true);

                Mage::throwException($errorMessage);
            } else {

                $parentTransactionId = $this->getCkoLastTransId('Refund', $ckoPaymentId);
                $payment->setTransactionId($response->action_id);
                $payment->setParentTransactionId($parentTransactionId);

                if ($amountLessThanGrandTotal) {
                    $payment->setIsTransactionClosed(0);
                } else {
                    $order->setPaymentIsRefunded(1);
                }
                
                $order->save();
            }

        } catch (CheckoutModelException $ex) {
            $errorMessage = "An error has occurred while processing your refund request. ";
            Mage::log($errorMessage, null, $this->_code . '.log');
            Mage::log($ex->getBody(), Zend_Log::DEBUG, $this->_code . '.log', true);
            Mage::throwException($errorMessage);
        } catch (CheckoutHttpException $ex) {
            Mage::log($ex->getMessage(), Zend_Log::DEBUG, $this->_code . '.log', true);
            $errorMessage = "An error has occurred while processing your refund request. ";
            Mage::throwException($errorMessage);
        }

        return $this;
    }

    /**
     * Used to process void from backend
     *
     * @param Varien_Object $payment
     * @return $this|Mage_Payment_Model_Abstract
     * @throws Mage_Core_Exception
     */
    public function void(Varien_Object $payment)
    {
        $ckoPaymentId = $payment->getAdditionalInformation('ckoPaymentId');
        $order = $payment->getOrder();
        $orderId = $order->getIncrementId();

        // Check if CKO payment id exist in order
        if (empty($ckoPaymentId)) {
            $errorMessage = 'CKO PaymentId not found for order Id : ' . $orderId;

            Mage::log($errorMessage, null, $this->_code . '.log');
            Mage::throwException($errorMessage);
        }

        $environment =  Mage::getModel('ckopayment/checkoutcomConfig')->getEnvironment() == 'sandbox' ? true : false;
        // Initialize the Checkout Api
        $checkout = new CheckoutApi($this->_getSecretKey(), $environment);

        try {
            // Check if payment is already voided or captured on checkout.com hub
            $details = $checkout->payments()->details($ckoPaymentId);

            if ($details->status == 'Voided' || $details->status == 'Captured') {
                $errorMessage = 'Payment has already been voided or captured on Checkout.com hub for order Id : ' . $orderId;

                Mage::log($errorMessage, null, $this->_code . '.log');
                Mage::throwException($errorMessage);

                return $this;
            }

            // Prepare void payload
            $ckoPayment = new Voids($ckoPaymentId, $orderId);

            // Process void payment on checkout.com
            $response = $checkout->payments()->void($ckoPayment);

            if (!$response->isSuccessful()) {
                $errorMessage = 'An error has occurred while processing your void payment on Checkout.com hub. Order Id : ' . $orderId;

                Mage::log($errorMessage, null, $this->_code . '.log');
                Mage::log($response, Zend_Log::DEBUG, $this->_code . '.log', true);

                Mage::throwException($errorMessage);
            } else {
                $order->setPaymentIsVoided(1);
                $parentTransactionId = $this->getCkoParentTransId('Authorization', $ckoPaymentId);
                $payment->setTransactionId($response->action_id);
                $payment->setParentTransactionId($parentTransactionId);

                $order->save();
            }
        } catch (CheckoutModelException $ex) {
            $errorMessage = "An error has occurred while processing your void request. ";
            Mage::log($errorMessage, null, $this->_code . '.log');
            Mage::log($ex->getBody(), Zend_Log::DEBUG, $this->_code . '.log', true);
            Mage::throwException($errorMessage);
        } catch (CheckoutHttpException $ex) {
            Mage::log($ex->getMessage(), Zend_Log::DEBUG, $this->_code . '.log', true);
            $errorMessage = "An error has occurred while processing your void request. ";
            Mage::throwException($errorMessage);
        }

        return $this;
    }

    /**
     * Return full charge request
     *
     * @param $cardToken
     * @param $amount
     * @param $currencyCode
     * @param $quoteId
     * @return Payment
     */
    private function _getRequestParam($token, $amount, $currencyCode, $quoteId, $orderId, $isSaveCard = null, $identification, $billingName)
    {
        $session = Mage::getSingleton('ckopayment/session_quote');
        $quote = Mage::getModel('ckopayment/checkoutcomUtils')->getQuote($quoteId);

        // Get billing address from quote
        $billingAddress = $quote->getBillingAddress();
        // Get billing Street from billing address
        $billStreet = Mage::helper('customer/address')
            ->convertStreetLines($billingAddress->getStreet(), 2);

        // Billing address details
        $billStreet1 = $billStreet[0];
        $billStreet2 = $billStreet[1];
        $billCity = $billingAddress->getCity();
        $billRegion =$billingAddress->getRegion();
        $billPostcode = $billingAddress->getPostcode();
        $billCountry = $billingAddress->getCountry();
        $customerName = $billingAddress->getFirstName() . ' ' . $billingAddress->getLastName() ;

        $email = Mage::helper('ckopayment')->getCustomerEmail($quoteId);

        // Get Shipping address from quote
        $shippingAddress = $quote->getShippingAddress();
        // Get Shipping Street from billing address
        $shipStreet = Mage::helper('customer/address')
            ->convertStreetLines($shippingAddress->getStreet(), 2);

        // Shipping address details
        $shipStreet1 = $shipStreet[0];
        $shipStreet2 = $shipStreet[1];
        $shipCity = $shippingAddress->getCity();
        $shipRegion =$shippingAddress->getRegion();
        $shipPostcode = $shippingAddress->getPostcode();
        $shipCountry = $shippingAddress->getCountry();

        // validation check for admin order creation
        if (empty($billStreet1) && empty($shipStreet1)) {

            // Get admin order param from post
            $adminOrderParams = Mage::app()->getRequest()->getParam('order');

            // Get billing address details from post for admin orders
            if (empty($billStreet1) && !empty($adminOrderParams)) {
                $billingAddress = $adminOrderParams['billing_address'];
                $billStreet1 = $billingAddress['street'][0];
                $billStreet2 = $billingAddress['street'][1];
                $billCity = $billingAddress['city'];
                $billRegion = $billingAddress['region'];
                $billPostcode = $billingAddress['postcode'];
                $billCountry = $billingAddress['country_id'];
                $customerName = $billingAddress['firstname'] . ' ' . $billingAddress['lastname'] ;
                $email = $adminOrderParams['account']['email'];
            } else {
                $customerName = $billingAddress->getName();
            }

            // Get Shipping address details from post for admin orders
            if (empty($shipStreet1) && !empty($adminOrderParams)) {

                $adminParam = Mage::app()->getRequest()->getParams();

                // if shipping same as billing
                if($adminParam['shipping_as_billing'] == 1) {
                    $shipStreet1 = $billStreet1;
                    $shipStreet2 = $billStreet2;
                    $shipCity = $billCity;
                    $shipRegion = $billRegion;
                    $shipPostcode = $billPostcode;
                    $shipCountry = $billCountry;
                } else {
                    $shippingAddress = $adminOrderParams['shipping_address'];
                    $shipStreet1 = $shippingAddress['street'][0];
                    $shipStreet2 = $shippingAddress['street'][1];
                    $shipCity = $shippingAddress['city'];
                    $shipRegion = $shippingAddress['region'];
                    $shipPostcode = $shippingAddress['country_id'];
                    $shipCountry = $billingAddress['country_id'];
                }
            }
        }

        $autoCapture = $this->_getAutoCapture() == 1 ? true : false;
        $threeD = $this->_getThreeDs() == 1 ? true : false;
        $noThreeD = $this->_getNoThreeD() == 1 ? true : false;
        $descriptor = Mage::getModel('ckopayment/checkoutcomConfig')->getDynamicDescriptor() == 1 ? true : false;
        $isMadaEnable = $this->_isMadaEnable() == 1 ? true : false;

        if ($isSaveCard) {
            // Get source id from db
            $sourceId = $this->_getSourceId($token['customer_card']);

            //Create a payment method instance with source id
            $method = new IdSource($sourceId);

            if($this->getIsCvvRequire()){
                $method->cvv = $token['cc_id'];
            }

        } else {
            // Create a payment method instance with card token
            $method = new TokenSource($token['cko_card_token']);
        }

        // Set Billing address in param
        $billingAddressParam = new Address();
        $billingAddressParam->address_line1 = $billStreet1;
        $billingAddressParam->address_line2 = $billStreet2;
        $billingAddressParam->city = $billCity;
        $billingAddressParam->state = $billRegion;
        $billingAddressParam->zip = $billPostcode;
        $billingAddressParam->country = $billCountry;
        $method->billing_address = $billingAddressParam;

        // Prepare the payment parameters
        $payment = new Payment($method, $currencyCode);
        $payment->capture = $autoCapture;
        $payment->amount = $amount;
        $payment->reference = $orderId;

        $payment->customer = array(
            'email' => $email,
            'name' => $customerName,
        );

        // Set 3ds to false if admin order
        if(Mage::app()->getRequest()->getParam('order')){
            $threeD = false;
            $risk = new Risk(false);
            $payment->risk = $risk;
        }

        $threeDs = new ThreeDs($threeD);

        if ($threeD) {
            $threeDs->attempt_n3d = $noThreeD;
        }

        // Set 3Ds to payment request
        $payment->threeDs = $threeDs;

        // Set dynamic descriptor if it is set in admin config
        if ($descriptor) {
            $descriptorName = Mage::getModel('ckopayment/checkoutcomConfig')->getDescriptorName();
            $descriptorCity = Mage::getModel('ckopayment/checkoutcomConfig')->getDescriptorCity();
            $descriptor = new BillingDescriptor($descriptorName, $descriptorCity);
            $payment->billing_descriptor = $descriptor;
        }

        // Set shipping Address
        $shippingAddressParam = new Address();
        $shippingAddressParam->address_line1 = $shipStreet1;
        $shippingAddressParam->address_line2 = $shipStreet2;
        $shippingAddressParam->city = $shipCity;
        $shippingAddressParam->state = $shipRegion;
        $shippingAddressParam->zip = $shipPostcode;
        $shippingAddressParam->country = $shipCountry;

        $payment->shipping = new Shipping($shippingAddressParam);

        // Set redirection url in payment request
        $payment->success_url = Mage::getBaseUrl() . 'ckopayment/api/success';
        $payment->failure_url = Mage::getBaseUrl() . 'ckopayment/api/error';
        
        // Additional info in metadata
        $udf5 = "Platform Data - Magento " . Mage::getVersion() 
        . ", Integration Data - Checkout.com " 
        . Mage::helper('ckopayment')->getExtensionVersion() 
        . ", SDK Data - PHP SDK ". CheckoutApi::VERSION 
        . ", order Id - " . $orderId . ", Server - " . Mage::getBaseUrl();

        $metadata = array(
            'udf5' => $udf5
        );

        $payment->processing  = array(
            'dlocal' => array(
                'country'  => "MX",
                'payer' => array(
                    'document'  => $identification,
                    'name'  => $billingName,
                    'email'  =>  $email 
                )
            )
        );


        // set capture delay if payment action is authorise and capture
        if($autoCapture){
            $captureDelay =  Mage::getModel('ckopayment/checkoutcomConfig')->getDelayedCaptureTimestamp();
            $payment->capture_on = $captureDelay;
        }

        // Check if mada is enable from admin module config
        if($isMadaEnable){

            $madaCard = false;

            if (!empty($token['cko_card_bin'])) {
                // Check if bin is mada card
                $madaCard = Mage::helper('ckopayment')->isMadaCard($token['cko_card_bin']);
            } else {
                // Check if saved card
                if ($isSaveCard) {
                    // Check if source id is from mada card
                    $isSourceIdMada = $this->_isSourceIdMada($sourceId);
                    $madaCard = $isSourceIdMada == 1 ? true : false;

                    if($madaCard){
                        $method->cvv = $token['cc_id'];
                    }
                }
            }

            if($madaCard){
                $payment->capture = true;
                $payment->capture_on = null;
                $payment->threeDs =  new ThreeDs(true);
                $metadata = array_merge($metadata, array('udf1' => 'Mada'));

                $session->setIsMadaCard(true);
            }
        }

        // Set additional info in payment request
        $payment->metadata = $metadata;

        return $payment;
    }

    /**
     * Return secret key from checkout.com config model
     *
     * @return mixed
     */
    private function _getSecretKey()
    {
        return Mage::getModel('ckopayment/checkoutcomConfig')->getSecretKey();
    }

    /**
     * Get Auto capture from admin module setting
     *
     * @return mixed
     */
    private function _getAutoCapture()
    {
        return Mage::getModel('ckopayment/checkoutcomConfig')->getPaymentAction();
    }

    /**
     * Get 3d from admin module setting
     *
     * @return mixed
     */
    private function _getThreeDs()
    {
        return Mage::getModel('ckopayment/checkoutcomConfig')->getThreeD();
    }

    /**
     * Get attempt non 3d from admin module setting
     *
     * @return mixed
     */
    private function _getNoThreeD()
    {
        return Mage::getModel('ckopayment/checkoutcomConfig')->getAttemptNoThreeD();
    }

    /**
     * Get save card enable from admin module setting
     *
     * @return mixed
     */
    public function getIsSaveCardEnable()
    {
        return Mage::helper('ckopayment')->getConfigData($this->_code, 'ckocom_card_saved');
    }

    /**
     * Get the save card title from admin module setting
     *
     * @return mixed
     */
    public function getSaveCardTitle()
    {
        return Mage::helper('ckopayment')->getConfigData($this->_code, 'ckocom_card_saved_title');
    }

    public function getIsCvvRequire()
    {
        return Mage::helper('ckopayment')->getConfigData($this->_code, 'ckocom_require_cvv');
    }

    /**
     * Return customer from session if logged in
     * @return string
     */
    public function getCustomerId()
    {
        $customerId = '';

        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $customerData = Mage::getSingleton('customer/session')->getCustomer();
            $customerId = $customerData->getId();
        }

        return $customerId;
    }

    /**
     * Return source_id from db
     *
     * @param $token
     * @return mixed
     */
    private function _getSourceId($token)
    {
        try {
            $resource = Mage::getSingleton('core/resource');
            $readConnection = $resource->getConnection('core_read');
            $table = $resource->getTableName('ckopayment_cards');
            $writeConnection = $resource->getConnection('core_write');
            $query = "SELECT source_id FROM {$table} WHERE entity_id = '{$token}'";
            $result = $writeConnection->fetchAll($query);
        } catch (Exception $ex) {
            Mage::log('Error Getting source id from db.', null, $this->_code . '.log');
            Mage::log($ex, Zend_Log::DEBUG, $this->_code . '.log', true);
        }

        $sourceId = $result[0]['source_id'];

        return $sourceId;
    }

    /**
     * Get enable mada option from admin module setting
     *
     * @return mixed
     */
    private function _isMadaEnable()
    {
        return Mage::helper('ckopayment')->getConfigData($this->_code, 'ckocom_card_mada');
    }

    /**
     * Return is_mada from db
     *
     * @param $sourceId
     * @return mixed
     */
    private function _isSourceIdMada($sourceId)
    {
        try {
            $resource = Mage::getSingleton('core/resource');
            $readConnection = $resource->getConnection('core_read');
            $table = $resource->getTableName('ckopayment_cards');
            $writeConnection = $resource->getConnection('core_write');
            $query = "SELECT is_mada FROM {$table} WHERE source_id = '{$sourceId}'";
            $result = $writeConnection->fetchAll($query);
        } catch (Exception $ex) {
            Mage::log('Error Getting source id from db.', null, $this->_code . '.log');
            Mage::log($ex, Zend_Log::DEBUG, $this->_code . '.log', true);
        }

        $isMada = $result[0]['is_mada'];

        return $isMada;
    }

    /**
     * getCkoParentTransId
     * 
     * Get payment action id from cko 
     *
     * @param  mixed $actionType
     * @param  mixed $ckoPaymentId
     * @return void
     */
    public function getCkoParentTransId($actionType, $ckoPaymentId) 
    {
        $environment =  Mage::getModel('ckopayment/checkoutcomConfig')->getEnvironment() == 'sandbox' ? true : false;
        // Initialize the Checkout Api
        $checkout = new CheckoutApi($this->_getSecretKey(), $environment);
        
        $actions = $checkout->payments()->actions($ckoPaymentId);
        $parentTransactionId = '';

        foreach ($actions as $action) {
            foreach ($action as $act) {
                if ($act->type == $actionType ) {
                    $parentTransactionId = $act->id;
                }
            }
        }

        return $parentTransactionId;
    }
    
    /**
     * getCkoLastTransId
     *
     * @param  mixed $actionType
     * @param  mixed $ckoPaymentId
     * @return void
     */
    public function getCkoLastTransId($actionType, $ckoPaymentId) 
    {
        $environment =  Mage::getModel('ckopayment/checkoutcomConfig')->getEnvironment() == 'sandbox' ? true : false;
        // Initialize the Checkout Api
        $checkout = new CheckoutApi($this->_getSecretKey(), $environment);
        
        $actions = $checkout->payments()->actions($ckoPaymentId);
        $parentTransactionId = '';

        foreach ($actions as $action) {
            $test = $action[1];
            $parentTransactionId = $test->id;

            return $parentTransactionId;
        }
        
        return $parentTransactionId;
    }

    /**
     * getTotalRefunded
     *
     * @param  mixed $ckoPaymentId
     * @return void
     */
    public function getTotalRefunded($ckoPaymentId) 
    {
        $environment =  Mage::getModel('ckopayment/checkoutcomConfig')->getEnvironment() == 'sandbox' ? true : false;
        // Initialize the Checkout Api
        $checkout = new CheckoutApi($this->_getSecretKey(), $environment);
        
        $actions = $checkout->payments()->actions($ckoPaymentId);

        $arr = array();

        foreach ($actions as $action) {
            foreach ($action as $act) {
                if ($act->type == 'Refund' ) {
                    $totalRefunded += $act->amount;
                }
            }
        }

        return $totalRefunded;
    }
}