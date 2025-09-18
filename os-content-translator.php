<?php
/*
Plugin Name: OS Content Translator
Description: Automatisiertes Übersetzungsmodul mit Polylang-Integration (modular).
Version: 2.0.0
Author: ORANGE SERVICES
*/
if (!defined('ABSPATH')) exit;

define('OSCT_DIR', plugin_dir_path(__FILE__));
define('OSCT_URL', plugin_dir_url(__FILE__));
define('OSCT_NS', 'OSCT');

//define('OSCT_TEST_JOB_ID', '7e526cb0-b8c6-4c4d-9e4b-98e2428757c8');

require_once OSCT_DIR . 'src/Core/Autoloader.php';
OSCT\Core\Autoloader::register();

register_activation_hook(__FILE__, function () {
    OSCT\Core\Installer::install();
});

add_action('plugins_loaded', function () {
    (new OSCT\Core\Hooks())->register();
}, 20);

//HM Jobs Importer
add_action('init', function () {
    if (!function_exists('pll_register_string')) return;

    $GLOBALS['os_pll_defaults'] = [
        'Suchen nach' => [
            'en' => 'Search for',
            'ro' => 'Căutare după',
            'pl' => 'Szukaj',
            'hu' => 'Keresés',
            'bg' => 'Търсене на',
            'de' => 'Suchen nach',
            'hr' => 'Pretraži',
        ],
        'Jobtitel oder Ort...' => [
            'en' => 'Job title or location...',
            'ro' => 'Titlu job sau locație...',
            'pl' => 'Stanowisko lub lokalizacja...',
            'hu' => 'Állás megnevezése vagy hely...',
            'bg' => 'Длъжност или място...',
            'de' => 'Jobtitel oder Ort...',
            'hr' => 'Naziv posla ili mjesto...',
        ],
        'von' => [
            'en' => 'of',
            'ro' => 'din',
            'pl' => 'z',
            'hu' => 'ből',
            'bg' => 'от',
            'de' => 'von',
            'hr' => 'od',
        ],
        'Stellenangeboten' => [
            'en' => 'job offers',
            'ro' => 'oferte de muncă',
            'pl' => 'oferty pracy',
            'hu' => 'állásajánlatok',
            'bg' => 'обяви за работа',
            'de' => 'Stellenangeboten',
            'hr' => 'ponude poslova',
        ],
    ];

    foreach (array_keys($GLOBALS['os_pll_defaults']) as $original) {
        pll_register_string(sanitize_title($original), $original, 'Theme-Texte');
    }
});

function os_pll($text)
{
    $translated = function_exists('pll__') ? pll__($text) : $text;
    if ($translated !== $text) return $translated;
    $lang = function_exists('pll_current_language') ? pll_current_language() : 'de';
    if (isset($GLOBALS['os_pll_defaults'][$text][$lang])) return $GLOBALS['os_pll_defaults'][$text][$lang];
    return $text;
}

function eos_pll($text)
{
    echo os_pll($text);
}
