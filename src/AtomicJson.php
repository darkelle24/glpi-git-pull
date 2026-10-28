<?php
namespace GlpiPlugin\Gitpull;

final class AtomicJson
{
    public static function read(string $file, array $default = []): array
    {
        if (!file_exists($file)) {
            return $default;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException("Lecture impossible : $file");
        }
        $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \RuntimeException("Objet JSON attendu : $file");
        }
        return $value;
    }

    public static function write(string $file, array $value): void
    {
        $temp = tempnam(dirname($file), '.gitpull-');
        if ($temp === false) {
            throw new \RuntimeException('Création du fichier temporaire impossible.');
        }
        try {
            chmod($temp, 0600);
            $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($temp, $json) !== strlen($json) || !rename($temp, $file)) {
                throw new \RuntimeException("Écriture atomique impossible : $file");
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
