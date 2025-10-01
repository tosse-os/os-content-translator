<?php
namespace OSCT\Features\Blocks;
if (!defined('ABSPATH')) exit;

final class BlockSyncService {
    public function register(): void {
        add_filter('render_block_data', [$this,'mapReusableBlockToLanguage'], 10, 2);
    }
    public function mapReusableBlockToLanguage(array $parsed_block, $source_post_id): array {
        if (empty($parsed_block['blockName'])) return $parsed_block;
        if (!function_exists('pll_current_language')) return $parsed_block;

        $lang = pll_current_language('slug');
        if (!$lang) return $parsed_block;

        if ($parsed_block['blockName'] === 'core/block') {
            if (empty($parsed_block['attrs']['ref'])) return $parsed_block;
            if (!function_exists('pll_get_post')) return $parsed_block;
            $tr = pll_get_post((int)$parsed_block['attrs']['ref'], $lang);
            if ($tr) $parsed_block['attrs']['ref'] = (int)$tr;
            return $parsed_block;
        }

        if ($parsed_block['blockName'] === 'core/navigation') {
            $attrs = $parsed_block['attrs'] ?? [];

            if (!empty($attrs['ref']) && function_exists('pll_get_post')) {
                $navPost = pll_get_post((int)$attrs['ref'], $lang);
                if ($navPost) $parsed_block['attrs']['ref'] = (int)$navPost;
            }

            if (!empty($attrs['menuId']) && function_exists('pll_get_term')) {
                $menu = pll_get_term((int)$attrs['menuId'], $lang);
                if ($menu && !is_wp_error($menu)) $parsed_block['attrs']['menuId'] = (int)$menu;
            }

            return $parsed_block;
        }

        return $parsed_block;
    }
}
