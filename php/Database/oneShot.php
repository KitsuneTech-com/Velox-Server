<?php

namespace KitsuneTech\Velox\Database;
use KitsuneTech\Velox\VeloxException;
use KitsuneTech\Velox\Database\Procedures\{PreparedStatement, StatementSet, Transaction};
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;

function oneShot(PreparedStatement|StatementSet|Transaction $query, array $input) : array|ResultSet|bool|null {
    if ($query instanceof PreparedStatement){
        $query->addParameterSet($input);
        $query->execute();
        return $query->getResults();
    }
    elseif ($query instanceof StatementSet){
        $query->addCriteria($input);
        $query->execute();
        return $query->getResults();
    }
    elseif ($query instanceof Transaction){
        //This will need to be changed to $query->addInput() when the nested queries update is merged
        $query->addParameterSet($input);
        $query->begin();
        $query->executeAll();
        return $query->getQueryResults();
    }    
}
