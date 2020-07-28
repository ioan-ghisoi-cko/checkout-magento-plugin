<?php

use Checkout\CheckoutApi;
use Checkout\Checkout\Models\Tokens;
use Checkout\Library\Exceptions\CheckoutHttpException;
use Checkout\Library\Exceptions\CheckoutModelException;
use Checkout\Models\Tokens\ApplePay;
use Checkout\Models\Tokens\ApplePayHeader;
use Checkout\Models\Tokens\GooglePay;

/**
 * Class Checkoutcom_Ckopayment_ApiController
 */
class Checkoutcom_Ckopayment_ApiController extends Mage_Core_Controller_Front_Action
{
    const CONFIG = 'ckopayment/checkoutcomConfig';
    const WEBHOOK = 'ckopayment/checkoutcomWebhook';
    const UTILS = 'ckopayment/checkoutcomUtils';

    /**
     * Get Magento model for the config
     *
     * @return mixed
     */
    public function _getConfigModel()
    {
        return Mage::getModel(self::CONFIG);
    }

    /**
     * Get Webhook model for the config
     *
     * @return mixed
     */
    public function _getWebhookModel()
    {
        return Mage::getModel(self::WEBHOOK);
    }

    public function _getUtilsModel()
    {
        return Mage::getModel(self::UTILS);
    }

