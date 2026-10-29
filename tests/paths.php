<?php
// Portable path/manifest tests: no GLPI database, no external Git commands.
use GlpiPlugin\Gitpull\AtomicJson;
use GlpiPlugin\Gitpull\Manifest;
use GlpiPlugin\Gitpull\Paths;

$dir = str_replace('\\', '/', sys_get_temp_dir()) . '/gitpull-paths-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
define('GLPI_ROOT', $dir);
define('GLPI_CONFIG_DIR', $dir);
define('GLPI_VAR_DIR', $dir);
// Load Paths from a simulated installed plugin to exercise the real self-directory guard.
mkdir($dir . '/plugins/gitpull/src', 0700, true);
copy(dirname(__DIR__) . '/src/Paths.php', $dir . '/plugins/gitpull/src/Paths.php');
foreach (['AtomicJson', 'Paths', 'Manifest'] as $class) {
    require $class === 'Paths'
        ? $dir . '/plugins/gitpull/src/Paths.php'
        : dirname(__DIR__) . '/src/' . $class . '.php';
}
$dir = Paths::root(); // Use the canonical root on Windows too (short-path aliases).
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$count;
};
$reject = static function (callable $fn) use ($check): void {
    try {
        $fn();
    } catch (InvalidArgumentException) {
        $check(true, 'Rejet du chemin');
        return;
    }
    throw new RuntimeException('Un chemin interdit a été accepté.');
};
$repo = [
    'id' => 'sample', 'url' => 'https://github.com/example/my-plugin.git',
    'post_pull' => null, 'hard_pull' => false, 'auto_check' => true, 'auto_pull' => false,
    'check_interval' => 5, 'pull_interval' => 30,
];
try {
    $check(Paths::selfTarget() === 'plugins/gitpull', 'Destination exacte du plugin installé');
    $selfManifest = new Manifest();
    $initialRevision = $selfManifest->revision();
    $selfRepo = $selfManifest->all()['gitpull'];
    $check($selfRepo['path'] === 'plugins/gitpull', 'Manifeste initial dirigé vers le plugin installé');
    $check(Paths::target(Manifest::validate($selfRepo)) === $dir . '/plugins/gitpull', 'Gestion du dossier exact du plugin autorisée');
    $selfManifest->save($selfRepo, $initialRevision);
    $check($selfManifest->all()['gitpull'] === $selfRepo, 'Configuration du plugin lui-même enregistrable');
    $reject(fn() => Paths::validateTarget('plugins'));
    $reject(fn() => Paths::validateTarget('plugins/gitpull/src'));
    $reject(fn() => Paths::validateTarget($dir));
    // An intentionally empty existing manifest must stay empty.
    AtomicJson::write(Paths::manifest(), ['version' => 1, 'repositories' => []]);
    $check($selfManifest->all() === [], 'Manifeste existant vide préservé');
    $valid = Manifest::validate($repo);
    $check($valid['path'] === '', 'Champ path facultatif');
    $check(Paths::target($valid) === $dir . '/plugins/my-plugin', 'Destination par défaut depuis le nom du dépôt');
    $check(Paths::target($repo + ['path' => 'extensions/team/plugin']) === $dir . '/extensions/team/plugin', 'Destination relative personnalisée');
    $legacy = Manifest::validate($repo + ['path' => $dir . '/plugins/legacy']);
    $check($legacy['path'] === 'plugins/legacy', 'Migration en mémoire du chemin absolu interne');
    $check(Paths::defaultTarget('https://github.com/example/my-plugin') === 'plugins/my-plugin', 'URL sans suffixe .git');

    foreach (['../escape', 'plugins/../escape', './plugins/a', 'plugins//a', 'plugins/a/',
        '/etc/evil', $dir . '-outside/plugin', 'plugins/../../escape', 'plugins/./a',
        'plugins/a/..', 'plugins/.git/a', 'plugins/%2e%2e/a', 'plugins\\..\\a',
        'plugins/a' . chr(0), 'C:/outside/plugin', $dir . '/../escape'] as $bad) {
        $reject(fn() => Manifest::validate($repo + ['path' => $bad], false));
    }
    $check(!is_dir($dir . '/extensions'), 'La validation ne crée aucun dossier');
    Paths::prepareParent($repo + ['path' => 'extensions/team/plugin']);
    $check(is_dir($dir . '/extensions/team'), 'Création des parents lors du déploiement');
    $check(!file_exists($dir . '/extensions/team/plugin'), 'La cible reste réservée au clone atomique');

    $m = new Manifest();
    $m->save($repo, $m->revision());
    $raw = AtomicJson::read(Paths::manifest());
    $check($raw['repositories'][0]['path'] === '', 'Champ vide conservé dans le manifeste');
    $check(!isset($raw['repositories'][0]['target']), 'Aucun chemin calculé ajouté au manifeste');
    $other = $repo;
    $other['id'] = 'other';
    $reject(fn() => $m->save($other, $m->revision())); // same inferred target
    $other['path'] = 'plugins/my-plugin/nested';
    $reject(fn() => $m->save($other, $m->revision())); // overlapping target
    $other['path'] = 'plugins/other';
    $m->save($other, $m->revision());
    $check(count($m->all()) === 2, 'Deux destinations distinctes');
    $other['post_pull'] = ['type' => 'bash', 'path' => 'scripts/install.sh'];
    $scriptRepo = Manifest::validate($other);
    $check($scriptRepo['post_pull']['path'] === 'scripts/install.sh', 'Script relatif conservé');
    $scriptRepo['post_pull']['path'] = $dir . '/plugins/other/scripts/install.sh';
    $scriptRepo = Manifest::validate($scriptRepo);
    $check($scriptRepo['post_pull']['path'] === 'scripts/install.sh', 'Ancien script absolu interne converti');
    foreach (['../install.sh', 'scripts/../install.sh', '/opt/install.sh',
        $dir . '/plugins/other-copy/install.sh', 'scripts\\install.sh', '.git/hooks/post-merge'] as $bad) {
        $attempt = $other;
        $attempt['post_pull']['path'] = $bad;
        $reject(fn() => Manifest::validate($attempt, false));
    }
    mkdir($dir . '/plugins/other/scripts', 0700, true);
    file_put_contents($dir . '/plugins/other/scripts/install.sh', '#!/bin/sh');
    $check(str_replace('\\', '/', Paths::script($scriptRepo)) === $dir . '/plugins/other/scripts/install.sh', 'Script résolu en absolu dans le clone');
    $missing = $other;
    $missing['post_pull']['path'] = 'missing.sh';
    try {
        Paths::script($missing);
        throw new LogicException('Script absent accepté.');
    } catch (RuntimeException) {
        $check(true, 'Script absent rejeté');
    }
    $outside = $dir . '-outside';
    mkdir($outside);
    file_put_contents($outside . '/install.sh', 'outside');
    if (@symlink($outside, $dir . '/plugins/link')) {
        $reject(fn() => Manifest::validate($repo + ['path' => 'plugins/link/plugin']));
        $linked = $other;
        $linked['path'] = 'plugins/link';
        $linked['post_pull']['path'] = 'install.sh';
        $reject(fn() => Paths::script($linked));
        unlink($dir . '/plugins/link');
    } else {
        echo "Liens symboliques : test indisponible sur cet hôte.\n";
    }
    echo "$count vérifications de chemins OK\n";
} finally {
    if (isset($outside) && is_file($outside . '/install.sh')) {
        unlink($outside . '/install.sh');
        rmdir($outside);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isLink()) {
            unlink($item->getPathname());
        } elseif ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}
