<?php

/**
 * File Name: index.php
 * Author: Sergio Sebastiani
 * Date: 2024-06-10
 * Description: see readme.txt
 */

/* Set configuration */


//error_reporting(E_ALL);
//ini_set('display_errors', 1);

$conf = json_decode(file_get_contents("./conf.json"), true);

$servername = $conf['database']['server'];
$username = $conf['database']['username'];
$password = $conf['database']['password'];;

$port = $conf['database']['port'];
$db = $conf['database']['db'];

$table = $conf['database']['table'];
//$snapshot_table = $conf['database']['snapshot_table'];

$key = $conf['database']['pk'];
$timestamp_col = $conf['database']['timestamp_col'];

$sql_dir = $conf['paths']['sql_dir'];
$sql_filename = $conf['paths']['sql_dir']."/".$conf['paths']['sql_filename'];
$logs_folder = $conf['paths']['logs_folder'];
$log_filename = $conf['paths']['log_filename'];

$period_min =  $conf['period_min'];

$transfer_method_post_state = $conf['transfer_method']['post']['active'];
$url = $conf['transfer_method']['post']['url'];

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../generic.php';

// Import Monolog classes
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\ErrorLogHandler;

// Define the folder path - Create the folder if it doesn't exist
$logFolder = __DIR__ . '/' . $logs_folder . '/';  
if (!file_exists($logFolder)) {mkdir($logFolder, 0777, true);}

// Create a logger instance
$log = new Logger('my_logger');

// Define the log file path inside the folder
$logFile = $logFolder . $log_filename . '.log';


$handler = new RotatingFileHandler($logFile, 1, Logger::DEBUG);

// Set filename format for daily rotation
$handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');

// Add the handler to the logger
$log->pushHandler($handler);


$log->info("Start Generate and Send SQL");
$log->info('Conf: ', $conf);


// Connect to your database securely
try {

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Throw exceptions on errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Set default fetch mode
        PDO::ATTR_EMULATE_PREPARES   => false,                   // Disable emulated prepared statements
    ];

    $pdo = new PDO('mysql:host='.$servername.';port='.$port.';dbname='.$db, $username, $password, $options);

    $log_msg = "Connected to server: " . $servername . " database:" . $db;
    $log->info($log_msg);
    _echo($log_msg,1);

} catch (PDOException $e) {
    $error_message = "Error connecting to the database: " . $e->getMessage();
    $log->error($error_message);
    _echo($log_msg,2);
}



use Predis\Client;


$redis = new Client([
    'scheme' => 'tcp',
    'host'   => 'redis',  // This should match the service name in Docker Compose
    'port'   => 6379      // Default Redis port
]);



// Open file to store changes as SQL querys 
$sql_file = fopen('./'.$sql_filename, 'w');


/*************************************************************************/ 
// Generate SQL statements for insert updated records
/*************************************************************************/

$log_msg = "Start searching records new or modified records";
$log->info($log_msg);
_echo($log_msg,1);


$thisStartExecutionKey = 'script:this_execution_start_timestamp';
$startTimeStamp = $redis->get($thisStartExecutionKey);

// if not found start timestamp in Y-m-d H:i:s format 
if(DateTime::createFromFormat('Y-m-d H:i:s', $startTimeStamp) === false){

    // Calculate the timestamp for $period_min minutes ago
    $MinutesAgoTimestamp = strtotime('-'.$period_min.' minutes');
      
    // Format the timestamp as 'Y-m-d H:i:s'
    $startTimeStamp = date("Y-m-d H:i:s", $MinutesAgoTimestamp );
    
    $log_msg = "Start timestamp not found. Set to: " . $startTimeStamp;
    $log->info($log_msg);
    _echo($log_msg,1);
}
else{
    $log_msg = "Start timestamp set to: " . $startTimeStamp;
    $log->info($log_msg);
    _echo($log_msg,1);
}

$endTimeStamp = new DateTime(); 
$endTimeStamp = $endTimeStamp->format('Y-m-d H:i:s');


// Fetch column names
$sql = "SHOW COLUMNS FROM ".$table;
$query = $pdo->prepare($sql);
$query->execute();
$columns = $query->fetchAll(PDO::FETCH_COLUMN);

// Finde new / modified records                        
$sql = "SELECT * FROM $table WHERE $timestamp_col > '$startTimeStamp' AND $timestamp_col <= '$endTimeStamp'";

$query = $pdo->prepare($sql);
$query->execute();
$newRecords = $query->fetchAll(PDO::FETCH_ASSOC);

$log_msg = "Executed query: " . $sql;
$log->info($log_msg);

$log_msg = "Found: " . count($newRecords) . " new or modified records";
$log->info($log_msg);
_echo($log_msg,1);


if (!empty($newRecords)) {
    foreach ($newRecords as $record) {

        $values = [];
        foreach ($columns as $column) {
            $values[] = $pdo->quote($record[$column]);
        }

        $updateClauses = [];
        foreach ($columns as $column) {
            $updateClauses[] = "$column = VALUES($column)";
        }

        $insertQuery = "INSERT INTO $table (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ")
                ON DUPLICATE KEY UPDATE data = VALUES(data), " . implode(", ", $updateClauses);
        
        fwrite($sql_file, $insertQuery . ";\n");

    }
}

$log_msg = "Stop searching new or modified records";
$log->info($log_msg);
_echo($log_msg,1);



/*************************************************************************/
// Generate SQL statements for deleted records
/*************************************************************************/

