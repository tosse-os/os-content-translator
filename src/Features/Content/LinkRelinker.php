<?php

namespace OSCT\Features\Content;

if (!defined('ABSPATH')) exit;

final class LinkRelinker
{
  public function register(): void
  {
    error_log('innerlr');
    add_filter('the_content', [$this, 'run'], 9);
  }
  public function run(string $html): string
  {
    error_log('innerlr0');
    if (is_admin()) return $html;
    error_log('innerlr1');
    if (!function_exists('pll_current_language') || !function_exists('pll_get_post')) return $html;
    $lang = pll_current_language('slug');
    if (!$lang) return $html;

    $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);
    libxml_use_internal_errors(true);
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html);
    $changed = false;

    foreach ($dom->getElementsByTagName('a') as $a) {
      $href = $a->getAttribute('href');
      if (!$href) continue;
      $p = wp_parse_url($href);
      if (!empty($p['scheme']) && !in_array($p['scheme'], ['http', 'https'])) continue;
      if (!empty($p['host']) && $p['host'] !== $siteHost) continue;
      if (!empty($p['fragment'])) continue;

      $abs = $href;
      if (empty($p['host'])) {
        $path = isset($p['path']) ? $p['path'] : '/';
        $abs = home_url($path);
        if (!empty($p['query'])) $abs .= '?' . $p['query'];
      }

      $postId = url_to_postid($abs);
      if ($postId) {
        $tr = pll_get_post($postId, $lang);
        if ($tr) {
          $a->setAttribute('href', get_permalink($tr));
          $changed = true;
        }
      } else {
        $homeAbs = trailingslashit(home_url('/'));
        $absNorm = trailingslashit(preg_replace('~\?.*$~', '', $abs));
        if ($absNorm === $homeAbs) {
          $a->setAttribute('href', trailingslashit(pll_home_url($lang)));
          $changed = true;
        }
      }
    }

    if (!$changed) return $html;
    $body = $dom->getElementsByTagName('body')->item(0);
    $out = '';
    foreach ($body->childNodes as $child) $out .= $dom->saveHTML($child);
    return $out;
  }
}
