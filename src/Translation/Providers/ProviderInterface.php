<?php

namespace OSCT\Translation\Providers;

if (!defined('ABSPATH')) exit;

interface ProviderInterface
{
    public function name(): string;
    public function valid(): bool;
    public function translate(string $text, string $target, string $source): string;
}
