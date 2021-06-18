<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;
use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\Database\Procedures\Query as Query;
use KitsuneTech\Velox\VeloxException;

class PreparedStatement extends Query {
    private array $_namedParams = [];
    private int $_paramCount = 0;
    private array $_paramArray = [];
    private int $_setCount = 0;
    
    public function __construct(Connection &$conn, string $sql, ?int $queryType = null, int $resultType = VELOX_RESULT_UNION, private ?string $setId = null) {
        parent::__construct($conn,$sql,$queryType,$resultType);
        $paramMatch = [];
        if (preg_match_all("/:[A-Za-z0-9_]+/",$sql,$paramMatch) > 0){
            $this->_namedParams = $paramMatch[0];
            $this->_paramCount = count($this->_namedParams);
        }
        else {
            $this->_paramCount = preg_match_all("/\?/",$sql);
        }
    }
    public function __clone() : void {
        parent::__clone();
        $this->clear();
    }
    public function addParameterSet(array $paramArray, string $prefix = '') : int {
        foreach ($paramArray as $key => $value){
            if (!is_array($value)){
                $paramArray[":".$prefix.$key] = $value;
            }
            unset($paramArray[$key]);
        }
        $this->_paramArray[] = $paramArray;
        $this->_setCount++;
        //return the index of the inserted parameter set
        return $this->_setCount - 1;
    }
    public function getNamedParams() : array {
        return $this->_namedParams;
    }
    public function &getParams() : array {
        return $this->_paramArray;
    }
    public function getParamCount() : int {
        return $this->_paramCount;
    }
    public function clear() : void {
        $this->_paramArray = [];
        $this->_setCount = 0;
    }
    public function dumpQuery(): array {
        $dump = parent::dumpQuery();
        $dump["type"] = "PreparedStatement";
        $dump["parameters"] = $this->_paramArray;
        return $dump;
    }
    public function getSetId() : ?string {
        return $this->setId;
    }
}
