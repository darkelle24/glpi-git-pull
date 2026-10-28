<?php
use GlpiPlugin\Gitpull\Manifest;
use GlpiPlugin\Gitpull\Paths;
use GlpiPlugin\Gitpull\Process;

if (PHP_OS_FAMILY !== 'Linux') {
    fwrite(STDERR, "Test Linux uniquement.\n");
    exit(1);
}
$dir = sys_get_temp_dir() . '/gitpull-linux-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
define('GLPI_ROOT', $dir);
define('GLPI_CONFIG_DIR', $dir);
define('GLPI_VAR_DIR', $dir);
putenv('GITPULL_ALLOWED_ROOTS=' . $dir);
foreach (['AtomicJson', 'Paths', 'Manifest', 'Process'] as $class) {
    require dirname(__DIR__) . '/src/' . $class . '.php';
}
$count = 0;
$reject = static function (callable $fn) use (&$count): void {
    try {
        $fn();
    } catch (InvalidArgumentException) {
        ++$count;
        return;
    }
    throw new RuntimeException('Entrée dangereuse acceptée.');
};
$r = [
    'id' => 'example', 'url' => 'https://github.com/example/plugin.git',
    'path' => $dir . '/example', 'post_pull' => null, 'hard_pull' => false,
    'auto_check' => true, 'auto_pull' => false, 'check_interval' => 5, 'pull_interval' => 30,
];
try {
    Process::assertUser();
    Manifest::validate($r);
    ++$count;
    $m = new Manifest();
    $m->save($r, $m->revision());
    if ($m->all()['example'] !== $r) {
        throw new RuntimeException('Configuration non conservée.');
    }
    ++$count;
    foreach ([
        ['url' => 'ssh://github.com/example/plugin.git'],
        ['url' => 'https://token@github.com/example/plugin.git'],
        ['url' => 'https://github.com/example/plugin.git?token=x'],
        ['auto_check' => 'true'], ['check_interval' => 0],
        ['status' => 'ready'], ['path' => $dir . '/../outside'],
        ['path' => '/etc/gitpull-test'], ['path' => $dir . '/nested/plugin'],
    ] as $bad) {
        $reject(fn() => Manifest::validate(array_replace($r, $bad)));
    }
    symlink($dir, $dir . '/link');
    $reject(fn() => Paths::validateTarget($dir . '/link'));
    unlink($dir . '/link');
    $p = new Process();
    if ($p->run(['/usr/bin/printf', '%s', 'literal;$(echo unsafe)']) !== 'literal;$(echo unsafe)') {
        throw new RuntimeException('Arguments interprétés par un shell.');
    }
    ++$count;
    echo "$count vérifications Linux OK\n";
} finally {
    foreach (glob($dir . '/.*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file) || is_link($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}
