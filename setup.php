<?php
/**
 * gitpull — SPDX-License-Identifier: GPL-3.0-or-later
 */
use GlpiPlugin\Gitpull\Scheduler;

defined('GLPI_ROOT') || die('Direct access forbidden');
define('PLUGIN_GITPULL_VERSION', '0.2.5');

function plugin_init_gitpull(): void
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['gitpull'] = true;
    $PLUGIN_HOOKS['config_page']['gitpull'] = 'front/dashboard.php';
    $PLUGIN_HOOKS['menu_toadd']['gitpull'] = ['config' => Scheduler::class];
}

function plugin_version_gitpull(): array
{
    return [
        'name' => 'Plugin Git Gestion',
        'version' => PLUGIN_GITPULL_VERSION,
        'author' => 'gitpull contributors',
        'license' => 'GPLv3+',
        'requirements' => ['glpi' => ['min' => '12.0.0', 'max' => '12.99.99']],
    ];
}

function plugin_gitpull_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, '12.0.0', '>=')
        && version_compare(GLPI_VERSION, '13.0.0', '<')
        && PHP_OS_FAMILY === 'Linux'
        && function_exists('proc_open')
        && function_exists('posix_geteuid');
}

function plugin_gitpull_check_config($verbose = false): bool
{
    return true; // The manifest may legitimately be absent before provisioning.
}
