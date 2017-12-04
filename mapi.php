<?php

//------------------------//
//     GET HEADERS        //
//------------------------//
// GET MARKETO HEADERS
$host = $_SERVER['HTTP_MARKETO_HOST'];
$clientId = $_SERVER['HTTP_MARKETO_CLIENT_ID'];
$clientSecret = $_SERVER['HTTP_MARKETO_CLIENT_SECRET'];

// GET MANAGEO HEADERS
$keyManageo = $_SERVER['HTTP_MANAGEO_API_KEY'];

// GET DATA FROM BODY REQUEST
$json = file_get_contents('php://input');
$request = json_decode($json, true);
$action = $request["result"]["action"];
$email = $request["result"]["email"];
$mapping = $request["result"]["mapping"];

$showDebug = $request["result"]["showDebug"];
if(!isset($email) || !isset($action) || !isset($mapping) ){
  new MApiError("00","params missing");
}
if(!isset($keyManageo)){
  new MApiError("01","Manageo Key missing");
}
if(!isset($host) || !isset($clientId) || !isset($clientSecret)){
  new MApiError("02","Marketo Keys missing");
}

//------------------------//
//    GET THE JOB DONE    //
//------------------------//
$fields = array();
$marketo_fields = array();
//$fields = array("email","firstName","lastName","site","mainPhone","company","PostalCode","sicCode");

// MARKETO -  GET/SET THE TOKEN
setMarketoToken();
// GET FIELDS IN MARKETO INSTANCE
getMarketoFields();
if($showDebug) {
  print_r($marketo_fields);
}
// CHECK MAPPING FIELDS VS MARKETO REAL FIELDS
for($c=0;$c<count($mapping);$c++){
    if(in_array($mapping[$c]['mktKey'],$marketo_fields)){
      array_push($fields,$mapping[$c]['mktKey']);
    }
}
if($showDebug) {
  echo "<br>MKT FIELDS = ";
  print_r($fields);
}
// GET DATA FROM MANAGEO
$mngData = searchByEmail($email);
if($showDebug) {
  echo "MANAGEO DATA <br><pre>";
  print_r($mngData);
}
// IF THERE IS A RESULT

// MARKETO - GET THE DATA leads.json...
$mktData = getLeadData($email,$fields);
if($showDebug) {
  echo "MKT DATA <br>";
  print_r($mktData);
}

// CHECK THE DATA AND DO SOME INTEL
$lead1 = new stdClass();
$lead1->email = $email;
// SET THE OTHER FIELDS
for($c=0;$c<count($mapping);$c++){
    // is it a real marketo field ?
    if(in_array($mapping[$c]['mktKey'],$marketo_fields)){
      if($showDebug) {echo "<br>".$mapping[$c]['overwrite']." --- ".$mapping[$c]['mktKey'] .' = ' .$mngData->$mapping[$c]['mngKey'].' ('.$mapping[$c]['mngKey'].')';}
      // is it overwritable ?
      if (true == $mapping[$c]['overwrite']) {
        $lead1->$mapping[$c]['mktKey'] = $mngData->$mapping[$c]['mngKey'];
      } else if (false == $mapping[$c]['overwrite'] && !isset($mktData->$mapping[$c]['mktKey']) ) { // SHOULD APPLY OVERWRITING RULE ?
        if($showDebug) {
          echo " OVERWRITING BECAUSE EMPTY";
          echo $mktData->$mapping[$c]['mktKey'] ." is empty";
        }
        $lead1->$mapping[$c]['mktKey'] = $mngData->$mapping[$c]['mngKey'];
      }
    }
}
//$lead1->firstName = "lead1";
if($showDebug) {
  echo "Lead to go ";
  print_r($lead1);
}

$newData = bodyBuilder("createOrUpdate","email",array($lead1));
// MARKETO - CREATEorUPDATE THE DATA TO MARKETO
$returnMessage = createOrUpdateMKT($email, $newData);

  if($showDebug) {
    print_r($returnMessage);
    echo "</pre>";
  }else{

  http_response_code(200);
  header('Content-Type: application/json');
  $result = new stdClass();
  $result->marketoResult = $returnMessage->result[0];
  $result->updatedLeadData = $lead1;
  echo json_encode($result);
}
// \o/



//------------------------//
//        HELPERS         //
//------------------------//

