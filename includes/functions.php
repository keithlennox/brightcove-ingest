<?php

/*
Functions used by other brightcove ingest scripts.
*/

/*
oauth FUNCTION
Call Brightcove OAUTH API to get an access token. Access token expires in 5 minutes.
Before calling, checks to see if token has expired. If it has, gets a new one. If it has not, uses the exisiting token.
*/
function getToken(){
  global $refid; //The global keyword is needed to access variables that are used outside the function.
  global $counter;
  global $status_divider;
  global $status_value;
  global $status_file;
	global $sleep_dur;
  static $header; //The static keyword is needed to prevent variable from being deleted when the function completes.
  static $expire_time;
  while($counter <= 3){ 
    $current_time = time();
    if($current_time > $expire_time or !isset($expire_time)){
      $oauth_client_id = "xxxxxxxxxxxxxxxxxxxx";
      $oauth_client_secret = "xxxxxxxxxxxxxxxxxxxx";
      $oauth_authorization = "{$oauth_client_id}:{$oauth_client_secret}";
      $oauth_url = "https://oauth.brightcove.com/v3/access_token?grant_type=client_credentials";
      $oauth_header = array('Content-type: application/x-www-form-urlencoded',);
      $oath_post = array();
      $oauth_json_response = cURL("POST", $oauth_url, $oauth_header, $oath_post, $oauth_authorization);
      $oauth_array_response = json_decode($oauth_json_response,true);
      if(isset($oauth_array_response['access_token'])){//If token was returned...
        $access_token = $oauth_array_response["access_token"];// Get access token from response array.
        $header = array('Content-type: application/json', "Authorization: Bearer {$access_token}",); //Create header for cms and di calls
        $expire_time = time() + 290;
				//$status_value .= "GET ACCESS TOKEN: PASS ({$current_time}/{$expire_time}) - " . date('Y-m-d H:i:s') . "\r\n\r\n{$oauth_json_response}{$status_divider}"; //This junks up the status file. Only un-comment this line for testing.
        return $header;
        break;
      }else{//If token was not returend...
        trigger_error("Get access token failed for asset {$refid} - {$oauth_json_response}");
        if($counter == 3) {
          $status_value .= "GET ACCESS TOKEN: FAIL - " . date('Y-m-d H:i:s') . "\r\n\r\n{$oauth_json_response}{$status_divider}";
          file_put_contents($status_file, $status_value);
          die(1);
        }else{
          sleep($sleep_dur);
        }
      }
    }else{//If token is not expired...
			//$status_value .= "GET ACCESS TOKEN: SKIPPED ({$current_time}/{$expire_time}) - " . date('Y-m-d H:i:s') . $status_divider; //This junks up the status file. Only un-comment this line for testing.
      return $header;
      break;
    }
    $counter++;
  }//End while
}//End function

/*cURL FUNCTION
Uses cURL to make API calls to Brightcove.
*/
function cURL($type, $url, $header, $postfields, $authorization){
	global $refid;
	$ch = curl_init($url);
	if($type == "POST") {curl_setopt($ch, CURLOPT_POST, TRUE);}
	if($type == "PATCH") {curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');}
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	if($authorization != NULL) {curl_setopt($ch, CURLOPT_USERPWD, $authorization);}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
	if($type == "POST" or $type == "PATCH") {curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);}
	$response = curl_exec($ch);
	$error_no = curl_errno($ch);
	$error_message = curl_error($ch);
	curl_close($ch);
	$error = "cURL error #{$error_no} for asset {$refid} - {$error_message}";
	if($error_no != 0){
		trigger_error($error);
	}
	return $response;
}

/*
PURGE LOG FUNCTION
This function prevents log files from getting too big.
It takes 4 paremeters: path to log file, line ending characters, max file size in bytes, number of bytes to remove when max bytes is reached.
It checks the file size. If the file is over $max_bytes, it removes $purge_bytes from the begining of the file.
*/
function purgeLog($file, $line_end, $max_bytes, $purge_bytes) {
  $size = filesize($file); //Check the log file size (bytes).
  if($size > $max_bytes){ //If log file is too big, delete some from the beginning
    $temp_string = file_get_contents($file, NULL, NULL, $purge_bytes); //Load the existing log file into a temporary string except for the first x number of bytes
    $temp_string = strstr($temp_string, $line_end); //Remove all characters prior to the first line break
    $temp_string = ltrim($temp_string); //Remove the first line break
    file_put_contents($file, $temp_string); //Print the temporary string to the log file, overwriting previous contents of the file
  }
}

?>