<?php

namespace OSCT\Admin\Pages;

use OSCT\Domain\Repos\OptionRepo;
use OSCT\Domain\Repos\LanguageRepo;
use OSCT\Domain\Repos\ContentRepo;
use OSCT\Domain\Repos\JobsRepo;
use OSCT\Translation\TranslationService;

if (!defined('ABSPATH')) exit;

final class DashboardPage
{
    private JobsRepo $jobs;

    public function __construct(
        private OptionRepo $opt,
        private LanguageRepo $langsRepo,
        private ContentRepo $content,
        private TranslationService $translator,
        ?JobsRepo $jobs = null
    ) {
        $this->jobs = $jobs ?: new JobsRepo();
    }

    public function render(): void
    {
        $o         = $this->opt->all();
        $active    = (array)($o['languages_active'] ?? []);
        $menuId    = (int)($o['menu_id'] ?? 0);
        $menuName  = $menuId ? $this->content->menuName($menuId) : '';
        $wlMenu    = (array)($o['page_whitelist'] ?? []);
        $wlExtra   = (array)($o['page_whitelist_extra'] ?? []);
        $wlBlocks  = (array)($o['block_whitelist'] ?? []);
        $wlPages   = array_values(array_unique(array_map('intval', array_merge($wlMenu, $wlExtra))));
        $langs     = $this->langsRepo->all();

        $tr = get_transient('osct_translate_result');
        delete_transient('osct_translate_result');

        echo '<div class="wrap"><h1>OS Content Translator – Dashboard</h1>';
        if ($tr && is_array($tr)) {
            echo '<div class="notice notice-success is-dismissible"><p>Übersetzung abgeschlossen. Neu: ' . intval($tr['created']) . ', übersprungen: ' . intval($tr['skipped']) . '.</p></div>';
        }

        $menuDbg = get_transient('osct_menu_debug');
        delete_transient('osct_menu_debug');
        if ($menuDbg && is_array($menuDbg)) {
            $s = $menuDbg['summary'] ?? [];
            echo '<h2>Menü-Clone Debug</h2>';
            echo '<p>Erstellt: ' . intval($s['created'] ?? 0) . ', Zuweisungen: ' . intval($s['assigned'] ?? 0) . ', Items: ' . intval($s['cloned_items'] ?? 0) . '</p>';
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>Zeit</th><th>Sprache</th><th>Ziel-Menü</th><th>Ziel-Menü-ID</th><th>Aktion</th><th>Status</th><th>Neu-ID</th><th>Src-ID</th><th>Typ</th><th>Objekt</th><th>Obj-ID</th><th>Titel</th><th>URL</th><th>Msg</th>';
            echo '</tr></thead><tbody>';
            foreach (($menuDbg['rows'] ?? []) as $r) {
                echo '<tr>';
                echo '<td>' . esc_html($r['ts']) . '</td>';
                echo '<td>' . esc_html($r['lang'] ?? '') . '</td>';
                echo '<td>' . esc_html($r['dst_menu'] ?? '') . '</td>';
                echo '<td>' . intval($r['dst_menu_id'] ?? 0) . '</td>';
                echo '<td>' . esc_html($r['action'] ?? '') . '</td>';
                echo '<td>' . esc_html($r['status'] ?? '') . '</td>';
                echo '<td>' . intval($r['new_item_id'] ?? 0) . '</td>';
                echo '<td>' . intval($r['src_item_id'] ?? 0) . '</td>';
                echo '<td>' . esc_html($r['type'] ?? '') . '</td>';
                echo '<td>' . esc_html($r['object'] ?? '') . '</td>';
                echo '<td>' . intval($r['object_id'] ?? 0) . '</td>';
                echo '<td>' . esc_html($r['title'] ?? '') . '</td>';
                echo '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis">' . esc_html($r['url'] ?? '') . '</td>';
                echo '<td>' . esc_html($r['message'] ?? '') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>Status</h2><table class="widefat striped"><tbody>';
        echo '<tr><td>Aktives Menü</td><td>' . ($menuId ? esc_html($menuName) . " (#{$menuId})" : '–') . '</td></tr>';
        echo '<tr><td>Freigegebene Seiten</td><td>' . count($wlPages) . '</td></tr>';
        echo '<tr><td>Freigegebene Blöcke</td><td>' . count($wlBlocks) . '</td></tr>';
        echo '<tr><td>Aktive Zielsprachen</td><td>' . esc_html(implode(', ', $active)) . '</td></tr>';
        echo '</tbody></table>';

        $defaultLang = $this->langsRepo->default();
        $totalJobs = $this->jobs->countAll();
        $anyTranslated = $this->jobs->countAnyTranslated($active);
        echo '<h2>Stellenangebote</h2>';
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><td>Gesamt</td><td>' . intval($totalJobs) . '</td></tr>';
        echo '<tr><td>Bereits eingesetzt (mind. 1 Sprache)</td><td>' . intval($anyTranslated) . ' / ' . intval($totalJobs) . ' (' . ($totalJobs ? round($anyTranslated / $totalJobs * 100) : 0) . '%)</td></tr>';
        echo '</tbody></table>';

        if (!empty($active) && $totalJobs > 0) {
            echo '<h3>Übersetzungsstand je Zielsprache</h3>';
            echo '<table class="widefat striped"><thead><tr><th>Sprache</th><th>Übersetzt</th><th>Anteil</th></tr></thead><tbody>';
            foreach ($active as $l) {
                if ($l === $defaultLang) continue;
                $done = $this->jobs->countTranslated($l);
                $pct = $totalJobs ? round($done / $totalJobs * 100) : 0;
                echo '<tr><td>' . esc_html($l) . '</td><td>' . intval($done) . ' / ' . intval($totalJobs) . '</td><td>' . $pct . '%</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>Übersetzung starten</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="osct_do_translate">';
        wp_nonce_field('osct_do_translate');
        echo '<p style="margin-bottom:8px"><label><input type="checkbox" name="osct_test" value="1" checked> Testlauf</label></p>';
        echo '<p style="margin-bottom:8px">Stellenanzeigen-Limit: <select name="osct_jobs_limit">'
            . '<option value="0" selected>Alle offenen Stellen</option>'
            . '<option value="10">Erste 10</option>'
            . '<option value="50">Erste 50</option>'
            . '<option value="100">Erste 100</option>'
            . '<option value="250">Erste 250</option>'
            . '<option value="500">Erste 500</option>'
            . '</select></p>';
        echo '<p style="margin-bottom:8px"><label><input type="checkbox" name="osct_do_jobs" value="1"> Stellenanzeigen</label></p>';
        echo '<p style="margin-bottom:8px"><label><input type="checkbox" name="osct_do_menu_pages" value="1"> Seiten im gewählten Menü</label></p>';
        echo '<p style="margin-bottom:8px"><label><input type="checkbox" name="osct_do_extra_pages" value="1"> Standardseiten</label></p>';
        echo '<p style="margin-bottom:8px"><label><input type="checkbox" name="osct_do_blocks" value="1"> Reusable Blocks</label></p>';
        echo '<p style="margin-top:12px"><label><input type="checkbox" name="osct_force" value="1"> Force: vorhandene Zielversionen trotz "OK" neu übersetzen</label></p>';
        echo '<p>Übersetzt nur die aktivierten Gruppen in die aktiven Zielsprachen.</p>';
        echo '<p><button class="button button-primary">Übersetzung jetzt ausführen</button> ';
        echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'osct-debug'], admin_url('admin.php'))) . '">Letzter Lauf (Debug)</a></p>';
        echo '</form>';


        if (!empty($active) && !empty($wlPages)) {
            echo '<h2>Seiten × Sprachen</h2><table class="widefat striped"><thead><tr><th>Seite</th>';
            foreach ($active as $l) echo '<th>' . esc_html($l) . '</th>';
            echo '</tr></thead><tbody>';
            $pages = $this->content->getPostsByIds($wlPages, 'page');
            foreach ($pages as $p) {
                echo '<tr><td>#' . $p->ID . ' ' . esc_html(get_the_title($p)) . '</td>';
                foreach ($active as $l) echo '<td>' . $this->badge($this->translator->state($p->ID, $l)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if (!empty($active) && !empty($wlBlocks)) {
            echo '<h2>Reusable Blocks × Sprachen</h2><table class="widefat striped"><thead><tr><th>Block</th>';
            foreach ($active as $l) echo '<th>' . esc_html($l) . '</th>';
            echo '</tr></thead><tbody>';
            $blocks = $this->content->getPostsByIds($wlBlocks, 'wp_block');
            foreach ($blocks as $b) {
                echo '<tr><td>#' . $b->ID . ' ' . esc_html(get_the_title($b)) . '</td>';
                foreach ($active as $l) echo '<td>' . $this->badge($this->translator->state($b->ID, $l)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>Freigegebene Inhalte</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>Titel</th><th>Permalink</th><th>Typ</th><th>Quelle</th>';
        echo '</tr></thead><tbody>';

        if (!empty($wlPages)) {
            $posts = $this->content->getPostsByIds($wlPages, 'page');
            foreach ($posts as $p) {
                $quelle = in_array($p->ID, $wlMenu, true) ? 'Menü' : 'Manuell';
                echo '<tr>';
                echo '<td>#' . (int)$p->ID . '</td>';
                echo '<td>' . esc_html(get_the_title($p)) . '</td>';
                echo '<td><a href="' . esc_url(get_permalink($p)) . '" target="_blank">' . esc_html(get_permalink($p)) . '</a></td>';
                echo '<td>page</td>';
                echo '<td>' . $quelle . '</td>';
                echo '</tr>';
            }
        }

        if (!empty($wlBlocks)) {
            $blocks = $this->content->getPostsByIds($wlBlocks, 'wp_block');
            foreach ($blocks as $b) {
                echo '<tr>';
                echo '<td>#' . (int)$b->ID . '</td>';
                echo '<td>' . esc_html(get_the_title($b)) . '</td>';
                echo '<td><a href="' . esc_url(get_edit_post_link($b->ID)) . '" target="_blank">' . esc_html(get_edit_post_link($b->ID)) . '</a></td>';
                echo '<td>wp_block</td>';
                echo '<td>Block</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        echo '</div>';
    }

    private function badge(string $s): string
    {
        return $s === 'ok' ? '<span style="color:#0a0">OK</span>' : ($s === 'stale' ? '<span style="color:#e69500">Veraltet</span>' :
            '<span style="color:#a00">Fehlt</span>');
    }
}
