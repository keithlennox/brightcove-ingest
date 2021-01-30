<?php

/*
- This scripts uploads a single vtt file to Brightcove using "Dynamic Ingest".
- This script requires F:\Vantage_Hot_Folders\Common_Elements\Scripts\Brightcove\includes\functions.php

EXPLANATION
- Vantage creates a vtt (caption or metadata) file and uploads it to a staging server.
- Vantage uses a notify action to trigger brightcove_vtt.bat. This windows batch file triggers brightcove_vtt.php (this script).
- PHP calls the Brightcove OAUTH API to get an access token. This is required for all other API calls.
- PHP calls the Brightcove CMS API to get the Brightcove ID and text tracks for the asset. These are needed for the next calls.
- To avoid duplicate text tracks on Brightcove, this script first removes existing caption or metadata tracks from the text tracks array before uploading new ones.
- PHP calls the Brightcove Dynamic Ingest API to ingest the vtt asset that Vantage put on the staging server. Brightcove returns the job ID.
- PHP writes a status file to the Brightcove output folder on the vantage SAN. This file contains the value returned from each API call that was made. This information tells you that Brightcove received and understood your instructions, but it does not tell you if the instructions were successfully carried out. For that you need to receive a callback from Brightcove. See the next step.
- Brightcove ingests the vtt and calls back to bc_di_callback.php. This PHP script writes the status of the ingest operation to a log file: bc_di_callback.log.
- When this script completes successfully, it returns a success code to the Vantage notify action.
- For additional documentation refer to brightcove_video.php.
*/

//PHP INI SETTINGS
set_time_limit(600); //Number of seconds a script is allowed to run. Does not measure time of anyhting that comes before it.

//INCLUDES
require "includes/functions.php";

//INITIALIZE VARS
$status_value = "";
$status_divider = "\r\n\r\n-----------------------------------------------------------------\r\n\r\n";
$sleep_dur = 180; //Seconds

//GET REF ID FROM VANTAGE
if(!isset($argv[1])) {
  trigger_error("Get reference id failed");
  die(1);
}else {
	$refid = $argv[1];
	if(substr($refid,-3) == "DVT") {
		$refid = substr($refid,0,-3);
		$kind = "metadata";
		$status_file = "F:\Vantage_Hot_Folders\Output\Brightcove\\{$refid}_DVT.txt";
		$vtt_url = "https://xxxxxxxxxx/captions/{$refid}DVT.vtt";
	}else {
		$kind = "captions";
		$status_file = "F:\Vantage_Hot_Folders\Output\Brightcove\\{$refid}_CC.txt";
		$vtt_url = "https://xxxxxxxxxx/captions/{$refid}.vtt";
	}
	$status_value .=  "GET REFERENCE ID: PASS - " . date("Y-m-d H:i:s") . "\r\n\r\n{$refid}{$status_divider}";
}

//GET ACCOUNT NUMBER FROM VANTAGE
if(!isset($argv[2])) {
  $status_value .=  "GET ACCOUNT NUMBER: FAIL - " . date("Y-m-d H:i:s") . "\r\n\r\nAccount parameter not set{$status_divider}";
  file_put_contents($status_file, $status_value);
  trigger_error("Get account number failed for asset {$refid}");
  die(1);
}else {
	$account = $argv[2];
  $status_value .=  "GET ACCOUNT NUMBER: PASS - " . date("Y-m-d H:i:s") . "\r\n\r\n{$account}{$status_divider}";
}

//GET SOURCE VTT FILE FROM VANTAGE
$vtt_file_headers = @get_headers($vtt_url);
if($vtt_file_headers[0] != 'HTTP/1.1 200 OK'){
	trigger_error("Get source vtt failed for asset {$refid} - {$vtt_file_headers[0]} - {$vtt_url}");
  $status_value .= "GET SOURCE VTT: FAIL - " . date("Y-m-d H:i:s") . "\r\n\r\n{$vtt_file_headers[0]}\r\n{$vtt_url}{$status_divider}";
  file_put_contents($status_file, $status_value);
  die(1);
}else {
  $status_value .= "GET SOURCE VTT: PASS - " . date("Y-m-d H:i:s") . "\r\n\r\n{$vtt_file_headers[0]}\r\n{$vtt_url}{$status_divider}";
}

//CREATE VARS FOR CMS CALLS
$cms_url = "https://cms.api.brightcove.com/v1/accounts/{$account}/videos/ref:{$refid}";
$cms_post["text_tracks"] = array(); //Create empty text trks array. If the array is empty we want an empty array returned not NULL

//CREATE VARS FOR DI CALL
$callback_url = "https://xxxxxxxxxxxxxxxxxxxx/bc_di_callback.php";
$di_post['callbacks'] = array($callback_url);
$di_post['text_tracks'][0]['url'] = $vtt_url;
$di_post['text_tracks'][0]['srclang'] = "en";
$di_post['text_tracks'][0]['kind'] = $kind;
$di_post['text_tracks'][0]['label'] = "English";
$di_post['text_tracks'][0]['default'] = false; //Changed to false Jan 2018 to stop cc from automatically playing in player version 6.
$di_post = json_encode($di_post); //Brightcove needs the post array in json format

