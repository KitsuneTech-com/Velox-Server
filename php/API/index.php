<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit(); } //Return CORS preflight headers

//Path to autoloader substituted by post-install script. If you are editing this file because you received the
//exception below, replace the following path with the absolute path of the autoloader file.
$autoloaderPath = '/path/to/autoloader';

if ((@include_once $autoloaderPath) === false){
    throw new Exception("Autoloader not found. If this endpoint was not installed with the Composer installer, ".
                        "or if the autoloader was moved, you will need to edit this file to correct the path.");
}

use KitsuneTech\Velox\VeloxException as VeloxException;
use KitsuneTech\Velox\Structures\{Model, Diff};

//The endpoint uses the 'q' GET parameter to find the appropriate query definition, so this parameter must be sent on the request.
if (!isset($_GET['q'])){
    throw new VeloxException("No query name specified. Name must be specified as a '?q=' GET parameter on the request.",1);
}
else {
    $queryName = $_GET['q'];
}

$queryFileName = __DIR__."/queries/".$queryName.".php";

//Make sure the query definition file exists in the defined location.
if (!file_exists($queryFileName)){
    throw new VeloxException('Query definition file does not exist for query "'.$queryName.'"',2);
}

$SELECT = $_POST['select'] ?? null;
$UPDATE = $_POST['update'] ?? null;
$INSERT = $_POST['insert'] ?? null;
$DELETE = $_POST['delete'] ?? null;
$META = $_POST['meta'] ?? null;

if ($SELECT || $UPDATE || $INSERT || $DELETE){
    $DIFF = new Diff();
    $DIFF->select = json_decode($SELECT);
    $DIFF->update = json_decode($UPDATE);
    $DIFF->insert = json_decode($INSERT);
    $DIFF->delete = json_decode($DELETE);
}
else {
    $DIFF = null;
}

$QUERIES = [];

//Run the specified query definition file
require_once $queryFileName;

////////-- Query definition code execution --/////////

if (isset($QUERIES['SELECT'])){
    $VELOX_MODEL = new Model($QUERIES['SELECT'] ?? null, $QUERIES['UPDATE'] ?? null, $QUERIES['INSERT'] ?? null, $QUERIES['DELETE'] ?? null);
    if ($DIFF){
	$VELOX_MODEL->synchronize($DIFF);
    }
    //Set the query version header (if it's set as a positive integer)
    if ($QUERY_VERSION ?? false){
	if (!is_int($QUERY_VERSION)){
	    throw new VeloxException('Incorrect version value set in query definition (query "'.$queryName.'"',4);
	}
    }
}

//Run any custom post-processing code
if (function_exists("postProcessing")){
    postProcessing($VELOX_MODEL);
}

//Export JSON to browser
$VELOX_MODEL->export();
