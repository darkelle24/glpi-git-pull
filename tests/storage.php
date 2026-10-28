<?php
// Autonomous tests, compatible with Windows and Linux; no GLPI bootstrap.
use GlpiPlugin\Gitpull\AtomicJson;
use GlpiPlugin\Gitpull\Manifest;
use GlpiPlugin\Gitpull\StateStore;
use GlpiPlugin\Gitpull\Diagnostics;

$dir = sys_get_temp_dir() . '/gitpull-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
define('GLPI_ROOT', $dir);
define('GLPI_VAR_DIR', $dir);
define('GLPI_CONFIG_DIR', $dir);
foreach (['AtomicJson', 'Paths', 'Manifest', 'StateStore', 'Diagnostics'] as $class) {
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
    $expect(fn() => AtomicJson::read($file), JsonException::class);
    file_put_contents($file, '{"version":1,"repositories":[]}%' );
    $expect(fn() => $manifest->all(false), JsonException::class);
    $r = [
        'id' => 'gitpull', 'url' => 'https://github.com/darkelle24/glpi-git-pull.git',
        'path' => '/var/www/glpi/plugins/gitpull', 'post_pull' => null, 'hard_pull' => false,
        'auto_check' => true, 'auto_pull' => false, 'check_interval' => 60, 'pull_interval' => 1440,
    ];
    AtomicJson::write(PathsForTest(), ['version' => 1, 'repositories' => [$r]]);
    $default = new Manifest();
    $check(isset($default->all(false)['gitpull']), 'Un dépôt bloqué reste visible dans la liste');
    $expect(fn() => $default->all(), InvalidArgumentException::class);
    $configHash = hash_file('sha256', PathsForTest());
    $diagnostic = Diagnostics::report();
    $check($diagnostic['path'] === PathsForTest(), 'Chemin exact affiché');
    $check($diagnostic['count'] === 1 && $diagnostic['validation_error'] !== null, 'Diagnostic de la cible rejetée');
    for ($i = 0; $i < 55; ++$i) {
        Diagnostics::log('test', 'event-' . $i);
    }
    $diagnostic = Diagnostics::report();
    $check(count($diagnostic['events']) === 50, 'Journal borné à 50 événements');
    $check($diagnostic['events'][0]['message'] === 'event-54', 'Événements les plus récents en premier');
    $check(hash_file('sha256', PathsForTest()) === $configHash, 'Diagnostic et journal sans modification du manifeste');
    $bad = $r;
    $bad['auto_check'] = 'true';
    AtomicJson::write(PathsForTest(), ['version' => 1, 'repositories' => [$bad]]);
    $expect(fn() => $default->all(false), InvalidArgumentException::class);
    file_put_contents(PathsForTest(), '{"version":1,"repositories":[]}%' );
    $diagnostic = Diagnostics::report();
    $check(str_contains($diagnostic['validation_error'], 'JSON invalide'), 'JSON avec suffixe pourcent signalé');
    $check($diagnostic['count'] === null, 'JSON corrompu non présenté comme une liste vide');
    unlink(PathsForTest());
    $diagnostic = Diagnostics::report();
    $check(!$diagnostic['exists'] && $diagnostic['count'] === null, 'Fichier absent clairement signalé');
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

function PathsForTest(): string
{
    return GLPI_CONFIG_DIR . '/gitpull_repos.json';
}
