<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database;
use KitsuneTech\Velox\VeloxException;
use KitsuneTech\Velox\Database\Procedures\Query;
use KitsuneTech\Velox\Structures\ResultSet;

/**
 * `Connection` - A generalized database connection class
 *
 * `Connection` establishes a connection to a given database. Supported databases include MySQL/MariaDB,
 * Microsoft SQL Server, or any ODBC-compliant data source. The connection can be established using native extensions
 * (mysqli or sqlsrv), PDO, or ODBC. If a database type isn't specified, Connection assumes the database is MySQL/MariaDB.
 * If a connection type isn't specified, `Connection` will first attempt to use PDO and fall back to native extensions if
 * PDO cannot be used. ODBC connections are established purely through connection strings and ignore the database type.
 *
 * The database connection itself is established at the time of instantiation, and remains open until the `Connection` object is
 * destroyed. The connection can be closed manually by calling the `close()` method.
 *
 * @author KitsuneTech
 * @version 1.0 beta 1
 * @since 1.0 beta 1
 *
 */
class Connection {
    //Class constants

    /** @var int MySQL/MariaDB database*/
    public const DB_MYSQL = 0;
    /** @var int Microsoft SQL Server database */
    public const DB_MSSQL = 1;
    /** @var int ODBC data source */
    public const DB_ODBC  = 2;

    /** @var int Autodetect extension to use (note: this will slow the initial connection) */
    public const CONN_AUTO = 0;
    /** @var int Use native extensions */
    public const CONN_NATIVE = 1;
    /** @var int Use PDO library */
    public const CONN_PDO = 2;
    /** @var int Use ODBC functions */
    public const CONN_ODBC = 3;

    //Class properties
    private $_conn;
    private bool $_inTransaction = false;
    private array $_lastAffected = [];

