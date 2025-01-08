<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;

use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;
use KitsuneTech\Velox\VeloxException;

/**
 * A class for managing database transactions
 *
 * This class expands on the basic concept of T-SQL database transactions by both allowing transactions to be coordinated between
 * data sources (including commit and rollback) and by allowing interstitial application code to be run between database procedure
 * calls (e.g., to transform the data returned by a previous procedure before passing it on to the next). Velox Transactions are
 * built by first instantiating a new Transaction object, then using one or more sequential calls to {@see Transaction::addQuery()}
 * or {@see Transaction::addFunction()} to add the desired procedures. Once this is done, the transaction is initiated by
 * calling {@see Transaction::begin()} to start the transaction on each data source. Initial parameters for each Transaction iteration
 * can be defined by calling {@see Transaction::addTransactionParameters()} using an array appropriate for the first procedure defined
 * in the Transaction; each additional call to this method will add an iteration to the Transaction. These iterations can be run
 * individually or all at once as desired, using one or several of the following methods:
 *
 *{@see Transaction::executeNextProcedure()}    Executes the next defined query or function.
 *
 *{@see Transaction::executeIteration()}        Runs a single iteration of the Transaction using the current set of parameters.
 *
 *{@see Transaction::executeAll()}              Runs the Transaction for each defined set of parameters.
 *
 * If any query fails during the execution of a Transaction, all databases affected are rolled back to their previous state.
 * Transactions can also be nested for more granular control of the commit/rollback process.
 *
 * The Transaction instance is itself callable, and invoking it as a function is a simple alias for calling {@see Transaction::executeAll()}.
 *
 * @version 1.0.0
 * @since 1.0.0-alpha
 * @license https://www.mozilla.org/en-US/MPL/2.0/ Mozilla Public License 2.0
 *
 */

class Transaction {
    public const LAST = -1;
    private Connection $_baseConn;
    private array $_connections = [];
    private array $_results = [];
    private int $_currentProcedureIndex = 0;
    private int $_currentIterationIndex = 0;
    private array $_lastAffected = [];
    private array $_iterations = [];
    private string|int $_lastDefinedProcedure;

    /** @var array A collection of procedures (Queries, PreparedStatements, and/or nested Transactions) to be run in
     * sequence by this Transaction.
     *
     * **Note: though this property is public, it should be considered read-only and should not be modified directly.**
     * Use {@see Transaction::addQuery()} and/or {@see Transaction::addFunction()} to insert procedures into the
     * execution order.
     */
    public array $procedures = [];

    /**
     * @param Connection|null $conn     (optional) The Connection instance to begin this Transaction with; this is
     * optional, and if this isn't provided, the Connection associated with the first added procedure will be adopted at that time.
     */
    public function __construct(?Connection &$conn = null) {
        if (isset($conn)){
            $this->_baseConn = $conn;
            $this->_connections[] = $conn;
        }
    }
    /**
     * @ignore
     */
    public function __invoke(bool $commit = false) : void {
        $this->executeAll();
    }

