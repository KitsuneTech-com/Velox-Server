<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database;
use KitsuneTech\Velox\VeloxException;
use KitsuneTech\Velox\Database\Procedures\Query;
use KitsuneTech\Velox\Structures\ResultSet;

/**
 * Connection - A generalized database connection class
 *
 * Connection establishes a connection to a given database. Supported databases include MySQL/MariaDB,
 * Microsoft SQL Server, or any ODBC-compliant data source. The connection can be established using native extensions
 * (mysqli or sqlsrv), PDO, or ODBC. If a database type isn't specified, Connection assumes the database is MySQL/MariaDB.
 * If a connection type isn't specified, Connection will first attempt to use PDO and fall back to native extensions if
 * PDO cannot be used. ODBC connections are established purely through connection strings and ignore the database type.
 *
 * The database connection itself is established at the time of instantiation, and remains open until the Connection object is
 * destroyed. The connection can be closed manually by calling the close() method.
 *
 * @author KitsuneTech
 * @version 1.0 beta 1
 * @since 1.0 beta 1
 *
 */
class Connection {
    private $_conn;
    private ?string $_host;
    private ?string $_db;
    private int $_serverType;
    private ?int $_port;
    private ?int $_connectionType;
    private bool $_inTransaction = false;
    private array $_lastAffected = [];

    /** @var int MySQL/MariaDB */
    public const DB_MYSQL = 0;
    /** @var int Microsoft SQL Server */
    public const DB_MSSQL = 1;
    /** @var int ODBC */
    public const DB_ODBC  = 2;

    /** @var int Use native extensions */
    public const CONN_NATIVE = 0;
    /** @var int Use PDO library */
    public const CONN_PDO = 1;
    /** @var int Use ODBC functions */
    public const CONN_ODBC = 2;

