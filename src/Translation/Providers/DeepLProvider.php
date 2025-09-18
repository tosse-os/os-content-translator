<?php

namespace OSCT\Translation\Providers;

use OSCT\Domain\Repos\OptionRepo;

if (!defined('ABSPATH')) exit;

final class DeepLProvider implements ProviderInterface
{
    public function __construct(private OptionRepo $opt) {}

    public function name(): string
    {
        return 'deepl';
    }

    public function valid(): bool
    {
        $key = trim((string)$this->opt->get('api_deepl', ''));
        return $key !== '';
    }

    public function translate(string $text, string $target, string $source): string
    {
        $key = trim((string)$this->opt->get('api_deepl', ''));
        if ($key === '' || $text === '') return '';

        $endpoint = str_ends_with($key, ':fx') ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';

        $tgt = LangMap::deeplTarget($target);
        $src = LangMap::deeplSource($source);

        $resp = wp_remote_post($endpoint, [
            'timeout' => 25,
            'headers' => ['Authorization' => 'DeepL-Auth-Key ' . $key],
            'body'    => [
                'text'         => $text,
                'target_lang'  => $tgt,
                'source_lang'  => $src,
                'tag_handling' => 'html'
            ]
        ]);
        if (is_wp_error($resp)) {
            \OSCT\Core\Debug::add(['event' => 'deepl_error', 'reason' => 'wp_error', 'msg' => $resp->get_error_message()]);
            return '';
        }
        $code = wp_remote_retrieve_response_code($resp);
        $bodyStr = wp_remote_retrieve_body($resp);
        if ($code !== 200) {
            \OSCT\Core\Debug::add(['event' => 'deepl_error', 'reason' => 'http_' . $code, 'body' => mb_substr($bodyStr, 0, 300)]);
            return '';
        }
        $body = json_decode($bodyStr, true);
        if (!isset($body['translations'][0]['text'])) {
            \OSCT\Core\Debug::add(['event' => 'deepl_error', 'reason' => 'no_field', 'body' => mb_substr($bodyStr, 0, 200)]);
            return '';
        }
        return (string)$body['translations'][0]['text'];
    }
}
