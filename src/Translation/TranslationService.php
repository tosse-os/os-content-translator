<?php

namespace OSCT\Translation;

use OSCT\Core\Debug;
use OSCT\Domain\Repos\OptionRepo;
use OSCT\Domain\Repos\LanguageRepo;
use OSCT\Domain\Repos\ContentRepo;
use OSCT\Domain\Repos\LogRepo;
use OSCT\Translation\Providers\ProviderInterface;
use OSCT\Translation\Providers\GoogleProvider;
use OSCT\Translation\Providers\DeepLProvider;
use OSCT\Translation\JobsTranslator;
use OSCT\Translation\Jobs\JobsRunner;


if (!defined('ABSPATH')) exit;

final class TranslationService
{
    private HashService $hash;
    private ProviderInterface $provider;
    private LogRepo $logs;
    private string $runId = '';
    private bool $force = false;

    public function __construct(
        private OptionRepo $opt,
        private LanguageRepo $langs,
        private ContentRepo $content,
        ProviderInterface $provider
    ) {
        $this->hash = new HashService();
        $this->provider = $provider;
        $this->logs = new LogRepo();
    }

    public function setProvider(ProviderInterface $p): void
    {
        $this->provider = $p;
    }
    public function setRunId(string $id): void
    {
        $this->runId = $id;
    }

    private function currentRunId(): string
    {
        if ($this->runId === '') {
            $this->runId = wp_generate_uuid4();
        }

        return $this->runId;
    }
    public function setForce(bool $on): void
    {
        $this->force = $on;
    }

    private function pickProviders(string $lang): array
    {
        $over = (array)$this->opt->get('provider_override', []);
        $default = $this->opt->get('provider_default', 'google');

        $pGoogle = new \OSCT\Translation\Providers\GoogleProvider($this->opt);
        $pDeepL  = new \OSCT\Translation\Providers\DeepLProvider($this->opt);

        $byName = [
            'google' => $pGoogle,
            'deepl'  => $pDeepL,
        ];

        $order = [];
        if (!empty($over[$lang]) && isset($byName[$over[$lang]])) {
            $order[] = $byName[$over[$lang]];
        }
        if (isset($byName[$default])) {
            $order[] = $byName[$default];
        }
        foreach ($byName as $p) {
            $order[] = $p;
        }

        $uniq = [];
        $out  = [];
        foreach ($order as $p) {
            $n = $p->name();
            if (isset($uniq[$n])) continue;
            $uniq[$n] = true;
            $out[] = $p;
        }
        return $out;
    }

    private function providerForLang(string $lang): ?\OSCT\Translation\Providers\ProviderInterface
    {
        foreach ($this->pickProviders($lang) as $p) {
            if ($p->valid()) return $p;
        }
        return null;
    }

    private function t(string $text, string $lang, string $source): string
    {
        $candidates = $this->pickProviders($lang);
        $tried = [];
        foreach ($candidates as $p) {
            $tried[] = $p->name();
            if (!$p->valid()) continue;
            $res = $p->translate($text, $lang, $source);
            if ($res !== '') {
                \OSCT\Core\Debug::add([
                    'event'    => 'provider_ok',
                    'provider' => $p->name(),
                    'target'   => $lang,
                    'len_in'   => mb_strlen($text),
                    'len_out'  => mb_strlen($res),
                ]);
                return $res;
            }
        }
        \OSCT\Core\Debug::add([
            'event'    => 'no_valid_provider',
            'target'   => $lang,
            'tried'    => implode(',', $tried),
        ]);
        return '';
    }

    public function state(int $srcId, string $lang): string
    {
        if (!function_exists('pll_get_post')) return 'missing';
        $tgt = pll_get_post($srcId, $lang);
        if (!$tgt) return 'missing';
        $src = $this->hash->srcHash($srcId);
        $tgtH = $this->hash->getTargetHash($tgt, $lang);
        return ($tgtH && $tgtH === $src) ? 'ok' : 'stale';
    }

