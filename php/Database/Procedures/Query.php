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
    
    public function __construct(public Connection &$conn, public string $sql, public int $queryType = QUERY_SELECT, public int $resultType = VELOX_RESULT_ARRAY) {}
    
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
