<?php
// Server pengembangan lokal:  php -S localhost:8080 -t <root-repo> app/tools/dev-router.php
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$root = dirname(__DIR__, 2);
$f = $root . $u;
if (preg_match('#^/app/(src|sql|storage|tools|tests)/#', $u)) { http_response_code(403); exit; }
if ($u !== '/' && is_file($f) && !str_ends_with($u, '.php')) return false;
if (str_starts_with($u, '/app')) { $_SERVER['SCRIPT_NAME'] = '/app/index.php'; require $root . '/app/index.php'; return true; }
return false;
