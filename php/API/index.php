<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit(); } //Return CORS preflight headers

//Normal requests start here

require_once 'config/config.php';
require_once 'php/errorReporting.php';
require_once 'php/core.php';

use KitsuneTech\Velox;

//The endpoint uses the 'q' GET parameter to find the appropriate query definition, so this parameter must be sent on the request.
if (!isset($_GET['q'])){
    throw new Velox\VeloxException("No query name specified. Name must be specified as a '?q=' GET parameter on the request.",1);
}
else {
    $queryName = $_GET['q'];
}

$queryFileName = $GLOBALS['VeloxQueryPath'].$queryName.".php";

//Make sure the query definition file exists in the defined location.
if (!file_exists($queryFileName)){
    throw new Velox\VeloxException('Query definition file does not exist for query "'.$queryName.'"',2);
}

$SELECT = $_POST['select'] ?? null;
$UPDATE = $_POST['update'] ?? null;
$INSERT = $_POST['insert'] ?? null;
$DELETE = $_POST['delete'] ?? null;
$META = $_POST['meta'] ?? null;

if ($SELECT || $UPDATE || $INSERT || $DELETE){
    $DIFF = new Velox\Diff();
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
    $VELOX_MODEL = new Velox\Model($QUERIES['SELECT'] ?? null, $QUERIES['UPDATE'] ?? null, $QUERIES['INSERT'] ?? null, $QUERIES['DELETE'] ?? null);
    if ($DIFF){
	$VELOX_MODEL->synchronize($DIFF);
    }
    //Set the query version header (if it's set as a positive integer)
    if ($QUERY_VERSION ?? false){
	if (!is_int($QUERY_VERSION)){
	    throw new Velox\VeloxException('Incorrect version value set in query definition (query "'.$queryName.'"',4);
	}
    }
}

//Run any custom post-processing code
if (function_exists("postProcessing")){
    postProcessing($VELOX_MODEL);
}
