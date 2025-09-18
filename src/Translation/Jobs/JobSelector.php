<?php

namespace OSCT\Translation\Jobs;

use OSCT\Domain\Repos\JobsRepo;
use OSCT\Domain\Repos\OptionRepo;
use OSCT\Domain\Repos\LanguageRepo;

if (!defined('ABSPATH')) exit;

final class JobSelector
{
  public function __construct(
    private JobsRepo $jobs,
    private OptionRepo $opt,
    private LanguageRepo $langs
  ) {}

  /**
   * @return array{rows: array<int,array>, source: string, targets: string[]}
   */
  public function list(?string $onlyJobId, ?int $limit): array
  {
    $rows = $this->jobs->all();

    if ($onlyJobId) {
      $rows = array_values(array_filter(
        $rows,
        fn($r) => (string)$r['job_id'] === (string)$onlyJobId
      ));
    }

    // deterministisch sortieren, damit "erste 2" immer gleich sind
    usort($rows, fn($a, $b) => strnatcmp((string)$a['job_id'], (string)$b['job_id']));

    if ($limit !== null) {
      $rows = array_slice($rows, 0, $limit);
    }

    $source  = $this->langs->default();
    $targets = (array)$this->opt->get('languages_active', []);

    return ['rows' => $rows, 'source' => $source, 'targets' => $targets];
  }
}
