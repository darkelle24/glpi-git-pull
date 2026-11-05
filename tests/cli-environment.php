<?php
// Real PHP child process; no GLPI installation, SQL connection or credentials required.
use GlpiPlugin\Gitpull\Process;

require dirname(__DIR__) . '/src/Paths.php';
require dirname(__DIR__) . '/src/Process.php';
$dir = str_replace('\\', '/', sys_get_temp_dir()) . '/gitpull-cli-' . bin2hex(random_bytes(6));
mkdir($dir . '/config-volume', 0700, true);
define('GLPI_CONFIG_DIR', $dir . '/config-volume');
define('GLPI_VAR_DIR', $dir . '/files-volume');
define('GLPI_LOG_DIR', $dir . '/logs-volume');
define('GLPI_MARKETPLACE_DIR', $dir . '/marketplace-volume');
define('GLPI_ENVIRONMENT_TYPE', 'production');
$configFile = GLPI_CONFIG_DIR . '/config_db.php';
file_put_contents($configFile, '<?php return "configuration-volume-loaded";');
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$count;
};
// Resolved constants must take precedence over inherited or conflicting settings.
$names = ['GLPI_CONFIG_DIR', 'GLPI_DB_PASSWORD', 'GITHUB_TOKEN', 'GIT_SSH_COMMAND'];
$previous = [];
foreach ($names as $name) {
    $previous[$name] = getenv($name);
    putenv($name . '=gitpull-test-inherited-value');
}
try {
    $env = Process::environment();
    foreach (['GLPI_CONFIG_DIR', 'GLPI_VAR_DIR', 'GLPI_LOG_DIR', 'GLPI_MARKETPLACE_DIR', 'GLPI_ENVIRONMENT_TYPE'] as $name) {
        $check(($env[$name] ?? null) === constant($name), $name . ' doit être transmis au processus PHP');
    }
    foreach (['GLPI_DB_PASSWORD', 'GITHUB_TOKEN', 'GIT_SSH_COMMAND'] as $name) {
        $check(!array_key_exists($name, $env), $name . ' ne doit pas être hérité');
    }
    $check($env['GIT_TERMINAL_PROMPT'] === '0' && $env['GIT_CONFIG_GLOBAL'] === '/dev/null'
        && $env['GIT_ALLOW_PROTOCOL'] === 'https', 'Les restrictions Git restent actives');

    $child = static function (array $environment) use ($dir): array {
        $code = '$config = (getenv("GLPI_CONFIG_DIR") ?: __DIR__ . "/config") . "/config_db.php";'
            . 'if (!is_readable($config)) { exit(2); }'
            . 'echo json_encode([require $config, getenv("GLPI_VAR_DIR"), getenv("GLPI_LOG_DIR"), getenv("GLPI_DB_PASSWORD")]);';
        $pipes = [];
        $proc = proc_open([PHP_BINARY, '-r', $code], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $dir, $environment, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new RuntimeException('Le processus PHP de test ne démarre pas.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $output, $error];
    };
    $withoutConfig = $env;
    unset($withoutConfig['GLPI_CONFIG_DIR']);
    $check($child($withoutConfig)[0] === 2, 'La perte du chemin Docker reproduit la configuration SQL introuvable');
    [$exit, $output, $error] = $child($env);
    $check($exit === 0 && $error === '', 'Le processus PHP charge la configuration du volume : ' . $error);
    $check(json_decode($output, true) === ['configuration-volume-loaded', GLPI_VAR_DIR, GLPI_LOG_DIR, false],
        'Configuration et chemins transmis au vrai processus, sans hériter du mot de passe');
    echo "$count vérifications de l’environnement CLI OK\n";
} finally {
    foreach ($previous as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
    unlink($configFile);
    rmdir(GLPI_CONFIG_DIR);
    foreach ([GLPI_VAR_DIR . '/_plugins/gitpull', GLPI_VAR_DIR . '/_plugins', GLPI_VAR_DIR, $dir] as $path) {
        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
