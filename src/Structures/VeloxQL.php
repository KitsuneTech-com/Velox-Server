<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Structures;

class VeloxQL {
    public array $select;
    public array $update;
    public array $insert;
    public array $delete;
    public function __construct(string $json = ""){
        //Expected JSON format (where col1 is an autoincrement field not set by this library):
        //(each row is an object with properties representing each field name)
        // {
        //  select:
        //    [{where: [{col1: ["=","someValue"]}],
        //  insert:
        //    [{values: {col2: 'data', col3: 'data'},{col2: 'moredata', col3: 'moredata'}}],
        //  delete:
        //    [{where: [{col1: 1},{col2: 'deleteThis', col3: 'deleteThis'}]],
        //  update:
        //    [{values: {col2: 'changeThis'}, where: {col2: 'fromThis'}},{values: {col2: 'thisToo'}, where: {col1: 2}}]
        // }
        $diffObj = $json != "" ? json_decode($json) : (object)[];
        $this->select = $diffObj->select ?? [];
        $this->update = $diffObj->update ?? [];
        $this->insert = $diffObj->insert ?? [];
        $this->delete = $diffObj->delete ?? [];
    }
}

class_exists(VeloxQL::class);