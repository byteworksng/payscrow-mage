<?php


/**
 * Created by Byteworks Limited.
 * Author: Chibuzor Ogbu
 * Date: 02/07/2017
 * Time: 4:47 PM
 */
class Payscrow_PayscrowGateway_Block_Info_PayscrowGateway extends Mage_Payment_Block_Info
{
    protected function _prepareSpecificInformation($transport = null)
    {
        if (null !== $this->_paymentSpecificInformation)
        {
            return $this->_paymentSpecificInformation;
        }

        $data = array();
        if ($this->getInfo()->getCustomFieldOne())
        {
            $data[Mage::helper('payment')->__('Custom Field One')] = $this->getInfo()->getCustomFieldOne();
        }

        if ($this->getInfo()->getCustomFieldTwo())
        {
            $data[Mage::helper('payment')->__('Custom Field Two')] = $this->getInfo()->getCustomFieldTwo();
        }

        $transport = parent::_prepareSpecificInformation($transport);

        return $transport->setData(array_merge($data, $transport->getData()));
    }
}