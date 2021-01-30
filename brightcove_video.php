<?php

/*
- This scripts uploads a single video file to Brightcove using "Dynamic Ingest".
- This script requires F:\Vantage_Hot_Folders\Common_Elements\Scripts\Brightcove\includes\functions.php

EXPLANATION
- Vantage creates a high quality MP4 file and uploads it to a staging server.
- Vantage uses a notify action to trigger brightcove_video.bat. This windows batch file triggers brightcove_video.php (this script).
- PHP parses the Telescope source XML for all metadata values required by Brightcove.
- PHP calls the Brightcove OAUTH API to get an access token. This is required for all other API calls.
- PHP calls the Brightcove CMS API to create a new metadata record. PHP passes all required metadata values to Brightcove in this call. If the asset already exists on Brightcove, another CMS API call is made that overwrites the existing record. Brightcove returns the Brightcove ID, which is required for the next call.
- PHP calls the Brightcove Dynamic Ingest API to ingest the video asset that Vantage put on the staging server. Brightcove returns the job ID.
- If the Brightcove reference ID contains "AltAudio", PHP appends the content title with "(Described Video)".
- If the Brightcove reference ID contains "AltAudio", PHP calls the CMS API to find the matching video on Brightcove, the one that contains normal audio.
- If the Brightcove reference ID contains "AltAudio", PHP calls the CMS API to re-name the matching video. For example, re-name 123456X to 123456DV. This is the match for 123456DV_AltAudio.
- PHP writes a status file to the Brightcove output folder on the vantage SAN. This file contains the value returned from each API call that was made. This information tells you that Brightcove received and understood your instructions, but it does not tell you if the instructions were successfully carried out. For that you need to receive a callback from Brightcove. See the next step.
- Brightcove ingests the video and calls back to bc_di_callback.php. This PHP script writes the status of the ingest operation to a log file bc_di_callback.log.
- When this script completes successfully, it returns a success code to the Vantage notify action.
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
  $status_file = "F:\Vantage_Hot_Folders\Output\Brightcove\\{$refid}.txt";
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

//GET SOURCE FILE FROM VANTAGE
$video_url = "https://xxxxxxxxxxxxxxxxxxxx/{$refid}.mp4"; //Requires HTTPS and authentication (user:password).
$video_file_headers = @get_headers($video_url); //We disable errors with @.
if($video_file_headers[0] != 'HTTP/1.1 200 OK'){
  trigger_error("Get source video failed for asset {$refid} - {$video_file_headers[0]} - {$video_url}");
  $status_value .= "GET SOURCE VIDEO: FAIL - " . date("Y-m-d H:i:s") . "\r\n\r\n{$video_file_headers[0]}\r\n{$video_url}{$status_divider}";
  file_put_contents($status_file, $status_value);
  die(1);
}else {
  $status_value .= "GET SOURCE VIDEO: PASS - " . date("Y-m-d H:i:s") . "\r\n\r\n{$video_file_headers[0]}\r\n{$video_url}{$status_divider}";
}

/*
GET TELESCOPE XML
If a tag in the XML is missing or if the tag is present but the value is missing, a NULL value is passed to the var. No error is returned.
*/
if(!isset($argv[3])) {
	$status_value .=  "GET SOURCE XML: FAIL - " . date("Y-m-d H:i:s") . "\r\n\r\nXML path parameter not set{$status_divider}";
	trigger_error("Get source XML failed for asset {$refid} - XML path parameter not set");
  file_put_contents($status_file, $status_value);
  die(1);
}else {
	$xml_path = $argv[3];
	$xml = simplexml_load_file($xml_path);
	if ($xml === false) {
		$status_value .=  "GET SOURCE XML: FAIL - " . date("Y-m-d H:i:s"). "\r\n\r\nXML failed to load: {$xml_path}{$status_divider}";
		trigger_error("Get source XML failed for asset {$refid} - XML failed to load: {$xml_path}");
    file_put_contents($status_file, $status_value);
    die(1);
	}else {
   $status_value .=  "GET SOURCE XML: PASS - " . date("Y-m-d H:i:s") . "\r\n\r\n{$xml_path}{$status_divider}";
		$title = (string)$xml->asset->EpisodeTitle;
		$short_description = (string)$xml->asset->ShortDescription;
		$long_description = (string)$xml->asset->InternetDescription;
		$keywords = (string)$xml->asset->Keywords;
		$asset_type = (string)$xml->asset->AssetType;
		$geogate_filter  = (string)$xml->asset->GeogateFilter; //Allow
		$geogate_territory = (string)$xml->asset->GeogateTerritory; //CA or 00
		$publish_point = (string)$xml->asset->PublishPoint;
		$sort_order = (string)$xml->asset->SortOrder;
		$tvo_embeddable = (string)$xml->asset->TVOEmbeddable; //Yes or No
		$tvo_series = (string)$xml->asset->TVOSeries;
		$tvo_seriesname = (string)$xml->asset->SeriesTitle;
		$valid_from = (string)$xml->asset->UTCValidFrom; //Value from Telescope compensates for Daylight Savings Time.
		$valid_to = (string)$xml->asset->UTCValidTo; //Value from Telescope compensates for Daylight Savings Time.
		$web_distribution_strand = (string)$xml->asset->WEB_DISTRIBUTION_STRAND;
		$is_documentary = (string)$xml->asset->IS_DOCUMENTARY;
		$is_promo = (string)$xml->asset->IS_PROMO;
		$is_archive = (string)$xml->asset->IS_ARCHIVE;
	}
}

