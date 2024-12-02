<?php
namespace KitsuneTech\Velox\Utility;
use KitsuneTech\Velox\VeloxException as VeloxException;

function recur_ksort(&$array) {
    foreach ($array as &$value) {
        if (is_array($value)) {
            recur_ksort($value);
        }
    }
    return ksort($array);
}

function isAscii($str) {
    return preg_match('/[^\x00-\x7F]/', $str) == 0;
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

function isAssoc($array){
    return (array_values($array) !== $array);
}

function array_change_key_case_recursive($arr, $case = CASE_LOWER) {
    //from user zhangxuejiang on php.net
    return array_map(function($item) use($case) {
        if(is_array($item)) $item = array_change_key_case_recursive($item, $case);
        return $item;
    },array_change_key_case($arr, $case));
}

/**
 * Pretty self-explanatory.
 * @param int $num The integer to be checked
 * @return bool Whether this integer is a power of 2
 */
function isPowerOf2(int $num) : bool {
    return ($num != 0) && (($num & ($num-1)) == 0);
}