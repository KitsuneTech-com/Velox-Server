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
        string $host,
        string $db_name,
        string $uid,
        string $pwd,
        int $serverType = DB_MYSQL) {
    
        if (!$host) {
            throw new VeloxException("Database host not provided",11);
        }
        if (!$db_name) {
            throw new VeloxException("Database name not provided",12);
        }
        if (!$uid) {
            throw new VeloxException("Database user not provided",13);
        }
        if (!$pwd) {
            throw new VeloxException("Database password not provided",14);
        }
        $this->_host = $host;
        $this->_db = $db_name;
        $this->_serverType = $serverType;
        $this->_inTransaction = false;
    
        switch ($this->_serverType){
            case DB_MYSQL:
                try {
                    $connStr = http_build_query(['host' => $host, 'dbname' => $db_name],'',";");
                    $this->_conn = new \PDO("mysql:$connStr",$uid,$pwd);
                    $this->_conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                    $this->_usePDO = true;
                }
                catch (\PDOException $ex) {
                    if ($this->_serverType === DB_MYSQL) {
                        throw new VeloxException("PDO error: ".$ex->getMessage(),(int)$ex->getCode(),$ex);
                    }
                }
                break;
            case DB_MSSQL:
                if (extension_loaded("pdo_sqlsrv")){
                    try {
                        $connStr = http_build_query(['Server' => $host, 'Database' => $db_name], '', ";");
                        $this->_conn = new \PDO("sqlsrv:$connStr", $uid, $pwd);
                        $this->_conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                        $this->_usePDO = true;
                    }
                    catch (\PDOException $ex) {
                        if ($this->_serverType === DB_MYSQL) {
                            throw new VeloxException("PDO error: ".$ex->getMessage(),(int)$ex->getCode(),$ex);
                        }
                    }
                }
                elseif (extension_loaded("sqlsrv")){
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
                    $this->_conn = sqlsrv_connect($host,["Database"=>$db_name, "UID"=>$uid, "PWD"=>$pwd]);
                }
                else {
                    throw new VeloxException("SQL Server connections require either the sqlsrv or pdo_sqlsrv extensions to be loaded.",15);
                }

                break;
            default:
                throw new VeloxException("Invalid database type constant",10);
        }
    }
    public function beginTransaction() : bool {
        $this->_inTransaction = true;
        if ($this->_usePDO){
            return $this->_conn->beginTransaction();
        }
        elseif ($this->_serverType == DB_MSSQL){
            return sqlsrv_begin_transaction($this->_conn);
        }
    }
    public function inTransaction() : bool {
        return $this->_inTransaction;
    }
    public function setSavepoint() : bool {
        if (!$this->_inTransaction){
            throw new VeloxException("Transactional method called without active transaction",18);
        }
        switch ($this->_serverType){
            case DB_MYSQL:
                return $this->_conn->exec("SAVEPOINT currentQuery") !== false;
            case DB_MSSQL:
                if ($this->_usePDO){
                    return $this->_conn->exec("SAVE TRANSACTION currentQuery") !== false;
                }
                else {
                    return (bool)sqlsrv_query($this->_conn,"SAVE TRANSACTION currentQuery");
                }
        }
    }
    public function rollBack(bool $toSavepoint = false) : bool {
        if (!$this->_inTransaction){
            throw new VeloxException("Transactional method called without active transaction",18);
        }
        switch ($this->_serverType){
            case DB_MYSQL:
                if ($toSavepoint){
                    return $this->_conn->exec("ROLLBACK TO SAVEPOINT currentQuery") !== false;
                }
                else {
                    return $this->_conn->rollBack();
                }
            case DB_MSSQL:
                if ($this->_usePDO){
                    if ($toSavepoint) {
                        return $this->_conn->exec("ROLLBACK TRANSACTION currentQuery") !== false;
                    } else {
                        return $this->_conn->rollBack();
                    }
                }
                else {
                    if ($toSavepoint) {
                        return (bool)sqlsrv_query($this->conn, "ROLLBACK TRANSACTION currentQuery");
                    } else {
                        return sqlsrv_rollback($this->_conn);
                    }
                }
        }
    }
    public function commit() : bool {
        if (!$this->_inTransaction){
            throw new VeloxException("Transactional method called without active transaction",18);
        }
        $success = false;
        if ($this->_usePDO){
            $success = $this->_conn->commit();
        }
        elseif ($this->_serverType == DB_MSSQL){
            $success = sqlsrv_commit($this->_conn);
        }
        $this->_inTransaction = !$success;
        return (bool)$success;
    }
    public function serverType() : int {
        return $this->_serverType;
    }
    
    public function getLastAffected() : array {     //Note: the side effect of calling this method is that the array of previously affected ids will
        $lastAffected = $this->_lastAffected;       //be cleared.
        $this->_lastAffected = [];
        return $lastAffected;
    }
    
    public function close() : bool {
        if ($this->_usePDO){
            $this->_conn = null;
        }
        elseif ($this->_serverType == DB_MSSQL){
            return sqlsrv_close($this->_conn);
        }
    }
    
    public function execute(string|Query $query, int $queryType = QUERY_SELECT, int $resultType = VELOX_RESULT_ARRAY) : ResultSet|array|bool {
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
    
        if ($query instanceof PreparedStatement){
            $paramArray = $query->getParams();
            $placeholders = array_fill(0,$query->getParamCount(),null);
        }
        
        try {
            switch ($this->_serverType){
                case DB_MYSQL:
                    $stmt = $this->_conn->prepare($query->sql);
                    break;
                case DB_MSSQL:
                    $sql = $query->sql;
                    if ($queryType == QUERY_INSERT || $queryType == QUERY_UPDATE){
                        //add SCOPE_IDENTITY() to the query to retrieve the last updated id
                        $sql = preg_replace("[\s;]+$", "; SCOPE_IDENTITY();", $sql);
                    }
                    if ($this->_usePDO){
                        $stmt = $this->_conn->prepare($query->sql);
                    }
                    elseif (!($stmt = sqlsrv_prepare($this->_conn,$sql,$placeholders))){
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
        catch (Exception $ex){
            throw new VeloxException("SQL statement failed to prepare",20,$ex);
        }
        $results = [];
    
        try {
            if (count($placeholders) > 0){    //placeholders signify that this is intended to be run more than once
                $firstExecution = true;
                if ($this->_usePDO){
                    foreach ($paramArray as $paramSet){
                        foreach (array_keys($paramSet) as $key){
                            try {
                                $stmt->bindParam($key,$paramSet[$key]);
                            }
                            catch(Exception $ex){
                                if ($queryType == QUERY_PROC && str_starts_with($key,':op_')){
                                    //Ignore missing :op_ placeholder for stored procedures (these will be passed by StatementSet
                                    //but are not strictly necessary)
                                }
                                else {
                                    throw new VeloxException('Placeholder '.$key.' does not exist in prepared statement SQL',46);
                                }
                            }
                        }
                        if (!$stmt->execute($paramSet)){
                            $err = $stmt->errorInfo();
                            throw new Exception($err[2],$err[1]);
                        }
                        switch ($queryType){
                            case QUERY_INSERT:
                            case QUERY_UPDATE:
                                $this->_lastAffected[] = $this->_conn->lastInsertId();
                                break;
                            case QUERY_SELECT:
                                switch ($resultType){
                                    case VELOX_RESULT_ARRAY:
                                    case VELOX_RESULT_UNION:
                                    case VELOX_RESULT_UNION_ALL:
                                        $resultArray = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                                        $currentResult = new ResultSet($resultArray);
                                        if ($firstExecution || $resultType == VELOX_RESULT_ARRAY){
                                            $results[] = $currentResult;
                                        }
                                        else {
                                            $results[0]->merge($currentResult,($resultType == VELOX_RESULT_UNION_ALL));
                                        }
                                        break;
                                    case VELOX_RESULT_FIELDS:
                                        $currentResult = [];
                                        $columnCount = $stmt->columnCount;
                                        for ($i=0; $i<$columnCount-1; $i++){
                                            $currentResult[] = $stmt->getColumnMeta($i);
                                        }
                                        $results[] = $currentResult;
                                        break 3;    //Break out of all iteration, since the remaining results would be redundant
                                }
                                break;
                        }
                        $stmt->closeCursor();
                        $firstExecution = false;
                    }
                }
                elseif ($this->_serverType == DB_MSSQL){
                    foreach ($paramArray as $paramSet){
                        $keys = array_keys($paramSet);
                        $placeholderCount = count($placeholders);
                        for ($i=0; $i<$placeholderCount; $i++){
                            $placeholders[$i] = $paramSet[$keys[$i]];
                        }
                        if (!sqlsrv_execute($stmt)){
                            $errors = sqlsrv_errors();
                            $errorStr = "";
                            foreach($errors as $error){
                                $errorStr .= "(code ".$error['code']."): ".$error['message']."\n";
                            }
                            throw new VeloxException($errorStr,17);
                        }
                        switch ($queryType){
                            case QUERY_INSERT:
                            case QUERY_UPDATE:
                                sqlsrv_fetch($stmt);
                                $this->_lastAffected[] = sqlsrv_get_field($stmt,0);
                                break;
                            case QUERY_SELECT:
                                switch ($resultType){
                                    case VELOX_RESULT_ARRAY:
                                    case VELOX_RESULT_UNION:
                                    case VELOX_RESULT_UNION_ALL:
                                        $currentResult = new ResultSet(sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC));
                                        while ($nextRow = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)){
                                            $currentResult[] = $nextRow;
                                        }
                                        if ($firstExecution || $resultType == VELOX_RESULT_ARRAY){
                                            $results[] = $currentResult;
                                        }
                                        else {
                                            $results[0]->merge($currentResult,($resultType == VELOX_RESULT_UNION_ALL));
                                        }
                                        break;
                                    case VELOX_RESULT_FIELDS:
                                        foreach (sqlsrv_field_metadata($stmt) as $metadata){
                                            $currentResult[] = $metadata;
                                        }
                                        $results[] = $currentResult;
                                        break 3;    //Break out of all iteration, since the remaining results would be redundant
                                }
                                break;
                        }
                        $firstExecution = false;
                    }
                }
                if (count($results)==1){
                    $results = $results[0];
                }
            }
            else {
                if ($this->_usePDO){
                    if (!$stmt->execute()){
                        $err = $stmt->errorInfo();
                        throw new Exception($err[2],$err[1]);
                    }
                    $this->_lastAffected[] = $this->_conn->lastInsertId();
                    switch ($resultType){
                        case VELOX_RESULT_ARRAY:
                        case VELOX_RESULT_UNION:
                        case VELOX_RESULT_UNION_ALL:
                            $results = new ResultSet($stmt->fetchAll(\PDO::FETCH_ASSOC));
                            break;
                        case VELOX_RESULT_FIELDS:
                            $currentResult = [];
                            $columnCount = $stmt->columnCount;
                            for ($i=0; $i<$columnCount-1; $i++){
                                $currentResult[] = $stmt->getColumnMeta($i);
                            }
                            $results[] = $currentResult;
                            break;
                    }
                }
                elseif ($this->_serverType == DB_MSSQL){
                    if (!sqlsrv_execute($stmt)){
                        $err = sqlsrv_errors();
                        throw new Exception($err['message'],$err['code']);
                    }
                    switch ($queryType){
                        case QUERY_INSERT:
                        case QUERY_UPDATE:
                            sqlsrv_fetch($stmt);
                            $this->_lastAffected[] = sqlsrv_get_field($stmt,0);
                            break;
                        case QUERY_SELECT:
                            switch ($resultType){
                                case VELOX_RESULT_ARRAY:
                                case VELOX_RESULT_UNION:
                                case VELOX_RESULT_UNION_ALL:
                                    $results = new ResultSet(sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC));
                                    while ($nextRow = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)){
                                        $results[] = $nextRow;
                                    }
                                    break;
                                case VELOX_RESULT_FIELDS:
                                    foreach (sqlsrv_field_metadata($stmt) as $metadata){
                                        $currentResult[] = $metadata;
                                    }
                                    $results[] = $currentResult;
                                    break;
                            }
                            break;
                    }
                }
            }
        }
        catch(Exception $ex){
            throw new VeloxException("Query failed to execute",21,$ex);
        }
        return $results ? $results : true;
    }
    public function getHost() : string {
        return $this->_host;
    }
    public function getDB() : string {
        return $this->_db;
    }
    public function getServerType() : string {
        switch ($this->_serverType){
            case DB_MYSQL:
                return "MySQL (PDO)";
            case DB_MSSQL:
                if ($this->_usePDO){
                    return "MSSQL (PDO)";
                }
                else {
                    return "MSSQL (sqlsrv)";
                }
            default:
                return "Unidentified";
        }
    }
}
