<?php
namespace OSCT\Translation;
if (!defined('ABSPATH')) exit;

final class HashService {
    public function srcHash(int $post_id): string {
        $p = get_post($post_id);
        $title = get_the_title($post_id);
        $content = $p ? $p->post_content : '';
        return md5($title.'|'.$content);
    }
    public function setTargetHash(int $post_id, string $lang, string $hash): void {
        update_post_meta($post_id,'_osct_tr_'.$lang.'_hash',$hash);
        update_post_meta($post_id,'_osct_tr_'.$lang.'_updated',time());
    }
    public function getTargetHash(int $post_id, string $lang): string {
        return (string) get_post_meta($post_id,'_osct_tr_'.$lang.'_hash',true);
    }
}
