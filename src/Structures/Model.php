<?php

namespace KitsuneTech\Velox\Structures;
use KitsuneTech\Velox\VeloxException;
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
    private VeloxQL|array|null $_filter = null;
    private array $_filteredIndices = [];
    private int|null $_lastQuery = null;
    private bool $_delaySelect = false;
    private int $_currentIndex = 0;

    /**
     * Model is the core data storage class for Velox. Each instance of this class holds an iterable dataset composed of the results of
     * the procedure passed to the first argument of its constructor; alternatively, the Model can be directly populated
     *
     *
     * @param PreparedStatement|StatementSet|null $_select              The SELECT-equivalent procedure used to populate the Model
     * @param PreparedStatement|StatementSet|Transaction|null $_update  The procedure used to UPDATE the database from the Model
     * @param PreparedStatement|StatementSet|Transaction|null $_insert  The procedure used to INSERT new records into the database from the Model
     * @param PreparedStatement|StatementSet|Transaction|null $_delete  The procedure used to DELETE records removed from the Model
     * @param string|null $instanceName                                 An optional identifier (required for any Models involved in a join in which column names overlap)
     * @throws VeloxException                                           if the initial SELECT procedure throws an exception
     */
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
            $this->_diff = new VeloxQL('{}');
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
    public function select(bool $vql = false) : VeloxQL|bool {
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
            
            if ($vql) {
                $this->_diff = new VeloxQL();
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
        // an array of parameter sets ["placeholder"=>"value"]; if the update object is a StatementSet, the array should be VeloxQL-like (each element
        // having "values" and "where" keys with the appropriate structure [see the comments in php/Structures/VeloxQL.php].

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
    
    public function synchronize(VeloxQL $vql) : void {
        $this->_delaySelect = true;
        $operations = ["update","delete","insert","select"]; //Perform operations in this order
        for ($i=0; $i<count($operations); $i++){
            if ($operations[$i] !== "select"){
                $this->executeDML($operations[$i],$vql->{$operations[$i]});
            }
            else {
                $this->setFilter($vql);
            }
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
    public function diff() : VeloxQL {
        return $this->_diff;
    }
    public function setFilter(VeloxQL|array|null $filter = null) : void {
        $this->_filter = $filter instanceof VeloxQL ? $filter->select : (!is_null($filter) ? $filter : []);
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
            throw new VeloxException("Specified pivot-by column '$pivotBy' does not exist in dataset.",68);
        }
        $pivotByValues = array_values(array_unique($pivotByColumn));

        //Check if $indexColumn column exists in data set
        $indexValues = array_unique(array_column($this->_data,$indexColumn));
        if (count($indexValues) == 0){
            throw new VeloxException("Index column '$indexColumn' does not exist in dataset.",69);
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
            throw new VeloxException("Value column '$valueColumn' does not exist in dataset.",70);
        }

        //Check whether all given columns exist in the $pivotBy column
        $vql = array_diff($pivotColumns,$pivotByValues);
        if (count($vql) > 0 && !$suppressColumnException){
            throw new VeloxException("Value(s) ".implode(",",$vql)." specified in pivot columns array do not exist in $pivotBy column.",71);
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
                $expanded[$currentIdx] = [$indexColumn => $currentIdx]; //This is redundant for now, but allows us to reindex the array when we're done
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
        foreach($expanded as $idx=>$row){
            for ($i=0; $i<count($pivotColumns); $i++){
                if (!isset($row[$pivotColumns[$i]])){
                    $expanded[$idx][$pivotColumns[$i]] = null;
                }
            }
        }
        //Reindex results sequentially
        $expanded = array_values($expanded);

        $outputModel->_data = $expanded;
        $outputModel->_columns = [$indexColumn, ...$pivotColumns];

        return $outputModel;
    }
    public function join(int $joinType, Model $joinModel, array|string|null $joinConditions = null) : Model {
        //SQL wildcards to be replaced by PCRE equivalents (for use in LIKE/NOT LIKE)
        $wildcards = [
            ["%","_"],
            [".*",".{1}"]
        ];
        $joinFunctions = [
            LEFT_JOIN => function(){},
            RIGHT_JOIN => function(){},
            INNER_JOIN => function(){},
            FULL_JOIN => function(){},
            CROSS_JOIN => function(){}
        ];
        $comparisons = [
            "=" => function($a,$b){ return $a == $b; },
            ">" => function($a,$b){ return $a > $b; },
            "<" => function($a,$b){ return $a < $b; },
            ">=" => function($a,$b){ return $a >= $b; },
            "<=" => function($a,$b){ return $a <= $b; },
            "<>" => function($a,$b){ return $a != $b; },
            "LIKE" => function($a,$b) use ($wildcards) {
                //Convert SQL wildcards in $b to PCRE equivalents, add bookends to make it an exact match
                $b = "^".str_replace($wildcards, "", $b)."$";
                return preg_match($b,$a);
            },
            "NOT LIKE" => function($a,$b) use ($wildcards) {
                $b = "^".str_replace($wildcards, "", $b)."$";
                return !preg_match($b,$a);
            }
        ];
        $returnModel = new Model;
        //$joinConditions can be:
        //  a string indicating a column name; in this case the join would work in the same manner as the SQL USING clause,
        //      performing an equijoin on columns having that name in each Model and coalescing those columns into one.
        //  an array; this array must have three elements. The first and third elements must be the names of the columns
        //      on which the join is to be made - the first being the column existing in the invoked Model, the third being
        //      the column existing in the Model to be joined. The second element should be a string containing the SQL
        //      comparison operator to be used; the direction of comparison follows the order of elements.
        //          e.g. ["parentColumn","<","joinedColumn"]
        //      All SQL comparison operators are supported.
        //  null - this is only valid if $joinType is CROSS_JOIN, in which case all rows are joined with all rows and no
        //      comparison is necessary or used.

        // --- Initial condition checks (is there anything about the current state that will prevent a successful join?) --- //
        if (!$joinConditions && $joinType !== CROSS_JOIN){
            throw new VeloxException("Join conditions must be specified",72);
        }
        $commonColumns = array_intersect($this->columns(),$joinModel->columns());
        if (is_string($joinConditions) && !in_array($joinConditions, $commonColumns)){
            throw new VeloxException("Join column specified does not exist in both Models",73);
        }
        elseif (is_array($joinConditions)){
            //If an array is specified for $joinConditions, it must contain exactly the elements needed to perform the join
            if ((function($arr){ return array_sum(array_map('is_string',$arr)) == 3; })($joinConditions)){
                throw new VeloxException("Join conditions array must contain exactly three strings",74);
            }
            if (!in_array($joinConditions[0],$this->columns())){
                throw new VeloxException("Left side column does not exist in invoking Model",75);
            }
            if (!in_array($joinConditions[1],array_keys($comparisons))){
                throw new VeloxException("The provided operator is invalid",76);
            }
            if (!in_array($joinConditions[2],$joinModel->columns())){
                throw new VeloxException("Right side column does not exist in joining Model",77);
            }
        }
        //If there are matching column names in both Models and they aren't part of the join operation (or if they are,
        //the join is ON-equivalent, and the Models do not have distinct instanceName properties), throw an error for ambiguity
        $commonColumnCount = count($commonColumns);
        if ($commonColumnCount > 1 ||
            $commonColumnCount == 1 && is_array($joinConditions) && $this->instanceName == $joinModel->instanceName){
            throw new VeloxException("Identical column names exist in both Models",78);
        }

        //Create a merged column list
        $mergedColumns = $this->_columns + $joinModel->_columns;

        //Define the left and right sides of the join
        $left = $this;
        $right = $joinModel;

        switch ($joinType){
            case RIGHT_JOIN:
                //A right join is simply a left join with the sides flipped, so swap the left and right Models and proceed
                $right = $this;
                $left = $joinModel;
            case LEFT_JOIN:
                //Everything from $this and only those rows from $joinModel that match $joinConditions
                foreach ($left as $row){

                }
                break;
            case INNER_JOIN:
                //Only those rows that exactly match $joinCondition
                foreach ($left as $row){

                }
                break;
            case FULL_JOIN:
                foreach ($left as $row){

                }
                //All rows from both $this and $joinModel, matched on $joinCondition where possible
                break;
            case CROSS_JOIN:
                //Every row from $this matched with every row from $joinModel, irrespective of $joinCondition
                foreach ($left as $row){

                }
                break;
        }
        return $returnModel;
    }
    public function lastQuery() : ?int {
        return $this->_lastQuery;
    }
    public function export(int $flags = TO_BROWSER+AS_JSON, ?string $fileName = null, ?int $ignoreRows = 0, bool $noHeader = false) : string|bool {
        return Export($this,$flags,$fileName,$ignoreRows,$noHeader);
    }
}
