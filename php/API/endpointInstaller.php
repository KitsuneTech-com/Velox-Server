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

echo "Velox API endpoint post-installer\n";
echo "---------------------------------\n";
echo "Would you like to configure Velox API endpoints? [y/n] (n)\n";
$answer = trim(fgets(STDIN));
if ($answer == "y"){
    echo "Enter the full absolute path of your website root.\n";
    $webpath = trim(fgets(STDIN));
    $finished = false;
    while (true){
        echo "Enter the site-relative endpoint path for each endpoint you wish ";
        echo "to create, or hit Enter to finish.\n";
        $apiPath = trim(fgets(STDIN));
        if ($apiPath != ""){
            echo "\n";
            $fullPath = rtrim($webpath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$apiPath;
            $directory = false;
            if (!is_dir($fullPath)){
                echo "Directory ".$fullPath." does not exist. Creating...\n";
                if (!mkdir($fullPath)){
                    echo "Error: Could not create directory ".$fullPath.".\n\n";
                }
                else {
                    $directory = true;
                }
            }
            if ($directory){
                $endpointPath = $fullPath.DIRECTORY_SEPARATOR."index.php";
                $queriesPath = $fullPath.DIRECTORY_SEPARATOR."queries";
                echo "Copying ".__DIR__.DIRECTORY_SEPARATOR."index.php to ".$endpointPath."...\n";
                $index = copy(__DIR__.DIRECTORY_SEPARATOR."index.php",$endpointPath);
                if ($index){
                    echo "API endpoint created at ".$fullPath.".\n";
                    
                    echo "Setting relative path to autoloader...\n";
                    $relPath = relativePath($fullPath,__DIR__."/../../../../").";
                    $endpointFile = file_get_contents($endpointPath);
                    $endpointFile = str_replace('/path/to/autoloader',$relPath,$endpointFile);
                    file_put_contents($endpointPath,$endpointFile);
                    
                    echo "Copying queries directory...\n";
                    $queries = copy_dir(__DIR__.DIRECTORY_SEPARATOR."queries",$queriesPath);
                    if ($queries){
                        echo "Queries subdirectory created at ".$queriesPath."\n\n";
                    }
                    else {
                        echo "Error: Could not create queries subdirectory at ".$queriesPath."\n\n";
                    }
                }
                else {
                    echo "Error: could not create API endpoint at ".$endpointPath.".\n\n";
                }
            }
        }
        else {
            break;
        }
    }
    echo "Endpoint creation finished.\n";
}