$log_msg = "Start searching deleted records";
$log->info($log_msg);
_echo($log_msg,1);


// Get primary Key
if(!isset($key) || strlen($key) == 0){


    $sql = "SELECT k.COLUMN_NAME FROM information_schema.table_constraints t 
    JOIN information_schema.key_column_usage k USING(constraint_name, table_schema, table_name) 
    WHERE t.constraint_type = 'PRIMARY KEY' AND t.table_schema = DATABASE() AND t.table_name = '".$table."';";
    $query = $pdo->prepare($sql);
    $query->execute();
    $deletedRecords = $query->fetchAll(PDO::FETCH_ASSOC);

    $log_msg = "Executed query: " . $sql;
    $log->info($log_msg);
    
    if(count($deletedRecords) == 1){
        $key = $deletedRecords['COLUMN_NAME'];
        $log_msg = "Found primary key: " . $key;
        $log->info($log_msg);
    }
    else{
        $log_msg = 'Not found primary key for taable: ' . $table;
        $log->error($log_msg);
        _echo($log_msg,2);
    }
}
else{
    $log_msg = "Found primary key: " . $key;
    $log->info($log_msg);
}


$currentKeys = $pdo->query("SELECT ".$key." FROM ".$db.".".$table)->fetchAll(PDO::FETCH_COLUMN);

$deletedKeys = [];
foreach ($redis->sMembers('snapshot_keys') as $snapshotKey) {
    if (!in_array($snapshotKey, $currentKeys)) {
        $deletedKeys[] = $snapshotKey;  // These keys are no longer in the main table
    }
}

if (!empty($deletedKeys)) {

    $log_msg = "Found: " . count($deletedKeys) . " deleted records";
    $log->info($log_msg);
    _echo($log_msg,1);

    foreach ($deletedKeys as $key) {
        $deleteQuery = "DELETE FROM ".$db.".".$table." WHERE ID = " . $key;
        fwrite($sql_file, $deleteQuery . ";\n");  // Write to the file
    }
}

fclose($sql_file);

$redis->del('snapshot_keys');  // Clear any previous snapshot

// Loop through the keys and add each to the Redis set
foreach ($currentKeys as $key) {
    $redis->sAdd('snapshot_keys', $key);
}

$log_msg = "Stop searching deleted records";
$log->info($log_msg);
_echo($log_msg,1);

// Closed connection with dB
$pdo = null;
$log_msg = "Closed connection with dB";
$log->info($log_msg);
_echo($log_msg,1);

// Saved timestamp for start value next execution
$redis->set($thisStartExecutionKey, $endTimeStamp);
$log_msg = "Saved start value timestamp for next execution: " . $endTimeStamp;
$log->info($log_msg);
_echo($log_msg,1);


// Rename SQL file
$newFileName = $conf['paths']['sql_dir']."/".$startTimeStamp. "_" . $endTimeStamp . "_" . $conf['paths']['sql_filename'];
$newFileName = str_replace(' ', '', $newFileName);
$newFileName = str_replace('-', '', $newFileName);
$newFileName = str_replace(':', '', $newFileName);

rename(__DIR__ . '/' . $sql_filename, __DIR__ . '/' .$newFileName);
$log_msg = "renamed: " . __DIR__ . '/' . $sql_filename . ' into: ' . __DIR__ . '/' .$newFileName;
$log->info($log_msg);


// Check if the file is empty
if (filesize($sql_filename) === 0) {
    $log_msg = "No changes";
    $log->info($log_msg);
    _echo($log_msg,1);
}
else if ($transfer_method_post_state){

    $log_msg = "Start sending post request to remote server at: " . $url;
    $log->info($log_msg);
    _echo($log_msg,1);


    $log_msg = "Changes Update/Insert: " . count($newRecords);
    $log->info($log_msg);

    $log_msg = "Changes Delete: " . count($deletedRecords);
    $log->info($log_msg);



    // Initialize a cURL session
    $ch = curl_init();

    // Set the URL for the POST request
    $url = $conf['url'];
 
    // Define the POST data
    $postData = [
        'file' => new CURLFile(__DIR__ . '/' . $newFileName),  // Attach the file
        // Add other key-value pairs as needed
    ];

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);  // Use multipart/form-data for file upload
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    
    // Set any additional headers if needed
    //curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //    'Authorization: Bearer YOUR_ACCESS_TOKEN',  // Replace with your token if needed
    //]);

    // Execute the POST request
    $response = curl_exec($ch);
      // Check for cURL errors
    if ($response === false) {
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $log_msg = 'Response:' . 'cURL error: ' . $curlError . ' (errno: ' . $curlErrno . ')';
        $log->error($log_msg);
        _echo($log_msg,1);

    } else {
        // Print the response from the server
        $log_msg = "Response from server - start:";
        $log->info($log_msg);
        _echo($log_msg,1);

        $log_msg = $response;
        $log->info($log_msg);
        _echo($log_msg,1);

        $log_msg = "Response from server - stop";
        $log->info($log_msg);
        _echo($log_msg,1);
    }


    // Close the cURL session
    curl_close($ch);

    
    $log_msg = "Stop sending post request to remote server";
    $log->info($log_msg);
    _echo($log_msg,1);
}
else{
    $log_msg = "No tranfer method selected";
    $log->info($log_msg);
    _echo($log_msg,1);
}


/* Stop */
$log_msg = "Stop Generate and Send SQL";
$log->info($log_msg);
_echo($log_msg,1);
?>