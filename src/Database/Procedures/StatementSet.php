<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Database\Procedures;

use KitsuneTech\Velox\VeloxException;
use KitsuneTech\Velox\Database\Connection as Connection;
use KitsuneTech\Velox\Database\Procedures\{Query, Transaction};
use KitsuneTech\Velox\Structures\{Diff, ResultSet};
use function KitsuneTech\Velox\Utility\{recur_ksort, isAssoc};

/** `StatementSet` is a class that dynamically generates a collection of related PreparedStatements. This is best used for
 * queries that may have an unknown number of parameters, such as a search query. The standard SQL syntax is augmented
 * with AngularJS-style placeholders for the contents of typical clauses; these placeholders are substituted at execution
 * time with the appropriate prepared statement syntax based on the content of the criteria set assigned to it. This also
 * supports the use of all standard SQL comparisons, including IN, NOT IN, BETWEEN, NOT BETWEEN, and LIKE, and also provides
 * a reverse-pattern lookup for LIKE and RLIKE ("EKIL" and "ELIKR" respectively).
 *
 * StatementSet placeholders are as follows:
 *  - <<columns>>: The columns to be inserted
 *  - <<values>>: The values to be inserted or updated
 *  - <<condition>>: The condition to be used in a WHERE clause
 *
 * These should be used where the appropriate clause(s) would normally be used in a standard SQL statement:
 * - SELECT <<columns>> FROM myTable WHERE <<condition>>
 * - INSERT INTO myTable (<<columns>>) VALUES (<<values>>)
 * - UPDATE myTable SET <<values>> WHERE <<condition>>
 * - DELETE FROM myTable WHERE <<condition>>
 *
 * Criteria are passed in as an associative array. This array should contain either or both of two keys, as appropriate
 * for the query type:
 * - "values": An array of associative arrays, with the keys being the column names and the values being the values to be
 *  inserted or updated.
 * - "where": An array of associative arrays, the construction of which determines how the corresponding <<condition>> is
 * constructed. Every array in the "where" array represents a set of conditions that will be joined by AND; each set of
 * conditions is joined by OR. The keys of each set of conditions are the column names, and the values are arrays having
 * an operator as the first element, followed by zero, one, or two values as appropriate for the operator.
 *
 * In JSON, a sample criteria set for an UPDATE might look like this:
 * ```json
 * {
 *  "values": [
 *      {"col1": "val1", "col2": "val2"}
 *  ],
 *  "where": [
 *      {col1: ["=", "val1"], col2: ["BETWEEN", 3, 4]},
 *      {col2: ["IN", ["val2", "val3"]]},
 *      {col1: ["IS NULL"]}
 *  ]
 *}
 * ```
 *
 * If the above criteria were used in a StatementSet defined with this SQL:
 *
 * `UPDATE myTable SET <<values>> WHERE <<condition>>`
 *
 * the resulting query would be:
 * ```sql
 * UPDATE myTable SET col1 = "val1", col2 = "val2" WHERE (col1 = "val1" AND col2 BETWEEN 3 AND 4) OR (col2 IN ("val2", "val3")) OR (col1 IS NULL)
 * ```
 */
class StatementSet implements \Countable, \Iterator, \ArrayAccess {
    private array $_statements = [];
    private int $_position = 0;
    private array $_keys = [];

    /** @var ResultSet|array|bool|null The results returned from the last execution */
    public ResultSet|array|bool|null $results;

    /** @param Connection   $conn       The Connection instance to use for this instance
     *  @param string       $_baseSql   The SQL template by which to generate the prepared statements
     *  @param int|null     $queryType  The type of query to be run ({@see Query::QUERY_SELECT QUERY_SELECT, etc.})
     *  @param array|Diff   $criteria   Initial criteria to be applied; this can be used to avoid having to call setCriteria() later
     *  @param string|null  $name       The name of this StatementSet; can be used to distinguish between multiple StatementSets in a single Transaction
     *  @throws VeloxException          If criteria are incorrectly formatted (see exception text for description)
     */
    public function __construct(public Connection &$conn, private string $_baseSql = "", public ?int $queryType = null, public array|Diff $criteria = [], public ?string $name = null){
        $lc_query = strtolower($this->_baseSql);
        if (str_starts_with($lc_query,"call")){
            throw new VeloxException("Stored procedure calls are not supported by StatementSet.",46);
        }
        if (!$this->queryType){
            //Attempt to determine type by first keyword if query type isn't specified

            if (str_starts_with($lc_query,"select")){
                $this->queryType = Query::QUERY_SELECT;
            }
            elseif (str_starts_with($lc_query,"insert")){
                $this->queryType = Query::QUERY_INSERT;
            }
            elseif (str_starts_with($lc_query,"update")){
                $this->queryType = Query::QUERY_UPDATE;
            }
            elseif (str_starts_with($lc_query,"delete")){
                $this->queryType = Query::QUERY_DELETE;
            }
            else {
                $this->queryType = Query::QUERY_SELECT;
            }
        }
        if ($this->criteria instanceof Diff || count($this->criteria) > 0){
            $this->addCriteria($this->criteria);
        }
    }

