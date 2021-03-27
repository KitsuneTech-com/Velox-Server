<?php
namespace KitsuneTech\Velox\Utility;

function recur_ksort(&$array) {
    foreach ($array as &$value) {
        if (is_array($value)) {
            recur_ksort($value);
        }
    }
    return ksort($array);
}

function sqllike_comp(mixed $value1, string $op, mixed $value2, bool $exact = false) : bool {
    $v1_type = gettype($value1);
    $v2_type = gettype($value2);
    if ($exact){
        if ($v1_type != $v2_type) return false; //Mismatched types automatically fail an exact match
        switch ($op){
            case "=":
                return $value1 === $value2;
            case "<":
                return $value1 < $value2;
            case ">":
                return $value1 > $value2;
            case "<=":
                return $value1 <= $value2;
            case ">=":
                return $value1 >= $value2;
            case "<>":
                return $value1 !== $value2;
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
    else {
        //Rely on PHP type coercion if $exact is false
        switch ($op){
            case "=":
                return $value1 == $value2;
            case "<":
                return $value1 < $value2;
            case ">":
                return $value1 > $value2;
            case "<=":
                return $value1 <= $value2;
            case ">=":
                return $value1 >= $value2;
            case "<>":
                return $value1 !== $value2;
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
}
