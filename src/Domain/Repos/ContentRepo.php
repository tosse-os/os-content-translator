<?php
namespace OSCT\Domain\Repos;
if (!defined('ABSPATH')) exit;

final class ContentRepo {
    public function menus(?string $lang = null): array {
        $menus = wp_get_nav_menus();
        $out   = [];

        foreach ($menus as $m) {
            $menuLang = null;
            if ($lang !== null && function_exists('pll_get_term_language')) {
                $res = pll_get_term_language((int)$m->term_id, 'slug');
                if (is_array($res)) {
                    if (isset($res['slug']) && is_string($res['slug'])) {
                        $menuLang = $res['slug'];
                    } else {
                        $first = reset($res);
                        $menuLang = is_string($first) ? $first : null;
                    }
                } elseif (is_string($res)) {
                    $menuLang = $res;
                }
            }

            if ($lang !== null && $menuLang !== null && $menuLang !== '' && $menuLang !== $lang) {
                continue;
            }

            $out[(int)$m->term_id] = $m->name;
        }

        return $out;
    }
    public function menuName(int $id): string {
        $obj = wp_get_nav_menu_object($id);
        return $obj ? $obj->name : '';
    }
    public function menuPages(int $menu_id): array {
        if ($menu_id<=0) return [];
        $items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache'=>false]) ?: [];
        $ids=[]; foreach ($items as $it) if ($it->object==='page' && !empty($it->object_id)) $ids[]=(int)$it->object_id;
        if (!$ids) return [];
        $pages = get_posts(['post_type'=>'page','post__in'=>array_values(array_unique($ids)),'posts_per_page'=>-1,'orderby'=>'post__in','post_status'=>'publish']);
        $out=[]; foreach ($pages as $p) $out[$p->ID]= $p->post_title;
        return $out;
    }
    public function allPagesExcluding(array $exclude): array {
        $q = new \WP_Query(['post_type'=>'page','post_status'=>'publish','posts_per_page'=>-1,'post__not_in'=>array_map('intval',$exclude),'orderby'=>'title','order'=>'ASC','fields'=>'ids']);
        $out=[]; foreach ($q->posts as $id) $out[(int)$id]=get_the_title($id);
        return $out;
    }
    public function allBlocks(): array {
        $q = new \WP_Query(['post_type'=>'wp_block','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC','fields'=>'ids']);
        $out=[]; foreach ($q->posts as $id) $out[(int)$id]=get_the_title($id);
        return $out;
    }
    /** @return \WP_Post[] */
    public function getPostsByIds(array $ids, string $type): array {
        return get_posts(['post_type'=>$type,'post__in'=>array_map('intval',$ids),'posts_per_page'=>-1,'orderby'=>'post__in']);
    }
}
