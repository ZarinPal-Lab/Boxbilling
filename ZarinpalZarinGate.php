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
class Payment_Adapter_ZarinpalZarinGate
{
	public function __construct($config)
	{
		$this->config = $config;
		
		if(!extension_loaded('soap')) {
			throw new Exception('Soap extension required for Zarinpal payment gateway module');
		}
		
		if (!$this->config['merchantID']) {
			throw new Exception('Zarinpal Payment gateway is not configured properly. Please update configuration parameters at "Configuration -> Payments".');
		}
	}

	public static function getConfig()
	{
		return array(
			'supports_one_time_payments'=> true,
			'supports_subscriptions'    => false,
			'description'				=> 'Clients will be redirected to ZarinPal.com to make payment.<br />' ,
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
     * 
     * @param type $api_admin
     * @param type $invoice_id
     * @param type $subscription
     * @return string
     */
	public function getHtml($api_admin, $invoice_id, $subscription)
	{
		$invoice = $api_admin->invoice_get(array('id' => $invoice_id));

		$buyer = $invoice['buyer'];
		
		$client = new SoapClient('https://de.zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8'));
		$result = $client->PaymentRequest(
											array(
													'MerchantID' 	=> $this->config['merchantID'],
													'Amount' 		=> $invoice['total'],
													'Description' 	=> 'فاکتور شماره: '. $invoice['serie_nr'],
													'Email' 		=> $invoice['buyer']['email'],
													'Mobile' 		=> '',
													'CallbackURL' 	=> $this->config['redirect_url']
												)
										 );

		$url = 'https://www.zarinpal.com/pg/StartPay/'. $result->Authority .'/ZarinGate';
		return $this->_generateForm($url, array(), 'get');
	}

	public function processTransaction($api_admin, $id, $data, $gateway_id)
	{	
		if($data['get']['Status'] == 'OK' && strlen($data['get']['Authority']) == 36){
			$tx = $api_admin->invoice_transaction_get(array('id' => $id));
			$invoice = $api_admin->invoice_get(array('id' => $tx['invoice_id']));
			
			if(!empty($invoice['total'])){
				
				$client = new SoapClient('https://de.zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8'));
				$result = $client->PaymentVerification(
														array(
																'MerchantID'	 => $this->config['merchantID'],
																'Authority' 	 => $data['get']['Authority'],
																'Amount'		 => $invoice['total']
															)
													   );
				if($result->Status == 100){
					
					$tx_data = array(
										'id' => $id,
										'invoice_id' => $data['get']['bb_invoice_id'],
										'currency'   => $invoice['currency'],
										'txn_status' => 'complete',
										'txn_id' => $result->RefID,
										'amount' => $invoice['total'],
										'type' => 'payment',
										'status' => 'complete',
										'updated_at' => date('c'),
									);
					$api_admin->invoice_transaction_update($tx_data);
					
					$bd = array(
						'id'            => $invoice['client']['id'],
						'amount'        => $invoice['total'],
						'description'   => 'ZarinPal transaction '. $result->RefID,
						'type'          => 'ZarinPal',
						'rel_id'        => $result->RefID,
					);
					$api_admin->client_balance_add_funds($bd);
					$api_admin->invoice_batch_pay_with_credits(array('client_id' => $invoice['client']['id']));
				} else {
					throw new Exception('Invalid Payment');
				}
			}
		} else {
			throw new Exception('Invalid Payment');
		}
		
		$d = array(
			'id'        => $id, 
			'error'     => '',
			'error_code'=> '',
			'status'    => 'processed',
			'updated_at'=> date('c'),
		);
		$api_admin->invoice_transaction_update($d);
	}
	
	private function _generateForm($url, $data, $method = 'post')
	{
		$form  = '';
		$form .= '<form name="payment_form" action="'. $url .'" method="'. $method .'">' . PHP_EOL;
		if(!empty($data)){
			foreach($data as $key => $value) {
				$form .= sprintf('<input type="hidden" name="%s" value="%s" />', $key, $value) . PHP_EOL;
			}
		}
		$form .=  '<input class="bb-button bb-button-submit" type="submit" value="Pay with ZarinPal" id="payment_button"/>'. PHP_EOL;
		$form .=  '</form>' . PHP_EOL . PHP_EOL;

		if(isset($this->config['auto_redirect']) && $this->config['auto_redirect']) {
			$form .= sprintf('<h2>%s</h2>', __('Redirecting to ZarinPal.com'));
			$form .= "<script type='text/javascript'>$(document).ready(function(){    document.getElementById('payment_button').style.display = 'none';    document.forms['payment_form'].submit();});</script>";
		}

		return $form;
	}

}
