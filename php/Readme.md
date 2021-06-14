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
the url and require properties to reflect your fork.

## Structure

The class structure is divided into a set of sub-namespaces underneath the KitsuneTech\Velox base, as reflected in the structure in
this directory. (The one exception is the API directory, which is not part of the class structure. More on this later.) Each of these
sub-namespaces handles a different facet of the server-side component.

### Database

The Database sub-namespace controls database communication. The Connection object serves as the interface for this communication, using
whichever PHP library is needed to connect to the given database. (At present, PDO is used for MySQL and sqlsrv
[https://docs.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server?view=sql-server-ver15] is used for Microsoft SQL Server.
Support for other database engines may be included in the future.) All queries and procedures are handled through one or more Connection
instances, and the specific functions and methods necessary for this are abstracted away, using the following classes contained in the
Database\Procedures sub-namespace:

#### Query

Query is the most fundamental class in Procedures. This represents a single SQL statement to be run on the Connection as supplied to its
constructor. Once defined, it is run using its execute() method, and the results, if any, are retrieved with the getResults() method.

#### PreparedStatement

PreparedStatement is a subclass of Query, which extends it with methods that allow named and positional placeholders to be defined and used
following the syntax appropriate for the database engine. A single PreparedStatement object can be used to batch queries by iterative calls
to the addParameterSet() method, each call supplied with an associative array having the placeholders and values to be substituted. The batch
of queries can then be run with a single call to the execute() method, and the cumulative results retrieved with getResults().

#### StatementSet

StatementSet is the most versatile of these classes. It addresses several shortcomings of the SQL standard prepared statement by creating its own set
of PreparedStatements depending on the values and criteria given to it. Among other things, this behavior allows for operators to be assigned dynamically
and for column names and values to only be specified as needed. The addCriteria() method is used for this, and can be either iteratively called or
passed a complete data structure.
Because of this unique, non-standard behavior, SQL used to define a StatementSet follows an augmented syntax, similar to that used by Angular. Three
basic placeholders are allowed (<<values>>, <<columns>>, and <<condition>>), and these are added to a base SQL statement where the appropriate clauses
would be. Examples:
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
this code to store and manipulate prior results, and to manipulate the following procedure as needed. The execution plan can then be run all at once
using the executeAll() procedure, or one step at a time using executeNext().
