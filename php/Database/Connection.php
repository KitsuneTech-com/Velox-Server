<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database;
use KitsuneTech\Velox\VeloxException;
use KitsuneTech\Velox\Database\Procedures\{Query,PreparedStatement};
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;
                       
class Connection {
    private $_conn;
    private string $_host;
    private string $_db;
    private int $_serverType;
    private bool $_inTransaction = false;
    private array $_lastAffected = [];
    private string $_timestampFileLoc;
    private bool $_usePDO = false;
    public function __construct (
        string|null $host = null,
        string|null $db_name = null,
        string|null $uid = null,
        string|null $pwd = null,
        int|null $port = null,
        int $serverType = DB_MYSQL,
        int|null $connectionType = null,
        array $options = []
    ) {
        if (!$host && ($connectionType !== CONN_ODBC || $serverType !== DB_ODBC)) {
            throw new VeloxException("Database host not provided",11);
        }
        if (!$db_name && ($connectionType !== CONN_ODBC || $serverType !== DB_ODBC)) {
            throw new VeloxException("Database name not provided",12);
        }
        if (!$uid && ($connectionType !== CONN_ODBC || $serverType !== DB_ODBC)) {
            throw new VeloxException("Database user not provided",13);
        }
        if (!$pwd && ($connectionType !== CONN_ODBC || $serverType !== DB_ODBC)) {
            throw new VeloxException("Database password not provided",14);
        }
        $this->_host = $host;
        $this->_db = $db_name;
        $this->_serverType = $serverType;
        $this->_port = $port;
        $this->_inTransaction = false;
        $this->_connectionType = $connectionType ?? CONN_PDO;   //If not provided, start with PDO and fallback where applicable

        switch ($this->_connectionType) {
            case CONN_PDO:
                $dsnArray = [];
                $connPrefix = "";
                switch ($this->_serverType){
                    case DB_MYSQL:
                        $connPrefix = "mysql:";
                        if ($connectionType === CONN_PDO && !extension_loaded('pdo_mysql')) {
                            throw new VeloxException("pdo_mysql required to connect to MySQL using PDO.",53);
                        }
                        $dsnArray = [
                            'host' => $this->_host,
                            'dbname' => $this->_db
                        ];
                        if ($this->_port) {
                            $dsnArray['port'] = $this->_port;
                        }
                        break;
                    case DB_MSSQL:
                        $connPrefix = "sqlsrv:";
                        if ($connectionType === CONN_PDO && !extension_loaded('pdo_sqlsrv')) {
                            throw new VeloxException("pdo_sqlsrv required to connect to SQL Server using PDO.",53);
                        }
                        $dsnArray = [
                            'Server' => $this->_host,
                            'Database' => $this->_db
                        ];
                        if ($this->_port) {
                            $dsnArray['Server'].=",$this->_port";
                        }
                        break;
                    case DB_ODBC:
                        $connPrefix = "odbc:";
                        if ($connectionType === CONN_PDO && !extension_loaded('pdo_odbc')) {
                            throw new VeloxException("pdo_odbc required to connect to ODBC using PDO.",53);
                        }
                        if ($this->_host){
                            $dsnArray['dsn'] = $this->_host;
                        }
                        else {
                            $dsnArray = $options;
                        }
                        break;
                }
                try {
                    $connStr = http_build_query($dsnArray + $options, '', ";");
                    $this->_conn = new \PDO("$connPrefix$connStr", $uid, $pwd);
                    $this->_conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                    $connectionType = CONN_PDO;
                }
                catch (\PDOException $ex) {
                    throw new VeloxException("PDO error: ".$ex->getMessage(),(int)$ex->getCode(),$ex);
                }
                if ($connectionType) break;
            case CONN_NATIVE:
                switch ($this->_serverType){
                    case DB_MYSQL:
                        $connArgs = [
                            $this->_host,
                            $uid,
                            $pwd,
                            $this->_db
                        ];
                        if ($this->_port) {
                            $connArgs[] = $this->_port;
                        }
                        $this->_conn = new \mysqli(...$connArgs);
                        if ($this->_conn->connect_error) {
                            throw new VeloxException("MySQLi error: ".$this->_conn->connect_error,(int)$this->_conn->connect_errno);
                        }
                        $connectionType = CONN_NATIVE;
                        break;
                    case DB_MSSQL:
                        if (!extension_loaded('sqlsrv')) {
                            throw new VeloxException("SQL Server native connections require the sqlsrv extension to be loaded.",54);
                        }
                        $mssqlHost = $this->_host;
                        if ($this->_port) {
                            $mssqlHost .= ",$this->_port";
                        }
                        $this->_conn = sqlsrv_connect($mssqlHost,["Database"=>$db_name, "UID"=>$uid, "PWD"=>$pwd] + $options);
                        if (($errors = sqlsrv_errors(SQLSRV_ERR_ALL))){
                            if (!$this->_serverType){
                                throw new VeloxException("Unidentified database engine or incorrect parameters",16);
                            }
                            $errorStrings = [];
                            foreach ($errors as $error){
                                if ($error['code'] != "5701" && $error['code'] != "5703") {
                                    //5701 and 5703 are informational and don't actually indicate an error.
                                    $errorStrings[] = "SQLSTATE " . $error['SQLSTATE'] . " (" . $error['code'] . "): " . $error['message'];
                                }
                            }
                            if (count($errorStrings) > 0) {
                                throw new VeloxException("SQL Server error(s): " . implode(', ', $errorStrings), 17);
                            }
                        }
                        $connectionType = CONN_NATIVE;
                        break;
                    case DB_ODBC:
                        $connectionType = CONN_ODBC;
                        //Defer to CONN_ODBC below
                        break;
                    default:
                        throw new VeloxException("Unidentified database engine or incorrect parameters",16);
                }
                if ($connectionType && $connectionType !== CONN_ODBC) break;
            case CONN_ODBC:
                if (!$connectionType === CONN_ODBC){    //Fallback skips ODBC since ODBC connections require different parameters
                    if (!function_exists("odbc_connect")){
                        throw new VeloxException("This PHP installation has not been built with ODBC support.",59);
                    }
                    if ($this->_host){
                        $dsn = $this->_host;
                    }
                    else {
                        $dsn = http_build_query($options, '', ";");
                    }
                    $this->_conn = odbc_connect($dsn,$uid,$pwd);
                    if (!$this->_conn){
                        throw new VeloxException("ODBC error: ".odbc_errormsg(), (int)odbc_error());
                    }
                }
                if ($connectionType) break;
            default:
                throw new VeloxException("Unknown connection type",55);
        }
    }
    public function beginTransaction() : bool {
        $this->_inTransaction = true;
        switch ($this->_connectionType){
            case CONN_PDO:
                return $this->_conn->beginTransaction();
            case CONN_ODBC:
                return odbc_autocommit($this->_conn,false);
            case CONN_NATIVE:
                switch ($this->_serverType){
                    case DB_MYSQL:
                        return $this->_conn->begin_transaction();
                    case DB_MSSQL:
                        return sqlsrv_begin_transaction($this->_conn);
                }
            default:
                throw new VeloxException("Unknown connection type",55);
        }
    }
    public function inTransaction() : bool {
        return $this->_inTransaction;
    }
    public function setSavepoint() : bool {
        if (!$this->_inTransaction){
            throw new VeloxException("Transactional method called without active transaction",18);
        }
        switch ($this->_connectionType){
            case CONN_PDO:
            case CONN_ODBC:
                switch ($this->_serverType){
                    case DB_MYSQL:
                        $savepointQuery = "SAVEPOINT currentQuery";
                        break;
                    case DB_MSSQL:
                        $savepointQuery = "SAVE TRANSACTION currentQuery";
                        break;
                    default:
                        throw new VeloxException("Savepoint not supported on this database engine",19);
                }
                return $this->_conn->exec($savepointQuery) !== false;
            case CONN_NATIVE:
                switch ($this->_serverType){
                    case DB_MYSQL:
                        return $this->_conn->savepoint("currentQuery");
                    case DB_MSSQL:
                        return (bool)sqlsrv_query($this->_conn,"SAVE TRANSACTION currentQuery");
                }
            default:
                throw new VeloxException("Unknown connection type",55);
        }
    }
    public function rollBack(bool $toSavepoint = false) : bool {
        if (!$this->_inTransaction){
            throw new VeloxException("Transactional method called without active transaction",18);
        }
        if ($toSavepoint){
            switch ($this->_serverType){
                case DB_MYSQL:
                    $rollbackQuery = "ROLLBACK TO SAVEPOINT currentQuery";
                    break;
                case DB_MSSQL:
                    $rollbackQuery = "ROLLBACK TRANSACTION currentQuery";
                    break;
            }
            switch ($this->_connectionType){
                case CONN_PDO:
                    return $this->_conn->exec($rollbackQuery) !== false;
                case CONN_ODBC:
                    return odbc_exec($this->_conn,$rollbackQuery) !== false;
                case CONN_NATIVE:
                    switch ($this->_serverType){
                        case DB_MYSQL:
                            return $this->_conn->rollback(0,"currentQuery");
                        case DB_MSSQL:
                            return sqlsrv_query($this->_conn,$rollbackQuery);
                        default:
                            throw new VeloxException("Invalid database type constant",10);
                    }
                default:
                    throw new VeloxException("Unknown connection type",55);
            }
        }
        else {
            switch ($this->_connectionType){
                case CONN_PDO:
                    return $this->_conn->rollBack();
                case CONN_ODBC:
                    return odbc_rollback($this->_conn);
                case CONN_NATIVE:
                    switch ($this->_serverType){
                        case DB_MYSQL:
                            return $this->_conn->rollback();
                        case DB_MSSQL:
                            return sqlsrv_rollback($this->_conn);
                        default:
                            throw new VeloxException("Invalid database type constant", 10);
                    }
                default:
                    throw new VeloxException("Unknown connection type",55);
            }
        }
    }
    public function commit() : bool {
        if (!$this->_inTransaction){
            throw new VeloxException("Transactional method called without active transaction",18);
        }
        $success = false;
        switch ($this->_connectionType){
            case CONN_PDO:
                $success = $this->_conn->commit();
                break;
            case CONN_ODBC:
                $success = odbc_commit($this->_conn);
                break;
            case CONN_NATIVE:
                switch ($this->_serverType){
                    case DB_MYSQL:
                        $success = $this->_conn->commit();
                        break;
                    case DB_MSSQL:
                        $success = sqlsrv_commit($this->_conn);
                        break;
                }
        }
        $this->_inTransaction = !$success;
        return (bool)$success;
    }
    public function serverType() : int {
        return $this->_serverType;
    }
    public function connectionType() : int {
        return $this->_connectionType;
    }

