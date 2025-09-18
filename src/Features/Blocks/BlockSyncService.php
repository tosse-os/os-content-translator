<?php
namespace OSCT\Features\Blocks;
if (!defined('ABSPATH')) exit;

final class BlockSyncService {
    public function register(): void {
        add_filter('render_block_data', [$this,'mapReusableBlockToLanguage'], 10, 2);
    }
    public function mapReusableBlockToLanguage(array $parsed_block, $source_post_id): array {
        if (empty($parsed_block['blockName']) || $parsed_block['blockName'] !== 'core/block') return $parsed_block;
        if (empty($parsed_block['attrs']['ref'])) return $parsed_block;
        if (!function_exists('pll_get_post') || !function_exists('pll_current_language')) return $parsed_block;
        $lang = pll_current_language('slug'); if (!$lang) return $parsed_block;
        $tr = pll_get_post((int)$parsed_block['attrs']['ref'], $lang);
        if ($tr) $parsed_block['attrs']['ref'] = (int)$tr;
        return $parsed_block;
    }
}