    public function translateRun(?array $onlyIds = null, ?array $what = null): array
    {
        $what = array_merge([
            'menu_pages' => true,
            'extra_pages' => true,
            'blocks'     => true,
            'jobs'       => true,
            'test'       => false,
        ], is_array($what) ? $what : []);

        $o = $this->opt->all();
        $idsMenu  = array_map('intval', (array)($o['page_whitelist'] ?? []));
        $idsExtra = array_map('intval', (array)($o['page_whitelist_extra'] ?? []));
        $idsBlocks = $what['blocks'] ? array_map('intval', (array)($o['block_whitelist'] ?? [])) : [];

        if ($what['test']) {
            if ($what['menu_pages'])  $idsMenu  = array_slice($idsMenu, 0, 2);
            if ($what['extra_pages']) $idsExtra = array_slice($idsExtra, 0, 2);
            if ($what['blocks'])      $idsBlocks = array_slice($idsBlocks, 0, 2);
        }

        if (is_array($onlyIds) && $onlyIds) {
            $only = array_map('intval', $onlyIds);
            $idsMenu   = array_values(array_intersect($idsMenu, $only));
            $idsExtra  = array_values(array_intersect($idsExtra, $only));
            $idsBlocks = array_values(array_intersect($idsBlocks, $only));
        }

        $idsPages = array_values(array_unique(array_merge($what['menu_pages'] ? $idsMenu : [], $what['extra_pages'] ? $idsExtra : [])));
        $all = array_values(array_unique(array_merge($idsPages, $idsBlocks)));

        $created = 0;
        $skipped = 0;
        $words   = 0;
        $chars   = 0;
        $jobsDone = 0;

        foreach ($all as $id) {
            $r = $this->translateSingle((int)$id);
            if (is_wp_error($r)) {
                $skipped++;
                continue;
            }
            $created += (int)$r['__created'];
            $skipped += (int)$r['__skipped'];
            $words   += (int)$r['__words'];
            $chars   += (int)$r['__chars'];
        }

        if (!empty($what['jobs'])) {
            $jr = new JobsRunner($this->opt, $this->langs, $this->logs);
            $jr->setTranslator(fn(string $t, string $l, string $s) => $this->t($t, $l, $s));
            $jr->setRunId($this->runId);
            $jr->setForce($this->force);

            // Testlauf → nur 2 Stellenanzeigen
            if (!empty($what['test'])) {
                $jr->setLimit(2);
            } elseif (!empty($what['jobs_limit'])) {
                $jr->setLimit((int)$what['jobs_limit']);
            }

            if (defined('OSCT_TEST_JOB_ID') && OSCT_TEST_JOB_ID) {
                $jr->setOnlyJobId(OSCT_TEST_JOB_ID);
            }

            $jres = $jr->translateAll();
            $created += (int)$jres['created'];
            $skipped += (int)$jres['skipped'];
            $words   += (int)$jres['words'];
            $chars   += (int)$jres['chars'];
            $jobsDone = $jres['created'] + $jres['skipped'];
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'total'   => count($all) + $jobsDone,
            'words'   => $words,
            'chars'   => $chars,
        ];
    }


    public function translateSingle(int $post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_status === 'trash' || !in_array($post->post_type, ['page', 'wp_block'], true))
            return new \WP_Error('invalid', 'Invalid post');
        if (!function_exists('pll_set_post_language')) return new \WP_Error('nopll', 'Polylang missing');

        $source = $this->langs->default();
        $targets = (array)$this->opt->get('languages_active', []);
        $review = (int)$this->opt->get('review_as_draft', 1) ? 'draft' : 'publish';
        $translateSlugs = (int)$this->opt->get('slug_translate', 0) === 1;

        $titleSrc   = get_the_title($post_id);
        $contentSrc = $post->post_content;
        $slugSrc    = $post->post_name;
        $srcHash    = $this->hash->srcHash($post_id);

        $map = [$source => $post_id];
        $sumWords = 0;
        $sumChars = 0;
        $didCreate = 0;
        $didSkip = 0;

