<?php

namespace KitsuneTech\Velox\Structures;
use KitsuneTech\Velox\VeloxException as VeloxException;
use KitsuneTech\Velox\Database\Procedures\{PreparedStatement, StatementSet, Transaction};
use function KitsuneTech\Velox\Transport\Export as Export;
use function KitsuneTech\Velox\Utility\sqllike_comp as sqllike_comp;

class Model {
    
    // Note: in Model->update() and Model->delete(), $where is an array of arrays containing a set of conditions to be OR'd toogether.
    // In Model->update() and Model->insert(), $values is an array of associative arrays, the keys of which are the column names represented
    // in the model. In Model->insert(), any columns not specified are set as NULL.   
    private array $_columns = [];
    private array $_data = [];
    private Diff $_diff;
    private Diff|array|null $_filter = null;
    private array $_filteredIndices = [];
    private int|null $_lastQuery;
    private bool $_delaySelect = false;
    
    //Model->returnDiff controls whether a Model->export returns a full resultset or just the rows that have been changed with the previous DML call
    // (false by default: returns full resultset)
    public bool $returnDiff = false;
    
    //Model->submodels is public for the sake of reference by Export. This property should not be modified directly.
    public array $submodels = [];
    
    //Used to join nested Models by a specific column. These will automatically be utilized if submodels are present.
    public ?string $primaryKey = null;
    
    public function __construct(
            private PreparedStatement|StatementSet $_select = null,
            private PreparedStatement|StatementSet|Transaction $_update = null,
            private PreparedStatement|StatementSet|Transaction $_insert = null,
            private PreparedStatement|StatementSet|Transaction $_delete = null,
            public ?string $instanceName = null
        ){
        if ($this->_update && !($this->_update instanceof Transaction)) {
            $this->_update->queryType = QUERY_UPDATE;
            $this->_update->resultType = VELOX_RESULT_NONE;
        }
        if ($this->_insert && !($this->_insert instanceof Transaction)) {
            $this->_insert->queryType = QUERY_INSERT;
            $this->_insert->resultType = VELOX_RESULT_NONE;
        }
        if ($this->_delete && !($this->_delete instanceof Transaction)) {
            $this->_delete->queryType = QUERY_DELETE;
            $this->_delete->resultType = VELOX_RESULT_NONE;
        }
        $conn = $this->_select->conn ?? $this->_update->conn ?? $this->_insert->conn ?? $this->_delete->conn ?? null;
        $this->_update = $this->_update ?? new Transaction($conn);
        $this->_insert = $this->_insert ?? new Transaction($conn);
        $this->_delete = $this->_delete ?? new Transaction($conn);
        $this->_diff = new Diff('{}');
    }
    
