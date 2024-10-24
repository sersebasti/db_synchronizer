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
$snapshot_table = $conf['database']['snapshot_table'];

$key = $conf['database']['pk'];
$timestamp_col = $conf['database']['timestamp_col'];

$sql_dir = $conf['paths']['sql_dir'];
$sql_filename = $conf['paths']['sql_dir']."/".$conf['paths']['sql_filename'];
$start_time_filename = $conf['paths']['start_time_filename'];
$logs_folder = $conf['paths']['logs_folder'];
$log_filename = $conf['paths']['log_filename'];

$period_min =  $conf['period_min'];

$transfer_method_post_state = $conf['transfer_method']['post']['active'];
$url = $conf['transfer_method']['post']['url'];

require_once __DIR__ . '/../vendor/autoload.php';


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


$handler = new RotatingFileHandler($logFile, 12, Logger::DEBUG);

// Set filename format for daily rotation
$handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');

// Add the handler to the logger
$log->pushHandler($handler);


// Example log message
$log->info('This is a log entry.');


$log->info("Start Generate and Send SQL");
$log->info('Conf: ', $conf);


// Connect to your database securely
try {
    // Set up the PDO options for safer connection
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Throw exceptions on errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Set default fetch mode
        PDO::ATTR_EMULATE_PREPARES   => false,                   // Disable emulated prepared statements
    ];

    // Establish the database connection
    $pdo = new PDO('mysql:host='.$servername.';port='.$port.';dbname='.$db, $username, $password, $options);

    // Log the successful connection (do not expose sensitive details in production)
    $log_msg = "Connected to server: " . $servername . " database:" . $db;
    $log->info($log_msg);
    _echo($log_msg,1);

} catch (PDOException $e) {
    // Log a generic error message in production (avoid exposing sensitive information)
    $error_message = "Error connecting to the database: " . $e->getMessage();
    $log->error($error_message);

    // Output the error message directly on the webpage
    _echo($log_msg,2);

    // Optionally, you could log the specific message in development mode:
    // $error_message = "Error connecting to server: " .$servername. " database: " . $db . " error: " . $e->getMessage()

    // Consider re-throwing the exception or gracefully handling the error
    // throw new Exception('Database connection failed');
}




// Output changes to an SQL script
$sql_file = fopen('./'.$sql_filename, 'w');

// Get the current timestamp
$endTime = date("Y-m-d H:i:s");

// Check if there is a saved end time from the previous execution
if (file_exists('./'.$start_time_filename)) {
    $startTime = trim(file_get_contents('./'.$start_time_filename));
    $log->info("Start Time set to: " . $startTime);
    _echo($log_msg,1);
} else {

    // Calculate the timestamp for minutes ago
    $MinutesAgoTimestamp  = strtotime('-'.$period_min.' minutes');

    // Format the timestamp as 'Y-m-d H:i:s'
    $startTime = date("Y-m-d H:i:s", $MinutesAgoTimestamp );
    
    $log->info("Start Time not found - set to: " . $startTime);
    _echo($log_msg,1);
}



/*************************************************************************/ 
// Generate SQL statements for insert updated records
/*************************************************************************/

$log_msg = "Start searching records new or modified records";
$log->info($log_msg);
_echo($log_msg,1);


// Fetch column names
$sql = "SHOW COLUMNS FROM ".$table;
$query = $pdo->prepare($sql);
$query->execute();
$columns = $query->fetchAll(PDO::FETCH_COLUMN);

// Finde new / modified records
$sql = "SELECT * FROM $table WHERE $timestamp_col > '".$startTime."' AND $timestamp_col <= '".$endTime."'";
$query = $pdo->prepare($sql);
$query->execute();
$newRecords = $query->fetchAll(PDO::FETCH_ASSOC);

$log_msg = "Executed query: " . $sql;
$log->info($log_msg);

$log_msg = "Found: " . count($newRecords) . " records new or modified";
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

