<?php
if (!defined('ABSPATH')) exit;

function osct_get_default_lang() {
    $opt = get_option('polylang');
    if (!empty($opt['default_lang'])) return $opt['default_lang'];
    return 'en';
}

function osct_get_active_target_langs() {
    $o = get_option('osct_settings', []);
    $langs = isset($o['languages_active']) && is_array($o['languages_active']) ? $o['languages_active'] : [];
    return array_values(array_unique(array_filter($langs)));
}

function osct_should_translate_slugs() {
    $o = get_option('osct_settings', []);
    return !empty($o['slug_translate']);
}

function osct_review_as_draft() {
    $o = get_option('osct_settings', []);
    return !empty($o['review_as_draft']);
}

function osct_whitelist_ids() {
    $o = get_option('osct_settings', []);
    $a = isset($o['page_whitelist']) && is_array($o['page_whitelist']) ? $o['page_whitelist'] : [];
    $b = isset($o['page_whitelist_extra']) && is_array($o['page_whitelist_extra']) ? $o['page_whitelist_extra'] : [];
    $pages = array_values(array_unique(array_map('intval', array_merge($a,$b))));
    return $pages;
}

function osct_whitelist_blocks() {
    $o = get_option('osct_settings', []);
    $b = isset($o['block_whitelist']) && is_array($o['block_whitelist']) ? $o['block_whitelist'] : [];
    return array_values(array_unique(array_map('intval',$b)));
}

function osct_copy_basic_meta($src_id, $dst_id) {
    $meta = get_post_meta($src_id);
    foreach ($meta as $k => $vals) {
        if (strpos($k, '_edit_lock') === 0) continue;
        if (strpos($k, '_edit_last') === 0) continue;
        foreach ($vals as $v) add_post_meta($dst_id, $k, maybe_unserialize($v));
    }
}

function osct_src_hash($post_id){
    $p = get_post($post_id);
    $title = get_the_title($post_id);
    $content = $p ? $p->post_content : '';
    return md5($title.'|'.$content);
}

function osct_set_target_hash($post_id,$lang,$hash){
    update_post_meta($post_id,'_osct_tr_'.$lang.'_hash',$hash);
    update_post_meta($post_id,'_osct_tr_'.$lang.'_updated',time());
}

function osct_get_target_hash($post_id,$lang){
    return get_post_meta($post_id,'_osct_tr_'.$lang.'_hash',true);
}

function osct_translation_state($src_id,$lang){
    if (!function_exists('pll_get_post')) return 'missing';
    $tgt = pll_get_post($src_id,$lang);
    if (!$tgt) return 'missing';
    $srcHash = osct_src_hash($src_id);
    $tgtHash = osct_get_target_hash($tgt,$lang);
    if ($tgtHash && $tgtHash === $srcHash) return 'ok';
    return 'stale';
}

