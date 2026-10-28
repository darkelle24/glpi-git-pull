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

    public static function validateTarget(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")
            || preg_match('~(^|/)\.{1,2}(/|$)|//|/$~', $path)) {
            throw new \InvalidArgumentException('Chemin Linux absolu normalisé requis.');
        }
        $roots = explode(':', getenv('GITPULL_ALLOWED_ROOTS') ?: GLPI_ROOT . '/plugins');
        $allowed = false;
        foreach ($roots as $root) {
            $real = realpath($root);
            // Only direct children: no nested/overlapping deployment targets.
            if ($real !== false && dirname($path) === $real && is_writable($real)) {
                $allowed = true;
            }
        }
        if (!$allowed) {
            throw new \InvalidArgumentException('La cible doit être un enfant direct d’une racine autorisée et accessible en écriture.');
        }
        $self = realpath(dirname(__DIR__));
        if ($self !== false && ($path === $self || str_starts_with($self, $path . '/'))) {
            throw new \InvalidArgumentException('gitpull ne peut pas se remplacer lui-même.');
        }
        $cursor = $path;
        while ($cursor !== '/') {
            if (is_link($cursor)) {
                throw new \InvalidArgumentException('Liens symboliques interdits dans le chemin cible.');
            }
            $cursor = dirname($cursor);
        }
    }
}