function searchByEmail($email) {
  GLOBAL $keyManageo;

  $curl = curl_init();
  curl_setopt_array($curl, array(
    CURLOPT_URL => "https://manageo.azure-api.net/company/search/SearchCompaniesByEmail?email=".$email,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => array(
      "cache-control: no-cache",
      "content-type: application/json",
      "ocp-apim-subscription-key: ".$keyManageo
    ),
    ));
  $response = curl_exec($curl);
  $err = curl_error($curl);
  curl_close($curl);

  if ($err) {
    new MApiError("02","Manageo err".$err);
  } else {
    $json = json_decode($response);
    if(!isset($json->success)){
      new MApiError("02B",$json); // ie: bad key
    } else if($json->success==false){
      new MApiError("02B",$json->error); // no success no results
    } else{
      return $json->result;
    }
    //echo "SUCCESS? :". $json->success;
  }
}

function setMarketoToken(){
  GLOBAL $token,$host,$clientId, $clientSecret;
  //$token = "b8ac265e-2a67-4f5a-b4c3-21891dcf21e3:lon";
   $ch = curl_init($host . "/identity/oauth/token?grant_type=client_credentials&client_id=" . $clientId . "&client_secret=" . $clientSecret);
   curl_setopt($ch,  CURLOPT_RETURNTRANSFER, 1);
   curl_setopt($ch, CURLOPT_HTTPHEADER, array('accept: application/json',));
   $response = json_decode(curl_exec($ch));
   curl_close($ch);
   $token = $response->access_token;
   //return $token;
}

function getLeadData($email, $fields) {
  GLOBAL $token, $host;
  $curl = curl_init();

  curl_setopt_array($curl, array(
    CURLOPT_URL => $host."/rest/v1/leads.json?access_token=".$token."&filterType=email&filterValues=".$email."&fields=".implode(",", $fields),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => array(
      "cache-control: no-cache"
    ),
  ));
  $response = curl_exec($curl);
  $err = curl_error($curl);
  curl_close($curl);

  if ($err) {
    return "cURL Error #:" . $err;
  } else {
    $json = json_decode($response);
    //echo "SUCCESS? :". $json->success;
    return $json->result[0];
  }

}

function createOrUpdateMKT($email, $newData){
  GLOBAL $token,$host;

  $curl = curl_init();
  curl_setopt_array($curl, array(
    CURLOPT_URL => $host."/rest/v1/leads.json?access_token=".$token,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => $newData,
    CURLOPT_HTTPHEADER => array(
      "cache-control: no-cache",
      "content-type: application/json"
    ),
  ));

  $response = curl_exec($curl);
  $err = curl_error($curl);
  curl_close($curl);
  if ($err) {
    return "cURL Error #:" . $err;
  } else {
    $json = json_decode($response);
    //echo "SUCCESS? :". $json->success;
    return $json;
  }
}

function getMarketoFields() {
  GLOBAL $token, $host, $marketo_fields;
  $curl = curl_init();

  curl_setopt_array($curl, array(
    CURLOPT_URL => $host."/rest/v1/leads/describe.json?access_token=".$token,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => array(
      "cache-control: no-cache"
    ),
  ));
  $response = curl_exec($curl);
  $err = curl_error($curl);
  curl_close($curl);

  if ($err) {
    return "cURL Error #:" . $err;
  } else {
    $json = json_decode($response);
    //echo "SUCCESS? :". $json->success;
    //print_r($json->result);
    for ($c=0;$c<count($json->result);$c++) {
      // echo "displayName = ".$json->result[$c]->displayName . " - ".$json->result[$c]->rest->name."<br>";
      array_push($marketo_fields, $json->result[$c]->rest->name);
    }
    return $json->success;
  }

}

function bodyBuilder($action, $lookupField, $input){
		$body = new stdClass();
		if (isset($action)){
			$body->action = $action;
		}
		if (isset($lookupField)){
			$body->lookupField = $lookupField;
		}
		$body->input = $input;
		$json = json_encode($body);
		return $json;
	}

/*Objects*/

class MApiError{
  public $success = "false";
  public $code = "00";
  public $message = "";

  function __construct($c,$m){
    $this->code = $c;
    $this->message = $m;

    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode($this);
    die;
  }
}

?>
