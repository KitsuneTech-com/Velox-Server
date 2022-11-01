<?php

namespace KitsuneTech\Velox\Database;
use KitsuneTech\Velox\VeloxException;
use KitsuneTech\Velox\Database\Procedures\{Query, PreparedStatement, StatementSet, Transaction};
use KitsuneTech\Velox\Structures\ResultSet as ResultSet;

function oneShot(Query|StatementSet|Transaction $query, array|object $input = null) : array|ResultSet|bool|null {
    if ($query instanceof PreparedStatement){
        if ($input){
            $query->addParameterSet($input);
        }
        $query->execute();
        return $query->getResults();
    }
    elseif ($query instanceof Query){
        if ($input){
            throw new VeloxException("Input is not supported for Query objects.",51);
        }
        $query->execute();
        return $query->getResults();
    }
    elseif ($query instanceof StatementSet){
        if ($input){
            $query->addCriteria($input);
        }
        $query->execute();
        return $query->getResults();
    }
    elseif ($query instanceof Transaction){
        if ($input){
            //This will need to be changed to $query->addInput() when the nested queries update is merged
            $query->addParameterSet($input);
        }
        $query->begin();
        $query->executeAll();
        return $query->getQueryResults();
    }    
}
