<?php


/**
 * Created by Byteworks Limited.
 * Author: Chibuzor Ogbu
 * Date: 02/07/2017
 * Time: 4:40 PM
 */
class Payscrow_PayscrowGateway_Block_Form_PayscrowGateway extends Mage_Payment_Block_Form
{
    protected function _construct()
    {
        $this->setMethodLabelAfterHtml($this->getLabel());
        parent::_construct();

        return;

    }

    private function getLabel()
    {
        $charges = $this->getGatewayCharges();
        $label =  <<<EOD
        <div id="payscrow_logo">
        <style>
.payscrow-body{
padding:10px 5px 10px;  position:relative; height:100px; top: 0;margin-bottom:15px; width:400px;background-color:#fff; border:1px solid #f1efef
}
.payscrow-brand{
display:block;float:left;  background-color: transparent;
}
.payscrow-brand img{
width: 180px;
margin-right:20px;
}

.payscrow-content p{
color: inherit;line-height: 16px;font-weight: 400;padding-top:5px ;font-size: 12px;
}
.payscrow-content p:nth-child(2){
color: #9a9a9a;font-size: 10px;line-height:inherit;
}
@media screen and (max-width: 600px){
.payscrow-body{
width:100%;
height: 110px
}
.payscrow-content{
float: left;
}
.payscrow-brand img{
width: 170px;
}

}
</style>
<div  class="payscrow-body">
<div class="payscrow-brand">
<img alt="Payscrow - Escrow Payment Service"  src="http://payscrow.net/assets/logos/logo-black.png">

</div>
<div class="payscrow-content">
<p>Secure your funds till items are delivered</p>
<p>{$charges}</p>
</div>
</div>
</div>
<script>
document.getElementById("payscrow_logo").previousSibling.remove();
</script>
EOD;

        return $label;
    }

    private function getGatewayCharges()
    {
        if (function_exists('curl_init'))
        {
            $curl = curl_init();
            curl_setopt_array(
                $curl, array(
                         CURLOPT_URL => "https://payscrow.net/api/charges",
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
                $error = "cURL error ({$errno}):\n {$error_message}";
            }

            curl_close($curl);
        }
        else
        {
            $result = file_get_contents('https://payscrow.net/api/charges');
        }

        if ($result)
        {
            $result = json_decode($result, true);
            $currency = isset($result) && $result[ 'currency' ] == 'NGN'
                ? html_entity_decode("&#8358;")
                : $result[ 'currency' ];
            $fee = "An Escrow Fee of {$currency}{$result['customerCharge']} will be applied";
        }
        else
        {
            $fee = "";
        }

        if (isset($error))
        {
            return $error;
        }

        return $fee;
    }

}