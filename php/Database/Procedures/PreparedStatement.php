<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;
use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\VeloxException;

class PreparedStatement extends Query {
    private array $_namedParams = [];
    private int $_paramCount = 0;
    private array $_paramArray = [];
    
    public function __construct(Connection &$conn, string $sql, int $queryType = QUERY_SELECT, int $resultType = VELOX_RESULT_UNION, private ?string $setId = null) {
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
    public function addParameterSet(array $paramArray, string $prefix = '') : void {
        foreach ($paramArray as $key => $value){
            $paramArray[":".$prefix.$key] = $value;
            unset($paramArray[$key]);
        }
        $this->_paramArray[] = $paramArray;
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
