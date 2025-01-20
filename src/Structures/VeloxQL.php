<?php
declare(strict_types=1);

namespace KitsuneTech\Velox\Structures;

/**
 * An object-oriented representation of SQL CRUD operations (SELECT, INSERT, UPDATE, DELETE) meant to be applied to a Model
 * or a Velox API endpoint.
 *
 * Any or all of the operations can be applied at the same time, either by supplying a JSON string to the constructor,
 * or by manipulating the appropriate properties directly. If supplying JSON to the constructor, it should look similar
 * to the following, depending on the operations required:
 *
 *  ```json
 *   {
 *    select:
 *      [{where: [{col1: ["=","someValue"]}]}],
 *    insert:
 *      [{values: [{col2: 'data', col3: 'data'},{col2: 'moredata', col3: 'moredata'}]}],
 *    delete:
 *      [{where: [{col1: ["=",1]},{col2: ["=",'deleteThis'], col3: ["=",'deleteThis']}]}],
 *    update:
 *      [{values: [{col2: 'changeThis'}], where: [{col2: ["=",'fromThis']}]},{values: {col2: 'thisToo'}, where: [{col1: ["=",2]}]}]
 *  }
 *  ```
 *
 * The corresponding properties of the VeloxQL object will be set accordingly if these properties are set directly,
 * their structure should be PHP-equivalent to the property in question.
 *
 * @version 1.0.0
 * @since 1.0.0-alpha
 * @license https://www.mozilla.org/en-US/MPL/2.0/ Mozilla Public License 2.0
 */
class VeloxQL {
    public array $select;
    public array $update;
    public array $insert;
    public array $delete;

    /**
     * @param string $json A string representation of a JSON array having elements equivalent to the structure of the
     * VeloxQL object
     *
     * @version 1.0.0
     * @since 1.0.0-alpha
     */
    public function __construct(string $json = ""){
        $vqlObj = $json != "" ? json_decode($json) : (object)[];
        $this->select = $vqlObj->select ?? [];
        $this->update = $vqlObj->update ?? [];
        $this->insert = $vqlObj->insert ?? [];
        $this->delete = $vqlObj->delete ?? [];
    }
}