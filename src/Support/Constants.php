<?php
/** @var int Velox errors suppressed */
const VELOX_ERR_NONE = 0;
/** @var int Velox errors sent to stderr */
const VELOX_ERR_STDERR = 1;
/** @var int Velox errors formatted as JSON */
const VELOX_ERR_JSONOUT = 2;
/** @var int Full stack trace included with error report */
const VELOX_ERR_STACKTRACE = 4;


/** @var int Export to browser (with necessary headers) */
const TO_BROWSER = 1;
/** @var int Export to designated file */
const TO_FILE = 2;
/** @var int Export to string (as return value) */
const TO_STRING = 4;
/** @var int Export to stdout */
const TO_STDOUT = 8;

/** @var int Format export as JSON object */
const AS_JSON = 16;
/** @var int Format export as XML document */
const AS_XML = 32;
/** @var int Format export as HTML document */
const AS_HTML = 64;
/** @var int Format export as CSV sheet */
const AS_CSV = 128;

const LEFT_JOIN = 0;
const RIGHT_JOIN = 1;
const INNER_JOIN = 2;
const FULL_JOIN = 3;
const CROSS_JOIN = 4;

const VELOX_SUPPORTED_OPERATORS = [
    "=",">","<",">=","<=","<>","BETWEEN","IN","LIKE","NOT BETWEEN","NOT IN","NOT LIKE"
];
const VELOX_SUPPORTED_JOIN_OPERATORS = [
    "=",">","<",">=","<=","<>","LIKE","NOT LIKE"
];

if(!defined('STDIN'))	{ define('STDIN',  fopen('php://stdin',  'rb')); }
if(!defined('STDOUT'))	{ define('STDOUT', fopen('php://stdout', 'wb')); }
if(!defined('STDERR'))	{ define('STDERR', fopen('php://stderr', 'wb')); }
if(!isset($GLOBALS['Velox'])) { $GLOBALS['Velox'] = []; }

//Determine mbstring support at runtime
define('MBSTRING_SUPPORT', extension_loaded('mbstring'));