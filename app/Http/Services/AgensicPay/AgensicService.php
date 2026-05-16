<?php


namespace App\Http\Services\AgensicPay;


class AgensicService
{
    function withdraw($from){
        $json=[
            'apikey'=>'87S86K61M9W11G27R25G99W30O96X23F87D79N85G',
            'country'=>$from['country'],
            'action'=>'Send fund',
            'carrier'=>$from['carrier'],
            'number'=>$from['phone'],
            'amount'=>$from['amount']
        ];
        return $this->cURL('https://digitwave-services.com/api/add',$json);
    }
    function payment($from){
        $json=[
            'apikey'=>'87S86K61M9W11G27R25G99W30O96X23F87D79N85G',
            'country'=>$from['country'],
            'action'=>'Fundraising',
            'carrier'=>$from['carrier'],
            'number'=>$from['phone'],
            'amount'=>$from['amount']
        ];
        logger(json_encode($json));
        return $this->cURL('https://digitwave-services.com/api/add',$json);
    }
    function getPayID($from){
        $json=[
            'apikey'=>'87S86K61M9W11G27R25G99W30O96X23F87D79N85G',
            'transactionId'=>$from['transactionId'],
        ];
        return $this->cURL('https://digitwave-services.com/api/getwithid',$json);
    }
    protected function cURL($url, $json)
    {

        // Create curl resource
        $ch = curl_init($url);

        // Request headers
        $headers = array(
            'Content-Type:application/json',
        );

        // Return the transfer as a string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // $output contains the output string
        $output = curl_exec($ch);


        // Close curl resource to free up system resources
        curl_close($ch);
        return json_decode($output,true);
    }
}
