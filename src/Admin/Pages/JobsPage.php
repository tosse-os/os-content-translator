<?php

namespace OSCT\Admin\Pages;

use OSCT\Domain\Repos\OptionRepo;
use OSCT\Domain\Repos\LanguageRepo;
use OSCT\Domain\Repos\JobsRepo;

if (!defined('ABSPATH')) exit;

final class JobsPage
{
  public function __construct(
    private OptionRepo $opt,
    private LanguageRepo $langs,
    private JobsRepo $repo
  ) {}

  public function render(): void
  {
    $activeLangs = (array)$this->opt->get('languages_active', []);
    $defaultLang = $this->langs->default();

    // Zielsprachen ohne Default
    $allTargetLangs = array_values(array_filter($activeLangs, fn($l) => $l !== $defaultLang));

    // --- Filter & Sorting ---
    $s        = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $filterL  = isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : '';
    $orderby  = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'job_id';
    $order    = (isset($_GET['order']) && strtolower($_GET['order']) === 'desc') ? 'desc' : 'asc';
    $paged    = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
    $perPage  = 50;

    $deletedFlag = isset($_GET['deleted']) ? sanitize_text_field($_GET['deleted']) : '';
    $deletedJob  = isset($_GET['deleted_job']) ? sanitize_text_field($_GET['deleted_job']) : '';
    $deletedLang = isset($_GET['deleted_lang']) ? sanitize_text_field($_GET['deleted_lang']) : '';

    // „gültige“ Filter-Sprache
    $hasLangFilter = ($filterL !== '' && in_array($filterL, $allTargetLangs, true));
    $columnLangs   = $hasLangFilter ? [$filterL] : $allTargetLangs;

    $redirectArgs = [
      'page'    => 'osct-jobs',
      'orderby' => $orderby,
      'order'   => $order,
    ];
    if ($s !== '') {
      $redirectArgs['s'] = $s;
    }
    if ($filterL !== '') {
      $redirectArgs['lang'] = $filterL;
    }
    if ($paged > 1) {
      $redirectArgs['paged'] = $paged;
    }
    $redirectBase = add_query_arg($redirectArgs, admin_url('admin.php'));

    // Source-Rows
    $rows = $this->repo->all();

    // Suche
    if ($s !== '') {
      $needle = mb_strtolower($s);
      $rows = array_values(array_filter($rows, function ($r) use ($needle) {
        $jid = mb_strtolower((string)($r['job_id'] ?? ''));
        $jnm = mb_strtolower((string)($r['job_name'] ?? ''));
        return (str_contains($jid, $needle) || str_contains($jnm, $needle));
      }));
    }

    // Aufbereiten
    $data = [];
    foreach ($rows as $r) {
      $jobId   = (string)($r['job_id'] ?? '');
      $jobName = (string)($r['job_name'] ?? '');
      $rowId   = $r['id'] ?? ($r['ID'] ?? '–');

      // Status pro (sichtbarer) Sprache
      $translated = 0;
      $langsState = [];
      foreach ($columnLangs as $lang) {
        $tr = $this->repo->getTranslation($jobId, $lang);
        $ok = $tr ? 1 : 0;
        $translated += $ok;
        $langsState[$lang] = [
          'ok'         => $ok,
          'updated_at' => $tr['updated_at'] ?? null,
        ];
      }

      // „Zuletzt aktualisiert“:
      // - wenn Sprachfilter aktiv: updated_at für diese Sprache
      // - sonst: Max(updated_at) über alle Übersetzungen
      $lastUpdated = null;
      if ($hasLangFilter) {
        $lastUpdated = $langsState[$filterL]['updated_at'] ?? null;
      } else {
        $allTr = $this->repo->allTranslationsForJob($jobId);
        foreach ($allTr as $tr) {
          $ts = $tr['updated_at'] ?? null;
          if ($ts && (!$lastUpdated || strcmp($ts, $lastUpdated) > 0)) {
            $lastUpdated = $ts;
          }
        }
      }

      $data[] = [
        '_row_id'       => $rowId,
        'job_id'        => $jobId,
        'job_name'      => $jobName,
        '_translated'   => $translated,
        '_langs'        => $langsState,
        '_updated_at'   => $lastUpdated, // GMT (current_time('mysql', true))
      ];
    }

    // Sortierung
    usort($data, function ($a, $b) use ($orderby, $order, $columnLangs, $hasLangFilter, $filterL) {
      $cmp = 0;
      switch ($orderby) {
        case 'job_id':
          $cmp = strnatcmp((string)$a['job_id'], (string)$b['job_id']);
          break;
        case 'job_name':
          $cmp = strnatcmp((string)$a['job_name'], (string)$b['job_name']);
          break;
        case 'translated':
          $cmp = ($a['_translated'] <=> $b['_translated']);
          break;
        case 'updated_at':
          $au = $a['_updated_at'] ?? '';
          $bu = $b['_updated_at'] ?? '';
          // leere nach hinten
          if ($au === '' && $bu !== '') $cmp = -1;
          elseif ($au !== '' && $bu === '') $cmp = 1;
          else $cmp = strnatcmp($au, $bu);
          break;
        default:
          // Sortierung pro Sprachspalte: ✓ (1) vor – (0)
          if (in_array($orderby, $columnLangs, true)) {
            $av = (int)($a['_langs'][$orderby]['ok'] ?? 0);
            $bv = (int)($b['_langs'][$orderby]['ok'] ?? 0);
            $cmp = ($av <=> $bv);
            break;
          }
          $cmp = 0;
      }
      return ($order === 'desc') ? -$cmp : $cmp;
    });

    // Pagination
    $total   = count($data);
    $pages   = max(1, (int)ceil($total / $perPage));
    $offset  = ($paged - 1) * $perPage;
    $slice   = array_slice($data, $offset, $perPage);

    echo '<div class="wrap"><h1>OS Content Translator – Jobs-Übersicht</h1>';

    if ($deletedFlag !== '') {
      $noticeCls = $deletedFlag === '1' ? 'notice notice-success is-dismissible' : 'notice notice-error';
      $jobDisplay = $deletedJob !== '' ? esc_html($deletedJob) : '–';
      $langDisplay = $deletedLang !== '' ? esc_html(strtoupper($deletedLang)) : '–';
      $message = $deletedFlag === '1'
        ? sprintf('Übersetzung %1$s für Job %2$s wurde gelöscht.', $langDisplay, $jobDisplay)
        : 'Übersetzung konnte nicht gelöscht werden.';
      echo '<div class="' . esc_attr($noticeCls) . '"><p>' . esc_html($message) . '</p></div>';
    }

    // Filterleiste
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="osct-jobs">';
    echo '<p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
    echo '<input type="search" name="s" value="' . esc_attr($s) . '" placeholder="JobID oder Jobname" class="regular-text" style="max-width:260px">';
    echo '<label>Sprache: ';
    echo '<select name="lang">';
    echo '<option value="">Alle</option>';
    foreach ($allTargetLangs as $L) {
      echo '<option value="' . esc_attr($L) . '" ' . selected($filterL, $L, false) . '>' . esc_html(strtoupper($L)) . '</option>';
    }
    echo '</select></label>';
    echo '<button class="button">Filtern</button>';
    echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'osct-jobs'], admin_url('admin.php'))) . '">Zurücksetzen</a>';
    echo '</p>';
    echo '</form>';

    // Summen (auf Basis sichtbarer Sprachspalten)
    $sumTranslated = array_sum(array_map(fn($r) => (int)$r['_translated'], $data));
    echo '<p><strong>Gesamt Jobs:</strong> ' . intval($total) . '. ';
    echo '<strong>Summe Übersetzungen (in sichtbaren Sprachen):</strong> ' . intval($sumTranslated) . '.</p>';

    // Sortable-Header Helper
    $makeSort = function ($key, $label) use ($orderby, $order, $s, $paged, $filterL) {
      $newOrder = ($orderby === $key && $order === 'asc') ? 'desc' : 'asc';
      $url = add_query_arg([
        'page'    => 'osct-jobs',
        's'       => $s,
        'lang'    => $filterL,
        'orderby' => $key,
        'order'   => $newOrder,
        'paged'   => $paged
      ], admin_url('admin.php'));
      $arrow = '';
      if ($orderby === $key) $arrow = $order === 'asc' ? ' ↑' : ' ↓';
      return '<a href="' . esc_url($url) . '">' . esc_html($label . $arrow) . '</a>';
    };

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . $makeSort('row_id', 'ID') . '</th>';
    echo '<th>' . $makeSort('job_id', 'JobID') . '</th>';
    echo '<th>' . $makeSort('job_name', 'Jobname') . '</th>';
    echo '<th>' . $makeSort('translated', 'Übersetzt (Anz.)') . '</th>';
    // pro sichtbarer Sprache eine Spalte
    foreach ($columnLangs as $lang) {
      echo '<th>' . $makeSort($lang, strtoupper(esc_html($lang))) . '</th>';
    }
    echo '<th>' . $makeSort('updated_at', 'Zuletzt aktualisiert') . '</th>';
    echo '</tr></thead><tbody>';

    if (!$slice) {
      echo '<tr><td colspan="' . (5 + count($columnLangs)) . '"><em>Keine Einträge.</em></td></tr>';
    } else {
      foreach ($slice as $r) {
        echo '<tr>';
        echo '<td>' . esc_html($r['_row_id']) . '</td>';
        echo '<td><code>' . esc_html($r['job_id']) . '</code></td>';
        echo '<td>' . esc_html($r['job_name']) . '</td>';
        echo '<td>' . intval($r['_translated']) . '</td>';
        foreach ($columnLangs as $lang) {
          $ok = !empty($r['_langs'][$lang]['ok']);
          if ($ok) {
            $confirmMsg = sprintf(
              'Übersetzung %s für Job %s wirklich löschen?',
              strtoupper($lang),
              $r['job_id']
            );
            $confirmJson = wp_json_encode($confirmMsg);
            if ($confirmJson === false) {
              $confirmJson = "'" . esc_js($confirmMsg) . "'";
            }
            $deleteUrl = wp_nonce_url(
              add_query_arg([
                'action'       => 'osct_delete_job_translation',
                'job_id'       => $r['job_id'],
                'lang'         => $lang,
                'redirect_to'  => $redirectBase,
              ], admin_url('admin-post.php')),
              'osct_delete_job_translation'
            );
            echo '<td><a href="' . esc_url($deleteUrl) . '" style="color:#0a0;text-decoration:none;font-weight:bold;" title="' . esc_attr(sprintf('Übersetzung %s löschen', strtoupper($lang))) . '" onclick="return confirm(' . $confirmJson . ');">✓</a></td>';
          } else {
            echo '<td><span style="color:#a00">–</span></td>';
          }
        }
        $lu = $r['_updated_at'] ?? '';
        // updated_at ist in GMT gespeichert (current_time('mysql', 1)); hübsch formatieren
        $luDisp = $lu ? esc_html(get_date_from_gmt($lu, 'Y-m-d H:i:s')) : '–';
        echo '<td>' . $luDisp . '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';

    // Pagination
    if ($pages > 1) {
      echo '<div class="tablenav"><div class="tablenav-pages">';
      for ($i = 1; $i <= $pages; $i++) {
        $url = add_query_arg([
          'page'    => 'osct-jobs',
          's'       => $s,
          'lang'    => $filterL,
          'orderby' => $orderby,
          'order'   => $order,
          'paged'   => $i
        ], admin_url('admin.php'));
        $cls = $i == $paged ? 'class="page-numbers current"' : 'class="page-numbers"';
        echo '<a ' . $cls . ' href="' . esc_url($url) . '">' . $i . '</a> ';
      }
      echo '</div></div>';
    }

    echo '</div>';
  }
}
