<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;
use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;
use KitsuneTech\Velox\VeloxException;

/**
 * Query is the base class for all Velox query procedures. An instance of Query can be used to execute any SQL query the
 * underlying database supports; however, for any queries that require input, PreparedStatement or StatementSet should be
 * used instead. These classes provide automatic sanitation of input parameters, and StatementSet provides the ability to
 * execute multiple queries in a single call.
 *
 * @author KitsuneTech
 * @version 1.0 beta 1
 * @since 1.0 beta 1
 *
 * @param Connection $conn The Connection instance to use for this query
 * @param string $sql The SQL query to execute
 * @param int $queryType The type of query to execute. This affects how placeholders are assigned and what type of result is expected.
 * @param int $resultType The type of result to return. This determines what response is stored in Query::results.
 */
class Query {
    /** @var array|ResultSet|bool The results of the executed query */
    public array|ResultSet|bool $results = [];
    private array $_lastAffected = [];
    
    public function __construct(public Connection &$conn, public string $sql, public ?int $queryType = null, public int $resultType = VELOX_RESULT_ARRAY) {
        if (!$this->queryType){
            //Attempt to determine type by first keyword if query type isn't specified
            $lc_query = strtolower($this->sql);
            if (str_starts_with($lc_query,"select")){
                $this->queryType = QUERY_SELECT;
            }
            elseif (str_starts_with($lc_query,"insert")){
                $this->queryType = QUERY_INSERT;
            }
            elseif (str_starts_with($lc_query,"update")){
                $this->queryType = QUERY_UPDATE;
            }
            elseif (str_starts_with($lc_query,"delete")){
                $this->queryType = QUERY_DELETE;
            }
            elseif (str_starts_with($lc_query,"call")){
                $this->queryType = QUERY_PROC;
            }
            else {
                $this->queryType = QUERY_SELECT;
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
                if (!$stmt->execute()) {
                    throw new VeloxException('PDO Error: ' . $stmt->errorInfo(), $stmt->errorCode());
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
                        if ($parameters) {
                            $success = $stmt->execute($parameters);
                        } else {
                            $success = $stmt->execute();
                        }
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
            case QUERY_INSERT:
            case QUERY_UPDATE:
            case QUERY_PROC:
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
                if ($queryType !== QUERY_PROC) {
                    //Stored procedure calls don't have a specific query type, so treat them as both SELECT and DML.
                    //Therefore, QUERY_PROC falls through to QUERY_SELECT.
                    return $resultSet;
                }
            case QUERY_SELECT:
                $resultArray = [];
                switch ($resultType) {
                    case VELOX_RESULT_ARRAY:
                    case VELOX_RESULT_UNION:
                    case VELOX_RESULT_UNION_ALL:
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
                    case VELOX_RESULT_FIELDS:
                        $currentResult = [];
                        $columnCount = $stmt->columnCount;
                        for ($i = 0; $i < $columnCount - 1; $i++) {
                            $currentResult[] = $stmt->getColumnMeta($i);
                        }
                        return new ResultSet($currentResult);
                    case VELOX_RESULT_NONE:
                        return $resultSet;
                    default:
                        throw new VeloxException('Invalid result type constant', 56);
                }
            case QUERY_DELETE:
                //No results for DELETE queries.
                return new ResultSet([]);
            default:
                throw new VeloxException('Invalid query type constant', 57);
        }
    }
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
                                if (!($this->queryType == QUERY_PROC && str_starts_with($key, ':op_'))) {
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
                        if ($this->resultType == VELOX_RESULT_ARRAY){
                            array_merge($results,$resultSet->getRawData());
                        }
                        else if ($i == 0) {
                            $results[] = $resultSet;
                        }
                        else {
                            $results[0]->merge($resultSet, ($this->resultType === VELOX_RESULT_UNION_ALL));
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
            VELOX_RESULT_NONE => null,
            VELOX_RESULT_ARRAY => $results,
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
    
    public function getResults() : array|ResultSet|null {
        if ($this->resultType !== VELOX_RESULT_NONE && !isset($this->results)){
            throw new VeloxException("Query results not yet available",22);
        }
        else {
            return $this->results;
        }
    }
    public function getLastAffected() : array {
	    return $this->_lastAffected;
    }
	
    public function dumpQuery() : array {
        return [
            "type"=>"Query",
            "connection"=>["host"=>$this->conn->getHost(),"db"=>$this->conn->getDB(),"type"=>$this->conn->getServerType()],
            "procedure"=>$this->sql,
            "parameters"=>null
        ];
    }
}
