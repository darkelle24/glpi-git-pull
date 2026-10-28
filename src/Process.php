<?php
namespace GlpiPlugin\Gitpull;

final class Process
{
    public static function assertUser(): void
    {
        $user = function_exists('posix_getpwnam') ? posix_getpwnam('www-data') : false;
        if (!$user || posix_geteuid() !== $user['uid']) {
            throw new \RuntimeException('Exécution obligatoire sous www-data (aucune élévation automatique).');
        }
    }

    public function run(array $argv, ?string $cwd = null, int $timeout = 120): string
    {
        self::assertUser();
        // No shell, no inherited Git/SSH/token environment; no interactive prompt.
        $env = [
            'PATH' => '/usr/bin:/bin', 'HOME' => Paths::cache(), 'LANG' => 'C.UTF-8',
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_ALLOW_PROTOCOL' => 'https',
            'GIT_CONFIG_COUNT' => '0',
        ];
        $proc = proc_open($argv, [
            0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $cwd, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Lancement du processus impossible.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = microtime(true);
        $output = '';
        $exit = -1;
        try {
            do {
                foreach ([1, 2] as $n) {
                    $chunk = stream_get_contents($pipes[$n]);
                    $output = substr($output . ($chunk === false ? '' : $chunk), -32768);
                }
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                if (microtime(true) - $start > $timeout) {
                    proc_terminate($proc, 9);
                    throw new \RuntimeException("Délai dépassé ($timeout s).\n$output");
                }
                usleep(50000);
            } while (true);
            foreach ([1, 2] as $n) {
                $output = substr($output . stream_get_contents($pipes[$n]), -32768);
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($proc);
        }
        if ($exit === -1) {
            $exit = $closed;
        }
        if ($exit !== 0) {
            throw new \RuntimeException("Commande en échec ($exit).\n$output");
        }
        return trim($output);
    }

    public function git(array $args, ?string $cwd = null): string
    {
        return $this->run(array_merge([
            '/usr/bin/git', '-c', 'core.hooksPath=/dev/null',
            '-c', 'credential.helper=', '-c', 'http.followRedirects=false',
            '-c', 'protocol.file.allow=never', '-c', 'protocol.ext.allow=never',
            '-c', 'submodule.recurse=false',
        ], $args), $cwd);
    }
}
