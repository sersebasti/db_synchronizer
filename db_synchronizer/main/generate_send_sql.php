<?php

/**
 * File Name: index.php
 * Author: Sergio Sebastiani
 * Date: 2024-06-10
 * Description: see readme.txt
 */

/* Set configuration */


require_once __DIR__ . '/../vendor/autoload.php';




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
$log_filename = $conf['paths']['log_filename'];

$period_min =  $conf['period_min'];

/* Start */
$log_msg = "Start Generate and Send SQL";
wlog($log_msg, $log_filename, 1);

// Connect to your database

try {
    $pdo = new PDO('mysql:host='.$servername.';dbname='.$db, $username,$password);
    $log_msg = "Connected to server: " .$servername. " database:" . $db;
    wlog($log_msg, $log_filename, 1);
    

} catch (PDOException $e) {
    wlog("Error connection to server: " .$servername. " database:" .$db. " error: ".$e->getMessage(), $log_filename, 2);
}


// Output changes to an SQL script
$sql_file = fopen('./'.$sql_filename, 'w');

// Get the current timestamp
$endTime = date("Y-m-d H:i:s");

// Check if there is a saved end time from the previous execution
if (file_exists('./'.$start_time_filename)) {
    $startTime = trim(file_get_contents('./'.$start_time_filename));
} else {

    // Calculate the timestamp for minutes ago
    $MinutesAgoTimestamp  = strtotime('-'.$period_min.' minutes');

    // Format the timestamp as 'Y-m-d H:i:s'
    $startTime = date("Y-m-d H:i:s", $MinutesAgoTimestamp );

    wlog("Start Time not found - set to: " . $startTime, $log_filename, 1);
}



/*************************************************************************/ 
// Generate SQL statements for insert updated records
/*************************************************************************/

$log_msg = "Start searching records new or modified records";
wlog($log_msg, $log_filename, 1);


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
wlog($log_msg, $log_filename, 1);

$log_msg = "Found: " . count($newRecords) . " records new or modified";
wlog($log_msg, $log_filename, 1);


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
wlog($log_msg, $log_filename, 1);



/*************************************************************************/
// Generate SQL statements for deleted records
/*************************************************************************/

$log_msg = "Start searching deleted records";
wlog($log_msg, $log_filename, 1);


// Get primary Key
if(!isset($key) || strlen($key) == 0){


    $sql = "SELECT k.COLUMN_NAME FROM information_schema.table_constraints t 
    JOIN information_schema.key_column_usage k USING(constraint_name, table_schema, table_name) 
    WHERE t.constraint_type = 'PRIMARY KEY' AND t.table_schema = DATABASE() AND t.table_name = '".$table."';";
    $query = $pdo->prepare($sql);
    $query->execute();
    $result = $query->fetchAll(PDO::FETCH_ASSOC);

    $log_msg = "Executed query: " . $sql;
    wlog($log_msg, $log_filename, 1);
    
    if(count($result) == 1){
        $key = $result['COLUMN_NAME'];
        $log_msg = "Found primary key: " . $key;
        wlog($log_msg, $log_filename, 1);
    }
    else{
        $log_msg = 'Not found primary key for taable: ' . $table;
        wlog($log_msg, $log_filename, 2);
    }
}
else{
    $log_msg = "Found primary key: " . $key;
    wlog($log_msg, $log_filename, 1);
}


// Get previous key values from snapshot_table
$sql = "SELECT ".$db.".".$snapshot_table.".".$key." FROM ".$db.".".$snapshot_table." LEFT JOIN ".$db.".".$table." ON ".$table.".".$key." = ".$db.".".$snapshot_table.".".$key." WHERE ".$table.".".$key." IS NULL";
$query = $pdo->prepare($sql);
$query->execute();
$deletedRecords = $query->fetchAll(PDO::FETCH_ASSOC);

$log_msg = "Executed query: " . $sql;
wlog($log_msg, $log_filename, 1);

$log_msg = "Found: " . count($deletedRecords) . " deleted records";
wlog($log_msg, $log_filename, 1);

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
wlog($log_msg, $log_filename, 1);

$sql = "INSERT INTO ".$db.".".$snapshot_table."(".$key.") SELECT ".$key." FROM ".$db.".".$table;
$query = $pdo->prepare($sql);
$query->execute();

$log_msg = "Executed query: " . $sql;
wlog($log_msg, $log_filename, 1);


$log_msg = "Stop searching deleted records";
wlog($log_msg, $log_filename, 1);


// Close connection to dB
$pdo = null;
$log_msg = "Closed connection with dB";
wlog($log_msg, $log_filename, 1);

// Save the end time for the next execution
file_put_contents('./'.$start_time_filename, $endTime);

$log_msg = "Saved last execution time: " . $endTime . " in ". $start_time_filename;
wlog($log_msg, $log_filename, 1);


// Check if the file is empty
if (filesize($sql_filename) === 0) {
    $log_msg = "No changes";
    wlog($log_msg, $log_filename, 1);

    $log_msg = "Stop Generate SQL";
    wlog($log_msg, $log_filename, 1);
}
else{

    $log_msg = "Start sending post request to remote server at: " . $url;
    wlog($log_msg, $log_filename, 1);


    $newFileName = $conf['paths']['sql_dir']."/".$startTime. "_" . $endTime . "_" . $conf['paths']['sql_filename'];
    $newFileName = str_replace(' ', '', $newFileName);
    $newFileName = str_replace('-', '', $newFileName);
    $newFileName = str_replace(':', '', $newFileName);

    $log_msg = "Changes Update/Insert: " . count($newRecords);
    wlog($log_msg, $log_filename, 1);

    $log_msg = "Changes Delete: " . count($deletedRecords);
    wlog($log_msg, $log_filename, 1);

    rename(__DIR__ . '/' . $sql_filename, __DIR__ . '/' .$newFileName);
    $log_msg = "renamed: " . __DIR__ . '/' . $sql_filename . ' into: ' . __DIR__ . '/' .$newFileName;
    wlog($log_msg, $log_filename, 1);

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
        wlog($log_msg, $log_filename, 1);
        //throw new Exception('cURL error: ' . $curlError . ' (errno: ' . $curlErrno . ')');
    } else {
        // Print the response from the server
        $log_msg = "Response from server - start:";
        wlog($log_msg, $log_filename, 1);

        $log_msg = $response;
        wlog($log_msg, $log_filename, 1);

        $log_msg = "Response from server - stop";
        wlog($log_msg, $log_filename, 1);
    }


    // Close the cURL session
    curl_close($ch);

    
    $log_msg = "Stop sending post request to remote server";
    wlog($log_msg, $log_filename, 1);
}


/* Stop */
$log_msg = "Stop Generate and Send SQL";
wlog($log_msg, $log_filename, 1);


function wlog($log_msg, $log_filename, $echo){
    file_put_contents("./".$log_filename, date("Y-m-d H:i:s") . ': '.$log_msg.PHP_EOL,FILE_APPEND );
    if($echo == 1){echo $log_msg.'<br>';}
    elseif($echo == 2){die($log_msg.'<br>');}
}

?>