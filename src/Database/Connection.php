<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database;
use KitsuneTech\Velox\VeloxException;
use KitsuneTech\Velox\Database\Procedures\Query;
use KitsuneTech\Velox\Structures\ResultSet;

class Connection {
    private $_conn;
    private ?string $_host;
    private ?string $_db;
    private int $_serverType;
    private int $_port;
    private int $_connectionType;
    private bool $_inTransaction = false;
    private array $_lastAffected = [];
    public function __construct (
        string|null $host = null,
        string|null $dbName = null,
        string|null $uid = null,
        string|null $pwd = null,
        int|null $port = null,
        int $serverType = DB_MYSQL,
        int|null $connectionType = null,
        array $options = []
    ) {
        if (!$host && ($connectionType !== CONN_ODBC && $serverType !== DB_ODBC)) {
            throw new VeloxException("Database host not provided",11);
        }
        if (!$dbName && ($connectionType !== CONN_ODBC && $serverType !== DB_ODBC)) {
            throw new VeloxException("Database name not provided",12);
        }
        if (!$uid && ($connectionType !== CONN_ODBC && $serverType !== DB_ODBC)) {
            throw new VeloxException("Database user not provided",13);
        }
        if (!$pwd && ($connectionType !== CONN_ODBC && $serverType !== DB_ODBC)) {
            throw new VeloxException("Database password not provided",14);
        }
        $this->_host = $host;
        $this->_db = $dbName;
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
                        if (!extension_loaded('pdo_mysql')) {
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
                        if (!extension_loaded('pdo_sqlsrv')) {
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
                        if (!extension_loaded('pdo_odbc')) {
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
                    $connStr = urldecode(http_build_query($dsnArray + $options, '', ";"));
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
                        $this->_conn = sqlsrv_connect($mssqlHost,["Database"=>$dbName, "UID"=>$uid, "PWD"=>$pwd] + $options);
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
                if ($connectionType === CONN_ODBC){    //Fallback skips ODBC since ODBC connections require different parameters
                    if (!function_exists("odbc_connect")){
                        throw new VeloxException("This PHP installation has not been built with ODBC support.",59);
                    }
                    if ($this->_host){
                        $dsn = $this->_host;
                    }
                    else {
                        $dsn = urldecode(http_build_query($options, '', ";"));
                        if (!$uid && isset($dsn['uid'])){
                            $uid = $dsn['uid'];
                        }
                        if (!$pwd && isset($dsn['Pwd'])){
                            $pwd = $dsn['Pwd'];
                        }
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
    public function connectionInstance() : object {
        return $this->_conn;
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
        if (gettype($query) == "string"){
            $query = new Query($this,$query,$queryType,$resultType);
        }
        $query->execute();
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
