<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;

use KitsuneTech\Velox\VeloxException;
use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\Database\Procedures\{PreparedStatement, Transaction};
use KitsuneTech\Velox\Structures\{Diff, ResultSet};
use function KitsuneTech\Velox\Utility\recur_ksort;

class StatementSet implements \Countable, \Iterator, \ArrayAccess {
    private array $_statements = [];
    private int $_position = 0;
    public ResultSet|array|bool|null $results;
    public bool $optimize = true;
    
    public function __construct(public Connection &$conn, private string $_baseSql = "", public int $queryType = QUERY_SELECT, private array|Diff $_criteria = []){
        if ($this->_criteria instanceof Diff || count($this->_criteria) > 0){
            $this->addCriteria($this->_criteria);
        }
    }
    
    // Countable implementation
    public function count() : int {
        return count($this->_keys);
    }
    
    //Iterator implementation
    public function current() : PreparedStatement {
        return $this->_statements[$this->_position];
    }
    public function key() : int {
        return $this->_position;
    }
    public function next() : void {
        $this->_position++;
    }
    public function rewind() : void {
        $this->_position = 0;
    }
    public function valid() : bool {
        return isset($this->_statements[$this->_position]);
    }
    
    //ArrayAccess implementation
    public function offsetSet(mixed $offset, mixed $stmt) : void {
        if (is_null($offset)){
            $this->_statements[] = $stmt;
        }
        else {
            $this->_statements[$offset] = $stmt;
        }
    }
    public function offsetExists(mixed $offset) : bool {
        return isset($this->_statements[$offset]);
    }
    public function offsetUnset(mixed $offset) : void {
        unset($this->_statements[$offset]);
    }
    public function offsetGet(mixed $offset) : PreparedStatement {
        return $this->_statements[$offset] ?? null;
    }
    
    //Class-specific methods
    private function criterionHash(object|array $criterion) : string {
        $criterion = (array)$criterion;
        $valuesList = [];
        if (isset($criterion['values'])){
            foreach (array_keys($criterion['values']) as $key){
                $valuesList[] = $key;
            }
            $criterion['values'] = $valuesList;
        }
        if (isset($criterion['where'])){
            foreach ($criterion['where'] as $or){
                foreach ($or as $column => $condition){
                    if ($condition[0] == "IN" || $condition[0] == "NOT IN"){
                        //IN / NOT IN conditions also need to take into account unique number of elements
                        $condition[0] .= count($condition[1]);
                    }
                    $criterion['where'][$column] = $condition[0];
                }
            }
        }
        recur_ksort($criterion);
        return (string)crc32(serialize($criterion));
    }
    
