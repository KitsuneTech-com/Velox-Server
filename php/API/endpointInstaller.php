#!/usr/bin/env php
<?php
function relativePath($from, $to, $ps = DIRECTORY_SEPARATOR){
	$arFrom = explode($ps, rtrim($from, $ps));
	$arTo = explode($ps, rtrim($to, $ps));
	while(count($arFrom) && count($arTo) && ($arFrom[0] == $arTo[0])){
    	array_shift($arFrom);
    	array_shift($arTo);
  	}
	return str_pad("", count($arFrom) * 3, '..'.$ps).implode($ps, $arTo);
}

//Command flow

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
			$index = exec("cp ".getcwd()."/index.php ".$fullpath);
			$queries = exec("cp -r ".getcwd()."/queries ".$fullpath."/queries");
			if ($index){
				echo "API endpoint created at ".$fullpath.".\n";
				$queries = exec("cp -r ".getcwd()."/queries ".$fullpath."/queries");
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
