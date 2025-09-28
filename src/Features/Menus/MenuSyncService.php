<?php

namespace OSCT\Features\Menus;

use OSCT\Domain\Repos\OptionRepo;
use OSCT\Domain\Repos\LanguageRepo;
use OSCT\Translation\TranslationService;

if (!defined('ABSPATH')) exit;

final class MenuSyncService
{
  private array $debug = [];
  /** @var callable|null */
  private $translator;

  public function __construct(
    private OptionRepo $opt,
    private LanguageRepo $langs,
    TranslationService|callable|null $translator = null
  ) {
    if ($translator instanceof TranslationService) {
      $this->translator = fn(string $text, string $lang, string $source) => $translator->quickTranslate($text, $lang, $source);
    } elseif (is_callable($translator)) {
      $this->translator = $translator;
    } else {
      $this->translator = null;
    }
  }

  public function bootstrap(): array
  {
    return $this->sync();
  }

  public function sync(): array
  {
    $srcMenuId = (int)$this->opt->get('menu_id', 0);
    if (!$srcMenuId) return ['created' => 0, 'assigned' => 0, 'cloned_items' => 0, 'created_menus' => []];

    $targets   = (array)$this->opt->get('languages_active', []);
    $default   = $this->langs->default();
    $srcMenu   = wp_get_nav_menu_object($srcMenuId);
    if (!$srcMenu) return ['created' => 0, 'assigned' => 0, 'cloned_items' => 0, 'created_menus' => []];

    $baseSlots = $this->slotsOf($srcMenuId);

    $sumCreated = 0;
    $sumAssigned = 0;
    $sumItems = 0;
    $createdMenus = [];

    foreach ($targets as $lang) {
      if ($lang === $default) continue;

      $targetName = 'MainMenu (' . $lang . ')';
      $dstMenuId = 0;

      if (function_exists('pll_get_term')) {
        $maybe = pll_get_term($srcMenuId, $lang);
        if ($maybe && !is_wp_error($maybe)) $dstMenuId = (int)$maybe;
      }

      if (!$dstMenuId) {
        $existing = wp_get_nav_menu_object($targetName);
        if ($existing) {
          $dstMenuId = (int)$existing->term_id;
        } else {
          $newId = wp_create_nav_menu($targetName);
          if (is_wp_error($newId)) continue;
          $dstMenuId = (int)$newId;
          $sumCreated++;
          $createdMenus[] = ['lang' => $lang, 'name' => $targetName];
          if (function_exists('pll_set_term_language')) pll_set_term_language($dstMenuId, $lang);
          if (function_exists('pll_get_term_translations') && function_exists('pll_save_term_translations')) {
            $map = pll_get_term_translations($srcMenuId) ?: [];
            $map[$default] = $srcMenuId;
            $map[$lang] = $dstMenuId;
            pll_save_term_translations($map);
          }
          $sumItems += $this->cloneItemsFlat($srcMenuId, $dstMenuId, $lang, $targetName);
          #$sumItems += $this->cloneItemsWithParents($srcMenuId, $dstMenuId, $lang, $targetName);
        }
      }

      if ($dstMenuId && $baseSlots) {
        $this->assignSlots($lang, $dstMenuId, $baseSlots);
        $sumAssigned++;
      }
    }

    $summary = ['created' => $sumCreated, 'assigned' => $sumAssigned, 'cloned_items' => $sumItems, 'created_menus' => $createdMenus];
    set_transient('osct_menu_debug', ['summary' => $summary, 'rows' => $this->debug], 600);
    return $summary;
  }