    public function getLastAffected() : array {     //Note: the side effect of calling this method is that the array of previously affected ids will
        $lastAffected = $this->_lastAffected;       //be cleared.
        $this->_lastAffected = [];
        return $lastAffected;
    }
    
    public function close() : bool {
        switch ($this->_connectionType){
            case CONN_PDO:
                throw new VeloxException("PDO connection cannot be closed with the close() method",52);
            case CONN_ODBC:
                odbc_close($this->_conn);
                return true;
            case CONN_NATIVE:
                switch ($this->_serverType){
                    case DB_MYSQL:
                        return $this->_conn->close();
                    case DB_MSSQL:
                        return sqlsrv_close($this->_conn);
                }
            default:
                throw new VeloxException("Unknown connection type",55);
        }
    }
    
    public function execute(string|Query $query, int $queryType = QUERY_SELECT, int $resultType = VELOX_RESULT_ARRAY) : ResultSet|array|bool {
        function executeStatement(&$connObj, &$stmt, $queryType, $resultType, &$parameters = null) : ResultSet|null{
            switch ($connObj->_connectionType) {
                case CONN_PDO:
                    if (!$stmt->execute()) {
                        throw new VeloxException('PDO Error: ' . $stmt->errorInfo(), $stmt->errorCode());
                    }
                    break;
                case CONN_ODBC:
                    if (!odbc_execute($stmt, $parameters)) {
                        throw new VeloxException('ODBC Error: ' . odbc_errormsg(), (int)odbc_error());
                    }
                    break;
                case CONN_NATIVE:
                    switch ($connObj->_serverType) {
                        case DB_MYSQL:
                            if ($parameters) {
                                $success = $stmt->execute($parameters);
                            } else {
                                $success = $stmt->execute();
                            }
                            if (!$success) {
                                throw new VeloxException('MySQL Error: ' . $stmt->errorInfo(), $stmt->errorCode());
                            }
                            break;
                        case DB_MSSQL:
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
                    switch ($connObj->_connectionType) {
                        case CONN_PDO:
                            $connObj->_lastAffected[] = $connObj->_conn->lastInsertId();
                        case CONN_ODBC:
                            $insertIdSql = match ($connObj->_serverType) {
                                DB_MYSQL => "SELECT LAST_INSERT_ID()",
                                DB_MSSQL => "SELECT SCOPE_IDENTITY()",
                            };
                            $insertIdStmt = $connObj->_conn->prepare($insertIdSql);
                            odbc_execute($insertIdStmt);
                            $connObj->_lastAffected[] = odbc_result($insertIdStmt, 1);
                            break;
                        case CONN_NATIVE:
                            switch ($connObj->_serverType) {
                                case DB_MYSQL:
                                    $connObj->_lastAffected[] = $connObj->_conn->insert_id;
                                    break;
                                case DB_MSSQL:
                                    $insertIdStmt = sqlsrv_query($connObj->_conn, "SELECT SCOPE_IDENTITY()");
                                    $connObj->_lastAffected[] = sqlsrv_fetch_array($insertIdStmt)[0];
                            }
                            break;
                        default:
                            throw new VeloxException("Unknown connection type", 55);
                    }
                    return null;
                case QUERY_SELECT:
                    $resultArray = [];
                    switch ($resultType) {
                        case VELOX_RESULT_ARRAY:
                        case VELOX_RESULT_UNION:
                        case VELOX_RESULT_UNION_ALL:
                            switch ($connObj->_connectionType) {
                                case CONN_PDO:
                                    $resultArray = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                                    break;
                                case CONN_ODBC:
                                    while ($row = odbc_fetch_array($stmt)) {
                                        $resultArray[] = $row;
                                    }
                                    break;
                                case CONN_NATIVE:
                                    switch ($connObj->_serverType) {
                                        case DB_MYSQL:
                                            $resultArray = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                                            break;
                                        case DB_MSSQL:
                                            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                                                $resultArray[] = $row;
                                            }
                                            break;
                                    }
                            }
			    $results = new ResultSet($resultArray);
                            return $results;
                        case VELOX_RESULT_FIELDS:
                            $currentResult = [];
                            $columnCount = $stmt->columnCount;
                            for ($i = 0; $i < $columnCount - 1; $i++) {
                                $currentResult[] = $stmt->getColumnMeta($i);
                            }
                            $results = new ResultSet($currentResult);
			    return $results;
                        default:
                            throw new VeloxException('Invalid result type constant', 56);
                    }
                default:
                    throw new VeloxException('Invalid query type constant', 57);

            }
        }

        if (gettype($query) == "string"){
            $query = new Query($this,$query,$queryType,$resultType);
        }
        else {
            $queryType = $query->queryType;
            $resultType = $query->resultType;
        }
        if (!$query->sql){
            throw new VeloxException("Query SQL is not set",19);
        }
        $paramArray = [];
        $placeholders = [];

        //Assemble the array of placeholders to be bound
        if ($query instanceof PreparedStatement){
            $paramArray = $query->getParams();
            $namedParams = $query->getNamedParams();
            if (count($namedParams) > 0){
                for($i=0; $i<count($namedParams); $i++){
                        $placeholders[$namedParams[$i]] = null;
                }
            }
            else {
                $placeholders = array_fill(1,$query->getParamCount(),null);
            }
        }

        //Prepare the statements and bind the placeholder array (if applicable)
        try {
            $sql = $query->sql;
            switch ($this->_connectionType){
                case CONN_PDO:
                    $stmt = $this->_conn->prepare($sql);
                    if (count($placeholders) > 0){
                        foreach ($placeholders as $key => $value) {
                            $placeholders[$key] = $value;
                            try {
                                //PDOStatement::bindParam() is called once for each parameter.
                                //Equivalent non-PDO parameter binding tends to be done once per statement using an array of parameters, so these calls happen outside the loop.
                                $stmt->bindParam($key, $placeholders[$key]);
                            }
                            catch (\PDOException $ex) {
                                if (!($queryType == QUERY_PROC && str_starts_with($key, ':op_'))) {
                                    throw new VeloxException('Placeholder ' . $key . ' does not exist in prepared statement SQL', 46);
                                }
                            }
                        }
                    }
                    break;
                case CONN_ODBC:
                    $stmt = odbc_prepare($this->_conn,$sql);
                    break;
                case CONN_NATIVE:
                    switch ($this->_serverType){
                        case DB_MYSQL:
                            $stmt = $this->_conn->prepare($sql);
                            if (count($placeholders) > 0){
                                if (!$stmt->bind_param(str_repeat("s", count($placeholders)), ...$placeholders)) {
                                    throw new VeloxException('MySQL Error: ' . $stmt->errorInfo(), (int)$stmt->errorCode());
                                };
                            }
                            break;
                        case DB_MSSQL:
                            $args = [$this->_conn, $sql];
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
                    $resultSet = executeStatement($this, $stmt, $queryType, $resultType, $placeholders);
                    if ($resultSet) {
			if ($resultType == VELOX_RESULT_ARRAY){
			    array_merge($results,$resultSet->getRawData());
			}
                        else if ($i == 0) {
                            $results[] = $resultSet;
                        }
			else {
                            $results[0]->merge($resultSet, ($resultType == VELOX_RESULT_UNION_ALL));
                        }
                    }
                }
            }
            else {
                $resultSet = executeStatement($this, $stmt, $queryType, $resultType);
                if ($resultSet) {
                    $results[] = $resultSet;
                }
            }
        }
        catch (VeloxException $ex){
            throw new VeloxException("SQL statement failed to execute",21,$ex);
        }
	switch ($resultType){
		case VELOX_RESULT_NONE:
			return true;
		case VELOX_RESULT_ARRAY:
			return $results;
		default:
			return $results[0];
	}
    }
    public function getHost() : string {
        return $this->_host;
    }
    public function getDB() : string {
        return $this->_db;
    }
    public function getServerType() : string {
        return match ($this->_serverType) {
            DB_MYSQL => "MySQL",
            DB_MSSQL => "SQL Server",
            default => "Unknown",
        };
    }
}
