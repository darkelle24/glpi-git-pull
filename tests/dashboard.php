<?php
/** Regression checks for real front controllers with a simulated GLPI redirect contract. */
namespace {
    final class TestRedirect extends \Exception
    {
        public function __construct(public string $url, public int $status)
        {
            parent::__construct('GLPI redirect');
        }
    }

    final class TestPage
    {
        public static ?array $rendered = null;
    }

    final class Session
    {
        public static function checkRight($name, $right): void {}
        public static function start(): void
        {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
        }
    }

    final class Plugin
    {
        public function isActivated($name): bool { return true; }
    }

    final class Html
    {
        public static function header(...$args): void {}
        public static function footer(): void {}
        public static function redirect($url, $status = 302): never
        {
            // Same control-flow contract as GLPI 12.
            throw new TestRedirect($url, $status);
        }
    }
}

namespace Glpi\Exception\Http {
    final class AccessDeniedHttpException extends \RuntimeException {}
}

namespace Glpi\Application\View {
    final class TemplateRenderer
    {
        public static function getInstance(): self { return new self(); }
        public function display($template, $data): void
        {
            \TestPage::$rendered = ['template' => $template, 'data' => $data];
        }
    }
}

namespace GlpiPlugin\Gitpull {
    final class RepositoryManager
    {
        public static string $result = 'success';
        public static int $calls = 0;

        public function run(array $repo, string $action): bool
        {
            ++self::$calls;
            if (self::$result === 'exception') {
                throw new \RuntimeException('Échec de lancement simulé');
            }
            if (self::$result === 'busy') {
                return false;
            }
            (new StateStore())->put($repo, [
                'status' => self::$result === 'success' ? 'ready' : 'error',
                'last_check' => time(), 'last_check_attempt' => time(),
                'error' => self::$result === 'success' ? null : 'Git indisponible (simulation)',
            ]);
            return self::$result === 'success';
        }
    }
}

namespace {
    use GlpiPlugin\Gitpull\AtomicJson;
    use GlpiPlugin\Gitpull\Manifest;
    use GlpiPlugin\Gitpull\Paths;
    use GlpiPlugin\Gitpull\RepositoryManager;
    use GlpiPlugin\Gitpull\StateStore;

    $dir = sys_get_temp_dir() . '/gitpull-dashboard-' . bin2hex(random_bytes(6));
    foreach (['', '/config', '/files', '/plugins', '/sessions'] as $suffix) {
        mkdir($dir . $suffix, 0700);
    }
    define('GLPI_ROOT', $dir);
    define('GLPI_CONFIG_DIR', $dir . '/config');
    define('GLPI_VAR_DIR', $dir . '/files');
    define('UPDATE', 2);
    foreach (['AtomicJson', 'Paths', 'Manifest', 'StateStore', 'Process', 'Diagnostics', 'Web'] as $class) {
        require dirname(__DIR__) . '/src/' . $class . '.php';
    }
    $CFG_GLPI = ['root_doc' => '/glpi-subpath'];
    ini_set('session.save_path', $dir . '/sessions');
    ini_set('session.use_cookies', '0');
    ini_set('session.cache_limiter', '');
    session_id('gitpull-test-' . bin2hex(random_bytes(8)));
    Session::start();
    $_SESSION['gitpull_csrf'] = 'test-csrf-token';

