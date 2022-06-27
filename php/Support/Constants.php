<?php
const VELOX_ERR_NONE = 0;
const VELOX_ERR_STDERR = 1;
const VELOX_ERR_JSONOUT = 2;
const VELOX_ERR_STACKTRACE = 4;

const DB_MYSQL = 0;
const DB_MSSQL = 1;

const VELOX_RESULT_NONE = 0;
const VELOX_RESULT_ARRAY = 1;
const VELOX_RESULT_UNION = 2;
const VELOX_RESULT_UNION_ALL = 3;
const VELOX_RESULT_FIELDS = 4;

const TO_BROWSER = 1;
const TO_FILE = 2;
const TO_OBJECT = 4;
const TO_STDOUT = 8;
const TO_STRING = 16;

const AS_JSON = 32;
const AS_XML = 64;
const AS_HTML = 128;
const AS_CSV = 256;

const QUERY_SELECT = 1;
const QUERY_UPDATE = 2;
const QUERY_INSERT = 3;
const QUERY_DELETE = 4;
const QUERY_PROC = 5;

const VELOX_SUPPORTED_OPERATORS = [
    "=",">","<",">=","<=","<>","BETWEEN","IN","LIKE","NOT BETWEEN","NOT IN","NOT LIKE"
];

if(!defined('STDIN'))	{ define('STDIN',  fopen('php://stdin',  'rb')); }
if(!defined('STDOUT'))	{ define('STDOUT', fopen('php://stdout', 'wb')); }
if(!defined('STDERR'))	{ define('STDERR', fopen('php://stderr', 'wb')); }
if(!isset($GLOBALS['Velox'])) { $GLOBALS['Velox'] = []; }

//Determine mbstring support at runtime
define('MBSTRING_SUPPORT', extension_loaded('mbstring'));
