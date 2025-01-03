<?php

namespace KitsuneTech\Velox\Structures;
use KitsuneTech\Velox\VeloxException;
use KitsuneTech\Velox\Database\Procedures\{Query, PreparedStatement, StatementSet, Transaction};
use function KitsuneTech\Velox\Transport\Export as Export;
use function KitsuneTech\Velox\Utility\sqllike_comp as sqllike_comp;

/**
 * The core data storage class for Velox.
 *
 * Each instance of this class holds an iterable dataset composed of the results of the procedure passed to the first
 * argument of its constructor; alternatively, the Model can be directly populated.
 */
class Model implements \ArrayAccess, \Iterator, \Countable {
    
    // Note: in Model::update() and Model::delete(), $where is an array of arrays containing a set of conditions to be OR'd toogether.
    // In Model::update() and Model::insert(), $values is an array of associative arrays, the keys of which are the column names represented
    // in the model. In Model::insert(), any columns not specified are set as NULL.
    private array $_columns = [];
    private array $_data = [];
    private object $_vql;
    private VeloxQL|array|null $_filter = null;
    private array $_filteredIndices = [];
    private int|null $_lastQuery = null;
    private bool $_delaySelect = false;
    private int $_currentIndex = 0;

    /**
     * @var ?string $instanceName An optional identifier (required for any Models involved in a join in which column names overlap)
     */
    public ?string $instanceName = null;

    /**

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
        ?string                                          $instanceName = null){
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
            $this->instanceName = $instanceName;
            $this->_vql = new VeloxQL('{}');
            if (isset($this->_select)) $this->select();
    }

    // Countable implementation
    /**
     * @ignore
     */
    public function count() : int {
        return count($this->_data);
    }
    /**#@-*/

    // Iterator implementation
    /**
     * @ignore
     */
    public function current() : array {
        return $this->_data[$this->_currentIndex];
    }
    /**
     * @ignore
     */
    public function key() : int {
        return $this->_currentIndex;
    }
    /**
     * @ignore
     */
    public function next() : void {
        $this->_currentIndex++;
    }
    /**
     * @ignore
     */
    public function rewind() : void {
        $this->_currentIndex = 0;
    }
    /**
     * @ignore
     */
    public function valid() : bool {
        return isset($this->_data[$this->_currentIndex]);
    }

    // ArrayAccess implementation
    /**
     * @ignore
     */
    public function offsetSet(mixed $offset, mixed $row) : void {
        throw new VeloxException('Model rows cannot be inserted by array access. Use Model->insert() instead.', 48);
    }
    /**
     * @ignore
     */
    public function offsetGet(mixed $offset) : array {
        if (!$this->offsetExists($offset)){
            throw new VeloxException("Offset out of bounds",49);
        }
        return $this->_data[$offset];
    }
    /**
     * @ignore
     */
    public function offsetUnset(mixed $offset) : void {
        $currentRow = $this->_data[$offset];
        $this->delete($currentRow);
        $this->select();
    }
    /**
     * @ignore
     */
    public function offsetExists(mixed $offset) : bool {
        return isset($this->_data[$offset]);
    }

    // Class-specific methods

