<?php
use GlpiPlugin\Gitpull\Scheduler;

defined('GLPI_ROOT') || die('Direct access forbidden');

function plugin_gitpull_install(): bool
{
    // No Git execution during installation, no overwrite of an existing manifest.
    $task = new CronTask();
    if ($task->getFromDBbyName(Scheduler::class, 'Sync')) {
        return true;
    }
    return CronTask::register(Scheduler::class, 'Sync', 60, [
        'mode' => CronTask::MODE_EXTERNAL,
        'allowmode' => CronTask::MODE_EXTERNAL,
        'state' => CronTask::STATE_WAITING,
        'comment' => 'gitpull : initialisation, vérification et pull des dépôts',
    ]);
}

function plugin_gitpull_uninstall(): bool
{
    // GLPI unregisters cron tasks. Preserve manifest, repositories and scripts.
    return true;
}
