<?php
if (!defined('ABSPATH')) exit;

function osct_get_languages() {
    $out = [];
    if (function_exists('pll_languages_list')) {
        $objs = pll_languages_list(['fields'=>'objects']);
        if (is_array($objs) && !empty($objs)) {
            foreach ($objs as $o) {
                if (is_object($o) && !empty($o->slug)) {
                    $out[$o->slug] = (object)[
                        'slug'=>$o->slug,
                        'name'=>isset($o->name)?$o->name:$o->slug,
                        'locale'=>isset($o->locale)?$o->locale:$o->slug
                    ];
                }
            }
        }
        if (empty($out)) {
            $slugs = pll_languages_list();
            if (is_array($slugs)) {
                foreach ($slugs as $slug) if (!empty($slug)) $out[$slug]=(object)['slug'=>$slug,'name'=>strtoupper($slug),'locale'=>$slug];
            }
        }
    }
    if (empty($out)) {
        $opt = get_option('polylang');
        if (is_array($opt) && !empty($opt['languages'])) {
            foreach ($opt['languages'] as $slug=>$row) $out[$slug]=(object)['slug'=>$slug,'name'=>isset($row['name'])?$row['name']:$slug,'locale'=>isset($row['locale'])?$row['locale']:$slug];
        } elseif (!empty($opt['default_lang'])) {
            $slug=$opt['default_lang']; $out[$slug]=(object)['slug'=>$slug,'name'=>strtoupper($slug),'locale'=>$slug];
        }
    }
    ksort($out);
    return $out;
}

function osct_status_badge($state){
    if($state==='ok') return '<span style="color:#0a0">OK</span>';
    if($state==='stale') return '<span style="color:#e69500">Veraltet</span>';
    return '<span style="color:#a00">Fehlt</span>';
}

