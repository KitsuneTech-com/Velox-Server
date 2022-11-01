# Velox MVA Framework

Velox is intended as a streamlined client/server data retrieval and communication platform for websites and progressive web apps. While designed mainly for a LAMP backend, Velox also wraps SQL Server and ODBC connections into a single platform-agnostic class structure that eliminates the need to juggle syntax when using multiple data sources. Velox is comprised of both front end and back end components, designed to communicate with each other over AJAX, but decoupled in such a way that each can be used independently if necessary. Used as a whole, Velox simplifies development by implementing a CRUD interface on the front end and automating the data flow up to and back from the back-end database. The client-side component communicates with the server-side component by way of an API endpoint that parses the request and dynamically includes the appropriate query definition file, which can be set up using the provided template with only the sample SQL replaced. This allows the back-end developer to work almost entirely in SQL and build the necessary datasets to pass to the front-end with minimal effort. This repository contains the server-side component of this framework.

## Requirements
The eventual goal is to make this framework as portable and platform-agnostic as possible. At this early stage in development, however, the focus is currently on a LAMP server architecture. The particular requirements for the server-side component are as follows:

* PHP 8.0.2+, with Composer 2.0+
  * Either the PDO or sqlsrv extension (or both), depending on the database engine to be used
  * The xmlwriter extension, if the server-side component is to be used without the client-side component and XML output is needed
* A web server that supports PHP server-side scripting (Apache 2.4+ is specifically supported, but NGINX, IIS, and others may work as well)

## Note for Developers and Potential Contributors
This framework is still in development, and is not at present suitable for integration into any serious project. When ready, it will be released under a permissive open-source license (which specific license is still under consideration), without cost. Because this is being developed for common use, any feature requests are welcome and will be considered on their merits. Feel free to drop any such requests in Issues.

## Disclaimer
As mentioned in the note above, this is under active development, and as such there is absolutely no warranty of fitness of use. If you use any part of this framework prior to official release, you do so at your own risk. Anyone who contributes code to this project does so without assuming any liability for damages that might result from its use prior to official release.
