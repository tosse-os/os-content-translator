<?php
namespace OSCT\Domain\Repos;
if (!defined('ABSPATH')) exit;

final class OptionRepo {
    private string $key = 'osct_settings';

    private array $defaults = [
        'provider_default'    => 'google',
        'provider_override'   => [],       // NEU: Map [lang => google|deepl]
        'api_google'          => '',
        'api_deepl'           => '',
        'languages_active'    => [],
        'menu_id'             => 0,
        'page_whitelist'      => [],
        'menu_whitelist_map'  => [],
        'page_whitelist_extra'=> [],
        'block_whitelist'     => [],
        'slug_translate'      => 0,
        'review_as_draft'     => 1,
        'only_new'            => 0,        // optional (zukunft)
    ];

    public function all(): array {
        $o = get_option($this->key, []);
        if (!is_array($o)) $o = [];
        return array_merge($this->defaults, $o);
    }

    public function get(string $k, $default=null) {
        $a = $this->all();
        return $a[$k] ?? $default;
    }

    public function updateAll(array $o): void {
        update_option($this->key, array_merge($this->defaults, $o));
    }
}