  private function cloneItemsFlat(int $srcMenuId, int $dstMenuId, string $lang, string $dstName): int
  {
    $srcItems = wp_get_nav_menu_items($srcMenuId, ['update_post_term_cache' => false]) ?: [];
    if (!$srcItems) return 0;

    usort($srcItems, fn($a, $b) => ($a->menu_order ?? 0) <=> ($b->menu_order ?? 0));

    $created = 0;
    $sourceLang = $this->langs->default();

    foreach ($srcItems as $it) {
      $type   = get_post_meta($it->ID, '_menu_item_type', true) ?: (string)$it->type;
      $object = get_post_meta($it->ID, '_menu_item_object', true) ?: (string)$it->object;
      $objId  = (int)(get_post_meta($it->ID, '_menu_item_object_id', true) ?: (int)$it->object_id);
      $url    = (string)(get_post_meta($it->ID, '_menu_item_url', true) ?: (string)$it->url);
      $title  = (string)($it->title ?: $it->post_title ?: '');
      if ($title === '' && $type === 'post_type' && $objId) $title = (string)(get_the_title($objId) ?: '');
      if ($title === '' && $type === 'taxonomy' && $objId && $object) {
        $t = get_term($objId, $object);
        if ($t && !is_wp_error($t)) $title = (string)$t->name;
      }
      if ($title === '' && $type === 'custom') $title = $url ?: '#';

      $translatedTitle = $this->translateTitle($title, $lang, $sourceLang, $dstName, (int)$it->ID);

      $args = [
        'menu-item-status'    => 'publish',
        'menu-item-position'  => (int)($it->menu_order ?? 0),
        'menu-item-parent-id' => 0,
        'menu-item-title'     => $translatedTitle,
      ];

      if ($type === 'post_type') {
        $args['menu-item-type'] = 'post_type';
        $args['menu-item-object'] = $object ?: 'page';
        // Übersetzte Objekt-ID holen falls vorhanden
        if (function_exists('pll_get_post')) {
          $translated_objId = pll_get_post($objId, $lang);
          $args['menu-item-object-id'] = $translated_objId ?: $objId;
        } else {
          $args['menu-item-object-id'] = $objId;
        }
      } elseif ($type === 'taxonomy') {
        $args['menu-item-type'] = 'taxonomy';
        $args['menu-item-object'] = $object ?: 'category';
        $args['menu-item-object-id'] = $objId;
      } elseif ($type === 'post_type_archive') {
        $args['menu-item-type'] = 'post_type_archive';
        $args['menu-item-object'] = $object ?: 'post';
      } else {
        $args['menu-item-type'] = 'custom';
        $args['menu-item-url']  = $url ?: '#';
      }

      $newId = wp_update_nav_menu_item($dstMenuId, 0, $args);
      if (!is_wp_error($newId)) {
        $created++;
      }
    }

    return $created;
  }

