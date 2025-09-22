<?php
if (!defined('ABSPATH')) exit;

function osct_src_lang() {
    $opt = get_option('polylang');
    if (!empty($opt['default_lang'])) return $opt['default_lang'];
    return 'en';
}

function osct_dry_active_target_langs() {
    $o = get_option('osct_settings', []);
    $active = isset($o['languages_active']) && is_array($o['languages_active']) ? $o['languages_active'] : [];
    $src = osct_src_lang();
    $active = array_values(array_unique(array_filter($active)));
    return array_values(array_diff($active, [$src]));
}

function osct_dry_whitelist_pages() {
    $o = get_option('osct_settings', []);
    $ids = isset($o['page_whitelist']) && is_array($o['page_whitelist']) ? array_map('intval',$o['page_whitelist']) : [];
    if (empty($ids)) return [];
    $posts = get_posts(['post_type'=>'page','post__in'=>$ids,'posts_per_page'=>-1,'orderby'=>'post__in','post_status'=>'publish']);
    $out = [];
    foreach ($posts as $p) $out[$p->ID] = $p->post_title;
    return $out;
}

function osct_all_lang_names() {
    $out = [];
    if (function_exists('pll_languages_list')) {
        $objs = pll_languages_list(['fields'=>'objects']);
        if (is_array($objs)) {
            foreach ($objs as $o) if (is_object($o) && !empty($o->slug)) $out[$o->slug] = $o->name ?: $o->slug;
        }
    }
    if (empty($out)) {
        $opt = get_option('polylang');
        if (!empty($opt['default_lang'])) $out[$opt['default_lang']] = strtoupper($opt['default_lang']);
    }
    ksort($out);
    return $out;
}

function osct_render_dry_run() {
    $pages = osct_dry_whitelist_pages();
    $target_defaults = osct_dry_active_target_langs();
    $names = osct_all_lang_names();
    $result = get_transient('osct_dry_result');
    delete_transient('osct_dry_result');

    echo '<div class="wrap"><h1>OS Content Translator – Trockenlauf</h1>';

    if ($result && is_array($result)) {
        echo '<h2>Ergebnis</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Seite</th><th>Sprache</th><th>Titel (Beispiel)</th><th>Zeichen Titel</th><th>Zeichen Inhalt</th><th>Fehler</th></tr></thead><tbody>';
        foreach ($result as $row) {
            echo '<tr>';
            echo '<td>#'.(int)$row['post_id'].' '.esc_html($row['post_title']).'</td>';
            echo '<td>'.esc_html($row['lang']).'</td>';
            echo '<td>'.esc_html($row['title_sample']).'</td>';
            echo '<td>'.(int)$row['title_len'].'</td>';
            echo '<td>'.(int)$row['content_len'].'</td>';
            echo '<td>'.($row['error'] ? '<span style="color:#a00">'.esc_html($row['error']).'</span>' : '–').'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h2>Auswahl</h2>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="osct_do_dry_run">';
    wp_nonce_field('osct_do_dry_run');

    echo '<table class="form-table"><tbody>';

    echo '<tr><th>Seiten</th><td>';
    if (!empty($pages)) {
        foreach ($pages as $id=>$title) {
            echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="pages[]" value="'.esc_attr($id).'" checked> '.esc_html($title).' (#'.(int)$id.')</label>';
        }
    } else {
        echo '<em>Keine freigegebenen Seiten. Bitte in den Einstellungen Menü wählen und Seiten freischalten.</em>';
    }
    echo '</td></tr>';

    echo '<tr><th>Sprachen</th><td>';
    if (!empty($names)) {
        if (empty($target_defaults)) {
            echo '<em>Keine Zielsprachen aktiv. Bitte unter „Einstellungen → Zielsprachen“ aktivieren.</em>';
        } else {
            foreach ($names as $slug=>$label) {
                if (!in_array($slug, $target_defaults, true)) continue;
                echo '<label style="display:inline-block;margin:4px 16px 4px 0"><input type="checkbox" name="langs[]" value="'.esc_attr($slug).'" checked> '.esc_html($label).' ('.esc_html($slug).')</label>';
            }
        }
    } else {
        echo '<em>Keine Sprachen gefunden.</em>';
    }
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<p><button class="button button-primary">Trockenlauf starten</button> <a class="button" href="'.esc_url(add_query_arg(['page'=>'osct-settings'], admin_url('admin.php'))).'">Einstellungen</a></p>';

    echo '</form></div>';
}

function osct_handle_dry_run() {
    if (!current_user_can('manage_options')) wp_die();
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'],'osct_do_dry_run')) wp_die();

    $pages = isset($_POST['pages']) && is_array($_POST['pages']) ? array_map('intval', $_POST['pages']) : [];
    $langs = isset($_POST['langs']) && is_array($_POST['langs']) ? array_map('sanitize_text_field', $_POST['langs']) : [];
    if (empty($pages)) $pages = array_keys(osct_dry_whitelist_pages());
    if (empty($langs)) $langs = osct_dry_active_target_langs();

    $src_lang = osct_src_lang();
    $out = [];

    foreach ($pages as $pid) {
        $p = get_post($pid);
        if (!$p || $p->post_type !== 'page' || $p->post_status !== 'publish') continue;
        $title = get_the_title($pid);
        $content = wp_strip_all_tags($p->post_content);
        $srcTitleLen = mb_strlen($title);
        $srcContentLen = mb_strlen($content);

        foreach ($langs as $lang) {
            if ($lang === $src_lang) continue;
            $t1 = function_exists('osct_api_translate') ? osct_api_translate($title, $lang, $src_lang) : ['ok'=>false,'text'=>'','error'=>'API fehlt'];
            $t2 = function_exists('osct_api_translate') ? osct_api_translate($content, $lang, $src_lang) : ['ok'=>false,'text'=>'','error'=>'API fehlt'];

            $titleText = $t1['ok'] ? $t1['text'] : '';
            $contentText = $t2['ok'] ? $t2['text'] : '';
            $err = $t1['ok'] && $t2['ok'] ? '' : trim(($t1['error']?:'').' '.($t2['error']?:''));

            $out[] = [
                'post_id' => $pid,
                'post_title' => $title,
                'lang' => $lang,
                'title_sample' => $titleText !== '' ? mb_substr($titleText, 0, 120) : '',
                'title_len' => $titleText !== '' ? mb_strlen($titleText) : $srcTitleLen,
                'content_len' => $contentText !== '' ? mb_strlen($contentText) : $srcContentLen,
                'error' => $err
            ];
        }
    }

    set_transient('osct_dry_result', $out, 300);
    wp_redirect(add_query_arg(['page'=>'osct-dry-run'], admin_url('admin.php')));
    exit;
}
add_action('admin_post_osct_do_dry_run','osct_handle_dry_run');
