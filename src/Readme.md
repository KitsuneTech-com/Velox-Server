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
Note: Since Velox is currently in active development, no version number is yet assigned. If you fork this repository, you will need to change
the url to reflect your fork.

## Classes

The class structure is divided into a set of sub-namespaces underneath the `KitsuneTech\Velox` base, as reflected in the structure in
this directory. (There are two exceptions: the API and Support directories, which are not part of the class structure. More on the API later.)
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
XML, or HTML) - set through a sum of flags (see Support/Constants.php). Webhook functionality is also available in this sub-namespace; however, its functionality has not yet been thoroughly tested and is not yet adequately documented. If you choose to use this and encounter problems, feel free to open an issue.
  
## API

The Velox API combines all of the above features into an interface that reduces database interaction to a set of POST calls made to common endpoints,
which forward requests to "query definition files" as needed based on the nature of the request. Because the structure of this API depends exclusively
on POST requests, it is inherently non-RESTful; in lieu of using HTTP verbs, the nature of the request is determined by how the body of the POST is structured.

All requests to a Velox API endpoint are done using either form-encoded variables or a raw JSON object, containing one or more of the main SQL query verbs
(select, update, insert, delete) as keys and having the values thereof in the form of JSON-encoded arrays of objects representing the conditions and/or
values to be used by the corresponding query. Each object in the array, depending on the type of query being used, will have either or both of the following properties: "where", which defines the filtering criteria (as in a SQL WHERE clause); and "values", which contains name-value pairs to be inserted or updated by the query. The "where" property is itself an array of objects, each representing a set of criteria to be ORed together; each element object represents specific column criteria, with the properties ANDed together. The values of these properties are arrays of one to three elements, the first of which is a string containing a standard SQL operator, and the following element(s) corresponding to the right side of the operation. The "values" property is simpler; the object represents a single row to be inserted or updated, with the keys and values being the column names and intended values, respectively.
  
If all this seems complicated, an illustration may help to clear it up. Let's say you have a table called "addresses", structured as so:

id | address1       | address2 | city              | state | zip   |
-- | -------------- | -------- | ----------------- | ----- | ----- |
 1 | 123 Elm Street | Apt. 123 | Spring            | TX    | 77373 |
 2 | 456 Oak Road   | null     | Summer Branch     | TX    | 75486 |
 3 | 789 Pine Ave.  | Ste. 456 | Falls City        | TX    | 78113 |
 4 | 1011 Cedar Dr. | Box 789  | Winters           | TX    | 79567 |

If you wanted to get any rows from Falls City, TX, using SQL, you might write the query as so:
  
```sql
SELECT * FROM addresses WHERE city = 'Falls City' AND state = 'TX';
```
  
With the Velox API, if the query definition file includes:
  
```sql
$QUERIES['SELECT'] = new StatementSet($conn,"SELECT * FROM addresses WHERE <<criteria>>");
```
  
then the JSON used to perform the same query would be:
  
```json
{"select": [{"where": [{"city": ["=","Falls City"], "state": ["=","TX"]}]}]}
```
  
Alternatively, if this were to be built programmatically:
  
```js
//Define the request body
let request = {};
request.select = [];
  
//Define the row object
let row = {};
row.where = [];

//Define the where criteria
let criteria = {};
criteria.city = ["=","Falls City"];
criteria.state = ["=","TX"];
row.where.push(criteria);

//Add the row object to the request
request.push(row);
```

Similarly, if you wanted an UPDATE query to set any null address2 values to "---", using this in the query definition file:
  
```sql
$QUERIES['UPDATE'] = new StatementSet($conn,"UPDATE addresses SET <<values>> WHERE <<condition>>");
```
  
The JSON in the request would look like:
  
```json
{"update": [{"values": {"address2": "---"}, "where": [{"address2": ["IS NULL"]}]}]}
```
  
Or programmatically:
  
```js
//Define the request body
let request = {};
request.update = [];

//Define the row object
let row = {};
row.values = {};
row.where = [];

//Define the values
row.values.address2 = "---";

//Define the criteria
let criteria = {};
criteria.address2 = ["IS NULL"];
row.where.push(criteria);

//Add the row object to the request
request.push(row);
```
  
Being able to build API requests programmatically through JavaScript objects allows filters and updates of high complexity to be constructed client-side
with minimal code on the back-end. StatementSet is optimized for specifically these kinds of queries; it only builds as many PreparedStatements as necessary to run the request; where possible, similar criteria are grouped together and run as criteria on one PreparedStatement.

### Conditional operators

#### Binary comparisons

Most comparison operations in SQL are binary, meaning that a pair of values are compared to each other. For these binary comparisons, the corresponding Velox JSON uses the SQL operator as the first element of the comparison array, and the value to be compared as the second.

```json
{"select": [{"where": [{"state": ["=","TX"], "beginDate": [">","2000-01-01"]}]}]}
```

#### IS NULL / IS NOT NULL

`IS NULL` and `IS NOT NULL` are treated as unary, meaning that the column is not checked against an arbitrary value. For these, the comparison array will consist only of the desired operator.

```json
{"update": [{"values": {"address2": "---"}, "where": [{"address2": ["IS NULL"]}]}]}
```

#### BETWEEN / NOT BETWEEN

`BETWEEN` and `NOT BETWEEN` are trinary; these compare the column value to two arbitrary values. If one of these is used, the comparison array must consist of three elements: first the operator, then the two values to which the column is compared.

```json
{"select": [{"where": [{"beginDate": ["BETWEEN","2000-01-01","2001-01-01"]}]}]}
```

#### IN / NOT IN

`IN` and `NOT IN` are also supported. These operators compare the column against an arbitrary number of values, so for these, the comparison array must consist of two elements: the operator, and an array of values to which the column will be compared.

```json
{"select": [{"where": [{"myNumber": ["IN",[1,2,4,8]]}]}]}
```

#### EKIL / EKILR
In addition to the SQL standard comparison operations, Velox provides `EKIL` and `EKILR`. These are inverted versions of `LIKE` and `RLIKE`, respectively (read it backwards), and perform the same comparisons, except that when the statement is assembled, the placeholder is put on the left side of the expression rather than on the right. (e.g. `:value LIKE myColumn`). This inversion allows the value to be compared against a pattern stored in the given column, where normally one would compare a value in the given column to a chosen pattern.

Thus:
```json
{"select": [{"where": [{"number_pattern": ["EKIL","2053553"]}]}]}
```
would match a row where number_pattern has the value "205%", since "2053553" LIKE "205%".
