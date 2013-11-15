<?php
/**
 * BoxBilling
 *
 * LICENSE
 *
 * This source file is subject to the license that is bundled
 * with this package in the file LICENSE.txt
 * It is also available through the world-wide-web at this URL:
 * http://www.boxbilling.com/LICENSE.txt
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@boxbilling.com so we can send you a copy immediately.
 *
 * @copyright Copyright (c) 2010-2012 BoxBilling (http://www.boxbilling.com)
 * @license   http://www.boxbilling.com/LICENSE.txt
 * @version   $Id$
 */
class Payment_Adapter_ZarinpalWebGate extends Payment_AdapterAbstract
{
    public $go_url;
	
    public function init()
    {

        if (!$this->getParam('securityCode')) {
        	throw new Payment_Exception('Payment gateway "Zarinpal" is not configured properly. Please update configuration parameter "API Key Code" at "Configuration -> Payments".');
        }
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments'   =>  true,
            'supports_subscriptions'     =>  false,
            'description'     =>  'Clients will be redirected to Zarinpal.ir to make payment.<br />' ,
            'form'  => array(
                 'securityCode' => array('text', array(
                 			'label' => 'API Key Code',
                 			'description' => 'To setup your "API Key Code" login to Zarinpal account. Go to LINK "http://zarinpal.com/". Copy "API Key Code" and paste it to this field.',
                 			'validators' => array('notempty'),
                 	),
                 ),
            ),
        );
    }

    /**
     * Return payment gateway type
     * @return string
     */
    public function getType()
    {
        return Payment_AdapterAbstract::TYPE_FORM;
    }

    /**
     * Return payment gateway type
     * @return string
     */
    public function getServiceUrl()
    {
        if($this->testMode) {
            return 'http://zarinpal.com/';
        }
		return $this->go_url;
    }

    /**
     * Init call to webservice or return form params
     * @param Payment_Invoice $invoice
     */
	public function singlePayment(Payment_Invoice $invoice)
	{
		include('nusoap.php');
        $api = $this->getParam('securityCode');
		$merchantID = $this->getParam('securityCode');
        $amount = (int)$invoice->getTotalWithTax();
		$callBackUrl =  urlencode($this->getParam('redirect_url'));
		
        $client = new nusoap_client('https://de.zarinpal.com/pg/services/WebGate/wsdl', 'wsdl');
		$res = $client->call('PaymentRequest', array(
		array(
					'MerchantID' 	=> $merchantID ,
					'Amount' 		=> $amount ,
					'Description' 	=> $product_id ,
					'Email' 		=> '' ,
					'Mobile' 		=> '' ,
					'CallbackURL' 	=> $callBackUrl
					)
		
		));
		
		if($res->Ststus == 100){
            $this->go_url = "https://www.zarinpal.com/pg/StartPay/" . $result->Authority . "/";
        }else{
            throw new Exception('Zarinpal error : '.$res->Ststus);
        }

        return $data;
	}

    /**
     * Perform recurent payment
     */
	public function recurrentPayment(Payment_Invoice $invoice)
	{
		throw new Payment_Exception('Not implemented yet');
	}

    /**
     * Handle IPN and return response object
     * @return Payment_Transaction
     */
	public function getTransaction($data, Payment_Invoice $invoice)
	{
        $ipn = $data['post'];
		include_once('nusoap.php');

        $api = $this->getParam('securityCode');
        $trans_id = $ipn['trans_id'];
		$amount = (int) $invoice->getTotalWithTax();
		$client = new nusoap_client('https://de.zarinpal.com/pg/services/WebGate/wsdl', 'wsdl');
        $url = $client;
		$merchant = $api;

		$res = $client->call("PaymentVerification", array(
		array(
				'MerchantID'	 => $merchant ,
				'Authority' 	 => $ipn['au'] ,
				'Amount'		 => $amount
				)
		
		));

        if ($res->status != 100){
            throw new Payment_Exception('Sale verification failed: '. $res->status);
        }

        $response = new Payment_Transaction();
        $response->setType(Payment_Transaction::TXTYPE_PAYMENT);
        $response->setId($trans_id);
        $response->setAmount($invoice->getTotalWithTax());
        $response->setCurrency($invoice->getCurrency());
        $response->setStatus(Payment_Transaction::STATUS_COMPLETE);
        return $response;
	}
    /**
     * Check if Ipn is valid
     */
    public function isIpnValid($data, Payment_Invoice $invoice)
    {
        $ipn = $data['post'];
        return true;
    }
}
