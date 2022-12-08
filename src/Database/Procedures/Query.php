<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;
use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;
use KitsuneTech\Velox\VeloxException;

/**
 * `Query` is the base class for all Velox query procedures. An instance of `Query` can be used to execute any SQL query the
 * underlying database supports; however, for any queries that require input, `PreparedStatement` or `StatementSet` should be
 * used instead. These classes provide automatic sanitation of input parameters, and `StatementSet` provides the ability to
 * execute multiple queries in a single call.
 *
 * @author KitsuneTech
 * @version 1.0 beta 1
 * @since 1.0 beta 1
 *
 */
class Query {
    /** @var int SELECT */
    public const QUERY_SELECT = 1;

    /** @var int UPDATE */
    public const QUERY_UPDATE = 2;

    /** @var int INSERT */
    public const QUERY_INSERT = 3;

    /** @var int DELETE */
    public const QUERY_DELETE = 4;

    /** @var int CALL/EXECUTE (for stored procedure) */
    public const QUERY_PROC = 5;

    /** @var int No result expected (used for DML queries) */
    const RESULT_NONE = 0;

    /** @var int Results will be stored in an array of associative arrays, each key of which is the field name for that item. */
    const RESULT_ARRAY = 1;

    /** @var int Results will be stored in an array of ResultSet objects, each of which represents one query execution. Use this for one-off queries. */
    const RESULT_DISTINCT = 2;

    /** @var int Results will be stored in a single ResultSet object, which represents the combined result of all executions of this query. This should be used in
     * {@see PreparedStatement} or {@see StatementSet} calls where an aggregate result is desired from multiple executions of the same query. */
    const RESULT_UNION = 3;

    /** @var int Only the field names are returned. Use this if you're only trying to determine which columns the query will return. */
    const RESULT_FIELDS = 4;

    private array $_lastAffected = [];

    /** @var array|ResultSet|bool The results of the executed query */
    public array|ResultSet|bool $results = [];

    /**
     * @param Connection $conn      The Connection instance to use for this query
     * @param string $sql           The SQL query to execute
     * @param int|null $queryType   The type of query to execute. This affects how placeholders are assigned and what type of result is expected. See the QUERY_* constants for possible values.
     * @param int $resultType       The type of result to return. This determines what response is stored in Query::results. See the RESULT_* constants for possible values.
     * @param string|null $name     The name of this query. This is used to identify the query in a {@see Transaction}.
     */
    public function __construct(public Connection &$conn, public string $sql, public ?int $queryType = null, public int $resultType = Query::RESULT_ARRAY, public ?string $name = null) {
        if (!$this->queryType){
            //Attempt to determine type by first keyword if query type isn't specified
            $lc_query = strtolower($this->sql);
            if (str_starts_with($lc_query,"select")){
                $this->queryType = Query::QUERY_SELECT;
            }
            elseif (str_starts_with($lc_query,"insert")){
                $this->queryType = Query::QUERY_INSERT;
            }
            elseif (str_starts_with($lc_query,"update")){
                $this->queryType = Query::QUERY_UPDATE;
            }
            elseif (str_starts_with($lc_query,"delete")){
                $this->queryType = Query::QUERY_DELETE;
            }
            elseif (str_starts_with($lc_query,"call")){
                $this->queryType = Query::QUERY_PROC;
            }
            else {
                //Assume SELECT for query that doesn't start with the above keywords. Note: this does not account for DDL queries,
                //which should not be run with Velox. Remember the principle of least privilege when delegating permissions for
                //web-accessible scripts.
                $this->queryType = Query::QUERY_SELECT;
            }
        }
    }
    public function __clone() : void {
        //A cloned Query doesn't retain the original's results.
        $this->results = [];
        $this->_lastAffected = [];
    }