    /**
     * Routing for webhooks from checkout.com
     * @url ckopayment/api/webhook
     */
    public function webhookAction()
    {
        $webhookCode = Checkoutcom_Ckopayment_Helper_Data::CODE_CHECKOUT_COM_WEBHOOK_LOG;

        // Check if post is empty
        if (!$this->getRequest()->isPost()) {
            Mage::log('Empty post data', null, $webhookCode);

            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        $request = new Zend_Controller_Request_Http();
        $key = $request->getHeader('Authorization');

        // Get psk from admin module config
        $privateSharedKey = $this->_getConfigModel()->getPrivateSharedKey();

        if ($key !== $privateSharedKey) {
            Mage::log(
                "Webhook authorisation key {$key} and private shared key on module config {$privateSharedKey} not match.",
                null,
                $webhookCode
            );

            $this->getResponse()->setHttpResponseCode(401);
            return;
        }

        $webhookData = json_decode(file_get_contents('php://input'));

        if (empty($webhookData)) {
            Mage::log('Empty webhook data', null, $webhookCode);

            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        $eventType = $webhookData->type;
        $response = false;

        switch ($eventType) {
            case 'payment_expired':
                // TODO check for expired 3d or apms
                break;
            case 'payment_voided':
                $response = $this->_getWebhookModel()->voidPayment($webhookData);
                break;
            case 'payment_captured':
                $response = $this->_getWebhookModel()->capturePayment($webhookData);
                break;
            case 'payment_refunded':
                $response = $this->_getWebhookModel()->refundPayment($webhookData);
                break;
            case 'payment_canceled':
                $response = $this->_getWebhookModel()->cancelPayment($webhookData);
                break;    
            default:
                $response = true;
                return;
        }

        $httpCode = $response ? 200 : 500;
        $this->getResponse()->setHttpResponseCode($httpCode);
    }

    /**
     * Use when 3Ds payment is successful
     * @url ckopayment/api/success
     *
     * @return Mage_Core_Controller_Varien_Action
     */
    public function successAction()
    {
        $session = Mage::getSingleton('ckopayment/session_quote');
        $isSaveCardCheck = $session->getIsSaveCardCheck();

        $ckoSessionId = (string) $this->getRequest()->getParam('cko-session-id');
        $secretKey = $this->_getConfigModel()->getSecretKey();
        $environment = $this->_getConfigModel()->getEnvironment() == 'sandbox' ? true : false;
        $checkout = new CheckoutApi($secretKey, $environment);
        $response = $checkout->payments()->details($ckoSessionId);
        $source = $response->source;
        $paymentStatus = $response->status;
        $id = isset($response->actions) ? $response->actions[0]['id'] : $response->id;

        if ($response->isSuccessful()) {
            $orderId = $response->reference;
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
            $authStatus = $this->_getConfigModel()->getAuthorisedOrderStatus();

            switch ($source['type']) {
                case 'card':
                    $order->getPayment()->setCcLast4($source['last4'])->setCcType($source['scheme']);
                    $message = '3Ds payment authorised successfully on checkout.com.';
                    break;
                default:
                    $message = strtoupper($source['type']). ' payment completed.';
                    break;
            }

            $amountCents = $response->amount;
            $amount = $this->_getUtilsModel()->decimalToValue($amountCents, $response->currency);
            $payment = $order->getPayment();

            if ($response->risk['flagged']) {
                // Register Authorization
                $payment->setTransactionId($id)
                    ->setShouldCloseParentTransaction(0)
                    ->setAdditionalInformation('ckoPaymentId', $response->id)
                    ->setIsTransactionClosed(0);

                // Payment Flagged status from config
                $flagStatus = Mage::getModel('ckopayment/checkoutcomConfig')->getFlaggedOrderStatus();

                // check if flagged status configured in module equals to suspected_fraud
                if ($flagStatus == "suspected_fraud") {
                    $state = Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW;
                    $status = Mage_Sales_Model_Order::STATUS_FRAUD;
                    $message = 'Payment flagged on Checkout.com';

                    $order->setState($state, $status, $message);

                } else {
                    $order->setState(Mage_Sales_Model_Order::STATE_NEW, true);
                    $order->addStatusHistoryComment('Payment flagged on Checkout.com ', false);
                }

            } elseif ($paymentStatus == 'Captured') {
                $message = '3Ds payment captured successfully on checkout.com.';
                $captureStatus = $this->_getConfigModel()->getCapturedOrderStatus();
                $order->setState($captureStatus, true);
                $order->addStatusHistoryComment($message);
            } else {
                $authorisedStatus = $this->_getConfigModel()->getAuthorisedOrderStatus();
                
                // $order->addStatusHistoryComment($message, false);
                // Register Authorization
                $payment->setTransactionId($id)
                    ->setShouldCloseParentTransaction(0)
                    ->setAdditionalInformation('ckoPaymentId', $response->id)
                    ->setIsTransactionClosed(0)
                    ->registerAuthorizationNotification($amount);

                if ($authorisedStatus == 'pending') {
                    $order->setState(Mage_Sales_Model_Order::STATE_NEW, true);
                }                
            }

            //check if saved card checkbox was check to save source id in db
            if ($isSaveCardCheck) {
                Mage::getModel('ckopayment/customerCard')->saveCard($response, $isSaveCardCheck, $order->getCustomerId());

                $session->setIsSaveCardCheck(false);
            }

            $order->sendNewOrderEmail();
            $order->save();
            return $this->_redirect('checkout/onepage/success');
        }
    }

    /**
     * Redirect to cart
     * @url ckopayment/api/error
     *
     * @return Mage_Core_Controller_Varien_Action
     */
    public function errorAction()
    {
        $redirectUrl = Mage::helper('checkout/url')->getCheckoutUrl();
        $lastOrderIncrementId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        if (!is_null($lastOrderIncrementId)) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($lastOrderIncrementId);

            $order->cancel();
            $order->setStatus(Mage_Sales_Model_Order::STATE_CANCELED);
            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Webhook received from checkout.com. Payment cancelled.');
            $order->save();

            $helper = Mage::helper('ckopayment');
            $helper->restoreQuoteSession($order);
        }
        Mage::getSingleton('core/session')->addError('An error has occurred while processing your payment.
        Please check your payment details and try again. Thank you');
        return $this->_redirectUrl($redirectUrl);
    }

    /**
     * Create the ApplePay session
     * @url ckopayment/api/validateSession
     */
    public function validateSessionAction()
    {
        $url = $this->getRequest()->getParam("url");
        $domain = $this->getRequest()->getParam("domain");
        $displayName = $this->getRequest()->getParam("displayName");

        $merchantId = $this->_getConfigModel()->getAppleMerchantIdentifier();
        $certificate = $this->_getConfigModel()->getAppleCertificate();
        $certificateKey = $this->_getConfigModel()->getAppleCertificateKey();

        if (
            "https" == parse_url($url, PHP_URL_SCHEME) &&
            substr(parse_url($url, PHP_URL_HOST), -10) == ".apple.com"
        ) {
            $ch = curl_init();

            $data =
                '{
                    "merchantIdentifier":"' . $merchantId . '",
                    "domainName":"' . $domain . '",
                    "displayName":"' . $displayName . '"
                }';

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_SSLCERT, $certificate);
            curl_setopt($ch, CURLOPT_SSLKEY, $certificateKey);

            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

            // Log curl error
            if (curl_exec($ch) === false) {
                Mage::log(curl_error($ch), null, 'checkoutcom_applepay.log');
            }

            // close cURL resource, and free up system resources
            curl_close($ch);
        }
    }

