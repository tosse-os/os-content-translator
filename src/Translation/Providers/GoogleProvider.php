<?php

namespace OSCT\Translation\Providers;

use OSCT\Domain\Repos\OptionRepo;

if (!defined('ABSPATH')) exit;

final class GoogleProvider implements ProviderInterface
{
    public function __construct(private OptionRepo $opt) {}

    public function name(): string
    {
        return 'google';
    }

    public function valid(): bool
    {
        $key = trim((string)$this->opt->get('api_google', ''));
        return $key !== '';
    }

    public function translate(string $text, string $target, string $source): string
    {
        $key = trim((string)$this->opt->get('api_google', ''));
        if ($key === '' || $text === '') return '';

        $tgt = LangMap::googleTarget($target);
        $src = LangMap::googleSource($source);

        $chunks = $this->chunk($text, 4500);
        $out = [];

        foreach ($chunks as $chunk) {
            $url = add_query_arg([
                'key' => $key
            ], 'https://translation.googleapis.com/language/translate/v2');

            $resp = wp_remote_post($url, [
                'timeout' => 25,
                'headers' => ['Accept' => 'application/json'],
                'body'    => [
                    'q'      => $chunk,
                    'target' => $tgt,
                    'source' => $src,
                    'format' => 'html'
                ]
            ]);

            if (is_wp_error($resp)) {
                \OSCT\Core\Debug::add(['event' => 'google_error', 'reason' => 'wp_error', 'msg' => $resp->get_error_message()]);
                return '';
            }
            $code = wp_remote_retrieve_response_code($resp);
            $bodyStr = wp_remote_retrieve_body($resp);
            if ($code !== 200) {
                \OSCT\Core\Debug::add(['event' => 'google_error', 'reason' => 'http_' . $code, 'body' => mb_substr($bodyStr, 0, 300)]);
                return '';
            }

            $body = json_decode($bodyStr, true);
            if (!isset($body['data']['translations'][0]['translatedText'])) {
                \OSCT\Core\Debug::add(['event' => 'google_error', 'reason' => 'no_field', 'body' => mb_substr($bodyStr, 0, 200)]);
                return '';
            }
            $out[] = (string)$body['data']['translations'][0]['translatedText'];
        }

        return implode('', $out);
    }

    private function chunk(string $html, int $limit): array
    {
        if (mb_strlen($html) <= $limit) return [$html];
        $parts = [];
        $buffer = '';
        $segments = preg_split('/(<\/p>)/iu', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($segments as $seg) {
            $candidate = $buffer . $seg;
            if (mb_strlen($candidate) > $limit && $buffer !== '') {
                $parts[] = $buffer;
                $buffer = $seg;
            } else {
                $buffer = $candidate;
            }
        }
        if ($buffer !== '') $parts[] = $buffer;

        $final = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) <= $limit) {
                $final[] = $p;
                continue;
            }
            $final = array_merge($final, $this->chunkByWords($p, $limit));
        }
        return $final;
    }

    private function chunkByWords(string $text, int $limit): array
    {
        $out = [];
        $buf = '';
        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($tokens as $tok) {
            $candidate = $buf . $tok;
            if (mb_strlen($candidate) > $limit && $buf !== '') {
                $out[] = $buf;
                $buf = $tok;
            } else {
                $buf = $candidate;
            }
        }
        if ($buf !== '') $out[] = $buf;
        return $out;
    }
}
