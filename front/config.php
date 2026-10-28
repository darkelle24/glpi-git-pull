<?php
// Compatibility for old bookmarks; plugin settings now opens the dashboard.
defined('GLPI_ROOT') || die('Direct access forbidden');
if (isset($_GET['id']) || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    return require __DIR__ . '/repository.form.php';
}
\GlpiPlugin\Gitpull\Web::guard();
Html::redirect(\GlpiPlugin\Gitpull\Web::base() . 'dashboard.php');
