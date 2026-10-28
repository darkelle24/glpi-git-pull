<?php
// GLPI 12 initializes this script through public/index.php.
defined('GLPI_ROOT') || die('Direct access forbidden');

use GlpiPlugin\Gitpull\Manifest;
use GlpiPlugin\Gitpull\Web;

Web::guard();
$manifest = new Manifest();
$error = null;
$r = [
    'id' => '', 'url' => '', 'path' => '', 'post_pull' => null,
    'hard_pull' => false, 'auto_check' => true, 'auto_pull' => false,
    'check_interval' => 5, 'pull_interval' => 30,
];
$revision = '';
$resolvedTarget = null;
try {
    // The browser revision must correspond to the same manifest snapshot.
    $revision = $manifest->revision();
    $repos = $manifest->all(false);
    if ($revision !== $manifest->revision()) {
        throw new RuntimeException('Configuration modifiée pendant la lecture. Rechargez.');
    }
    $id = $_GET['id'] ?? '';
    if (!is_string($id)) {
        throw new InvalidArgumentException('Identifiant invalide.');
    }
    if ($id !== '') {
        if (!isset($repos[$id])) {
            throw new InvalidArgumentException('Dépôt absent du manifeste.');
        }
        $r = $repos[$id];
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $str = static function (string $key): string {
            $value = $_POST[$key] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException("Champ invalide : $key");
            }
            return trim($value);
        };
        $script = $str('script_path');
        $r = [
            'id' => $str('id'), 'url' => $str('url'), 'path' => $str('path'),
            'post_pull' => $script === '' ? null : ['type' => $str('script_type'), 'path' => $script],
            'hard_pull' => isset($_POST['hard_pull']),
            'auto_check' => isset($_POST['auto_check']), 'auto_pull' => isset($_POST['auto_pull']),
            'check_interval' => filter_var($str('check_interval'), FILTER_VALIDATE_INT),
            'pull_interval' => filter_var($str('pull_interval'), FILTER_VALIDATE_INT),
        ];
        if (($id !== '' && $r['id'] !== $id) || ($id === '' && isset($repos[$r['id']]))) {
            throw new InvalidArgumentException('Identifiant immuable ou déjà utilisé.');
        }
        $manifest->save($r, $str('revision'));
        \GlpiPlugin\Gitpull\Diagnostics::log('config_saved', 'Dépôt ' . $r['id'] . ' enregistré dans ' . \GlpiPlugin\Gitpull\Paths::manifest());
        $_SESSION['gitpull_notice'] = 'Configuration enregistrée dans ' . \GlpiPlugin\Gitpull\Paths::manifest();
        Html::redirect(Web::base() . 'dashboard.php');
        return;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    \GlpiPlugin\Gitpull\Diagnostics::log($_SERVER['REQUEST_METHOD'] === 'POST' ? 'config_save_error' : 'config_read_error', $error);
}
try {
    $resolvedTarget = \GlpiPlugin\Gitpull\Paths::target($r);
} catch (Throwable) {
    // An empty URL on the add form cannot yet provide the default directory.
}
Web::render('config.html.twig', ['repo' => $r, 'revision' => $revision, 'error' => $error, 'resolved_target' => $resolvedTarget, 'editing' => is_string($_GET['id'] ?? null) && $_GET['id'] !== '']);
