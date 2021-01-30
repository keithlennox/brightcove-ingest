<?php

/*
Captures Brightcove callback into a log file.
Expects bc_di_callback.log and bc_di_error.log in same directory.
Do not delete log files or apache write permissions to these files may be lost.
Brightcove sends a separate callback for each title (entityType=TITLE) and each rendition (entityType=ASSET).
*/

/*
Some notes on log file size:
10 videos published to Brightcove = approx 16000 bytes of data in this log file.
Max file size for our log file is 1,000,000 bytes or 625 Brightcove videos.
Min file size is 750,000 bytes or 450 Brightcove videos.
*/

/*
This script expects the following to be set in php_ini. These can be checked using ini_get() or phpinfo().
error_reporting=22527
display_errors=off
display_startup_errors=off
log_errors=on
log_errors_max_length=1024
*/

//Write errors to our local log file instead of the default location
ini_set("error_log", "../cdn2/bc_di_error.log");

//Enable CORS
header("Access-Control-Allow-Origin: *");

//Print message to screen
print "Dynamic Ingest Callabck is running";

//Set time zone and timestamp
date_default_timezone_set('America/Toronto');
$timestamp = date("Y-m-d H:i:s");

//Get info returned from Brightcove
$json = file_get_contents('php://input');

//Format the log entry
$log_entry = "{$timestamp} - {$json}\r\n";

//Use for testing only
//trigger_error("Test error message Test error message Test error message Test error message Test error message Test error message");

//Print to log
file_put_contents("../cdn2/bc_di_callback.log", $log_entry, FILE_APPEND);

//Call purgeLog function
purgeLog("../cdn2/bc_di_callback.log", "\r\n", 1000000, 250000); //Windows line breaks
purgeLog("../cdn2/bc_di_error.log", "\n", 1000000, 250000); //Linux line breaks

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

/*
The code below can be used to parse json returned from Brightcove for certain values.
These values can be used to trigger other code.
The code below sends an email if there's an error or if the entityType = title.

$array= json_decode($json,true);
$entity = $array['entity'];
$entityType = $array['entityType'];
$status = $array['status'];
if($entityType == "TITLE"){
  mail("xxxxxxxxx@xxx.org",$entity,$log_entry);
}
if($status == "FAILED"){
  mail("xxxxxxxxx@xxx.org",$entity,$log_entry);
}
*/

?>