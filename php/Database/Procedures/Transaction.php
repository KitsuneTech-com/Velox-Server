<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;

class Transaction {
    public array $queries;
    private Connection $_baseConn;
    private array $_connections = [];
    private array $_results = [];
    private int $_currentIndex = 0;
    private array $_lastAffected = [];
    private array $_paramArray = [];
    
    public function __construct(?Connection $conn) {
        if (isset($conn)){
            $this->_baseConn = $conn;
            $this->_connections[] = $conn;
        }
        $this->queries = [];
    }
    
    //Assembly
    public function addQuery(string|Query|StatementSet $query, ?string $keyColumn = null, ?int $resultType = VELOX_RESULT_NONE) : void {
        if (gettype($query) == "string"){
            if (!isset($this->_baseConn)){
                throw new VeloxException("Transaction has no active connection",26);
            }
            $this->queries[] = new Query($this->_baseConn,$query,$keyColumn,$resultType);
        }
        elseif ($query instanceof Query || $query instanceof StatementSet){
            $connectionExists = false;
            if (count($this->_connections) == 0){
                $this->queries[] = $this->_baseConn = $query->conn;
            }
            else {
                foreach($this->_connections as $conn){
                    if ($query->conn === $conn){
                        $connectionExists = true;
                        break;
                    }
                }
            }
            if (!$connectionExists){
                $this->_connections[] = $query->conn;
            }
            if (count($this->queries) == 0 && count($this->_paramArray) > 0){
                foreach ($this->_paramArray as $paramSet){
                    $query->addParameterSet($paramSet);
                }
            }
            if ($query instanceof StatementSet){
                foreach ($query as $stmt){
                    $this->queries[] = $stmt;
                }
            }
            else {
                $this->queries[] = $query;
            }
        }
    }
    public function addParameterSet(array $paramArray) : void {
        $this->_paramArray[] = $paramArray;
        if (count($this->queries) > 0 && $this->queries[0] instanceof PreparedStatement){
            $this->queries[0]->addParameterSet($paramArray);
        }
    }
    public function getParams() : array {
        return $this->_paramArray;
    }
    
    //Execution
    public function begin() : void {
        foreach ($this->_connections as $conn){
            $conn->beginTransaction();
        }
    }
    public function executeNext() : array|bool {
        if (!(isset($this->queries[$this->_currentIndex]))){
            return false;
        }
        $currentQuery = $this->queries[$this->_currentIndex];
        $lastQuery = $this->queries[$this->_currentIndex-1] ?? null;
        try {
            $currentQuery->conn->setSavepoint();
            $currentQuery->execute();
        
            if ($lastQuery && $lastQuery->getSetId() == $currentQuery->getSetId()){
                $lastQueryResults = $lastQuery->getQueryResults();
                if ($lastQueryResults instanceof ResultSet){
                    $lastQueryResults->merge($currentQuery->getResults());
                }
                elseif (is_array($lastQueryResults)){
                    $lastQueryResults += $currentQuery->getResults();
                }
            }
            else {
                $this->_results[] = $currentQuery->results;
            }
            $this->_currentIndex++;
            $this->_lastAffected = $currentQuery->getLastAffected();
            return $this->_lastAffected;
        }
        catch (Exception $ex){
            $currentQuery->conn->rollBack(true);
            throw new VeloxException("Query in transaction failed",27,$ex);
        }
    }
  
    public function getQueryResults(?int $queryIndex = null) : ResultSet|array|bool {
        if (is_null($queryIndex)){
            $queryIndex = count($this->queries)-1;
        }
        return $this->_results[$queryIndex];
    }
  
    public function executeAll() : bool {
        $this->begin();
        try {
            while ($this->executeNext()){}
            foreach ($this->_connections as $conn){
                $conn->commit();
            }
            return true;
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
    public function getLastAffected() : array {
        return $this->_lastAffected;
    }
    public function getTransactionPlan() : array {
        $queryDumpArray = [];
        foreach ($this->queries as $query){
            $queryDumpArray[] = $query->dumpQuery();
        }
        return $queryDumpArray;
    }
}
