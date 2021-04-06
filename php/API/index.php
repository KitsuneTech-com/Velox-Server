<?php

//*CORS placeholder*//

//Path to autoloader substituted by post-install script. If you are editing this file because you received the
//exception below, replace the following path with the absolute path of the autoloader file.
$autoloaderPath = '/path/to/autoloader';

if ((@include_once $autoloaderPath) === false){
    throw new Exception("Autoloader not found. If this endpoint was not installed with the Composer installer, ".
                        "or if the autoloader was moved, you will need to edit this file to correct the path.");
}

//*Custom configuration placeholder*//

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

//Generate array (try decoding JSON request first; if it's not JSON, fallback to standard POST
try {
    $post_array = json_decode(file_get_contents("php://input"),true,512,JSON_THROW_ON_ERROR);
}
catch(Exception $ex){
    $post_array = $_POST;
}

//Assign variables from array
$SELECT = $post_array['select'] ?? [];
$UPDATE = $post_array['update'] ?? [];
$INSERT = $post_array['insert'] ?? [];
$DELETE = $post_array['delete'] ?? [];
$META = $post_array['meta'] ?? [];


if ($SELECT || $UPDATE || $INSERT || $DELETE){
    $DIFF = new Diff();
    $DIFF->select = is_array($SELECT) ? $SELECT : json_decode($SELECT);
    $DIFF->update = is_array($UPDATE) ? $UPDATE : json_decode($UPDATE);
    $DIFF->insert = is_array($INSERT) ? $INSERT : json_decode($INSERT);
    $DIFF->delete = is_array($DELETE) ? $DELETE : json_decode($DELETE);
}
else {
    $DIFF = null;
}

$QUERIES = [];

//Run the specified query definition file
require_once $queryFileName;

////////-- Query definition code execution --/////////

//Allow pre-processing of data prior to query call (such as password hashing, etc.)
if (function_exists("preProcessing")){
    preProcessing($SELECT,$UPDATE,$INSERT,$DELETE);
}

if ($QUERIES['SELECT'] ?? false){
    $VELOX_MODEL = new Model($QUERIES['SELECT'], $QUERIES['UPDATE'] ?? null, $QUERIES['INSERT'] ?? null, $QUERIES['DELETE'] ?? null);
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
else {
    if (isset($QUERIES['UPDATE'])){
        $QUERIES['UPDATE']->execute();    
    }
    if (isset($QUERIES['INSERT'])){
        $QUERIES['INSERT']->execute();
    }
    if (isset($QUERIES['DELETE'])){
        $QUERIES['DELETE']->execute();
    }
}

//Run any custom post-processing code
if (function_exists("postProcessing")){
    postProcessing($VELOX_MODEL ?? null);
}

//Export JSON to browser
if (isset($VELOX_MODEL)){
    $VELOX_MODEL->export();
}
else {
    echo "{lastQuery: ".time().", columns: {}, data: {} }";
}
