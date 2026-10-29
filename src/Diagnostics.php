<?php
namespace GlpiPlugin\Gitpull;

/** Runtime diagnostics only. Never writes the manifest. */
final class Diagnostics
{
    public static function log(string $event, string $message): void
    {
        $lock = null;
        try {
            $path = Paths::cache() . '/diagnostics.json';
            $lock = fopen($path . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Journal gitpull non accessible.');
            }
            try {
                $entries = AtomicJson::read($path, []);
            } catch (\Throwable) {
                $entries = [];
            }
            $entries[] = ['at' => time(), 'event' => $event, 'message' => substr($message, 0, 4096)];
            AtomicJson::write($path, array_slice($entries, -50));
        } catch (\Throwable $e) {
            // Diagnostics must never turn a successful save into a failure.
            error_log('[gitpull] ' . $event . ': ' . $message . ' — journal: ' . $e->getMessage());
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public static function report(): array
    {
        $file = Paths::manifest();
        clearstatcache(true, $file);
        $user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $r = [
            'path' => $file, 'resolved' => realpath($file) ?: null,
            'exists' => file_exists($file), 'readable' => is_readable($file),
            'writable' => is_writable($file), 'directory_writable' => is_writable(dirname($file)),
            'lock_path' => $file . '.lock',
            'lock_writable' => file_exists($file . '.lock') ? is_writable($file . '.lock') : is_writable(dirname($file)),
            'user' => $user['name'] ?? 'Indisponible',
            'modified' => is_file($file) ? filemtime($file) : null,
            'count' => null, 'validation_error' => null, 'events' => [],
            'log_path' => GLPI_VAR_DIR . '/_plugins/gitpull/diagnostics.json',
            'log_error' => null, 'runtime' => Process::runtime(),
        ];
        if ($r['exists']) {
            try {
                $m = new Manifest();
                $r['count'] = count($m->all(false));
                $m->all(); // Surface target safety/permission errors, even before any Git operation.
            } catch (\Throwable $e) {
                $r['validation_error'] = $e->getMessage();
            }
        }
        try {
            $r['events'] = array_reverse(AtomicJson::read($r['log_path'], []));
        } catch (\Throwable $e) {
            $r['log_error'] = $e->getMessage();
        }
        return $r;
    }
}
