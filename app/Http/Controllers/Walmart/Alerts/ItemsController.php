<?php

namespace App\Http\Controllers\Walmart\Alerts;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Integration\walmart;
use App\Mail\SendMail;
use App\Models\Walmart\Items;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;

class ItemsController extends Controller
{
    public function index()
    {
        return view('walmart.alerts.walmart_items');
    }

    public function walmartItems(Request $request)
    {
        ini_set('max_execution_time', '700');
        $client_id = $request->get('clientID');
        $secret = $request->get('clientSecretID');

        $this->validate($request, [

            'clientName' => 'required',
            'clientID' => 'required',
            'clientSecretID' => 'required',

        ]);
        //End of validation

        $token = Walmart::getToken($client_id, $secret);

        $total_records = Walmart::getItemTotal($client_id, $secret, $token);

        if($total_records > 0){

            $per_page = Config::get('constants.walmart.per_page');  // 100 Records on per page
            $no_of_pages = $total_records / $per_page; // Total record divided into per page

            for ($i = 0; $i < $no_of_pages; $i++) {

                $offset = $i * $per_page;
                $url = "https://marketplace.walmartapis.com/v3/items?offset=" . $offset . "&limit=" . $per_page;
                $requestID = uniqid();
                $authorization = base64_encode($client_id . ":" . $secret);

                $curl = curl_init();

                $options = array(
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_HTTPHEADER => array(
                        'WM_SVC.NAME: Walmart Marketplace',
                        'Authorization: Basic ' . $authorization,
                        'WM_QOS.CORRELATION_ID: ' . $requestID,
                        'WM_SEC.ACCESS_TOKEN: ' . $token,
                        'Accept: application/json',
                        'Content-Type: application/json',
                        'Cookie: TS01f4281b=0130aff232afca32ba07d065849e80b32e6ebaf11747c58191b2b4c9d5dd53a042f7d890988bf797d7007bddb746c3b59d5ee859d0'
                    ),

                    CURLOPT_HTTPGET => true,
                );

                curl_setopt_array($curl, $options);
                $response = curl_exec($curl);
                $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

                curl_close($curl);

                $response = json_decode($response, true);
                $res = $response['ItemResponse'];

                if (count($res) > 0) {
                    foreach ($response['ItemResponse'] as $items) {
                        if ($items['publishedStatus'] === "SYSTEM_PROBLEM") {

                            $unpublishedReasons = '';
                            $test = [];
                            if (array_key_exists('unpublishedReasons', $items)) {
                                $unpublished = $items['unpublishedReasons']['reason'];
                                $unpublishedReasons = implode(' ', $unpublished);

                                $alert_type = '';

                                if(str_contains($unpublishedReasons, 'intellectual')){
                                    $alert_type = Config::get('constants.walmart.ip_claim');
                                }
                                if(str_contains($unpublishedReasons, 'compliance')){
                                    $alert_type = Config::get('constants.walmart.regulatory_compliance');
                                }
                                if(str_contains($unpublishedReasons, 'partnered')){
                                    $alert_type = Config::get('constants.walmart.brand_partnership_violation');
                                }
                                if(str_contains($unpublishedReasons, 'Safety')){
                                    $alert_type = Config::get('constants.walmart.offensive_product');
                                }

                                if($alert_type != ''){

                                    $walmartAlerts = [
                                        'sku' => $items['sku'] ? $items['sku'] : '',
                                        'product_name' => isset($items['productName']) ? $items['productName'] : '',
                                        'reason' => $unpublishedReasons,
                                        'alert_type' => $alert_type,
                                        'status' => $items['publishedStatus'] ? $items['publishedStatus'] : '',
                                        'product_url' => $items['wpid'] ? $items['wpid'] : '',
                                    ];

                                    $insert_alerts = Items::insert_item_alert($walmartAlerts);
                                }

                            }

                        }

                    }
                    //End loop

                }

            }
            // End of for loop

            $walmartData = Items::all()->groupBy('alert_type');
            // Get data from DB to send email

            // return $walmartData;
            $email = auth()->user()->email;
            // match condition to unique user

            if (!empty($email)) {
                if (isset($walmartData['ip_claim']) && count($walmartData['ip_claim']) > 0) {
                    $detail = [];
                    foreach ($walmartData['ip_claim'] as $ipClaim) {
                        $detail[] = [
                            'productID' => $ipClaim['sku'],
                            'productName' => $ipClaim['product_name'],
                            'publishedStatus' => $ipClaim['status'],
                            'reason' => $ipClaim['reason'],
                            'AlertType' => $ipClaim['alert_type'] ? 'IP Claim Alert' : '',
                            'productLink' => "https://www.walmart.com/ip/" . $ipClaim['sku'],
                            'userEmail' => $email
                        ];
                    }
                    Mail::to($email)->send(new SendMail($detail));
                }
                // IP Claim condition

                if (isset($walmartData['offensive_product']) && count($walmartData['offensive_product']) > 0) {
                    $detail = [];
                    foreach ($walmartData['offensive_product'] as $offensiveProduct) {
                        $detail[] = [
                            'productID' => $offensiveProduct['sku'],
                            'productName' => $offensiveProduct['product_name'],
                            'publishedStatus' => $offensiveProduct['status'],
                            'reason' => $offensiveProduct['reason'],
                            'AlertType' => $offensiveProduct['alert_type'] ? 'Offensive Product Alert' : '',
                            'productLink' => "https://www.walmart.com/ip/" . $offensiveProduct['sku'],
                            'userEmail' => $email
                        ];
                    }

                    Mail::to($email)->send(new SendMail($detail));
                }
                // Offensive Product

                if (isset($walmartData['regulatory_compliance']) && count($walmartData['regulatory_compliance']) > 0) {
                    $detail = [];
                    foreach ($walmartData['regulatory_compliance'] as $regulatoryCompliance) {
                        $detail[] = [
                            'productID' => $regulatoryCompliance['sku'],
                            'productName' => $regulatoryCompliance['product_name'],
                            'publishedStatus' => $regulatoryCompliance['status'],
                            'reason' => $regulatoryCompliance['reason'],
                            'AlertType' => $regulatoryCompliance['alert_type'] ? 'Regulatory Compliance Alert' : '',
                            'productLink' => "https://www.walmart.com/ip/" . $regulatoryCompliance['sku'],
                            'userEmail' => $email
                        ];
                    }

                    Mail::to($email)->send(new SendMail($detail));
                }
                // regulatory compliance Product

                if (isset($walmartData['brand_partnership_violation']) && count($walmartData['brand_partnership_violation']) > 0) {
                    $detail = [];
                    foreach ($walmartData['brand_partnership_violation'] as $brandPartnershipViolation) {
                        $detail[] = [
                            'productID' => $brandPartnershipViolation['sku'],
                            'productName' => $brandPartnershipViolation['product_name'],
                            'publishedStatus' => $brandPartnershipViolation['status'],
                            'reason' => $brandPartnershipViolation['reason'],
                            'AlertType' => $brandPartnershipViolation['alert_type'] ? 'Walmart Brand Partnership Violation' : '',
                            'productLink' => "https://www.walmart.com/ip/" . $brandPartnershipViolation['sku'],
                            'userEmail' => $email
                        ];
                    }

                    Mail::to($email)->send(new SendMail($detail));
                }
                // brand Partnership Violation Product

            }
            // Email is here

        }
        // End of total items condition
        return redirect()->back()->withSuccess('Email Has Been Sent Successfully');

    }
    //End function
}
