#!/usr/bin/env php
<?php
// Read-only runtime report; no GLPI DB bootstrap, no commands launched.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
require_once dirname(__DIR__) . '/src/Process.php';
$runtime = \GlpiPlugin\Gitpull\Process::runtime();
$runtime['uid'] = function_exists('posix_geteuid') ? posix_geteuid() : null;
echo json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($runtime['errors'] === [] ? 0 : 1);
