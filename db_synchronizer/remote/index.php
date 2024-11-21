<?php
$file = $_FILES['file'];

$conf = json_decode(file_get_contents("./conf.json"), true);

$upload_dir = $conf['paths']['upload_dir'];

//check whether main upload folder the exists and/or create
$uploaddir = getcwd()."/".$upload_dir."/";
if(!(file_exists($uploaddir))){
  mkdir($uploaddir);
  chmod($uploaddir, 0777);
}

require_once __DIR__ . '/../generic.php';

//check whether the file exists
if (file_exists($uploaddir. $_FILES["file"]["name"]) && $file !== null)
{
    $log_msg = $_FILES["file"]["name"] . " already exists. ";
    _echo($log_msg,1);

    // Wil delete adn import again file if file wirth same name allready present 
    if(unlink($uploaddir. $_FILES["file"]["name"])){
        $log_msg = $_FILES["file"]["name"] . " deleted. ";
        _echo($log_msg,1);
    }

}

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
        echo 'Upload Failed !!!';
    }
} else {
    $log_msg = 'No file data received in the request.';
    _echo($log_msg, 1);
    echo 'No file to upload!';
}



$servername = $conf['database']['server'];
$username = $conf['database']['username'];
$password = $conf['database']['password'];;

$port = $conf['database']['port'];
$db = $conf['database']['db'];

$table = $conf['database']['table'];
$key = $conf['database']['pk'];


// Connect to your database securely
try {

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Throw exceptions on errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Set default fetch mode
        PDO::ATTR_EMULATE_PREPARES   => false,                   // Disable emulated prepared statements
    ];

    $pdo = new PDO('mysql:host='.$servername.';port='.$port.';dbname='.$db, $username, $password, $options);

    $log_msg = "Connected to server: " . $servername . " database:" . $db;
    _echo($log_msg,1);

} catch (PDOException $e) {
    $log_msg = "Error connecting to the database: " . $e->getMessage();
    _echo($log_msg,1);
}



// Closed connection with dB
$pdo = null;
$log_msg = "Closed connection with " . $servername . " database:" . $db;


_echo($log_msg,1);

   
?>