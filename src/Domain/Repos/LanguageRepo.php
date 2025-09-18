<?php
namespace OSCT\Domain\Repos;
if (!defined('ABSPATH')) exit;

final class LanguageRepo {
    public function all(): array {
        $out = [];

        // 1) Polylang-Objekt direkt (am zuverlässigsten)
        if (function_exists('PLL')) {
            $pll = PLL();
            if (isset($pll->model) && method_exists($pll->model, 'get_languages_list')) {
                $list = $pll->model->get_languages_list(['hide_empty' => 0]);
                if (is_array($list)) {
                    foreach ($list as $o) {
                        if (!empty($o->slug)) {
                            $out[$o->slug] = [
                                'slug'   => $o->slug,
                                'name'   => $o->name ?? strtoupper($o->slug),
                                'locale' => $o->locale ?? $o->slug,
                            ];
                        }
                    }
                }
            }
        }

        // 2) Klassische API
        if (empty($out) && function_exists('pll_languages_list')) {
            $objs = pll_languages_list(['fields' => 'objects', 'hide_empty' => 0]);
            if (is_array($objs)) {
                foreach ($objs as $o) {
                    if (!empty($o->slug)) {
                        $out[$o->slug] = [
                            'slug'   => $o->slug,
                            'name'   => $o->name ?? strtoupper($o->slug),
                            'locale' => $o->locale ?? $o->slug,
                        ];
                    }
                }
            }
        }

        // 3) Über die Sprache-Taxonomie + pll_get_language()
        if (empty($out)) {
            $tax = function_exists('pll_get_language_taxonomy') ? pll_get_language_taxonomy() : 'language';
            $terms = function_exists('get_terms') ? get_terms([
                'taxonomy'   => $tax,
                'hide_empty' => false,
            ]) : [];
            if (is_array($terms)) {
                foreach ($terms as $t) {
                    if (empty($t->slug)) continue;
                    $obj = function_exists('pll_get_language') ? pll_get_language($t->slug) : null;
                    $out[$t->slug] = [
                        'slug'   => $t->slug,
                        'name'   => $obj->name   ?? $t->name   ?? strtoupper($t->slug),
                        'locale' => $obj->locale ?? $t->slug,
                    ];
                }
            }
        }

        // 4) Fallback auf gespeicherte Optionen
        if (empty($out)) {
            $opt = get_option('polylang');
            if (is_array($opt) && !empty($opt['languages'])) {
                foreach ($opt['languages'] as $slug => $row) {
                    $out[$slug] = [
                        'slug'   => $slug,
                        'name'   => $row['name']   ?? strtoupper($slug),
                        'locale' => $row['locale'] ?? $slug,
                    ];
                }
            } elseif (!empty($opt['default_lang'])) {
                $slug = $opt['default_lang'];
                $out[$slug] = ['slug'=>$slug,'name'=>strtoupper($slug),'locale'=>$slug];
            }
        }

        ksort($out);
        return $out;
    }

    public function default(): string {
        $opt = get_option('polylang');
        return !empty($opt['default_lang']) ? $opt['default_lang'] : 'en';
    }
}