    /**
     * Inserts a query into the Transaction execution order.
     *
     * This query can take the form of a string of SQL, or any Velox procedure object. If a string is provided, the query
     * will be run on the Transaction's base connection, as provided to the {@see __construct() constructor}.
     *
     * @param string|Query|StatementSet|Transaction $query A Velox query or SQL query string to be run at this point in the execution order
     * @param int|null $resultType If the provided query is a SQL string, this is the desired result type (see the RESULT_ constants in {@see Query}) Default (null) is no result.
     * @param string|null $name An optional name by which to refer to the function. This will be available for analysis with {@see Transaction::getTransactionPlan()}.
     *
     * @return void
     * @throws VeloxException if a string is passed as the query and the Transaction does not have a base connection
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function addQuery(string|Query|StatementSet|Transaction &$query, ?int $resultType = Query::RESULT_NONE, ?string $name = null) : void {
        $executionCount = count($this->procedures);
        //If a string is passed, build a Query from it, using the base connection of this instance
        if (gettype($query) == "string"){
            if (!isset($this->_baseConn)){
                //If no base connection exists, we haven't set one yet. Query needs this.
                throw new VeloxException("Transaction has no active connection",26);
            }
            //Build it and add it to the $this->queries array
            $instance = new Query($this->_baseConn,$query,$resultType);
        }
        else {
            //Add the query connection to $this->_connections if it doesn't already exist
            if (!in_array($query->conn,$this->_connections,true)){
                $this->_connections[] = $query->conn;
                $this->_baseConn = $this->_baseConn ?? $query->conn;

            }
            $instance =& $query;
        }
        if (!$name){
            $name = $instance->name ?? count($this->procedures); //Default name is the index of the procedure in $this->procedures
        }
        $this->_lastDefinedProcedure = $name;
        $this->procedures[] = ["instance" => $instance, "name" => $name];
    }

    /**
     * Inserts a user-defined callable function into the Transaction execution order.
     *
     * Any functions added with this method are passed two arguments. Each of these arguments is an array containing two elements; the first element of each
     * is a Velox procedure or a callable function, and the second element is an array of arguments or parameters to be applied to that procedure or function.
     * The first array corresponds to the last procedure or function that was added to the transaction, and the second array corresponds to
     * the next procedure or function that will be added to the transaction. Whatever arguments or parameters were passed to the previous procedure will be
     * available in the first array, and any arguments already defined for the next procedure will be available in the second array. These can be modified as
     *
     * If no previous or next procedure exists, the corresponding argument will be null. If this function expects parameters itself [as might be defined in
     * {@see Transaction::addTransactionParameters()}], these will be chained to the argument list after the second array.
     *
     * Thus, the definition should resemble the following (type hinting is, of course, optional):
     *
     * ```php
     * $transactionInstance = new Transaction();
     * $myFunction = function(array|null $previous, array|null $next, $optionalArgument1, $optionalArgument2...) : void {
     *     //function code goes here
     * }
     * $transactionInstance.addFunction($myFunction);
     * ```
     * No return value is necessary for functions defined in this way. Any actions performed by the function should act on or use the
     * references passed in with the arguments, or else global variables. They are run as closures, and do not inherit any external scope.
     *
     * @param callable $function An anonymous function to be added to the execution order, following the description above.
     * @param string|null $name An optional name by which to refer to the function. This will be available for analysis with {@see Transaction::getTransactionPlan()}.
     *
     * @return void
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function addFunction(callable $function, ?string $name = null) : void {
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
        $this->_lastDefinedProcedure = $name ?? $procedureIndex;
        $this->procedures[] = ["instance" => $scopedFunction->bindTo($this,$this), "name" => $this->_lastDefinedProcedure];
    }

    /**
     * Adds a set of parameters to be used by the first procedure in the execution order.
     *
     * This acts as {@see PreparedStatement::addParameterSet()} or {@see StatementSet::addCriteria()} and feeds the first
     * procedure in the execution order with the given parameters/criteria at execution time. This method can be called
     * multiple times; the Transaction will be run once for each set of parameters/criteria, in the order provided.
     *
     * @param array $procedureParams
     *
     * @return void
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function addTransactionParameters(array $procedureParams) : void {
        $this->_iterations[] = $procedureParams;
    }

    /**
     * Initiates the T-SQL transaction for each distinct connection used by this Transaction.
     *
     * @return void
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function begin() : void {
        foreach ($this->_connections as $conn){
            $conn->beginTransaction();
        }
    }

    /**
     * Runs the next procedure or function in the execution order. This can be used to step through a Transaction incrementally
     * without committing the results for the procedure or function in question.
     *
     * @return bool True if the procedure or function completed successfully.
     * @throws VeloxException if the procedure or function fails. See the call stack for more details.
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
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
                if (!isset($this->_results[$this->_currentIterationIndex])){
                    $this->_results[$this->_currentIterationIndex] = [$currentProcedure['name'] => $procedure->results];
                }
                else {
                    $this->_results[$this->_currentIterationIndex][$currentProcedure['name']] = $procedure->results;
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

    /**
     * Returns the result set(s) from the Transaction in its current state.
     *
     * These results are returned by default as a
     * two-dimensional array in which each element is an array containing the results of each iteration of the Transaction,
     * in order. These arrays themselves contain, in execution order, the results for each procedure in the given iteration.
     *
     * Arguments can be passed to this method to filter the results as desired. The first optional argument is the index
     * of the iteration whose results are to be retrieved (zero-indexed, in order of execution) and the second is the name
     * or index (again in execution order) of a particular procedure. Specifying one and passing null for the other
     * retrieves an array of results, in execution order belonging to the specified iteration or procedure.
     *
     * The special constant {@see Transaction::LAST} can also be passed as the first argument; in this case, only the result
     * of the most recently committed iteration is returned.
     *
     * @param int|null $iterationIndex The index of the iteration whose results are to be returned
     * @param int|string|null $name The name or index of the procedure whose results are to be returned
     *
     * @return ResultSet|array|bool The desired result set, in whichever form results from the combination (or lack thereof)
     * of arguments passed.
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function getQueryResults(?int $iterationIndex = null, int|string|null $name = null) : ResultSet|array|bool {
        if ($iterationIndex == Transaction::LAST) $iterationIndex = $this->_currentIterationIndex - 1;
        if (count($this->_results) == 0){
            return false;
        }
        elseif (is_null($iterationIndex)){
            if (isset($name)){
                //Name is set, iteration is not
                return array_column($this->_results,$name);
            }
            else {
                //Neither is set
                return $this->_results;
            }
        }
        elseif (is_null($name)){
            //Iteration is set, name is not
            return $this->_results[$iterationIndex];
        }
        else {
            //Both are set
            return $this->_results[$iterationIndex][$name];
        }
    }

    /**
     * Executes the current iteration of this Transaction.
     *
     * A single iteration of a Transaction represents a complete pass through the execution order for one set of
     * initial criteria. Where multiple sets of initial criteria are assigned, executeIteration() always acts in
     * first-in-first-out order. The iteration is not autocommitted by default, but a commit can be forced by passing
     * true as the sole argument. On completion, the internal pointer is advanced, so that the next call will act on
     * the next iteration.
     *
     * @param bool $commit If true, commit the iteration on completion.
     *
     * @return bool Whether there are any more iterations to execute (useful for a while loop)
     * @throws VeloxException if any errors occur during the execution of the underlying procedures. If this happens,
     *   the Transaction is rolled back.
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */

