<?php
//This file contains server-specific information to be used by the server-side library. Change this as necessary
//to fit your configuration.
require_once '../php/errorReporting.php';
use KitsuneTech\Velox;

$GLOBALS['Velox'] = [];

//The root directory of the site in which Velox is being used (one directory below this one)
$GLOBALS['Velox']['SiteRoot'] = getcwd()."/..";

//The full path of the directory in which the API root is stored (must be at or above the site root)
$GLOBALS['Velox']['VeloxRoot'] = $GLOBALS['Velox']['SiteRoot'].'/velox';

//The full path where the query definitions are stored (this does not necessarily have to be at
//or above the site root, but must be accessible to PHP)
$GLOBALS['Velox']['QueryDefPath'] = $GLOBALS['Velox']['SiteRoot'].'/queries';

//The mode and level of error reporting (constants defined in php/constants.php)
$GLOBALS['Velox']['ErrorReportingMode'] = VELOX_ERR_JSONOUT+VELOX_ERR_STACKTRACE;

//
Velox\veloxErrorReporting($GLOBALS['Velox']['ErrorReportingMode']);

//Database connections can be generated and stored in this array, to avoid hard-coding
//connection details into query definitions
$GLOBALS['Velox']['Connections'] = [];
$GLOBALS['Velox']['Connections']['my-database'] = 





$GLOBALS['VeloxConnections'] = [];				    //This array stores all used database connections
$GLOBALS['VeloxConnections']['NIS'] = new Velox\Connection('localhost','npc-rev2', 'web', 'webserver');
