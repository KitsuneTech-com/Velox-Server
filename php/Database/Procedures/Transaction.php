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
    private array $_input = [];
    public array $executionOrder = [];
    
    public function __construct(?Connection &$conn = null) {
        if (isset($conn)){
            $this->_baseConn = $conn;
            $this->_connections[] = $conn;
        }
    }
    
    public function __clone() : void {
        $this->_input = [];
        $this->_results = [];
        $this->_currentIndex = 0;
        $this->_lastAffected = [];
        foreach ($this->executionOrder as $idx => $procedure){
            $this->executionOrder[$idx] = clone $procedure;
        }
    }
    
    public function __destruct() {
        $this->rollBack();
    }
    
    //Assembly
    public function addQuery(string|Query|StatementSet &$query, ?int $resultType = VELOX_RESULT_NONE) : void {
        $executionCount = count($this->executionOrder);
        //If a string is passed, build a Query from it, using the base connection of this instance
        if (gettype($query) == "string"){
            if (!isset($this->_baseConn)){
                //If no base connection exists, we haven't set one yet. Query needs this.
                throw new VeloxException("Transaction has no active connection",26);
            }
            //Build it and add it to the $this->queries array
            $this->executionOrder[] = new Query($this->_baseConn,$query,$resultType);
        }
        else {
            //Add the query connection to $this->_connections if it doesn't already exist
            if (!in_array($query->conn,$this->_connections,true)){
                $this->_connections[] = $query->conn;
                $this->_baseConn = $this->_baseConn ?? $query->conn;
            }
            
            //Add initial parameters (for PreparedStatement) or criteria (for StatementSet)
            if (!$this->executionOrder && !!$this->_input){
                //Get class name for following switch
                $refl = new \ReflectionObject($query);
                $className = $refl->getShortName();
                
                switch ($className){
                    case "PreparedStatement":
                        foreach ($this->_input as $paramSet){
                            $query->addParameterSet($paramSet);
                        }
                        break;
                    case "StatementSet":
                        foreach ($this->_input as $criteria){
                            $query->addCriteria($criteria);
                        }
                        break;
                }
            }
            $this->executionOrder[] = &$query;
        }   
    }
    public function addFunction(callable $function) : void {
        // Any functions added with this method are passed two arguments (in order):
        //  * A reference to the previous function or Velox procedure (if any),
        //  * and a reference to the following function or Velox procedure (if any).
        // Thus, the definition should resemble the following (type hinting is, of course, optional, but the reference operators are not):
        // ------------------
        // $transactionInstance = new Transaction();
        // $myFunction = function(mixed &$previous, Query|callable|null &$next) : void {
        //     //function code goes here
        // }
        // $transactionInstance.addFunction($myFunction);
        // -------------------
        // No return value is necessary for functions defined in this way. Any actions performed by the function should act on or use the
        // references passed in with the arguments, or else global variables. They are run as closures, and do not inherit any external scope.
        //
        // If the function is the first element in the Transaction execution order and initial data is provided through Transaction->addInput(),
        // this data is made available through the $previous argument.
        
        $executionCount = count($this->executionOrder);
        $scopedFunction = function() use (&$function,$executionCount){
            $previous = &$this->executionOrder[$executionCount-1] ?? $this->_input;
            $next = &$this->executionOrder[$executionCount+1] ?? null;
            $boundFunction = $function->bindTo($this);
            $boundFunction($previous,$next);
        };
        $this->executionOrder[] = $scopedFunction->bindTo($this,$this);
    }
    public function addInput(array $input, string $prefix = '') : void {
        $this->_input[] = $input;
    }
    public function getParams() : array {
        return $this->_input;
    }
    
    //Execution
    public function begin() : void {
        foreach ($this->_connections as $conn){
            $conn->beginTransaction();
        }
    }
    public function executeNext(bool $autocommit = false) : bool {
        if (!(isset($this->executionOrder[$this->_currentIndex]))){
            return false;
        }
        
        $currentQuery = $this->executionOrder[$this->_currentIndex];
        $lastQuery = $this->executionOrder[$this->_currentIndex-1] ?? null;
        if ($this->_input && !$lastQuery){
            if ($currentQuery instanceof PreparedStatement){
                foreach ($this->_input as $paramSet){
                    $currentQuery->addParameterSet($paramSet);
                }
            }
            elseif ($currentQuery instanceof StatementSet){
                $currentQuery->addCriteria($this->_input);
            }
            elseif ($currentQuery instanceof Query){
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
            if ($autocommit){
                $this->commitLast();
            }
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
            $queryIndex = count($this->executionOrder)-1;
        }
        if (isset($this->_results[$queryIndex])){
            return $this->_results[$queryIndex];
        }
        else {
            return false;
        }
    }
    
    public function rollBack() : void {
       $ex = null;
       foreach ($this->_connections as $conn){
            try {
                $conn->rollBack();
            }
            catch(VeloxException $rollbackEx){
                //Store any exception and continue
                //Note: if more exceptions are thrown, only the most recent is stored
                $ex = $rollbackEx;
                continue;
            }
        }
        if ($ex){
            //Throw any stored exception
            throw $ex;
        }
    }
    public function commitLast() : void {
        //Commit on the connection used by the most recent query
        $position = $this->_currentIndex;
        while ($previous = $this->executionOrder[$position--]){
            if ($previous instanceof Query || $previous instanceof StatementSet){
                $previous->conn->commit();
                $previous->conn->beginTransaction();
                return;
            }
            if ($position == 0){
                return;
            }
        }
    }
  
    public function executeAll(bool $autocommit = false) : bool {
        try {
            while ($next = $this->executeNext()){
                if ($autocommit){
                    $this->commitLast();
                }
            }
            foreach ($this->_connections as $conn){
                $conn->commit();
            }
            return true;
        }
        catch (VeloxException $ex){
            try {
                $this->rollBack();
            }
            finally {
                throw $ex;
            }
        }
    }
    public function getLastAffected() : array {
        return $this->_lastAffected;
    }
    public function getTransactionPlan() : array {
        $queryDumpArray = [];
        foreach ($this->executionOrder as $query){
            $queryDumpArray[] = $query->dumpQuery();
        }
        return $queryDumpArray;
    }
}