    /**
     * Create a card token from a apple wallet payload
     * @url ckopayment/api/generateAppleToken
     */
    public function generateAppleTokenAction()
    {
        
        $appleToken = $this->getRequest()->getParam("token");

        $publicKey = $this->_getConfigModel()->getPublicKey();
        $transactionId = $appleToken["header"]["transactionId"];
        $publicKeyHash = $appleToken["header"]["publicKeyHash"];
        $ephemeralPublicKey = $appleToken["header"]["ephemeralPublicKey"];
        $version = $appleToken["version"];
        $signature = $appleToken["signature"];
        $data = $appleToken["data"];
        $environment = $this->_getConfigModel()->getEnvironment() == 'sandbox' ? true : false;

        $checkout = new CheckoutApi();
        $checkout->configuration()->setPublicKey($publicKey);
        $checkout->configuration()->setSandbox($environment);

        $header = new ApplePayHeader($transactionId, $publicKeyHash, $ephemeralPublicKey);
        $applepay = new ApplePay($version, $signature, $data, $header);

        try {
            $token = $checkout->tokens()->request($applepay);
            $this->getResponse()->setBody($token->getId());
        } catch (CheckoutModelException $ex) {
            Mage::log($ex->getBody(), null, 'checkoutcom_applepay.log');
            $this->getResponse()
                ->clearHeaders()
                ->setHeader('HTTP/1.0', 500, true)
                ->setBody(YOUR_RETURN_CONTENT);
        } catch (CheckoutHttpException $ex) {
            Mage::log($ex->getBody(), null, 'checkoutcom_applepay.log');
            $this->getResponse()
                ->clearHeaders()
                ->setHeader('HTTP/1.0', 500, true)
                ->setBody(YOUR_RETURN_CONTENT);
        }
        return $this;
    }

    /**
     * Create a card token from a google wallet payload
     * @url ckopayment/api/generateGoogleToken
     */
    public function generateGoogleTokenAction()
    {
        $googleToken = $this->getRequest()->getParam("token");
        $publicKey = $this->_getConfigModel()->getPublicKey();
        $protocolVersion = $googleToken["protocolVersion"];
        $signature = $googleToken["signature"];
        $signedMessage = $googleToken["signedMessage"];
        $environment = $this->_getConfigModel()->getEnvironment() == 'sandbox' ? true : false;

        $checkout = new CheckoutApi();
        $checkout->configuration()->setPublicKey($publicKey);
        $checkout->configuration()->setSandbox($environment);

        $googlepay = new GooglePay($protocolVersion, $signature, $signedMessage);

        try {
            $token = $checkout->tokens()->request($googlepay);
            $this->getResponse()->setBody($token->getId());
        } catch (CheckoutModelException $ex) {
            Mage::log($ex->getBody(), null, 'checkoutcom_googlepay.log');
            $this->getResponse()
                ->clearHeaders()
                ->setHeader('HTTP/1.0', 500, true)
                ->setBody(YOUR_RETURN_CONTENT);
        } catch (CheckoutHttpException $ex) {
            Mage::log($ex->getBody(), null, 'checkoutcom_googlepay.log');
            $this->getResponse()
                ->clearHeaders()
                ->setHeader('HTTP/1.0', 500, true)
                ->setBody(YOUR_RETURN_CONTENT);
        }
        return $this;
    }
}
