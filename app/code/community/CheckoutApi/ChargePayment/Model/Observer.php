<?php

/**
 * Class CheckoutApi_ChargePayment_Model_Observer
 *
 * @version 20151203
 */
class CheckoutApi_ChargePayment_Model_Observer {

    /**
     * Cancel Order after Void
     *
     * @param $observer
     * @return CheckoutApi_ChargePayment_Model_Observer
     * @throws Exception
     *
     * @version 20151203
     */
    public function setOrderStatusForVoid(Varien_Event_Observer $observer) {
        $orderId            = Mage::app()->getRequest()->getParam('order_id');
        $order              = Mage::getModel('sales/order')->load($orderId);

        if (!is_object($order)) {
            return $this;
        }
        $payment            = $order->getPayment();
        $paymentCode        = (string)$payment->getMethodInstance()->getCode();
        $isCancelledOrder   = false;

        if ($paymentCode === CheckoutApi_ChargePayment_Helper_Data::CODE_CREDIT_CARD) {
            $isCancelledOrder   = Mage::getModel('chargepayment/creditCard')->getVoidStatus();
        } else if ($paymentCode === CheckoutApi_ChargePayment_Helper_Data::CODE_CREDIT_CARD_JS) {
            $isCancelledOrder   = Mage::getModel('chargepayment/creditCardJs')->getVoidStatus();
        }

        if (!$isCancelledOrder) {
            return;
        }

        $message    = 'Transaction has been void';

        $order->registerCancellation($message);
        $order->setStatus(Mage_Sales_Model_Order::STATE_CANCELED);
        $order->save();

        return $this;
    }

    /**
     * Save order into registry to use it in the overloaded controller.
     *
     * @param Varien_Event_Observer $observer
     * @return CheckoutApi_ChargePayment_Model_Observer
     *
     * @version 20160215
     *
     */
    public function saveOrderAfterSubmit(Varien_Event_Observer $observer)
    {
        /* @var $order Mage_Sales_Model_Order */
        $order = $observer->getEvent()->getData('order');
        Mage::register('charge_payment_order', $order, true);

        return $this;
    }

    /**
     * Set data for response of frontend saveOrder action
     *
     * @param Varien_Event_Observer $observer
     * @return CheckoutApi_ChargePayment_Model_Observer
     *
     * @version 20160215
     */
    public function addAdditionalFieldsToResponseFrontend(Varien_Event_Observer $observer)
    {
        /* @var $order Mage_Sales_Model_Order */
        $order = Mage::registry('charge_payment_order');

        if ($order && $order->getId()) {
            $payment = $order->getPayment();
            if ($payment &&
                ($payment->getMethod() == CheckoutApi_ChargePayment_Helper_Data::CODE_CREDIT_CARD || $payment->getMethod() == CheckoutApi_ChargePayment_Helper_Data::CODE_CREDIT_CARD_JS)) {
                /* @var $controller Mage_Core_Controller_Varien_Action */
                $controller = $observer->getEvent()->getData('controller_action');
                $result = Mage::helper('core')->jsonDecode(
                    $controller->getResponse()->getBody('default'),
                    Zend_Json::TYPE_ARRAY
                );

                if (empty($result['error'])) {
                    $redirectUrl        = Mage::getUrl('checkout/onepage/success', array('_secure'=>true));
                    $session            = Mage::getSingleton('chargepayment/session_quote');
                    $is3d               = $session->getIs3d();
                    $paymentRedirectUrl = $session->getPaymentRedirectUrl();

                    $result['success']      = true;
                    $result['is3d']         = !$is3d ? false : true;
                    $result['redirect_url'] = !empty($paymentRedirectUrl) ? $paymentRedirectUrl : $redirectUrl;

                    /* Restore session for 3d payment */
                    if ($is3d) {
                        $order->setStatus(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
                        $order->save();

                        $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
                        $session->setLastOrderIncrementId($order->getIncrementId());
                        $session->addCheckoutOrderIncrementId($order->getIncrementId());

                        if ($quote->getId()) {
                            $quote->setIsActive(1)
                                ->setReservedOrderId(NULL)
                                ->save();
                            Mage::getSingleton('checkout/session')->replaceQuote($quote);
                        }
                    }

                    $session->unsetData('is3d');
                    $session->unsetData('payment_redirect_url');

                    $controller->getResponse()->clearHeader('Location');
                    $controller->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
                }
            }
        }

        return $this;
    }

    /**
     * Update all edit increments for all orders if module is enabled.
     * Needed for correct work of edit orders in Admin area.
     *
     * @param Varien_Event_Observer $observer
     * @return Mage_Authorizenet_Model_Directpost_Observer
     */
    public function updateAllEditIncrements(Varien_Event_Observer $observer)
    {
//        /* @var $order Mage_Sales_Model_Order */
//        $order = $observer->getEvent()->getData('order');
//        Mage::helper('authorizenet')->updateOrderEditIncrements($order);
//
//        Mage::app()->getResponse()->setRedirect();
//        Mage::app()->getResponse()->sendResponse();
//
//        exit(0);

        return $this;
    }
}