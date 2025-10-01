<?php
if (!defined('ABSPATH')) exit;

function osct_settings_get() {
    $o = get_option('osct_settings', []);
    if (!is_array($o)) $o = [];
    $o += [
        'provider_default' => 'google',
        'api_google' => '',
        'api_deepl' => '',
        'languages_active' => [],
        'provider_override' => [],
        'post_types' => [],
        'slug_translate' => 0,
        'review_as_draft' => 1,
        'only_new' => 0,
        'menu_id' => 0,
        'page_whitelist' => [],
        'page_whitelist_extra' => [],
        'block_whitelist' => []
    ];
    return $o;
}

function osct_settings_langs() {
    $out = [];
    if (function_exists('pll_languages_list')) {
        $objs = pll_languages_list(['fields'=>'objects']);
        if (is_array($objs) && !empty($objs)) {
            foreach ($objs as $o) {
                if (is_object($o) && !empty($o->slug)) {
                    $out[$o->slug] = ['slug'=>$o->slug,'name'=>isset($o->name)?$o->name:$o->slug,'locale'=>isset($o->locale)?$o->locale:$o->slug];
                }
            }
        }
        if (empty($out)) {
            $slugs = pll_languages_list();
            if (is_array($slugs)) {
                foreach ($slugs as $s) if (!empty($s)) $out[$s] = ['slug'=>$s,'name'=>strtoupper($s),'locale'=>$s];
            }
        }
    }
    if (empty($out)) {
        $opt = get_option('polylang');
        if (is_array($opt) && !empty($opt['languages'])) {
            foreach ($opt['languages'] as $slug=>$row) {
                $out[$slug] = ['slug'=>$slug,'name'=>isset($row['name'])?$row['name']:$slug,'locale'=>isset($row['locale'])?$row['locale']:$slug];
            }
        } elseif (!empty($opt['default_lang'])) {
            $slug = $opt['default_lang'];
            $out[$slug] = ['slug'=>$slug,'name'=>strtoupper($slug),'locale'=>$slug];
        }
    }
    ksort($out);
    return $out;
}

function osct_settings_post_types() {
    $pts = get_post_types(['public'=>true],'objects');
    $out = [];
    foreach ($pts as $k=>$obj) $out[$k] = isset($obj->labels->name)?$obj->labels->name:$k;
    ksort($out);
    return $out;
}

function osct_settings_menus() {
    $menus = wp_get_nav_menus();
    $out = [];
    foreach ($menus as $m) $out[(int)$m->term_id] = $m->name;
    return $out;
}

function osct_settings_menu_pages($menu_id) {
    $ids = [];
    $menu_id = (int)$menu_id;
    if ($menu_id <= 0) return [];
    $items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache'=>false]);
    if (empty($items)) return [];
    foreach ($items as $it) {
        if (!empty($it->object) && $it->object === 'page' && !empty($it->object_id)) {
            $ids[] = (int)$it->object_id;
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids)) return [];
    $pages = get_posts([
        'post_type'=>'page',
        'post__in'=>$ids,
        'posts_per_page'=>-1,
        'orderby'=>'post__in',
        'post_status'=>'publish'
    ]);
    $out = [];
    foreach ($pages as $p) $out[$p->ID] = $p->post_title;
    return $out;
}

function osct_settings_all_pages_excluding($exclude_ids) {
    $exclude_ids = array_map('intval',(array)$exclude_ids);
    $q = new WP_Query([
        'post_type'=>'page',
        'post_status'=>'publish',
        'posts_per_page'=>-1,
        'post__not_in'=>$exclude_ids,
        'orderby'=>'title',
        'order'=>'ASC',
        'fields'=>'ids'
    ]);
    $out = [];
    if ($q->have_posts()) {
        foreach ($q->posts as $pid) $out[(int)$pid] = get_the_title($pid);
    }
    return $out;
}

function osct_settings_blocks_all() {
    $types = ['wp_block', 'wp_navigation'];
    $out = [];

    foreach ($types as $type) {
        if (!post_type_exists($type)) continue;

        $q = new WP_Query([
            'post_type'      => $type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids'
        ]);

        foreach ($q->posts as $pid) {
            $title = get_the_title($pid);
            if ($type === 'wp_navigation') $title = ($title !== '' ? $title : __('(ohne Titel)', 'os-content-translator')) . ' (Navigation)';
            $out[(int)$pid] = $title !== '' ? $title : sprintf('Block #%d', (int)$pid);
        }
    }

    if (!empty($out)) {
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    }

    return $out;
}

