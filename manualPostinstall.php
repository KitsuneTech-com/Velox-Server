#!/usr/bin/env php
<?php
//Composer seems to have an issue with running interactive post-install scripts, so this is
//an alternate means of accomplishing the same. Use the php binary to run this file after
//Composer has installed the library.

echo "Velox web component installation\n";
echo "--------------------------------\n";
echo "Enter the full absolute path of your website root, or hit Enter to cancel installation.\n";
$webpath = trim(fgets(STDIN));
if ($webpath == ""){
    echo "Web component installation cancelled.";
    exit(0);
}
echo "Would you like to configure API endpoint(s)? [y/n] (n)\n";
$answer = trim(fgets(STDIN));
if ($answer == "y") {
    include_once('php/API/endpointInstaller.php');
}
