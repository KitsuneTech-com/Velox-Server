<?php

namespace KitsuneTech\Velox\Structures;
use KitsuneTech\Velox\VeloxException as VeloxException;
use KitsuneTech\Velox\Database\Procedures\{Query, PreparedStatement, StatementSet, Transaction};
use function KitsuneTech\Velox\Transport\Export as Export;
use function KitsuneTech\Velox\Utility\sqllike_comp as sqllike_comp;

class Model implements \ArrayAccess, \Iterator, \Countable {
    
    // Note: in Model::update() and Model::delete(), $where is an array of arrays containing a set of conditions to be OR'd toogether.
    // In Model::update() and Model::insert(), $values is an array of associative arrays, the keys of which are the column names represented
    // in the model. In Model::insert(), any columns not specified are set as NULL.
    private array $_columns = [];
    private array $_data = [];
    private object $_diff;
    private Diff|array|null $_filter = null;
    private array $_filteredIndices = [];
    private int|null $_lastQuery;
    private bool $_delaySelect = false;
    private int $_currentIndex = 0;
    
    public function __construct(
        private PreparedStatement|StatementSet|null             $_select = null,
        private PreparedStatement|StatementSet|Transaction|null $_update = null,
        private PreparedStatement|StatementSet|Transaction|null $_insert = null,
        private PreparedStatement|StatementSet|Transaction|null $_delete = null,
        public ?string                                          $instanceName = null){
            $props = ["_select","_update","_insert","_delete"];
            foreach($props as $prop){
                if (isset($this->$prop)){
                    if ($this->$prop->queryType != Query::QUERY_PROC){
                        $this->$prop->queryType = constant("KitsuneTech\Velox\Database\Procedures\Query::QUERY".strtoupper($prop));
                    }
                    if ($prop != "_select" && $this->$prop instanceof PreparedStatement){
                        $this->$prop->resultType = Query::RESULT_NONE;
                    }
                }
            }
            $this->_diff = new Diff('{}');
            if (isset($this->_select)) $this->select();
    }
    
    // Countable implementation
    public function count() : int {
        return count($this->_data);
    }

