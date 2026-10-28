<?php
// GLPI 12 initializes this script through public/index.php.
defined('GLPI_ROOT') || die('Direct access forbidden');

use GlpiPlugin\Gitpull\Manifest;
use GlpiPlugin\Gitpull\RepositoryManager;
use GlpiPlugin\Gitpull\StateStore;
use GlpiPlugin\Gitpull\Web;

Web::guard();
$error = null;
$rows = [];
try {
    $repos = (new Manifest())->all(false);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? '';
        $action = $_POST['action'] ?? '';
        if (!is_string($id) || !isset($repos[$id])
            || !is_string($action) || !in_array($action, ['check', 'pull'], true)) {
            throw new InvalidArgumentException('Dépôt ou action invalide.');
        }
        // Release session so another browser tab can read the running status.
        session_write_close();
        (new RepositoryManager())->run($repos[$id], $action);
        Html::redirect(Web::base() . 'dashboard.php');
        return;
    }
    $store = new StateStore();
    $labels = [
        'ready' => 'À jour', 'update_available' => 'Nouvelle mise à jour disponible',
        'pulling' => 'En cours de pull', 'checking' => 'Vérification en cours',
        'error' => 'Erreur / Non disponible',
    ];
    foreach ($repos as $r) {
        try {
            Manifest::validate($r); // Display unsafe targets but never execute them.
        } catch (InvalidArgumentException $e) {
            \GlpiPlugin\Gitpull\Diagnostics::log('config_target_error', $r['id'] . ' : ' . $e->getMessage());
            $rows[] = [
                'repo' => $r, 'target' => \GlpiPlugin\Gitpull\Paths::target($r), 'state' => ['error' => $e->getMessage()],
                'running' => false, 'blocked' => true, 'label' => 'Configuration refusée',
                'next_check' => null, 'next_pull' => null,
            ];
            continue;
        }
        $s = $store->get($r);
        $locked = $store->lock($r);
        $running = $locked === null;
        if (is_resource($locked)) {
            flock($locked, LOCK_UN);
            fclose($locked);
        }
        $status = $s['status'] ?? 'unknown';
        if (!$running && in_array($status, ['pulling', 'checking'], true)) {
            $status = 'error';
            $s['error'] = 'Exécution interrompue : verrou libéré sans résultat final.';
        }
        $rows[] = [
            'repo' => $r, 'state' => $s, 'running' => $running, 'blocked' => false,
            'label' => $labels[$status] ?? 'Non initialisé',
            'next_check' => $r['auto_check']
                ? ($s['last_check_attempt'] ?? 0) + $r['check_interval'] * 60 : null,
            'next_pull' => $r['auto_pull'] || !file_exists(\GlpiPlugin\Gitpull\Paths::target($r))
                ? ($s['last_pull_attempt'] ?? 0) + $r['pull_interval'] * 60 : null,
        ];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    \GlpiPlugin\Gitpull\Diagnostics::log('dashboard_error', $error);
}
Web::render('dashboard.html.twig', ['rows' => $rows, 'error' => $error]);
