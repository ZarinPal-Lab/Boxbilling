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
	private $formUrl;

	public function init()
	{
		if(!extension_loaded('soap')) {
			throw new Payment_Exception('Soap extension required for Zarinpal payment gateway module');
		}
		
		if (!$this->getParam('merchantID')) {
			throw new Payment_Exception('Zarinpal Payment gateway is not configured properly. Please update configuration parameters at "Configuration -> Payments".');
		}
	}

	public static function getConfig()
	{
		return array(
			'supports_one_time_payments'=> true,
			'supports_subscriptions'    => false,
			'description'				=> 'Clients will be redirected to Zarinpal.com to make payment.<br />' ,
			'form'						=> array(
													'merchantID' => array('text', array(
																							'label' => 'Zarinpal MerchantID',
																							'description' => 'To setup your "Merchant Code" login to Zarinpal account and copy your "Merchant Code" from there.',
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
			throw new Payment_Exception('TestMode Not implemented on Zarinpal');
		}
		return $this->formUrl;
	}

	/**
	 * Init call to webservice or return form params
	 * @param Payment_Invoice $invoice
	 */
	public function singlePayment(Payment_Invoice $invoice)
	{
		$buyerInfo  = $invoice->getBuyer();
		$merchantID = $this->getParam('merchantID');
		$amount 	= (int)$invoice->getTotalWithTax();
		$callBackUrl= $this->getParam('redirect_url');
		
		$client = $this->_getSoapClient();
		
		$result = $client->PaymentRequest(
											array(
													'MerchantID' 	=> $merchantID,
													'Amount' 		=> $amount,
													'Description' 	=> 'فاکتور شماره: '. $invoice->getId() .' توضيحات فاکتور: '. $invoice->getTitle(),
													'Email' 		=> $buyerInfo->getEmail(),
													'Mobile' 		=> $buyerInfo->getPhone(),
													'CallbackURL' 	=> $callBackUrl
												)
										 );

		if($result->Status == 100){
			$this->formUrl = 'https://www.zarinpal.com/pg/StartPay/'. $result->Authority .'/';
		} else {
			throw new Payment_Exception('Zarinpal Payment error: '. $result->Status);
		}

		return array();
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
		$ipn = $data['get'];

		if($ipn['Status'] == 'OK'){
			$merchantID = $this->getParam('merchantID');
			$amount = (int) $invoice->getTotalWithTax();
			$client = $this->_getSoapClient();

			$result = $client->PaymentVerification(
													array(
															'MerchantID'	 => $merchantID,
															'Authority' 	 => $ipn['Authority'],
															'Amount'		 => $amount
														)
												   );

			if ($result->Status == 100){
				$response = new Payment_Transaction();
				$response->setType(Payment_Transaction::TXTYPE_PAYMENT);
				$response->setId($result->RefID);
				$response->setAmount($invoice->getTotalWithTax());
				$response->setCurrency($invoice->getCurrency());
				$response->setStatus(Payment_Transaction::STATUS_COMPLETE);
				return $response;
			} else {
				throw new Payment_Exception('Payment verification failed: '. $result->Status);
			}
		} else {
			throw new Payment_Exception('Payment not ok. Status: '. $ipn['Status']);
		}
	}
	
	/**
	 * Check if Ipn is valid
	 */
	public function isIpnValid($data, Payment_Invoice $invoice)
	{
		$ipn = $data['post'];
		return true;
	}
	
    private function _getSoapClient()
    {
		$wsdl = 'https://de.zarinpal.com/pg/services/WebGate/wsdl';
		
		$options = array(
			'encoding' => 'UTF-8'
		);
		
		return new SoapClient($wsdl, $options);
    }
}
