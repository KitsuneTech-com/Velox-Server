#!/usr/bin/env php
<?php
//Necessary utility functions
function relativePath($from, $to, $ps = DIRECTORY_SEPARATOR) : string {
    $arFrom = explode($ps, rtrim($from, $ps));
    $arTo = explode($ps, rtrim($to, $ps));
    while(count($arFrom) && count($arTo) && ($arFrom[0] == $arTo[0])){
        array_shift($arFrom);
        array_shift($arTo);
    }
    return str_pad("", count($arFrom) * 3, '..'.$ps).implode($ps, $arTo);
}

function copy_dir($src,$dst) : bool {
    $dir = opendir($src);
    if (!is_dir($dst)){
        if (!mkdir($dst)){
            return false;
        }
    }
    while( ( $file = readdir($dir)) !== false ) {
        if (( $file != '.' ) && ( $file != '..' )) {
            if ( is_dir($src . '/' . $file) ) {
                if (!copy_dir($src . '/' . $file,$dst . '/' . $file)){
                    return false;
                }
            }
            else {
                if (!copy($src . '/' . $file,$dst . '/' . $file)){
                    return false;
                }
            }
        }
    }
    closedir($dir);
    return true;
}

// ---- Execution begins here ---- //

//get current file path
$thisDir = dirname(__FILE__);

echo "Velox API endpoint post-installer\n";
echo "---------------------------------\n";
echo "Would you like to configure Velox API endpoints? (y/[n])\n";
$answer = trim(fgets(STDIN));
if ($answer == "y"){
    echo "Enter the absolute path of your website root.\n";
    $webpath = trim(fgets(STDIN));
    $finished = false;
    while (true){
        echo 'Enter the site-relative endpoint path for each endpoint you wish ';
        echo 'to create, or hit Enter to finish.\n';
        $apipath = trim(fgets(STDIN));
        if ($apipath != ""){
            $fullpath = rtrim($webpath,"/")."/".$apipath;
            echo "Copying ".$thisDir."/index.php to ".$fullpath."/index.php...\n";
            $index = copy($thisDir."/index.php",$fullpath."/index.php");
            if ($index){
                echo "API endpoint created at ".$fullpath.".\n\n";
                echo "Copying queries directory...\n";
                $queries = copy_dir($thisDir."/queries",$fullpath."/queries");
                if ($queries){
                    echo "Queries subdirectory created at ".$fullpath."/queries\n";
                }
                else {
                    echo "Failed to create queries subdirectory at ".$fullpath."/queries\n";
                }
            }
            else {
                echo "Endpoint creation failed at ".$fullpath.".\n";
            } 
        }
        else {
            break;
        }
    }
    echo "Endpoint creation finished.\n";
}
