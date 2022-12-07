<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;

use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;
use KitsuneTech\Velox\VeloxException;

/**
 * `Transaction` - a class for managing database transactions
 *
 * This class expands on the basic concept of database transactions by both allowing transactions to be coordinated between
 * data sources (including commit and rollback) and by allowing interstitial application code to be run between database procedure
 * calls. This can be used, for example, to manage import/export of data between disparate databases. Velox Transactions are
 * built by first instantiating a new Transaction object, then using one or more sequential calls to {@see Transaction::addQuery()}
 * or {@see Transaction::addFunction()} to add the desired procedures. Once this is done, the transaction is initiated by
 * calling {@see Transaction::begin()} to start the transaction on each data source. Initial parameters for each Transaction iteration
 * can be defined by calling {@see Transaction::addTransactionParameters()} using an array appropriate for the first procedure defined
 * in the Transaction; each additional call to this method will add an iteration to the Transaction. These iterations can be run
 * individually or all at once as desired, using the following methods:
 *
 *{@see Transaction::executeNextProcedure()}    Executes the next defined query or function.
 *
 *{@see Transaction::executeIteration()}        Runs a single iteration of the Transaction using the current set of parameters.
 *
 *{@see Transaction::executeAll()}             Runs the Transaction for each defined set of parameters.
 */

class Transaction {
    private Connection $_baseConn;
    private array $_connections = [];
    private array $_results = [];
    private int $_currentProcedureIndex = 0;
    private int $_currentIterationIndex = 0;
    private array $_lastAffected = [];
    private array $_iterations = [];
    private array $_currentIteration = [];
    public array $procedures = [];

    public function __construct(?Connection &$conn = null, ?string $name = null) {
        if (isset($conn)){
            $this->_baseConn = $conn;
            $this->_connections[] = $conn;
        }
    }

    //Assembly
    public function addQuery(string|Query|StatementSet|Transaction &$query, ?int $resultType = Query::RESULT_NONE, ?string $name = null) : void {
        $executionCount = count($this->procedures);
        //If a string is passed, build a Query from it, using the base connection of this instance
        if (gettype($query) == "string"){
            if (!isset($this->_baseConn)){
                //If no base connection exists, we haven't set one yet. Query needs this.
                throw new VeloxException("Transaction has no active connection",26);
            }
            //Build it and add it to the $this->queries array
            $this->procedures[] = ["instance" => new Query($this->_baseConn,$query,$resultType), "name" => $name];
        }
        else {
            //Add the query connection to $this->_connections if it doesn't already exist
            if (!in_array($query->conn,$this->_connections,true)){
                $this->_connections[] = $query->conn;
                $this->_baseConn = $this->_baseConn ?? $query->conn;

            }
            $this->procedures[] = ["instance" => &$query, "name" => $name ?? $query->name];
        }
    }
    public function addFunction(callable $function, ?string $name = null) : void {
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

        $procedureIndex = count($this->procedures);
        $scopedFunction = function() use (&$function,$procedureIndex){
            $previousProcedure = $this->procedures[$procedureIndex - 1] ?? null;
            $nextProcedure = $this->procedures[$procedureIndex + 1] ?? null;
            $previousArgsRef = $this->_iterations[$this->_currentIterationIndex][$previousProcedure["name"]] ?? null;
            $previousArguments =& $previousArgsRef;
            $nextArgsRef = $this->_iterations[$this->_currentIterationIndex][$nextProcedure["name"]] ?? null;
            $nextArguments =& $nextArgsRef;
            $previous = ["procedure" => $previousProcedure, "arguments" => &$previousArguments];
            $next = ["procedure" => $nextProcedure, "arguments" => &$nextArguments];
            $boundFunction = $function->bindTo($this);
            $arguments = $this->_iterations[$this->_currentIterationIndex][$this->procedures[$procedureIndex]["name"]] ?? null;
            if ($arguments){
                $boundFunction($previous,$next,...$arguments);
            }
            else {
                $boundFunction($previous,$next);
            }
        };
        $this->procedures[] = ["instance" => $scopedFunction->bindTo($this,$this), "name" => $name ?? $procedureIndex];
    }
    public function addTransactionParameters(array $procedureParams) : void {
        $this->_iterations[] = $procedureParams;
    }