function osct_render_dashboard() {
    $o = get_option('osct_settings', []);
    $langs = osct_get_languages();
    $active = isset($o['languages_active']) ? (array)$o['languages_active'] : [];
    $menu_id = isset($o['menu_id']) ? (int)$o['menu_id'] : 0;
    $wl_menu = isset($o['page_whitelist']) ? (array)$o['page_whitelist'] : [];
    $wl_extra = isset($o['page_whitelist_extra']) ? (array)$o['page_whitelist_extra'] : [];
    $wl_blocks = isset($o['block_whitelist']) ? (array)$o['block_whitelist'] : [];
    $whitelist_pages = array_values(array_unique(array_map('intval', array_merge($wl_menu,$wl_extra))));
    $whitelist_all = array_values(array_unique(array_map('intval', array_merge($whitelist_pages,$wl_blocks))));
    $menu_name = $menu_id ? wp_get_nav_menu_object($menu_id)->name : '';
    $total_pages = count($whitelist_pages);
    $total_blocks = count($wl_blocks);

    $tr = get_transient('osct_translate_result');
    delete_transient('osct_translate_result');

    echo '<div class="wrap">';
    echo '<h1>OS Content Translator – Dashboard</h1>';

    if ($tr && is_array($tr)) {
        echo '<div class="notice notice-success is-dismissible"><p>Übersetzung abgeschlossen. Neu: '.intval($tr['created']).', übersprungen: '.intval($tr['skipped']).'.</p></div>';
    }

    echo '<h2>Status</h2>';
    echo '<table class="widefat striped"><tbody>';
    echo '<tr><td>Aktives Menü</td><td>'.($menu_id?esc_html($menu_name).' (#'.(int)$menu_id.')':'–').'</td></tr>';
    echo '<tr><td>Freigegebene Seiten (gesamt)</td><td>'.(int)$total_pages.'</td></tr>';
    echo '<tr><td>Freigegebene Reusable Blocks</td><td>'.(int)$total_blocks.'</td></tr>';
    echo '<tr><td>Aktive Zielsprachen</td><td>'.esc_html(implode(', ', $active)).'</td></tr>';
    echo '</tbody></table>';

    echo '<h2>Schneller Trockenlauf</h2>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="osct_do_dry_run">';
    wp_nonce_field('osct_do_dry_run');
    echo '<p>Führt einen Trockenlauf für alle freigegebenen Seiten und Blöcke in die aktiven Zielsprachen aus, ohne Inhalte anzulegen.</p>';
    echo '<p><a class="button button-secondary" href="'.esc_url(add_query_arg(['page'=>'osct-dry-run'], admin_url('admin.php'))).'">Erweiterter Trockenlauf</a> ';
    echo '<button class="button button-primary">Trockenlauf starten</button></p>';
    echo '</form>';

    echo '<h2>Übersetzung starten</h2>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="osct_do_translate">';
    wp_nonce_field('osct_do_translate');
    echo '<p>Übersetzt fehlende oder veraltete Zielsprachen. Bestehende, aktuelle Übersetzungen werden übersprungen.</p>';
    echo '<p><button class="button button-primary">Übersetzung jetzt ausführen</button> ';
    echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'osct-settings'], admin_url('admin.php'))).'">Einstellungen</a></p>';
    echo '</form>';

    echo '<h2>Seiten × Sprachen</h2>';
    if (!empty($active) && !empty($whitelist_pages)) {
        echo '<table class="widefat striped"><thead><tr><th>Seite</th>';
        foreach($active as $lang) echo '<th>'.esc_html($lang).'</th>';
        echo '</tr></thead><tbody>';
        $posts = get_posts(['post_type'=>'page','post__in'=>$whitelist_pages,'posts_per_page'=>-1,'orderby'=>'post__in']);
        foreach ($posts as $p) {
            echo '<tr>';
            echo '<td>#'.(int)$p->ID.' '.esc_html(get_the_title($p)).'</td>';
            foreach($active as $lang){
                $state = osct_translation_state($p->ID,$lang);
                echo '<td>'.osct_status_badge($state).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>Keine freigegebenen Seiten oder keine Zielsprachen aktiv.</p>';
    }

    echo '<h2>Reusable Blocks × Sprachen</h2>';
    if (!empty($active) && !empty($wl_blocks)) {
        echo '<table class="widefat striped"><thead><tr><th>Block</th>';
        foreach($active as $lang) echo '<th>'.esc_html($lang).'</th>';
        echo '</tr></thead><tbody>';
        $blocks = get_posts(['post_type'=>'wp_block','post__in'=>$wl_blocks,'posts_per_page'=>-1,'orderby'=>'post__in']);
        foreach ($blocks as $b) {
            echo '<tr>';
            echo '<td>#'.(int)$b->ID.' '.esc_html(get_the_title($b)).'</td>';
            foreach($active as $lang){
                $state = osct_translation_state($b->ID,$lang);
                echo '<td>'.osct_status_badge($state).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>Keine freigegebenen Reusable Blocks oder keine Zielsprachen aktiv.</p>';
    }

    if (!empty($whitelist_all)) {
        echo '<h2>Freigegebene Inhalte</h2>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Titel</th><th>Permalink</th><th>Typ</th><th>Quelle</th></tr></thead><tbody>';
        $posts = get_posts(['post_type'=>['page','wp_block'],'post__in'=>$whitelist_all,'posts_per_page'=>-1,'orderby'=>'post__in']);
        foreach ($posts as $p) {
            $src = in_array($p->ID,$wl_menu,true) ? 'Menü' : (in_array($p->ID,$wl_blocks,true) ? 'Block' : 'Manuell');
            $link = $p->post_type==='wp_block' ? get_edit_post_link($p->ID,'') : get_permalink($p);
            echo '<tr>';
            echo '<td>#'.(int)$p->ID.'</td>';
            echo '<td>'.esc_html(get_the_title($p)).'</td>';
            echo '<td>'.($link?'<a href="'.esc_url($link).'" target="_blank">'.esc_html($link).'</a>':'–').'</td>';
            echo '<td>'.esc_html($p->post_type).'</td>';
            echo '<td>'.esc_html($src).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>';
}
