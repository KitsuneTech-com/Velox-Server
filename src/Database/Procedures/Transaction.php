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
    public array $executionOrder = [];
    
    public function __construct(?Connection &$conn = null, ?string $name = null) {
        if (isset($conn)){
            $this->_baseConn = $conn;
            $this->_connections[] = $conn;
        }
    }
    
    //Assembly
    public function addQuery(string|Query|StatementSet|Transaction &$query, ?int $resultType = Query::RESULT_NONE, ?string $name) : void {
        $executionCount = count($this->executionOrder);
        //If a string is passed, build a Query from it, using the base connection of this instance
        if (gettype($query) == "string"){
            if (!isset($this->_baseConn)){
                //If no base connection exists, we haven't set one yet. Query needs this.
                throw new VeloxException("Transaction has no active connection",26);
            }
            //Build it and add it to the $this->queries array
            $this->executionOrder[] = ["procedure" => new Query($this->_baseConn,$query,$resultType), "arguments" => null, "name" => $name];
        }
        else {
            //Add the query connection to $this->_connections if it doesn't already exist
            if (!in_array($query->conn,$this->_connections,true)){
                $this->_connections[] = $query->conn;
                $this->_baseConn = $this->_baseConn ?? $query->conn;
            }
            
            //Add initial parameters (for PreparedStatement) or criteria (for StatementSet)
            if (count($this->_executionOrder) == 0 && count($this->_paramArray) > 0){
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
            $this->executionOrder[] = ["procedure" => &$query, "arguments" => null, "name" => $name || $query->name];
        }   
    }
    public function addFunction(callable $function, ?string $name) : void {
        // Any functions added with this method are passed two arguments. Each of these arguments is an array containing two elements; the first element of each
        // is a Velox procedure or a callable function, and the second element is an array of arguments or parameters to be applied to that procedure or function.
        // The first array corresponds to the last procedure or function that was added to the transaction, and the second array corresponds to
        // the next procedure or function that will be added to the transaction. Whatever arguments or parameters were passed to the previous procedure will be
        // available in the first array, and any arguments already defined for the next procedure will be available in the second array. These can be modified as
        //
        // If no previous or next procedure exists, the corresponding argument will be null. If this function expects parameters itself [as might be defined in
        // Transaction::addTransactionParameters()], these will be chained to the argument list after the second array.
        //
        // Thus, the definition should resemble the following (type hinting is, of course, optional):
        // ------------------
        // $transactionInstance = new Transaction();
        // $myFunction = function(array|null $previous, array|null $next, $optionalArgument1, $optionalArgument2...) : void {
        //     //function code goes here
        // }
        // $transactionInstance.addFunction($myFunction);
        // -------------------
        // No return value is necessary for functions defined in this way. Any actions performed by the function should act on or use the
        // references passed in with the arguments, or else global variables. They are run as closures, and do not inherit any external scope.
        
        $executionCount = count($this->executionOrder);
        $scopedFunction = function() use (&$function,$executionCount){
            $previous = $this->executionOrder[$executionCount - 1] ?? null;
            $next = $this->executionOrder[$executionCount + 1] ?? null;
            $boundFunction = $function->bindTo($this);
            $boundFunction($previous,$next,...$this->executionOrder["arguments"]);
        };
        $this->executionOrder[] = ["procedure" => $scopedFunction->bindTo($this,$this), "arguments" => null];
    }
    public function addParameterSet(array $paramArray, string $prefix = '') : void {
        $this->_paramArray[] = $paramArray;
        if (!!$this->executionOrder && $this->executionOrder[0]['procedure'] instanceof PreparedStatement){
            $this->executionOrder[0]['procedure']->addParameterSet($paramArray,$prefix);
        }
        else {
            throw new VeloxException("Attempted to add parameter set to Transaction without a leading PreparedStatement",61);
        }
    }
    public function addCriteria(array $criteria, string $prefix = '') : void {
        $this->_paramArray[] = $criteria;
        if (!!$this->executionOrder && $this->executionOrder[0]['procedure'] instanceof StatementSet){
            $this->executionOrder[0]['procedure']->addCriteria($criteria);
        }
        else {
            throw new VeloxException("Attempted to add criteria to Transaction without a leading StatementSet",62);
        }
    }
    public function addTransactionParameters(array $procedureParams) : void {
        foreach ($procedureParams as $procedure => $paramArray) {
            if (is_int($procedure)){
                $this->executionOrder[$procedure]["arguments"] = $paramArray;
            }
            else {
                for ($i = 0; $i < count($this->executionOrder); $i++){
                    if ($this->executionOrder[$i]["name"] == $procedure){
                        $this->executionOrder[$i]["arguments"] = $paramArray;
                    }
                }
            }
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
        if (!(isset($this->executionOrder[$this->_currentIndex]))){
            return false;
        }
        $currentExecution = $this->executionOrder[$this->_currentIndex];
        $query = $currentExecution['procedure'];
        $arguments = $currentExecution['arguments'];
        try {
            if ($query instanceof Query || $query instanceof StatementSet) {
                $query->conn->setSavepoint();
                if ($arguments){
                    $refl = new \ReflectionObject($query);
                    $className = $refl->getShortName();

                    switch ($className){
                        case "PreparedStatement":
                            foreach ($arguments as $paramSet){
                                $query->addParameterSet($paramSet);
                            }
                            break;
                        case "StatementSet":
                            foreach ($arguments as $criteria){
                                $query->addCriteria($criteria);
                            }
                            break;
                    }
                }
                $query();
            }
            else {

            }

            if ($query instanceof Query || $query instanceof StatementSet){
                $this->_results[] = $query->results;
                $this->_lastAffected = $query->getLastAffected();
            }
            
            $this->_currentIndex++;
            return true;
        }
        catch (Exception $ex){
            if ($query instanceof Query || $query instanceof StatementSet){
                $query->conn->rollBack(true);
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
        return $this->_results[$queryIndex] ?? false;
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
        foreach ($this->executionOrder as $query){
            $queryDumpArray[] = $query->dumpQuery();
        }
        return $queryDumpArray;
    }
}
