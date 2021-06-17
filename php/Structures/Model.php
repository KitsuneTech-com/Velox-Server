<?php

namespace KitsuneTech\Velox\Structures;
use KitsuneTech\Velox\VeloxException as VeloxException;
use KitsuneTech\Velox\Database\Procedures\{PreparedStatement, StatementSet, Transaction};
use function KitsuneTech\Velox\Transport\Export as Export;
use function KitsuneTech\Velox\Utility\sqllike_comp as sqllike_comp;

class Model implements \ArrayAccess, \Iterator, \Countable {
    
    // Note: in Model::update() and Model::delete(), $where is an array of arrays containing a set of conditions to be OR'd toogether.
    // In Model::update() and Model::insert(), $values is an array of associative arrays, the keys of which are the column names represented
    // in the model. In Model::insert(), any columns not specified are set as NULL.   
    private PreparedStatement|StatementSet|null $_select;
    private PreparedStatement|StatementSet|Transaction|null $_update;
    private PreparedStatement|StatementSet|Transaction|null $_insert;
    private PreparedStatement|StatementSet|Transaction|null $_delete;
    private array $_columns = [];
    private array $_data = [];
    private object $_diff;
    private Diff|array|null $_filter = null;
    private array $_filteredIndices = [];
    private int|null $_lastQuery;
    private bool $_delaySelect = false;
    private int $_currentIndex = 0;
    
    //Model->instanceName has no bearing on the execution of Model. This is here as a user-defined property to help distinguish instances
    //(such as when several Models are stored in an array)
    public string|null $instanceName = null;
    
    public function __construct(PreparedStatement|StatementSet $select = null, PreparedStatement|StatementSet|Transaction $update = null, PreparedStatement|StatementSet|Transaction $insert = null, PreparedStatement|StatementSet|Transaction $delete = null){
        $this->_select = $select;
        if ($update && !($update instanceof Transaction)) {
            $update->queryType = QUERY_UPDATE;
            $update->resultType = VELOX_RESULT_NONE;
        }
        if ($insert && !($insert instanceof Transaction)) {
            $insert->queryType = QUERY_INSERT;
            $insert->resultType = VELOX_RESULT_NONE;
        }
        if ($delete && !($delete instanceof Transaction)) {
            $delete->queryType = QUERY_DELETE;
            $delete->resultType = VELOX_RESULT_NONE;
        }
        $conn = $select->conn ?? $update->conn ?? $insert->conn ?? $delete->conn;
        $this->_select = $select ?? null;
        $this->_update = $update ?? new Transaction($conn);
        $this->_insert = $insert ?? new Transaction($conn);
        $this->_delete = $delete ?? new Transaction($conn);
        $this->_diff = new Diff('{}');
        $this->instanceName = null;
        $this->select();
    }
    
    // Countable implementation
    public function count() : int {
        return count($this->_data);
    }
    
    // Iterator implementation
    public function current() : array {
        return $this->_data[$this->_currentIndex];
    }
    public function key() : int {
        return $this->_currentIndex;
    }
    public function next() : void {
        $this->_currentIndex++;
    }
    public function rewind() : void {
        $this->_currentIndex = 0;
    }
    public function valid() : bool {
        return isset($this->_data[$this->_currentIndex]);
    }
    
    // ArrayAccess implementation
    public function offsetSet(mixed $offset, mixed $row){
        throw new VeloxException('Model rows cannot be inserted by array access. Use Model->insert() instead.',48);
    }
    public function offsetGet(mixed $offset) : array {
        if (!$this->offsetExists($offset)){
            throw new VeloxException("Offset out of bounds",49);
        }
        return $this->_data[$offset];
    }
    public function offsetUnset(mixed $offset) : void {
        $currentRow = $this->_data[$offset];
        $this->delete($currentRow);
        $this->select();
    }
    public function offsetExists(mixed $offset) : bool {
        return isset($this->_data[$offset]);
    }
    
