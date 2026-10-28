<?php
namespace GlpiPlugin\Gitpull;

final class Manifest
{
    public function __construct(private ?string $file = null)
    {
        $this->file ??= Paths::manifest();
    }

    public function all(bool $validateTargets = true): array
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
            try {
                $repo = self::validate($input, $validateTargets);
            } catch (\InvalidArgumentException $e) {
                $id = is_string($input['id'] ?? null) ? $input['id'] : '(sans identifiant)';
                throw new \InvalidArgumentException("Dépôt $id : " . $e->getMessage(), 0, $e);
            }
            if (isset($repos[$repo['id']])) {
                throw new \InvalidArgumentException('Identifiant dupliqué.');
            }
            $target = Paths::target($repo);
            foreach ($paths as $other) {
                if (Paths::overlaps($target, $other)) {
                    throw new \InvalidArgumentException('Destinations identiques ou imbriquées interdites.');
                }
            }
            $repos[$repo['id']] = $repo;
            $paths[] = $target;
        }
        return $repos;
    }

    public static function validate(array $r, bool $validateTargets = true): array
    {
        $r += ['path' => '']; // An absent/empty destination means plugins/<repository-name>.
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
        $r['path'] = Paths::normalizeTarget($r['path']);
        Paths::target($r); // Validate the inferred default even in read-only views.
        if ($validateTargets) {
            Paths::validateTarget(Paths::target($r));
        }
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
            || str_contains($r['post_pull']['path'], "\0"))) {
            throw new \InvalidArgumentException('Script : type bash/php et chemin relatif au dépôt requis.');
        }
        if ($r['post_pull'] !== null) {
            $r['post_pull']['path'] = Paths::normalizeScript($r['post_pull']['path'], $r);
        }
        return $r;
    }

    public function save(array $repo, string $revision): void
    {
        if (!is_dir(dirname($this->file)) || !is_writable(dirname($this->file))) {
            throw new \RuntimeException('Enregistrement impossible : dossier non accessible en écriture : ' . dirname($this->file));
        }
        if (file_exists($this->file) && !is_writable($this->file)) {
            throw new \RuntimeException('Enregistrement impossible : manifeste non accessible en écriture : ' . $this->file);
        }
        if (file_exists($this->file . '.lock') && !is_writable($this->file . '.lock')) {
            throw new \RuntimeException('Enregistrement impossible : verrou non accessible en écriture : ' . $this->file . '.lock');
        }
        // Stable sidecar lock: rename() must not change the locked inode.
        $lock = fopen($this->file . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('Verrouillage du manifeste impossible : ' . $this->file . '.lock');
        }
        try {
            if (!hash_equals($this->revision(), $revision)) {
                throw new \RuntimeException('Configuration modifiée entre-temps. Rechargez le formulaire.');
            }
            $repos = $this->all(false); // Allow correcting an existing target rejected at runtime.
            $repo = self::validate($repo);
            foreach ($repos as $id => $other) {
                if ($id !== $repo['id'] && Paths::overlaps(Paths::target($other), Paths::target($repo))) {
                    throw new \InvalidArgumentException('Destination déjà utilisée ou imbriquée dans un autre dépôt.');
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
