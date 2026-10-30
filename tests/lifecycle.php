<?php
namespace {
    final class Plugin {
        public const ANEW = 0, ACTIVATED = 1, NOTINSTALLED = 2, TOBECONFIGURED = 3,
            NOTACTIVATED = 4, NOTUPDATED = 6;
        public static int $state = self::NOTINSTALLED, $installs = 0, $activations = 0;
        public static bool $valid = true, $failInstall = false, $failActivation = false, $needsConfig = false;
        public array $fields = [];
        public function getInformationsFromDirectory($directory): array { return self::$valid ? ['name' => $directory] : []; }
        public function checkPluginState($directory): void {}
        public function getFromDBByCrit($criteria): bool {
            $this->fields = ['id' => 1, 'directory' => $criteria['directory'], 'state' => self::$state];
            return true;
        }
        public static function load($directory, $force): void {}
        public function install($id): void {
            ++self::$installs;
            if (!self::$failInstall) self::$state = self::$needsConfig ? self::TOBECONFIGURED : self::NOTACTIVATED;
        }
        public function activate($id): bool {
            ++self::$activations;
            if (self::$failActivation) return false;
            self::$state = self::ACTIVATED;
            return true;
        }
    }
}
namespace GlpiPlugin\Gitpull {
    // No actual process, DNS lookup, Git command or GLPI database in this test.
    function dns_get_record($host, $flags): array { return [['ip' => '93.184.216.34']]; }
    final class Process {
        public static array $events = [], $commands = [];
        public static ?string $failStep = null;
        public static function assertUser(): void {}
        public static function binary($name): string { return PHP_BINARY; }
        public function git(array $args, ?string $cwd = null): string {
            if ($args[0] === 'clone') {
                $staging = $args[array_key_last($args)];
                mkdir($staging . '/.git', 0700, true);
                file_put_contents($staging . '/setup.php', '<?php');
                file_put_contents($staging . '/hook.php', '<?php');
                self::$events[] = 'download';
            }
            if ($args[0] === 'pull') self::$events[] = 'download';
            return match ($args[0]) {
                'remote' => 'https://github.com/example/sample.git',
                'rev-parse' => $args[1] === '--symbolic-full-name' ? 'refs/remotes/origin/main' : 'abcdef',
                'rev-list' => '1',
                default => 'OK',
            };
        }
        public function run(array $args, ?string $cwd = null, int $timeout = 120): string {
            self::$commands[] = [$args, $cwd, $timeout];
            $step = $args[3] ?? 'script';
            self::$events[] = $step;
            if (self::$failStep === $step) throw new \RuntimeException('Échec simulé ' . $step);
            $service = new PluginLifecycle();
            return match ($step) {
                'install' => $service->installOrUpdate($args[2]),
                'activate' => $service->activateIfNeeded($args[2]),
                default => 'Script OK',
            };
        }
    }
}
namespace {
    use GlpiPlugin\Gitpull\{Manifest, Paths, PluginLifecycle, Process, RepositoryManager, StateStore};
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/gitpull-lifecycle-' . bin2hex(random_bytes(6));
    mkdir($dir . '/plugins/sample/.git', 0700, true);
    define('GLPI_ROOT', $dir);
    define('GLPI_VAR_DIR', $dir);
    define('GLPI_CONFIG_DIR', $dir);
    foreach (['AtomicJson', 'Paths', 'Manifest', 'StateStore', 'Diagnostics', 'PluginLifecycle', 'RepositoryManager'] as $class) {
        require dirname(__DIR__) . '/src/' . $class . '.php';
    }
    $count = 0;
    $check = static function (bool $ok, string $label) use (&$count): void {
        if (!$ok) throw new RuntimeException($label);
        ++$count;
    };
    $reject = static function (callable $fn, string $label) use ($check): void {
        try { $fn(); } catch (RuntimeException | InvalidArgumentException) { $check(true, $label); return; }
        throw new RuntimeException($label);
    };
    $repo = ['id' => 'sample', 'url' => 'https://github.com/example/sample.git', 'path' => 'plugins/sample',
        'post_pull' => null, 'hard_pull' => false, 'auto_check' => true, 'auto_pull' => false,
        'check_interval' => 5, 'pull_interval' => 30];
    try {
        $check(!array_key_exists('manage_plugin', Manifest::validate($repo)), 'Ancien manifeste accepté sans changer sa forme');
        $reject(fn() => Manifest::validate($repo + ['manage_plugin' => 'yes']), 'Option non booléenne refusée');
        $check(PluginLifecycle::directory($repo) === null, 'Dépôt ordinaire ignoré');
        file_put_contents($dir . '/plugins/sample/setup.php', '<?php');
        file_put_contents($dir . '/plugins/sample/hook.php', '<?php');
        $check(PluginLifecycle::directory($repo) === 'sample', 'Plugin direct détecté');
        $check(PluginLifecycle::directory(array_replace($repo, ['path' => 'extensions/sample'])) === null, 'Cible hors plugins ignorée');
        $check(PluginLifecycle::directory(array_replace($repo, ['path' => 'plugins/sample/nested'])) === null, 'Cible imbriquée ignorée');
        $service = new PluginLifecycle();
        $service->installOrUpdate('sample');
        $check(Plugin::$installs === 1 && Plugin::$state === Plugin::NOTACTIVATED, 'Installation initiale');
        $service->activateIfNeeded('sample');
        $check(Plugin::$activations === 1 && Plugin::$state === Plugin::ACTIVATED, 'Activation initiale');
        $service->installOrUpdate('sample');
        $service->activateIfNeeded('sample');
        $check(Plugin::$installs === 1 && Plugin::$activations === 1, 'Plugin à jour et actif laissé intact');
        Plugin::$state = Plugin::NOTUPDATED;
        $service->installOrUpdate('sample');
        $service->activateIfNeeded('sample');
        $check(Plugin::$installs === 2 && Plugin::$activations === 2, 'Mise à jour et réactivation');
        Plugin::$state = Plugin::NOTACTIVATED;
        $service->installOrUpdate('sample');
        $service->activateIfNeeded('sample');
        $check(Plugin::$installs === 2 && Plugin::$activations === 3, 'Plugin installé seulement réactivé');
        Plugin::$state = Plugin::NOTINSTALLED;
        Plugin::$failInstall = true;
        $reject(fn() => $service->installOrUpdate('sample'), 'Échec installation détecté malgré retour void');
        Plugin::$failInstall = false;
        Plugin::$needsConfig = true;
        $service->installOrUpdate('sample');
        $activations = Plugin::$activations;
        $reject(fn() => $service->activateIfNeeded('sample'), 'Configuration manuelle requise signalée');
        $check(Plugin::$activations === $activations, 'Configuration manuelle non contournée');
        Plugin::$needsConfig = false;
        Plugin::$state = Plugin::NOTACTIVATED;
        Plugin::$failActivation = true;
        $reject(fn() => $service->activateIfNeeded('sample'), 'Échec activation détecté');
        Plugin::$failActivation = false;
        Plugin::$valid = false;
        $reject(fn() => $service->installOrUpdate('sample'), 'Métadonnées plugin invalides refusées');
        Plugin::$valid = true;
        $reject(fn() => $service->installOrUpdate('../sample'), 'Identifiant dangereux refusé');

        $manager = new RepositoryManager();
        $check($manager->run($repo, 'pull') && Process::$commands === [], 'Case décochée sans gestion GLPI');
        $managed = $repo + ['manage_plugin' => true];
        $check($manager->run($managed, 'check') && Process::$commands === [], 'Vérifier ne déclenche pas de gestion GLPI');
        file_put_contents($dir . '/plugins/sample/prepare.php', '<?php');
        $managed['post_pull'] = ['type' => 'php', 'path' => 'prepare.php'];
        Plugin::$state = Plugin::NOTUPDATED;
        Process::$events = [];
        $check($manager->run($managed, 'pull'), 'Download avec script et gestion GLPI');
        $check(Process::$events === ['download', 'script', 'install', 'activate'], 'Ordre téléchargement, script, installation et activation');
        $check(Plugin::$state === Plugin::ACTIVATED, 'Plugin finalement actif');
        $command = Process::$commands[1];
        $check($command[0][2] === 'sample' && $command[0][3] === 'install'
            && str_ends_with($command[0][1], '/bin/manage-plugin.php')
            && $command[1] === Paths::root(), 'Processus isolé ciblé sur le bon plugin');
        Process::$events = [];
        Process::$failStep = 'install';
        $check(!$manager->run($managed, 'pull'), 'Échec installation propagé au dépôt');
        $state = (new StateStore())->get($managed);
        $check($state['status'] === 'error' && str_contains($state['error'], 'install'), 'Erreur GLPI visible dans l’état');
        $check(!in_array('activate', Process::$events, true), 'Pas d’activation après échec installation');
        Process::$failStep = 'script';
        Process::$events = [];
        $check(!$manager->run($managed, 'pull') && !in_array('install', Process::$events, true),
            'Script en échec bloque la gestion GLPI');
        Process::$failStep = 'activate';
        $check(!$manager->run($managed, 'pull'), 'Échec activation propagé');
        Process::$failStep = null;
        $clone = array_replace($managed, ['id' => 'second', 'path' => 'plugins/second', 'post_pull' => null]);
        Plugin::$state = Plugin::NOTINSTALLED;
        Process::$events = [];
        $check($manager->run($clone, 'pull'), 'Gestion GLPI après clonage initial');
        $check(Process::$events === ['download', 'install', 'activate'], 'Clonage puis installation et activation');
        $check((new StateStore())->get($clone)['status'] === 'ready', 'Résultat final après clonage');
        echo "$count vérifications de gestion GLPI OK\n";
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
