<?php
namespace OSCT\Admin\Pages;
use OSCT\Domain\Repos\OptionRepo;
use OSCT\Domain\Repos\LanguageRepo;
use OSCT\Domain\Repos\ContentRepo;
use OSCT\Translation\TranslationService;

if (!defined('ABSPATH')) exit;

final class DryRunPage {
    public function __construct(
        private OptionRepo $opt,
        private LanguageRepo $langs,
        private ContentRepo $content,
        private TranslationService $translator
    ) {}

    public function render(): void {
        $res = get_transient('osct_dry_result'); delete_transient('osct_dry_result');
        echo '<div class="wrap"><h1>OS Content Translator – Trockenlauf</h1>';
        if ($res) {
            echo '<h2>Ergebnis</h2>';
            echo '<pre style="background:#fff;border:1px solid #ddd;padding:12px;max-height:400px;overflow:auto">'.esc_html(print_r($res,true)).'</pre>';
        } else {
            echo '<p>Noch kein Trockenlauf ausgeführt.</p>';
        }
        echo '<p><a class="button" href="'.esc_url(add_query_arg(['page'=>'osct-dashboard'], admin_url('admin.php'))).'">Zurück zum Dashboard</a></p>';
        echo '</div>';
    }
}
