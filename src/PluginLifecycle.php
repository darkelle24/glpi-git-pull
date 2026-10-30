<?php
namespace GlpiPlugin\Gitpull;

/** Called in a fresh GLPI process, after repository files and optional scripts are updated. */
final class PluginLifecycle
{
    public static function directory(array $repo): ?string
    {
        $relative = Paths::normalizeTarget($repo['path'] ?? '');
        if ($relative === '') {
            $relative = Paths::defaultTarget($repo['url'] ?? '');
        }
        if (!preg_match('~^plugins/([a-z0-9]+)$~D', $relative, $matches)) {
            return null;
        }
        $target = Paths::target($repo);
        Paths::validateTarget($target);
        return is_file($target . '/setup.php') && is_file($target . '/hook.php')
            ? $matches[1] : null;
    }

    public function installOrUpdate(string $directory): string
    {
        $plugin = $this->load($directory);
        $state = (int) $plugin->fields['state'];
        if (in_array($state, [\Plugin::ACTIVATED, \Plugin::NOTACTIVATED, \Plugin::TOBECONFIGURED], true)) {
            return 'Plugin GLPI ' . $directory . ' déjà installé à cette version.';
        }
        if (!in_array($state, [\Plugin::ANEW, \Plugin::NOTINSTALLED, \Plugin::NOTUPDATED], true)) {
            throw new \RuntimeException('Installation GLPI impossible pour ' . $directory . ' (état ' . $state . ').');
        }
        \Plugin::load($directory, true);
        $plugin->install($plugin->fields['id']);
        if (!$plugin->getFromDBByCrit(['directory' => $directory])
            || !in_array((int) $plugin->fields['state'],
                [\Plugin::ACTIVATED, \Plugin::NOTACTIVATED, \Plugin::TOBECONFIGURED], true)) {
            throw new \RuntimeException('Installation / mise à jour GLPI échouée pour ' . $directory
                . ' : vérifiez les prérequis et le journal GLPI.');
        }
        return 'Plugin GLPI ' . $directory . ' installé / mis à jour.';
    }

    public function activateIfNeeded(string $directory): string
    {
        $plugin = $this->load($directory);
        $state = (int) $plugin->fields['state'];
        if ($state === \Plugin::ACTIVATED) {
            return 'Plugin GLPI ' . $directory . ' déjà actif.';
        }
        if ($state === \Plugin::TOBECONFIGURED) {
            throw new \RuntimeException('Le plugin ' . $directory
                . ' nécessite une configuration manuelle dans GLPI avant son activation.');
        }
        if ($state !== \Plugin::NOTACTIVATED || !$plugin->activate($plugin->fields['id'])
            || !$plugin->getFromDBByCrit(['directory' => $directory])
            || (int) $plugin->fields['state'] !== \Plugin::ACTIVATED) {
            throw new \RuntimeException('Activation GLPI échouée pour ' . $directory
                . ' : vérifiez sa configuration, ses prérequis et le journal GLPI.');
        }
        return 'Plugin GLPI ' . $directory . ' activé.';
    }

    private function load(string $directory): \Plugin
    {
        if (!preg_match('/^[a-z0-9]+$/D', $directory)
            || self::directory(['path' => 'plugins/' . $directory]) !== $directory) {
            throw new \InvalidArgumentException('Dossier de plugin GLPI invalide.');
        }
        $plugin = new \Plugin();
        if (!$plugin->getInformationsFromDirectory($directory)) {
            throw new \RuntimeException('Le dépôt ' . $directory . ' ne déclare pas un plugin GLPI valide.');
        }
        $plugin->checkPluginState($directory);
        if (!$plugin->getFromDBByCrit(['directory' => $directory])) {
            throw new \RuntimeException('Plugin GLPI non détecté : ' . $directory);
        }
        return $plugin;
    }
}
