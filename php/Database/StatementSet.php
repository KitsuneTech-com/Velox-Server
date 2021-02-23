<?php
class StatementSet implements \Iterator {
    private string $_baseSql;
    public Connection $conn;
    private array $_criteria;
    private int $_queryType;
    private array $_statements;
    private int $_position;
    public ResultSet|array|bool $results;
    
    public function __construct(Connection $conn, string $baseSql = "", int $queryType = QUERY_SELECT, array|Diff $criteria = []){
        $this->_baseSql = $baseSql;
        $this->conn = $conn;
        $this->_criteria = [];
        //stored procedures are handled differently due to lack of operators
        $this->_queryType = $queryType;
        $this->_statements = [];
        $this->_position = 0;
        if ($criteria instanceof Diff || count($criteria) > 0){
            $this->addCriteria($criteria);
        }
    }
    
    //Iterator implementation
    public function current() : PreparedStatement {
        return $this->_statements[$this->_position];
    }
    public function key() : int {
        return $this->_position;
    }
    public function next() : void {
        $this->_position++;
    }
    public function rewind() : void {
        $this->_position = 0;
    }
    public function valid() : bool {
        return isset($this->_statements[$this->_position]);
    }
    
    private function criterionHash(object|array $criterion) : string {
        function recur_ksort(&$array) {
            foreach ($array as &$value) {
               if (is_array($value)) {
               recur_ksort($value);
               }
            }
        return ksort($array);
        }
        $criterion = (array)$criterion;
        $valuesList = [];
        if (isset($criterion['values'])){
            foreach (array_keys($criterion['values']) as $key){
                $valuesList[] = $key;
            }
            $criterion['values'] = $valuesList;
        }
        if (isset($criterion['where'])){
            foreach ($criterion['where'] as $or){
                foreach ($or as $column => $condition){
                    $criterion['where'][$column] = $condition[0];
                }
            }
        }
        recur_ksort($criterion);
        return (string)crc32(serialize($criterion));
    }
    
    public function addCriteria (array|Diff $criteria) : void {
        if ($criteria instanceof Diff){
            switch ($this->_queryType){
                case QUERY_SELECT:
                    $this->addCriteria($criteria->select);
                    break;
                case QUERY_INSERT:
                    $this->addCriteria($criteria->insert);
                    break;
                case QUERY_UPDATE:
                    $this->addCriteria($criteria->update);
                    break;
                case QUERY_DELETE:
                    $this->addCriteria($criteria->delete);
                    break;
                }
        }
        else {
            foreach($criteria as $criterion){
                $hashedKeys = $this->criterionHash($criterion);
                if (!isset($this->_criteria[$hashedKeys])){
                    $this->_criteria[$hashedKeys] = ["where"=>$criterion['where'] ?? [],"values"=>$criterion['values'] ?? [],"data"=>[]];
                }
                $this->_criteria[$hashedKeys]['data'][] = ["where"=>$criterion['where'] ?? [],"values"=>$criterion['values'] ?? []];
            }
        }
    }
    public function setStatements() : void {
        $setId = uniqid();
        $statements = [];
        $criteria = $this->_criteria;
    
        if (count($criteria) == 0){
            $criteria[0]['where'] = [];
            $criteria[0]['data'] = [];
        }
        foreach($criteria as $variation){
        
            $whereStr = "";
            $valuesStr = "";
            $columnsStr = "";
        
            switch ($this->_queryType){
                case QUERY_SELECT:
                case QUERY_DELETE:
                case QUERY_UPDATE:
                case QUERY_PROC:
                    //format where clause
                    $orArray = [];
                    foreach ($variation['where'] as $andSet){
                        $andArray = [];
                        foreach ($andSet as $column => $details){
                            $andArray[] = $column . " " . $details[0] ." :w_" . $column . ($details[0] == "BETWEEN" ? " AND :wb_" . $column : "");
                        }
                        switch (count($andArray)){
                            case 0:
                                break;
                            case 1:
                                $orArray[] = $andArray[0];
                                break;
                            default:
                                $orArray[] = "(".implode(" AND ",$andArray).")";
                                break;
                        }
                    }
            
                    switch (count($orArray)){
                        case 0:
                            $whereStr = "1=1";
                            break;
                        case 1:
                            $whereStr = $orArray[0];
                            break;
                        default:
                            $whereStr = "(".implode(" OR ",$orArray).")";
                            break;
                    }
                    if ($this->_queryType != QUERY_UPDATE && $this->_queryType != QUERY_PROC){
                        break;
                    }

                case QUERY_INSERT:  //and fall-through for QUERY_UPDATE and QUERY_PROC
                    //format values
                    $valuesArray = $variation['values'];
                    $valuesStrArray = [];
                    $columnsStrArray = [];
                    foreach (array_keys($valuesArray) as $column){
                        switch ($this->_queryType){
                            case QUERY_INSERT:
                                $columnsStrArray[] = $column;
                                $valuesStrArray[] = ":v_".$column;
                                break;
                            case QUERY_UPDATE:
                                $valuesStrArray[] = $column."= :v_".$column;
                                break;
                        }
                    }
                    $valuesStr = implode(",", $valuesStrArray);
                    $columnsStr = implode(",", $columnsStrArray);
                    break;
            }
        
            if ($this->_queryType == QUERY_INSERT){
                $valuesStr = "(".$columnsStr.") VALUES (".$valuesStr.")";
                $columnsStr = "";
            }
        
            $substitutedSQL = str_replace(["<<condition>>","<<columns>>","<<values>>"],[$whereStr,$columnsStr,$valuesStr],$this->_baseSql);
            $stmt = new PreparedStatement($this->conn, $substitutedSQL, null, $this->_queryType, VELOX_RESULT_UNION);
        
            foreach ($variation['data'] as $row){
                $parameterSet = [];
                foreach ($row['where'] as $or){
                    foreach ($or as $column => $data){
                        try {
                            $parameterSet['w_'.$column] = $data[1];
                        }
                        catch (Exception $ex){
                            throw new VeloxException("Operand missing in 'where' array",23);
                        }
                        if ($data[0] == "BETWEEN") {
                            try {
                                $parameterSet['wb_'.$column] = $data[2];
                            }
                            catch (Exception $ex){
                                throw new VeloxException('BETWEEN operator used without second operand',24);
                            }
                        }
                    }
                }
                foreach ($row['values'] as $column => $value){
                    $parameterSet['v_'.$column] = $value;
                }
                $stmt->addParameterSet($parameterSet);
            }
            $statements[] = $stmt;
        }
        $this->_statements = $statements;
    }
    public function execute() : bool {
        if (count($this->_statements) == 0){
            $this->setStatements();
            if (count($this->_statements) == 0){
                throw new VeloxException('Criteria must be set before StatementSet can be executed.',25);
            }
        }
        if (!$this->conn->inTransaction()){
            $transaction = new Transaction($this->conn);
            $transaction->addQuery($this);
            $transaction->executeAll();
            $this->results = $transaction->getQueryResults();
        }
        return true;
    }
}