    public function countDistinct(string $column) : int {
        if (count($this->_data) == 0){
            return 0;
        }
        if (!in_array($column,$this->_columns)){
            throw new VeloxException("Column $column does not exist in result set.",38);
        }
        return count(array_unique(array_column($this->_data,$column)));
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
    public function offsetSet(mixed $offset, mixed $row) : void {
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
    public function select(bool $diff = false) : Diff|bool {
        if (!$this->_select){
            throw new VeloxException('The associated procedure for select has not been defined.',37);
        }
        if ($this->_select->queryType == Query::QUERY_PROC){
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
                        throw new VeloxException('The PreparedStatement returned multiple result sets. Make sure that $resultType is set to Query::RESULT_DISTINCT or Query::RESULT_UNION.',29);
                }
            }
            elseif ($this->_select->results instanceof ResultSet){
                $this->_data = $this->_select->results->getRawData();
                $this->_columns = $this->_select->results->columns();
            }
            else {
                $this->_data = [];
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
        else {
            return false;
        }
    }

    private function executeDML(string $verb, array $rows) : bool {
        //$rows is expected to be an array of associative arrays. If the associated update object is a PreparedStatement, each element must be
        // an array of parameter sets ["placeholder"=>"value"]; if the update object is a StatementSet, the array should be Diff-like (each element
        // having "values" and "where" keys with the appropriate structure [see the comments in php/Structures/Diff.php].

        //This method is not called directly. Rather, each of the three DML methods (insert, update, delete) calls it with the appropriate verb.

        $procedure = $this->{'_'.$verb};
        if (!$procedure){
            throw new VeloxException("The associated procedure for $verb has not been defined.",37);
        }
        $currentProcedure = clone $procedure;
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
                $currentProcedure->addCriteria($rows);
                $currentProcedure->setStatements();
                break;
        }
        $currentProcedure();

        if (!$this->_delaySelect){
            $this->select();
        }
        return true;
    }
    public function update(array $rows) : bool {
        return $this->executeDML("update", $rows);
    }
    public function insert(array $rows) : bool {
        return $this->executeDML("insert", $rows);
    }
    public function delete(array $rows) : bool {
        return $this->executeDML("delete", $rows);
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
    public function setFilter(Diff|array|null $filter = null) : void {
        $this->_filter = $filter instanceof Diff ? $filter->select : (!is_null($filter) ? $filter : []);
        $this->_filteredIndices = [];
        if (!$this->_filter){
            return;
        }
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
    public function pivot(string $pivotBy, string $indexColumn, string $valueColumn, array $pivotColumns = null, bool $ignore = false, bool $suppressColumnException = false) : Model {
        // This method performs a pivot-like operation on the current data and returns the result as a new Model.
        //
        // Arguments (in order):
        //   $pivotBy (required)                 - a string containing the name of the column to be pivoted (containing the intended column names)
        //   $indexColumn (required)             - a string containing the name of the column to be used as an index (the values by which the pivot results will
        //                                         be grouped)
        //   $valueColumn (required)             - a string containing the name of the column in which the pivoted data values are to be found
        //   $pivotColumns (optional)            - an array of specific values from $pivotBy (i.e., intended columns) to be used; all others will be ignored
        //                                         (if not provided, all unique values from the $pivotBy column will be used)
        //   $ignore (optional)                  - if set to true, $pivotColumns is instead treated as a list of columns to be ignored, and all others are included
        //   $suppressColumnException (optional) - if set to true, no exception will be thrown if one or more of the $pivotColumns do not exist in the original dataset;
        //                                         instead, the missing columns will be included with their values set to null

        $outputModel = new Model;
        $rowCount = count($this->_data);
        if ($rowCount == 0){
            return $outputModel; //Don't bother trying to pivot an empty Model; just return
        }
        //Check if $pivotBy column exists in data set
        $pivotByColumn = array_column($this->_data,$pivotBy);
        if (count($pivotByColumn) == 0){
            throw new VeloxException("Specified pivot-by column '$pivotBy' does not exist in result set.",68);
        }
        $pivotByValues = array_unique($pivotByColumn);

        //Check if $indexColumn column exists in data set
        $indexValues = array_unique(array_column($this->_data,$indexColumn));
        if (count($indexValues) == 0){
            throw new VeloxException("Index column '$indexColumn' does not exist in result set.",69);
        }
        if (is_null($pivotColumns)){
            $pivotColumns = $pivotByValues;
        }
        if ($ignore){
            $pivotColumns = array_diff($pivotByValues,$pivotColumns);
        }

        //Check if $valueColumn column exists in data set
        $values = array_column($this->_data,$valueColumn);
        if (count($values) == 0){
            throw new VeloxException("Value column '$valueColumn' does not exist in result set.",70);
        }

        //Check whether all given columns exist in the $pivotBy column
        $diff = array_diff($pivotColumns,$pivotByValues);
        if (count($diff) > 0 && !$suppressColumnException){
            throw new VeloxException("Value(s) ".implode(",",$diff)." specified in pivot columns array do not exist in $pivotBy column.",71);
        }

        $flippedColumns = array_flip($pivotColumns);
        foreach ($flippedColumns as $name=>$type){
            $flippedColumns[$name] = false; //Does the column contain any text? Default is false until non-numeric data is found
        }

        //Iterate once through the rows to determine if any of the above needs to be set to true
        for ($i=0; $i<$rowCount; $i++){
            $row = $this->_data[$i];
            if (!$flippedColumns[$row[$pivotBy]] && !is_numeric($row[$valueColumn])){
                $flippedColumns[$row[$pivotBy]] = true;
            }
        }

        $expanded = [];
        for ($i=0; $i<$rowCount; $i++){
            $row = $this->_data[$i];
            $currentIdx = $row[$indexColumn];
            if (!isset($expanded[$currentIdx])){
                $expanded[$currentIdx] = [];
            }
            if (isset($flippedColumns[$row[$pivotBy]])){
                if (isset($expanded[$currentIdx][$row[$pivotBy]])){
                    //summation depends on column data type - numbers should be added and everything else should be concatenated
                    if ($flippedColumns[$row[$pivotBy]]){
                        $expanded[$currentIdx][$row[$pivotBy]] .= ",".$row[$valueColumn];
                    }
                    else {
                        $expanded[$currentIdx][$row[$pivotBy]] += $row[$valueColumn];
                    }
                }
                else {
                    $expanded[$currentIdx][$row[$pivotBy]] = $row[$valueColumn];
                }
            }
        }
        //Fill in any gaps with nulls
        foreach($expanded as $idx=>$value){
            for ($i=0; $i<count($pivotColumns); $i++){
                if (!isset($expanded[$idx][$pivotColumns[$i]])){
                    $expanded[$idx][$pivotColumns[$i]] = null;
                }
            }
        }

        $outputModel->_data = $expanded;
        return $outputModel;
    }
    public function lastQuery() : ?int {
        return $this->_lastQuery;
    }
    public function export(int $flags = TO_BROWSER+AS_JSON, ?string $fileName = null, ?int $ignoreRows = 0, bool $noHeader = false) : string|bool {
        return Export($this,$flags,$fileName,$ignoreRows,$noHeader);
    }
}
