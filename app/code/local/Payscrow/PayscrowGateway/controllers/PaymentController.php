<?php


/**
 * Created by Byteworks Limited.
 * Author: Chibuzor Ogbu
 * Date: 02/07/2017
 * Time: 4:45 PM
 */
class Payscrow_PayscrowGateway_PaymentController extends Mage_Core_Controller_Front_Action
{

    protected $gatewayUrl;
    protected $isSandboxMode;
    protected $staticConfig;

    public function __construct(
        \Zend_Controller_Request_Abstract $request,
        \Zend_Controller_Response_Abstract $response,
        array $invokeArgs = []
    ) {
        $this->isSandboxMode = Mage::getStoreConfig('payment/payscrowgateway/sandbox_mode', Mage::app()->getStore());

        $this->staticConfig = simplexml_load_string(
            file_get_contents(Mage::getConfig()->getModuleDir('etc', 'Payscrow_PayscrowGateway').DS.'config.xml'),
            'Varien_Simplexml_Element'
        );

        $this->gatewayUrl = $this->isSandboxMode
            ? (string) $this->staticConfig ->xpath( 'default/payment/payscrowgateway/gateway_demo_url')[0]
            : (string) $this->staticConfig ->xpath( 'default/payment/payscrowgateway/gateway_url')[0];
        parent::__construct($request, $response, $invokeArgs);
    }

    /**
     * The redirect action is triggered by submitting/placing order
     */
    public function redirectAction()
    {

        $responseUrlData = $this->staticConfig->xpath( 'default/payment/payscrowgateway/response_url');
        $url = $responseUrlData[0];
        $parseUrl = parse_url(trim($url));
        $path = explode('/', $parseUrl['path'], 2);
        $domain = trim(isset($parseUrl['host']) ? $parseUrl['host'] : array_shift($path));

        if (!empty($domain)){
            $url = ( isset( $_SERVER[ 'HTTPS' ] ) && $_SERVER['HTTPS'] != 'off'
                    ? "https"
                    : "http" ) . "://" . ( ( isset( $_SERVER[ 'HTTP_HOST' ] ) && isset( $_SERVER[ 'SERVER_NAME' ] ) )
                    ? ( str_ireplace( 'www.', '', $_SERVER[ 'SERVER_NAME' ] ) == str_ireplace(
                            'www.', '', $_SERVER[ 'HTTP_HOST' ]
                        ) )
                        ? $_SERVER[ 'HTTP_HOST' ]
                        : $_SERVER[ 'SERVER_NAME' ]
                    : $_SERVER[ 'SERVER_NAME' ] ).'/' . (isset($parseUrl['host']) ? $parseUrl['path'] : $path[0]);
            $responseUrl = $url;
        }
        else{
            $url = ( isset( $_SERVER[ 'HTTPS' ] ) && $_SERVER['HTTPS'] != 'off'
                    ? "https"
                    : "http" ) . "://" . ( ( isset( $_SERVER[ 'HTTP_HOST' ] ) && isset( $_SERVER[ 'SERVER_NAME' ] ) )
                    ? ( str_ireplace( 'www.', '', $_SERVER[ 'SERVER_NAME' ] ) == str_ireplace(
                            'www.', '', $_SERVER[ 'HTTP_HOST' ]
                        ) )
                        ? $_SERVER[ 'HTTP_HOST' ]
                        : $_SERVER[ 'SERVER_NAME' ]
                    : $_SERVER[ 'SERVER_NAME' ] ) . $url;
            $responseUrl = $url;
        }

        $dta = [];

        $dta['gatewayUrl'] = $this->gatewayUrl.'customer/transactions/start';
        $dta['accessKey'] = $this->isSandboxMode
            ? (string) $this->staticConfig->xpath( 'default/payment/payscrowgateway/access_demo_key')[0]
            : Mage::getStoreConfig('payment/payscrowgateway/access_key', Mage::app()->getStore());

        $dta['deliveryDuration'] = Mage::getStoreConfig('payment/payscrowgateway/max_delivery_duration', Mage::app()->getStore());

        $dta['responseUrl'] = $responseUrl;

        $this->loadLayout();

        $block = $this->getLayout()->createBlock(
            'Mage_Core_Block_Template', 'payscrowgateway',
            array('template' => 'payscrowgateway/redirect.phtml')
        )->assign($dta);

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
                $isTest =
                $gatewayUrl = $this->gatewayUrl."api/paymentconfirmation?transactionId={$params['transactionId']}";
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
