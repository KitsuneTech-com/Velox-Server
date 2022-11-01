# Velox server-side libraries

The server-side component of Velox consists of a PHP library built as a Composer project. This project can be imported as a Composer
dependency by including the following in the composer.json file in your project and then running the ```composer install``` command:

```
{
  "repositories": [
    {
      "url": "https://github.com/KitsuneTech-com/Velox.git",
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

The class structure is divided into a set of sub-namespaces underneath the KitsuneTech\Velox base, as reflected in the structure in
this directory. (There are two exceptions: the API and Support directories, which are not part of the class structure. More on the API later.)
Each of these sub-namespaces handles a different facet of the server-side component.

### Database

The Database sub-namespace controls database communication. The Connection object serves as the interface for this communication, using
whichever PHP extension is needed to connect to the given database. The following are examples of how a Connection object can be instantiated:
```
$pdoMySQLConnection = new Connection($hostname,$database_name,$user_id,$password,$port,DB_MYSQL,CONN_PDO);
$sqlsrvConnection = new Connection($hostname,$database_name,$user_id,$password,$port,DB_MSSQL,CONN_NATIVE);
$odbcDSNConnection = new Connection($dsn_name,null,null,null,null,null,CONN_ODBC);
$SQLServerODBCByConnectionString = new Connection(null,null,null,null,null,null,CONN_ODBC,["Driver"=>"{ODBC Driver 18 for SQL Server}","server"=>$hostname,"database"=>$database_name,"Uid"=>$user_id,"Pwd"=>$password]);
```
The first two examples are fairly self-explanatory. If the port is passed as null, the default port for the given database type is assumed. The last two arguments shown in these are constants representing the database engine and connection type, respectively. The database type can currently be one of the following:
* DB_MYSQL (for MySQL / MariaDB)
* DB_MSSQL (for Microsoft SQL Server)
* DB_ODBC (for ODBC data sources)

The connection type can be one of these:
* CONN_PDO (this uses a PDO library compatible with the given database engine)
* CONN_NATIVE (this uses a compatible non-PDO library [DB_MYSQL uses mysqli, DB_MSSQL uses sqlsrv])
* CONN_ODBC (this uses the ODBC library)
(note: if CONN_ODBC is used, the DB_ constants are ignored, so they can be left off)

The second two examples above demonstrate ODBC connections. The first of these connects to a named DSN; the second to a DSN-less resource whose connection string attributes are given in the array. If the enormous number of nulls in these makes you cringe, you can instead call the constructor with named arguments:
```
$odbcDSNConnection = new Connection(host: $dsn_name, connectionType: DB_ODBC);
$SQLServerODBCByConnectionString = new Connection(connectionType: CONN_ODBC, options: ["Driver"=>"{ODBC Driver 18 for SQL Server}","server"=>$hostname,"database"=>$database_name,"Uid"=>$user_id,"Pwd"=>$password]);
```
That's easier, right? The full list of named parameters are, in order: host, dbName, uid, pwd, port, serverType, connectionType, and options. Any unused parameters can be omitted.

All queries and procedures are handled through these Connection instances, and the specific functions and/or methods necessary for these are abstracted away, using the following classes contained in the Database\Procedures sub-namespace:

#### Query

Query is the most fundamental class in Procedures. This represents a single SQL statement to be run on the Connection as supplied to its
constructor. Once defined, it is run using its execute() method, and the results, if any, are retrieved with the getResults() method.

#### PreparedStatement

PreparedStatement is a subclass of Query that extends it with methods that allow named and positional placeholders to be defined and used
following the syntax appropriate for the database engine. A single PreparedStatement object can be used to batch queries by iterative calls
to its addParameterSet() method, each call supplied with an associative array having the placeholders and values to be substituted. The batch
of queries can then be run with a single execute() method call, and the combined results are available with a single call to the getResults()
method.

#### StatementSet

StatementSet is the most versatile of these classes. It addresses several shortcomings of the SQL-standard prepared statement by creating its own set
of PreparedStatements depending on the values and criteria given to it. Among other things, this behavior allows for operators to be assigned dynamically
and for column names and values to only be specified as needed.
Because of this unique, non-standard behavior, SQL used to define a StatementSet follows an augmented syntax, with placeholders similar to those used
by Angular. Three basic placeholders are allowed (\<\<values\>\>, \<\<columns\>\>, and \<\<condition\>\>), and these are added to a base SQL statement where the appropriate clauses would be. Examples:
  ```
  SELECT <<columns>> FROM myTable WHERE <<condition>>
  INSERT INTO myTable (<<columns>>) VALUES (<<values>>)
  UPDATE myTable SET <<values>> WHERE <<condition>>
  DELETE FROM myTable WHERE <<condition>>
  ```

#### Transaction
  
Transaction is a representation of a SQL transaction, in which multiple statements are run and only committed when complete. In Velox, it has the
unique capability of performing operations on multiple databases simultaneously using procedures run on several Connections. A Transaction can be
set up with consecutive calls to its addQuery method, each of which appends the given procedure to its execution plan. addFunction() can be used to
insert interstitial code to be run between procedures; code defined in this way has access to both the previous and subsequent procedures, which allows
this code to store and manipulate prior results, and to manipulate the following procedure as needed. The execution plan can then be run all at once,
or one step at a time.

#### Constructor arguments

The first argument for each of these is a reference to the Connection object that is to run them. This is the sole (and optional) argument for a Transaction instance; otherwise the next argument is the SQL query string itself, followed by a constant describing what type of query it is (QUERY_SELECT, QUERY_INSERT, QUERY_UPDATE, QUERY_DELETE, or QUERY_PROC [for a stored procedure]). If omitted, QUERY_SELECT is assumed.

### Structures

The Structures sub-namespace contains data structure classes used by the server-side component. Two of these - Diff and ResultSet - are used to
structure data passing to and from (respectively) the database through the Database\Procedures classes. The third - Model - is the most crucial
of these, as it mediates the data flow between the API interface and the database. A Model is a memory-resident representation of a dataset as
defined by the procedures assigned to it, and can be used to abstract away the entire database communication process by way of its various methods.
Once a Model is populated, filtering and sorting can be done without ever touching the database, and any changes made through the corresponding
methods are automatically forwarded to the database by way of the associated procedures; the Model is subsequently refreshed with current data.
  
### Transport

The Transport sub-namespace contains just one class: Export. This is used in combination with one or more instances of Structures\Model as a means
to send the contents of the given Models to the desired location (the browser, a file, a PHP object, or STDOUT) in the desired format (JSON, CSV,
XML, or HTML) - set through a sum of flags (see Support/Constants.php).
  
## API

The Velox API combines all of the above features into an interface that reduces database interaction to a set of POST calls made to common endpoints,
which forward requests to "query definition files" as needed based on the nature of the request. Because the structure of this API depends exclusively
on POST requests, it is inherently non-RESTful; in lieu of using HTTP verbs, the nature of the request is determined by how the body of the POST is structured.
All requests to a Velox API endpoint are done using either form-encoded variables or a raw JSON object, containing one or more of the main SQL query verbs
(select, update, insert, delete) as keys and having the values thereof in the form of JSON-encoded arrays of objects representing the conditions and/or
values to be used by the corresponding query. Each object in the array, depending on the type of query being used, will have either or both of the following properties: "where", which defines the filtering criteria (as in a SQL WHERE clause); and "values", which contains name-value pairs to be inserted or
updated by the query. The "where" property is itself an array of objects, each representing a set of criteria to be ORed together; each element object
represents specific column criteria, with the properties ANDed together. The values of these properties are arrays of one to three elements, the first of
which is a string containing a standard SQL operator, and the following element(s) corresponding to the right side of the operation. The "values" property
is simpler; the object represents a single row to be inserted or updated, with the keys and values being the column names and intended values, respectively.
  
If all this seems complicated, an illustration may help to clear it up. Let's say you have a table called "addresses", structured as so:

id | address1       | address2 | city              | state | zip   |
-- | -------------- | -------- | ----------------- | ----- | ----- |
 1 | 123 Elm Street | Apt. 123 | Spring            | TX    | 77373 |
 2 | 456 Oak Road   | null     | Summer Branch     | TX    | 75486 |
 3 | 789 Pine Ave.  | Ste. 456 | Falls City        | TX    | 78113 |
 4 | 1011 Cedar Dr. | Box 789  | Winters           | TX    | 79567 |

If you wanted to get any rows from Falls City, TX, using SQL, you might write the query as so:
  
``` SELECT * FROM addresses WHERE city = 'Falls City' AND state = 'TX'; ```
  
With the Velox API, if the query definition file includes:
  
``` $QUERIES['SELECT'] = new StatementSet($conn,"SELECT * FROM addresses WHERE <<criteria>>"); ```
  
then the JSON used to perform the same query would be:
  
```{"select": [{"where": [{"city": ["=","Falls City"], "state": ["=","TX"]}]}]}```
  
Alternatively, if this were to be built programmatically:
  
```
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
  
```
$QUERIES['UPDATE'] = new StatementSet($conn,"UPDATE addresses SET <<values>> WHERE <<condition>>");
```
  
The JSON in the request would look like:
  
``` {"update": [{"values": {"address2": "---"}, "where": [{"address2": ["IS NULL"]}]}]} ```
  
Or programmatically:
  
```
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
with minimal code on the back-end. StatementSet is optimized for specifically these kinds of queries; it only builds as many PreparedStatements as necessary
to run the request; where possible, similar criteria are grouped together and run as criteria on one PreparedStatement.

### EKIL / EKILR
In addition to the SQL standard comparison keywords, Velox provides EKIL and EKILR. These are inverted versions of LIKE and RLIKE, respectively (read it backwards), and perform the same comparisons, except that when the statement is assembled, the placeholder is put on the left side of the expression rather than on the right. (e.g. :value LIKE myColumn). This inversion allows the value to be compared against a pattern stored in the given column, where normally one would compare a value in the given column to a chosen pattern.

Thus:
```{"select": [{"where": [{"number_pattern": ["EKIL","2053553"]}]}]}```
would match a row where number_pattern has the value "205%", since "2053553" LIKE "205%".