<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;
use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\VeloxException;

/**
 * `PreparedStatement` is a subclass of `Query` that allows for parameterized prepared statements. The SQL syntax for these
 * statements follows the MySQL syntax for prepared statements, which is the same as the syntax used by PDO. Either
 * named or positional parameters may be used. The values for the parameters are passed in as an associative array
 * to the `addParameterSet()` method; several calls to this method may be made to add multiple sets of parameters, each
 * of which will be bound and executed in sequence when the `execute()` method is called.
 */

class PreparedStatement extends Query {
    private array $_namedParams = [];
    private int $_paramCount = 0;
    private array $_paramArray = [];
    private ?string $setId = null;
    
    public function __construct(Connection &$conn, string $sql, int $queryType = Query::QUERY_SELECT, int $resultType = Query::RESULT_DISTINCT, ?string $setId = null) {
        parent::__construct($conn,$sql,$queryType,$resultType);
        $paramMatch = [];
        if (preg_match_all("/:[A-Za-z0-9_]+/",$sql,$paramMatch) > 0){
            $this->_namedParams = $paramMatch[0];
            $this->_paramCount = count($this->_namedParams);
        }
        else {
            $this->_paramCount = preg_match_all("/\?/",$sql);
        }
        $this->setId = $setId;
    }
    public function __clone() : void {
        parent::__clone();
        $this->clear();
    }
    public function addParameterSet(array $paramArray, string $prefix = '') : void {
        foreach ($paramArray as $key => $value){
            if (!is_scalar($value) && !is_null($value)){
                throw new VeloxException("Value for :".$key." is not a scalar or null.",50);
            }
            $paramArray[":".$prefix.$key] = $value;
            unset($paramArray[$key]);
        }
        $this->_paramArray[] = $paramArray;
    }
    public function getNamedParams() : array {
        return $this->_namedParams;
    }
    public function getParams() : array {
        return $this->_paramArray;
    }
    public function getParamCount() : int {
        return $this->_paramCount;
    }
    public function getSetCount() : int {
        return $this->_setCount;
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