        foreach ($targets as $lang) {
            if ($lang === $source) continue;

            $providerInstance = $this->providerForLang($lang);
            $provName = $providerInstance ? $providerInstance->name() : 'none';
            $existing = function_exists('pll_get_post') ? pll_get_post($post_id, $lang) : 0;
            $stateBefore = $this->state($post_id, $lang);
            $tgtHash = $existing ? $this->hash->getTargetHash($existing, $lang) : '';

            Debug::add([
                'event'       => 'pre',
                'post_id'     => $post_id,
                'post_type'   => $post->post_type,
                'source_lang' => $source,
                'target_lang' => $lang,
                'provider'    => $provName,
                'state_before' => $stateBefore,
                'src_hash'    => $srcHash,
                'tgt_hash'    => $tgtHash,
                'reason'      => $this->force ? 'force=1' : '',
            ]);

            if ($existing && $stateBefore === 'ok' && !$this->force) {
                $wtSrc = $this->countWords($titleSrc);
                $ctSrc = $this->countChars($titleSrc);
                $wcSrc = $this->countWords($contentSrc);
                $ccSrc = $this->countChars($contentSrc);

                $this->logs->insert([
                    'run_id' => $this->currentRunId(),
                    'post_id' => $post_id,
                    'post_type' => $post->post_type,
                    'source_lang' => $source,
                    'target_lang' => $lang,
                    'provider' => $provName,
                    'action' => 'skip',
                    'status' => 'ok',
                    'words_title' => $wtSrc,
                    'chars_title' => $ctSrc,
                    'words_content' => $wcSrc,
                    'chars_content' => $ccSrc,
                    'src_hash' => $srcHash,
                    'message' => 'OK',
                ]);

                Debug::add([
                    'event' => 'skip',
                    'post_id' => $post_id,
                    'post_type' => $post->post_type,
                    'source_lang' => $source,
                    'target_lang' => $lang,
                    'provider' => $provName,
                    'action' => 'skip',
                    'status' => 'ok',
                    'reason' => 'state=ok & force=0',
                    'state_before' => $stateBefore,
                    'src_hash' => $srcHash,
                    'tgt_hash' => $tgtHash,
                    'words_title' => $wtSrc,
                    'chars_title' => $ctSrc,
                    'words_content' => $wcSrc,
                    'chars_content' => $ccSrc,
                ]);

                $didSkip++;
                $map[$lang] = $existing;
                continue;
            }

            $titleTr = $this->t($titleSrc, $lang, $source);
            [$contentMasked, $scMap] = $this->protectShortcodes($contentSrc);
            $contentTrRaw = $this->t($contentMasked, $lang, $source);
            $contentTr = $this->restoreShortcodes($contentTrRaw, $scMap);

            $wt = $this->countWords($titleTr);
            $ct = $this->countChars($titleTr);
            $wc = $this->countWords($contentTr);
            $cc = $this->countChars($contentTr);

            if ($titleTr === '' && $contentTr === '') {
                $wtSrc = $this->countWords($titleSrc);
                $ctSrc = $this->countChars($titleSrc);
                $wcSrc = $this->countWords($contentSrc);
                $ccSrc = $this->countChars($contentSrc);

                $this->logs->insert([
                    'run_id' => $this->currentRunId(),
                    'post_id' => $post_id,
                    'post_type' => $post->post_type,
                    'source_lang' => $source,
                    'target_lang' => $lang,
                    'provider' => $provName,
                    'action' => 'skip',
                    'status' => 'empty',
                    'words_title' => $wtSrc,
                    'chars_title' => $ctSrc,
                    'words_content' => $wcSrc,
                    'chars_content' => $ccSrc,
                    'src_hash' => $srcHash,
                    'message' => 'No translation returned',
                ]);

                Debug::add([
                    'event' => 'skip',
                    'post_id' => $post_id,
                    'post_type' => $post->post_type,
                    'source_lang' => $source,
                    'target_lang' => $lang,
                    'provider' => $provName,
                    'action' => 'skip',
                    'status' => 'empty',
                    'reason' => 'No valid provider or empty response',
                    'state_before' => $stateBefore,
                    'src_hash' => $srcHash,
                    'tgt_hash' => $tgtHash,
                    'words_title' => $wtSrc,
                    'chars_title' => $ctSrc,
                    'words_content' => $wcSrc,
                    'chars_content' => $ccSrc,
                ]);

                $didSkip++;
                if ($existing) $map[$lang] = $existing;
                continue;
            }

            $sumWords += ($wt + $wc);
            $sumChars += ($ct + $cc);

            $newSlug = $slugSrc;
            if ($translateSlugs && $post->post_type === 'page') {
                $slugTr = $this->t($titleSrc, $lang, $source);
                if ($slugTr !== '') $newSlug = sanitize_title($slugTr);
            }

            if ($existing) {
                $res = wp_update_post([
                    'ID' => $existing,
                    'post_title'  => $titleTr !== '' ? wp_specialchars_decode($titleTr) : $titleSrc,
                    'post_content' => $contentTr !== '' ? $contentTr : $contentSrc,
                    'post_name'   => $post->post_type === 'page' ? $newSlug : $slugSrc
                ], true);

                $this->hash->setTargetHash($existing, $lang, $srcHash);

                $this->logs->insert([
                    'run_id' => $this->currentRunId(),
                    'post_id' => $post_id,
                    'post_type' => $post->post_type,
                    'source_lang' => $source,
                    'target_lang' => $lang,
                    'provider' => $provName,
                    'action' => 'update',
                    'status' => is_wp_error($res) ? 'error' : 'ok',
                    'words_title' => $wt,
                    'chars_title' => $ct,
                    'words_content' => $wc,
                    'chars_content' => $cc,
                    'src_hash' => $srcHash,
                    'message' => is_wp_error($res) ? $res->get_error_message() : 'Updated',
                ]);

                Debug::add([
                    'event' => 'update',
                    'post_id' => $post_id,
                    'post_type' => $post->post_type,
                    'source_lang' => $source,
                    'target_lang' => $lang,
                    'provider' => $provName,
                    'action' => 'update',
                    'status' => is_wp_error($res) ? 'error' : 'ok',
                    'reason' => 'existing',
                    'state_before' => $stateBefore,
                    'src_hash' => $srcHash,
                    'tgt_hash' => $this->hash->getTargetHash($existing, $lang),
                    'words_title' => $wt,
                    'chars_title' => $ct,
                    'words_content' => $wc,
                    'chars_content' => $cc,
                ]);

                $map[$lang] = $existing;
            } else {
                $newId = wp_insert_post([
                    'post_type'  => $post->post_type,
                    'post_status' => $review,
                    'post_title' => $titleTr !== '' ? wp_specialchars_decode($titleTr) : $titleSrc,
                    'post_content' => $contentTr !== '' ? $contentTr : $contentSrc,
                    'post_name'  => $post->post_type === 'page' ? $newSlug : $slugSrc,
                    'post_author' => get_current_user_id(),
                    'post_parent' => $post->post_parent
                ], true);

                if (is_wp_error($newId)) {
                    $this->logs->insert([
                        'run_id' => $this->currentRunId(),
                        'post_id' => $post_id,
                        'post_type' => $post->post_type,
                        'source_lang' => $source,
                        'target_lang' => $lang,
                        'provider' => $provName,
                        'action' => 'create',
                        'status' => 'error',
                        'words_title' => $wt,
                        'chars_title' => $ct,
                        'words_content' => $wc,
                        'chars_content' => $cc,
                        'src_hash' => $srcHash,
                        'message' => $newId->get_error_message(),
                    ]);

                    Debug::add([
                        'event' => 'create',
                        'post_id' => $post_id,
                        'post_type' => $post->post_type,
                        'source_lang' => $source,
                        'target_lang' => $lang,
                        'provider' => $provName,
                        'action' => 'create',
                        'status' => 'error',
                        'reason' => 'insert_failed',
                        'state_before' => $stateBefore,
                        'src_hash' => $srcHash,
                        'tgt_hash' => '',
                        'words_title' => $wt,
                        'chars_title' => $ct,
                        'words_content' => $wc,
                        'chars_content' => $cc,
                    ]);

                    $didSkip++;
                    continue;
                }

                pll_set_post_language($newId, $lang);
                $this->copyMeta($post_id, $newId);
                $this->hash->setTargetHash($newId, $lang, $srcHash);

                $this->logs->insert([
                    'run_id' => $this->currentRunId(),
                    'post_id' => $post_id,
                    'post_type' => $post->post_type,
                    'source_lang' => $source,
                    'target_lang' => $lang,
                    'provider' => $provName,
                    'action' => 'create',
                    'status' => 'ok',
                    'words_title' => $wt,
                    'chars_title' => $ct,
                    'words_content' => $wc,
                    'chars_content' => $cc,
                    'src_hash' => $srcHash,
                    'message' => 'Created',
                ]);

                Debug::add([
                    'event' => 'create',
                    'post_id' => $post_id,
                    'post_type' => $post->post_type,
                    'source_lang' => $source,
                    'target_lang' => $lang,
                    'provider' => $provName,
                    'action' => 'create',
                    'status' => 'ok',
                    'reason' => 'new',
                    'state_before' => $stateBefore,
                    'src_hash' => $srcHash,
                    'tgt_hash' => $this->hash->getTargetHash($newId, $lang),
                    'words_title' => $wt,
                    'chars_title' => $ct,
                    'words_content' => $wc,
                    'chars_content' => $cc,
                ]);

                $didCreate++;
                $map[$lang] = $newId;
            }
        }

