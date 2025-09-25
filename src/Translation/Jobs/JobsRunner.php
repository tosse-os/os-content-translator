<?php

namespace OSCT\Translation\Jobs;

use OSCT\Domain\Repos\OptionRepo;
use OSCT\Domain\Repos\LanguageRepo;
use OSCT\Domain\Repos\JobsRepo;
use OSCT\Domain\Repos\LogRepo;

if (!defined('ABSPATH')) exit;

final class JobsRunner
{
  private JobsRepo $repo;
  private $translator;
  private string $runId = '';
  private bool $force   = false;
  private ?string $onlyJobId = null;
  private ?int $limit = null;

  public function __construct(
    private OptionRepo $opt,
    private LanguageRepo $langs,
    private LogRepo $logs
  ) {
    $this->repo = new JobsRepo();
    $this->translator = fn(string $t, string $l, string $s) => $t; // no-op Fallback
  }

  private function currentRunId(): string
  {
    if ($this->runId === '') {
      $this->runId = wp_generate_uuid4();
    }

    return $this->runId;
  }

  public function setTranslator(callable $fn): void
  {
    $this->translator = $fn;
  }
  public function setRunId(string $id): void
  {
    $this->runId = $id;
  }
  public function setForce(bool $on): void
  {
    $this->force = $on;
  }
  public function setOnlyJobId(?string $id): void
  {
    $this->onlyJobId = $id ? trim($id) : null;
  }
  public function setLimit(?int $n): void
  {
    $this->limit = $n ? max(1, (int)$n) : null;
  }