function osct_render_settings() {
    $o = osct_settings_get();
    $langs = osct_settings_langs();
    $pts = osct_settings_post_types();
    $providers = ['google'=>'Google Translate','deepl'=>'DeepL'];
    $menus = osct_settings_menus();
    $menu_pages = osct_settings_menu_pages($o['menu_id']);
    $extra_pages = osct_settings_all_pages_excluding(array_keys($menu_pages));
    $blocks = osct_settings_blocks_all();

    echo '<div class="wrap">';
    echo '<h1>OS Content Translator – Einstellungen</h1>';
    if (isset($_GET['updated'])) echo '<div class="notice notice-success is-dismissible"><p>Einstellungen gespeichert.</p></div>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="osct_save_settings">';
    wp_nonce_field('osct_settings_save','osct_nonce');

    echo '<h2>Provider</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Standard-Provider</th><td>';
    foreach ($providers as $k=>$label) {
        echo '<label style="margin-right:16px"><input type="radio" name="provider_default" value="'.esc_attr($k).'" '.checked($o['provider_default'],$k,false).'> '.esc_html($label).'</label>';
    }
    echo '</td></tr>';
    echo '<tr><th>API-Key Google</th><td><input type="text" name="api_google" value="'.esc_attr($o['api_google']).'" class="regular-text"></td></tr>';
    echo '<tr><th>API-Key DeepL</th><td><input type="text" name="api_deepl" value="'.esc_attr($o['api_deepl']).'" class="regular-text"></td></tr>';
    echo '</tbody></table>';

    echo '<h2>Zielsprachen</h2>';
    echo '<table class="form-table"><tbody><tr><th>Sprachen aus Polylang</th><td>';
    if (!empty($langs)) {
        foreach ($langs as $slug=>$L) {
            $checked = in_array($slug,(array)$o['languages_active'],true) ? 'checked' : '';
            echo '<label style="display:inline-block;margin:4px 16px 4px 0"><input type="checkbox" name="languages_active[]" value="'.esc_attr($slug).'" '.$checked.'> '.esc_html($L['name']).' ('.esc_html($slug).')</label>';
        }
    } else {
        echo '<em>Keine Sprachen gefunden.</em>';
    }
    echo '</td></tr></tbody></table>';

    echo '<h2>Provider-Override je Sprache</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Sprache</th><th>Provider</th></tr></thead><tbody>';
    foreach ($langs as $slug=>$L) {
        $val = isset($o['provider_override'][$slug]) ? $o['provider_override'][$slug] : '';
        echo '<tr><td>'.esc_html($L['name']).' ('.esc_html($slug).')</td><td><select name="provider_override['.esc_attr($slug).']"><option value="">Standard</option>';
        foreach ($providers as $k=>$label) {
            echo '<option value="'.esc_attr($k).'" '.selected($val,$k,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></td></tr>';
    }
    echo '</tbody></table>';

    echo '<h2>Menü-gebundene Seitenauswahl</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Menü auswählen</th><td><select name="menu_id">';
    echo '<option value="0">– bitte wählen –</option>';
    foreach ($menus as $mid=>$mname) {
        echo '<option value="'.esc_attr($mid).'" '.selected((int)$o['menu_id'],(int)$mid,false).'>'.esc_html($mname).' (#'.(int)$mid.')</option>';
    }
    echo '</select> <button class="button" name="reload" value="1">Neu laden</button></td></tr>';

    echo '<tr><th>Seiten im gewählten Menü</th><td>';
    if ($o['menu_id'] && !empty($menu_pages)) {
        foreach ($menu_pages as $pid=>$title) {
            $checked = in_array((string)$pid, array_map('strval',(array)$o['page_whitelist']), true) ? 'checked' : '';
            echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="page_whitelist[]" value="'.esc_attr($pid).'" '.$checked.'> '.esc_html($title).' (#'.(int)$pid.')</label>';
        }
    } elseif ($o['menu_id']) {
        echo '<em>Dieses Menü enthält keine Seiten-Links.</em>';
    } else {
        echo '<em>Bitte oben ein Menü wählen und neu laden.</em>';
    }
    echo '</td></tr>';
    echo '</tbody></table>';

    echo '<h2>Weitere veröffentlichte Seiten</h2>';
    echo '<table class="form-table"><tbody><tr><th>Standardseiten (veröffentlicht)</th><td>';
    if (!empty($extra_pages)) {
        foreach ($extra_pages as $pid=>$title) {
            $checked = in_array((string)$pid, array_map('strval',(array)$o['page_whitelist_extra']), true) ? 'checked' : '';
            echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="page_whitelist_extra[]" value="'.esc_attr($pid).'" '.$checked.'> '.esc_html($title).' (#'.(int)$pid.')</label>';
        }
    } else {
        echo '<em>Keine zusätzlichen veröffentlichten Seiten gefunden.</em>';
    }
    echo '</td></tr></tbody></table>';

    echo '<h2>Reusable Blocks &amp; Navigationen</h2>';
    echo '<table class="form-table"><tbody><tr><th>Blöcke &amp; Navigationen</th><td>';
    if (!empty($blocks)) {
        foreach ($blocks as $bid=>$title) {
            $checked = in_array((string)$bid, array_map('strval',(array)$o['block_whitelist']), true) ? 'checked' : '';
            echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="block_whitelist[]" value="'.esc_attr($bid).'" '.$checked.'> '.esc_html($title).' (#'.(int)$bid.')</label>';
        }
    } else {
        echo '<em>Keine veröffentlichten Reusable Blocks oder Navigationen gefunden.</em>';
    }
    echo '</td></tr></tbody></table>';

    echo '<h2>Optionen</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Slugs übersetzen</th><td><label><input type="checkbox" name="slug_translate" value="1" '.checked($o['slug_translate'],1,false).'> Aktiv</label></td></tr>';
    echo '<tr><th>Review als Entwurf</th><td><label><input type="checkbox" name="review_as_draft" value="1" '.checked($o['review_as_draft'],1,false).'> Aktiv</label></td></tr>';
    echo '<tr><th>Nur neue Inhalte automatisch</th><td><label><input type="checkbox" name="only_new" value="1" '.checked($o['only_new'],1,false).'> Aktiv</label></td></tr>';
    echo '</tbody></table>';

    submit_button('Einstellungen speichern');
    echo '</form>';
    echo '</div>';
}

function osct_save_settings() {
    if (!current_user_can('manage_options')) wp_die();
    if (empty($_POST['osct_nonce']) || !wp_verify_nonce($_POST['osct_nonce'],'osct_settings_save')) wp_die();

    $provider_default = isset($_POST['provider_default']) ? sanitize_text_field($_POST['provider_default']) : 'google';
    $api_google = isset($_POST['api_google']) ? sanitize_text_field($_POST['api_google']) : '';
    $api_deepl = isset($_POST['api_deepl']) ? sanitize_text_field($_POST['api_deepl']) : '';
    $languages_active = isset($_POST['languages_active']) && is_array($_POST['languages_active']) ? array_values(array_map('sanitize_text_field', $_POST['languages_active'])) : [];
    $provider_override_in = isset($_POST['provider_override']) && is_array($_POST['provider_override']) ? $_POST['provider_override'] : [];
    $provider_override = [];
    foreach ($provider_override_in as $k=>$v) $provider_override[sanitize_text_field($k)] = sanitize_text_field($v);
    $post_types = isset($_POST['post_types']) && is_array($_POST['post_types']) ? array_values(array_map('sanitize_text_field', $_POST['post_types'])) : [];
    $slug_translate = !empty($_POST['slug_translate']) ? 1 : 0;
    $review_as_draft = !empty($_POST['review_as_draft']) ? 1 : 0;
    $only_new = !empty($_POST['only_new']) ? 1 : 0;
    $menu_id = isset($_POST['menu_id']) ? (int)$_POST['menu_id'] : 0;
    $page_whitelist = isset($_POST['page_whitelist']) && is_array($_POST['page_whitelist']) ? array_values(array_unique(array_map('absint', $_POST['page_whitelist']))) : [];
    $page_whitelist_extra = isset($_POST['page_whitelist_extra']) && is_array($_POST['page_whitelist_extra']) ? array_values(array_unique(array_map('absint', $_POST['page_whitelist_extra']))) : [];
    $block_whitelist = isset($_POST['block_whitelist']) && is_array($_POST['block_whitelist']) ? array_values(array_unique(array_map('absint', $_POST['block_whitelist']))) : [];

    $o = [
        'provider_default' => $provider_default,
        'api_google' => $api_google,
        'api_deepl' => $api_deepl,
        'languages_active' => $languages_active,
        'provider_override' => $provider_override,
        'post_types' => $post_types,
        'slug_translate' => $slug_translate,
        'review_as_draft' => $review_as_draft,
        'only_new' => $only_new,
        'menu_id' => $menu_id,
        'page_whitelist' => $page_whitelist,
        'page_whitelist_extra' => $page_whitelist_extra,
        'block_whitelist' => $block_whitelist
    ];

    update_option('osct_settings', $o);
    wp_redirect(add_query_arg(['page'=>'osct-settings','updated'=>1], admin_url('admin.php')));
    exit;
}
add_action('admin_post_osct_save_settings','osct_save_settings');
