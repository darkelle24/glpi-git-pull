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
        try {
            $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \JsonException("JSON invalide dans $file : " . $e->getMessage()
                . ' Vérifiez notamment les caractères après la dernière accolade (par exemple %).', 0, $e);
        }
        if (!is_array($value)) {
            throw new \RuntimeException("Objet JSON attendu : $file");
        }
        return $value;
    }

    public static function write(string $file, array $value): void
    {
        if (!is_dir(dirname($file)) || !is_writable(dirname($file))) {
            throw new \RuntimeException('Dossier non accessible en écriture : ' . dirname($file));
        }
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