  public function translateAll(): array
  {
    $source  = $this->langs->default();
    $targets = (array)$this->opt->get('languages_active', []);
    $rows    = $this->repo->all();

    if ($this->onlyJobId) {
      $rows = array_values(array_filter($rows, fn($r) => (string)$r['job_id'] === $this->onlyJobId));
    }

    // deterministische Reihenfolge
    usort($rows, fn($a, $b) => strnatcmp((string)$a['job_id'], (string)$b['job_id']));

    $limited = $rows;
    if ($this->limit !== null) {
      $limited = [];

      foreach ($rows as $r) {
        if (count($limited) >= $this->limit) break;

        $needsTranslation = $this->force;

        if (!$needsTranslation) {
          $jobId   = (string)$r['job_id'];
          $nameSrc = (string)$r['job_name'];
          $val     = $this->decodeJobValue($r['job_value']);

          $srcHash = $this->srcHash($nameSrc, $val);

          foreach ($targets as $lang) {
            if ($lang === $source) continue;

            $existing = $this->repo->getTranslation($jobId, $lang);

            if (!$existing || empty($existing['src_hash']) || $existing['src_hash'] !== $srcHash) {
              $needsTranslation = true;
              break;
            }
          }
        }

        if ($needsTranslation) {
          $limited[] = $r;
        }
      }
    }

    // Batch-Info: welche Jobs laufen in diesem Durchgang?
    $pickedIds = array_map(fn($r) => (string)$r['job_id'], $limited);
    $this->logs->insert([
      'run_id'         => $this->currentRunId(),
      'post_id'        => 0,
      'post_type'      => 'job',
      'source_lang'    => $source,
      'target_lang'    => '-',        // marker
      'provider'       => 'mixed',
      'action'         => 'batch',
      'status'         => 'info',
      'words_title'    => 0,
      'chars_title'    => 0,
      'words_content'  => 0,
      'chars_content'  => 0,
      'src_hash'       => '',
      'message'        => sprintf(
        'Picked %d jobs%s: %s',
        count($limited),
        $this->limit !== null ? " (limit={$this->limit})" : '',
        implode(',', array_slice($pickedIds, 0, 100))
      ),
    ]);

    $created = 0;
    $skipped = 0;
    $wordsTitleTotal = 0;
    $charsTitleTotal = 0;
    $wordsContentTotal = 0;
    $charsContentTotal = 0;

    foreach ($limited as $r) {
      $jobId   = (string)$r['job_id'];
      $nameSrc = (string)$r['job_name'];
      $val     = $this->decodeJobValue($r['job_value']);

      $srcHash = $this->srcHash($nameSrc, $val);
      $mSrc    = $this->countAll($nameSrc, $val);

      // BEGIN-Log pro Job (damit in „Schritte“ gruppierbar)
      $this->logs->insert([
        'run_id'         => $this->currentRunId(),
        'post_id'        => 0,
        'post_type'      => 'job',
        'source_lang'    => $source,
        'target_lang'    => '-', // marker
        'provider'       => 'mixed',
        'action'         => 'begin',
        'status'         => 'info',
        'words_title'    => $mSrc['wt'],
        'chars_title'    => $mSrc['ct'],
        'words_content'  => $mSrc['wc'],
        'chars_content'  => $mSrc['cc'],
        'src_hash'       => $srcHash,
        'message'        => sprintf('job_id=%s; title="%s"', $jobId, mb_substr($nameSrc, 0, 180)),
      ]);

      $fieldsPlain = [
        'Bezeichnung',
        'BezeichnungAusschreibung',
        'MetaDescription',
        'ArbeitgeberleistungHeader',
        'AufgabenHeader',
        'FachlicheAnforderungenHeader',
        'KontaktTextHeader',
        'StellenzielHeader',
        'PersoenlicheAnforderungenHeader',
        'PerspektivenHeader',
        'UnternehmensbedeutungHeader',
        'ArbeitgebervorstellungHeader'
      ];
      $fieldsHtml  = ['Arbeitgeberleistung', 'Aufgaben', 'FachlicheAnforderungen', 'KontaktText'];

      foreach ($targets as $lang) {
        if ($lang === $source) continue;

        // Zustand der bestehenden Übersetzung
        $existing    = $this->repo->getTranslation($jobId, $lang);
        $stateBefore = $existing && !empty($existing['src_hash']) && $existing['src_hash'] === $srcHash ? 'ok' : 'stale';

        if ($existing && $stateBefore === 'ok' && !$this->force) {
          $mm = $this->countAll($nameSrc, $val);
          $this->logs->insert([
            'run_id'         => $this->currentRunId(),
            'post_id'        => 0,
            'post_type'      => 'job',
            'source_lang'    => $source,
            'target_lang'    => $lang,
            'provider'       => 'mixed',
            'action'         => 'skip',
            'status'         => 'ok',
            'words_title'    => $mm['wt'],
            'chars_title'    => $mm['ct'],
            'words_content'  => $mm['wc'],
            'chars_content'  => $mm['cc'],
            'src_hash'       => $srcHash,
            'message'        => "OK; job_id={$jobId}",
          ]);
          $skipped++;
          continue;
        }

        // feldweise Übersetzung
        $valTr = $val;
        $nameTrLang = $this->t($nameSrc, $lang, $source);

        foreach ($fieldsPlain as $k) {
          if (isset($val[$k]) && is_string($val[$k])) {
            $tr = $this->t($val[$k], $lang, $source);
            $valTr[$k] = $tr !== '' ? $tr : $val[$k];
          }
        }
        foreach ($fieldsHtml as $k) {
          if (isset($val[$k]) && is_string($val[$k])) {
            [$masked, $map] = $this->protectShortcodes($val[$k]);
            $trRaw = $this->t($masked, $lang, $source);
            $tr    = $trRaw !== '' ? $trRaw : $val[$k];
            $valTr[$k] = $this->restoreShortcodes($tr, $map);
          }
        }
        if (isset($valTr['MetaDescription']) && is_string($valTr['MetaDescription'])) {
          $valTr['MetaDescription'] = mb_substr($valTr['MetaDescription'], 0, 170);
        }

        // Slug/Links
        $oldSlug   = isset($val['LinkSlug']) && is_string($val['LinkSlug']) ? $val['LinkSlug'] : sanitize_title($nameSrc);
        $plz       = isset($val['EinsatzortPlz']) ? (string)$val['EinsatzortPlz'] : '';
        $ort       = isset($val['EinsatzortOrt']) ? (string)$val['EinsatzortOrt'] : '';
        $baseTitle = $nameTrLang !== '' ? $nameTrLang : $nameSrc;
        $newSlug   = sanitize_title($baseTitle . ($plz !== '' ? '-' . $plz : '') . ($ort !== '' ? '-' . $ort : ''));
        $valTr['LinkSlug'] = $newSlug;

        foreach (array_merge($fieldsHtml, $fieldsPlain) as $k) {
          if (isset($valTr[$k]) && is_string($valTr[$k])) {
            $valTr[$k] = $this->rewriteLinks($valTr[$k], $oldSlug, $newSlug, $lang);
          }
        }
        if (!empty($val['JsonLd']) && is_string($val['JsonLd'])) {
          $j = json_decode($val['JsonLd'], true);
          if (is_array($j)) {
            if ($baseTitle !== '') $j['title'] = $baseTitle;
            if (isset($j['url'])) $j['url'] = $this->rewriteUrl((string)$j['url'], $oldSlug, $newSlug, $lang);
            $valTr['JsonLd'] = wp_json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          } else {
            $valTr['JsonLd'] = $this->rewriteLinks($val['JsonLd'], $oldSlug, $newSlug, $lang);
          }
        }

        // speichern
        $createdAt = $this->pickCreatedAt($r, $val);
        $this->repo->upsert($jobId, $lang, $baseTitle, $valTr, $newSlug, $srcHash, $createdAt);

        // Log/Metriken
        $mm    = $this->countAll($baseTitle, $valTr);
        $wordsTitleTotal   += $mm['wt'];
        $charsTitleTotal   += $mm['ct'];
        $wordsContentTotal += $mm['wc'];
        $charsContentTotal += $mm['cc'];
        $action = ($existing ? 'update' : 'create');

        $this->logs->insert([
          'run_id'         => $this->currentRunId(),
          'post_id'        => 0,
          'post_type'      => 'job',
          'source_lang'    => $source,
          'target_lang'    => $lang,
          'provider'       => 'mixed',
          'action'         => $action,
          'status'         => 'ok',
          'words_title'    => $mm['wt'],
          'chars_title'    => $mm['ct'],
          'words_content'  => $mm['wc'],
          'chars_content'  => $mm['cc'],
          'src_hash'       => $srcHash,
          'message'        => ucfirst($action) . "; job_id={$jobId}",
        ]);

        if (!$existing) $created++;
      }
    }

    $totalWords = $wordsTitleTotal + $wordsContentTotal;
    $totalChars = $charsTitleTotal + $charsContentTotal;

    $this->logs->insert([
      'run_id'         => $this->currentRunId(),
      'post_id'        => 0,
      'post_type'      => 'job',
      'source_lang'    => $source,
      'target_lang'    => '-',
      'provider'       => 'mixed',
      'action'         => 'summary',
      'status'         => 'info',
      'words_title'    => $wordsTitleTotal,
      'chars_title'    => $charsTitleTotal,
      'words_content'  => $wordsContentTotal,
      'chars_content'  => $charsContentTotal,
      'src_hash'       => '',
      'message'        => sprintf(
        'Summary; jobs=%d; created=%d; skipped=%d; words=%d; chars=%d; words_title=%d; words_content=%d; chars_title=%d; chars_content=%d',
        $created + $skipped,
        $created,
        $skipped,
        $totalWords,
        $totalChars,
        $wordsTitleTotal,
        $wordsContentTotal,
        $charsTitleTotal,
        $charsContentTotal
      ),
    ]);

    return [
      'created' => $created,
      'skipped' => $skipped,
      'words'   => $totalWords,
      'chars'   => $totalChars,
    ];
  }

