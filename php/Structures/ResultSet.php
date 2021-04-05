<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Structures;

class ResultSet implements \ArrayAccess, \Iterator, \Countable {
    private array $_resultArray = [];
    private array $_keys = [];
    private array $_columns = [];
    private int $_position = 0;
    
    public function __construct(?array $resultArray) {
        if ($resultArray){
            $this->_resultArray = $resultArray;
            $this->_columns = array_keys($resultArray[0]);
        }
        else {
            $this->_resultArray = [];
        }
        $this->_keys = array_keys($this->_resultArray);
    }
    
    // Countable implementation
    public function count() : int {
        return count($this->_keys);
    }
    
    // Iterator implementation
    public function current() : array {
        return $this->_resultArray[$this->_keys[$this->_position]];
    }
    public function key() : int|string {
        return $this->_keys[$this->_position];
    }
    public function next() : void {
        $this->_position++;
    }
    public function rewind() : void {
        $this->_position = 0;
    }
    public function valid() : bool {
        return isset($this->_keys[$this->_position]);
    }
    
    // ArrayAccess implementation
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
    public function offsetExists(mixed $offset) : bool {
        return isset($this->_resultArray[$offset]);
    }
    public function offsetUnset(mixed $offset) : void {
        unset($this->_resultArray[$offset]);
        unset($this->_keys[array_search($offset,$this->_keys)]);
        $this->_keys = array_values($this->_keys);
    }
    public function offsetGet(mixed $offset) : ?array {
        return isset($this->_resultArray[$offset]) ? $this->_resultArray[$offset] : null;
    }
    
    //Class-specific functionality
    public function merge(ResultSet $mergeResultSet, bool $filterDuplicates = false) : void {
        foreach ($mergeResultSet as $row){
            if (!$filterDuplicates || !in_array($row,$this->_resultArray)){
                $this->_resultArray[] = $row;
            }
        }
        $this->keys = array_keys($this->_resultArray);
    }
    public function getRawData() : array {
        return $this->_resultArray;
    }
    public function columns() : array {
        return $this->_columns;
    }
}
