# Velox MVA Framework

Velox is intended as a streamlined client/server data retrieval and communication platform for websites and progressive web apps. While currently designed for a LAMP backend (and optionally Microsoft SQL Server through the PHP sqlsrv driver), the included libraries are designed to make database-driven web development as platform-agnostic as possible. Velox is comprised of both front end and back-end components, designed to communicate with each other over AJAX, but decoupled in such a way that each can be used independently if necessary. Used as a whole, Velox simplifies development by implementing a CRUD interface on the front-end and automating the data flow up to and back from the back-end database. The client-side component communicates with the server-side component by way of an API endpoint that parses the request and dynamically includes the appropriate query definition file, which can be set up using the provided template with only the sample SQL replaced. This allows the back-end developer to work almost entirely in SQL and build the necessary datasets to pass to the front-end with minimal effort.

## Requirements
The eventual goal is to make this framework as portable and platform-agnostic as possible. At this early stage in development, however, the focus is currently on a LAMP server architecture. The particular requirements for the server-side component are as follows:

* PHP 8.0.2+, with Composer 2.0+
  * Either the PDO or sqlsrv extension (or both), depending on the database engine to be used
  * The xmlwriter extension, if the server-side component is to be used without the client-side component and XML output is needed
* A web server that supports PHP server-side scripting (Apache 2.4+ is specifically supported, but NGINX, IIS, and others may work as well)

The client-side component has yet to be fleshed out, but at minimum it will require JavaScript support for the nullish coalescing operator, Shadow DOM V1, form-associated custom elements, and AJAX communication (at present, the library uses XmlHttpRequest for this, but may be adapted to use the Fetch API instead). Web Sockets support may be built in later, when the corresponding server-side component is built, but this will not be a requirement. Put together, the supported browsers for this component (as determined from caniuse.com) are as follows:

### Supported:
* Edge 80+
* Firefox 72+
* Chrome 80+
* Safari 13.1+
* Opera 67+
* IOS Safari 13.7+
* Android Browser 81+
* Chrome for Android 88+
* Firefox for Android 85+

### Possibly supported:
* IOS Safari 13.7+ (may have issues with Shadow DOM [https://caniuse.com/shadowdomv1])
* UC Browser for Android (unknown support for nullish coalescing operator, XMLHttpRequest - Fetch API is supported)
* QQ Browser (unknown support for nullish coalescing operator, XMLHttpRequest - Fetch API is supported)

### Not currently supported:
* Internet Explorer (dead browser)
* Opera Mini (no support for Shadow DOM)
* Opera Mobile (no support for nullish coalescing operator, custom elements, Fetch API - XMLHttpRequest support is unknown)
* Samsung Internet (no support for nullish coalescing operator)
* Baidu Browser (no support for Shadow DOM)
* KaiOS Browser (no support for Shadow DOM or custom elements)

Any browsers not listed here are not on the list provided by caniuse.com, and should be regarded as a "maybe." As of December 2020, W3C statistics report that the supported browsers and versions above account for over 94.2% of overall usage, and because of this no effort will be made to polyfill for missing functionality. If an issue does arise from a supported browser, it should be raised in Issues; if you absolutely must use an unsupported browser, you will have to build your own fork. This list may evolve as the client-side component is developed and other features are added.

## Note for Developers and Potential Contributors
This framework is still in development, and is not at present suitable for integration into any serious project. When ready, it will be released under a permissive open-source license (which specific license is still under consideration), without cost. Because this is being developed for common use, any feature requests are welcome and will be considered on their merits. Feel free to drop any such requests in Issues.

## Disclaimer
As mentioned in the note above, this is under active development, and as such there is absolutely no warranty of fitness of use. If you use any part of this framework prior to official release, you do so at your own risk. Anyone who contributes code to this project does so without assuming any liability for damages that might result from its use prior to official release.
