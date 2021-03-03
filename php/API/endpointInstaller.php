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
        echo "Creating directory ".$dst."...\n";
        if (!mkdir($dst)){
            echo "Failed to create directory.\n";
            return false;
        }
    }
    while( ( $file = readdir($dir)) !== false ) {
        $sourceFile = $src."/".$file;
        $destFile = $dst."/".$file;
        if (( $file != '.' ) && ( $file != '..' )) {
            if ( is_dir($sourceFile) ) {
                if (!copy_dir($sourceFile,$destFile)){
                    return false;
                }
            }
            else {
                echo "Copying ".$sourceFile." to ".$destFile."...\n";
                if (!copy($sourceFile,$destFile)){
                    echo "Failed to copy.\n";
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
    echo "Enter the full wwwabsolute path of your website root.\n";
    $webpath = trim(fgets(STDIN));
    $finished = false;
    while (true){
        echo "Enter the site-relative endpoint path for each endpoint you wish ";
        echo "to create, or hit Enter to finish.\n";
        $apipath = trim(fgets(STDIN));
        if ($apipath != ""){
            echo "\n";
            $fullpath = rtrim($webpath,"/")."/".$apipath;
            $directory = false;
            if (!is_dir($fullpath)){
                echo "Directory ".$fullpath." does not exist. Creating...\n";
                if (!mkdir($fullpath)){
                    echo "Error: Could not create directory ".$fullpath.".\n\n";
                }
                else {
                    $directory = true;
                }
            }
            if ($directory){
                echo "Copying ".$thisDir."/index.php to ".$fullpath."/index.php...\n";
                $index = copy($thisDir."/index.php",$fullpath."/index.php");
                if ($index){
                    echo "API endpoint created at ".$fullpath.".\n\n";
                    echo "Copying queries directory...\n";
                    $queries = copy_dir($thisDir."/queries",$fullpath."/queries");
                    if ($queries){
                        echo "Queries subdirectory created at ".$fullpath."/queries\n\n";
                    }
                    else {
                        echo "Error: Could not create queries subdirectory at ".$fullpath."/queries\n\n";
                    }
                }
                else {
                    echo "Error: could not create API endpoint at ".$fullpath."/index.php.\n\n";
                }
            }
        }
        else {
            break;
        }
    }
    echo "Endpoint creation finished.\n";
}
