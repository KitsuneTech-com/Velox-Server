<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Structures;

class VeloxQL {
    public array $select;
    public array $update;
    public array $insert;
    public array $delete;

    /**
     * VeloxQL is an object-oriented representation of SQL CRUD operations (SELECT, INSERT, UPDATE, DELETE) meant to be
     * applied to a Model or a Velox API endpoint. Any or all of the operations can be applied at the same time, either
     * by supplying a JSON string to the constructor, or by manipulating the appropriate properties directly. If supplying
     * JSON to the constructor, it should look similar to the following, depending on the operations required:
     *
     * ```
     *  {
     *   select:
     *     [{where: [{col1: ["=","someValue"]}],
     *   insert:
     *     [{values: {col2: 'data', col3: 'data'},{col2: 'moredata', col3: 'moredata'}}],
     *   delete:
     *     [{where: [{col1: 1},{col2: 'deleteThis', col3: 'deleteThis'}]],
     *   update:
     *     [{values: {col2: 'changeThis'}, where: {col2: 'fromThis'}},{values: {col2: 'thisToo'}, where: {col1: 2}}]
     * }
     * ```
     *
     * The corresponding properties of the VeloxQL object will be set accordingly if these properties are set directly,
     * their structure should be PHP-equivalent to the property in question.
     *
     * @param string $json
     */
    public function __construct(string $json = ""){
        //Expected JSON format (where col1 is an autoincrement field not set by this library):
        //(each row is an object with properties representing each field name)

        $vqlObj = $json != "" ? json_decode($json) : (object)[];
        $this->select = $vqlObj->select ?? [];
        $this->update = $vqlObj->update ?? [];
        $this->insert = $vqlObj->insert ?? [];
        $this->delete = $vqlObj->delete ?? [];
    }
}

class_exists(VeloxQL::class);