$log_msg = "Stop searching records new or modified records";
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
    $result = $query->fetchAll(PDO::FETCH_ASSOC);

    $log_msg = "Executed query: " . $sql;
    $log->info($log_msg);
    
    if(count($result) == 1){
        $key = $result['COLUMN_NAME'];
        $log_msg = "Found primary key: " . $key;
        $log->info($log_msg);
    }
    else{
        $log_msg = 'Not found primary key for taable: ' . $table;
        $log->info($log_msg);
    }
}
else{
    $log_msg = "Found primary key: " . $key;
    $log->info($log_msg);
}


// Get previous key values from snapshot_table
$sql = "SELECT ".$db.".".$snapshot_table.".".$key." FROM ".$db.".".$snapshot_table." LEFT JOIN ".$db.".".$table." ON ".$table.".".$key." = ".$db.".".$snapshot_table.".".$key." WHERE ".$table.".".$key." IS NULL";
$query = $pdo->prepare($sql);
$query->execute();
$deletedRecords = $query->fetchAll(PDO::FETCH_ASSOC);

$log_msg = "Executed query: " . $sql;
$log->info($log_msg);

$log_msg = "Found: " . count($deletedRecords) . " deleted records";
$log->info($log_msg);
_echo($log_msg,1);

if (!empty($deletedRecords)) {
    foreach ($deletedRecords as $deletedRecord) {
        $deleteQuery = "DELETE FROM ".$db.".".$table." WHERE ID = " . $deletedRecord[$key];
        fwrite($sql_file, $deleteQuery . ";\n");
    }
}

fclose($sql_file);


// Create snapshot of the primary keys values
$sql = "TRUNCATE ".$db.".".$snapshot_table;
$query = $pdo->prepare($sql);
$query->execute();

$log_msg = "Executed query: " . $sql;
$log->info($log_msg);

$sql = "INSERT INTO ".$db.".".$snapshot_table."(".$key.") SELECT ".$key." FROM ".$db.".".$table;
$query = $pdo->prepare($sql);
$query->execute();

$log_msg = "Executed query: " . $sql;
$log->info($log_msg);


$log_msg = "Stop searching deleted records";
$log->info($log_msg);
_echo($log_msg,1);

// Close connection to dB
$pdo = null;
$log_msg = "Closed connection with dB";
$log->info($log_msg);
_echo($log_msg,1);

// Save the end time for the next execution
file_put_contents('./'.$start_time_filename, $endTime);

$log_msg = "Saved last execution time: " . $endTime . " in ". $start_time_filename;
$log->info($log_msg);
_echo($log_msg,1);


// Check if the file is empty
if (filesize($sql_filename) === 0) {
    $log_msg = "No changes";
    $log->info($log_msg);
    _echo($log_msg,1);

    $log_msg = "Stop Generate SQL";
    $log->info($log_msg);
}
else if ($transfer_method_post_state){

    $log_msg = "Start sending post request to remote server at: " . $url;
    $log->info($log_msg);
    _echo($log_msg,1);


    $newFileName = $conf['paths']['sql_dir']."/".$startTime. "_" . $endTime . "_" . $conf['paths']['sql_filename'];
    $newFileName = str_replace(' ', '', $newFileName);
    $newFileName = str_replace('-', '', $newFileName);
    $newFileName = str_replace(':', '', $newFileName);

    $log_msg = "Changes Update/Insert: " . count($newRecords);
    $log->info($log_msg);

    $log_msg = "Changes Delete: " . count($deletedRecords);
    $log->info($log_msg);

    rename(__DIR__ . '/' . $sql_filename, __DIR__ . '/' .$newFileName);
    $log_msg = "renamed: " . __DIR__ . '/' . $sql_filename . ' into: ' . __DIR__ . '/' .$newFileName;
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
        //throw new Exception('cURL error: ' . $curlError . ' (errno: ' . $curlErrno . ')');
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

function _echo($log_msg, $echo){
    //file_put_contents("./".$log_filename, date("Y-m-d H:i:s") . ': '.$log_msg.PHP_EOL,FILE_APPEND );
    if($echo == 1){echo $log_msg.'<br>';}
    elseif($echo == 2){die($log_msg.'<br>');}
}
?>