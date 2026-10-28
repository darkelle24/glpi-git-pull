<?php
namespace GlpiPlugin\Gitpull;

final class RepositoryManager
{
    public function __construct(
        private StateStore $store = new StateStore(),
        private Process $process = new Process()
    ) {
    }

    public function run(array $repo, string $action): bool
    {
        if (!in_array($action, ['check', 'pull', 'bootstrap'], true)) {
            throw new \InvalidArgumentException('Action inconnue.');
        }
        if ($action === 'bootstrap' && file_exists($repo['path'])) {
            return true;
        }
        $lock = $this->store->lock($repo);
        if ($lock === null) {
            Diagnostics::log('repository_busy', $repo['id'] . ' : opération déjà en cours.');
            return false;
        }
        $state = $this->store->get($repo);
        $log = '';
        try {
            Process::assertUser();
            $repo = Manifest::validate($repo);
            $now = time();
            $state['status'] = $action === 'check' ? 'checking' : 'pulling';
            $state['started_at'] = $now;
            $state['last_action'] = $action;
            if ($action === 'check') {
                $state['last_check_attempt'] = $now;
            } else {
                $state['last_pull_attempt'] = $now;
            }
            $this->store->put($repo, $state);
            $path = $repo['path'];
            if (!file_exists($path)) {
                $this->publicHost($repo['url']);
                // Atomic publish: a failed clone never leaves a partial target.
                $staging = dirname($path) . '/.gitpull-' . bin2hex(random_bytes(8));
                $log .= $this->process->git(['clone', '--no-recurse-submodules', '--', $repo['url'], $staging]) . "\n";
                if (file_exists($path) || !rename($staging, $path)) {
                    throw new \RuntimeException("Publication impossible. Clone temporaire : $staging");
                }
                $this->postPull($repo, $log);
                $state['last_pull_success'] = time();
                $state['last_pull_attempt'] = $now;
                $state['last_check'] = time();
                $state['last_check_attempt'] = $now;
                $state['update_available'] = false;
            } else {
                if (!is_dir($path . '/.git') || is_link($path . '/.git')) {
                    throw new \RuntimeException('La cible existe mais ce n’est pas un clone Git indépendant.');
                }
                $origin = $this->process->git(['remote', 'get-url', 'origin'], $path);
                if ($origin !== $repo['url']) {
                    throw new \RuntimeException('origin diffère du manifeste ; correction manuelle requise.');
                }
                $this->publicHost($repo['url']);
                $log .= $this->process->git(['fetch', '--prune', '--no-tags', 'origin'], $path) . "\n";
                $upstream = $this->process->git(['rev-parse', '--symbolic-full-name', '@{upstream}'], $path);
                if (!str_starts_with($upstream, 'refs/remotes/origin/')) {
                    throw new \RuntimeException('Branche suivie origin requise (HEAD détachée interdite).');
                }
                $remote = $this->process->git(['rev-parse', '@{upstream}'], $path);
                $state['last_check'] = time();
                $state['last_check_attempt'] = $now;
                $behind = (int) $this->process->git(['rev-list', '--count', 'HEAD..@{upstream}'], $path);
                $state['update_available'] = $behind > 0;
                if ($action === 'pull') {
                    if ($repo['hard_pull']) {
                        $log .= $this->process->git(['reset', '--hard', $remote], $path) . "\n";
                    } else {
                        $log .= $this->process->git(['pull', '--ff-only', '--no-rebase',
                            '--no-recurse-submodules'], $path) . "\n";
                    }
                    // Retry scripts even when an earlier script failed after a successful Git update.
                    $this->postPull($repo, $log);
                    $state['last_pull_success'] = time();
                    $state['update_available'] = false;
                }
            }
            $state['status'] = !empty($state['update_available']) ? 'update_available' : 'ready';
            $state['error'] = null;
            Diagnostics::log('repository_success', $repo['id'] . ' : ' . $action . ' terminé.');
            return true;
        } catch (\Throwable $e) {
            $state['status'] = 'error';
            $state['error'] = $e->getMessage();
            $log .= "\n" . $e->getMessage();
            Diagnostics::log('repository_error', $repo['id'] . ' : ' . $action . ' : ' . $e->getMessage());
            return false;
        } finally {
            // Also fixes stale "running" state after normal failures.
            if ($action === 'bootstrap' && ($state['status'] ?? '') === 'pulling'
                && empty($state['last_pull_success'])) {
                $state['status'] = 'ready';
            }
            $state['finished_at'] = time();
            $state['log'] = substr($log, -32768);
            $history = $state['history'] ?? [];
            $history[] = ['at' => time(), 'action' => $action, 'status' => $state['status'],
                'error' => $state['error'] ?? null];
            $state['history'] = array_slice($history, -20);
            try {
                $this->store->put($repo, $state);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function postPull(array $repo, string &$log): void
    {
        $s = $repo['post_pull'];
        if ($s === null) {
            return;
        }
        if (!is_file($s['path']) || !is_readable($s['path'])) {
            throw new \RuntimeException('Script post-pull absent ou illisible.');
        }
        $binary = $s['type'] === 'php' ? '/usr/bin/php' : '/bin/bash';
        $log .= $this->process->run([$binary, $s['path']], $repo['path'], 300) . "\n";
    }

    private function publicHost(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!$records) {
            throw new \RuntimeException('Nom DNS indisponible.');
        }
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip !== null && !filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Adresse réseau privée/réservée interdite.');
            }
        }
        // Enforce network egress policy in Docker as DNS can change after validation.
    }
}
