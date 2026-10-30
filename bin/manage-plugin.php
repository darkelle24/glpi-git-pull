#!/usr/bin/env php
<?php
// Separate process so GLPI loads the downloaded plugin code, including a gitpull update.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
$directory = $argv[1] ?? '';
$step = $argv[2] ?? '';
if (!preg_match('/^[a-z0-9]+$/D', $directory) || !in_array($step, ['install', 'activate'], true)) {
    fwrite(STDERR, "Usage : manage-plugin.php nomplugin install|activate\n");
    exit(1);
}
if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')
    || !($webUser = posix_getpwnam('www-data')) || posix_geteuid() !== $webUser['uid']) {
    fwrite(STDERR, "Exécution obligatoire sous www-data.\n");
    exit(1);
}
try {
    $glpiRoot = dirname(__DIR__, 3);
    require_once $glpiRoot . '/src/Glpi/Application/ResourcesChecker.php';
    (new \Glpi\Application\ResourcesChecker($glpiRoot))->checkResources();
    require_once $glpiRoot . '/vendor/autoload.php';
    $kernel = new \Glpi\Kernel\Kernel();
    $kernel->boot();
    global $DB;
    if (!($DB instanceof \DBmysql) || !$DB->connected || !\Config::isLegacyConfigurationLoaded()) {
        throw new \RuntimeException('GLPI doit être installé et sa base accessible.');
    }
    foreach (['Paths', 'PluginLifecycle'] as $class) {
        require_once dirname(__DIR__) . '/src/' . $class . '.php';
    }
    $lifecycle = new \GlpiPlugin\Gitpull\PluginLifecycle();
    echo ($step === 'install' ? $lifecycle->installOrUpdate($directory)
        : $lifecycle->activateIfNeeded($directory)) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
