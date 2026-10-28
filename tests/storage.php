<?php
// Autonomous tests, compatible with Windows and Linux; no GLPI bootstrap.
use GlpiPlugin\Gitpull\AtomicJson;
use GlpiPlugin\Gitpull\Manifest;
use GlpiPlugin\Gitpull\StateStore;

$dir = sys_get_temp_dir() . '/gitpull-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
define('GLPI_VAR_DIR', $dir);
define('GLPI_CONFIG_DIR', $dir);
foreach (['AtomicJson', 'Paths', 'Manifest', 'StateStore'] as $class) {
    require dirname(__DIR__) . '/src/' . $class . '.php';
}
$count = 0;
$check = static function (bool $condition, string $message) use (&$count): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$count;
};
$expect = static function (callable $fn, string $type) use ($check): void {
    try {
        $fn();
    } catch (Throwable $e) {
        $check($e instanceof $type, 'Exception attendue : ' . $type . ', reçue : ' . get_class($e));
        return;
    }
    throw new RuntimeException('Exception attendue mais absente.');
};
try {
    $file = $dir . '/manifest.json';
    $check(AtomicJson::read($file, ['default' => true]) === ['default' => true], 'Valeur par défaut');
    AtomicJson::write($file, ['version' => 1, 'repositories' => []]);
    $manifest = new Manifest($file);
    $check($manifest->all() === [], 'Manifeste vide');
    $hash = hash_file('sha256', $file);
    $repo = ['id' => 'example', 'path' => '/tmp/gitpull-test-target'];
    $store = new StateStore();
    $store->put($repo, ['status' => 'pulling', 'last_check' => 123]);
    $check($store->get($repo)['status'] === 'pulling', 'Lecture du cache');
    $check(hash_file('sha256', $file) === $hash, 'Le cache ne modifie pas le manifeste');
    $changed = $repo + ['auto_pull' => true];
    $check($store->get($changed) === [], 'Configuration modifiée : état distinct');
    $lock = $store->lock($repo);
    $check(is_resource($lock), 'Premier verrou');
    $check($store->lock($repo) === null, 'Exclusion concurrente');
    flock($lock, LOCK_UN);
    fclose($lock);
    $lock = $store->lock($repo);
    $check(is_resource($lock), 'Verrou libéré');
    flock($lock, LOCK_UN);
    fclose($lock);
    $expect(fn() => $manifest->save([], str_repeat('0', 64)), RuntimeException::class);
    $check(hash_file('sha256', $file) === $hash, 'Conflit sans écrasement');
    AtomicJson::write($file, ['version' => 1, 'repositories' => [], 'status' => 'pulling']);
    $expect(fn() => $manifest->all(), InvalidArgumentException::class);
    file_put_contents($file, '{broken');
    $expect(fn() => $manifest->all(), JsonException::class);
    $check(file_get_contents($file) === '{broken', 'JSON invalide préservé');
    echo "$count vérifications OK\n";
} finally {
    // Only the unique test directory created above, never an installation directory.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
