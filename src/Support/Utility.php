<?php
namespace KitsuneTech\Velox\Utility;
use KitsuneTech\Velox\VeloxException as VeloxException;

/**
 * Recursively sorts a multidimensional array by key, in ascending order
 *
 * As {@see ksort()}, but applied to every nested array.
 * @param $array array A multidimensional array to be sorted by key
 * @return bool The return value of the root level ksort. This will always return
 * true in PHP >=8.2.0 ({@see https://www.php.net/manual/en/function.ksort.php})
 */
function recur_ksort(array &$array) : bool {
    foreach ($array as &$value) {
        if (is_array($value)) {
            recur_ksort($value);
        }
    }
    return ksort($array);
}

/**
 * @param $str string A string to be checked
 * @return bool Whether the string consists entirely of ASCII characters
 */
function isAscii(string $str) : bool {
    return preg_match('/[^\x00-\x7F]/', $str) == 0;
}

/**
 * Performs a SQL-like comparison between two values.
 *
 * The order of parameters and the operators available are equivalent to what exists in a standard SQL comparison.
 * Thus, a comparison that can be represented in SQL as `leftValue = rightValue` would be performed by this function
 * as `sqllike_comp($leftValue,'=',$rightValue)`. All standard SQL operators are available, including LIKE/NOT LIKE
 * and RLIKE/NOT RLIKE, with equivalent behavior. Type casting and case-sensitivity are equivalent to MySQL/MariaDB.
 *
 * (note: LIKE/NOT LIKE comparisons require replacing SQL wildcards with their PCRE equivalents, which may incur some
 * overhead for iterative calls. It may be preferable to use RLIKE/NOT RLIKE with regexp syntax.)
 *
 * @param mixed $value1
 * @param string $op
 * @param mixed|null $value2
 * @return bool
 * @throws VeloxException
 */
function sqllike_comp(mixed $value1, string $op, mixed $value2) : bool {
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

/**
 * @param array $array The array to be checked
 * @return bool True if the array is associative (has non-sequential or non-numeric keys)
 */
function isAssoc(array $array) : bool {
    return (array_values($array) !== $array);
}

/**
 * Recursively sets the case on all keys in the given array.
 *
 * As {@see array_change_key_case()}, but applied to all levels of the array.
 * @param $arr
 * @param $case
 * @return array|array[]
 *
 * @author zhangxuejiang
 * @see https://www.php.net/manual/en/function.array-change-key-case.php#124285
 */

function array_change_key_case_recursive(array $arr, int $case = CASE_LOWER) : array {
    //from user zhangxuejiang on php.net
    return array_map(function($item) use($case) {
        if(is_array($item)) $item = array_change_key_case_recursive($item, $case);
        return $item;
    },array_change_key_case($arr, $case));
}

/**
 * @param int $num An integer to be checked
 * @return bool Whether this integer is a power of 2
 */
function isPowerOf2(int $num) : bool {
    return ($num != 0) && (($num & ($num-1)) == 0);
}

/**
 * Returns an array of all column names in a two-dimensional array.
 *
 * This accounts for sparse arrays by iterating through all rows and checking for the existence of unique keys, building
 * up a list as it goes. This will be slightly slower than simply running {@see https://www.php.net/manual/en/function.array-keys.php array_keys()}
 * on the first row, but it's more reliable than assuming that the first row contains an element for each column.
 *
 * @param array $arr The array whose columns are to be determined
 * @return array An array of all column names in the given array
 */
function array_all_columns(array $arr) : array {
    $columns = [];
    foreach ($arr as $row){
        foreach ($row as $column => $value){
            if (!isset($columns[$column])) $columns[$column] = null;
        }
    }
    return array_keys($columns);
}