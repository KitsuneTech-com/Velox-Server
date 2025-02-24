<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;
use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\VeloxException;

/**
 * A subclass of Query that allows for parameterized prepared statements.
 *
 * The SQL syntax for PreparedStatement queries follows the MySQL syntax for prepared statements, which is the same as
 * the syntax used by PDO. Either named or positional parameters may be used. The values for the parameters are passed in
 * as an associative array to the {@see PreparedStatement::addParameterSet() addParameterSet()} method; several calls to
 * this method may be made to add multiple sets of parameters, each of which will be bound and executed in sequence when
 * the {@see Query::execute() execute()} method is called.
 *
 * @version 1.0.0
 * @since 1.0.0-alpha
 * @license https://www.mozilla.org/en-US/MPL/2.0/ Mozilla Public License 2.0
 */

class PreparedStatement extends Query {
    private array $_namedParams = [];
    private int $_paramCount = 0;
    private array $_paramArray = [];
    /**
     * @param Connection $conn      The Connection instance to use for this query
     * @param string $sql           The SQL query to execute
     * @param int|null $queryType   The type of query to execute. This affects how placeholders are assigned and what type of result is expected. See the QUERY_* constants for possible values.
     * @param int $resultType       The type of result to return. This determines what response is stored in Query::results. See the RESULT_* constants for possible values.
     * @param string|null $name     The name of this query. This is used to identify the query in a {@see Transaction}.
     * @param string|null $setId    An identifier used to group instances under a parent {@see StatementSet}. This can be omitted in standalone PreparedStatements.
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function __construct(public Connection &$conn, public string $sql, public ?int $queryType = Query::QUERY_SELECT, public int $resultType = Query::RESULT_DISTINCT, public ?string $name = null, private ?string $setId = null) {
        parent::__construct($conn,$sql,$queryType,$resultType,$name);
        $paramMatch = [];
        if (preg_match_all("/:[A-Za-z0-9_]+/",$sql,$paramMatch) > 0){
            $this->_namedParams = $paramMatch[0];
            $this->_paramCount = count($this->_namedParams);
        }
        else {
            $this->_paramCount = preg_match_all("/\?/",$sql);
        }
    }

    /** @ignore */
    public function __clone() : void {
        parent::__clone();
        $this->clear();
    }

    /**
     * Adds a set of prepared statement parameters (either named or positional) to be bound and executed.
     *
     * @param array $paramArray    An array of associative arrays, each of which contain parameter names and values to bind to the query.
     * @param string $prefix       A prefix to prepend to the parameter names in the query. This is used to prevent collisions with other parameters in the same query. Can be omitted for standalone PreparedStatements.
     *
     * @return void
     * @throws VeloxException      If any value passed is not a null or scalar value that can be bound to a parameter.
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function addParameterSet(array $paramArray, string $prefix = '') : void {
        foreach ($paramArray as $key => $value){
            if (!is_scalar($value) && !is_null($value)){
                throw new VeloxException("Value for :".$key." is not a scalar or null.",50);
            }
            if (filter_var($key, FILTER_VALIDATE_INT) === false) {
                $paramArray[":" . $prefix . $key] = $value;
                unset($paramArray[$key]);
            }
        }
        $this->_paramArray[] = $paramArray;
    }
    /**
     * @return array An array of parameter names assigned to this instance.
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function getNamedParams() : array {
        return $this->_namedParams;
    }
    /**
     * @return array An array of all parameter sets assigned to this instance.
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function getParams() : array {
        return $this->_paramArray;
    }
    /**
     * @return int The number of parameters assigned to this instance.
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function getParamCount() : int {
        return $this->_paramCount;
    }
    /**
     * Clears all parameter sets assigned to this instance.
     *
     * @return void
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function clear() : void {
        $this->_paramArray = [];
    }
    /**
     * @return array An array containing the execution context for this query, including the base SQL and connection parameters.
     * This may be useful for debugging.
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function dumpQuery(): array {
        $dump = parent::dumpQuery();
        $dump["type"] = "PreparedStatement";
        $dump["parameters"] = $this->_paramArray;
        return $dump;
    }
    /**
     * @return string|null The set id assigned to this instance, for use by StatementSet.
     * @internal
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function getSetId() : ?string {
        return $this->setId;
    }
}
