<?php
namespace GlpiPlugin\Gitpull;

final class Process
{
    public static function assertUser(): void
    {
        $user = function_exists('posix_getpwnam') ? posix_getpwnam('www-data') : false;
        if (!$user || !function_exists('posix_geteuid') || posix_geteuid() !== $user['uid']) {
            throw new \RuntimeException('Exécution obligatoire sous www-data (aucune élévation automatique).');
        }
    }


    private const BINARIES = [
        'git' => ['/usr/bin/git', '/usr/local/bin/git', '/bin/git'],
        'php' => ['/usr/local/bin/php', '/usr/bin/php', '/bin/php'],
        'bash' => ['/bin/bash', '/usr/bin/bash'],
    ];

    private static function executable(string $path): bool
    {
        try {
            return @is_file($path) && @is_executable($path);
        } catch (\Throwable) {
            // open_basedir or another PHP restriction may prevent inspection.
            return false;
        }
    }

    public static function binary(string $name): string
    {
        $candidates = self::BINARIES[$name] ?? null;
        if ($candidates === null) {
            throw new \InvalidArgumentException('Exécutable inconnu.');
        }
        if ($name === 'php' && PHP_SAPI === 'cli') {
            $candidates[] = PHP_BINARY;
        }
        foreach ($candidates as $path) {
            if (self::executable($path)) {
                return $path;
            }
        }
        $hint = $name === 'git'
            ? ' Installez Git dans le conteneur du service glpi ; sa présence dans alpine/git ne suffit pas.'
            : ' Installez cet interpréteur dans le conteneur GLPI.';
        throw new \RuntimeException("Exécutable $name absent, non exécutable ou inaccessible à PHP. Chemins testés : "
            . implode(', ', $candidates) . '.' . $hint
            . (ini_get('open_basedir') ? ' open_basedir=' . ini_get('open_basedir') : ''));
    }

    public static function runtime(): array
    {
        $info = [
            'sapi' => PHP_SAPI, 'php_version' => PHP_VERSION,
            'proc_open' => function_exists('proc_open'),
            'open_basedir' => ini_get('open_basedir') ?: '(aucune restriction)',
            'binaries' => [], 'errors' => [],
        ];
        if (!$info['proc_open']) {
            $info['errors'][] = 'proc_open est indisponible ou désactivée dans PHP.';
        }
        foreach (['git', 'php', 'bash'] as $name) {
            try {
                $info['binaries'][$name] = self::binary($name);
            } catch (\RuntimeException $e) {
                $info['binaries'][$name] = null;
                // PHP CLI and Bash are required only when a matching script is configured.
                if ($name === 'git') {
                    $info['errors'][] = $e->getMessage();
                }
            }
        }
        return $info;
    }

    public static function validateCommand(array $argv, ?string $cwd): void
    {
        if (!array_is_list($argv) || $argv === []) {
            throw new \InvalidArgumentException('Commande vide ou mal structurée.');
        }
        foreach ($argv as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('Argument de commande invalide.');
            }
        }
        if (!self::executable($argv[0])) {
            throw new \RuntimeException('Exécutable absent, non exécutable ou inaccessible à PHP : '
                . $argv[0] . '. open_basedir=' . (ini_get('open_basedir') ?: '(aucune restriction)'));
        }
        if ($cwd !== null && (!is_dir($cwd) || !is_readable($cwd) || !is_executable($cwd))) {
            throw new \RuntimeException('Répertoire de travail absent ou non accessible : ' . $cwd);
        }
    }

    public function run(array $argv, ?string $cwd = null, int $timeout = 120): string
    {
        self::assertUser();
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException("Fonction PHP $function indisponible ou désactivée.");
            }
        }
        self::validateCommand($argv, $cwd);
        // No shell, no inherited Git/SSH/token environment; no interactive prompt.
        $env = [
            'PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => Paths::cache(), 'LANG' => 'C.UTF-8',
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_ALLOW_PROTOCOL' => 'https',
            'GIT_CONFIG_COUNT' => '0',
        ];
        $pipes = [];
        $warning = '';
        $proc = false;
        // Capture the actual OS/PHP reason, without losing GLPI's normal error handler.
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = substr($warning . ' ' . $message, -4096);
            return true;
        }, E_WARNING);
        try {
            // A closed stdin pipe avoids opening /dev/null through PHP's open_basedir.
            $proc = proc_open($argv, [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes, $cwd, $env, ['bypass_shell' => true]);
        } catch (\Throwable $e) {
            $warning .= ' ' . $e->getMessage();
        } finally {
            restore_error_handler();
        }
        if (!is_resource($proc)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            throw new \RuntimeException('Lancement impossible : ' . $argv[0]
                . ' ; dossier=' . ($cwd ?? '(hérité)')
                . ' ; cause=' . (trim($warning) ?: 'refus système sans détail')
                . ' ; open_basedir=' . (ini_get('open_basedir') ?: '(aucune restriction)'));
        }
        fclose($pipes[0]); // Child receives EOF immediately, no interactive input.
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
            throw new \RuntimeException("Commande " . $argv[0] . " en échec ($exit).\n$output");
        }
        return trim($output);
    }

    public function git(array $args, ?string $cwd = null): string
    {
        return $this->run(array_merge([
            self::binary('git'), '-c', 'core.hooksPath=/dev/null',
            '-c', 'credential.helper=', '-c', 'http.followRedirects=false',
            '-c', 'protocol.file.allow=never', '-c', 'protocol.ext.allow=never',
            '-c', 'submodule.recurse=false',
        ], $args), $cwd);
    }
}