//GET VIDEO HEIGHT FROM VANTAGE
if(!isset($argv[4])) {
  $status_value .=  "GET VIDEO HEIGHT: FAIL - " . date("Y-m-d H:i:s") . "\r\n\r\nVideo height parameter not set{$status_divider}";
  file_put_contents($status_file, $status_value);
  trigger_error("Get video height failed for asset {$refid}");
  die(1);
}else {
	$video_height = $argv[4];
  $status_value .=  "GET VIDEO HEIGHT: PASS - " . date("Y-m-d H:i:s") . "\r\n\r\n{$video_height}{$status_divider}";
}

//ASSIGN VALUES BASED ON REF ID
if(substr($refid, -9) == '_AltAudio'){ //If the refid ends with _AltAudio (case sensitive)
  $title = "{$title} (Described Video)";
}

//ASSIGN VALUES BASED ON PUBLISH POINT AND VIDEO HEIGHT
if(strpos($publish_point,"Brightcove / tvo.org HQ") !== false or strpos($publish_point,"Described Video / tvo.org HQ") !== false) {
  $ingest_profile = "tvo-custom-dd-ingest-profile-hq";
}elseif(strpos($publish_point,"Brightcove / ILC") !== false and $video_height == "1080"){
  $ingest_profile = "tvo-custom-dd-ingest-profile-1080";
}else{
  $ingest_profile = "tvo-custom-dd-ingest-profile";
}

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
FIND MATCHING REFERENCE ID
This code makes sure that any alt audio asset publish and kill dates match that of the regular audio asset.
If this is an alt audio asset, call CMS API using GET to find matching regular audio asset. If found, populate start/end dates with data from regular audio asset. If not found, die.
If this is a regular audio asset, populate start/end dates with data from source XML.
Start/end dates are used in the next API call.
*/
if(substr($refid, -9) == '_AltAudio'){ //If the refid ends with _AltAudio (case sensitive)
  while($counter <= 3){
    $header = getToken(); //Returns existing BC token if it has not expired, otherwise returns new token. See includes/functions.php for details.
    $base_refid = chop($refid,"DV_AltAudio"); //The ref id without the DV_AltAudio (123456DV_AltAudio)
    $cms_search_url = "https://cms.api.brightcove.com/v1/accounts/{$account}/videos?q=%2Dname:%22Described%20Video%22%20%2Breference_id:{$base_refid}"; //Searches for reference_ids that contain the base ref id (no X or DV) where the title does not contain (Described Video).
    $cms_search_json_response = cURL("GET", $cms_search_url, $header, null, null);
    $cms_search_array_response = json_decode($cms_search_json_response,true); //Returns an array of video information for each match found.
    if(isset($cms_search_array_response[0]['reference_id']) and count($cms_search_array_response) == 1) {//If a single matching reference id was returned (if the first condition fails, PHP will not test the second condition)
      $matching_refid = $cms_search_array_response[0]['reference_id']; //Code later in this script checks if this var isset. If it is, makes an API call to update the ref id of the regular audio asset.
			$cms_post['schedule']['starts_at'] = $cms_search_array_response[0]['schedule']['starts_at'];
			$cms_post['schedule']['ends_at'] = $cms_search_array_response[0]['schedule']['ends_at'];
      $status_value .= "FIND MATCHING REFERENCE ID: PASS (found {$matching_refid}) - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_search_json_response}{$status_divider}";
      break;
    }else{
      trigger_error("Find matching refid failed for asset {$refid} - {$cms_search_json_response}");
      if($counter == 3) {
        $status_value .= "FIND MATCHING REFERENCE ID: FAIL (no match found for {$refid}) - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_search_json_response}{$status_divider}";
        file_put_contents($status_file, $status_value);
        die(1);
      }else{
        sleep($sleep_dur); //Sleep before re-trying
      }
    } //End if (API call succeeded)
  $counter++; //Increment loop counter
  }//End while loop
}else{
	$cms_post['schedule']['starts_at'] = substr_replace(substr_replace($valid_from,"Z",20,0),"T",10,1);
	$cms_post['schedule']['ends_at'] = substr_replace(substr_replace($valid_to,"Z",20,0),"T",10,1);
}//End if (refid ends with AltAudio)

