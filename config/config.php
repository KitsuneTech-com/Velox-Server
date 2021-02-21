<?php
$GLOBALS['VeloxRootDir'] = '/srv/www/htdocs/velox';

use KitsuneTech\Velox;

Velox\veloxErrorReporting();

$GLOBALS['VeloxConnections'] = [];				    //This array stores all used database connections
$GLOBALS['VeloxQueryPath'] = getenv('APP_ROOT_PATH').'/queries/';   //This specifies the path where Velox query files are stored
$GLOBALS['Velox']['ErrorReporting'] = VELOX_ERR_JSONOUT+VELOX_ERR_STACKTRACE;

$GLOBALS['VeloxConnections']['NIS'] = new Velox\Connection('localhost','npc-rev2', 'web', 'webserver');


//This file contains server-specific information to be used by the server-side library. Change this as necessary
//to fit your configuration.

require_once '../php/constants.php';
require_once '../php/errorReporting.php';
require_once '../php/connection.php';

use KitsuneTech\Velox;

$GLOBALS['Velox'] = [];

//The root directory of the site in which Velox is being used (one directory below this one)
$GLOBALS['Velox']['SiteRoot'] = getcwd()."/..";

//The full path of the directory in which the API root is stored (must be at or above the site root)
$GLOBALS['Velox']['VeloxRoot'] = $GLOBALS['Velox']['SiteRoot'].'/velox';

//The full path where the query definitions are stored (
$GLOBALS['Velox']['QueryDefPath'] = $GLOBALS['Velox']['SiteRoot'].'/queries';

//The mode and level of error reporting (constants defined in php/constants.php)
$GLOBALS['Velox']['ErrorReportingMode'] = VELOX_ERR_JSONOUT+VELOX_ERR_STACKTRACE;