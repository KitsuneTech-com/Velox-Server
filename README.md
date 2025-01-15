# Velox Server

Velox Server is the primary server-side component of the [Velox MVA](https://github.com/KitsuneTech-com/Velox-MVA)
framework. It provides a platform-agnostic class structure that eliminates the need to juggle syntax when accessing
multiple data sources, including MySQL/MariaDB, Microsoft SQL Server, and ODBC-compatible sources. The Model class
also allows for additional data caching, manipulation, and export of the retrieved datasets.

## Requirements
Velox Server has been built to be as portable and platform-agnostic as possible, though it has yet to be tested on
non-POSIX systems. (Users are welcome to try this at their own risk, and feedback in such cases is welcome.) The
minimum software requirements for Velox Server are as follows:

* PHP 8.0.2+, with Composer 2.0+
  * One or more of the following extensions, depending on the database engine to be used:
    * MySQL / MariaDB: either mysqli or pdo_mysql
    * Microsoft SQL Server: either sqlsrv or pdo_sqlsrv (note: either of these require the Microsoft ODBC Driver for SQL
      Server.)
    * ODBC: either odbc or pdo_odbc, along with the necessary drivers for the desired connection
  * The xmlwriter extension, if Velox Server is to be used standalone and XML output is needed
* Certain forms of output may require a web server that supports PHP server-side scripting (Apache 2.4+ is specifically
  supported, but NGINX, IIS, and others may work as well)

## Installation
Velox Server is built as a Composer package using PSR-4 autoloading. To install it, make sure you first have Composer
installed and initialized in your project if you hadn't already, then include the following in the composer.json file
and run the ```composer update``` command:

```json
{
  "repositories": [
    {
      "url": "https://github.com/KitsuneTech-com/Velox-Server.git",
      "type": "git"
    }
  ],
  "require": {
    "kitsunetech/velox": "dev-main"
  }
}
```

Alternatively, the source can be downloaded directly from this repository and implemented with a PSR-4 autoloader of
your choice. Velox Server can also be implemented without an autoloader, but this is not recommended since the class
files will have to be included/required individually based on dependencies. These methods may work but are not supported.

## Usage

Having been built according to the PSR-4 standard, Velox Server class structure is divided into a set of sub-namespaces
underneath the `KitsuneTech\Velox` base, as reflected in the structure of the src directory. (There is one exception:
the Support directory, which contains independent helper functions and constants that are not part of the class
structure.) Each of these sub-namespaces handles a different facet of the server-side component.

To use any of these classes, include your autoloader according to its documentation, then write use statements for
the fully qualified class name of each class or function you wish to implement. Remember to include the sub-namespace
as appropriate.

```php
use KitsuneTech\Velox\Database\Connection;
use KitsuneTech\Velox\Structures\{Model, VeloxQL};
use function KitsuneTech\Velox\Transport\Export;
```

### Database

The Database sub-namespace controls database communication. The `Connection` object serves as the interface for this
communication, using whichever PHP extension is needed to connect to the given database. The following are examples of
how a Connection object can be instantiated:
```php
$pdoMySQLConnection = new Connection($hostname,$database_name,$user_id,$password,$port,Connection::DB_MYSQL,Connection::CONN_PDO);
$sqlsrvConnection = new Connection($hostname,$database_name,$user_id,$password,$port,Connection::DB_MSSQL,Connection::CONN_NATIVE);
$odbcDSNConnection = new Connection($dsn_name,null,null,null,null,null,Connection::CONN_ODBC);
$SQLServerODBCByConnectionString = new Connection(null,null,null,null,null,null,Connection::CONN_ODBC,["Driver"=>"{ODBC Driver 18 for SQL Server}","server"=>$hostname,"database"=>$database_name,"Uid"=>$user_id,"Pwd"=>$password]);
```
The first two examples are fairly self-explanatory. If the port is passed as null, the default port for the given
database type is assumed. The last two arguments shown in these are constants representing the database engine and
connection type, respectively. The database type can currently be one of the following:
* `Connection::DB_MYSQL` (for MySQL / MariaDB)
* `Connection::DB_MSSQL` (for Microsoft SQL Server)
* `Connection::DB_ODBC` (for ODBC data sources)

The connection type can be one of these:
* `Connection::CONN_PDO` (this uses a PDO library compatible with the given database engine)
* `Connection::CONN_NATIVE` (this uses a compatible non-PDO library [DB_MYSQL uses mysqli, DB_MSSQL uses sqlsrv])
* `Connection::CONN_ODBC` (this uses the ODBC library)
  (note: if CONN_ODBC is used, the DB_ constants are ignored, so they can be left off)

The second two examples above demonstrate ODBC connections. The first of these connects to a named DSN; the second to a
DSN-less resource whose connection string attributes are given in the array. If the enormous number of nulls in these
makes you cringe, you can instead call the constructor with named arguments:
```php
$odbcDSNConnection = new Connection(host: $dsn_name, connectionType: Connection::CONN_ODBC);
$SQLServerODBCByConnectionString = new Connection(connectionType: Connection::CONN_ODBC, options: ["Driver"=>"{ODBC Driver 18 for SQL Server}","server"=>$hostname,"database"=>$database_name,"Uid"=>$user_id,"Pwd"=>$password]);
```
That's easier, right? The full list of named parameters are, in order: host, dbName, uid, pwd, port, serverType,
connectionType, and options. Any unused parameters can be omitted.

All queries and procedures are handled through these Connection instances, and the specific functions and/or methods
necessary for these are abstracted away, using the following classes contained in the `Database\Procedures` sub-namespace:

#### Query

`Query` is the most fundamental class in Procedures. This represents a single SQL statement to be run on the Connection
as supplied to its constructor. Once defined, it is run using its `execute()` method, and the results, if any, are
retrieved with the `getResults()` method.

To run a Query, first create a Connection (see above) and then pass it as the first argument to the constructor. (You
can reuse a Connection if you already have one open.) The second argument is the SQL query you intend to run on the
connection, the third specifies what type of query you are running, and the fourth tells Query how it should return the
results to you. (See the full documentation for a complete description of the available options.)

Example:
```php
$myConnection = new Connection(host: "myDatabaseServer.xyz", dbName: "myDatabase", serverType: Connection::DB_MYSQL, connectionType: Connection::CONN_PDO);
$myQuery = new Query($myConnection, "SELECT thisColumn FROM myTable WHERE thatColumn BETWEEN 0 AND 9", Query::QUERY_SELECT, Query::RESULT_ARRAY);
$myQuery->execute();
$resultsZeroToNine = $myQuery->getResults();
```

#### PreparedStatement

`PreparedStatement` is a subclass of Query that extends it with methods that allow named and positional placeholders to
be defined and used following the syntax appropriate for the database engine. A single PreparedStatement object can be
used to batch queries by iterative calls to its `addParameterSet()` method, each call supplied with an associative array
having the placeholders and values to be substituted. The batch of queries can then be run with a single `execute()`
method call, and the combined results are available with a single call to the `getResults()` method.

Example:
```php
$myStatement = new PreparedStatement($myConnection, "SELECT thisColumn FROM myTable WHERE thatColumn = :chosenValue");
for ($anInteger = 0; $anInteger < 10; $anInteger++){
    $myStatement->addParameterSet(["chosenValue"=>$anInteger]);
}
$myStatement->execute();
$resultsZeroToNine = $myStatement->getResults();
```

#### StatementSet

`StatementSet` is the most versatile of these classes. It addresses several shortcomings of the SQL-standard prepared
statement by creating its own set of PreparedStatements depending on the values and criteria given to it. Among other
things, this behavior allows for operators to be assigned dynamically and for column names and values to only be
specified as needed. Because of this unique, non-standard behavior, SQL used to define a StatementSet follows an
augmented syntax, with placeholders similar to those used by Angular. Three basic placeholders are allowed
(`<<values>>`, `<<columns>>`, and `<<condition>>`), and these are added to a base SQL statement where the appropriate
clauses would be. The general pattern for each type of query is as follows:
  ```sql
  SELECT <<columns>> FROM myTable WHERE <<condition>>
  INSERT INTO myTable (<<columns>>) VALUES (<<values>>)
  UPDATE myTable SET <<values>> WHERE <<condition>>
  DELETE FROM myTable WHERE <<condition>>
  ```

Example:
```php
$myStatementSet = new StatementSet($myConnection, "SELECT <<columns>> FROM myTable WHERE <<condition>>");
$myStatementSet->addCriteria(["columns"=>["thisColumn"], "where"=>[["thatColumn"=>["BETWEEN",0,9]]]]);
$myStatementSet->setStatements();
$myStatementSet->execute();
$resultsZeroToNine = $myStatementSet->getResults();
```
#### Transaction

`Transaction` is a representation of a SQL transaction, in which multiple statements are run and only committed when
complete. In Velox, it has the unique capability of performing operations on multiple databases simultaneously using
procedures run on several Connections. A Transaction can be set up with consecutive calls to its addQuery method, each
of which appends the given procedure to its execution plan. `Transaction::addFunction()` can be used to insert
interstitial code to be run between procedures; code defined in this way has access to both the previous and subsequent
procedures, which allows this code to store and manipulate prior results, and to manipulate the following procedure as
needed. The execution plan can then be run all at once, or one step at a time.

As an example, this is what a simple ETL Transaction would look like, from a MySQL source to a SQL Server destination.
```php
//Create connections to the source and destination databases
$mysqlConnection = new Connection(host: "mysqlServer.xyz", dbName: "sourceDatabase", serverType: Connection::DB_MYSQL, connectionType: Connection::CONN_PDO);
$sqlsrvConnection = new Connection(host: "sqlsrvServer.xyz", dbName: "destinationDatabase", serverType: Connection::DB_MSSQL, connectionType: Connection::CONN_NATIVE);

//Map the source column names to the destination column names
$columnMap = ["sourceAbc" => "destinationAbc", "sourceXyz" => "destinationXyz"];

//Create StatementSets for the source SELECT and the destination INSERT
$sourceStatementSet = new StatementSet($mysqlConnection,"SELECT <<columns>> FROM sourceTable");
$destinationStatementSet = new StatementSet($sqlsrvConnection,"INSERT INTO destinationTable (<<columns>>) VALUES <<values>>",QUERY::QUERY_INSERT);

//Add the criteria for the source SELECT (the source column names above)
$sourceStatementSet->addCriteria(["columns"=>array_keys($columnMap)]);

//Define a transformation function to perform on the selected data
$transform = function($source,$destination) use ($columnMap) {
    //The Transaction will supply the arguments on execution. Each will be an array of two elements: the previous or
    //next defined procedure, respectively; and the arguments by which it was invoked, passed by reference.
    $sourceData = $source[0]->getResults();
    
    //Transform the data (here, we're just remapping columns) and feed it to the destination StatementSet
    foreach ($sourceData as $sourceRow){
        $destinationRow = [];
        foreach ($sourceRow as $sourceColumn => $value){
            $destinationRow[$columnMap[$sourceColumn]] = $value;
        }
        $destination[0]->addCriteria(["values"=>$destinationRow]);
    }
};

//Assemble the Transaction (in order of execution)
$myTransaction = new Transaction();
$myTransaction->addQuery($sourceStatementSet);
$myTransaction->addFunction($transform);
$myTransaction->addQuery($destinationStatementSet);

//Execute and finally commit it.
$myTransaction->executeAll();
$myTransaction->commit();
```
There are ways to simplify this process even further, using the classes below.

### Structures

The `Structures` sub-namespace contains data structure classes used by the server-side component. Two of these -
`VeloxQL` and `ResultSet` - are used to structure data passing to and from (respectively) the database through the
Database\Procedures classes. The third - `Model` - is the most crucial of these, as it mediates the data flow between
the API interface and the database. A Model is a memory-resident representation of a dataset as defined by the
procedures assigned to it, and can be used to abstract away the entire database communication process by way of its
various methods. Once a Model is populated, filtering and sorting can be done without ever touching the database, and
any changes made through the corresponding methods are automatically forwarded to the database by way of the associated
procedures; the Model is subsequently refreshed with current data.

#### VeloxQL
The VeloxQL class is a purely structural entity (no methods) which implements an object-oriented equivalent of the
VALUES and/or WHERE clauses of a query -- in short, the conditional part. By using VeloxQL objects with a StatementSet
or Model, instances of the latter can be defined one time for a given dataset and easily reused with multiple sets of
values or criteria.

Each VeloxQL instance has four properties, one for each query type -- select, insert, update, and delete. Each
represents an array of operations of that type to be performed, with the clauses appropriate to that query type.
Thus, each element of a given property must be an array having the following keys, respectively:

* select: "where"
* update: "values", "where"
* insert: "values"
* delete: "where"

The value for each key must itself be an array, the expected contents of which depend on the key, as described below:

##### "where"
A "where" array is an array of arrays, with each array being a set of conditions to be applied to the corresponding
SELECT query, ORed together; each set of conditions is an associative array wherein each key is a column name for that
dataset, and the corresponding value is an array representation of a SQL-equivalent comparison expression for that
column; this array will contain between one and three elements, depending on the expression; the general format of this
is as follows:

* Unary operations: `["IS NULL"]`, `["IS NOT NULL"]`
* Binary comparisons: `["=", "someValue"]` (all SQL-standard binary comparisons are supported)
* Trinary comparisons: `["BETWEEN","firstValue","secondValue"]` (the values here should of course be of a type that can
be compared in this manner)
* Set comparisons: `["IN",["value1","value2","value3"]]`

Put together, a "where" array might look something like this:
```php
[
    ["column1" => ["=",2], "column2" => ["<", 3]],
    ["column1" => ["<>", 5], "column2" => ["IS NULL"]],
    ["column1" => ["BETWEEN", 1, 10]]
]
```
which corresponds to the following SQL WHERE clause:
```sql
WHERE (column1 = 2 AND column2 < 3) OR (column1 <> 5 AND column2 IS NULL) OR (column1 BETWEEN 1 AND 10)
```

##### "values"
A "values" array is also an array of arrays, but a much simpler one. Each array represents one set of columns/values
(as an associative array) to be either inserted or updated, depending on the query type. Only one such array is used
for each UPDATE, but several arrays can be used to perform a batch INSERT. For example, this VeloxQL object is set up
to insert two rows into a dataset, each having different values for the two given columns:
```php
$vql = new VeloxQL;
$vql->insert = [
    [
    "values"=>[
        ["column1" => "firstValueColumn1", "column2" => "firstValueColumn2"],
        ["column1" => "secondValueColumn1", "column2" => "secondValueColumn2"]
    ]
];
```

### Transport

The `Transport` sub-namespace defines classes and functions used to package and transport data between Velox and other
non-database media. This currently consists of one primary function: `Export`.

#### Export
Export's purpose is more or less self-explanatory: it exports the dataset(s) of one or several Models in one of several
formats (JSON, CSV, XML, and HTML are currently supported) to the specified destination (the browser, a file, a PHP
string, or STDOUT). The usage is also quite simple -- it's a single function call, with the following parameters, in order:

1. The Model (or array of Models) to be exported,
2. A pair of constants added together, indicating the format and destination for the exported data,
3. A path and/or filename to which the data will be sent (this only applies to file and browser exports),
4. The number of rows from the Model(s) to be skipped from the beginning of the dataset, if desired (default: 0), and
5. whether to leave off the column headers (these are included by default).

The constants expected in the second parameter are predefined as follows:

##### Format
| Constant | Description                                                                  |
|----------|------------------------------------------------------------------------------|
| AS_JSON  | A JSON array of objects, each of which represent one row in key/value format |
| AS_CSV   | A CSV spreadsheet containing the exported data in tabular form               |
| AS_XML   | An XML representation of the exported Model(s)                               |
| AS_HTML  | An HTML page containing a `<table>` populated with the exported data           |

##### Destination
| Constant   | Description                                                                                           |
|------------|-------------------------------------------------------------------------------------------------------|
| TO_BROWSER | HTTP headers are sent before the data is sent to the web server in the given format                   |
| TO_FILE    | A local file is created from the exported data                                                        |
| TO_STRING  | Export() returns a string representation of the data in the given format, without outputting anything |
| TO_STDOUT  | The results are sent directly to the console (if executing a script from the command line)            |

Any combination of format and destination constants can be provided, added together. For example, TO_FILE+AS_CSV will
create a local CSV file, while TO_BROWSER+AS_HTML will render an HTML page to a web client.