  private function cloneItemsWithParents(int $srcMenuId, int $dstMenuId, string $lang, string $dstName): int
  {
    $srcItems = wp_get_nav_menu_items($srcMenuId, ['update_post_term_cache' => false]) ?: [];
    if (!$srcItems) return 0;
    usort($srcItems, fn($a, $b) => ($a->menu_order ?? 0) <=> ($b->menu_order ?? 0));

    $map = [];
    $created = 0;
    $sourceLang = $this->langs->default();

    foreach ($srcItems as $it) {
      $type   = get_post_meta($it->ID, '_menu_item_type', true) ?: (string)$it->type;
      $object = get_post_meta($it->ID, '_menu_item_object', true) ?: (string)$it->object;
      $objId  = (int)(get_post_meta($it->ID, '_menu_item_object_id', true) ?: (int)$it->object_id);
      $url    = (string)(get_post_meta($it->ID, '_menu_item_url', true) ?: (string)$it->url);
      $title  = (string)($it->title ?: $it->post_title ?: '');
      if ($title === '' && $type === 'post_type' && $objId) $title = (string)(get_the_title($objId) ?: '');
      if ($title === '' && $type === 'taxonomy' && $objId && $object) {
        $t = get_term($objId, $object);
        if ($t && !is_wp_error($t)) $title = (string)$t->name;
      }
      if ($title === '' && $type === 'custom') $title = $url ?: '#';

      $translatedTitle = $this->translateTitle($title, $lang, $sourceLang, $dstName, (int)$it->ID);

      $args = [
        'menu-item-status'    => 'publish',
        'menu-item-position'  => (int)($it->menu_order ?? 0),
        'menu-item-parent-id' => 0,
        'menu-item-title'     => $translatedTitle,
      ];

      if ($type === 'post_type') {
        $args['menu-item-type'] = 'post_type';
        $args['menu-item-object'] = $object ?: 'page';
        $args['menu-item-object-id'] = $objId;
      } elseif ($type === 'taxonomy') {
        $args['menu-item-type'] = 'taxonomy';
        $args['menu-item-object'] = $object ?: 'category';
        $args['menu-item-object-id'] = $objId;
      } elseif ($type === 'post_type_archive') {
        $args['menu-item-type'] = 'post_type_archive';
        $args['menu-item-object'] = $object ?: 'post';
      } else {
        $args['menu-item-type'] = 'custom';
        $args['menu-item-url']  = $url ?: '#';
      }

      $newId = wp_update_nav_menu_item($dstMenuId, 0, $args);
      if (!is_wp_error($newId)) {
        $map[(int)$it->ID] = (int)$newId;
        $created++;
        $meta = get_post_meta($it->ID);
        foreach ($meta as $key => $values) {
          if (strpos($key, '_menu_item_') === 0) {
            update_post_meta($newId, $key, maybe_unserialize($values[0]));
          }
        }
      }
    }

    foreach ($srcItems as $it) {
      $srcId = (int)$it->ID;
      if (empty($map[$srcId])) continue;
      $parentSrcMeta = (int)get_post_meta($srcId, '_menu_item_menu_item_parent', true);
      $parentSrcProp = (int)($it->menu_item_parent ?? 0);
      $parentSrc = $parentSrcMeta ?: $parentSrcProp;
      $parentDst = $parentSrc && !empty($map[$parentSrc]) ? (int)$map[$parentSrc] : 0;

      $upd = wp_update_nav_menu_item($dstMenuId, (int)$map[$srcId], [
        'menu-item-parent-id' => $parentDst,
        'menu-item-position'  => (int)($it->menu_order ?? 0),
        'menu-item-status'    => 'publish',
      ]);
    }

    return $created;
  }

  private function slotsOf(int $menuId): array
  {
    $loc = get_theme_mod('nav_menu_locations', []);
    $out = [];
    foreach ($loc as $slot => $mid) if ((int)$mid === (int)$menuId) $out[] = $slot;
    return $out;
  }

  private function assignSlots(string $lang, int $menuId, array $slots): void
  {
    $restore = null;
    if (function_exists('pll_current_language') && function_exists('pll_switch_language')) {
      $restore = pll_current_language('slug');
      pll_switch_language($lang);
    }
    $loc = get_theme_mod('nav_menu_locations', []);
    if (!is_array($loc)) $loc = [];
    foreach ($slots as $slot) $loc[$slot] = $menuId;
    set_theme_mod('nav_menu_locations', $loc);
    if ($restore && function_exists('pll_switch_language')) pll_switch_language($restore);
  }

  private function translateTitle(string $title, string $targetLang, string $sourceLang, string $menuName, int $itemId): string
  {
    $result = $title;
    $translated = null;

    if (is_callable($this->translator) && $title !== '') {
      try {
        $translated = call_user_func($this->translator, $title, $targetLang, $sourceLang);
      } catch (\Throwable $e) {
        $this->debug[] = [
          'menu'       => $menuName,
          'item_id'    => $itemId,
          'lang'       => $targetLang,
          'title_src'  => $title,
          'error'      => $e->getMessage(),
        ];
      }
    }

    if (is_string($translated) && $translated !== '') {
      $result = $translated;
    }

    $this->debug[] = [
      'menu'       => $menuName,
      'item_id'    => $itemId,
      'lang'       => $targetLang,
      'title_src'  => $title,
      'title_dst'  => $result,
    ];

    return $result;
  }
}
