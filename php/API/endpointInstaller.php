#!/usr/bin/env php
<?php
echo "Velox API endpoint post-installer";
echo "---------------------------------";
echo "Would you like to configure Velox API endpoints? (y/[n])\n";
$answer = trim(fgets(STDIN));
if ($answer == "y"){
    echo "Enter the absolute path of your website root.\n";
    $webpath = trim(fgets(STDIN));
    $finished = false;
    while (true){
	echo 'Enter the site-relative endpoint path for each endpoint you wish';
	echo 'to create, or hit Enter to finish.\n';
	$apipath = trim(fgets(STDIN));
	if ($apipath){
	    $fullpath = rtrim($webpath,"/").$apipath;
	    $result = exec("cp ".getcwd()."/API ".$fullpath);
	    if ($result){
		echo "API endpoint created at ".$webpath.".\n";
	    }
	    else {
		echo "Endpoint creation failed at ".$webpath.".\n";
	    }
	}
	echo "Endpoint creation finished.\n";
    }
}