/*CREATE VARS FOR CMS CREATE AND OVERWRITE CALLS
An empty (null) var passed to the $cms_post array will not cause a PHP error.
If the field is required by Brightcove, Brightcove will return an error.
If the field is optional for Brightcove, Brightcove will not return an error but will populate the value with nothing.
Note: starts_at and ends_at are grabbed above.
*/
$cms_create_url = "https://cms.api.brightcove.com/v1/accounts/{$account}/videos";
$cms_overwrite_url ="https://cms.api.brightcove.com/v1/accounts/{$account}/videos/ref:{$refid}";
$cms_post['name'] = $title; //Required
$cms_post['description'] = $short_description; //Optional
$cms_post['economics'] = "FREE"; //Economics
$cms_post['long_description'] = $long_description; //Optional
$cms_post['reference_id'] = $refid; //Optional
$cms_post['state'] = "ACTIVE"; //Optional
$cms_post['tags'] = explode(",", $keywords); //Optional
$cms_post['custom_fields']['assettype'] = $asset_type; //Optional
$cms_post['custom_fields']['geogatefilter'] = $geogate_filter; //Optional
$cms_post['custom_fields']['geogateterritory'] = $geogate_territory; //Optional
$cms_post['custom_fields']['publishpoints'] = $publish_point; //Optional
$cms_post['custom_fields']['sortorder'] = $sort_order; //Optional
if($tvo_embeddable == "Yes") { //Optional
  $cms_post['custom_fields']['tvoembeddable'] = "1";
}elseif($tvo_embeddable == "No") {
  $cms_post['custom_fields']['tvoembeddable'] = "0";
}
$cms_post['custom_fields']['tvoseries'] = $tvo_series; //Optional
$cms_post['custom_fields']['tvoseriesname'] = $tvo_seriesname; //Optional
if(strpos($publish_point,"Brightcove / ILC World") !== false) { //If Brightcove / ILC World
	$cms_post['geo']['restricted'] = FALSE; //Optional
	$cms_post['geo']['countries'] = array(); //Optional
	$cms_post['geo']['exclude_countries'] = NULL; //Optional
}elseif(strpos($publish_point,"Brightcove / ILC Canada") !== false) { //If Brightcove / ILC Canada
	$cms_post['geo']['restricted'] = TRUE; //Optional
	$cms_post['geo']['countries'] = array('ca'); //Optional
	$cms_post['geo']['exclude_countries'] = FALSE; //Optional
}elseif($geogate_territory == "00" and $geogate_filter == "Allow") { //If available worldwide
	$cms_post['geo']['restricted'] = FALSE; //Optional
	$cms_post['geo']['countries'] = array(); //Optional
	$cms_post['geo']['exclude_countries'] = NULL; //Optional
}elseif($geogate_territory == "CA" and $geogate_filter == "Allow") { //If available in Canada only
	$cms_post['geo']['restricted'] = TRUE; //Optional
	$cms_post['geo']['countries'] = array('ca'); //Optional
	$cms_post['geo']['exclude_countries'] = FALSE; //Optional
}
$cms_post['custom_fields']['web_distribution_strand'] = $web_distribution_strand; //Optional
$cms_post['custom_fields']['is_documentary'] = $is_documentary; //Optional
$cms_post['custom_fields']['is_promo'] = $is_promo; //Optional
$cms_post['custom_fields']['is_archive'] = $is_archive; //Optional
$cms_post = json_encode($cms_post); //Brightcove needs the post array in json format

