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
    private array $_executionOrder = [];
    
    public function __construct(?Connection &$conn = null) : void {
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
            
            //Add initial parameters (for PreparedStatement) or criteria (for StatementSet)
            if (!$this->_executionOrder && !!$this->_paramArray){
                //Get class name for following switch
                $refl = new \ReflectionObject($query);
                $className = $refl->getShortName();
                
                switch ($className){
                    case "PreparedStatement":
                        foreach ($this->_paramArray as $paramSet){
                            $query->addParameterSet($paramSet);
                        }
                        break;
                    case "StatementSet":
                        foreach ($this->_paramArray as $criteria){
                            $query->addCriteria($criteria);
                        }
                        break;
                }
            }
            $this->_executionOrder[] = &$query;
        }   
    }
    public function addFunction(callable $function) : void {
        // Any functions added with this method are passed two arguments (in order):
        //  * A reference to the previous function or Velox procedure (if any),
        //  * and a reference to the following function or Velox procedure (if any).
        // Thus, the definition should resemble the following (type hinting is, of course, optional, but the reference operators are not):
        // ------------------
        // $transactionInstance = new Transaction();
        // $myFunction = function(Query|callable|null &$previous, Query|callable|null &$next) : void {
        //     //function code goes here
        // }
        // $transactionInstance.addFunction($myFunction);
        // -------------------
        // No return value is necessary for functions defined in this way. Any actions performed by the function should act on or use the
        // references passed in with the arguments, or else global variables. They are run as closures, and do not inherit any external scope.
        
        $executionCount = count($this->_executionOrder);
        $scopedFunction = function() use (&$function,$executionCount){
            $previous = &$this->_executionOrder[$executionCount-1] ?? null;
            $next = &$this->_executionOrder[$executionCount+1] ?? null;
            $boundFunction = $function->bindTo($this);
            $boundFunction($previous,$next);
        };
        $this->_executionOrder[] = $scopedFunction->bindTo($this,$this);
    }
    public function addParameterSet(array $paramArray, string $prefix = '') : void {
        $this->_paramArray[] = $paramArray;
        if (!!$this->_executionOrder && $this->_executionOrder[0] instanceof PreparedStatement){
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
    public function executeNext() : bool {
        if (!(isset($this->_executionOrder[$this->_currentIndex]))){
            return false;
        }
        
        $currentQuery = $this->_executionOrder[$this->_currentIndex];
        $lastQuery = $this->_executionOrder[$this->_currentIndex-1] ?? null;
        try {
            if ($currentQuery instanceof Query || $currentQuery instanceof StatementSet) {
                $currentQuery->conn->setSavepoint();
            }
            
            $currentQuery();
            
            if ($currentQuery instanceof Query || $currentQuery instanceof StatementSet){
                $this->_results[] = $currentQuery->results;
                $this->_lastAffected = $currentQuery->getLastAffected();
            }
            
            $this->_currentIndex++;
            return true;
        }
        catch (Exception $ex){
            if ($currentQuery instanceof Query || $currentQuery instanceof StatementSet){
                $currentQuery->conn->rollBack(true);
                throw new VeloxException("Query in transaction failed",27,$ex);
            }
            else {
                throw new VeloxException("User-defined function failed",39,$ex);
            }
        }
    }
  
    public function getQueryResults(?int $queryIndex = null) : ResultSet|array|bool {
        if (is_null($queryIndex)){
            $queryIndex = count($this->_executionOrder)-1;
        }
        if (isset($this->_results[$queryIndex])){
            return $this->_results[$queryIndex];
        }
        else {
            return false;
        }
    }
  
    public function executeAll() : bool {
        try {
            while ($next = $this->executeNext()){}
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