function osct_translate_single($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_status === 'trash') return new WP_Error('invalid_post','Invalid post');
    if (!in_array($post->post_type, ['page','wp_block'], true)) return new WP_Error('invalid_type','Unsupported type');

    $o = get_option('osct_settings', []);
    $in_menu = in_array((int)$post_id, isset($o['page_whitelist'])?$o['page_whitelist']:[], true) || in_array((int)$post_id, isset($o['page_whitelist_extra'])?$o['page_whitelist_extra']:[], true);
    $in_blocks = in_array((int)$post_id, isset($o['block_whitelist'])?$o['block_whitelist']:[], true);
    if (!($in_menu || $in_blocks)) return new WP_Error('not_whitelisted','Not whitelisted');

    $source_lang = osct_get_default_lang();
    $targets = osct_get_active_target_langs();
    if (empty($targets)) return new WP_Error('no_targets','No target languages');

    $status = osct_review_as_draft() ? 'draft' : 'publish';
    $title_src = get_the_title($post_id);
    $content_src = $post->post_content;
    $slug_src = $post->post_name;
    $srcHash = osct_src_hash($post_id);

    $map = [];
    $map[$source_lang] = $post_id;

    foreach ($targets as $lang) {
        if ($lang === $source_lang) continue;
        $existing = function_exists('pll_get_post') ? pll_get_post($post_id, $lang) : 0;
        if ($existing) {
            $state = osct_translation_state($post_id,$lang);
            if ($state === 'ok') {
                $map[$lang] = $existing;
                continue;
            }
        }

        $t1 = function_exists('osct_api_translate') ? osct_api_translate($title_src, $lang, $source_lang) : ['ok'=>false,'text'=>''];
        $t2 = function_exists('osct_api_translate') ? osct_api_translate($content_src, $lang, $source_lang) : ['ok'=>false,'text'=>''];
        $title_tr = $t1['ok'] ? $t1['text'] : '';
        $content_tr = $t2['ok'] ? $t2['text'] : '';

        if ($title_tr === '' && $content_tr === '') {
            if ($existing) $map[$lang] = $existing;
            continue;
        }

        $new_slug = $slug_src;
        if (osct_should_translate_slugs() && $post->post_type==='page') {
            $slug_try = function_exists('osct_api_translate') ? osct_api_translate($title_src, $lang, $source_lang) : ['ok'=>false,'text'=>''];
            if (!empty($slug_try['text'])) $new_slug = sanitize_title($slug_try['text']);
        }

        if ($existing) {
            $upd = [
                'ID' => $existing,
                'post_title' => $title_tr !== '' ? wp_specialchars_decode($title_tr) : $title_src,
                'post_content' => $content_tr !== '' ? $content_tr : $content_src
            ];
            if ($post->post_type==='page') $upd['post_name'] = $new_slug;
            wp_update_post($upd,true);
            osct_set_target_hash($existing,$lang,$srcHash);
            $map[$lang] = $existing;
            continue;
        }

        $new_id = wp_insert_post([
            'post_type' => $post->post_type,
            'post_status' => $status,
            'post_title' => $title_tr !== '' ? wp_specialchars_decode($title_tr) : $title_src,
            'post_content' => $content_tr !== '' ? $content_tr : $content_src,
            'post_name' => $post->post_type==='page' ? $new_slug : $slug_src,
            'post_author' => get_current_user_id(),
            'post_parent' => $post->post_parent
        ], true);

        if (is_wp_error($new_id)) continue;

        if (function_exists('pll_set_post_language')) pll_set_post_language($new_id, $lang);
        osct_copy_basic_meta($post_id, $new_id);
        osct_set_target_hash($new_id,$lang,$srcHash);
        $map[$lang] = $new_id;
    }

    if (count($map) > 1 && function_exists('pll_save_post_translations')) {
        pll_save_post_translations($map);
    }

    return $map;
}

function osct_translate_post($post_id) {
    return osct_translate_single($post_id);
}

function osct_translate_run($page_ids = []) {
    $created = 0;
    $skipped = 0;
    if (empty($page_ids)) $page_ids = array_merge(osct_whitelist_ids(), osct_whitelist_blocks());
    $page_ids = array_values(array_unique(array_map('intval',$page_ids)));
    foreach ($page_ids as $pid) {
        $r = osct_translate_single((int)$pid);
        if (is_wp_error($r)) { $skipped++; continue; }
        $created++;
    }
    return ['created'=>$created,'skipped'=>$skipped];
}

function osct_handle_test_run() {
    if (!current_user_can('manage_options')) wp_die();
    check_admin_referer('osct_test_run');
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    if (!$post_id) {
        $wl = array_merge(osct_whitelist_ids(), osct_whitelist_blocks());
        if (!empty($wl)) $post_id = (int) reset($wl);
    }
    $res = osct_translate_single($post_id);
    set_transient('osct_test_result', is_wp_error($res) ? $res->get_error_message() : json_encode($res), 120);
    wp_redirect(add_query_arg(['page'=>'osct-dashboard','testrun'=>1], admin_url('admin.php')));
    exit;
}
add_action('admin_post_osct_test_run','osct_handle_test_run');

function osct_handle_translate() {
    if (!current_user_can('manage_options')) wp_die();
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'],'osct_do_translate')) wp_die();
    @set_time_limit(180);
    $ids = array_merge(osct_whitelist_ids(), osct_whitelist_blocks());
    $res = osct_translate_run($ids);
    set_transient('osct_translate_result', $res, 300);
    wp_redirect(add_query_arg(['page'=>'osct-dashboard'], admin_url('admin.php')));
    exit;
}
add_action('admin_post_osct_do_translate','osct_handle_translate');
