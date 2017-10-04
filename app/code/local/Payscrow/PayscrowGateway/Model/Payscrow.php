<?php


/**
 * Created by Byteworks Limited.
 * Author: Chibuzor Ogbu
 * Date: 02/07/2017
 * Time: 4:45 PM
 */
class Payscrow_PayscrowGateway_Model_Payscrow extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'payscrowgateway';
    protected $_formBlockType = 'payscrowgateway/form_payscrowGateway';
    protected $_infoBlockType = 'payscrowgateway/info_payscrowGateway';
    protected $_isInitializeNeeded      = true;
    protected $_canUseInternal          = true;
    protected $_canUseForMultishipping  = false;

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('payscrowgateway/payment/redirect', array('_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? true : false ));
    }

}