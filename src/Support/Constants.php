<?php
const VELOX_ERR_NONE = 0;
const VELOX_ERR_STDERR = 1;
const VELOX_ERR_JSONOUT = 2;
const VELOX_ERR_STACKTRACE = 4;

const TO_BROWSER = 1;
const TO_FILE = 2;
const TO_STRING = 4;
const TO_STDOUT = 8;

const AS_JSON = 16;
const AS_XML = 32;
const AS_HTML = 64;
const AS_CSV = 128;

const VELOX_SUPPORTED_OPERATORS = [
    "=",">","<",">=","<=","<>","BETWEEN","IN","LIKE","NOT BETWEEN","NOT IN","NOT LIKE"
];

if(!defined('STDIN'))	{ define('STDIN',  fopen('php://stdin',  'rb')); }
if(!defined('STDOUT'))	{ define('STDOUT', fopen('php://stdout', 'wb')); }
if(!defined('STDERR'))	{ define('STDERR', fopen('php://stderr', 'wb')); }
if(!isset($GLOBALS['Velox'])) { $GLOBALS['Velox'] = []; }

//Determine mbstring support at runtime
define('MBSTRING_SUPPORT', extension_loaded('mbstring'));
