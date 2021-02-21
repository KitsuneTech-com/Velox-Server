# Velox MVC Framework
===================

Velox is intended as a streamlined client/server data retrieval and communication platform for websites and progressive web apps. While currently designed for a LAMP backend (and optionally Microsoft SQL Server through the PHP sqlsrv driver), the included libraries are designed to make database-driven web development as platform-agnostic as possible. Velox is comprised of both front end and back-end components, designed to communicate with each other over AJAX, but decoupled in such a way that each can be used independently if necessary. Used as a whole, Velox simplifies development by implementing a CRUD interface on the front-end and automating the data flow up to and back from the
back-end database. The client-side component communicates with
the server-side component by way of an API endpoint that parses the request and dynamically includes the appropriate query definition file, which can be set up using the provided template with only the sample SQL replaced. This allows the back-end developer to work almost entirely in SQL and build the necessary datasets to pass to the front-end with minimal effort.
