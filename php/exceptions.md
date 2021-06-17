## VeloxException codes/descriptions (subject to change during development)
Note: exceptions are not necessarily sequential per class. Numbers are added as needed during development.

### Database\Connection
| Code | Text                                                          | Explanation                                                                         |
| ---- | ------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| 10   | Invalid database type constant                                | An unsupported database type constant was passed. See constants.php.                |
| 11   | Database host not provided                                    | Connection information must be passed to the constructor.                           |
| 12   | Database name not provided                                    | Connection information must be passed to the constructor.                           |
| 13   | Database user not provided                                    | Connection information must be passed to the constructor.                           |
| 14   | Database password not provided                                | Connection information must be passed to the constructor.                           |
| 15   | sqlsrv extension must be installed for SQL Server connections | Velox uses the sqlsrv extension for Microsoft SQL Server connections.               |
| 16   | Unidentified database engine or incorrect parameters          | Velox was unable to connect to the database using the supplied information.         |
| 17   | SQL Server error(s):                                          | The Microsoft SQL Server instance returned the given error(s).                      |
| 18   | Transactional method called without active transaction        | The called method can't be invoked before Connection::beginTransaction().           |
| 19   | Query SQL is not set                                          | A Query object was passed to Connection::execute() without its sql property set.    |
| 20   | SQL statement failed to prepare                               | The underlying prepare function/method failed. See chained exception for details.   |
| 21   | Query failed to execute                                       | The database failed to execute the query. See chained exception for details.        |
| ***  | PDO/MySQL error:                                              | PDO returned an error when connecting to MySQL (error code is for PDOException)     |

### Database\Procedures\Query
| Code | Text                                                          | Explanation                                                                         |
| ---- | ------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| 22   | Query results not yet available                               | Query::getResults() was called before query execution finished.                     |

### Database\Procedures\StatementSet
| Code | Text                                                          | Explanation                                                                         |
| ---- | ------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| 23   | Operand missing in 'where' array                              | The specified condition did not include an operand to check for.                    |
| 24   | BETWEEN operator used without second operand                  | If BETWEEN is used as an operator, two operands must be specified in the array.     |
| 25   | Criteria must be set before StatementSet can be executed.     | StatementSet cannot be executed without specifying at least one set of conditions.  |
| 36   | Unsupported operator                                          | The operator specified is invalid or not supported                                  |
| 44   | IN operand must be in the form of an array                    | When using IN, the operand must be an array of values to check for.                 |
| 46   | Stored procedure calls are not supported by StatementSet.     | StatementSet placeholders are incompatible with stored procedure calls. A PreparedStatement must be used. |

### Database\Procedures\Transaction
| Code | Text                                                          | Explanation                                                                                     |
| ---- | ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| 26   | Transaction has no active connection                          | A query string was passed to Transaction::addQuery() without first setting a connection to use. |
| 27   | Query in transaction failed                                   | The transaction was rolled back after a failed query. See the chained exception for details.    |
| 39   | User-defined function failed                                  | A custom function added to the Transaction failed. See the chained exception for details.    |

### Database\Structures\ResultSet
| Code | Text                                                          | Explanation                                                                         |
| ---- | ------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| 28   | Specified key column does not exist                           | The column name specified for the key column doesn't exist in the results           |

### Database\Structures\Model
| Code | Text                                                          | Explanation                                                                         |
| ---- | ------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| 29   | The PreparedStatement returned multiple result sets. Make sure that $resultType is set to VELOX_RESULT_UNION or VELOX_RESULT_UNION_ALL. | Model uses only one result set at a time. |
| 37   | The associated procedure for xxxx has not been defined.       | In order to call the given Model method, the corresponding procedure must have been defined in the constructor call. |
| 38   | Column 'xxxx' does not exist in result set.                   | Attempted to filter on a column that doesn't exist in the result set.               |
| 40   | Select query required for DML queries on nested Models        | A nested Model was defined without a select query set on the parent Model. Submodels need this in order to match the parent's primary key. |
| 41   | Primary key column name must be specified for parent Model    | Without a primary key, submodel rows can't be linked to parent Model rows.          |
| 42   | Name and foreign key arguments cannot be empty strings        | The names and foreign keys of submodels must be specified.                          |
| 43   | Foreign key column 'xxxx' does not exist in submodel.         | The specified foreign key isn't a column in the submodel it was defined for. Make sure the key matches a column in the underlying query. |
| 45   | Submodel updates are not allowed when the parent Model update is a PreparedStatement | PreparedStatement placeholders don't supply the necessary column names. Only a StatementSet can do this. |


### Transport\Export
| Code | Text                                                          | Explanation                                                                              |
| ---- | ------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| 30   | Invalid flags set                                             | Flags must be a sum of TO_ and AS_ constants (see constants.php)                         |
| 31   | Filename is missing                                           | If the TO_FILE flag is used, the file name must be specified.                            |
| 32   | Only one to-browser Export can be called per request.         | A TO_BROWSER Export() call cannot send more than one response at a time.                 |
| 33   | Array contains elements other than instances of Model         | If an array is passed to Export, it must only contain Models.                            |
| 34   | XML export requires the xmlwriter extension                   | The XML generated with an AS_XML Export() call is built with the xmlwriter extension.    |
| 35   | A CSV file can have only one worksheet. You will need to export each Model separately. | Multiple worksheets are not supported by the CSV specification. 
