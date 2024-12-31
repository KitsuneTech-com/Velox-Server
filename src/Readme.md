# Velox server-side libraries

The server-side component of Velox consists of a PHP library built as a Composer project. This project can be imported as a Composer
dependency by including the following in the composer.json file in your project and then running the ```composer update``` command:

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

## Classes

The class structure is divided into a set of sub-namespaces underneath the `KitsuneTech\Velox` base, as reflected in the structure in
this directory. (There is one exception: the Support directory, which contains independent helper functions and constants that are not part of the class structure.)
Each of these sub-namespaces handles a different facet of the server-side component.

### Database

The Database sub-namespace controls database communication. The `Connection` object serves as the interface for this communication, using
whichever PHP extension is needed to connect to the given database. The following are examples of how a Connection object can be instantiated:
```php
$pdoMySQLConnection = new Connection($hostname,$database_name,$user_id,$password,$port,Connection::DB_MYSQL,Connection::CONN_PDO);
$sqlsrvConnection = new Connection($hostname,$database_name,$user_id,$password,$port,Connection::DB_MSSQL,Connection::CONN_NATIVE);
$odbcDSNConnection = new Connection($dsn_name,null,null,null,null,null,Connection::CONN_ODBC);
$SQLServerODBCByConnectionString = new Connection(null,null,null,null,null,null,Connection::CONN_ODBC,["Driver"=>"{ODBC Driver 18 for SQL Server}","server"=>$hostname,"database"=>$database_name,"Uid"=>$user_id,"Pwd"=>$password]);
```
The first two examples are fairly self-explanatory. If the port is passed as null, the default port for the given database type is assumed. The last two arguments shown in these are constants representing the database engine and connection type, respectively. The database type can currently be one of the following:
* `Connection::DB_MYSQL` (for MySQL / MariaDB)
* `Connection::DB_MSSQL` (for Microsoft SQL Server)
* `Connection::DB_ODBC` (for ODBC data sources)

The connection type can be one of these:
* `Connection::CONN_PDO` (this uses a PDO library compatible with the given database engine)
* `Connection::CONN_NATIVE` (this uses a compatible non-PDO library [DB_MYSQL uses mysqli, DB_MSSQL uses sqlsrv])
* `Connection::CONN_ODBC` (this uses the ODBC library)
(note: if CONN_ODBC is used, the DB_ constants are ignored, so they can be left off)

The second two examples above demonstrate ODBC connections. The first of these connects to a named DSN; the second to a DSN-less resource whose connection string attributes are given in the array. If the enormous number of nulls in these makes you cringe, you can instead call the constructor with named arguments:
```php
$odbcDSNConnection = new Connection(host: $dsn_name, connectionType: Connection::DB_ODBC);
$SQLServerODBCByConnectionString = new Connection(connectionType: Connection::CONN_ODBC, options: ["Driver"=>"{ODBC Driver 18 for SQL Server}","server"=>$hostname,"database"=>$database_name,"Uid"=>$user_id,"Pwd"=>$password]);
```
That's easier, right? The full list of named parameters are, in order: host, dbName, uid, pwd, port, serverType, connectionType, and options. Any unused parameters can be omitted.

All queries and procedures are handled through these Connection instances, and the specific functions and/or methods necessary for these are abstracted away, using the following classes contained in the `Database\Procedures` sub-namespace:

#### Query

`Query` is the most fundamental class in Procedures. This represents a single SQL statement to be run on the Connection as supplied to its
constructor. Once defined, it is run using its `execute()` method, and the results, if any, are retrieved with the `getResults()` method.

#### PreparedStatement

`PreparedStatement` is a subclass of Query that extends it with methods that allow named and positional placeholders to be defined and used
following the syntax appropriate for the database engine. A single PreparedStatement object can be used to batch queries by iterative calls
to its `addParameterSet()` method, each call supplied with an associative array having the placeholders and values to be substituted. The batch
of queries can then be run with a single `execute()` method call, and the combined results are available with a single call to the `getResults()`
method.

#### StatementSet

`StatementSet` is the most versatile of these classes. It addresses several shortcomings of the SQL-standard prepared statement by creating its own set
of PreparedStatements depending on the values and criteria given to it. Among other things, this behavior allows for operators to be assigned dynamically
and for column names and values to only be specified as needed. Because of this unique, non-standard behavior, SQL used to define a StatementSet follows an augmented syntax, with placeholders similar to those used by Angular. Three basic placeholders are allowed (`<<values>>`, `<<columns>>`, and `<<condition>>`), and these are added to a base SQL statement where the appropriate clauses would be. Examples:
  ```sql
  SELECT <<columns>> FROM myTable WHERE <<condition>>
  INSERT INTO myTable (<<columns>>) VALUES (<<values>>)
  UPDATE myTable SET <<values>> WHERE <<condition>>
  DELETE FROM myTable WHERE <<condition>>
  ```

#### Transaction
 
`Transaction` is a representation of a SQL transaction, in which multiple statements are run and only committed when complete. In Velox, it has the
unique capability of performing operations on multiple databases simultaneously using procedures run on several Connections. A Transaction can be
set up with consecutive calls to its addQuery method, each of which appends the given procedure to its execution plan. `Transaction::addFunction()` can be used to insert interstitial code to be run between procedures; code defined in this way has access to both the previous and subsequent procedures, which allows this code to store and manipulate prior results, and to manipulate the following procedure as needed. The execution plan can then be run all at once, or one step at a time.

#### Constructor arguments

The first argument for each of these is a reference to the `Connection` object that is to run them. This is the sole (and optional) argument for a Transaction instance; otherwise the next argument is the SQL query string itself, followed by a constant describing what type of query it is (`Query::QUERY_SELECT`, `Query::QUERY_INSERT`, `Query::QUERY_UPDATE`, `Query::QUERY_DELETE`, or `Query::QUERY_PROC` [for a stored procedure]). If omitted, `Query::QUERY_SELECT` is assumed.

### Structures

The `Structures` sub-namespace contains data structure classes used by the server-side component. Two of these - `VeloxQL` and `ResultSet` - are used to
structure data passing to and from (respectively) the database through the Database\Procedures classes. The third - `Model` - is the most crucial
of these, as it mediates the data flow between the API interface and the database. A Model is a memory-resident representation of a dataset as
defined by the procedures assigned to it, and can be used to abstract away the entire database communication process by way of its various methods.
Once a Model is populated, filtering and sorting can be done without ever touching the database, and any changes made through the corresponding
methods are automatically forwarded to the database by way of the associated procedures; the Model is subsequently refreshed with current data.
 
### Transport

The `Transport` sub-namespace contains one primary class: `Export`. This is used in combination with one or more instances of Structures\Model as a means
to send the contents of the given Models to the desired location (the browser, a file, a PHP object, or STDOUT) in the desired format (JSON, CSV,
XML, or HTML) - set through a sum of flags (see Support/Constants.php).
 