    public function select() : Diff|bool {
        if (!$this->_select){
            throw new VeloxException('The associated procedure for select has not been defined.',37);
        }
        if ($this->_select->execute()){
            $this->_lastQuery = time();
            if (is_array($this->_select->results)){
                $count = count($this->_select->results);
                switch ($count){
                    case 0:
                        $results = [];
                        break;
                    case 1:
                        $results = $this->_select->results[0];
                        break;
                    default:
                        throw new VeloxException('The PreparedStatement returned multiple result sets. Make sure that $resultType is set to VELOX_RESULT_UNION or VELOX_RESULT_UNION_ALL.',29);
                }
            }
            if ($this->_select->results instanceof ResultSet){
                $results = $this->_select->results->getRawData();
                $this->_columns = $this->_select->results->columns();
            }
            else {
                $results = [];
            }
            
            foreach ($this->submodels as $name => $submodel){
                $submodel->select();
                $pk = $this->primaryKey;
                $fk = $submodel->foreignKey;
                $submodel->object->sort($fk,SORT_ASC);
                $fk_column = array_column($submodel->object->data(),$fk);
                $this->sort($pk,SORT_ASC);
                foreach ($this->_data as $index => $row){
                    $fk_value = $this->_data[$pk];
                    $fk_indices = array_keys($fk_column,$fk_value);
                    $subdata = [];
                    foreach ($fk_indices as $idx){
                        $subdata[] = $submodel->object->data()[$idx];
                    }
                    $this->_data[$name] = $subdata;
                }
            }
            
            if ($this->returnDiff) {
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
            else {
                $this->_data = $results;
            }
            if ($this->_filter){
                $this->setFilter($this->_filter);
            }
            return true;
        }
    }
    
    public function update(array $rows) : bool {
        //$rows is expected to be an array of associative arrays. If the associated update object is a PreparedStatement, each element must be
        // an array of parameter sets ["placeholder"=>"value"]; if the update object is a StatementSet, the array should be Diff-like (each element
        // having "values" and "where" keys with the appropriate structure [see the comments in php/Structures/Diff.php].
        if (!$this->_update){
            throw new VeloxException('The associated procedure for update has not been defined.',37);
        }
        elseif (count($this->_submodels) > 0){
            if (!$this->_select){
                throw new VeloxException('Select query required for DML queries on nested Models',40);
            }
            $this->_select();
        }
        elseif ($this->_update instanceof PreparedStatement){
            $this->_update->clear();
        }
        $reflection = new \ReflectionClass($this->_update);
        $submodelCount = count($this->submodels);
        switch ($reflection->getShortName()){
            case "PreparedStatement":
                foreach($rows as $row){
                    if ($submodelCount > 0){
                        foreach ($row as $name => $value){
                            if (is_array($value)){
                                $submodels[$name]->object->addParameterSet($value);
                                unset($row[$column]);
                            }
                        }
                    }
                    $this->_update->addParameterSet($row);
                }
                break;
            case "StatementSet":
                if ($submodelCount > 0){
                    foreach ($rows as &$row){
                        foreach ($row as $column => $subcriteria){
                            if (is_array($subcriteria)){
                                foreach ($subcriteria as $row){
                                    $row->where[$submodels[$column]->primaryKey] = ["=",$
                                }
                                $submodels[$column]->addCriteria($value);
                                unset ($row[$column]);
                            }
                        }
                    }
                }
                $this->_update->addCriteria($rows);
                break;
        }
        
        $this->_update->execute();
        if (!$this->_delaySelect){
            $this->select();
        }
        return true;
    }
    
    public function insert(array $rows, bool $diff = false) : bool {
        if (!$this->_insert){
            throw new VeloxException('The associated procedure for insert has not been defined.',37);
        }
        elseif (count($this->_submodels) > 0){
            if (!$this->_select){
                throw new VeloxException('Select query required for DML queries on nested Models',40);
            }
            $this->_select();
        }
        elseif ($this->_insert instanceof PreparedStatement){
            $this->_insert->clear();
        }
        $reflection = new \ReflectionClass($this->_insert);
        switch ($reflection->getShortName()){
            case "PreparedStatement":
                $namedParams = $this->_insert->getNamedParams();
                foreach($rows as $row){
                    foreach($namedParams as $param){
                        if (!isset($row[$param])){
                            $row[$param] = null;
                        }
                        $this->_insert->addParameterSet($row);
                    }
                }
                break;
            case "StatementSet":
                $this->_insert->addCriteria($rows);
                break;
        }
        $this->_insert->execute();
        
        if (!$this->_delaySelect){
            $this->select();
        }
        return true;
    }
    
    public function delete(array $rows) : bool {
        if (!$this->_delete){
            throw new VeloxException('The associated procedure for delete has not been defined.',37);
        }
        elseif (count($this->_submodels) > 0){
            if (!$this->_select){
                throw new VeloxException('Select query required for DML queries on nested Models',40);
            }
            $this->_select();
        }
        elseif ($this->_delete instanceof PreparedStatement){
            $this->_delete->clear();
        }
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
            $this->select();
        }
        return true;
    }
    
    public function sort(...$args) : void {
        //Note: this sorting will use the default case-sensitive PHP sorting behavior, since the default
        //SQL ORDER BY behavior is case-sensitive as well.
        $sortArray = [];
        $argCount = count($args);
        for ($i=0; $i<$argCount; $i++){
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
    
    public function synchronize(Diff $diff) : void {
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
        if ($diff->select) {
            $this->setFilter($diff);
        }
        $this->select();
        $this->_delaySelect = false;
    }
    public function columns() : array {
        return $this->_columns;
    }
    public function data() : array {
        if ($this->_filter){
            return array_values(array_intersect_key($this->_data,array_flip($this->_filteredIndices)));
        }
        else {
            return $this->_data;
        }
    }
    public function diff() : Diff {
        return $this->_diff;
    }
    public function addSubmodel(string $name, Model $submodel, string $foreignKey) : void {
        //$name is the desired column name for export
        //$submodel is the Model object to be used as the submodel
        //$foreignKey is the column in the submodel containing the values to be matched against the Model's primary key column 
        $submodel->instanceName = $name;
        $this->_submodels[$name] = (object)['object'=>$submodel,'foreignKey'=>$foreignKey];
    }
    public function setFilter(Diff|array|null $filter) : void {
        $this->_filter = $filter instanceof Diff ? $filter->select : (!is_null($filter) ? $filter : []);
        $this->_filteredIndices = [];
        $whereArray = $this->_filter[0]['where'];
        foreach ($whereArray as $orArray){
            foreach ($this->_data as $idx => $row){
                foreach ($orArray as $column => $criteria){
                    if (!in_array($column,$this->_columns)){
                        throw new VeloxException("Column '".$column."' does not exist in result set.",38);
                    }
                    switch ($criteria[0]){
                        case "BETWEEN":
                            if (sqllike_comp($row[$column],"<",$criteria[1]) || sqllike_comp($row[$column],">",$criteria[2])){
                                continue 3;
                            }
                            break;
                        case "NOT BETWEEN":
                            if (sqllike_comp($row[$column],">=",$criteria[1]) && sqllike_comp($row[$column],"<=",$criteria[2])){
                                continue 3;
                            }
                            break;
                        case "IS NULL":
                            if (!is_null($row[$column])){
                                continue 3;
                            }
                            break;
                        case "IS NOT NULL":
                            if (is_null($row[$column])){
                                continue 3;
                            }
                            break;
                        default:
                            if (!sqllike_comp($row[$column],$criteria[0],$criteria[1])){
                                continue 3;
                            }
                            break;
                    }
                }
                if (!in_array($idx,$this->_filteredIndices)) $this->_filteredIndices[] = $idx;
            }
        }
    }
    public function lastQuery() : ?int {
        return $this->_lastQuery;
    }
    public function export(int $flags = TO_BROWSER+AS_JSON, ?string $fileName = null, ?int $ignoreRows = 0, bool $noHeader = false) : string|bool {
        return Export($this,$flags,$fileName,$ignoreRows,$noHeader);
    }
}