    public function executeIteration(bool $commit = false) : bool {
        try {
            if (!isset($this->_iterations[$this->_currentIterationIndex])) return false;
            while ($this->executeNextProcedure()){ /* continue execution */ }
            if ($commit) {
                $this->commit(); //iteration index is incremented inside commit()
            }
            else {
                $this->_currentIterationIndex++;
                $this->_currentProcedureIndex = 0;
            }
            return (isset($this->_iterations[$this->_currentIterationIndex]));
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

    /**
     * Executes all remaining iterations of this Transaction.
     *
     * This acts as {@see Transaction::executeIteration()}, except that it will continue executing iterations until there are none left.
     * These iterations are not autocommitted [as the default behavior for executeIteration()], but the iterations can
     * be force-committed as a group by passing true as the sole argument.
     *
     * @param bool $commit If true, commit the set of iterations once all are executed.
     *
     * @return void
     * @throws VeloxException if any errors occur during the execution of the underlying procedures. If this happens,
     *    the Transaction is rolled back.
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */

    public function executeAll(bool $commit = false) : void {
        while ($this->executeIteration()){ /* continue execution */ }
        if ($commit){
            $this->commit();
        }
    }

    /**
     * Commits any executed procedures that have yet to been committed.
     *
     * This performs a commit on each connection assigned to the Transaction. When all commits are done, the internal pointer
     * is advanced to the next iteration of the Transaction.
     *
     * @return void
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function commit() : void {
        foreach ($this->_connections as $conn){
            $conn->commit();
        }
        $this->_currentIterationIndex++;
        $this->_currentProcedureIndex = 0;
    }

    /**
     * Returns an array of indices affected by the most recent Velox procedure run in this Transaction. See the
     *  documentation on the procedure class in question for expected behavior.
     *
     * @return array The array of affected indices.
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function getLastAffected() : array {
        return $this->_lastAffected;
    }

    /**
     * @return int|string The name (if assigned) or index of the last function or Velox procedure assigned to this Transaction
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function finalProcedure() : int|string {
        return $this->_lastDefinedProcedure;
    }

    /**
     * A diagnostic method to retrieve details about the Transaction and the functions/procedures assigned to it
     *
     * @return array
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
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
