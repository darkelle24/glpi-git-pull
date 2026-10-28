<?php
namespace GlpiPlugin\Gitpull;

final class Manifest
{
    public function __construct(private ?string $file = null)
    {
        $this->file ??= Paths::manifest();
    }

    public function all(): array
    {
        $data = AtomicJson::read($this->file, ['version' => 1, 'repositories' => []]);
        if (($data['version'] ?? null) !== 1 || !isset($data['repositories'])
            || !is_array($data['repositories']) || !array_is_list($data['repositories'])
            || array_diff(array_keys($data), ['version', 'repositories'])) {
            throw new \InvalidArgumentException('Structure du manifeste invalide.');
        }
        $repos = [];
        $paths = [];
        foreach ($data['repositories'] as $input) {
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Dépôt invalide.');
            }
            $repo = self::validate($input);
            if (isset($repos[$repo['id']]) || isset($paths[$repo['path']])) {
                throw new \InvalidArgumentException('Identifiant ou chemin dupliqué.');
            }
            $repos[$repo['id']] = $repo;
            $paths[$repo['path']] = true;
        }
        return $repos;
    }

    public static function validate(array $r): array
    {
        $keys = ['id', 'url', 'path', 'post_pull', 'hard_pull', 'auto_check',
            'auto_pull', 'check_interval', 'pull_interval'];
        if (array_diff(array_keys($r), $keys) || array_diff($keys, array_keys($r))) {
            throw new \InvalidArgumentException('Champs manquants ou inconnus dans le dépôt.');
        }
        if (!is_string($r['id']) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $r['id'])) {
            throw new \InvalidArgumentException('Identifiant invalide.');
        }
        if (!is_string($r['url'])) {
            throw new \InvalidArgumentException('URL invalide.');
        }
        $u = parse_url($r['url']);
        if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])
            || isset($u['user'], $u['pass']) || isset($u['user']) || isset($u['pass'])
            || isset($u['query']) || isset($u['fragment']) || isset($u['port'])
            || preg_match('/[\x00-\x20\x7f]/', $r['url'])) {
            throw new \InvalidArgumentException('URL HTTPS publique sans identifiants, port, paramètres ni fragment requise.');
        }
        if (!is_string($r['path'])) {
            throw new \InvalidArgumentException('Chemin invalide.');
        }
        Paths::validateTarget($r['path']);
        foreach (['hard_pull', 'auto_check', 'auto_pull'] as $key) {
            if (!is_bool($r[$key])) {
                throw new \InvalidArgumentException("$key doit être un booléen.");
            }
        }
        foreach (['check_interval', 'pull_interval'] as $key) {
            if (!is_int($r[$key]) || $r[$key] < 1 || $r[$key] > 525600) {
                throw new \InvalidArgumentException("$key : entier de 1 à 525600 minutes requis.");
            }
        }
        if ($r['post_pull'] !== null && (!is_array($r['post_pull'])
            || array_diff(array_keys($r['post_pull']), ['type', 'path'])
            || !in_array($r['post_pull']['type'] ?? '', ['bash', 'php'], true)
            || !is_string($r['post_pull']['path'] ?? null)
            || !str_starts_with($r['post_pull']['path'], '/')
            || str_contains($r['post_pull']['path'], "\0"))) {
            throw new \InvalidArgumentException('Script : type bash/php et chemin absolu requis.');
        }
        return $r;
    }

    public function save(array $repo, string $revision): void
    {
        // Stable sidecar lock: rename() must not change the locked inode.
        $lock = fopen($this->file . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Verrouillage du manifeste impossible.');
        }
        try {
            if (!hash_equals($this->revision(), $revision)) {
                throw new \RuntimeException('Configuration modifiée entre-temps. Rechargez le formulaire.');
            }
            $repos = $this->all();
            $repo = self::validate($repo);
            foreach ($repos as $id => $other) {
                if ($id !== $repo['id'] && $other['path'] === $repo['path']) {
                    throw new \InvalidArgumentException('Chemin déjà utilisé.');
                }
            }
            $repos[$repo['id']] = $repo;
            AtomicJson::write($this->file, ['version' => 1, 'repositories' => array_values($repos)]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function revision(): string
    {
        $content = is_file($this->file) ? file_get_contents($this->file) : '';
        if ($content === false) {
            throw new \RuntimeException('Lecture du manifeste impossible.');
        }
        return hash('sha256', $content);
    }
}
