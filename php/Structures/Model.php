<?php

namespace KitsuneTech\Velox\Structures;
use KitsuneTech\Velox\VeloxException as VeloxException;
use KitsuneTech\Velox\Database\Procedures\{PreparedStatement, StatementSet, Transaction};
use function KitsuneTech\Velox\Transport\Export as Export;

class Model {
    
    // Note: in Model::update() and Model::delete(), $where is an array of arrays containing a set of conditions to be OR'd together.
    // In Model::update() and Model::insert(), $values is an array of associative arrays, the keys of which are the column names represented
    // in the model. In Model::insert(), any columns not specified are set as NULL.
    private PreparedStatement|StatementSet $_select;
    private PreparedStatement|StatementSet|Transaction $_update;
    private PreparedStatement|StatementSet|Transaction $_insert;
    private PreparedStatement|StatementSet|Transaction $_delete;
    private array $_columns;
    private array $_data;
    private object $_diff;
    private string $_keyColumn = '';
    private ?int $_lastQuery;
    private bool $_delaySelect = false;
    
    public ?string $instanceName;
    
    public function __construct(PreparedStatement|StatementSet $select, PreparedStatement|StatementSet|Transaction $update = null, PreparedStatement|StatementSet|Transaction $insert = null, PreparedStatement|StatementSet|Transaction $delete = null){
        $this->_select = $select;
        if ($update && !($update instanceof Transaction)) {
            $update->queryType = QUERY_UPDATE;
        }
        if ($insert && !($insert instanceof Transaction)) {
            $insert->queryType = QUERY_INSERT;
        }
        if ($delete && !($delete instanceof Transaction)) {
            $delete->queryType = QUERY_DELETE;
        }
        $this->_update = $update ?? new Transaction($this->_select->conn);
        $this->_insert = $insert ?? new Transaction($this->_select->conn);
        $this->_delete = $delete ?? new Transaction($this->_select->conn);
        $this->_diff = new Diff('{}');
        $this->instanceName = null;
        $this->select();
    }
    
    public function select(bool $diff = false) : void {
        if ($this->_select->execute()){
            $this->_lastQuery = time();
            if ($this->_select->results instanceof ResultSet){
                $results = $this->_select->results->getRawData();
            }
            else {
               throw new VeloxException('The PreparedStatement returned multiple result sets. Make sure that $resultType is set to VELOX_RESULT_UNION or VELOX_RESULT_UNION_ALL.',29);
            }
           if (!$diff){
                $this->_data = $results;
                $this->_columns = $this->_select->results->columns();
            }
            else {
                $this->_diff = new Diff();
                foreach ($this->_data as $index => $row){
                    if (!in_array($row,$results)){
                        unset($this->_data[$index]);
                        $this->_diff->delete[] = (object)$row;
                    }
                }
                foreach($results as $row){
                    if (!in_array($row,$this->_data)){
                        $this->_data[] = $row;
                        $this->_diff->insert[] = (object)$row;
                    }
                }
                //Note: no update is necessary on database-to-model diffs because the model has no foreign key constraints. It's assumed that the
                //database is taking care of this. Any SQL UPDATEs are propagated on the model as deletion and reinsertion.
            }
        }
    }
    
    public function update(array $rows) : bool {
        //$rows is expected to be an array of associative arrays. If the associated update object is a PreparedStatement, each element must be
        // an array of parameter sets ["placeholder"=>"value"]; if the update object is a StatementSet, the array should be Diff-like (each element
        // having "values" and "where" keys with the appropriate structure [see the comments in php/Structures/Diff.php].
        $this->_update->clear();
        $reflection = new \ReflectionClass($this->_update);
        switch ($reflection->getShortName()){
            case "PreparedStatement":
                foreach($rows as $row){
                    $this->_update->addParameterSet($row);
                }
                break;
            case "StatementSet":
                $this->_update->addCriteria($rows);
                break;
        }
        
        $this->_update->execute();
        if (!$this->_delaySelect){
            $this->select(true);
        }
        return true;
    }
    
    public function insert(array $rows) : bool {
        $this->_insert->clear();
        $reflection = new \ReflectionClass($this->_insert);
        switch ($reflection->getShortName()){
            case "PreparedStatement":
                foreach($rows as $row){
                    if (!isset($row[$column])){
                        $row[$column] = null;
                    }
                    $this->_insert->addParameterSet($row);
                }
                break;
            case "StatementSet":
                $this->_insert->addCriteria($rows);
                break;
        }
        $this->_insert->execute();
        
        if (!$this->_delaySelect){
            $this->select(true);
        }
        return true;
    }
    
    public function delete(array $rows) : bool {
        $this->_delete->clear();
        $reflection = new \ReflectionClass($this->_delete);
        switch ($reflection->getShortName()){
            case "PreparedStatement":
                foreach ($rows as $row){
                    $this->_delete->addParameterSet($row);
                }
                break;
            case "StatementSet":
                $this->_delete->addCriteria($rows);
                break;
        }
        
        $this->_delete->execute();
        if (!$this->_delaySelect){
            $this->select(true);
        }
        return true;
    }
    
    public function sort(...$args) : void {
        $sortArray = [];
        for ($i=0; $i<count($args); $i++){
            if (!in_array($args[$i],$this->_columns)){
                throw new VeloxException("Invalid column specified",29);
            }
            $column = array_column($this->_data,$args[$i]);
            switch ($args[$i+1] ?? null){
                case SORT_ASC:
                case SORT_DESC:
                    $direction = $args[$i+1];
                    $i++;
                    if (isset($args[$i+1]) && is_int($args[$i+1])){
                        $flags = $args[$i+1];
                        $i++;
                    }
                    else {
                        $flags = SORT_REGULAR;
                    }
                    break;
                case SORT_REGULAR:
                case SORT_NUMERIC:
                case SORT_STRING:
                case SORT_LOCALE_STRING:
                case SORT_NATURAL:
                case SORT_FLAG_CASE:
                    $flags = $args[$i+1] ?? null;
                    $i++;
                    if (isset($args[$i+1]) && is_int($args[$i+1])){
                        $direction = $args[$i+1];
                        $i++;
                    }
                    else {
                        $direction = SORT_ASC;
                    }
                    break;
            }
            $sortArray[] = $column;
            if (isset($direction)){
                $sortArray[] = $direction;
            }
            if (isset($flags)){
                $sortArray[] = $flags;
            }
            $direction = $flags = null;
        }
        $sortArray[] = &$this->_data;
        array_multisort(...$sortArray);
    }
    
    public function synchronize(Diff $diff){
        $this->_delaySelect = true;
        if ($diff->update) {
            $this->update($diff->update);
        }
        if ($diff->delete) {
            $this->delete($diff->delete);
        }
        if ($diff->insert) {
            $this->insert($diff->insert);
        }
        $this->select(true);
        $this->_delaySelect = false;
    }
    public function columns() : array {
        return $this->_columns;
    }
    public function data() : array {
        return $this->_data;
    }
    public function lastQuery() : ?int {
        return $this->_lastQuery;
    }
    public function export(int $flags = TO_BROWSER+AS_JSON, ?string $fileName = null, ?int $ignoreRows = 0, bool $noHeader = false) : string|bool {
        return Export($this,$flags,$fileName,$ignoreRows,$noHeader);
    }
}