    $checkCount = 0;
    $check = static function (bool $ok, string $label) use (&$checkCount): void {
        if (!$ok) {
            throw new \RuntimeException($label);
        }
        ++$checkCount;
    };
    $request = static function (string $page, string $method = 'GET', array $post = [], array $get = []): array {
        Session::start();
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['PHP_SELF'] = '/plugins/gitpull/front/' . $page;
        $_GET = $get;
        $_POST = $post;
        TestPage::$rendered = null;
        ob_start();
        try {
            (static function (string $file): void { require $file; })(dirname(__DIR__) . '/front/' . $page);
            return ['redirect' => null, 'view' => TestPage::$rendered, 'json' => ob_get_clean()];
        } catch (TestRedirect $e) {
            ob_end_clean();
            return ['redirect' => $e, 'view' => TestPage::$rendered];
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    };
    $postAction = static fn(string $action, string $id = 'alpha'): array => [
        'gitpull_csrf' => 'test-csrf-token', 'id' => $id, 'action' => $action,
    ];
    $repo = [
        'id' => 'alpha', 'url' => 'https://github.com/example/alpha.git', 'path' => 'plugins/alpha',
        'post_pull' => null, 'hard_pull' => false, 'auto_check' => true,
        'auto_pull' => false, 'check_interval' => 5, 'pull_interval' => 30,
    ];
    $second = $repo;
    $second['id'] = 'beta';
    $second['url'] = 'https://github.com/example/beta.git';
    $second['path'] = 'plugins/beta';
    try {
        AtomicJson::write(Paths::manifest(), ['version' => 1, 'repositories' => [$repo, $second]]);
        $manifestHash = hash_file('sha256', Paths::manifest());
        $page = $request('dashboard.php');
        $check(count($page['view']['data']['rows']) === 2, 'Les deux dépôts doivent être affichés');
        $check($page['view']['data']['dashboard_js_version'] === hash_file('sha256', dirname(__DIR__) . '/public/js/dashboard.js'), 'URL du script versionnée selon son contenu');

        $response = $request('dashboard.php', 'POST', $postAction('check'));
        $check($response['redirect']?->status === 303, 'Check doit rediriger, sans attraper la redirection');
        $check($response['redirect']->url === '/glpi-subpath/plugins/gitpull/front/dashboard.php', 'Sous-chemin GLPI conservé');
        $check($response['view'] === null, 'Aucun tableau vide rendu pendant le POST');
        $page = $request('dashboard.php');
        $check(count($page['view']['data']['rows']) === 2, 'Liste conservée après un check');
        $check($page['view']['data']['rows'][0]['target'] === Paths::target($repo), 'Destination absolue de chaque ligne');
        $check(str_contains($page['view']['data']['notice'], 'alpha'), 'Retour utilisateur après réussite');
        $calls = RepositoryManager::$calls;
        $page = $request('dashboard.php');
        $check(RepositoryManager::$calls === $calls, 'Actualiser le GET ne relance pas Git');
        $check($page['view']['data']['notice'] === null, 'Message de réussite consommé une fois');

        RepositoryManager::$result = 'failure';
        $response = $request('dashboard.php', 'POST', $postAction('pull'));
        $check($response['redirect']?->status === 303, 'Pull en erreur redirige aussi');
        $page = $request('dashboard.php');
        $check(count($page['view']['data']['rows']) === 2, 'Liste conservée après échec de pull');
        $check($page['view']['data']['error'] !== null, 'Échec annoncé après redirection');
        $check($page['view']['data']['rows'][0]['state']['error'] !== null, 'Erreur Git visible sur la ligne');

        RepositoryManager::$result = 'exception';
        $response = $request('dashboard.php', 'POST', $postAction('check'));
        $check($response['redirect']?->status === 303, 'Une exception backend revient au tableau de bord');
        $page = $request('dashboard.php');
        $check(count($page['view']['data']['rows']) === 2, 'Liste conservée après exception backend');
        $check(str_contains($page['view']['data']['error'], 'simulé'), 'Cause backend conservée');

        RepositoryManager::$result = 'busy';
        $lock = (new StateStore())->lock($repo);
        $response = $request('dashboard.php', 'POST', $postAction('pull'));
        $page = $request('dashboard.php');
        $check(count($page['view']['data']['rows']) === 2, 'Liste conservée si cible verrouillée');
        $check($page['view']['data']['rows'][0]['running'], 'Verrou actif affiché');
        flock($lock, LOCK_UN);
        fclose($lock);

        $calls = RepositoryManager::$calls;
        $response = $request('dashboard.php', 'POST', $postAction('invalid'));
        $check($response['redirect']?->status === 303 && RepositoryManager::$calls === $calls, 'Action invalide non exécutée');
        $page = $request('dashboard.php');
        $check(count($page['view']['data']['rows']) === 2 && $page['view']['data']['error'] !== null, 'Action invalide ne vide pas la liste');

        $response = $request('dashboard.php', 'POST', $postAction('check', 'unknown'));
        $check($response['redirect']?->status === 303 && RepositoryManager::$calls === $calls, 'Dépôt inconnu non exécuté');
        $request('dashboard.php'); // Consume the feedback.

        RepositoryManager::$result = 'success';
        $response = $request('dashboard.php', 'POST', $postAction('check'), ['gitpull_async' => '1']);
        $data = json_decode($response['json'], true, 512, JSON_THROW_ON_ERROR);
        $check($response['redirect'] === null && $response['view'] === null, 'Check asynchrone sans redirection ni page complète');
        $check(count($data['rows']) === 2 && $data['rows'][0]['label'] === 'À jour', 'Résultat asynchrone et liste conservés');
        $check(str_contains($data['notice'], 'alpha') && $data['error'] === null, 'Confirmation asynchrone');
        $calls = RepositoryManager::$calls;
        $response = $request('dashboard.php', 'GET', [], ['gitpull_async' => '1']);
        $data = json_decode($response['json'], true, 512, JSON_THROW_ON_ERROR);
        $check(RepositoryManager::$calls === $calls && count($data['rows']) === 2, 'Actualisation asynchrone sans relancer Git');

        $store = new StateStore();
        $store->put($repo, ['status' => 'checking', 'activity' => 'Récupération des mises à jour distantes…']);
        $lock = $store->lock($repo);
        $response = $request('dashboard.php', 'GET', [], ['gitpull_async' => '1']);
        $data = json_decode($response['json'], true, 512, JSON_THROW_ON_ERROR);
        $check($data['rows'][0]['running'] && $data['rows'][0]['label'] === 'Récupération des mises à jour distantes…', 'Étape en cours visible pendant le verrou');
        flock($lock, LOCK_UN);
        fclose($lock);
        $response = $request('dashboard.php', 'GET', [], ['gitpull_async' => '1']);
        $data = json_decode($response['json'], true, 512, JSON_THROW_ON_ERROR);
        $check(!$data['rows'][0]['running'] && $data['rows'][0]['label'] === 'Erreur / Non disponible', 'Une étape interrompue ne reste pas affichée en cours');

        RepositoryManager::$result = 'failure';
        $response = $request('dashboard.php', 'POST', $postAction('pull'), ['gitpull_async' => '1']);
        $data = json_decode($response['json'], true, 512, JSON_THROW_ON_ERROR);
        $check($data['error'] !== null && $data['rows'][0]['state']['error'] !== null, 'Erreur asynchrone visible sans rechargement');
        $calls = RepositoryManager::$calls;
        $response = $request('dashboard.php', 'POST', $postAction('invalid'), ['gitpull_async' => '1']);
        $data = json_decode($response['json'], true, 512, JSON_THROW_ON_ERROR);
        $check($data['error'] !== null && RepositoryManager::$calls === $calls, 'Action asynchrone invalide refusée');

        // A broken scalar cache must affect only its row, never the next repository.
        $cache = Paths::cache() . '/' . hash('sha256', json_encode($repo, JSON_THROW_ON_ERROR)) . '.json';
        file_put_contents($cache, 'null');
        $page = $request('dashboard.php');
        $check(count($page['view']['data']['rows']) === 2, 'Un cache illisible ne cache pas les autres dépôts');
        $check($page['view']['data']['rows'][0]['blocked'], 'Ligne en erreur conservée');
        $check($page['view']['data']['rows'][1]['repo']['id'] === 'beta', 'Le dépôt suivant reste affiché');
        (new StateStore())->put($repo, []);

        $check(hash_file('sha256', Paths::manifest()) === $manifestHash, 'Les actions ne modifient pas le manifeste');

        $save = [
            'gitpull_csrf' => 'test-csrf-token', 'revision' => (new Manifest())->revision(),
            'id' => 'alpha', 'url' => $repo['url'], 'path' => $repo['path'],
            'script_path' => '', 'script_type' => 'bash', 'auto_check' => 'on',
            'check_interval' => '10', 'pull_interval' => '30',
        ];
        $response = $request('repository.form.php', 'POST', $save, ['id' => 'alpha']);
        $check($response['redirect']?->status === 303, 'La sauvegarde ne capture pas sa redirection');
        $check((new Manifest())->all(false)['alpha']['check_interval'] === 10, 'Réglage effectivement sauvegardé');
        $page = $request('dashboard.php');
        $check(count($page['view']['data']['rows']) === 2, 'Liste conservée après sauvegarde');
        $check(str_contains($page['view']['data']['notice'], 'enregistrée'), 'Sauvegarde confirmée sans fausse erreur');

        try {
            $request('dashboard.php', 'POST', ['gitpull_csrf' => 'invalid', 'id' => 'alpha', 'action' => 'pull']);
            throw new \LogicException('POST sans jeton valide accepté.');
        } catch (\Glpi\Exception\Http\AccessDeniedHttpException) {
            $check(RepositoryManager::$calls === $calls, 'Protection CSRF préservée');
        }

        $manageSave = $save;
        $manageSave['revision'] = (new Manifest())->revision();
        $manageSave['manage_plugin'] = 'on';
        $request('repository.form.php', 'POST', $manageSave, ['id' => 'alpha']);
        $check((new Manifest())->all(false)['alpha']['manage_plugin'] === true, 'Gestion GLPI cochée enregistrée');
        unset($manageSave['manage_plugin']);
        $manageSave['revision'] = (new Manifest())->revision();
        $request('repository.form.php', 'POST', $manageSave, ['id' => 'alpha']);
        $check((new Manifest())->all(false)['alpha']['manage_plugin'] === false, 'Gestion GLPI décochée enregistrée');

        $invalidPathSave = $save;
        $invalidPathSave['revision'] = (new Manifest())->revision();
        $invalidPathSave['path'] = 'plugins/Mon-Plugin';
        $beforeInvalidPath = (new Manifest())->revision();
        $response = $request('repository.form.php', 'POST', $invalidPathSave, ['id' => 'alpha']);
        $check($response['redirect'] === null && $response['view']['data']['error'] !== null
            && (new Manifest())->revision() === $beforeInvalidPath, 'Nom de dossier invalide bloqué sans modifier le manifeste');
        $pathMessage = $response['view']['data']['path_error'];
        $check(str_contains($pathMessage, 'minuscules') && str_contains($pathMessage, 'plugins/monplugin')
            && str_contains($pathMessage, 'plugins/inventaire2'), 'Erreur sous le champ avec règle et deux exemples');

        $delete = [
            'gitpull_csrf' => 'test-csrf-token', 'action' => 'delete', 'id' => 'alpha',
            'revision' => (new Manifest())->revision(), 'confirmed' => '1',
        ];
        $beforeDelete = (new Manifest())->revision();
        $unconfirmed = $delete;
        $unconfirmed['confirmed'] = '0';
        $response = $request('repository.form.php', 'POST', $unconfirmed, ['id' => 'alpha']);
        $check($response['redirect'] === null && $response['view']['data']['error'] !== null
            && (new Manifest())->revision() === $beforeDelete, 'Suppression sans confirmation refusée');
        $stale = $delete;
        $stale['revision'] = str_repeat('0', 64);
        $response = $request('repository.form.php', 'POST', $stale, ['id' => 'alpha']);
        $check($response['view']['data']['error'] !== null
            && (new Manifest())->revision() === $beforeDelete, 'Suppression sur ancienne révision refusée');
        $mismatch = $delete;
        $mismatch['id'] = 'beta';
        $response = $request('repository.form.php', 'POST', $mismatch, ['id' => 'alpha']);
        $check($response['view']['data']['error'] !== null
            && (new Manifest())->revision() === $beforeDelete, 'Identifiant de suppression incohérent refusé');
        $response = $request('repository.form.php', 'POST', $delete);
        $check($response['view']['data']['error'] !== null
            && (new Manifest())->revision() === $beforeDelete, 'Suppression depuis le formulaire d’ajout refusée');
        $invalidToken = $delete;
        $invalidToken['gitpull_csrf'] = 'invalid';
        try {
            $request('repository.form.php', 'POST', $invalidToken, ['id' => 'alpha']);
            throw new \LogicException('Suppression sans CSRF acceptée');
        } catch (\Glpi\Exception\Http\AccessDeniedHttpException) {
            $check((new Manifest())->revision() === $beforeDelete, 'Suppression protégée par CSRF');
        }
        try {
            (new Manifest())->delete('unknown', $beforeDelete);
            throw new \LogicException('Suppression inconnue acceptée');
        } catch (\InvalidArgumentException) {
            $check((new Manifest())->revision() === $beforeDelete, 'Suppression d’un dépôt inconnu sans modification');
        }
        mkdir(Paths::target($repo), 0700);
        file_put_contents(Paths::target($repo) . '/keep.txt', 'conserver');
        $response = $request('repository.form.php', 'POST', $delete, ['id' => 'alpha']);
        $check($response['redirect']?->status === 303
            && $response['redirect']->url === '/glpi-subpath/plugins/gitpull/front/dashboard.php', 'Suppression puis retour à la liste');
        $remaining = (new Manifest())->all(false);
        $check(array_keys($remaining) === ['beta'], 'Seul le dépôt demandé est retiré');
        $check(file_get_contents(Paths::target($repo) . '/keep.txt') === 'conserver', 'Fichiers clonés conservés');
        $page = $request('dashboard.php');
        $check(count($page['view']['data']['rows']) === 1
            && str_contains($page['view']['data']['notice'], 'supprimé'), 'Liste et confirmation après suppression');
        $delete['id'] = 'beta';
        $delete['revision'] = (new Manifest())->revision();
        $request('repository.form.php', 'POST', $delete, ['id' => 'beta']);
        $check((new Manifest())->all(false) === [], 'Suppression du dernier dépôt autorisée');
        $emptyManifest = json_decode(file_get_contents(Paths::manifest()), true, 512, JSON_THROW_ON_ERROR);
        $check($emptyManifest['repositories'] === [] && $emptyManifest['version'] === 1, 'Manifeste vide valide conservé');

        echo "$checkCount vérifications du tableau de bord OK\n";
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
