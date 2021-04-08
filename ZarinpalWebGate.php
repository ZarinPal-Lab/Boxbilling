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
class Payment_Adapter_ZarinpalWebGate
{
    public function __construct($config)
    {
        $this->config = $config;

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

        $param_request = array(
            'merchant_id' => $this->config['merchantID'],
            'amount' => intval($invoice['total'] * 10000),
            'description' => 'فاکتور شماره: '. $invoice['serie_nr'],
            'callback_url' => $this->config['redirect_url']
        );
        $jsonData = json_encode($param_request);

        $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/request.json');
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ));
        $result = curl_exec($ch);
        $err = curl_error($ch);
        $result = json_decode($result, true, JSON_PRETTY_PRINT);
        curl_close($ch);

        $url = 'https://www.zarinpal.com/pg/StartPay/'. $result['data']['authority'];
        return $this->_generateForm($url, array(), 'get');
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {

        if($data['get']['Status'] == 'OK' && strlen($data['get']['Authority']) == 36){
            $tx = $api_admin->invoice_transaction_get(array('id' => $id));
            $invoice = $api_admin->invoice_get(array('id' => $tx['invoice_id']));

            if(!empty($invoice['total'])){

                $param_verify = array("merchant_id" => $this->config['merchantID'], "authority" => $data['get']['Authority'], "amount" =>  intval($invoice['total'] * 10000));
                $jsonData = json_encode($param_verify);
                $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/verify.json');
                curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($jsonData)
                ));

                $result = curl_exec($ch);
                $err = curl_error($ch);
                curl_close($ch);
                $result = json_decode($result, true);

                if($result['data']['code'] == 100){

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
