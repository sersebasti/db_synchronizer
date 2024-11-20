<?php
$file_result = "";
$file = $_FILES['file'];

//check whether main upload folder the exists and/or create
$uploaddir = getcwd() . "/upload"."/";
if(!(file_exists($uploaddir))){
  mkdir($uploaddir);
  chmod($uploaddir, 0777);
}


//check whether the file exists
if (file_exists($uploaddir. $_FILES["file"]["name"]))
{
  echo $_FILES["file"]["name"] . " already exists. ";
  unlink($uploaddir. $_FILES["file"]["name"]);
}

        if(isset($_FILES['file']['tmp_name'])) {
            if (is_uploaded_file($_FILES['file']['tmp_name'])) {

                $uploadfile = $uploaddir . basename($_FILES['file']['name']);
                echo 'File '. $_FILES['file']['name']. ' uploaded successfully';
                if ( move_uploaded_file($_FILES['file']['tmp_name'], $uploadfile) ) {
                    echo "File is valid, and was successfully moved ";
                }
                else {
                    print_r($_FILES);
                }
            }
            else {
                echo "Upload Failed !!!";
            }
        }
   
?>