<?php
if (!defined('ABSPATH')) exit;

function osct_api_get_settings() {
    $o = get_option('osct_settings', []);
    $o += ['api_google'=>'','provider_default'=>'google','provider_override'=>[]];
    return $o;
}

function osct_api_resolve_provider_for_lang($lang) {
    $o = osct_api_get_settings();
    if (!empty($o['provider_override'][$lang])) return $o['provider_override'][$lang];
    return $o['provider_default'];
}

function osct_api_translate_google($text, $target, $source = '') {
    $o = get_option('osct_settings', []);
    $key = trim($o['api_google']);
    if ($key === '') return ['ok'=>false,'text'=>'','error'=>'Google API-Key fehlt'];

    $endpoint = 'https://translation.googleapis.com/language/translate/v2';
    $body = ['q'=>$text,'target'=>$target];
    if (!empty($source)) $body['source'] = $source;
    $url = add_query_arg(['key'=>$key], $endpoint);

    $headers = ['Referer' => home_url('/')];

    $res = wp_remote_post($url, ['timeout'=>30,'body'=>$body,'headers'=>$headers]);
    if (is_wp_error($res)) return ['ok'=>false,'text'=>'','error'=>$res->get_error_message()];

    $code = wp_remote_retrieve_response_code($res);
    $raw  = wp_remote_retrieve_body($res);
    if ($code !== 200) return ['ok'=>false,'text'=>'','error'=>'HTTP '.$code.' '.$raw];

    $json = json_decode($raw, true);
    if (!isset($json['data']['translations'][0]['translatedText'])) {
        return ['ok'=>false,'text'=>'','error'=>'Unerwartete Antwort'];
    }
    return ['ok'=>true,'text'=>$json['data']['translations'][0]['translatedText'],'error'=>''];
}


function osct_api_translate($text, $target, $source = '') {
    $provider = osct_api_resolve_provider_for_lang($target);
    if ($provider === 'google') return osct_api_translate_google($text, $target, $source);
    return ['ok'=>false,'text'=>'','error'=>'Provider nicht implementiert'];
}
