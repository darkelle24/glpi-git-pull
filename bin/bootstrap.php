#!/usr/bin/env php
<?php
// Run after GLPI database installation. Works before activation of gitpull.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
// Resolve GLPI from this installation; no obsolete inc/includes.php bootstrap.
$glpiRoot = dirname(__DIR__, 3);
if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')
    || !($webUser = posix_getpwnam('www-data')) || posix_geteuid() !== $webUser['uid']) {
    fwrite(STDERR, "Exécution obligatoire sous www-data.\n");
    exit(1);
}
require_once $glpiRoot . '/src/Glpi/Application/ResourcesChecker.php';
(new \Glpi\Application\ResourcesChecker($glpiRoot))->checkResources();
require_once $glpiRoot . '/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
global $DB;
if (!($DB instanceof \DBmysql) || !$DB->connected || !\Config::isLegacyConfigurationLoaded()) {
    fwrite(STDERR, "GLPI doit être installé et sa base accessible avant le bootstrap.\n");
    exit(1);
}
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Gitpull\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
try {
    \GlpiPlugin\Gitpull\Process::assertUser();
    $repos = (new \GlpiPlugin\Gitpull\Manifest())->all();
    $ok = true;
    $manager = new \GlpiPlugin\Gitpull\RepositoryManager();
    foreach ($repos as $repo) {
        if (!file_exists(\GlpiPlugin\Gitpull\Paths::target($repo))) {
            $success = $manager->run($repo, 'bootstrap');
            echo $repo['id'] . ': ' . ($success ? 'OK' : 'ECHEC (voir cache)') . PHP_EOL;
            $ok = $success && $ok;
        }
    }
    exit($ok ? 0 : 1);
} catch (\Throwable $e) {
    \GlpiPlugin\Gitpull\Diagnostics::log('bootstrap_error', $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