    // Class-specific methods
    public function select() : Diff|bool {
        if (!$this->_select){
            throw new VeloxException('The associated procedure for select has not been defined.',37);
        }
        if ($this->_select->queryType == QUERY_PROC){
            //add criteria to query first   
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
            elseif ($this->_select->results instanceof ResultSet){
                $results = $this->_select->results->getRawData();
            }
            else {
                $results = [];
            }
<<<<<<< HEAD
            
            foreach ($this->submodels as $name => $submodel){
                if (!$this->primaryKey){
                    throw new VeloxException('Primary key column name must be specified for parent Model',41);
                }
                $submodel->select();
                $pk = $this->primaryKey;
                $fk = $submodel->foreignKey;
                $submodel->object->sort($fk,SORT_ASC);
                $fk_column = array_column($submodel->object->data(),$fk);
                if (!$fk_column){
                    throw new VeloxException("Foreign key column '".$fk."' does not exist in submodel.",43);
                }
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
=======
            $this->_data = $results;
            if ($this->_select->results instanceof ResultSet){
                $this->_columns = $this->_select->results->columns();
            }
            if ($this->_filter){
                $this->setFilter($this->_filter);
>>>>>>> 81737c0 (Reverting prior state due to overzealous merge)
            }
            
            if ($diff) {
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
                return $this->_diff;
            }
            else {
                return true;
            }
        }
    }
    
    public function update(array $rows) : bool {
        //$rows is expected to be an array of associative arrays. If the associated update object is a PreparedStatement, each element must be
        // an array of parameter sets ["placeholder"=>"value"]; if the update object is a StatementSet, the array should be Diff-like (each element
        // having "values" and "where" keys with the appropriate structure [see the comments in php/Structures/Diff.php].
        if (!$this->_update){
            throw new VeloxException('The associated procedure for update has not been defined.',37);
        }
<<<<<<< HEAD
        elseif ($hasSubmodels){
            if (!$this->_select){
                throw new VeloxException('Select query required for DML queries on nested Models',40);
            }
            $this->_select();
            //Hold on to the current filter to reapply later
            $currentFilter = $this->_filter;
            //Cache updated submodel names so we only query the ones needed
            $updatedSubmodels = [];
        }
        $currentProcedure = clone $this->_update;
        $reflection = new \ReflectionClass($currentProcedure);
        $statementType = $reflection->getShortName();
        
        switch ($statementType){
            case "PreparedStatement":
                foreach($rows as $row){
                    //Submodel updates are disallowed when the parent Model's update procedure is a PreparedStatement.
                    //PreparedStatement placeholders do not supply the necessary criteria for filtering.
                    $currentProcedure->addParameterSet($row);
                }
                break;
            case "StatementSet":
                if ($hasSubmodels){
                    foreach ($rows as &$row){
                        foreach ($row as $column => $subcriteria){
                            if (is_object($subcriteria)){
                                $this->setFilter($subcriteria);
                                $filteredResults = $this->data();
                                $filteredKeys = array_column($filteredResults,$this->primaryKey);
                                $fk = $this->submodels[$name]->foreignKey;
                                $whereCount = count($subcriteria->where);
                                for ($i=0; $i<$whereCount; $i++){
                                    $subcriteria->where[$i]->$fk = ["IN",$filteredKeys];
                                }
                                $this->submodels[$column]->object->_update->addCriteria($subcriteria);
                                unset ($row[$column]);
                            }
                        }
                    }
                }
                $currentProcedure->addCriteria($rows);
                break;
        }
        
        $transaction = new Transaction;
        $transaction->addQuery($currentProcedure);
        if ($hasSubmodels){
            foreach ($cachedSubmodels as $name){
                $transaction->addQuery($this->submodels[$name]->object->_update);
            }
        }
        $transaction->executeAll();
        
=======
        elseif ($this->_update instanceof PreparedStatement){
            $this->_update->clear();
        }
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
>>>>>>> 81737c0 (Reverting prior state due to overzealous merge)
        if (!$this->_delaySelect){
            $this->select(true);
        }
        return true;
    }
    
<<<<<<< HEAD
    public function insert(array $rows, bool $diff = false, bool $defer = false) : bool {
        $hasSubmodels = !!$this->submodels;
        if (!$this->_insert){
            throw new VeloxException('The associated procedure for insert has not been defined.',37);
        }
        elseif ($hasSubmodels){
            if (!$this->_select){
                throw new VeloxException('Select query required for DML queries on nested Models',40);
            }
            $this->select();
        }
        $transaction = new Transaction;
        $currentProcedure = clone $this->_insert;
        $reflection = new \ReflectionClass($currentProcedure);
        
=======
    public function insert(array $rows) : bool {
        if (!$this->_insert){
            throw new VeloxException('The associated procedure for insert has not been defined.',37);
        }
        elseif ($this->_insert instanceof PreparedStatement){
            $this->_insert->clear();
        }
        $reflection = new \ReflectionClass($this->_insert);
>>>>>>> 81737c0 (Reverting prior state due to overzealous merge)
        switch ($reflection->getShortName()){
            case "PreparedStatement":
                $namedParams = $this->_insert->getNamedParams();
                foreach($rows as $idx => $row){
                    foreach($namedParams as $param){
                        //set nulls for missing parameters of prepared statement
                        $row[$param] = $row[$param] ?? null;
                        
                        //make sure the data passed into named parameters is valid
                        if (is_iterable($row[$param])){
                            throw new VeloxException("Model->insert: Invalid value passed for PreparedStatement parameter.",47);
                        }
<<<<<<< HEAD
                    }
                    if ($hasSubmodels){
                        //Check the row for any nested datasets; cache them in an array and remove them from the row 
                        $submodelDataCache = [];
                        foreach ($row as $column => $value){
                            if (is_array($value)){
                                $submodelDataCache[$column] = $value;
                                unset($row[$column]);
                            }
                        }
=======
                        $this->_insert->addParameterSet($row);
>>>>>>> 81737c0 (Reverting prior state due to overzealous merge)
                    }
                    //If any nested datasets are found (and parameter sets already exist for the current procedure)...
                    if (isset($submodelDataCache) && $currentProcedure->getSetCount() > 0){
                        //Attach the previous PreparedStatement to the Transaction...
                        $transaction->addQuery($currentProcedure);
                        //...then make a fresh clone for this iteration
                        $currentProcedure = clone $this->_insert;
                    }
                    //Add the adjusted row to the current procedure
                    $currentProcedure->addParameterSet($row);
                    
                    if (isset($submodelDataCache)){
                         $parentModel = $this;
                         //Note: bridge function is called during Transaction execution, not as part of this method.
                         $bridge = function(Query &$previous, PreparedStatement|StatementSet &$next) use (&$submodelDataCache, &$parentModel){
                            foreach ($submodelDataCache as $submodelName => $rows){
                                $rowCount = count($rows);
                                $pk_value = $previous->getResults()[0][$parentMode->primaryKey];
                                
                                for ($i=0; $i<$rowCount; $i++){
                                    $fk_name = $submodelDataCache[$submodelName]->foreignKey;
                                    //add primary key values to each foreign key of each submodel insert
                                    if ($next instanceof PreparedStatement){
                                        $paramArray = &$next->getParams();
                                        array_walk($paramArray,function(&$paramSet) use ($fk_name, $pk_value){
                                            $paramSet[$fk_name] = $pk_value;
                                        });
                                    }
                                    elseif ($next instanceof StatementSet){
                                        
                                    }
                                }
                            }
                        }
                        
                        foreach($submodelDataCache as $submodelName => $rows){
                            //Clone the submodel insert procedure, attach the parameters, and add the procedure to the Transaction
                            $proc = $this->submodels[$submodelName]->insert($rows);
                            $transaction->addQuery($proc);
                        }
                        unset($submodelDataCache);
                    }
                }
                break;
            case "StatementSet":
                $currentProcedure->addCriteria($rows);
                $transaction->addQuery($currentProcedure);
                break;
        }
        $transaction->begin();
        $transaction->executeAll();
        
        if (!$this->_delaySelect){
            $this->select(true);
        }
        return true;
    }
    
    public function delete(array $rows) : bool {
        if (!$this->_delete){
            throw new VeloxException('The associated procedure for delete has not been defined.',37);
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
            $this->select(true);
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
        $this->select(true);
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
<<<<<<< HEAD
    public function diff() : Diff {
        return $this->_diff;
    }
    public function addSubmodel(string $name, Model $submodel, string $foreignKey) : void {
        //$name is the desired column name for export
        //$submodel is the Model object to be used as the submodel
        //$foreignKey is the column in the submodel containing the values to be matched against the Model's primary key column
        if (!$this->primaryKey){
            throw new VeloxException('Primary key column name must be specified for parent Model',41);
        }
        if ($name == "" || $foreignKey == ""){
            throw new VeloxException('Name and foreign key arguments cannot be empty strings',42);   
        }
        if ($this->_update instanceof PreparedStatement && isset($submodel->getDefinedQueries()['update'])){
            throw new VeloxException('Submodel updates are not allowed when the parent Model update is a PreparedStatement',45);
        }
        if ($this->_delete instanceof PreparedStatement && isset($submodel->getDefinedQueries()['delete'])){
            throw new VeloxException('Submodel deletes are not allowed when the parent Model delete is a PreparedStatement',45);
        }
        $submodel->instanceName = $name;
        $this->submodels[$name] = (object)['object'=>$submodel,'foreignKey'=>$foreignKey];
    }
=======
>>>>>>> 81737c0 (Reverting prior state due to overzealous merge)
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
