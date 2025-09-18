<?php

namespace OSCT\Translation\Jobs;

if (!defined('ABSPATH')) exit;

final class Helpers
{
  /** Hash aus Name + relevanten Feldern (stabil, DB-freundlich) */
  public static function srcHash(string $name, array $val): string
  {
    $pick = [
      'name' => $name,
      'Bezeichnung' => $val['Bezeichnung'] ?? '',
      'BezeichnungAusschreibung' => $val['BezeichnungAusschreibung'] ?? '',
      'Arbeitgeberleistung' => $val['Arbeitgeberleistung'] ?? '',
      'Aufgaben' => $val['Aufgaben'] ?? '',
      'FachlicheAnforderungen' => $val['FachlicheAnforderungen'] ?? '',
      'KontaktText' => $val['KontaktText'] ?? '',
      'MetaDescription' => $val['MetaDescription'] ?? '',
      'LinkSlug' => $val['LinkSlug'] ?? '',
      'EinsatzortPlz' => $val['EinsatzortPlz'] ?? '',
      'EinsatzortOrt' => $val['EinsatzortOrt'] ?? '',
    ];
    return sha1(wp_json_encode($pick));
  }

  /** Wörter im sichtbaren Text (HTML entfernt) */
  public static function countWords(string $html): int
  {
    $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, get_bloginfo('charset'));
    $text = preg_replace('/[\pZ\pC]+/u', ' ', $text);
    $arr  = preg_split('/\s+/u', trim($text));
    return $text === '' ? 0 : count($arr);
  }

  /** Zeichen im sichtbaren Text */
  public static function countChars(string $html): int
  {
    $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, get_bloginfo('charset'));
    return mb_strlen($text);
  }

  /** Metriken für Titel + ausgewählte Inhaltsfelder */
  public static function countAll(string $title, array $val): array
  {
    $wt = self::countWords($title);
    $ct = self::countChars($title);
    $fields = ['Arbeitgeberleistung', 'Aufgaben', 'FachlicheAnforderungen', 'KontaktText', 'BezeichnungAusschreibung'];
    $buf = '';
    foreach ($fields as $f) if (!empty($val[$f]) && is_string($val[$f])) $buf .= ' ' . $val[$f];
    $wc = self::countWords($buf);
    $cc = self::countChars($buf);
    return ['wt' => $wt, 'ct' => $ct, 'wc' => $wc, 'cc' => $cc];
  }

  /** Shortcodes maskieren, damit Provider kein Markup zerstören */
  public static function protectShortcodes(string $content): array
  {
    if (!function_exists('get_shortcode_regex')) return [$content, []];
    $regex = get_shortcode_regex();
    $map = [];
    $i = 0;
    $masked = preg_replace_callback('/' . $regex . '/s', function ($m) use (&$map, &$i) {
      $key = '__OSCT_SC_' . $i . '__';
      $map[$key] = $m[0];
      $i++;
      return $key;
    }, $content);
    return [$masked, $map];
  }

  public static function restoreShortcodes(string $content, array $map): string
  {
    if (empty($map)) return $content;
    return strtr($content, $map);
  }

  /** Links im HTML umschreiben (Slug + Sprachhome) */
  public static function rewriteLinks(string $html, string $oldSlug, string $newSlug, string $lang): string
  {
    $out = str_replace($oldSlug, $newSlug, $html);
    $home = trailingslashit(home_url());
    if (function_exists('pll_home_url')) {
      $langHome = trailingslashit(pll_home_url($lang));
      $out = str_replace($home, $langHome, $out);
    }
    return $out;
  }

  /** Eine einzelne URL umschreiben */
  public static function rewriteUrl(string $url, string $oldSlug, string $newSlug, string $lang): string
  {
    $u = str_replace($oldSlug, $newSlug, $url);
    $home = trailingslashit(home_url());
    if (function_exists('pll_home_url')) {
      $langHome = trailingslashit(pll_home_url($lang));
      if (str_starts_with($u, $home)) $u = $langHome . substr($u, strlen($home));
    }
    return $u;
  }

  /** ISO / RFC-Zeit robust nach MySQL-Datetime */
  public static function toMysqlDate(?string $s): ?string
  {
    if (!$s) return null;
    $norm = preg_replace('/\.\d+Z$/', 'Z', trim($s));
    try {
      $dt = new \DateTime($norm);
    } catch (\Exception $e) {
      return null;
    }
    return $dt->format('Y-m-d H:i:s');
  }

  /** „created_at“ aus Zeilen-/Wert-Feldern ermitteln */
  public static function pickCreatedAt(array $row, array $val): string
  {
    $d = self::toMysqlDate(is_string($row['created_at'] ?? null) ? $row['created_at'] : null);
    if ($d) return $d;

    $d = self::toMysqlDate($val['VeroeffentlichtAb'] ?? null);
    if ($d) return $d;

    $d = self::toMysqlDate($val['DatumAb'] ?? null);
    if ($d) return $d;

    if (!empty($val['JsonLd']) && is_string($val['JsonLd'])) {
      $j = json_decode($val['JsonLd'], true);
      if (is_array($j) && !empty($j['datePosted'])) {
        $d = self::toMysqlDate($j['datePosted']);
        if ($d) return $d;
      }
    }

    return current_time('mysql', 1);
  }
}
