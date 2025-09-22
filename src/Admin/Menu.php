<?php
namespace OSCT\Admin;
use OSCT\Admin\Pages\DashboardPage;
use OSCT\Admin\Pages\SettingsPage;
use OSCT\Admin\Pages\DryRunPage;
use OSCT\Admin\Pages\LogPage;
use OSCT\Admin\Pages\DebugPage;
use OSCT\Admin\Pages\JobsPage;

if (!defined('ABSPATH')) exit;

final class Menu
{
    public function __construct(
        private DashboardPage $dashboard,
        private SettingsPage $settings,
        private DryRunPage $dry,
        private LogPage $logs,
        private DebugPage $debug,
        private JobsPage $jobs // ← neu
    ) {}

    public function register(): void
    {
        add_menu_page('Übersetzungsmodul', 'Übersetzungsmodul', 'manage_options', 'osct-dashboard', [$this->dashboard, 'render'], 'dashicons-translation', 58);
        add_submenu_page('osct-dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'osct-dashboard', [$this->dashboard, 'render']);
        add_submenu_page('osct-dashboard', 'Einstellungen', 'Einstellungen', 'manage_options', 'osct-settings', [$this->settings, 'render']);
        add_submenu_page('osct-dashboard', 'Trockenlauf', 'Trockenlauf', 'manage_options', 'osct-dry-run', [$this->dry, 'render']);
        add_submenu_page('osct-dashboard', 'Logs', 'Logs', 'manage_options', 'osct-logs', [$this->logs, 'render']);
        add_submenu_page('osct-dashboard', 'Letzter Lauf (Debug)', 'Letzter Lauf (Debug)', 'manage_options', 'osct-debug', [$this->debug, 'render']);
        add_submenu_page('osct-dashboard', 'Jobs-Übersicht', 'Jobs-Übersicht', 'manage_options', 'osct-jobs', [$this->jobs, 'render']); // ← neu
    }
}
