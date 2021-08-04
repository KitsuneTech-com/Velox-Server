<?php

namespace KitsuneTech\Velox\Structures;
use KitsuneTech\Velox\VeloxException as VeloxException;
use KitsuneTech\Velox\Database\Procedures\{PreparedStatement, StatementSet, Transaction};
use function KitsuneTech\Velox\Transport\Export as Export;
use function KitsuneTech\Velox\Utility\sqllike_comp as sqllike_comp;

class Model implements \ArrayAccess, \Iterator, \Countable {
    
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
    private int $_currentIndex = 0;
    private bool $_invertFilter = false;
    
    //Model->returnDiff controls whether a Model->export returns a full resultset or just the rows that have been changed with the previous DML call
    // (false by default: returns full resultset)
    public bool $returnDiff = false;
    
    //Model->submodels is public for the sake of reference by Export. This property should not be modified directly by user-defined code.
    public array $submodels = [];
    
    //Used to join nested Models by a specific column. These will automatically be utilized if submodels are present.
    public ?string $primaryKey = null;
        
    public function __construct(
            public PreparedStatement|StatementSet|null $_select = null,
            public PreparedStatement|StatementSet|Transaction|null $_update = null,
            public PreparedStatement|StatementSet|Transaction|null $_insert = null,
            public PreparedStatement|StatementSet|Transaction|null $_delete = null,
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
    
    public function update(array $rows, bool $isDiff = false) : bool {
        //$rows is expected to be an array of associative arrays. If the associated update object is a PreparedStatement, each element must be
        // an array of parameter sets ["placeholder"=>"value"]; if the update object is a StatementSet, the array should be Diff-like (each element
        // having "values" and "where" keys with the appropriate structure [see the comments in php/Structures/Diff.php].
        $hasSubmodels = !!$this->submodels;
        
        if (!$this->_update){
            throw new VeloxException('The associated procedure for update has not been defined.',37);
        }
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
                    if ($isDiff){
                        //Set properly prefixed placeholders
                        //(UPDATE table SET column = :v_column WHERE column2 = :w_column2)
                        //(CALL updateTable(:v_column, :op_column2, :w_column2)
                        // ** Note: operators can't be dynamically added to SQL statements through prepared statements,
                        // so something like "UPDATE table SET column = :v_column WHERE column2 :op_column2 :w_column"
                        // won't work. This will attempt to pass 
                        $newRow = [];
                        $namedParams = $currentProcedure->getNamedParams();
                        foreach ($row['values'] as $column => $value){
                            $newRow['v_'.$column] = $value;
                        }
                        foreach ($row['where'] as $column => $condition){
                            $newRow['op_'.$column] = $condition[0];
                            $newRow['w_'.$column] = $condition[1];
                        }
                        foreach (array_keys($newRow) as $placeholder){
                            if (!isset($namedParams[$placeholder]) || !str_starts_with($placeholder,'op_')){
                                
                        $row = $newRow;
                    }
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
        
        if (!$this->_delaySelect){
            $this->select();
        }
        return true;
    }
    
    public function insert(array $rows) : bool {
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
        //Get the current statement type
        $reflection = new \ReflectionClass($currentProcedure);
        $statementType = $reflection->getShortName();
        
        if (isset($rows[0]['values']) && is_object($rows[0]['values'])){
            //If $rows is in the form of a Diff-like array, extract only the 'values' properties
            $rows = array_column($rows,'values');
        }
        $transaction = new Transaction;
        $currentProcedure = clone $this->_insert;
        if ($statementType == "PreparedStatement"){
            //set nulls for missing parameters of PreparedStatement
            $namedParams = $this->_insert->getNamedParams();
            foreach ($rows as &$row){
                foreach($namedParams as $param){
                    $row[$param] = $row[$param] ?? null;
                    //also make sure the data passed into named parameters is valid (so as to avoid any collision with submodels)
                    if (is_iterable($row[$param])){
                        throw new VeloxException("Model->insert: Invalid value passed for PreparedStatement parameter.",47);
                    }
                }
            }
        }
        //Check for submodel data; separate and cache it
        $submodelDataCache = [];
        if ($hasSubmodels){
            foreach ($rows as $idx => $row){
                //Check the row for any nested datasets; cache them in an array and remove them from the row 
                $submodelDataCache[$idx] = [];
                foreach ($row as $key => $value){
                    if (is_array($value)){
                        if (!array_key_exists($key,$this->submodels)){
                            throw new VeloxException("Model->insert: Array passed as value without corresponding submodel.",50);
                        }
                        $submodelDataCache[$idx][$column] = $value;
                        unset($row[$column]);
                    }
                }
            }
        }
        
        //If any nested datasets are found (and if rows have already been added to the current procedure)...
        if ($submodelDataCache){
            switch ($statementType){
                case "PreparedStatement":
                    $rowsExist = !!$currentProcedure->getSetCount();
                    break;
                case "StatementSet":
                    $rowsExist = !!$currentProcedure->criteria;
                    break;
                case "Transaction":
                    $rowsExist = !!$currentProcedure->input;
                    break;
            }
            if ($rowsExist){
                //Attach the previous procedure to the Transaction...
                $transaction->addQuery($currentProcedure);
                //...then make a fresh clone for this iteration
                $currentProcedure = clone $this->_insert;
            }
        }
        
        //Add the adjusted row to the current procedure
        foreach ($rows as $row){
            switch ($statementType){
                case "PreparedStatement":
                    $currentProcedure->addParameterSet($row);
                    break;
                case "StatementSet":
                    $currentProcedure->addCriteria($row);
                    break;
                case "Transaction":
                    $currentProcedure->addInput($row);
                    break;
            }
        }
        //Add submodel handling, if any
        if (isset($submodelDataCache)){
             $parentModel = &$this;
             $parentProcedure = &$currentProcedure;
             //Note: bridge function is called during Transaction execution, not as part of this method.
             // ---------------------------------------------------------------------------------------- //
             $bridge = function(Query &$previous, PreparedStatement|StatementSet|Transaction &$next) use ($submodelDataCache, &$parentModel, &$parentProcedure){
                foreach ($submodelDataCache as $submodelName => $rows){
                    $rowCount = count($rows);
                    //Using the imported $parentProcedure rather than $previous because $previous may be a sibling procedure rather than the parent
                    //(if multiple submodels are being inserted into, and this isn't the first of them)
                    $pk_value = $parentProcedure->getResults()[0][$parentModel->primaryKey];

                    for ($i=0; $i<$rowCount; $i++){
                        $fk_name = $submodelDataCache[$submodelName]->foreignKey;
                        //add primary key values to each foreign key of each submodel insert
                        if ($next instanceof PreparedStatement){
                            $paramArray = &$next->getParams();
                            foreach ($paramArray as &$paramSet){
                                $paramSet[$fk_name] = $pk_value;
                            }
                        }
                        elseif ($next instanceof StatementSet){
                            $criteriaArray = &$next->criteria;
                            foreach ($criteriaArray as &$criteriaSet){
                                $criteriaSet['values'][$fk_name] = $pk_value;
                            }
                            $next->setStatements();
                        }
                        elseif ($next instanceof Transaction){
                            $inputArray = &$next->input;
                            //Transaction input can be either in the form of PreparedStatement parameter sets
                            //or StatementSet criteria. We don't know what's there, so we can't make assumptions.
                            foreach ($inputArray as &$input){
                                if (array_key_exists('values',$input) && is_array($input['values'])){
                                    $input['values'][$fk_name] = $pk_value;
                                }
                                else {
                                    $input[$fk_name] = $pk_value;
                                }
                            }
                        }
                    }
                }
            }
            // ---------------------------------------------------------------------------------------- //
            
            foreach($submodelDataCache as $submodelName => $rows){
                //Clone the submodel insert procedure, attach the parameters, and add the procedure to the Transaction
                $transaction->addFunction($bridge);
                $proc = $this->submodels[$submodelName]->insert($rows);
                $transaction->addQuery($proc);
            }
            unset($submodelDataCache);
        }
        $transaction->begin();
        $transaction->executeAll();
        
        if (!$this->_delaySelect){
            $this->select();
        }
        return true;
    }
    
    public function delete(array $rows) : bool {
        if (!$this->_delete){
            throw new VeloxException('The associated procedure for delete has not been defined.',37);
        }
        elseif (!!$this->_submodels){
            if (!$this->_select){
                throw new VeloxException('Select query required for DML queries on nested Models',40);
            }
            $this->_filter = $rows;
            $this->select();
            $submodelDeletions = [];
            foreach($rows as $row){
                foreach ($row as $column => $data){
                    if (isset($this->_submodels[$column])){
                        if ($this->_submodels[$column]->deleteProtected && $data){
                            throw new VeloxException('Attempted to delete parent row of protected submodel',51);
                        }
                        elseif ($this->_submodels[$column]->deleteProtected === false){
                            if (!isset($submodelDeletions[$column])){
                                $submodelDeletions[$column] = [];
                            }
                            //$submodelDeletions[$column][] = [$this->_submodels[$column]->foreignKey => $data[
                        }
                    }
                }
            }
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
            case "Transaction":
                $this->_delete->addInput($rows);
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
            $this->update($diff->update,true);
        }
        if ($diff->delete) {
            $this->delete($diff->delete,true);
        }
        if ($diff->insert) {
            $this->insert($diff->insert,true);
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
            $args = [$this->_data, array_flip($this->_filteredIndices)];
            $filteredElements = $this->_invertFilter ? array_diff_key(...$args) : array_intersect_key(...$args);
            return array_values($filteredElements);
        }
        else {
            return $this->_data;
        }
    }
    public function diff() : Diff {
        return $this->_diff;
    }
    public function addSubmodel(string $name, Model $submodel, string $foreignKey, ?bool $deleteProtected = null) : void {
        //$name is the desired column name for export
        //$submodel is the Model object to be used as the submodel
        //$foreignKey is the column in the submodel containing the values to be matched against the Model's primary key column
        //$deleteProtected is a nullable boolean that determines whether DELETEs on the parent Model should be:
        //    true: restricted (any attempt to delete parent Model rows whose primary key matches a defined foreign key in the submodel will be disallowed)
        //    false: cascaded (all rows in the submodel with foreign keys that match the primary key of a deleted parent Model row will
        //        also be deleted
        //    null: the Model will not make any foreign key checks prior to parent Model row deletion. Any such checks are deferred to the database.
        //    *** Note: this should NOT be allowed to be null if no foreign key constraints exist on the database (such as if the submodel is derived from
        //    a different database). Either true or false should be selected as appropriate, in order to preserve integrity. ***
        //    If $deleteProtected is set to true, the parent Model row can still be deleted *if*, after any submodel deletions, no foreign key matches remain.
        //    If the parent Model has multiple submodels, if any one of them has $deleteProtected set to true, deletion cannot proceed unless no foreign key
        //    matches exist for that submodel, regardless of the value of $deleteProtected on the others.
        
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
        $this->submodels[$name] = (object)['object'=>$submodel,'foreignKey'=>$foreignKey,'deleteProtected'=>$deleteProtected];
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
                    if (!!$this->_submodels){
                        if (isset($this->_submodels[$column])){
                            if (!is_object($criteria)){
                                $reflection = new \ReflectionClass($criteria);
                                $variableType = $reflection->getShortName();
                                throw new VeloxException("Object expected for submodel column, ".$variableType." found.",52);
                            }
                            else {
                                $this->_submodels[$column]->object->setFilter([["where"=>[$criteria]]]);
                                if (!$this->_submodels[$column]->object->data()){
                                    continue 2;
                                }
                            }
                        }
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
                        case "IN":
                            if (!in_array($row[$column],$criteria[2])){
                                continue 3;
                            }
                            break;
                        case "NOT IN":
                            if (in_array($row[$column],$criteria[2])){
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
    public function invertFilter() : void {
        $this->_invertFilter = !$this->_invertFilter;
    }
    public function lastQuery() : ?int {
        return $this->_lastQuery;
    }
    public function getDefinedQueries() : array {
        return ['select'=>&$this->_select, 'update'=>&$this->_update, 'insert'=>&$this->_insert, 'delete'=>&$this->_delete];
    }
    public function export(int $flags = TO_BROWSER+AS_JSON, ?string $fileName = null, ?int $ignoreRows = 0, bool $noHeader = false) : string|bool {
        return Export($this,$flags,$fileName,$ignoreRows,$noHeader);
    }
}
