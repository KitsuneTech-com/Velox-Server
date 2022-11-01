<?php
namespace KitsuneTech\Velox\Utility;
use KitsuneTech\Velox\VeloxException as VeloxException;

function deleteArrayColumn(array &$array, string $key) : void {
    foreach ($array as $row){
        unset ($row[$key]);
    }
}
function isAscii($str) {
    return preg_match('/[^\x00-\x7F]/', $str) == 0;
}
function recur_ksort(&$array) {
    foreach ($array as &$value) {
        if (is_array($value)) {
            recur_ksort($value);
        }
    }
    return ksort($array);
}
function sqllike_comp(mixed $value1, string $op, mixed $value2 = null) : bool {
    //This is based on and functionally equivalent to MySQL comparison operations.
    $v1_type = gettype($value1);
    $v2_type = gettype($value2);
    
    //Convert strings to lowercase (use strtolower() if possible due to performance)
    //(do this because SQL string comparisons are case-insensitive)
    if ($v1_type == "string") $value1 = MBSTRING_SUPPORT && !isAscii($value1) ? mb_strtolower($value1) : strtolower($value1);
    if ($v2_type == "string") $value2 = MBSTRING_SUPPORT && !isAscii($value2) ? mb_strtolower($value2) : strtolower($value2);
    
    switch ($op){
        case "=":
            if ($v1_type == "string" && $v2_type == "string"){
                //Use strict comparison with strings to avoid numeric typecasting (which MySQL does not do when comparing two strings)
                return $value1 === $value2;
            }
            else {
                return $value1 == $value2;
            }
        case "<":
            return $value1 < $value2;
        case ">":
            return $value1 > $value2;
        case "<=":
            return $value1 <= $value2;
        case ">=":
            return $value1 >= $value2;
        case "<>":
            return $value1 != $value2;
        case "LIKE":
        case "NOT LIKE":
            //Convert SQL wildcards to PCRE syntax
            //*Note: this introduces overhead that could slow processing. It's better to use RLIKE and NOT RLIKE with regexp syntax. 
            $value2 = str_replace("%",".*",$value2);
            $value2 = str_replace("_",".",$value2);
            //fall through to RLIKE / NOT RLIKE case
        case "RLIKE":
        case "NOT RLIKE":
            return (bool)preg_match('/^'.$value2.'$/',$value1);
        default:
            throw new VeloxException("Unsupported operator",36);
    }
}

