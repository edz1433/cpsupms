<?php

/**
 * Sends the application root straight to the login page.
 *
 * Only used when the web server points /payroll at this project folder rather than
 * at public/. The target is derived from the current request, so it resolves to
 * /payroll/login on the server and to the matching path in any other mount point.
 */
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

header('Location: '.$base.'/login', true, 302);
exit;
