<?php 



// Execute git command to get the commit hash
session_start();
echo $_SERVER['HTTP_HOST'];
echo "<hr>";



$directory = $_SERVER['DOCUMENT_ROOT'].'/';
if (chdir($directory)) {
    $commitHash_api = exec("git --git-dir=".$directory."/.git --work-tree=".$directory." rev-parse HEAD");
    $tagName_api = exec("git --git-dir=".$directory."/.git --work-tree=".$directory." describe --tags --exact-match");
} else {
    // Directory change failed
    echo "Failed to change directory to ". $directory;
}


$arr_api = explode('.', $tagName_api);

echo "API Version: " .  $tagName_api . '<br>';


// Read the JSON file - versions  
//$versions = json_decode(file_get_contents('versions.json'),true); 
echo "<hr>";
echo "The time is " . date("h:i:sa")."<br>";
?>