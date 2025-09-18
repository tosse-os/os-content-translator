<?php
namespace OSCT\Core;

if (!defined('ABSPATH')) exit;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([__CLASS__, 'load'], true, true);
    }

    public static function load(string $class): void
    {
        // nur OSCT\ Klassen laden
        if (strncmp($class, 'OSCT\\', 5) !== 0) return;

        // OSCT\Domain\Repos\OptionRepo  ->  Domain/Repos/OptionRepo.php
        $relative = substr($class, 5);
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        // Plugin-Root: von /src/Core/ zwei Ebenen hoch -> /os-content-translator
        $pluginRoot = rtrim(\dirname(__DIR__, 2), '/\\') . DIRECTORY_SEPARATOR;

        // endgültiger Pfad innerhalb von /src/
        $file = $pluginRoot . 'src' . DIRECTORY_SEPARATOR . $relative;

        if (is_file($file) && is_readable($file)) {
            require_once $file;
        }
    }
}
