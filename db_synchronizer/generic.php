<?php
function _echo($log_msg, $echo){
    //file_put_contents("./".$log_filename, date("Y-m-d H:i:s") . ': '.$log_msg.PHP_EOL,FILE_APPEND );
    if($echo == 1){echo $log_msg.'<br>';}
    elseif($echo == 2){die($log_msg.'<br>');}
}
?>