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

## Note for Developers and Potential Contributors
As an open source project, the Velox MVA and its components (including Velox Server) have been developed for common
use, and any feature requests are welcome and will be considered on their merits. Feel free to drop any such requests
in Issues or Discussions. Those wishing to make contributions to the code base should read the
[Contribution Guidelines](https://github.com/KitsuneTech-com/Velox-Server/blob/main/CONTRIBUTING.md) for more details
on how to do so.