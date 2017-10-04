<?php


/**
 * Created by Byteworks Limited.
 * Author: Chibuzor Ogbu
 * Date: 02/07/2017
 * Time: 4:45 PM
 */
class Payscrow_PayscrowGateway_PaymentController extends Mage_Core_Controller_Front_Action
{

    /**
     * The redirect action is triggered by submitting/placing order
     */
    public function redirectAction()
    {
        $this->loadLayout();
        $block = $this->getLayout()->createBlock(
            'Mage_Core_Block_Template', 'payscrowgateway',
            array( 'template' => 'payscrowgateway/form/payscrowgateway.phtml' )
        );
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    /**
     * The response being returned by gateway is processed here
     */
    public function responseAction()
    {
        if ($this->getRequest()->isPost())
        {
            $response = file_get_contents('php://input');
            $params = json_decode($response, true);

            $orderId = isset($params[ 'ref' ])
                ? $params[ 'ref' ]
                : null; // Generally sent by gateway

            //                        lets validate the response is from payscrow
            if (isset($params[ 'transactionId' ]))
            {
                $gatewayUrl = "https://www.payscrow.net/api/paymentconfirmation?transactionId={$params['transactionId']}";
                $result = $this->verifyRequest($gatewayUrl);
            }
            else
            {
                $result = false;
            }

            if (isset($params[ 'statusCode' ]))
            {
                $statusDescription = "Payscrow confirmed this order as: {$params[ 'statusDescription' ]}";

                switch ($params[ 'statusCode' ])
                {
                    case "00":

                        if ($result && $result[ 'statusCode' ] == $params[ 'statusCode' ])
                        {
                            // Payment was successful, so update the order's state, send order email and move to the success page
                            $order = Mage::getModel('sales/order');

                            $order->loadByIncrementId($orderId);
                            $grandTotal = number_format($order->getBaseGrandTotal(), 2, '.', '');

                            if ((float) $grandTotal == ( isset($result[ 'amountPaid' ])
                                    ? $result[ 'amountPaid' ]
                                    : null )
                            )
                            {
                                $order->setState(
                                    Mage_Sales_Model_Order::STATE_PROCESSING, true, $statusDescription
                                );

                                $order->sendNewOrderEmail();
                                $order->setEmailSent(true);

                                $order->save();

                                Mage::getSingleton('checkout/session')->unsQuoteId();
                            }
                        }
                        break;
                    case "01":

                        if ($result && $result[ 'statusCode' ] == $params[ 'statusCode' ])
                        {
                            // payment refunded
                            $order = Mage::getModel('sales/order');
                            $order->loadByIncrementId($orderId);
                            $order->setState(
                                Mage_Sales_Model_Order::STATE_CLOSED, true, $statusDescription
                            );
                            $order->sendRefundOrderEmail();
                            $order->setEmailSent(true);
                            $order->save();
                        }
                        break;

                    case "03":
                        /**
                         * The cancel action is triggered when an order is cancelled from gateway
                         */
                        // There is a problem in the response we got
                        if ($result && $result[ 'statusCode' ] == $params[ 'statusCode' ])
                        {
                            if (Mage::getSingleton('checkout/session')->getLastRealOrderId())
                            {
                                $order = Mage::getModel('sales/order')->loadByIncrementId(
                                    Mage::getSingleton('checkout/session')->getLastRealOrderId()
                                );
                                if ($order->getId())
                                {
                                    // Flag the order as 'cancelled' and save it
                                    $order->cancel()->setState(
                                        Mage_Sales_Model_Order::STATE_CANCELED, true, $statusDescription
                                    )->save();
                                }
                            }
                        }
                        break;
                }
            }
        }

        if ($this->getRequest()->isGet())
        {
            $params = $this->getRequest()->getParams();
            switch ($params[ 'statusCode' ])
            {
                case "00":

                    Mage_Core_Controller_Varien_Action::_redirect(
                        'checkout/onepage/success', array(
                                                      '_secure' => isset($_SERVER[ 'HTTPS' ]) && $_SERVER[ 'HTTPS' ] != 'off'
                                                          ? true
                                                          : false
                                                  )
                    );

                    break;
                case "01":
                    Mage_Core_Controller_Varien_Action::_redirect('');
                    break;

                case "03":
                    /**
                     * The cancel action is triggered when an order is cancelled from gateway
                     */
                    // There is a problem in the response we got
                    Mage_Core_Controller_Varien_Action::_redirect(
                        'checkout/onepage/failure', array(
                                                      '_secure' => isset($_SERVER[ 'HTTPS' ]) && $_SERVER[ 'HTTPS' ] != 'off'
                                                          ? true
                                                          : false
                                                  )
                    );
                    break;
                default:
                    // Back to merchant - reorder
                    Mage_Core_Controller_Varien_Action::_redirect(
                        'vtweb/payment/reorder', array(
                                                   '_secure' => isset($_SERVER[ 'HTTPS' ]) && $_SERVER[ 'HTTPS' ] != 'off'
                                                       ? true
                                                       : false
                                               )
                    );
            }
        }

        else{
            Mage_Core_Controller_Varien_Action::_redirect('');
        }



    }

    private function verifyRequest( $gatewayUrl )
    {
        if (function_exists('curl_init'))
        {
            $curl = curl_init();
            curl_setopt_array(
                $curl, array(
                         CURLOPT_URL => $gatewayUrl,
                         CURLOPT_RETURNTRANSFER => true,
                         CURLOPT_FOLLOWLOCATION => 1,
                         CURLOPT_HTTPHEADER => array(
                             'Content-Type: application/json',
                             'Accept: application/json'
                         ),
                         CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.9) Gecko/20071025 Firefox/2.0.0.9'
                     )
            );

            $result = curl_exec($curl);

            if ($errno = curl_errno($curl))
            {
                $error_message = curl_strerror($errno);
                return "cURL error ({$errno}):\n {$error_message}";
            }

            curl_close($curl);
        }
        else
        {
            $result = file_get_contents(
                $gatewayUrl
            );
        }

        if ($result)
        {
            $result = json_decode($result, true);
        }

        return $result;
    }


}
