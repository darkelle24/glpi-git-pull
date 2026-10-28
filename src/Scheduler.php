<?php
namespace GlpiPlugin\Gitpull;

final class Scheduler extends \CommonGLPI
{
    public static function getMenuName()
    {
        return 'gitpull';
    }

    public static function getMenuContent()
    {
        if (!\Session::haveRight('config', UPDATE)) {
            return [];
        }
        return ['title' => 'gitpull', 'page' => '/plugins/gitpull/front/dashboard.php',
            'icon' => 'ti ti-git-pull-request'];
    }

    public static function cronInfo($name)
    {
        return $name === 'Sync' ? ['description' => 'gitpull : déploiement et synchronisation'] : [];
    }

    public static function cronSync($task = null)
    {
        try {
            Process::assertUser();
            $count = self::tick();
            if ($task) {
                $task->addVolume($count);
                $task->log("gitpull : $count action(s) traitée(s).\n");
            }
            return 1;
        } catch (\Throwable $e) {
            Diagnostics::log('cron_error', $e->getMessage());
            if ($task) {
                $task->log($e->getMessage() . "\n");
            }
            return 0;
        }
    }

    public static function tick(bool $bootstrapOnly = false): int
    {
        $store = new StateStore();
        $manager = new RepositoryManager($store);
        $count = 0;
        foreach ((new Manifest())->all() as $repo) {
            $s = $store->get($repo);
            $action = null;
            if (!file_exists(\GlpiPlugin\Gitpull\Paths::target($repo))) {
                if ($bootstrapOnly || self::due($s['last_pull_attempt'] ?? null, $repo['pull_interval'])) {
                    $action = 'bootstrap';
                }
            } elseif (!$bootstrapOnly) {
                if ($repo['auto_pull'] && self::due($s['last_pull_attempt'] ?? null, $repo['pull_interval'])) {
                    $action = 'pull';
                } elseif ($repo['auto_check'] && self::due($s['last_check_attempt'] ?? null, $repo['check_interval'])) {
                    $action = 'check';
                }
            }
            if ($action !== null) {
                $manager->run($repo, $action);
                ++$count;
            }
        }
        return $count;
    }

    public static function due(?int $last, int $minutes): bool
    {
        return $last === null || $last + $minutes * 60 <= time();
    }
}
