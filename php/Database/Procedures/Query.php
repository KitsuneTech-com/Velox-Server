<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;
use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;
use KitsuneTech\Velox\VeloxException;

class Query {
    public Connection $conn;
    public string $sql;
    public ?string $keyColumn;
    public ?int $resultType;
    public ?int $queryType;
    public array|ResultSet|bool $results;
    private array $_lastAffected = [];
    public array $tables = [];
    
    public function __construct(Connection $conn, string $sql, ?string $keyColumn = null, int $queryType = QUERY_SELECT, int $resultType = VELOX_RESULT_ARRAY) {
	$this->sql = $sql;
	$this->conn = $conn;
	$this->keyColumn = $keyColumn;
	$this->resultType = $resultType;
	$this->queryType = $queryType;
    }
    
    public function execute() : bool {
	echo $this->sql;
	$this->results = $this->conn->execute($this);
	$this->_lastAffected = $this->conn->getLastAffected();
	if ($this instanceof PreparedStatement){
	    $this->clear();
	}
	return true;
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