    // Countable implementation
    public function count() : int {
        return count($this->_keys);
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

    //ArrayAccess implementation
    public function offsetSet(mixed $offset, mixed $value) : void {
        if (is_null($offset)){
            $this->_statements[] = $value;
        }
        else {
            $this->_statements[$offset] = $value;
        }
    }
    public function offsetExists(mixed $offset) : bool {
        return isset($this->_statements[$offset]);
    }
    public function offsetUnset(mixed $offset) : void {
        unset($this->_statements[$offset]);
    }
    public function offsetGet(mixed $offset) : PreparedStatement|null {
        return $this->_statements[$offset] ?? null;
    }

    //Class-specific methods
    private function criterionHash(object|array $criterion) : string {
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
    /**
     * Adds criteria to the StatementSet. These criteria are the values and/or conditions that will be used to create and
     * execute the prepared statements based on the base SQL template and its <<placeholders>>.
     *
     * @param array|Diff $criteria  The criteria to be added
     * @return void
     * @throws VeloxException       If criteria are incorrectly formatted (see exception text for description)
     */
    public function addCriteria(array|Diff $criteria) : void {
        if ($criteria instanceof Diff){
            switch ($this->queryType){
                case Query::QUERY_SELECT:
                    $this->addCriteria($criteria->select);
                    break;
                case Query::QUERY_INSERT:
                    $this->addCriteria($criteria->insert);
                    break;
                case Query::QUERY_UPDATE:
                    $this->addCriteria($criteria->update);
                    break;
                case Query::QUERY_DELETE:
                    $this->addCriteria($criteria->delete);
                    break;
            }
        }
        else {
            $requiredKeys = [];
            switch ($this->queryType){
                case Query::QUERY_INSERT:
                case Query::QUERY_UPDATE:
                    $requiredKeys[] = "values";
                    if ($this->queryType == Query::QUERY_INSERT) break;
                case Query::QUERY_SELECT:
                case Query::QUERY_DELETE:
                    //case Query::QUERY_UPDATE: (fall-through)
                    $requiredKeys[] = "where";
                    break;
            }
            if (isAssoc($criteria)){
                throw new VeloxException("Criteria format is invalid",63);
            }
            $criteriaCount = count($criteria);
            for ($i=0; $i<$criteriaCount; $i++){
                $criterion = (array)$criteria[$i];
                if (array_diff_key(array_flip($requiredKeys),$criterion) || array_diff_key($criterion,array_flip($requiredKeys))){
                    throw new VeloxException("Element at index ".$i." does not contain the correct keys.",47);
                }
                $hashedKeys = $this->criterionHash($criterion);
                if (!isset($this->criteria[$hashedKeys])){
                    $this->criteria[$hashedKeys] = ["where"=>$criterion['where'] ?? [],"values"=>$criterion['values'] ?? [],"data"=>[]];
                }
                $this->criteria[$hashedKeys]['data'][] = ["where"=>$criterion['where'] ?? [],"values"=>$criterion['values'] ?? []];
            }
        }
    }

    /** Generates the PreparedStatements to be run based on the assigned criteria. This needs to be run before the StatementSet is executed. */
    public function setStatements() : void {
        $statements = [];
        $criteria = $this->criteria;

        if (count($criteria) == 0){
            $criteria[0]['where'] = [];
            $criteria[0]['values'] = [];
            $criteria[0]['data'] = [];
        }
        foreach($criteria as $variation){
            $whereStr = "";
            $valuesStr = "";
            $columnsStr = "";
            switch ($this->queryType){
                case Query::QUERY_SELECT:
                case Query::QUERY_DELETE:
                case Query::QUERY_UPDATE:
                case Query::QUERY_PROC:
                    //format where clause
                    $orArray = [];
                    foreach ($variation['where'] as $andSet){
                        $andArray = [];
                        foreach ($andSet as $column => $details){
                            switch ($details[0]){
                                case "IS NULL":
                                case "IS NOT NULL":
                                    $andArray[] = $column." ".$details[0];
                                    break;
                                case "=":
                                case "<":
                                case ">":
                                case "<=":
                                case ">=":
                                case "<>":
                                case "LIKE":
                                case "NOT LIKE":
                                case "RLIKE":
                                case "NOT RLIKE":
                                    $andArray[] = $column." ".$details[0]." :w_".$column;
                                    break;
                                case "EKIL":
                                case "EKILR":
                                case "NOT EKIL":
                                case "NOT EKILR":
                                    $operator = explode(" ", strrev($details[0]))[0];
                                    if (str_starts_with($details[0],"NOT")){
                                        $operator = "NOT ".$operator;
                                    }
                                    $andArray[] = ":w_".$column." ".$operator." ".$column;
                                    break;
                                case "BETWEEN":
                                case "NOT BETWEEN":
                                    $andArray[] = $column." ".$details[0]." :w_".$column." AND :wb_".$column;
                                    break;
                                default:
                                    throw new VeloxException("Unsupported operator",36);
                            }
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
                    if ($this->queryType != Query::QUERY_UPDATE && $this->queryType != Query::QUERY_PROC){
                        break;
                    }

                case Query::QUERY_INSERT:  //and fall-through for Query::QUERY_UPDATE and Query::QUERY_PROC
                    //format values
                    $valuesArray = $variation['values'];
                    $valuesStrArray = [];
                    $columnsStrArray = [];
                    foreach (array_keys($valuesArray) as $column){
                        switch ($this->queryType){
                            case Query::QUERY_INSERT:
                                $columnsStrArray[] = $column;
                                $valuesStrArray[] = ":v_".$column;
                                break;
                            case Query::QUERY_UPDATE:
                                $valuesStrArray[] = $column." = :v_".$column;
                                break;
                        }
                    }
                    $valuesStr = implode(",", $valuesStrArray);
                    $columnsStr = implode(",", $columnsStrArray);
                    break;
            }

            if ($this->queryType == Query::QUERY_INSERT){
                $valuesStr = "(".$columnsStr.") VALUES (".$valuesStr.")";
                $columnsStr = "";
            }

            $substitutedSQL = str_replace(["<<condition>>","<<columns>>","<<values>>"],[$whereStr,$columnsStr,$valuesStr],$this->_baseSql);

            $stmt = new PreparedStatement($this->conn, $substitutedSQL, $this->queryType, Query::RESULT_DISTINCT);

            foreach ($variation['data'] as $row){
                $parameterSet = [];
                foreach ($row['where'] as $or){
                    foreach ($or as $column => $data){
                        try {
                            $parameterSet['w_'.$column] = $data[1];
                        }
                        catch (\Exception $ex){
                            throw new VeloxException("Operand missing in 'where' array",23);
                        }
                        if ($data[0] == "BETWEEN" || $data[0] == "NOT BETWEEN") {
                            try {
                                $parameterSet['wb_'.$column] = $data[2];
                            }
                            catch (\Exception $ex){
                                throw new VeloxException($data[0].' operator used without second operand',24);
                            }
                        }
                        elseif ($this->queryType == Query::QUERY_PROC){
                            $parameterSet['op_'.$column] = $data[0];
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
    /** Executes the PreparedStatements generated by ({@see StatementSet::setStatements()}). If the connection type supports
     * it, these statements will be executed as a single transaction.
     * @return bool True if the transaction was successful.
     * @throws VeloxException If no criteria have yet been set for this StatementSet.
     */
    public function execute() : bool {
        if (count($this->_statements) == 0){
            //if no statements are set, try setting them and recheck
            $this->setStatements();
            if (count($this->_statements) == 0){
                throw new VeloxException('Criteria must be set before StatementSet can be executed.',25);
            }
        }
        if (!$this->conn->inTransaction()){
            $this->conn->beginTransaction();
            $newTransaction = true;
        }
        else {
            $newTransaction = false;
        }
        $this->results = null;
        foreach ($this->_statements as $stmt){
            $stmt->execute();
            $results = $stmt->getResults();
            if (!$this->results){
                $this->results = $stmt->getResults();
            }
            else {
                if ($this->results instanceof ResultSet){
                    $this->results->merge($stmt->results);
                }
                elseif (is_array($this->results)){
                    $this->results[] = $stmt->getResults();
                }
            }
        }
        if ($newTransaction){
            $this->conn->commit();
        }
        return true;
    }
    public function __invoke() : bool {
        return $this->execute();
    }

    /** Clears all assigned criteria and PreparedStatements so this instance can be reused. */
    public function clear() : void {
        $this->rewind();
        $this->_statements = [];
    }

    /**
     * @return array An array of index values affected by the last execution of this StatementSet
     */
    public function getLastAffected() : array {
        $affected = [];
        foreach ($this->_statements as $stmt){
            $affected = array_merge($affected,$stmt->getLastAffected());
        }
        return $affected;
    }
    /** @return ResultSet|array|null The results of the last execution of this StatementSet */
    public function getResults() : ResultSet|array|null {
        return $this->results;
    }
    /**
     * @return array An array containing the execution contexts for each PreparedStatement generated by the last run of
     * this StatementSet. This may be useful for debugging.
     */
    public function dumpQueries() : array {
        $queries = [];
        foreach ($this->_statements as $stmt){
            $queries[] = $stmt->dumpQuery();
        }
        return $queries;
    }
}
