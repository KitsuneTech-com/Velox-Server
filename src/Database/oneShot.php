<?php

namespace KitsuneTech\Velox\Database;
use KitsuneTech\Velox\VeloxException;
use KitsuneTech\Velox\Database\Procedures\{Query, PreparedStatement, StatementSet, Transaction};
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;

/**
 * A function to run a single Velox procedure and automatically return its result.
 *
 * This function will immediately execute the given procedure -- and only this procedure -- returning the result as
 * specified in the procedure's $resultType argument. If the procedure is a Transaction, it is executed completely
 * and committed. Parameter sets/criteria for this procedure can be passed as a second argument, as they would be in
 * the appropriate add method to the procedure itself.
 *
 * @param Query|StatementSet|Transaction $query  The procedure to be executed
 * @param array|object|null $input               Parameter sets/criteria to be added before execution
 *
 * @return array|ResultSet|bool|null             The results returned from the executed procedure
 * @throws VeloxException                        If parameters/criteria are specified for a base Query (this is not supported)
 *
 * @version 1.0.0
 * @since 1.0.0-alpha
 */
function oneShot(Query|StatementSet|Transaction $query, array|object|null $input = null) : array|ResultSet|bool|null {
    $namespaceComponents = explode('\\', get_class($query));
    $procedureClass = end($namespaceComponents);

    $addMethod = match ($procedureClass) {
        'PreparedStatement' => 'addParameterSet',
        'StatementSet' => 'addCriteria',
        'Transaction' => 'addTransactionParameters',
        'Query' => null
    };
    if ($input){
        if ($addMethod){
            $query->{$addMethod}($input);
        }
        else {
            throw new VeloxException("Input is not supported for Query objects.",51);
        }
    }
    if ($procedureClass == "Transaction"){
        $query->begin();
        $query->executeAll();
        return $query->getQueryResults();
    }
    else {
        $query->execute();
        return $query->getResults();
    }
}