    private function executeStatement(&$stmt, array &$parameters = null) : ResultSet {
        $connObj = $this->conn;
        $queryType = $this->queryType;
        $resultType = $this->resultType;
        $resultSet = new ResultSet();
        switch ($connObj->connectionType()) {
            case Connection::CONN_PDO:
                try {
                    if (!$stmt->execute()){
                        throw new VeloxException("Query execution failed: ".$stmt->errorInfo()[2]);
                    }
                }
                catch (\Exception $e) {
                    if ($stmt->errorCode() == "HY000" && $stmt->errorInfo()[1] == 2006) {
                        //Connection has gone away. Attempt to reconnect and retry.
                        $connObj->establish();
                        try {
                            if (!$stmt->execute()){
                                throw new VeloxException('PDO Error: ' . $stmt->errorInfo(), $stmt->errorInfo()[1]);
                            }
                        }
                        catch (\Exception $e) {
                            if ($stmt->errorCode() == "HY000" && $stmt->errorInfo()[1] == 2006) {
                                //Connection has gone away again. Give up.
                                throw new VeloxException("Database connection lost and could not be re-established.", 65);
                            }
                            else {
                                //Unrelated error.
                                throw new VeloxException('PDO Error: ' . $stmt->errorInfo(), $stmt->errorInfo()[1]);
                            }
                        }
                    }
                    else {
                        throw new VeloxException('PDO Error: ' . $stmt->errorInfo(), $stmt->errorInfo()[1]);
                    }
                }
                break;
            case Connection::CONN_ODBC:
                if (!odbc_execute($stmt, $parameters)) {
                    throw new VeloxException('ODBC Error: ' . odbc_errormsg(), (int)odbc_error());
                }
                break;
            case Connection::CONN_NATIVE:
                switch ($connObj->serverType()) {
                    case Connection::DB_MYSQL:
                        $success = $parameters ? $stmt->execute($parameters) : $stmt->execute();
                        if (!$success) {
                            throw new VeloxException('MySQL Error: ' . $stmt->errorInfo(), $stmt->errorCode());
                        }
                        break;
                    case Connection::DB_MSSQL:
                        if (!sqlsrv_execute($stmt)) {
                            $errors = sqlsrv_errors();
                            $errorStrings = [];
                            foreach ($errors as $error) {
                                $errorStrings[] = "SQLSTATE " . $error['SQLSTATE'] . " (" . $error['code'] . "): " . $error['message'];
                            }
                            if (count($errorStrings) > 0) {
                                throw new VeloxException("SQL Server error(s): " . implode(', ', $errorStrings), 17);
                            }
                        }
                        break;
                }
                break;
        }
        switch ($queryType) {
            case Query::QUERY_INSERT:
            case Query::QUERY_UPDATE:
            case Query::QUERY_PROC:
                // Retrieve affected indices
                switch ($connObj->connectionType()) {
                    case Connection::CONN_PDO:
                        $lastAffected = $connObj->connectionInstance()->lastInsertId();
                        break;
                    case Connection::CONN_ODBC:
                        $insertIdSql = match ($connObj->serverType()) {
                            Connection::DB_MYSQL => "SELECT LAST_INSERT_ID()",
                            Connection::DB_MSSQL => "SELECT SCOPE_IDENTITY()",
                        };
                        $insertIdStmt = $connObj->connectionInstance()->prepare($insertIdSql);
                        odbc_execute($insertIdStmt);
                        $lastAffected = odbc_result($insertIdStmt, 1);
                        break;
                    case Connection::CONN_NATIVE:
                        switch ($connObj->serverType()) {
                            case Connection::DB_MYSQL:
                                $lastAffected = $connObj->connectionInstance()->insert_id;
                                break;
                            case Connection::DB_MSSQL:
                                $insertIdStmt = sqlsrv_query($connObj->connectionInstance(), "SELECT SCOPE_IDENTITY()");
                                $lastAffected = sqlsrv_fetch_array($insertIdStmt)[0];
                        }
                        break;
                    default:
                        throw new VeloxException("Unknown connection type", 55);
                }
                $this->_lastAffected[] = $lastAffected;
                $resultSet->appendAffected([$lastAffected]);
                if ($queryType !== Query::QUERY_PROC) {
                    //Stored procedure calls don't have a specific query type, so treat them as both SELECT and DML.
                    //Therefore, QUERY_PROC falls through to QUERY_SELECT.
                    return $resultSet;
                }
            case Query::QUERY_SELECT:
                $resultArray = [];
                switch ($resultType) {
                    case Query::RESULT_ARRAY:
                    case Query::RESULT_DISTINCT:
                    case Query::RESULT_UNION:
                        switch ($connObj->connectionType()) {
                            case Connection::CONN_PDO:
                                $resultArray = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                                break;
                            case Connection::CONN_ODBC:
                                while ($row = odbc_fetch_array($stmt)) {
                                    $resultArray[] = $row;
                                }
                                break;
                            case Connection::CONN_NATIVE:
                                switch ($connObj->serverType()) {
                                    case Connection::DB_MYSQL:
                                        $resultArray = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                                        break;
                                    case Connection::DB_MSSQL:
                                        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                                            $resultArray[] = $row;
                                        }
                                        break;
                                }
                        }
                        $resultSet->merge(new ResultSet($resultArray));
                        return $resultSet;
                    case Query::RESULT_FIELDS:
                        $currentResult = [];
                        $columnCount = $stmt->columnCount;
                        for ($i = 0; $i < $columnCount - 1; $i++) {
                            $currentResult[] = $stmt->getColumnMeta($i);
                        }
                        return new ResultSet($currentResult);
                    case Query::RESULT_NONE:
                        return $resultSet;
                    default:
                        throw new VeloxException('Invalid result type constant', 56);
                }
            case Query::QUERY_DELETE:
                //DELETE queries have neither results nor affected indices.
                return new ResultSet([]);
            default:
                throw new VeloxException('Invalid query type constant', 57);
        }
    }

    /**
     * Prepares and executes the query. If the query is being run as a prepared statement with placeholders and parameters,
     * the query is prepared and executed once for each set of parameters.
     *
     * @return bool True if the query was executed successfully, false otherwise.
     * @throws VeloxException If the query cannot be prepared and/or executed successfully. The content of the exception
     * will vary depending on the nature of the error.
     */
    public function execute() : bool {
        if (!$this->sql){
            throw new VeloxException("Query SQL is not set",19);
        }
        $paramArray = [];
        $placeholders = [];
        $this->_lastAffected = [];

        //Assemble the array of placeholders to be bound
        if ($this instanceof PreparedStatement){
            $paramArray = $this->getParams();
            $namedParams = $this->getNamedParams();
            if (count($namedParams) > 0){
                for($i=0; $i<count($namedParams); $i++){
                    $placeholders[$namedParams[$i]] = null;
                }
            }
            else {
                $placeholders = array_fill(1,$this->getParamCount(),null);
            }
        }

        //Prepare the statements and bind the placeholder array (if applicable)
        try {
            $sql = $this->sql;
            switch ($this->conn->connectionType()){
                case Connection::CONN_PDO:
                    $stmt = $this->conn->connectionInstance()->prepare($sql);
                    if (count($placeholders) > 0){
                        foreach ($placeholders as $key => $value) {
                            $placeholders[$key] = $value;
                            try {
                                //PDOStatement::bindParam() is called once for each parameter.
                                //Equivalent non-PDO parameter binding tends to be done once per statement using an array of parameters, so these calls happen outside the loop.
                                $stmt->bindParam($key, $placeholders[$key]);
                            }
                            catch (\PDOException $ex) {
                                if (!($this->queryType == Query::QUERY_PROC && str_starts_with($key, ':op_'))) {
                                    throw new VeloxException('Placeholder ' . $key . ' does not exist in prepared statement SQL', 46);
                                }
                            }
                        }
                    }
                    break;
                case Connection::CONN_ODBC:
                    $stmt = odbc_prepare($this->conn->connectionInstance(),$sql);
                    break;
                case Connection::CONN_NATIVE:
                    switch ($this->_serverType){
                        case Connection::DB_MYSQL:
                            $stmt = $this->conn->connectionInstance()->prepare($sql);
                            if (count($placeholders) > 0){
                                if (!$stmt->bind_param(str_repeat("s", count($placeholders)), ...$placeholders)) {
                                    throw new VeloxException('MySQL Error: ' . $stmt->errorInfo(), (int)$stmt->errorCode());
                                };
                            }
                            break;
                        case Connection::DB_MSSQL:
                            $args = [$this->conn->connectionInstance(), $sql];
                            if (count($placeholders) > 0){
                                $args[] = $placeholders;
                            }
                            if (!($stmt = sqlsrv_prepare(...$args))){
                                $errors = sqlsrv_errors();
                                $errorStrings = [];
                                foreach ($errors as $error){
                                    $errorStrings[] = "SQLSTATE " . $error['SQLSTATE'] . " (" . $error['code'] . "): " . $error['message'];
                                }
                                if (count($errorStrings) > 0) {
                                    throw new VeloxException("SQL Server error(s): " . implode(', ', $errorStrings), 17);
                                }
                            }
                            break;
                    }
            }
        }
        catch (VeloxException $ex){
            throw new VeloxException("SQL statement failed to prepare",20,$ex);
        }

        //Execute the statement (for each parameter set, if applicable)
        $results = [];
        try {
            if (count($paramArray) > 0) {
                for ($i = 0; $i < count($paramArray); $i++) {
                    foreach ($paramArray[$i] as $key => $value) {
                        $placeholders[$key] = $value;
                    }
                    $resultSet = $this->executeStatement($stmt, $placeholders);
                    if (count($resultSet) > 0) {
                        if ($this->resultType == Query::RESULT_ARRAY){
                            array_merge($results,$resultSet->getRawData());
                        }
                        else if ($i == 0) {
                            $results[] = $resultSet;
                        }
                        else {
                            $results[0]->merge($resultSet, ($this->resultType === Query::RESULT_UNION));
                        }
                    }
                }
            }
            else {
                $resultSet = $this->executeStatement($stmt);
                if (count($resultSet)>0) {
                    $results[] = $resultSet;
                }
            }
        }
        catch (Exception $ex){
            throw new VeloxException("SQL statement failed to execute",21,$ex);
        }
        $finalResult = match ($this->resultType) {
            Query::RESULT_NONE => null,
            Query::RESULT_ARRAY => $results,
            default => $results[0] ?? new ResultSet()
        };
        $this->results = $finalResult;
        if ($this instanceof PreparedStatement){
            $this->clear();
        }
        return true;
    }
    //Magic method wrapper for execute() to make Query instance callable
    public function __invoke() : bool {
        return $this->execute();
    }
    /**
     * Returns the results of the query as executed. The return type will vary depending on which result type was set
     * for the query.
     * @return ResultSet|array|null The results of the query, or null if the query was run as QUERY_NONE.
     * @throws VeloxException If the query has not yet returned results. It may be useful to try-catch this method
     * in a sleep() loop while waiting for the query to complete.
     */
    public function getResults() : array|ResultSet|null {
        if ($this->resultType !== Query::RESULT_NONE && !isset($this->results)){
            throw new VeloxException("Query results not yet available",22);
        }
        else {
            return $this->results;
        }
    }
    /**
     * @return array An array of the last affected indices from this query. Note: due to idiosyncrasies in the way inserted ids
     * are returned by different database engines for different queries, it's best not to use this for queries that could
     * affect several rows per execution.
     */
    public function getLastAffected() : array {
	    return $this->_lastAffected;
    }

    /**
     * @return array An array containing the execution context for this query, including the base SQL and connection parameters.
     * This may be useful for debugging.
     */
    public function dumpQuery() : array {
        return [
            "type"=>"Query",
            "connection"=>["host"=>$this->conn->getHost(),"db"=>$this->conn->getDB(),"type"=>$this->conn->getServerType()],
            "procedure"=>$this->sql,
            "parameters"=>null
        ];
    }
}
