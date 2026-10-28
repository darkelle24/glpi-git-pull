<?php
namespace GlpiPlugin\Gitpull;

final class StateStore
{
    private function file(array $r): string
    {
        // Changes in configuration invalidate the old runtime state.
        return Paths::cache() . '/' . hash('sha256', json_encode($r, JSON_THROW_ON_ERROR)) . '.json';
    }

    public function get(array $r): array
    {
        try {
            return AtomicJson::read($this->file($r));
        } catch (\JsonException) {
            return []; // Disposable cache; the manifest is never treated this way.
        }
    }

    public function put(array $r, array $state): void
    {
        AtomicJson::write($this->file($r), $state);
    }

    public function lock(array $r)
    {
        // Lock on the target, not the configurable ID.
        $file = Paths::cache() . '/' . hash('sha256', $r['path']) . '.lock';
        $handle = fopen($file, 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return null;
        }
        return $handle;
    }
}
