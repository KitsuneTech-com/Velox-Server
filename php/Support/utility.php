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
