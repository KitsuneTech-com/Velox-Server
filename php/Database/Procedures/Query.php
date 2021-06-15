<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;
use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;
use KitsuneTech\Velox\VeloxException;

class Query {
    public array|ResultSet|bool $results;
    public array $tables = [];
    private array $_lastAffected = [];
    
    public function __construct(public Connection &$conn, public string $sql, public ?int $queryType = null, public int $resultType = VELOX_RESULT_ARRAY) {
        if (!$this->queryType){
            //Attempt to determine type by first keyword if query type isn't specified
            $lc_query = strtolower($sql);
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
    
    public function execute() : bool {
        $this->results = $this->conn->execute($this);
        $this->_lastAffected = $this->conn->getLastAffected();
        if ($this instanceof PreparedStatement){
            $this->clear();
        }
        return true;
    }
    //Magic method wrapper for execute() to make Query instance callable
    public function __invoke() : bool {
        return $this->execute();
    }
    
    public function getResults() : array|ResultSet|bool {
        if (!isset($this->results)){
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
        return ["type"=>"Query","connection"=>["host"=>$this->conn->getHost(),"db"=>$this->conn->getDB(),"type"=>$this->conn->getServerType()],"query"=>$this->sql];
    }
}
