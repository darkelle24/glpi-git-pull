<?php
// Read-only checks, no process started and no GLPI instance required.
require dirname(__DIR__) . '/src/Process.php';
use GlpiPlugin\Gitpull\Process;
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$count;
};
$reject = static function (callable $action, string $type, string $message) use ($check): void {
    try {
        $action();
    } catch (Throwable $e) {
        $check($e instanceof $type && str_contains($e->getMessage(), $message), 'Diagnostic incorrect : ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Une commande invalide a été acceptée.');
};
$reject(fn() => Process::validateCommand([], null), InvalidArgumentException::class, 'vide');
$reject(fn() => Process::validateCommand(['command' => PHP_BINARY], null), InvalidArgumentException::class, 'structurée');
$reject(fn() => Process::validateCommand([PHP_BINARY, "bad\0argument"], null), InvalidArgumentException::class, 'Argument');
$missing = sys_get_temp_dir() . '/gitpull-not-installed-' . bin2hex(random_bytes(6));
$reject(fn() => Process::validateCommand([$missing], null), RuntimeException::class, $missing);
$reject(fn() => Process::validateCommand([PHP_BINARY], $missing), RuntimeException::class, 'Répertoire de travail');
Process::validateCommand([PHP_BINARY, '-v'], null);
$check(true, 'Exécutable PHP courant accepté');
$check(is_executable(Process::binary('php')), 'PHP CLI détecté, y compris /usr/local/bin/php');
$reject(fn() => Process::binary('unknown'), InvalidArgumentException::class, 'inconnu');
$runtime = Process::runtime();
$check($runtime['proc_open'] === function_exists('proc_open'), 'Fonction proc_open réellement disponible');
$check(isset($runtime['open_basedir'], $runtime['binaries']['php']), 'Diagnostic exploitable');
if (!function_exists('proc_open')) {
    $check(in_array('proc_open est indisponible ou désactivée dans PHP.', $runtime['errors'], true), 'proc_open désactivée clairement signalée');
}
echo "$count vérifications du lanceur OK\n";
