<?php
$file = $_FILES['file'];

$conf = json_decode(file_get_contents("./conf.json"), true);


$upload_dir = $conf['paths']['upload_dir'];

// Check whether the main upload folder exists and/or create it
$uploaddir = getcwd() . "/" . $upload_dir . "/";
if (!(file_exists($uploaddir))) {
    mkdir($uploaddir);
    chmod($uploaddir, 0777);
}



//require_once __DIR__ . '/../generic.php';

// Check whether the file exists
if (file_exists($uploaddir . $_FILES["file"]["name"]) && $file !== null) {
    $log_msg = $_FILES["file"]["name"] . " already exists.";
    _echo($log_msg, 1);

    // Delete and import the file again if a file with the same name already exists
    if (unlink($uploaddir . $_FILES["file"]["name"])) {
        $log_msg = $_FILES["file"]["name"] . " deleted.";
        _echo($log_msg, 1);
    }
}

// Upload the file
if (isset($_FILES['file']['tmp_name'])) {
    if (is_uploaded_file($_FILES['file']['tmp_name'])) {
        $uploadfile = $uploaddir . basename($_FILES['file']['name']);

        if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadfile)) {
            $log_msg = 'File ' . $_FILES['file']['name'] . ' uploaded successfully.';
            _echo($log_msg, 1); // Assuming _echo is your custom logging function
        } else {
            $log_msg = 'File ' . $_FILES['file']['name'] . ' could not be imported.';
            _echo($log_msg, 1);
        }
    } else {
        $log_msg = 'No file found or file was not uploaded.';
        _echo($log_msg, 1);
    }
} else {
    $log_msg = 'No file data received in the request.';
    _echo($log_msg, 1);
}




// Database connection setup
$servername = $conf['database']['server'];
$username = $conf['database']['username'];
$password = $conf['database']['password'];
$port = $conf['database']['port'];
$db = $conf['database']['db'];

try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO('mysql:host=' . $servername . ';port=' . $port . ';dbname=' . $db, $username, $password, $options);
    $log_msg = "Connected to server: " . $servername . " database: " . $db;
    _echo($log_msg, 1);


    
    // Read the uploaded SQL file
    $sql_file_path = $uploadfile;
    if (file_exists($sql_file_path)) {
        $sql_content = file_get_contents($sql_file_path);

        // Split multiple SQL statements if necessary (assumes semicolon-separated queries)
        $statements = explode(';', $sql_content);

        foreach ($statements as $statement) {
            $statement = trim($statement); // Remove any extra whitespace
            if (!empty($statement)) {

                
                $pdo->exec($statement); // Execute the SQL statement
                $log_msg = "Executed SQL statement: " . $statement;
                
                
                //$log_msg = "SQL statement to be executed: " . $statement;
                 
                _echo($log_msg, 1);
            }
        }

        $log_msg = "All SQL statements executed successfully.";
        _echo($log_msg, 1);
    } else {
        $log_msg = "SQL file not found.";
        _echo($log_msg, 1);
    }
    

} catch (PDOException $e) {
    $log_msg = "Error executing SQL statements: " . $e->getMessage();
    _echo($log_msg, 1);
} finally {
    // Close the database connection
    $pdo = null;
    $log_msg = "Closed connection with " . $servername . " database: " . $db;
    _echo($log_msg, 1);
}
?>

<?php
function _echo($log_msg, $echo){
    //file_put_contents("./".$log_filename, date("Y-m-d H:i:s") . ': '.$log_msg.PHP_EOL,FILE_APPEND );
    if($echo == 1){echo $log_msg.'<br>';}
    elseif($echo == 2){die($log_msg.'<br>');}
}
?>