//CREATE VARS FOR DI CALL
$callback_url = "https://xxxxxxxxxxxxxxxxxxx/bc_di_callback.php";
$di_post['master'] = array("url" => $video_url);
$di_post['callbacks'] = array($callback_url);
$di_post['profile'] = $ingest_profile;
$di_post['capture-images'] = true;
$di_post = json_encode($di_post); //Brightcove needs the post array in json format

/*
CREATE NEW TITLE
Calls CMS API using POST.
Creates new metadata record with a reference ID that matches Telescope ID.
If successfull, returns the Brightcove ID.
Fails if the reference ID already exists on Brightcove.
*/
while($counter <= 3){
  $header = getToken(); //Returns existing BC token if it has not expired, otherwise returns new token. See includes/functions.php for details.
  $cms_create_json_response = cURL("POST", $cms_create_url, $header, $cms_post, null); //Make API call
  $cms_create_array_response = json_decode($cms_create_json_response,true); //Convert json to array
  if(isset($cms_create_array_response['id'])){
    $id = $cms_create_array_response["id"]; //Get Brightcove video ID from response array.
    $di_url = "https://ingest.api.brightcove.com/v1/accounts/{$account}/videos/{$id}/ingest-requests";
    $status_value .= "CREATE NEW METADATA RECORD: PASS - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_create_json_response}{$status_divider}";
    break;
  }elseif($cms_create_array_response[0]['error_code'] == "REFERENCE_ID_IN_USE"){
    break;
  }else{
    trigger_error("Create new metadata record failed for asset {$refid} - {$cms_create_json_response}");
    if($counter == 3) {
      $status_value .= "CREATE NEW METADATA RECORD: FAIL - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_create_json_response}{$status_divider}";
      file_put_contents($status_file, $status_value);
      die(1);
    }else{
      sleep($sleep_dur); //Sleep before re-trying
    }
  } //End if (API call succeeded)
  $counter++; //Increment loop counter
} //End while loop

/*
OVERWRITE EXISTING TITLE
If the referene ID already exists on Brightcove, calls CMS API using PATCH.
Overwrites the exisiting record on Brightcove.
*/
if(isset($cms_create_array_response[0]['error_code']) and $cms_create_array_response[0]['error_code'] == "REFERENCE_ID_IN_USE"){ //If asset already exists in previous call
  while($counter <= 3){
    $header = getToken(); //Returns existing BC token if it has not expired, otherwise returns new token. See includes/functions.php for details.
    $cms_overwrite_json_response = cURL("PATCH", $cms_overwrite_url, $header, $cms_post, null);
    $cms_overwrite_array_response = json_decode($cms_overwrite_json_response, true);
    if(isset($cms_overwrite_array_response['id'])){
      $id = $cms_overwrite_array_response["id"]; // Get Brightcove video ID from response array.
      $di_url = "https://ingest.api.brightcove.com/v1/accounts/{$account}/videos/{$id}/ingest-requests";
      $status_value .= "OVERWRITE EXISTING METADATA RECORD: PASS - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_overwrite_json_response}{$status_divider}";
      break;
    }else{
      trigger_error("Overwrite existing metadata record failed for asset {$refid} - {$cms_overwrite_json_response}");
      if($counter == 3) {
        $status_value .= "OVERWRITE EXISTING METADATA RECORD: FAIL - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_overwrite_json_response}{$status_divider}";
        file_put_contents($status_file, $status_value);
        die(1);
      }else{
        sleep($sleep_dur); //Sleep before re-trying
      }
    } //End if (API call succeeded)
    $counter++; //Increment loop counter
  } //End while loop
} //End if (asset already exists )

