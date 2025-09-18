<?php

namespace OSCT\Translation\Providers;

if (!defined('ABSPATH')) exit;

final class LangMap
{
  public static function googleTarget(string $slug): string
  {
    $slug = strtolower(trim($slug));
    $map = [
      'en' => 'en',
      'de' => 'de',
      'pl' => 'pl',
      'hu' => 'hu',
      'hr' => 'hr',
      'ro' => 'ro',
      'bg' => 'bg',
      'ru' => 'ru',
      'en-gb' => 'en',
      'en-us' => 'en',
      'pt-br' => 'pt',
      'pt-pt' => 'pt'
    ];
    return $map[$slug] ?? preg_replace('/[^a-z]/', '', $slug);
  }

  public static function googleSource(string $slug): string
  {
    return self::googleTarget($slug);
  }

  public static function deeplTarget(string $slug): string
  {
    $slug = strtolower(trim($slug));
    $map = [
      'de' => 'DE',
      'en' => 'EN-GB',
      'en-gb' => 'EN-GB',
      'en-us' => 'EN-US',
      'pl' => 'PL',
      'hu' => 'HU',
      'hr' => 'HR',
      'ro' => 'RO',
      'bg' => 'BG',
      'ru' => 'RU',
      'fr' => 'FR',
      'it' => 'IT',
      'es' => 'ES',
      'nl' => 'NL',
      'pt' => 'PT-PT',
      'pt-pt' => 'PT-PT',
      'pt-br' => 'PT-BR'
    ];
    return $map[$slug] ?? strtoupper($slug);
  }

  public static function deeplSource(string $slug): string
  {
    $slug = strtolower(trim($slug));
    $map = [
      'de' => 'DE',
      'en' => 'EN',
      'pl' => 'PL',
      'hu' => 'HU',
      'hr' => 'HR',
      'ro' => 'RO',
      'bg' => 'BG',
      'ru' => 'RU',
      'fr' => 'FR',
      'it' => 'IT',
      'es' => 'ES',
      'nl' => 'NL',
      'pt' => 'PT'
    ];
    return $map[$slug] ?? strtoupper($slug);
  }
}
