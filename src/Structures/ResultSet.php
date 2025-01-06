<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Structures;
/**
 * The default data structure returned by Velox database procedures.
 *
 * This can be iterated as a sparse two-dimensional array using native functions, and the available column names can
 * be retrieved through the {@see columns()} method.
 */
class ResultSet implements \ArrayAccess, \Iterator, \Countable {
    private array $_columns = [];
    private int $_position = 0;
    private array $_lastAffected = [];
    private array $_keys = [];

    /**
     * @param array $_resultArray A two-dimensional array containing the results returned by a Velox procedure.
     */
    public function __construct(private array $_resultArray = []) {
        for ($i=0; $i<count($this->_resultArray); $i++) {
            $rowColumns = array_keys($this->_resultArray[$i]);
            for ($j=0; $j<count($rowColumns); $j++) {
                if (!isset($this->_columns[$rowColumns[$j]])) {
                    $this->_columns[$rowColumns[$j]] = count($this->_columns);
                }
            }
        }
        $this->_columns = array_flip($this->_columns);
        $this->_keys = array_keys($this->_resultArray) ?? [];
    }
    
    // Countable implementation
    /**
     * @ignore No usage documentation required for core interface implementation
     */
    public function count() : int {
        return count($this->_keys);
    }
    
    // Iterator implementation
    /**
     * @ignore No usage documentation required for core interface implementation
     */
    public function current() : array {
        return $this->_resultArray[$this->_keys[$this->_position]];
    }
    /**
     * @ignore No usage documentation required for core interface implementation
     */
    public function key() : int|string {
        return $this->_keys[$this->_position];
    }
    /**
     * @ignore No usage documentation required for core interface implementation
     */
    public function next() : void {
        $this->_position++;
    }
    /**
     * @ignore No usage documentation required for core interface implementation
     */
    public function rewind() : void {
        $this->_position = 0;
    }
    /**
     * @ignore No usage documentation required for core interface implementation
     */
    public function valid() : bool {
        return isset($this->_keys[$this->_position]);
    }

    // ArrayAccess implementation
    /**
     * @ignore No usage documentation required for core interface implementation
     */
    public function offsetSet(mixed $offset, mixed $row) : void {
        if (is_null($offset)){
            $this->_resultArray[] = $row;
            $this->_keys[] = array_key_last($this->_resultArray);
        }
        else {
            $this->_resultArray[$offset] = $row;
            if (!in_array($offset, $this->_keys)){
                $this->_keys[] = $offset;
            }
        }
    }
    /**
     * @ignore No usage documentation required for core interface implementation
     */
    public function offsetExists(mixed $offset) : bool {
        return isset($this->_resultArray[$offset]);
    }
    /**
     * @ignore No usage documentation required for core interface implementation
     */
    public function offsetUnset(mixed $offset) : void {
        unset($this->_resultArray[$offset]);
        unset($this->_keys[array_search($offset,$this->_keys)]);
        $this->_keys = array_values($this->_keys);
    }
    /**
     * @ignore No usage documentation required for core interface implementation
     */
    public function offsetGet(mixed $offset) : ?array {
        return $this->_resultArray[$offset] ?? null;
    }
    
    //Class-specific functionality
    /**
     * @return array The index values of the last row(s) affected by the procedure that generated this ResultSet.
     */
    public function lastAffected() : array {
        return $this->_lastAffected;
    }
    /**
     * @ignore This is to only be used by Velox procedures
     */
    public function appendAffected(array $affected) : void {
        $this->_lastAffected = array_merge($this->_lastAffected,$affected);
    }

    /**
     * Appends the data of another ResultSet to the existing dataset.
     *
     * This method is functionally equivalent to a SQL UNION or UNION ALL. The contents of the ResultSet
     * provided are appended to the end of this ResultSet. If $filterDuplicates is passed as true, any rows from
     * the provided ResultSet that already exist in this ResultSet are skipped (as in a UNION operation).
     *
     * Only the data of this ResultSet is affected by this method. The ResultSet provided as the first argument
     * remains unchanged.
     *
     * @param ResultSet $mergeResultSet
     * @param bool $filterDuplicates
     * @return void
     */
    public function merge(ResultSet $mergeResultSet, bool $filterDuplicates = false) : void {
        foreach ($mergeResultSet as $row){
            if (!$filterDuplicates || !in_array($row,$this->_resultArray)){
                $this->_resultArray[] = $row;
            }
        }
        $this->_keys = array_keys($this->_resultArray);
        $this->_columns = array_unique(array_merge($this->_columns, $mergeResultSet->columns()));
        $this->appendAffected($mergeResultSet->lastAffected());
    }

    /**
     * @return array The unwrapped contents of this ResultSet, as a two-dimensional array
     */
    public function getRawData() : array {
        return $this->_resultArray;
    }

    /**
     * @return array An array consisting of all column names in this ResultSet.
     */
    public function columns() : array {
        return $this->_columns;
    }
}
