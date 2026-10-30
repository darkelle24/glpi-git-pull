<?php
namespace GlpiPlugin\Gitpull;

final class Paths
{
    public static function manifest(): string
    {
        return GLPI_CONFIG_DIR . '/gitpull_repos.json';
    }

    public static function cache(): string
    {
        $dir = GLPI_VAR_DIR . '/_plugins/gitpull';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Impossible de créer le cache : $dir");
        }
        return $dir;
    }

    public static function root(): string
    {
        $root = realpath(GLPI_ROOT);
        if ($root === false) {
            throw new \RuntimeException('Racine GLPI introuvable.');
        }
        return rtrim(str_replace('\\', '/', $root), '/');
    }

    public static function selfTarget(): string
    {
        $directory = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
        $prefix = self::root() . '/';
        // The fallback also permits standalone tests outside an installed GLPI tree.
        return str_starts_with($directory, $prefix)
            ? substr($directory, strlen($prefix)) : 'plugins/gitpull';
    }

    private static function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1;
    }

    public static function assertRelative(string $path): void
    {
        if ($path === '' || self::absolute($path) || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f:%?#]/', $path)) {
            throw new \InvalidArgumentException('Chemin relatif requis, sans préfixe système, caractère spécial ni retour arrière.');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strtolower($part) === '.git') {
                throw new \InvalidArgumentException('Les segments vides, ".", ".." et ".git" sont interdits.');
            }
        }
    }

    /** Accept legacy absolute targets only when strictly inside GLPI. */
    public static function normalizeTarget(string $path): string
    {
        if ($path === '') {
            return '';
        }
        if (self::absolute($path)) {
            $roots = [self::root(), rtrim(str_replace('\\', '/', GLPI_ROOT), '/')];
            $relative = null;
            foreach ($roots as $root) {
                if (str_starts_with($path, $root . '/')) {
                    $relative = substr($path, strlen($root) + 1);
                    break;
                }
            }
            if ($relative === null) {
                throw new \InvalidArgumentException('La destination doit rester à l’intérieur de GLPI.');
            }
            $path = $relative;
        }
        self::assertRelative($path);
        return $path;
    }

    public static function defaultTarget(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $name = is_string($path) ? basename(rtrim($path, '/')) : '';
        $name = preg_replace('/\.git$/i', '', $name);
        if (!$name || !preg_match('/^[a-zA-Z0-9._-]+$/D', $name)) {
            throw new \InvalidArgumentException('Impossible de déduire le nom du dépôt : indiquez une destination relative.');
        }
        $name = preg_replace('/[^a-z0-9]/', '', strtolower($name));
        if ($name === '') {
            throw new \InvalidArgumentException('Le nom du dépôt ne contient aucune lettre ni chiffre : indiquez une destination personnalisée.');
        }
        return 'plugins/' . $name;
    }

    public static function validatePluginDirectory(string $relative): void
    {
        $parts = explode('/', $relative);
        if ($parts[0] === 'plugins' && isset($parts[1])
            && !preg_match('/^[a-z0-9]+$/D', $parts[1])) {
            throw new \InvalidArgumentException('Dans plugins/, le nom du dossier doit contenir uniquement des lettres minuscules (a-z) et des chiffres (0-9), tout attaché, sans espace, accent ni signe. Exemples : plugins/monplugin et plugins/inventaire2.');
        }
    }

    public static function target(array $repo): string
    {
        $relative = self::normalizeTarget($repo['path'] ?? '');
        if ($relative === '') {
            $relative = self::defaultTarget($repo['url'] ?? '');
        }
        return self::root() . '/' . $relative;
    }

    private static function noLinks(string $absolute): void
    {
        $root = self::root();
        if (!str_starts_with($absolute, $root . '/')) {
            throw new \InvalidArgumentException('Chemin hors de GLPI interdit.');
        }
        $cursor = $root;
        foreach (explode('/', substr($absolute, strlen($root) + 1)) as $part) {
            $cursor .= '/' . $part;
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new \InvalidArgumentException('Lien symbolique interdit : ' . $cursor);
            }
        }
    }

    public static function overlaps(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a, $b . '/') || str_starts_with($b, $a . '/');
    }

    /** Validate filesystem permissions separately from the manifest schema. */
    public static function validateTarget(string $path): void
    {
        $relative = self::normalizeTarget($path);
        if ($relative === '') {
            throw new \InvalidArgumentException('La racine GLPI ne peut pas être une destination.');
        }
        self::validatePluginDirectory($relative);
        $absolute = self::root() . '/' . $relative;
        self::noLinks($absolute);
        $self = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
        if ($absolute !== $self && self::overlaps($absolute, $self)) {
            throw new \InvalidArgumentException('Seul le dossier exact de gitpull peut être géré ; ses parents et sous-dossiers sont interdits.');
        }
        $existing = $absolute;
        while (!file_exists($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                throw new \InvalidArgumentException('Aucun parent de destination accessible.');
            }
            $existing = $parent;
        }
        if (!is_dir($existing) || !is_writable($existing)) {
            throw new \InvalidArgumentException('Destination ou parent non accessible en écriture à www-data : ' . $existing);
        }
    }

    public static function prepareParent(array $repo): void
    {
        $target = self::target($repo);
        self::validateTarget($target);
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
            throw new \RuntimeException('Impossible de créer le parent : ' . $parent);
        }
        self::noLinks($target);
    }

    /** Script configuration is relative to the checkout; execution uses an absolute path. */
    public static function normalizeScript(string $path, array $repo): string
    {
        if (self::absolute($path)) {
            $prefixes = [self::target($repo)];
            $rootAlias = rtrim(str_replace('\\', '/', GLPI_ROOT), '/');
            $relativeTarget = self::normalizeTarget($repo['path'] ?? '');
            $prefixes[] = $rootAlias . '/' . ($relativeTarget !== '' ? $relativeTarget : self::defaultTarget($repo['url']));
            $relative = null;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($path, $prefix . '/')) {
                    $relative = substr($path, strlen($prefix) + 1);
                    break;
                }
            }
            if ($relative === null) {
                throw new \InvalidArgumentException('Le script doit se trouver dans le dépôt cloné.');
            }
            $path = $relative;
        }
        self::assertRelative($path);
        return $path;
    }

    public static function script(array $repo): string
    {
        $target = self::target($repo);
        $absolute = $target . '/' . self::normalizeScript($repo['post_pull']['path'], $repo);
        self::noLinks($absolute);
        $real = realpath($absolute);
        if ($real === false || !str_starts_with(str_replace('\\', '/', $real), $target . '/')
            || !is_file($real) || !is_readable($real)) {
            throw new \RuntimeException('Script absent, illisible ou extérieur au dépôt : ' . $absolute);
        }
        return $real;
    }
}