/*
INGEST VIDEO
Calls the Dynamic Ingest API using POST.
Ingests master video file.
*/
while($counter <= 3){
  $header = getToken(); //Returns existing BC token if it has not expired, otherwise returns new token.See includes/functions.php for details.
  $di_json_response = cURL("POST", $di_url, $header, $di_post, null);
  $di_array_response = json_decode($di_json_response,true);
  if(isset($di_array_response['id'])){
    $status_value .= "INGEST VIDEO: PASS - " . date('Y-m-d H:i:s') . "\r\n\r\n{$di_json_response}{$status_divider}";
    break;
  }else{
    trigger_error("Ingest video failed for asset {$refid} - {$di_json_response}");
    if($counter == 3) {
      $status_value .= "INGEST VIDEO: FAIL - " . date('Y-m-d H:i:s') . "\r\n\r\n{$di_json_response}{$status_divider}";
      file_put_contents($status_file, $status_value);
      die(1);
    }else{
      sleep($sleep_dur); //Sleep before re-trying
    }
  } //End if (API call succeeded)
  $counter++; //Increment loop counter
} //End while loop

/*
RENAME MATCHING REFERENCE ID
Calls CMS API using PATCH.
Re-names the original reference id (regular audio) version from assetId or assetIdX to assetIdDV.
*/
if(isset($matching_refid)){ //If a single matching reference id was returned in the previous call
  $DV_refid = chop($refid,"_AltAudio"); //The ref id without the _AltAudio (123456DV)
  $cms_rename_url ="https://cms.api.brightcove.com/v1/accounts/{$account}/videos/ref:{$matching_refid}";
  $cms_rename_post['reference_id'] = $DV_refid;
  $cms_rename_post = json_encode($cms_rename_post); //Brightcove needs the post array in json format
  while($counter <= 3){
    $header = getToken(); //Returns existing BC token if it has not expired, otherwise returns new token. See includes/functions.php for details.
    if($matching_refid == $DV_refid) {
      $status_value .= "RENAME MATCHING REFERENCE ID: SKIPPED ({$matching_refid} already named correctly) - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_search_json_response}{$status_divider}";
      break;
    }else{
      $cms_rename_json_response = cURL("PATCH", $cms_rename_url, $header, $cms_rename_post, null);
      $cms_rename_array_response = json_decode($cms_rename_json_response,true);
      if(isset($cms_rename_array_response['reference_id']) and $cms_rename_array_response['reference_id'] == $DV_refid){//If the first condition fails, PHP will not test the second condition (i think!)
        $status_value .= "RENAME MATCHING REFERENCE ID: PASS ({$matching_refid} renamed to {$DV_refid}) - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_rename_json_response}{$status_divider}";
        break;
      }else{
        trigger_error("Rename matching reference ID failed for asset ({$matching_refid} not renamed to {$DV_refid}) - {$cms_rename_json_response}");
        if($counter == 3) {
          $status_value .= "RENAME MATCHING REFERENCE ID: FAIL ({$matching_refid} not renamed to {$DV_refid}) - " . date('Y-m-d H:i:s') . "\r\n\r\n{$cms_rename_json_response}{$status_divider}";
          file_put_contents($status_file, $status_value);
          die(1);
        }else{
          sleep($sleep_dur); //Sleep before re-trying
        }
      }
    } //End if (API call succeeded)
    $counter++; //Increment loop counter
  } //End while loop
} //End if (matching ref id found)

//PRINT TO STATUS FILE
file_put_contents($status_file, $status_value);

/*
CALL PURGE LOG FUNCTION
This function insures that the errors log file does not become too full.
See includes/functions.php for details.
*/
purgeLog("F:\Vantage_Hot_Folders\Common_Elements\Scripts\Brightcove\\errors.log", "\r\n", 1000000, 250000); //Windows line breaks

?>