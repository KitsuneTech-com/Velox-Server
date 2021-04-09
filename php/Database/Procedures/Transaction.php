<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;

use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\Database\Procedures\{PreparedStatement,Query,StatementSet};
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;
use KitsuneTech\Velox\VeloxException;

class Transaction {
    private Connection $_baseConn;
    private array $_connections = [];
    private array $_results = [];
    private int $_currentIndex = 0;
    private array $_lastAffected = [];
    private array $_paramArray = [];
    private array $_executionOrder = []
    
    public function __construct(?Connection &$conn) {
        if (isset($conn)){
            $this->_baseConn = $conn;
            $this->_connections[] = $conn;
        }
    }
    
    //Assembly
    public function addQuery(string|Query|StatementSet &$query, ?int $resultType = VELOX_RESULT_NONE) : void {
        $executionCount = count($this->_executionOrder);
        //If a string is passed, build a Query from it, using the base connection of this instance
        if (gettype($query) == "string"){
            if (!isset($this->_baseConn)){
                //If no base connection exists, we haven't set one yet. Query needs this.
                throw new VeloxException("Transaction has no active connection",26);
            }
            //Build it and add it to the $this->queries array
            $this->_executionOrder[] = new Query($this->_baseConn,$query,$resultType);
        }
        else {
            //Add the query connection to $this->_connections if it doesn't already exist
            if (!in_array($query->conn,$this->_connections,true)){
                $this->_connections[] = $query->conn;
                $this->_baseConn = $this->_baseConn ?? $query->conn;
            }
            
            //Get class name for following switch
            $refl = new \ReflectionObject($query);
            $className = $refl->getShortName();
            
            //Class-specific handling
            switch ($className){
                case "PreparedStatement":
                    if (count($this->_executionOrder) == 0 && count($this->_paramArray) > 0){
                        foreach ($this->_paramArray as $paramSet){
                            $query->addParameterSet($paramSet);
                        }
                    }
                    break;
                case "StatementSet":
                    //do the same thing as above, except with addCriteria (needs code for this)
                
                    //add each PreparedStatement in StatementSet into $this->queries[]
                    foreach ($query as $stmt){
                        $this->_executionOrder[] = $stmt;
                    }
                    break;
                case "Query":
                    $this->_executionOrder[] = $query;
                    break;
            }
        }   
    }
    public function addFunction(callable $function) : void {
        $scopedFunction = function() use ($this){
            $function();
        };
        $this->_executionOrder[] = $scopedFunction;
    }
    public function addParameterSet(array $paramArray, string $prefix = '') : void {
        $this->_paramArray[] = $paramArray;
        if (count($this->_executionOrder) > 0 && $this->_executionOrder[0] instanceof PreparedStatement){
            $this->_executionOrder[0]->addParameterSet($paramArray,$prefix);
        }
    }
    public function getParams() : array {
        return $this->_paramArray;
    }
    
    
    
    //Execution
    public function begin() : void {
        foreach ($this->_connections as $conn){
            $conn->beginTransaction();
        }
    }
    public function executeNext() : array|bool {
        if (!(isset($this->_executionOrder[$this->_currentIndex]))){
            return false;
        }
        $currentQuery = $this->_executionOrder[$this->_currentIndex];
        $lastQuery = $this->_executionOrder[$this->_currentIndex-1] ?? null;
        try {
            $currentQuery->conn->setSavepoint();
            $currentQuery->execute();
        
            if ($lastQuery && $lastQuery->getSetId() == $currentQuery->getSetId()){
                $lastQueryResults = $lastQuery->getQueryResults();
                if ($lastQueryResults instanceof ResultSet){
                    $lastQueryResults->merge($currentQuery->getResults());
                }
                elseif (is_array($lastQueryResults)){
                    $lastQueryResults += $currentQuery->getResults();
                }
            }
            else {
                $this->_results[] = $currentQuery->results;
            }
            $this->_currentIndex++;
            $this->_lastAffected = $currentQuery->getLastAffected();
            return $this->_lastAffected;
        }
        catch (Exception $ex){
            $currentQuery->conn->rollBack(true);
            throw new VeloxException("Query in transaction failed",27,$ex);
        }
    }
  
    public function getQueryResults(?int $queryIndex = null) : ResultSet|array|bool {
        if (is_null($queryIndex)){
            $queryIndex = count($this->_executionOrder)-1;
        }
        return $this->_results[$queryIndex];
    }
  
    public function executeAll() : bool {
        $this->begin();
        try {
            while ($this->executeNext()){}
            foreach ($this->_connections as $conn){
                $conn->commit();
            }
            return true;
        }
        catch (VeloxException $ex){
            foreach ($this->_connections as $conn){
                try {
                    $conn->rollBack();
                }
                catch(VeloxException $rollbackEx){
                    continue;
                }
            }
            throw $ex;
        }
    }
    public function getLastAffected() : array {
        return $this->_lastAffected;
    }
    public function getTransactionPlan() : array {
        $queryDumpArray = [];
        foreach ($this->_executionOrder as $query){
            $queryDumpArray[] = $query->dumpQuery();
        }
        return $queryDumpArray;
    }
}