    /**
     * @param string|null $host The hostname or IP address of the database server
     * @param string|null $dbName The name of the database to connect to
     * @param string|null $uid The username to use for authentication
     * @param string|null $pwd The password to use for authentication
     * @param int|null $port The port to use for the connection (defaults to the default port for the database type)
     * @param int $serverType The type of database server to connect to (see the DB_* constants in src/Support/Constants.php for available options)
     * @param int|null $connectionType The type of connection to use (see the CONN_* constants in src/Support/Constants.php for available options)
     * @param array $options An array of options to use for the connection
     * @throws VeloxException if the connection cannot be established (exception specifies the reason)
     */
    public function __construct (
        string|null $host = null,
        string|null $dbName = null,
        string|null $uid = null,
        string|null $pwd = null,
        int|null $port = null,
        int $serverType = Connection::DB_MYSQL,
        int|null $connectionType = null,
        array $options = []
    ) {
        if (!$host && ($connectionType !== Connection::CONN_ODBC && $serverType !== Connection::DB_ODBC)) {
            throw new VeloxException("Database host not provided",11);
        }
        if (!$dbName && ($connectionType !== Connection::CONN_ODBC && $serverType !== Connection::DB_ODBC)) {
            throw new VeloxException("Database name not provided",12);
        }
        if (!$uid && ($connectionType !== Connection::CONN_ODBC && $serverType !== Connection::DB_ODBC)) {
            throw new VeloxException("Database user not provided",13);
        }
        if (!$pwd && ($connectionType !== Connection::CONN_ODBC && $serverType !== Connection::DB_ODBC)) {
            throw new VeloxException("Database password not provided",14);
        }
        $this->_host = $host;
        $this->_db = $dbName;
        $this->_serverType = $serverType;
        $this->_port = $port;
        $this->_inTransaction = false;
        $this->_connectionType = $connectionType ?? Connection::CONN_PDO;   //If not provided, start with PDO and fallback where applicable

        switch ($this->_connectionType) {
            case Connection::CONN_PDO:
                $dsnArray = [];
                $connPrefix = "";
                switch ($this->_serverType){
                    case Connection::DB_MYSQL:
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
                    case Connection::DB_MSSQL:
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
                    case Connection::DB_ODBC:
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
                    $connectionType = Connection::CONN_PDO;
                }
                catch (\PDOException $ex) {
                    throw new VeloxException("PDO error: ".$ex->getMessage(),(int)$ex->getCode(),$ex);
                }
                if ($connectionType) break;
            case Connection::CONN_NATIVE:
                switch ($this->_serverType){
                    case Connection::DB_MYSQL:
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
                        $connectionType = Connection::CONN_NATIVE;
                        break;
                    case Connection::DB_MSSQL:
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
                        $connectionType = Connection::CONN_NATIVE;
                        break;
                    case Connection::DB_ODBC:
                        $connectionType = Connection::CONN_ODBC;
                        //Defer to CONN_ODBC below
                        break;
                    default:
                        throw new VeloxException("Unidentified database engine or incorrect parameters",16);
                }
                if ($connectionType && $connectionType !== Connection::CONN_ODBC) break;
            case Connection::CONN_ODBC:
                if ($connectionType === Connection::CONN_ODBC){    //Fallback skips ODBC since ODBC connections require different parameters
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
    public function __destruct(){
        if ($this->_connectionType !== Connection::CONN_PDO){
            $this->close();
        }
    }

    /**
     * Returns the internal reference to the database connection. This is only public for use by the Query class and
     * should not be used by application code.
     * @return object The database connection reference
     */
    public function connectionInstance() : object {
        return $this->_conn;
    }

    /**
     * Initiates a transaction, if supported by the database engine.
     * @return bool True if the transaction was successfully started, as indicated by the appropriate library.
     * @throws VeloxException If the database engine does not support transactions.
     */
    public function beginTransaction() : bool {
        $this->_inTransaction = true;
        switch ($this->_connectionType){
            case Connection::CONN_PDO:
                return $this->_conn->beginTransaction();
            case Connection::CONN_ODBC:
                return odbc_autocommit($this->_conn,false);
            case Connection::CONN_NATIVE:
                switch ($this->_serverType){
                    case Connection::DB_MYSQL:
                        return $this->_conn->begin_transaction();
                    case Connection::DB_MSSQL:
                        return sqlsrv_begin_transaction($this->_conn);
                }
            default:
                throw new VeloxException("Unknown connection type",55);
        }
    }

    /** Identifies whether the connection has an active transaction.
     * @return bool True if a transaction exists.
     */
    public function inTransaction() : bool {
        return $this->_inTransaction;
    }

    /** Sets a transaction savepoint for rollback.
     * @return bool True if the savepoint was successfully set.
     * @throws VeloxException If no active transaction exists.
     */
    public function setSavepoint() : bool {
        if (!$this->_inTransaction){
            throw new VeloxException("Transactional method called without active transaction",18);
        }
        switch ($this->_connectionType){
            case Connection::CONN_PDO:
            case Connection::CONN_ODBC:
                switch ($this->_serverType){
                    case Connection::DB_MYSQL:
                        $savepointQuery = "SAVEPOINT currentQuery";
                        break;
                    case Connection::DB_MSSQL:
                        $savepointQuery = "SAVE TRANSACTION currentQuery";
                        break;
                    default:
                        throw new VeloxException("Savepoint not supported on this database engine",19);
                }
                return $this->_conn->exec($savepointQuery) !== false;
            case Connection::CONN_NATIVE:
                switch ($this->_serverType){
                    case Connection::DB_MYSQL:
                        return $this->_conn->savepoint("currentQuery");
                    case Connection::DB_MSSQL:
                        return (bool)sqlsrv_query($this->_conn,"SAVE TRANSACTION currentQuery");
                }
            default:
                throw new VeloxException("Unknown connection type",55);
        }
    }

    /** Rolls back the active transaction.
     * @param bool $toSavepoint If true, rolls back to the last savepoint. If false or unspecified, rolls back the entire transaction.
     * @return bool True if the rollback was successful.
     * @throws VeloxException If no active transaction exists.
     */
    public function rollBack(bool $toSavepoint = false) : bool {
        if (!$this->_inTransaction){
            throw new VeloxException("Transactional method called without active transaction",18);
        }
        if ($toSavepoint){
            switch ($this->_serverType){
                case Connection::DB_MYSQL:
                    $rollbackQuery = "ROLLBACK TO SAVEPOINT currentQuery";
                    break;
                case Connection::DB_MSSQL:
                    $rollbackQuery = "ROLLBACK TRANSACTION currentQuery";
                    break;
            }
            switch ($this->_connectionType){
                case Connection::CONN_PDO:
                    return $this->_conn->exec($rollbackQuery) !== false;
                case Connection::CONN_ODBC:
                    return odbc_exec($this->_conn,$rollbackQuery) !== false;
                case Connection::CONN_NATIVE:
                    switch ($this->_serverType){
                        case Connection::DB_MYSQL:
                            return $this->_conn->rollback(0,"currentQuery");
                        case Connection::DB_MSSQL:
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
                case Connection::CONN_PDO:
                    return $this->_conn->rollBack();
                case Connection::CONN_ODBC:
                    return odbc_rollback($this->_conn);
                case Connection::CONN_NATIVE:
                    switch ($this->_serverType){
                        case Connection::DB_MYSQL:
                            return $this->_conn->rollback();
                        case Connection::DB_MSSQL:
                            return sqlsrv_rollback($this->_conn);
                        default:
                            throw new VeloxException("Invalid database type constant", 10);
                    }
                default:
                    throw new VeloxException("Unknown connection type",55);
            }
        }
    }

    /** Commits the active transaction.
     * @return bool True if the commit was successful.
     * @throws VeloxException If no active transaction exists.
     */
    public function commit() : bool {
        if (!$this->_inTransaction){
            throw new VeloxException("Transactional method called without active transaction",18);
        }
        $success = false;
        switch ($this->_connectionType){
            case Connection::CONN_PDO:
                $success = $this->_conn->commit();
                break;
            case Connection::CONN_ODBC:
                $success = odbc_commit($this->_conn);
                break;
            case Connection::CONN_NATIVE:
                switch ($this->_serverType){
                    case Connection::DB_MYSQL:
                        $success = $this->_conn->commit();
                        break;
                    case Connection::DB_MSSQL:
                        $success = sqlsrv_commit($this->_conn);
                        break;
                }
        }
        $this->_inTransaction = !$success;
        return (bool)$success;
    }

    /** Returns the server type constant for this connection. See the DB_* constants in src/Support/Constants.php.
     * @return int The server type constant.
     */
    public function serverType() : int {
        return $this->_serverType;
    }

    /** Returns the connection type constant for this connection. See the CONN_* constants in src/Support/Constants.php.
     * @return int The connection type constant.
     */
    public function connectionType() : int {
        return $this->_connectionType;
    }

    /** Returns the last affected indices of the most recent query (equivalent to LAST_INSERT_ID in MySQL). Note: calling
     * this method will clear the stored indices; if you need to use them more than once, store them in a variable.
     * @return array The last affected indices.
     */
    public function getLastAffected() : array {
        $lastAffected = $this->_lastAffected;
        $this->_lastAffected = [];
        return $lastAffected;
    }

    /** Closes the active connection. Note: once the connection is closed, it cannot be reopened. PDO connections remain
     * open until the object is destroyed, so this method cannot be used to close these; therefore, the preferred means
     * to close a database connection is to destroy the object.
     * @return bool True if the connection was successfully closed.
     * @throws VeloxException If this method is attempted on a PDO connection.
     */
    public function close() : bool {
        switch ($this->_connectionType){
            case Connection::CONN_PDO:
                throw new VeloxException("PDO connection cannot be closed with the close() method",52);
            case Connection::CONN_ODBC:
                odbc_close($this->_conn);
                return true;
            case Connection::CONN_NATIVE:
                switch ($this->_serverType){
                    case Connection::DB_MYSQL:
                        return $this->_conn->close();
                    case Connection::DB_MSSQL:
                        return sqlsrv_close($this->_conn);
                }
            default:
                throw new VeloxException("Unknown connection type",55);
        }
    }
    /** Executes a given query. This can either be an instance of the Query class or a standalone SQL query string. If the
     * latter is passed, a new Query instance will be created from it.
     * @param Query|string $query The query to execute.
     * @param int $queryType The type of query to execute (default is QUERY_SELECT). See the QUERY_* constants in src/Support/Constants.php.
     * @param int $resultType The type of result to return (default is VELOX_RESULT_ARRAY). See the VELOX_RESULT_* constants in src/Support/Constants.php.
     * @return ResultSet|array|bool The result of the query, or false if the query failed.
     * @throws VeloxException If the query failed. The exception will be passed through from the Query class.
     */
    public function execute(string|Query $query, int $queryType = QUERY_SELECT, int $resultType = VELOX_RESULT_ARRAY) : ResultSet|array|bool {
        if (gettype($query) == "string"){
            $query = new Query($this,$query,$queryType,$resultType);
        }
        $query->execute();
        return $query->getResults();
    }

    /** Returns the specified host for this connection.
     * @return string The host as originally specified.
     */
    public function getHost() : string {
        return $this->_host;
    }

    /** Returns the database name for this connection.
     * @return string The database name as originally specified.
     */
    public function getDB() : string {
        return $this->_db;
    }

    /** Returns the server type for this connection, in user-readable format (presently either MySQL or SQL Server).
     * @return string The server type.
     */
    public function getServerType() : string {
        return match ($this->_serverType) {
            Connection::DB_MYSQL => "MySQL",
            Connection::DB_MSSQL => "SQL Server",
            default => "Unknown",
        };
    }
}
