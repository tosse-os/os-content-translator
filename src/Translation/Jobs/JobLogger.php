<?php

namespace OSCT\Translation\Jobs;

use OSCT\Domain\Repos\LogRepo;

if (!defined('ABSPATH')) exit;

final class JobsLogger
{
  public function __construct(
    private LogRepo $logs,
    private string $runId,
    private string $sourceLang
  ) {}

  private function rid(): string
  {
    return $this->runId ?: wp_generate_uuid4();
  }

  public function batch(array $pickedIds, ?int $limit): void
  {
    $this->logs->insert([
      'run_id'        => $this->rid(),
      'post_id'       => 0,
      'post_type'     => 'job',
      'source_lang'   => $this->sourceLang,
      'target_lang'   => '-',
      'provider'      => 'mixed',
      'action'        => 'batch',
      'status'        => 'info',
      'words_title'   => 0,
      'chars_title'   => 0,
      'words_content' => 0,
      'chars_content' => 0,
      'src_hash'      => '',
      'message'       => sprintf(
        'Picked %d jobs%s: %s',
        count($pickedIds),
        $limit !== null ? " (limit={$limit})" : '',
        implode(',', array_slice($pickedIds, 0, 50))
      ),
    ]);
  }

  public function beginJob(string $jobId, string $srcHash, array $mSrc): void
  {
    $this->logs->insert([
      'run_id'        => $this->rid(),
      'post_id'       => 0,
      'post_type'     => 'job',
      'source_lang'   => $this->sourceLang,
      'target_lang'   => '-',
      'provider'      => 'mixed',
      'action'        => 'begin',
      'status'        => 'info',
      'words_title'   => (int)$mSrc['wt'],
      'chars_title'   => (int)$mSrc['ct'],
      'words_content' => (int)$mSrc['wc'],
      'chars_content' => (int)$mSrc['cc'],
      'src_hash'      => $srcHash,
      'message'       => 'job_id=' . $jobId,
    ]);
  }

  public function result(string $jobId, string $target, string $action, string $status, array $m, string $srcHash): void
  {
    $this->logs->insert([
      'run_id'        => $this->rid(),
      'post_id'       => 0,
      'post_type'     => 'job',
      'source_lang'   => $this->sourceLang,
      'target_lang'   => $target,
      'provider'      => 'mixed',
      'action'        => $action,
      'status'        => $status,
      'words_title'   => (int)$m['wt'],
      'chars_title'   => (int)$m['ct'],
      'words_content' => (int)$m['wc'],
      'chars_content' => (int)$m['cc'],
      'src_hash'      => $srcHash,
      'message'       => ucfirst($action) . '; job_id=' . $jobId,
    ]);
  }

  public function error(string $jobId, string $message): void
  {
    $this->logs->insert([
      'run_id'        => $this->rid(),
      'post_id'       => 0,
      'post_type'     => 'job',
      'source_lang'   => $this->sourceLang,
      'target_lang'   => '-',
      'provider'      => 'mixed',
      'action'        => 'error',
      'status'        => 'error',
      'words_title'   => 0,
      'chars_title'   => 0,
      'words_content' => 0,
      'chars_content' => 0,
      'src_hash'      => '',
      'message'       => 'job_id=' . $jobId . '; ' . $message,
    ]);
  }
}