    /**
     * A private method that executes the appropriate Velox procedures defined by the DML verb specified, using the
     * supplied data.
     *
     * This method is wrapped by public methods which call it with the appropriate DML verb.
     *
     * @param string $verb The DML verb to call (update, insert, delete)
     * @param array $rows The data with which to run the procedure, as an array of associative arrays. If the associated
     * update object is a PreparedStatement, each element must be an array of parameter sets ["placeholder"=>"value"];
     * if the update object is a StatementSet, the array should be VeloxQL-like (each element having "values" and "where"
     * keys with the appropriate structure [see {@see VeloxQL}].
     * @return bool Success/failure
     * @throws VeloxException if the procedure for the given SQL verb has not been defined.
     * @ignore
     */
    private function executeDML(string $verb, array $rows) : bool {
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

    /**
     * Performs a select operation using the designated Velox procedure.
     *
     * The dataset of this Model will be populated with the results of this select. Any data already stored in the Model
     * will be replaced with the updated data from this call. The {@see Model::lastQuery()} timestamp is also updated
     * when the database sends its response.
     *
     * @param bool $vql If this is passed as true, a VeloxQL object will be returned containing the rows inserted
     *     and/or deleted from the previous dataset. (Updates are counted as a deletion of the old row and an insertion
     *     of the updated row.)
     * @return VeloxQL|bool As above, if $vql is passed as true. Otherwise, a boolean representing success or failure.
     * @throws VeloxException if the select procedure is a {@see PreparedStatement} and the procedure returns multiple
     *     result sets. Only one result set may be used to populate a Model.
     */
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
                $this->_vql = new VeloxQL();
                foreach ($this->_data as $index => $row){
                    if (!in_array($row,$results)){
                        unset($this->_data[$index]);
                        $this->_vql->delete[] = (object)$row;
                    }
                }
                foreach($results as $row){
                    if (!in_array($row,$this->_data)){
                        $this->_data[] = $row;
                        $this->_vql->insert[] = (object)$row;
                    }
                }
                return $this->_vql;
            }
            else {
                return true;
            }
        }
        else {
            return false;
        }
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

    /**
     * Perform all operations in the specified VeloxQL object on the data source and update the Model when complete.
     *
     * The procedures will be called in the following order:
     *  * update
     *  * delete
     *  * insert
     *  * select
     *
     * The first three are DML and will run the associated procedures on the data source using the provided criteria;
     * any criteria specified for select will be applied as a filter to the refreshed dataset.
     *
     * @param VeloxQL $vql The VeloxQL instance containing the data/criteria for each operation
     * @return void
     * @throws VeloxException
     */
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

    /**
     * @return array The names of all columns in the current data set
     */
    public function columns() : array {
        return $this->_columns;
    }

    /**
     * @return array The raw dataset as a two-dimensional array, with filter applied if applicable
     */
    public function data() : array {
        if ($this->_filter){
            return array_values(array_intersect_key($this->_data,array_flip($this->_filteredIndices)));
        }
        else {
            return $this->_data;
        }
    }

    /**
     * Returns the number of distinct values in a given column.
     *
     * @param string $column A column whose distinct values are to be counted
     * @return int The number of distinct values in the specified column
     * @throws VeloxException if the specified column doesn't exist
     */
    public function countDistinct(string $column) : int {
        if (count($this->_data) == 0){
            return 0;
        }
        if (!in_array($column,$this->_columns)){
            throw new VeloxException("Column $column does not exist in result set.",38);
        }
        return count(array_unique(array_column($this->_data,$column)));
    }
    public function vql() : VeloxQL {
        return $this->_vql;
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

    /**
     * Renames the specified column.
     *
     * This renaming affects both the column list available with the {@see columns()} method and the individual keys of the underlying data.
     *
     * @param string $oldName The column to be renamed
     * @param string $newName The new name for the column
     * @return void
     * @throws VeloxException if the old column name doesn't exist, the new column name already does, or if one or the
     * other isn't specified.
     */
    public function renameColumn(string $oldName, string $newName) : void {
        if (!$oldName || !$newName) throw new VeloxException("Both old and new column names must be specified.",79);
        if (!in_array($oldName,$this->_columns)) throw new VeloxException("Column '".$oldName."' does not exist in Model.",80);
        if (!in_array($newName,$this->_columns)) throw new VeloxException("Column '".$newName."' already exists in Model.",81);

        /* Replacement by flipping the columns array and setting/unsetting the keys for this and for the underlying dataset */
        $flippedColumns = array_flip($this->_columns);
        $flippedColumns[$newName] = null;
        unset ($flippedColumns[$oldName]);
        $this->_columns = array_keys($flippedColumns);
        $rowCount = count($this);
        for ($i=0; $i<$rowCount; $i++){
            $this[$i][$newName] = $this[$i][$oldName];
            unset ($this[$i][$oldName]);
        }
    }

    /**
     * Performs a pivot-like operation on the current data and returns the result as a new Model.
     *
     * @param string $pivotBy                   The column to be pivoted (containing the intended column names)
     * @param string $indexColumn               The column to be used as an index (the values by which the pivot results will be grouped)
     * @param string $valueColumn               The column in which the pivoted data values are to be found
     * @param array|null $pivotColumns          A set of specific values from $pivotBy to be used; all others will be ignored
     *                                          (if not provided, all unique values from the $pivotBy column will be used)
     * @param bool $ignore                      If true, $pivotColumns is instead treated as a list of columns to be ignored, and all others are included
     * @param bool $suppressColumnException     If true, no exception will be thrown if one or more of the $pivotColumns do not exist in the original dataset;
     *                                          instead, the missing columns will be included with their values set to null
     *
     * @return Model                            A new Model containing the joined dataset (independent of the original Models)
     * @throws VeloxException                   If any of the arguments specified are invalid (see the thrown exception for more details)
     *
     */
    public function pivot(string $pivotBy, string $indexColumn, string $valueColumn, array $pivotColumns = null, bool $ignore = false, bool $suppressColumnException = false) : Model {
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

    /**
     * Performs a join of the specified type between the dataset of this Model and the dataset of the specified Model.
     *
     * @param int $joinType     One of the following constants, representing the type of join to be done: LEFT_JOIN,
     *      RIGHT_JOIN, INNER_JOIN, FULL_JOIN, CROSS_JOIN (with behavior according to SQL standards)
     * @param Model $joinModel  The Model with which the dataset of this Model will be joined
     *
     * @param string|array|null $joinConditions One of the following:
     *   * A string indicating a column name; in this case the join would work in the same manner as the SQL USING clause,
     *       performing an equijoin on columns having that name in each Model and coalescing those columns into one.
     *   * An array containing exactly three elements. The first and third elements must be the names of the columns
     *       on which the join is to be made - the first being the column existing in the invoked Model, the third being
     *       the column existing in the Model to be joined. The second element should be a string containing the SQL
     *       comparison operator to be used; the direction of comparison follows the order of elements. (e.g. `["parentColumn","<","joinedColumn"]`)
     *       All SQL comparison operators are supported. EKIL, EKILR and their NOT inverses are also available as
     *       reverse-order LIKE and RLIKE comparisons (the pattern comes first)
     *   * null - this is only valid if $joinType is CROSS_JOIN, in which case all rows are joined with all rows and no
     *       comparison is necessary or used.
     *
     * @return Model A new Model representing the joined data set
     * @throws VeloxException if the join is improperly specified by the parameters
     */
    public function join(int $joinType, Model $joinModel, string|array|null $joinConditions = null) : Model
    {
        /**
         * changeColumn() replaces the given $oldColumn key with the $newColumn key for each row in a two-dimensional array.
         * This acts directly on the given $array, by reference.
         *
         * @param string $oldColumn The old column key
         * @param string $newColumn The replacement column key
         * @param array $array The array on which the operation is to be performed
         * @return void

         */
        function changeColumn(string $oldColumn, string $newColumn, array &$array) : void {
            $rowCount = count($array);
            for ($i=0; $i<$rowCount; $i++){
                if (isset($array[$i][$oldColumn])){
                    $array[$i][$newColumn] = $array[$i][$oldColumn];
                    unset($array[$i][$oldColumn]);
                }
            }
        }
        //SQL wildcards to be replaced by PCRE equivalents (for use in LIKE/NOT LIKE)
        $wildcards = [
            ["%", "_"],
            [".*", ".{1}"]
        ];
        $comparisons = ["=", "<", ">", "<=", ">=", "<>", "LIKE", "NOT LIKE", "RLIKE", "NOT RLIKE"];

        $left = $this;
        $right = $joinModel;
        $leftColumns = $left->_columns;
        $rightColumns = $right->_columns;

        $returnModel = new Model;

        // --- Initial condition checks (is there anything about the current state that will prevent a successful join?) --- //
        if (!$joinConditions && $joinType !== CROSS_JOIN) {
            throw new VeloxException("Join conditions must be specified", 72);
        }

        $commonColumns = array_values(array_intersect($left->_columns, $right->_columns));
        $commonColumnCount = count($commonColumns);

        $usingEquivalent = false;
        if (is_string($joinConditions)) {
            if (!in_array($joinConditions, $commonColumns)) {
                throw new VeloxException("Join column specified does not exist in both Models", 73);
            }
            else {
                $joinConditions = [$joinConditions, "=", $joinConditions];
                $usingEquivalent = true;
            }
        }
        elseif (is_array($joinConditions)) {
            //If an array is specified for $joinConditions, it must contain exactly the elements needed to perform the join
            if ((function ($arr) { return array_sum(array_map('is_string', $arr)) != 3;})($joinConditions)) {
                throw new VeloxException("Join conditions array must contain exactly three strings", 74);
            }
            if (!in_array($joinConditions[0], $left->_columns)) {
                throw new VeloxException("Left side column does not exist in invoking Model", 75);
            }
            if (!in_array($joinConditions[1], $comparisons)) {
                throw new VeloxException("The provided operator is invalid", 76);
            }
            if (!in_array($joinConditions[2], $right->_columns)) {
                throw new VeloxException("Right side column does not exist in joining Model", 77);
            }
        }

        $leftColumnSubstitutes = [];
        $rightColumnSubstitutes = [];

        if ($commonColumnCount > 0){
            //If there are matching column names in both Models, they aren't part of a USING-equivalent join operation,
            // and the Models do not have distinct instanceName properties, throw an error for ambiguity
            if ((!$usingEquivalent || $commonColumnCount > 1) && (!isset($left->instanceName) || !isset($right->instanceName) || $left->instanceName == $right->instanceName)){
                throw new VeloxException("Identical column names exist in both Models", 78);
            }
            for ($i=0; $i<$commonColumnCount; $i++){
                if ($commonColumns[$i] == $joinConditions[0]){
                    if ($usingEquivalent){
                        continue;
                    }
                    else {
                        $joinConditions[0] = $left->instanceName.".".$joinConditions[0];
                        $joinConditions[2] = $right->instanceName.".".$joinConditions[2];
                    }
                }
                $leftColumnSubstitutes[$commonColumns[$i]] = $left->instanceName.".".$commonColumns[$i];
                $rightColumnSubstitutes[$commonColumns[$i]] = $right->instanceName.".".$commonColumns[$i];
            }
            $leftColumns = str_replace(array_flip($leftColumnSubstitutes), $leftColumnSubstitutes, $leftColumns);
            $rightColumns = str_replace(array_flip($rightColumnSubstitutes), $rightColumnSubstitutes, $rightColumns);
        }

        $mergedColumns = array_unique(array_merge($leftColumns,$rightColumns));

        // --- Perform comparisons and match indices from each side --- //

        $leftData = $left->_data;
        $rightData = $right->_data;

        foreach ($leftColumnSubstitutes as $oldColumn => $newColumn){
            changeColumn($oldColumn, $newColumn, $leftData);
        }
        foreach($rightColumnSubstitutes as $oldColumn => $newColumn){
            changeColumn($oldColumn, $newColumn, $rightData);
        }

        $leftUniqueValues = array_unique(array_column($leftData, $joinConditions[0]));
        $rightUniqueValues = array_unique(array_column($rightData, $joinConditions[2]));
        $joinIndices = [];
        $unjoinedRightIndices = [];

        if ($joinType == RIGHT_JOIN) {
            foreach ($rightUniqueValues as $rightIndex => $rightValue) {
                $currentJoinArray = [];
                $joinFound = false;
                foreach ($leftUniqueValues as $leftIndex => $leftValue) {
                    if (sqllike_comp($leftValue, $joinConditions[1], $rightValue)) {
                        $joinFound = true;
                        $currentJoinArray[] = $leftIndex;
                    }
                }
                if ($joinFound) $joinIndices[$rightIndex] = $currentJoinArray;
            }
            [$leftData, $rightData] = [$rightData, $leftData];  //Swap the join order and proceed as a left join
        }
        elseif ($joinType != CROSS_JOIN) {
            $unjoinedRightIndices = array_flip(array_keys($rightData));
            foreach ($leftUniqueValues as $leftIndex => $leftValue) {
                $currentJoinArray = [];
                $joinFound = false;
                foreach ($rightUniqueValues as $rightIndex => $rightValue) {
                    if (sqllike_comp($leftValue, $joinConditions[1], $rightValue)) {
                        $joinFound = true;
                        $currentJoinArray[] = $rightIndex;
                        unset($unjoinedRightIndices[$rightIndex]);
                    }
                }
                if ($joinFound) $joinIndices[$leftIndex] = $currentJoinArray;
            }
            $unjoinedRightIndices = array_values(array_flip($unjoinedRightIndices));
            $unjoinedRightRowCount = count($unjoinedRightIndices);
        }

        // --- Assemble joined data set based on matched indices --- //

        $joinRows = [];
        $emptyLeftRow = array_map(function ($elem) { return null; }, array_flip($leftColumns));
        $emptyRightRow = array_map(function ($elem) { return null; }, array_flip($rightColumns));

        $leftRowCount = count($leftData);
        $rightRowCount = count($rightData);

        if ($joinType == CROSS_JOIN){
            for ($i=0; $i<$leftRowCount; $i++){
                for ($j=0; $j<$rightRowCount; $j++){
                    $joinRows[] = array_merge($leftData[$i], $rightData[$j]);
                }
            }
        }
        else {
            for ($i = 0; $i < $leftRowCount; $i++) {
                $currentLeftRow = $leftData[$i];
                //Inner join
                if (isset($joinIndices[$i])) {
                    $rightJoinCount = count($joinIndices[$i]);
                    for ($j = 0; $j < $rightJoinCount; $j++) {
                        $joinRows[] = array_merge($currentLeftRow, $rightData[$joinIndices[$i][$j]]);
                    }
                } //Left/right outer join
                elseif ($joinType != INNER_JOIN) {
                    $joinRows[] = array_merge($joinType == RIGHT_JOIN ? $emptyLeftRow : $emptyRightRow, $currentLeftRow);
                }
            }
            if ($joinType == FULL_JOIN) {
                for ($i = 0; $i < $unjoinedRightRowCount; $i++) {
                    $joinRows[] = array_merge($emptyLeftRow, $rightData[$unjoinedRightIndices[$i]]);
                }
            }
        }
        $returnModel->_data = $joinRows;
        $returnModel->_columns = $mergedColumns;
        $returnModel->_lastQuery = time();
        return $returnModel;
    }

    /**
     * @return int|null The Unix timestamp, in integer seconds, when the response was received from the Model's data source
     * during the most recent {@see: select()} call, or null if such a call has yet to be made.
     */
    public function lastQuery() : ?int {
        return $this->_lastQuery;
    }
    public function export(int $flags = TO_BROWSER+AS_JSON, ?string $fileName = null, ?int $ignoreRows = 0, bool $noHeader = false) : string|bool {
        return Export($this,$flags,$fileName,$ignoreRows,$noHeader);
    }
}