    public function addCriteria (array|Diff $criteria) : void {
        if ($criteria instanceof Diff){
            switch ($this->queryType){
                case QUERY_SELECT:
                    $this->addCriteria($criteria->select);
                    break;
                case QUERY_INSERT:
                    $this->addCriteria($criteria->insert);
                    break;
                case QUERY_UPDATE:
                    $this->addCriteria($criteria->update);
                    break;
                case QUERY_DELETE:
                    $this->addCriteria($criteria->delete);
                    break;
            }
        }
        else {
            $requiredKeys = [];
            switch ($this->queryType){
                case QUERY_INSERT:
                case QUERY_UPDATE:
                    $requiredKeys[] = "values";
                    if ($this->queryType == QUERY_INSERT) break;
                case QUERY_SELECT:
                case QUERY_DELETE:
                    $requiredKeys[] = "where";
                    break;
            }
            $criteriaCount = count($criteria);
            for ($i=0; $i<$criteriaCount; $i++){
                $criterion = (array)$criteria[$i];
                if (array_diff_key(array_flip($requiredKeys),$criterion) || array_diff_key($criterion,array_flip($requiredKeys))){
                    throw new VeloxException("Element at index ".$i." does not contain the correct keys.",37);
                }
                $hashedKeys = $this->criterionHash($criterion);
                if (!isset($this->_criteria[$hashedKeys])){
                    $this->_criteria[$hashedKeys] = ["where"=>$criterion['where'] ?? [],"values"=>$criterion['values'] ?? [],"data"=>[]];
                }
                $this->_criteria[$hashedKeys]['data'][] = ["where"=>$criterion['where'] ?? [],"values"=>$criterion['values'] ?? []];
            }
        }
    }
    public function setStatements() : void {
        $setId = uniqid();
        $statements = [];
        $criteria = $this->_criteria;

        if (count($criteria) == 0){
            $criteria[0]['where'] = [];
            $criteria[0]['values'] = [];
            $criteria[0]['data'] = [];
        }
        foreach($criteria as $variation){
            $whereStr = "";
            $valuesStr = "";
            $columnsStr = "";
            switch ($this->queryType){
                case QUERY_SELECT:
                case QUERY_DELETE:
                case QUERY_UPDATE:
                case QUERY_PROC:
                    //format where clause
                    $orArray = [];
                    foreach ($variation['where'] as $andSet){
                        $andArray = [];
                        foreach ($andSet as $column => $details){
                            switch ($details[0]){
                                case "IS NULL":
                                case "IS NOT NULL":
                                    $andArray[] = $column." ".$details[0];
                                    break;
                                case "=":
                                case "<":
                                case ">":
                                case "<=":
                                case ">=":
                                case "<>":
                                case "LIKE":
                                case "NOT LIKE":
                                case "RLIKE":
                                case "NOT RLIKE":
                                    $andArray[] = $column." ".$details[0]." :w_".$column;
                                    break;
                                case "BETWEEN":
                                case "NOT BETWEEN":
                                    $andArray[] = $column." ".$details[0]." :w_".$column." AND :wb_".$column;
                                    break;
                                case "IN":
                                case "NOT IN":
                                    $inList = [];
                                    $inCount = count($variation['data']['where'][0][$column][1]);
                                    for ($i=0; $i<$inCount; $i++){
                                        $inList[] = ":w".$i."_".$column;
                                    }
                                    $andArray[] = $column." ".$details[0]." (".implode(",",$inList).")";
                                    break;
                                default:
                                    throw new VeloxException("Unsupported operator",36);
                            }
                        }
                        switch (count($andArray)){
                            case 0:
                                break;
                            case 1:
                                $orArray[] = $andArray[0];
                                break;
                            default:
                                $orArray[] = "(".implode(" AND ",$andArray).")";
                                break;
                        }
                    }
            
                    switch (count($orArray)){
                        case 0:
                            $whereStr = "1=1";
                            break;
                        case 1:
                            $whereStr = $orArray[0];
                            break;
                        default:
                            $whereStr = "(".implode(" OR ",$orArray).")";
                            break;
                    }
                    if ($this->queryType != QUERY_UPDATE && $this->queryType != QUERY_PROC){
                        break;
                    }

                case QUERY_INSERT:  //and fall-through for QUERY_UPDATE and QUERY_PROC
                    //format values
                    $valuesArray = $variation['values'];
                    $valuesStrArray = [];
                    $columnsStrArray = [];
                    foreach (array_keys($valuesArray) as $column){
                        switch ($this->queryType){
                            case QUERY_INSERT:
                                $columnsStrArray[] = $column;
                                $valuesStrArray[] = ":v_".$column;
                                break;
                            case QUERY_UPDATE:
                                $valuesStrArray[] = $column." = :v_".$column;
                                break;
                        }
                    }
                    $valuesStr = implode(",", $valuesStrArray);
                    $columnsStr = implode(",", $columnsStrArray);
                    break;
            }
        
            if ($this->queryType == QUERY_INSERT){
                $valuesStr = "(".$columnsStr.") VALUES (".$valuesStr.")";
                $columnsStr = "";
            }
        
            $substitutedSQL = str_replace(["<<condition>>","<<columns>>","<<values>>"],[$whereStr,$columnsStr,$valuesStr],$this->_baseSql);
            
            $stmt = new PreparedStatement($this->conn, $substitutedSQL, $this->queryType, VELOX_RESULT_UNION);
        
            foreach ($variation['data'] as $row){
                $parameterSet = [];
                foreach ($row['where'] as $or){
                    foreach ($or as $column => $data){
                        switch ($data[0]){
                            case "BETWEEN":
                            case "NOT BETWEEN":
                                try {
                                    $parameterSet['wb_'.$column] = $data[2];
                                }
                                catch (Exception $ex){
                                    throw new VeloxException($data[0].' operator used without second operand',24);
                                }
                                break;
                            case "IN":
                            case "NOT IN":
                                try {
                                    $valueCount = count($data[1]);
                                    for ($i=0; $i<$valueCount; $i++){
                                        $parameterSet['w'.$i."_".$column] = $data[1][$i];
                                    }
                                }
                                catch (Exception $ex){
                                    throw new VeloxException("IN operand must be in the form of an array",44);
                                }
                                break;
                            default:
                                try {
                                    $parameterSet['w_'.$column] = $data[1];
                                }
                                catch (Exception $ex){
                                    throw new VeloxException("Operand missing in 'where' array",23);
                                }
                                break;
                        }
                    }
                }
                foreach ($row['values'] as $column => $value){
                    $parameterSet['v_'.$column] = $value;
                }
                $stmt->addParameterSet($parameterSet);
            }
            $statements[] = $stmt;
        }
        $this->_statements = $statements;
    }
    public function execute() : bool {
        if (count($this->_statements) == 0){
            //if no statements are set, try setting them and recheck
            $this->setStatements();
            if (count($this->_statements) == 0){
                throw new VeloxException('Criteria must be set before StatementSet can be executed.',25);
            }
        }
        if (!$this->conn->inTransaction()){
            $transaction = new Transaction($this->conn);
            $transaction->addQuery($this);
            $transaction->begin();
            $transaction->executeAll();
            $this->results = $transaction->getQueryResults();
        }
        else {
            $this->results = null;
            foreach ($this->_statements as $stmt){
                $stmt->execute();
                $results = $stmt->getResults();
                if (!$this->results){
                    $this->results = $stmt->getResults();
                }
                else {
                    if ($this->results instanceof ResultSet){
                        $this->results->merge($stmt->results);
                    }
                    elseif (is_array($this->results)){
                        $this->results[] = $stmt->getResults();
                    }
                }
            }
        }
        return true;
    }
    public function __invoke() : bool {
        return $this->execute();
    }
    public function clear() : void {
        $this->rewind();
        $this->_statements = [];
    }
    public function getLastAffected() : array {
        $affected = [];
        foreach ($this->_statements as $stmt){
            $affected = array_merge($affected,$stmt->getLastAffected());
        }
        return $affected;
    }
    public function getResults() : ResultSet|array|null {
        return $this->results;
    }
    public function dumpQueries() : array {
        $queries = [];
        foreach ($this->_statements as $stmt){
            $queries[] = $stmt->dumpQuery();
        }
        return $queries;
    }
}