        if (count($map) > 1 && function_exists('pll_save_post_translations')) pll_save_post_translations($map);

        return [
            'map' => $map,
            '__words' => $sumWords,
            '__chars' => $sumChars,
            '__created' => $didCreate,
            '__skipped' => $didSkip
        ];
    }

    private function copyMeta(int $src, int $dst): void
    {
        $meta = get_post_meta($src);
        foreach ($meta as $k => $vals) {
            if (strpos($k, '_edit_lock') === 0 || strpos($k, '_edit_last') === 0) continue;
            foreach ($vals as $v) add_post_meta($dst, $k, maybe_unserialize($v));
        }
    }

    private function whitelists(): array
    {
        $o = $this->opt->all();
        $ids    = array_values(array_unique(array_map('intval', array_merge((array)$o['page_whitelist'], (array)$o['page_whitelist_extra']))));
        $blocks = array_values(array_unique(array_map('intval', (array)$o['block_whitelist'])));
        return [$ids, $blocks];
    }

    private function countWords(string $html): int
    {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, get_bloginfo('charset'));
        $text = preg_replace('/[\pZ\pC]+/u', ' ', $text);
        $arr = preg_split('/\s+/u', trim($text));
        return $text === '' ? 0 : count($arr);
    }
    private function countChars(string $html): int
    {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, get_bloginfo('charset'));
        return mb_strlen($text);
    }
    private function protectShortcodes(string $content): array
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

    private function restoreShortcodes(string $content, array $map): string
    {
        if (empty($map)) return $content;
        return strtr($content, $map);
    }
}
