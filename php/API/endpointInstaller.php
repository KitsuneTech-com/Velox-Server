#!/usr/bin/env php
<?php
//Necessary utility functions
function relativePath(string $from, string $to, string $ps = DIRECTORY_SEPARATOR) : string {
    $arFrom = explode($ps, rtrim($from, $ps));
    $arTo = explode($ps, rtrim($to, $ps));
    while(count($arFrom) && count($arTo) && ($arFrom[0] == $arTo[0])){
        array_shift($arFrom);
        array_shift($arTo);
    }
    return str_pad("", count($arFrom) * 3, '..'.$ps).implode($ps, $arTo);
}

function confirmation(string $message, array $options, ?string $default = null) : string {
    echo $message." [".implode("/",$options)."]".($default ? " (".$default.")" : "")."\n";
    $response = trim(fgets(STDIN));
    if (in_array($response,$options)){
        return $response;
    }
    elseif ($default && !$response){
        return $default;
    }
    else {
        echo "Invalid response.\n";
        return confirmation($message, $options);
    }
}

function copy_dir(string $src, string $dst, bool $overwritePrompt = true) : bool {
    $dir = opendir($src);
    if (!is_dir($dst)){
        echo "Creating directory ".$dst."...\n";
        if (!mkdir($dst)){
            echo "Failed to create directory.\n";
            return false;
        }
    }
    else {
        echo "Directory ".$dst." exists. Copying contents into existing directory...\n";
    }
    while( ( $file = readdir($dir)) !== false ) {
        $sourceFile = $src."/".$file;
        $destFile = $dst."/".$file;
        if (( $file != '.' ) && ( $file != '..' )) {
            if ( is_dir($sourceFile) ) {
                if (!copy_dir($sourceFile,$destFile,$overwritePrompt)){
                    return false;
                }
            }
            else {
                if (file_exists($destFile) && $overwritePrompt){
                    $write = confirmation($destFile." already exists. Overwrite?",["y","n"],"n") == "y";
                }
                else {
                    $write = true;
                }
                if ($write){
                    echo "Copying ".$sourceFile." to ".$destFile."...\n";
                    if (!copy($sourceFile,$destFile)){
                        echo "Failed to copy.\n";
                        return false;
                    }
                }
            }
        }
    }
    closedir($dir);
    return true;
}
$sep = DIRECTORY_SEPARATOR;

// ---- Execution begins here ---- //
echo "\n";
echo "Velox API endpoint installation\n";
echo "-------------------------------\n";
if (!isset($webpath)){
    echo "Enter the full absolute path of your website root.\n";
    $webpath = trim(fgets(STDIN));
}
$keepSettings = null;
$firstRun = true;
$configFile = '';
while (true){
    echo "Enter the site-relative endpoint path for each endpoint you wish ";
    echo "to create, or hit Enter to finish.\n";
    $apiPath = trim(fgets(STDIN));
        
    if ($apiPath != ""){
        if (!$firstRun && is_null($keepSettings)){
            $keepSettings = confirmation("Would you like to reuse the settings you specified on the first endpoint for all further endpoints?",["y","n"],"n") == "y";
        }
        $cors = $keepSettings ? $cors : confirmation("Do you want to enable CORS on this endpoint? (required for access from external domains)",["y","n"],"y") == "y";
        $inc = $keepSettings ? $inc : confirmation("Do you have a custom configuration file you would like to require?",["y","n"],"n") == "y";
        if (!$keepSettings && $inc){
            echo "Enter the full path of the configuration file, or hit Enter to skip. (Note: PHP must be able to read the file.)\n";
            $configFile = trim(fgets(STDIN));
        }
        $fullPath = rtrim($webpath,$sep).$sep.$apiPath;
        $directory = true;
        if (!is_dir($fullPath)){
            echo "Directory ".$fullPath." does not exist. Creating...\n";
            if (!mkdir($fullPath)){
                $directory = false;
                echo "Error: Could not create directory ".$fullPath.".\n\n";
            }
        }
        if ($directory){
            $endpointPath = $fullPath.$sep."index.php";
            $queriesPath = $fullPath.$sep."queries";
            echo "Copying ".__DIR__.$sep."index.php to ".$endpointPath."...\n";
            $index = copy(__DIR__.$sep."index.php",$endpointPath);
            if ($index){
                echo "API endpoint created at ".$fullPath.".\n";
                $endpointFile = file_get_contents($endpointPath);
                
                //---- Endpoint code modification ----//
                if ($cors){
                    echo "Setting CORS headers...\n";
                    $headers = <<<'CODE'
                    header("Access-Control-Allow-Origin: *");
                    header("Access-Control-Allow-Methods: GET, POST");
                    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type");

                    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit(); } //Return CORS preflight headers
                    CODE;
                    $endpointFile = str_replace('//*CORS placeholder*//',$headers,$endpointFile);
                }
                if ($inc && $configFile){
                    echo "Adding reference to custom configuration file...\n";
                    $configCode = "require_once ('$configFile');";
                    $endpointFile = str_replace('//*Custom configuration placeholder*//',$configCode,$endpointFile);
                }
                echo "Setting relative path to autoloader...\n";
                $relPath = relativePath($fullPath,realpath(__DIR__."$sep..$sep..$sep..$sep..$sep")).$sep."autoload.php";
                $endpointFile = str_replace('/path/to/autoloader',$relPath,$endpointFile);
                
                echo "Saving modifications...\n";
                file_put_contents($endpointPath,$endpointFile);
                
                //---- Copying queries directory to endpoint path ----//
                echo "Copying queries directory...\n";
                $queries = copy_dir(__DIR__.$sep."queries",$queriesPath,true);
                if ($queries){
                    echo "Queries subdirectory created at ".$queriesPath."\n\n";
                }
                else {
                    echo "Error: Could not create queries subdirectory at ".$queriesPath."\n\n";
                }
                $firstRun = false;
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
