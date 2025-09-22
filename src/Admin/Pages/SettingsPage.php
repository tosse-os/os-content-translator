<?php
namespace OSCT\Admin\Pages;
use OSCT\Domain\Repos\OptionRepo;
use OSCT\Domain\Repos\LanguageRepo;
use OSCT\Domain\Repos\ContentRepo;

if (!defined('ABSPATH')) exit;

final class SettingsPage {
    public function __construct(
        private OptionRepo $opt,
        private LanguageRepo $langs,
        private ContentRepo $content
    ) {}

    public function render(): void {
        $o          = $this->opt->all();
        $languages  = $this->langs->all();
        $menuId     = (int)($o['menu_id'] ?? 0);
        $menus      = $this->content->menus($this->langs->default());
        $menuPages  = $this->content->menuPages($menuId);
        $extraPages = $this->content->allPagesExcluding(array_keys($menuPages));
        $blocks     = $this->content->allBlocks();
        $menuMap    = (array)($o['menu_whitelist_map'] ?? []);
        if ($menuId > 0 && empty($menuMap[$menuId]) && !empty($o['page_whitelist'])) {
            $menuMap[$menuId] = array_map('intval', (array)$o['page_whitelist']);
        }
        $wlMenu = array_map('intval', (array)($menuMap[$menuId] ?? ($o['page_whitelist'] ?? [])));

        echo '<div class="wrap"><h1>OS Content Translator – Einstellungen</h1>';
        if (isset($_GET['updated'])) echo '<div class="notice notice-success is-dismissible"><p>Einstellungen gespeichert.</p></div>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="osct_save_settings">';
        wp_nonce_field('osct_settings_save','osct_nonce');

        // Provider
        echo '<h2>Provider</h2><table class="form-table"><tbody>';
        $prov = $o['provider_default'] ?? 'google';
        echo '<tr><th>Standard-Provider</th><td>';
        foreach (['google'=>'Google Translate','deepl'=>'DeepL'] as $k=>$label) {
            echo '<label style="margin-right:16px"><input type="radio" name="provider_default" value="'.esc_attr($k).'" '.checked($prov,$k,false).'> '.esc_html($label).'</label>';
        }
        echo '</td></tr>';
        echo '<tr><th>API-Key Google</th><td><input type="text" name="api_google" class="regular-text" value="'.esc_attr($o['api_google'] ?? '').'"></td></tr>';
        echo '<tr><th>API-Key DeepL</th><td><input type="text" name="api_deepl" class="regular-text" value="'.esc_attr($o['api_deepl'] ?? '').'"></td></tr>';
        echo '</tbody></table>';

        // Zielsprachen
        echo '<h2>Zielsprachen</h2><table class="form-table"><tbody><tr><th>Sprachen aus Polylang</th><td>';
        $active = (array)($o['languages_active'] ?? []);
        foreach ($languages as $slug=>$L) {
            $ch = in_array($slug,$active,true) ? 'checked' : '';
            echo '<label style="display:inline-block;margin:4px 16px 4px 0"><input type="checkbox" name="languages_active[]" value="'.esc_attr($slug).'" '.$ch.'> '.esc_html($L['name']).' ('.esc_html($slug).')</label>';
        }
        echo '</td></tr></tbody></table>';

        // Provider-Override je Sprache (NEU/Zurück)
        echo '<h2>Provider-Override je Sprache</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Sprache</th><th>Provider</th></tr></thead><tbody>';
        $over = (array)($o['provider_override'] ?? []);
        foreach ($languages as $slug=>$L) {
            $val = $over[$slug] ?? '';
            echo '<tr><td>'.esc_html($L['name']).' ('.esc_html($slug).')</td><td>';
            echo '<select name="provider_override['.esc_attr($slug).']">';
            echo '<option value="">Standard</option>';
            echo '<option value="google" '.selected($val,'google',false).'>Google Translate</option>';
            echo '<option value="deepl" '.selected($val,'deepl',false).'>DeepL</option>';
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';

        // Menü-gebundene Auswahl
        echo '<h2>Menü-gebundene Seitenauswahl</h2><table class="form-table"><tbody>';
        echo '<tr><th>Menü auswählen</th><td><select name="menu_id"><option value="0">– bitte wählen –</option>';
        foreach ($menus as $id=>$name) echo '<option value="'.(int)$id.'" '.selected($menuId,(int)$id,false).'>'.esc_html($name).' (#'.(int)$id.')</option>';
        echo '</select> <button class="button" name="reload" value="1">Neu laden</button>';
        echo '<input type="hidden" name="menu_id_prev" value="'.(int)$menuId.'">';
        echo '</td></tr>';
        echo '<tr><th>Seiten im gewählten Menü</th><td>';
        if (!empty($menuPages)) {
            foreach ($menuPages as $pid=>$title) {
                $ch = in_array($pid,$wlMenu,true)?'checked':'';
                echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="page_whitelist[]" value="'.(int)$pid.'" '.$ch.'> '.esc_html($title).' (#'.(int)$pid.')</label>';
            }
        } else echo '<em>Bitte oben ein Menü wählen.</em>';
        echo '</td></tr></tbody></table>';

        // Weitere Seiten
        echo '<h2>Weitere veröffentlichte Seiten</h2><table class="form-table"><tbody><tr><th>Standardseiten</th><td>';
        $wlExtra = (array)($o['page_whitelist_extra'] ?? []);
        if (!empty($extraPages)) {
            foreach ($extraPages as $pid=>$title) {
                $ch = in_array($pid,$wlExtra,true)?'checked':'';
                echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="page_whitelist_extra[]" value="'.(int)$pid.'" '.$ch.'> '.esc_html($title).' (#'.(int)$pid.')</label>';
            }
        } else echo '<em>Keine zusätzlichen Seiten gefunden.</em>';
        echo '</td></tr></tbody></table>';

        // Reusable Blocks
        echo '<h2>Wiederverwendbare Blöcke</h2><table class="form-table"><tbody><tr><th>Reusable Blocks</th><td>';
        $blWl = (array)($o['block_whitelist'] ?? []);
        if (!empty($blocks)) {
            foreach ($blocks as $bid=>$title) {
                $ch = in_array($bid,$blWl,true)?'checked':'';
                echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="block_whitelist[]" value="'.(int)$bid.'" '.$ch.'> '.esc_html($title).' (#'.(int)$bid.')</label>';
            }
        } else echo '<em>Keine Reusable Blocks gefunden.</em>';
        echo '</td></tr></tbody></table>';

        // Optionen
        echo '<h2>Optionen</h2><table class="form-table"><tbody>';
        echo '<tr><th>Slugs übersetzen</th><td><label><input type="checkbox" name="slug_translate" value="1" '.checked(($o['slug_translate']??0),1,false).'> Aktiv</label></td></tr>';
        echo '<tr><th>Review als Entwurf</th><td><label><input type="checkbox" name="review_as_draft" value="1" '.checked(($o['review_as_draft']??1),1,false).'> Aktiv</label></td></tr>';
        echo '<tr><th>Nur neue Inhalte automatisch</th><td><label><input type="checkbox" name="only_new" value="1" '.checked(($o['only_new']??0),1,false).'> Aktiv</label></td></tr>';
        echo '</tbody></table>';

        submit_button('Einstellungen speichern');
        echo '</form></div>';
    }

    public function save(array $in): void {
        $o = $this->opt->all();

        $o['provider_default']    = isset($in['provider_default']) ? sanitize_text_field($in['provider_default']) : 'google';
        $o['api_google']          = sanitize_text_field($in['api_google'] ?? '');
        $o['api_deepl']           = sanitize_text_field($in['api_deepl'] ?? '');
        $o['languages_active']    = isset($in['languages_active']) ? array_values(array_map('sanitize_text_field',(array)$in['languages_active'])) : [];

        $menuId       = isset($in['menu_id']) ? (int)$in['menu_id'] : 0;
        $menuIdPrev   = isset($in['menu_id_prev']) ? (int)$in['menu_id_prev'] : $menuId;
        $wlMenuPosted = isset($in['page_whitelist']) ? array_values(array_unique(array_map('intval',(array)$in['page_whitelist']))) : [];

        $mapRaw = isset($o['menu_whitelist_map']) && is_array($o['menu_whitelist_map']) ? $o['menu_whitelist_map'] : [];
        $menuMap = [];
        foreach ($mapRaw as $mid => $list) {
            $menuMap[(int)$mid] = array_values(array_unique(array_map('intval', (array)$list)));
        }

        if ($menuIdPrev > 0) {
            $menuMap[$menuIdPrev] = $wlMenuPosted;
        }

        $o['menu_id'] = $menuId;
        $o['menu_whitelist_map'] = $menuMap;
        $o['page_whitelist'] = $menuId > 0
            ? array_values(array_unique(array_map('intval', (array)($menuMap[$menuId] ?? []))))
            : [];

        $o['page_whitelist_extra']= isset($in['page_whitelist_extra']) ? array_values(array_unique(array_map('intval',(array)$in['page_whitelist_extra']))) : [];
        $o['block_whitelist']     = isset($in['block_whitelist']) ? array_values(array_unique(array_map('intval',(array)$in['block_whitelist']))) : [];
        $o['slug_translate']      = !empty($in['slug_translate']) ? 1 : 0;
        $o['review_as_draft']     = !empty($in['review_as_draft']) ? 1 : 0;
        $o['only_new']            = !empty($in['only_new']) ? 1 : 0;

        // Provider-Override speichern
        $overIn = isset($in['provider_override']) && is_array($in['provider_override']) ? $in['provider_override'] : [];
        $over   = [];
        foreach ($overIn as $lang=>$val) {
            $lang = sanitize_text_field($lang);
            $val  = sanitize_text_field($val);
            if ($val==='google' || $val==='deepl') $over[$lang] = $val;
        }
        $o['provider_override'] = $over;

        $this->opt->updateAll($o);
        wp_redirect(add_query_arg(['page'=>'osct-settings','updated'=>1], admin_url('admin.php'))); exit;
    }
}