    /**
     * @param string|null $host             The hostname or IP address of the database server
     * @param string|null $db               The name of the database to connect to
     * @param string|null $uid              The username to use for authentication
     * @param string|null $pwd              The password to use for authentication
     * @param int|null $port                The port to use for the connection (defaults to the default port for the database type)
     * @param int $serverType               The type of database server to connect to (see the DB_ constants above)
     * @param int|null $connectionType      The type of connection to use (see the CONN_ constants above)
     * @param array $options                An array of options to use for the connection (as would be defined in a DSN connection string)
     *
     * @throws VeloxException if the connection cannot be established (exception specifies the reason)
     */
    public function __construct (
        private string|null $host = null,
        private string|null $db = null,
        private string|null $uid = null,
        private string|null $pwd = null,
        private int|null $port = null,
        private int $serverType = self::DB_MYSQL,
        private int|null $connectionType = self::CONN_PDO,
        private array $options = []
    )
    {

        if ($connectionType !== self::CONN_ODBC && $serverType !== self::DB_ODBC) {
            if (!$this->host) throw new VeloxException("Database host not provided", 11);
            if (!$this->db) throw new VeloxException("Database name not provided", 12);
            if (!$this->uid) throw new VeloxException("Database username not provided", 13);
            if (!$this->pwd) throw new VeloxException("Database password not provided", 14);
        }
        $this->establish();
    }
    public function establish() : void {
        $connected = false;
        switch ($this->connectionType) {
            case self::CONN_AUTO:
            case self::CONN_PDO:
                if (class_exists("PDO")) {
                    $dsnArray = [];
                    $connPrefix = "";
                    switch ($this->serverType) {
                        case self::DB_MYSQL:
                            $connPrefix = "mysql:";
                            if (!extension_loaded('pdo_mysql')) {
                                throw new VeloxException("pdo_mysql required to connect to MySQL using PDO.", 53);
                            }
                            $dsnArray = [
                                'host' => $this->host,
                                'dbname' => $this->db
                            ];
                            if ($this->port) {
                                $dsnArray['port'] = $this->port;
                            }
                            break;
                        case self::DB_MSSQL:
                            $connPrefix = "sqlsrv:";
                            if (!extension_loaded('pdo_sqlsrv')) {
                                throw new VeloxException("pdo_sqlsrv required to connect to SQL Server using PDO.", 53);
                            }
                            $dsnArray = [
                                'Server' => $this->host,
                                'Database' => $this->db
                            ];
                            if ($this->port) {
                                $dsnArray['Server'] .= ",$this->port";
                            }
                            break;
                        case self::DB_ODBC:
                            $connPrefix = "odbc:";
                            if (!extension_loaded('pdo_odbc')) {
                                throw new VeloxException("pdo_odbc required to connect to ODBC using PDO.", 53);
                            }
                            if ($this->host) {
                                $dsnArray['dsn'] = $this->host;
                            } else {
                                $dsnArray = $this->options;
                            }
                            break;
                    }
                    try {
                        $connStr = urldecode(http_build_query($dsnArray + $this->options, '', ";"));
                        $this->_conn = new \PDO("$connPrefix$connStr", $this->uid, $this->pwd);
                        $this->_conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                        $connected = true;
                    }
                    catch (\PDOException $ex) {
                        if ($this->connectionType === self::CONN_PDO) {
                            throw new VeloxException("PDO error: " . $ex->getMessage(), (int)$ex->getCode(), $ex);
                        }
                    }
                    if ($connected) {
                        $this->connectionType = self::CONN_PDO;
                        break;
                    }
                }
                elseif ($this->connectionType === self::CONN_PDO){
                    throw new VeloxException("The PDO extension is not installed.",79);
                }
            case self::CONN_NATIVE:
                switch ($this->serverType){
                    case self::DB_MYSQL:
                        if (extension_loaded('mysqli')) {
                            $connArgs = [
                                $this->host,
                                $this->uid,
                                $this->pwd,
                                $this->db
                            ];
                            if ($this->port) {
                                $connArgs[] = $this->port;
                            }
                            $this->_conn = new \mysqli(...$connArgs);
                            if ($this->_conn->connect_error) {
                                throw new VeloxException("MySQLi error: " . $this->_conn->connect_error, (int)$this->_conn->connect_errno);
                            }
                            $this->connectionType = self::CONN_NATIVE;
                            $connected = true;
                        }
                        elseif ($this->connectionType === self::CONN_NATIVE) {
                            throw new VeloxException("The mysqli extension is required to connect to MySQL using CONN_NATIVE.", 64);
                        }
                        break;
                    case self::DB_MSSQL:
                        if (extension_loaded('sqlsrv')) {
                            $mssqlHost = $this->host;
                            if ($this->port) {
                                $mssqlHost .= ",$this->port";
                            }
                            $this->_conn = sqlsrv_connect($mssqlHost,["Database"=>$this->db, "UID"=>$this->uid, "PWD"=>$this->pwd] + $this->options);
                            if (($errors = sqlsrv_errors(SQLSRV_ERR_ALL))){
                                if (!$this->serverType){
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
                            $connectionType = self::CONN_NATIVE;
                            $connected = true;
                        }
                        elseif ($this->connectionType === self::CONN_NATIVE) {
                            throw new VeloxException("SQL Server native connections require the sqlsrv extension to be loaded.",54);
                        }
                        break;
                    case self::DB_ODBC:
                        //Defer to CONN_ODBC below
                        break;
                    default:
                        throw new VeloxException("Unidentified database engine or incorrect parameters",16);
                }
                if ($connected) break;
            case self::CONN_ODBC:
                if ($this->connectionType === self::CONN_ODBC){    //Fallback skips ODBC since ODBC connections require different parameters
                    if (!function_exists("odbc_connect")){
                        throw new VeloxException("This PHP installation has not been built with ODBC support.",59);
                    }
                    if ($this->host){
                        $dsn = $this->host;
                    }
                    else {
                        $dsn = urldecode(http_build_query($this->options, '', ";"));
                        if (!$this->uid && isset($this->options['uid'])){
                            $this->uid = $this->options['uid'];
                        }
                        if (!$this->pwd && isset($this->options['Pwd'])){
                            $this->pwd = $this->options['Pwd'];
                        }
                    }
                    $this->_conn = odbc_connect($dsn,$this->uid,$this->pwd);
                    if (!$this->_conn){
                        throw new VeloxException("ODBC error: ".odbc_errormsg(), (int)odbc_error());
                    }
                    else {
                        $connected = true;
                    }
                }
                if ($connected) break;
            default:
                throw new VeloxException("Connection type not supported or extension not available",55);
        }
    }
    public function __destruct(){
        if ($this->connectionType !== self::CONN_PDO){
            $this->close();
        }
    }

    /**
     * Returns the internal reference to the database connection. This is only public for use by the `Query` class and
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
        switch ($this->connectionType){
            case self::CONN_PDO:
                try {
                    $returnVal = $this->_conn->beginTransaction();
                }
                catch (\PDOException $ex){
                    if ($ex->getCode() == "HY000" && str_contains($ex->getMessage(),"gone away")){
                        try {
                            $this->establish();
                            $returnVal = $this->_conn->beginTransaction();
                        }
                        catch (\PDOException $ex){
                            throw new VeloxException("PDO error: " . $ex->getMessage(), (int)$ex->getCode(), $ex);
                        }
                    }
                }
                return $returnVal;
                break;
            case self::CONN_ODBC:
                return odbc_autocommit($this->_conn,false);
            case self::CONN_NATIVE:
                switch ($this->serverType){
                    case self::DB_MYSQL:
                        return $this->_conn->begin_transaction();
                    case self::DB_MSSQL:
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
        switch ($this->connectionType){
            case self::CONN_PDO:
            case self::CONN_ODBC:
                switch ($this->serverType){
                    case self::DB_MYSQL:
                        $savepointQuery = "SAVEPOINT currentQuery";
                        break;
                    case self::DB_MSSQL:
                        $savepointQuery = "SAVE TRANSACTION currentQuery";
                        break;
                    default:
                        throw new VeloxException("Savepoint not supported on this database engine",19);
                }
                return $this->_conn->exec($savepointQuery) !== false;
            case self::CONN_NATIVE:
                switch ($this->serverType){
                    case self::DB_MYSQL:
                        return $this->_conn->savepoint("currentQuery");
                    case self::DB_MSSQL:
                        return (bool)sqlsrv_query($this->_conn,"SAVE TRANSACTION currentQuery");
                }
            default:
                throw new VeloxException("Unknown connection type",55);
        }
    }

    /** Rolls back the active transaction.
     * @param bool $toSavepoint If true, rolls back to the last savepoint. If false or unspecified, rolls back the entire transaction.
     *
     * @return bool True if the rollback was successful.
     * @throws VeloxException If no active transaction exists.
     */
    public function rollBack(bool $toSavepoint = false) : bool {
        if (!$this->_inTransaction){
            throw new VeloxException("Transactional method called without active transaction",18);
        }
        if ($toSavepoint){
            switch ($this->serverType){
                case self::DB_MYSQL:
                    $rollbackQuery = "ROLLBACK TO SAVEPOINT currentQuery";
                    break;
                case self::DB_MSSQL:
                    $rollbackQuery = "ROLLBACK TRANSACTION currentQuery";
                    break;
            }
            switch ($this->connectionType){
                case self::CONN_PDO:
                    return $this->_conn->exec($rollbackQuery) !== false;
                case self::CONN_ODBC:
                    return odbc_exec($this->_conn,$rollbackQuery) !== false;
                case self::CONN_NATIVE:
                    switch ($this->serverType){
                        case self::DB_MYSQL:
                            return $this->_conn->rollback(0,"currentQuery");
                        case self::DB_MSSQL:
                            return sqlsrv_query($this->_conn,$rollbackQuery);
                        default:
                            throw new VeloxException("Invalid database type constant",10);
                    }
                default:
                    throw new VeloxException("Unknown connection type",55);
            }
        }
        else {
            switch ($this->connectionType){
                case self::CONN_PDO:
                    return $this->_conn->rollBack();
                case self::CONN_ODBC:
                    return odbc_rollback($this->_conn);
                case self::CONN_NATIVE:
                    switch ($this->serverType){
                        case self::DB_MYSQL:
                            return $this->_conn->rollback();
                        case self::DB_MSSQL:
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
        switch ($this->connectionType){
            case self::CONN_PDO:
                $success = $this->_conn->commit();
                break;
            case self::CONN_ODBC:
                $success = odbc_commit($this->_conn);
                break;
            case self::CONN_NATIVE:
                switch ($this->serverType){
                    case self::DB_MYSQL:
                        $success = $this->_conn->commit();
                        break;
                    case self::DB_MSSQL:
                        $success = sqlsrv_commit($this->_conn);
                        break;
                }
        }
        $this->_inTransaction = !$success;
        return (bool)$success;
    }

    /** Returns the server type constant for this connection. See the `DB_*` constants defined above.
     * @return int The server type constant.
     */
    public function serverType() : int {
        return $this->serverType;
    }

    /** Returns the connection type constant for this connection. See the `CONN_*` constants defined above.
     * @return int The connection type constant.
     */
    public function connectionType() : int {
        return $this->connectionType;
    }

    /** Returns the last affected indices of the most recent query (equivalent to `LAST_INSERT_ID()` in MySQL). Note: calling
     * this method will clear the stored indices; subsequent calls will return an empty array until another query is executed.
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
        switch ($this->connectionType){
            case self::CONN_PDO:
                throw new VeloxException("PDO connection cannot be closed with the close() method",52);
            case self::CONN_ODBC:
                odbc_close($this->_conn);
                return true;
            case self::CONN_NATIVE:
                switch ($this->serverType){
                    case self::DB_MYSQL:
                        return $this->_conn->close();
                    case self::DB_MSSQL:
                        return sqlsrv_close($this->_conn);
                }
            default:
                throw new VeloxException("Unknown connection type",55);
        }
    }
    /** Executes a given query. This can either be an instance of the Query class or a standalone SQL query string. If the
     * latter is passed, a new `Query` instance will be created from it.
     * @param Query|string $query The query to execute.
     * @param int $resultType The type of result to return (default is Query::RESULT_ARRAY). See {@see \KitsuneTech\Velox\Database\Procedures\Query Query} for the constants to use.
     * @return ResultSet|array|bool The result of the query, or false if the query failed.
     * @throws VeloxException If the query failed. The exception will be passed through from the Query class.
     */
    public function execute(string|Query $query, int $queryType = Query::QUERY_SELECT, int $resultType = Query::RESULT_ARRAY) : ResultSet|array|bool {
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
        return $this->host;
    }

    /** Returns the database name for this connection.
     * @return string The database name as originally specified.
     */
    public function getDB() : string {
        return $this->db;
    }

    /** Returns the server type for this connection, in user-readable format (presently either MySQL or SQL Server).
     * @return string The server type.
     */
    public function getServerType() : string {
        return match ($this->serverType) {
            self::DB_MYSQL => "MySQL",
            self::DB_MSSQL => "SQL Server",
            default => "Unknown",
        };
    }
}
