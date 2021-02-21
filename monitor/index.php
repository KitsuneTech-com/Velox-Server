<?php
// API hook for remote database monitor updates
// --------------------------------------------
// Note: while this hook is JSON-based and does not expose any security threats,
// to avoid potential DoS attacks and other abuse, this should be accompanied by
// the .htaccess file in this directory (or equivalent functionality if Apache
// is not being used) to limit access to only the servers being monitored.

print_r($_SERVER);