  private function t(string $text, string $lang, string $source): string
  {
    return call_user_func($this->translator, $text, $lang, $source);
  }

  private function srcHash(string $name, array $val): string
  {
    $pick = [
      'name' => $name,
      'Bezeichnung' => $val['Bezeichnung'] ?? '',
      'BezeichnungAusschreibung' => $val['BezeichnungAusschreibung'] ?? '',
      'Arbeitgeberleistung' => $val['Arbeitgeberleistung'] ?? '',
      'Aufgaben' => $val['Aufgaben'] ?? '',
      'FachlicheAnforderungen' => $val['FachlicheAnforderungen'] ?? '',
      'KontaktText' => $val['KontaktText'] ?? '',
      'ArbeitgeberleistungHeader' => $val['ArbeitgeberleistungHeader'] ?? '',
      'AufgabenHeader' => $val['AufgabenHeader'] ?? '',
      'FachlicheAnforderungenHeader' => $val['FachlicheAnforderungenHeader'] ?? '',
      'KontaktTextHeader' => $val['KontaktTextHeader'] ?? '',
      'StellenzielHeader' => $val['StellenzielHeader'] ?? '',
      'PersoenlicheAnforderungenHeader' => $val['PersoenlicheAnforderungenHeader'] ?? '',
      'PerspektivenHeader' => $val['PerspektivenHeader'] ?? '',
      'UnternehmensbedeutungHeader' => $val['UnternehmensbedeutungHeader'] ?? '',
      'ArbeitgebervorstellungHeader' => $val['ArbeitgebervorstellungHeader'] ?? '',
      'MetaDescription' => $val['MetaDescription'] ?? '',
      'LinkSlug' => $val['LinkSlug'] ?? '',
      'EinsatzortPlz' => $val['EinsatzortPlz'] ?? '',
      'EinsatzortOrt' => $val['EinsatzortOrt'] ?? '',
    ];
    return sha1(wp_json_encode($pick));
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

  private function rewriteLinks(string $html, string $oldSlug, string $newSlug, string $lang): string
  {
    $out = str_replace($oldSlug, $newSlug, $html);
    $home = trailingslashit(home_url());
    if (function_exists('pll_home_url')) {
      $langHome = trailingslashit(pll_home_url($lang));
      $out = str_replace($home, $langHome, $out);
    }
    return $out;
  }

  private function rewriteUrl(string $url, string $oldSlug, string $newSlug, string $lang): string
  {
    $u = str_replace($oldSlug, $newSlug, $url);
    $home = trailingslashit(home_url());
    if (function_exists('pll_home_url')) {
      $langHome = trailingslashit(pll_home_url($lang));
      if (str_starts_with($u, $home)) $u = $langHome . substr($u, strlen($home));
    }
    return $u;
  }

  private function decodeJobValue($raw): array
  {
    $val = maybe_unserialize($raw);
    $val = $this->normalizeDecodedValue($val);

    if (!is_array($val)) {
      $decoded = is_string($raw) ? json_decode($raw, true) : null;
      $val = is_array($decoded) ? $decoded : [];
    }
    return $val;
  }

  private function normalizeDecodedValue($value)
  {
    if (is_object($value)) {
      $value = get_object_vars($value);
    }

    if (is_array($value)) {
      $normalized = [];
      foreach ($value as $k => $v) {
        $normalized[$k] = $this->normalizeDecodedValue($v);
      }
      return $normalized;
    }

    return $value;
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

  private function countAll(string $title, array $val): array
  {
    $wt = $this->countWords($title);
    $ct = $this->countChars($title);
    $parts = $this->collectContentStrings($val);
    $buf = empty($parts) ? '' : (' ' . implode(' ', $parts));
    $wc = $this->countWords($buf);
    $cc = $this->countChars($buf);
    return ['wt' => $wt, 'ct' => $ct, 'wc' => $wc, 'cc' => $cc];
  }

  private function collectContentStrings($value, ?string $key = null): array
  {
    $skipKeys = ['LinkSlug', 'EinsatzortPlz', 'EinsatzortOrt'];
    if (is_string($key) && in_array($key, $skipKeys, true)) {
      return [];
    }

    if (is_string($value)) {
      $value = trim($value);
      if ($value === '') return [];
      if ($key === 'JsonLd') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
          return $this->collectContentStrings($decoded);
        }
      }
      return [$value];
    }

    if (is_object($value)) {
      $value = get_object_vars($value);
    }

    if (is_array($value)) {
      $parts = [];
      foreach ($value as $k => $v) {
        if (is_string($k) && str_starts_with($k, '@')) {
          continue;
        }
        foreach ($this->collectContentStrings($v, is_string($k) ? $k : null) as $piece) {
          $parts[] = $piece;
        }
      }
      return $parts;
    }

    return [];
  }

  private function toMysqlDate(?string $s): ?string
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

  private function pickCreatedAt(array $row, array $val): string
  {
    $c = $row['created_at'] ?? null;
    $d = $this->toMysqlDate(is_string($c) ? $c : null);
    if ($d) return $d;

    $d = $this->toMysqlDate($val['VeroeffentlichtAb'] ?? null);
    if ($d) return $d;

    $d = $this->toMysqlDate($val['DatumAb'] ?? null);
    if ($d) return $d;

    if (!empty($val['JsonLd']) && is_string($val['JsonLd'])) {
      $j = json_decode($val['JsonLd'], true);
      if (is_array($j) && !empty($j['datePosted'])) {
        $d = $this->toMysqlDate($j['datePosted']);
        if ($d) return $d;
      }
    }
    return current_time('mysql', 1);
  }
}
