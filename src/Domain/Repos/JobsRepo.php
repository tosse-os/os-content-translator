<?php

namespace OSCT\Domain\Repos;

if (!defined('ABSPATH')) exit;

final class JobsRepo
{
  private string $srcTable;
  private string $i18nTable;

  public function __construct()
  {
    global $wpdb;
    $this->srcTable  = $wpdb->prefix . 'jobs';
    $this->i18nTable = $wpdb->prefix . 'jobs_i18n';
  }

  public function all(): array
  {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM {$this->srcTable}", ARRAY_A);
  }

  public function getTranslation(string $jobId, string $lang): ?array
  {
    global $wpdb;
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$this->i18nTable} WHERE job_id=%s AND lang=%s",
        $jobId,
        $lang
      ),
      ARRAY_A
    );
    return $row ?: null;
  }

  public function allTranslationsForJob(string $jobId): array
  {
    global $wpdb;
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT lang, updated_at FROM {$this->i18nTable} WHERE job_id=%s",
        $jobId
      ),
      ARRAY_A
    );
    return $rows ?: [];
  }

  public function upsert(
    string $jobId,
    string $lang,
    string $jobName,
    array $jobValue,
    string $slug,
    string $srcHash,
    ?string $createdAt = null
  ): int {
    global $wpdb;
    $data = [
      'job_id'     => $jobId,
      'lang'       => $lang,
      'job_name'   => $jobName,
      'job_value'  => maybe_serialize($jobValue),
      'link_slug'  => $slug,
      'src_hash'   => $srcHash,
      'updated_at' => current_time('mysql', 1),
    ];
    $exist = $this->getTranslation($jobId, $lang);
    if ($exist) {
      $wpdb->update($this->i18nTable, $data, ['id' => $exist['id']]);
      return (int)$exist['id'];
    }
    $data['created_at'] = $createdAt ?: current_time('mysql', 1);
    $wpdb->insert($this->i18nTable, $data);
    return (int)$wpdb->insert_id;
  }

  /**
   * Anzahl aller Jobs in der Quelltabelle.
   */
  public function countAll(): int
  {
    global $wpdb;
    return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->srcTable}");
  }

  /**
   * Anzahl der Job-IDs, die in mindestens EINER der angegebenen Sprachen eine Übersetzung haben.
   * @param string[] $langs Zielsprachen (Slugs wie 'en', 'pl', ...)
   */
  public function countAnyTranslated(array $langs): int
  {
    global $wpdb;
    $langs = array_values(array_filter(array_map('strval', $langs)));
    if (empty($langs)) return 0;

    // dynamische IN-Klausel bauen
    $placeholders = implode(',', array_fill(0, count($langs), '%s'));
    $sql = "
      SELECT COUNT(DISTINCT job_id)
      FROM {$this->i18nTable}
      WHERE lang IN ($placeholders)
    ";
    return (int)$wpdb->get_var($wpdb->prepare($sql, $langs));
  }

  /**
   * Anzahl der übersetzten Jobs für eine konkrete Zielsprache.
   */
  public function countTranslated(string $lang): int
  {
    global $wpdb;
    return (int)$wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(DISTINCT job_id) FROM {$this->i18nTable} WHERE lang=%s",
        $lang
      )
    );
  }
}