/*
INITIALIZE LOOP COUNTER
Each API call executes within its own re-try loop.
Each loop re-tries 3 times.
Each loop increments the counter, but does not reset it.
This means that there can only be a total of 3 re-tries, regardless of how many API calls are made.
We do it this way because the Vantage Notify action waits for this script to successfully complete.
Vantage has a limited number of Notify actions that it can run concurently. This can create a bottleneck.
*/
$counter = 0;

/*
GET BRIGHTCOVE ID AND TEXT TRACKS
Calls CMS API using GET.
Uses the Brightcove reference ID to obtain the Brightcove ID and text tracks.
The Brightcove ID is required for the ingest call.
*/
while($counter <= 3){
  $header = getToken(); //Returns existing BC token if it has not expired, otherwise returns new token. See includes/functions.php for details.
  $cms_get_json_response = cURL("GET", $cms_url, $header, null, null);
  $cms_get_array_response = json_decode($cms_get_json_response,true);
  if(isset($cms_get_array_response['id'])){
    $id = $cms_get_array_response["id"]; // Get Brightcove video ID from response array.
    $di_url = "https://ingest.api.brightcove.com/v1/accounts/{$account}/videos/{$id}/ingest-requests";
    $status_value .= "GET BRIGHTCOVE ID AND TEXT TRACKS: PASS (found {$id}) - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_get_json_response}{$status_divider}";
    break;
  }else{
    trigger_error("Get Brightcove ID and text tracks failed for asset {$refid} - {$cms_get_json_response}");
    if($counter == 3) {
      $status_value .= "GET BRIGHTCOVE ID AND TEXT TRACKS: FAIL - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_get_json_response}{$status_divider}";
      file_put_contents($status_file, $status_value);
      die(1);
    }else{
      sleep($sleep_dur); //Sleep before re-trying
    }
  } //End if (API call succeeded)
  $counter++; //Increment loop counter
} //End while loop

/*
DELETE TEXT TRACKS
Calls CMS API using PATCH
If you are uploading captions and the text track array already contains a caption trk, this removes the existing caption trk.
Conversely, if you are uploading metadata and the text track array already contains a metadata trk, this removes the existing metadata trk.
If you don't do this you will end up with duplicates. Brightcove has no overwite functionality when it comes to text tracks.
*/
while($counter <= 3){
	foreach ($cms_get_array_response['text_tracks'] as $value) {
		if($value['kind'] != $kind){
			$cms_post['text_tracks'][] = $value;
		}
	}
	$cms_post = json_encode($cms_post); //Brightcove needs the post array in json format
	$header = getToken(); //Returns existing BC token if it has not expired, otherwise returns new token. See includes/functions.php for details.
	$cms_delete_json_response = cURL("PATCH", $cms_url, $header, $cms_post, null); //type,url,header,postfields,authorization
	$cms_delete_array_response = json_decode($cms_delete_json_response,true);
	if(isset($cms_delete_array_response['text_tracks'])){
		$status_value .= "DELETE EXISTING VTT: PASS - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_delete_json_response}{$status_divider}";
		break;
	}else{
		trigger_error("Delete existing VTT failed for asset {$refid} - {$cms_delete_json_response}");
		if($counter == 3) {
			$status_value .= "DELETE EXISTING VTT: FAIL - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_delete_json_response}{$status_divider}";
			file_put_contents($status_file, $status_value);
			die(1);
		}else{
			sleep($sleep_dur); //Sleep before re-trying
		}
	} //End if (API call succeeded)
	$counter++; //Increment loop counter
} //End while loop

/*
INGEST CC
Calls the Dynamic Ingest API using POST.
Ingests vtt file.
*/
while($counter <= 3){
  $header = getToken(); //Returns existing BC token if it has not expired, otherwise returns new token. See includes/functions.php for details.
  $di_json_response = cURL("POST", $di_url, $header, $di_post, null);
  $di_array_response = json_decode($di_json_response,true);
  if(isset($di_array_response['id'])){
    $status_value .= "INGEST VTT: PASS - " . date('Y-m-d H:i:s') . "\r\n\r\n{$di_json_response}{$status_divider}";
    break;
  }else{
    trigger_error("Ingest cc failed for asset {$refid} - {$di_json_response}");
    if($counter == 3) {
      $status_value .= "INGEST VTT: FAIL - " . date('Y-m-d H:i:s') . "\r\n\r\n{$di_json_response}{$status_divider}";
      file_put_contents($status_file, $status_value);
      die(1);
    }else{
      sleep($sleep_dur); //Sleep before re-trying
    }
  } //End if (API call succeeded)
  $counter++; //Increment loop counter
} //End while loop

//PRINT TO STATUS FILE
file_put_contents($status_file, $status_value);

/*
CALL PURGE LOG FUNCTION
This function insures that the errors log file does not become too full.
See includes/functions.php for details.
*/
purgeLog("F:\Vantage_Hot_Folders\Common_Elements\Scripts\Brightcove\\errors.log", "\r\n", 1000000, 250000); //Windows line breaks

?>