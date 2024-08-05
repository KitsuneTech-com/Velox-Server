<?php

namespace KitsuneTech\Velox\Database;
use KitsuneTech\Velox\VeloxException;
use KitsuneTech\Velox\Database\Procedures\{Query, PreparedStatement, StatementSet, Transaction};
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;

/**
 * oneShot() is a means of running a single Velox procedure without binding it to a Model. Calling this function will
 * immediately execute this query -- and only this query -- returning the result as specified in the procedure's $resultType
 * argument. If the procedure is a Transaction, it is executed completely and committed. Parameter sets/criteria for this procedure
 * can be passed as a second argument, as they would be in the appropriate add method to the procedure itself.
 *
 * @param Query|StatementSet|Transaction $query  The procedure to be executed
 * @param array|object|null $input               Parameter sets/criteria to be added before execution
 * @return array|ResultSet|bool|null             The results returned from the executed procedure
 * @throws VeloxException                        If parameters/criteria are specified for a base Query (this is not supported)
 */
function oneShot(Query|StatementSet|Transaction $query, array|object|null $input = null) : array|ResultSet|bool|null {
    $procedureClass = str_replace(__NAMESPACE__.'\\','',get_class($query));
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