    //Execution
    public function begin() : void {
        foreach ($this->_connections as $conn){
            $conn->beginTransaction();
        }
    }
    public function executeNextProcedure() : bool {
        if (!(isset($this->procedures[$this->_currentProcedureIndex]))){
            return false;
        }
        $currentProcedure = $this->procedures[$this->_currentProcedureIndex];
        $currentIteration = $this->_iterations[$this->_currentIterationIndex];
        $procedure = $currentProcedure['instance'];

        try {
            if ($procedure instanceof Query || $procedure instanceof StatementSet) {
                $procedure->conn->setSavepoint();
                $arguments = $currentIteration[$currentProcedure['name']] ?? [];
                if ($arguments){
                    $refl = new \ReflectionObject($procedure);
                    $className = $refl->getShortName();

                    switch ($className){
                        case "PreparedStatement":
                            foreach ($arguments as $paramSet){
                                $procedure->addParameterSet($paramSet);
                            }
                            break;
                        case "StatementSet":
                            $procedure->addCriteria($arguments);
                            break;
                    }
                }
            }
            $procedure();

            if ($procedure instanceof Query || $procedure instanceof StatementSet){
                if (!isset($this->results[$this->_currentIterationIndex])){
                    $this->results[$this->_currentIterationIndex] = [$procedure->results];
                }
                else {
                    $this->results[$this->_currentIterationIndex][] = $procedure->results;
                }
                $this->_lastAffected = $procedure->getLastAffected();
            }

            $this->_currentProcedureIndex++;
            return true;
        }
        catch (\Exception $ex){
            if ($procedure instanceof Query || $procedure instanceof StatementSet){
                $procedure->conn->rollBack(true);
                throw new VeloxException("Query in transaction failed",27,$ex);
            }
            else {
                throw new VeloxException("User-defined function failed",39,$ex);
            }
        }
    }

    public function getQueryResults(?int $iterationIndex = null) : ResultSet|array|bool {
        if (is_null($iterationIndex)){
            $queryIndex = count($this->_iterations)-1;
        }
        return $this->_results[$iterationIndex] ?? false;
    }

    public function executeIteration(bool $commit = false) : bool {
        try {
            if (!isset($this->_iterations[$this->_currentIterationIndex])) return false;
            while ($this->executeNextProcedure()){ /* continue execution */ }
            if ($commit){
                foreach ($this->_connections as $conn){
                    $conn->commit();
                }
            }
            $this->_currentIterationIndex++;
            $this->_currentProcedureIndex = 0;
            return (!isset($this->_iterations[$this->_currentIterationIndex]));
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

    public function executeAll() : void {
        while ($this->executeIteration()){ /* continue execution */ }
        foreach ($this->_connections as $conn){
            $conn->commit();
        }
    }
    //Magic method wrapper for executeAll() to make Transaction callable
    public function __invoke(bool $commit = false) : void {
        $this->executeAll();
    }
    public function getLastAffected() : array {
        return $this->_lastAffected;
    }
    public function getTransactionPlan() : array {
        $queryDumpArray = [];
        foreach ($this->_iterations as $iteration){
            $queryDumpArray[] = array_map(function($procedure) use ($iteration){
                $procedureName = $procedure['name'];
                $procedure = $procedure['procedure'];
                $refl = new \ReflectionObject($procedure);
                $className = $refl->getShortName();
                $procedureDump = [
                    "type" => $className,
                    "name" => $procedureName,
                    "arguments" => $iteration[$procedureName] ?? []
                ];
                return $procedureDump;
            },$this->procedures);
        }
        return $queryDumpArray;
    }
}