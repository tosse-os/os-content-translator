<?php

namespace OSCT\Core;

use OSCT\Admin\Menu;
use OSCT\Admin\Pages\DashboardPage;
use OSCT\Admin\Pages\SettingsPage;
use OSCT\Admin\Pages\LogPage;
use OSCT\Admin\Pages\DebugPage;
use OSCT\Domain\Repos\OptionRepo;
use OSCT\Domain\Repos\LanguageRepo;
use OSCT\Domain\Repos\ContentRepo;
use OSCT\Domain\Repos\LogRepo;
use OSCT\Admin\Pages\JobsPage;
use OSCT\Domain\Repos\JobsRepo;
use OSCT\Translation\TranslationService;
use OSCT\Translation\Providers\GoogleProvider;
use OSCT\Translation\Providers\DeepLProvider;
use OSCT\Features\Blocks\BlockSyncService;
use OSCT\Features\Menus\MenuSyncService;

if (!defined('ABSPATH')) exit;

final class Hooks
{
    private OptionRepo $options;
    private LanguageRepo $langs;
    private ContentRepo $content;
    private LogRepo $logs;
    private TranslationService $translator;

    public function __construct()
    {
        $this->options     = new OptionRepo();
        $this->langs       = new LanguageRepo();
        $this->content     = new ContentRepo();
        $this->logs        = new LogRepo();
        $provider          = new GoogleProvider($this->options);
        $this->translator  = new TranslationService($this->options, $this->langs, $this->content, $provider);
    }

    public function register(): void
    {
        add_action('init', [$this, 'polylangRegisterWpBlock'], 20);
        add_action('admin_menu', [$this, 'adminMenu']);
        add_action('admin_post_osct_save_settings', [$this, 'handleSaveSettings']);
        add_action('admin_post_osct_do_translate',  [$this, 'handleTranslate']);
        add_action('admin_post_osct_delete_job_translation', [$this, 'handleDeleteJobTranslation']);

        (new BlockSyncService())->register();

        add_action('admin_init', function () {
            $prov = $this->options->get('provider_default', 'google');
            $this->translator->setProvider(
                $prov === 'deepl' ? new DeepLProvider($this->options) : new GoogleProvider($this->options)
            );
        });

        // Fix: nav-menus Screen braucht $GLOBALS['post']
        add_action('current_screen', function ($screen) {
            if (!$screen) return;
            if ($screen->id === 'nav-menus') {
                if (!isset($GLOBALS['post']) || !is_object($GLOBALS['post'])) {
                    $GLOBALS['post'] = (object)['ID' => 0, 'post_status' => 'auto-draft', 'post_type' => 'page'];
                }
            }
        }, 1);

        add_action('load-nav-menus.php', function () {
            if (!isset($GLOBALS['post']) || !is_object($GLOBALS['post'])) {
                $GLOBALS['post'] = (object)['ID' => 0, 'post_status' => 'auto-draft', 'post_type' => 'page'];
            }
        }, 1);

        // Cron Hook: run_id, force, what[]
        add_action('osct_run_translation', [$this, 'cronTranslate'], 10, 3);

        (new \OSCT\Features\Content\LinkRelinker())->register();
    }

    public function polylangRegisterWpBlock(): void
    {
        if (!function_exists('pll_get_post_types') || !function_exists('pll_set_post_types')) return;
        $types = pll_get_post_types();
        $types['wp_block'] = 'wp_block';
        pll_set_post_types($types);
    }

    public function adminMenu(): void
    {
        (new Menu(
            new DashboardPage($this->options, $this->langs, $this->content, $this->translator, new JobsRepo()),
            new SettingsPage($this->options, $this->langs, $this->content),
            new LogPage($this->logs),
            new DebugPage(),
            new JobsPage($this->options, $this->langs, new JobsRepo()) // ← wichtig
        ))->register();
    }


    public function handleSaveSettings(): void
    {
        check_admin_referer('osct_settings_save', 'osct_nonce');
        if (!current_user_can('manage_options')) wp_die();
        (new SettingsPage($this->options, $this->langs, $this->content))->save($_POST);
    }

    public function handleTranslate(): void
    {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer('osct_do_translate');

        $force = !empty($_POST['osct_force']);
        $runId = wp_generate_uuid4();

        $menuRes = (new MenuSyncService($this->options, $this->langs))->bootstrap();
        set_transient('osct_menu_sync', $menuRes, 300);

        $what = [
            'menu_pages'  => !empty($_POST['osct_do_menu_pages']),
            'extra_pages' => !empty($_POST['osct_do_extra_pages']),
            'blocks'      => !empty($_POST['osct_do_blocks']),
            'jobs'        => !empty($_POST['osct_do_jobs']),
            'test'        => !empty($_POST['osct_test']),
        ];

        if (!empty($what['test'])) {
            $this->cronTranslate($runId, $force, $what);
            wp_redirect(add_query_arg(['page' => 'osct-dashboard'], admin_url('admin.php')));
            exit;
        }

        wp_schedule_single_event(time() + 1, 'osct_run_translation', [$runId, $force, $what]);

        set_transient('osct_translate_result', [
            'created' => 0,
            'skipped' => 0,
            'total'   => 0,
            'words'   => 0,
            'chars'   => 0,
            'queued'  => 1
        ], 300);

        wp_redirect(add_query_arg(['page' => 'osct-dashboard'], admin_url('admin.php')));
        exit;
    }


    public function cronTranslate(string $runId, bool $force, ?array $what = null): void
    {
        $this->translator->setRunId($runId);
        $this->translator->setForce($force);

        \OSCT\Core\Debug::start($runId, ['force' => $force, 'mode' => 'cron', 'what' => $what]);

        // translateRun(?array $onlyIds = null, ?array $what = null)
        $res = $this->translator->translateRun(
            null,
            $what ?: ['menu_pages' => true, 'extra_pages' => true, 'blocks' => true, 'jobs' => true]
        );

        \OSCT\Core\Debug::finish($res);
        set_transient('osct_translate_result', $res, 300);
    }

    public function handleDeleteJobTranslation(): void
    {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer('osct_delete_job_translation');

        $jobId = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';
        $lang  = isset($_GET['lang']) ? sanitize_text_field(wp_unslash($_GET['lang'])) : '';

        $repo = new JobsRepo();
        $deleted = false;

        if ($jobId !== '' && $lang !== '') {
            $deleted = $repo->deleteTranslation($jobId, $lang);
        }

        $redirect = isset($_GET['redirect_to']) ? urldecode((string)wp_unslash($_GET['redirect_to'])) : '';
        $redirect = esc_url_raw($redirect);

        if (!$redirect) {
            $redirect = add_query_arg(['page' => 'osct-jobs'], admin_url('admin.php'));
        }

        $redirect = remove_query_arg(['deleted', 'deleted_job', 'deleted_lang'], $redirect);
        $redirect = add_query_arg([
            'deleted'      => $deleted ? '1' : '0',
            'deleted_job'  => $jobId,
            'deleted_lang' => $lang,
        ], $redirect);

        wp_safe_redirect($redirect);
        exit;
    }
}
