<?php
// GLPI 12 initializes this script through public/index.php.
defined('GLPI_ROOT') || die('Direct access forbidden');

use GlpiPlugin\Gitpull\Diagnostics;
use GlpiPlugin\Gitpull\Manifest;
use GlpiPlugin\Gitpull\Paths;
use GlpiPlugin\Gitpull\RepositoryManager;
use GlpiPlugin\Gitpull\StateStore;
use GlpiPlugin\Gitpull\Web;

Web::guard();
$async = ($_GET['gitpull_async'] ?? '') === '1';

// POST/Redirect/GET: GLPI's redirect exception must never enter a generic catch.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actionError = null;
    $notice = null;
    try {
        $repos = (new Manifest())->all(false);
        $id = $_POST['id'] ?? '';
        $action = $_POST['action'] ?? '';
        if (!is_string($id) || !isset($repos[$id])
            || !is_string($action) || !in_array($action, ['check', 'pull'], true)) {
            throw new InvalidArgumentException('Dépôt ou action invalide.');
        }
        // Keep the dashboard readable from another request while Git runs.
        session_write_close();
        if ((new RepositoryManager())->run($repos[$id], $action)) {
            $notice = ($action === 'check' ? 'Vérification terminée : ' : 'Pull terminé : ') . $id;
        } else {
            $actionError = 'Action non terminée pour ' . $id . ' : consultez son état et son journal (erreur ou opération déjà en cours).';
        }
    } catch (Throwable $e) {
        $actionError = $e->getMessage();
        Diagnostics::log('dashboard_action_error', $actionError);
    }

    // Reopen the session only to persist the feedback after the long operation.
    Session::start();
    unset($_SESSION['gitpull_notice'], $_SESSION['gitpull_action_error']);
    if ($actionError !== null) {
        $_SESSION['gitpull_action_error'] = $actionError;
    } elseif ($notice !== null) {
        $_SESSION['gitpull_notice'] = $notice;
    }
    if (!$async) {
        Html::redirect(Web::base() . 'dashboard.php', 303);
    }
}

$error = $_SESSION['gitpull_action_error'] ?? null;
unset($_SESSION['gitpull_action_error']);
$rows = [];
try {
    $repos = (new Manifest())->all(false);
    $store = new StateStore();
    $labels = [
        'ready' => 'À jour', 'update_available' => 'Nouvelle mise à jour disponible',
        'pulling' => 'En cours de pull', 'checking' => 'Vérification en cours',
        'error' => 'Erreur / Non disponible',
    ];
    foreach ($repos as $r) {
        $row = [
            'repo' => $r, 'target' => Paths::target($r), 'state' => [],
            'running' => false, 'blocked' => false, 'label' => 'Non initialisé',
            'next_check' => null, 'next_pull' => null,
        ];
        try {
            Manifest::validate($r);
            $s = $store->get($r);
            $lock = $store->lock($r);
            $row['running'] = $lock === null;
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $status = $s['status'] ?? 'unknown';
            if (!$row['running'] && in_array($status, ['pulling', 'checking'], true)) {
                $status = 'error';
                $s['error'] = 'Exécution interrompue : verrou libéré sans résultat final.';
            }
            $row['state'] = $s;
            $row['label'] = $row['running']
                ? ($s['activity'] ?? (in_array($status, ['checking', 'pulling'], true)
                    ? $labels[$status] : 'Opération en cours…'))
                : ($labels[$status] ?? 'Non initialisé');
            $row['next_check'] = $r['auto_check']
                ? ($s['last_check_attempt'] ?? 0) + $r['check_interval'] * 60 : null;
            $row['next_pull'] = $r['auto_pull'] || !file_exists($row['target'])
                ? ($s['last_pull_attempt'] ?? 0) + $r['pull_interval'] * 60 : null;
        } catch (Throwable $e) {
            // A single invalid target/cache must not hide the remaining repositories.
            $row['state'] = ['error' => $e->getMessage()];
            $row['blocked'] = true;
            $row['label'] = $e instanceof InvalidArgumentException
                ? 'Configuration refusée' : 'Erreur / Non disponible';
            Diagnostics::log('dashboard_repository_error', $r['id'] . ' : ' . $e->getMessage());
        }
        $rows[] = $row;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    Diagnostics::log('dashboard_error', $error);
}
if ($async) {
    $notice = $_SESSION['gitpull_notice'] ?? null;
    unset($_SESSION['gitpull_notice']);
    session_write_close();
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['rows' => $rows, 'error' => $error, 'notice' => $notice], JSON_THROW_ON_ERROR);
    return;
}
Web::render('dashboard.html.twig', ['rows' => $rows, 'error' => $error]);
