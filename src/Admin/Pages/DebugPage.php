<?php

namespace OSCT\Admin\Pages;

use OSCT\Domain\Repos\LogRepo;

if (!defined('ABSPATH')) exit;

final class DebugPage
{
    private LogRepo $logs;

    public function __construct()
    {
        $this->logs = new LogRepo();
    }

    public function render(): void
    {
        global $wpdb;

        $runId = $this->logs->lastRunId();
        echo '<div class="wrap"><h1>OS Content Translator – Letzter Lauf (Debug)</h1>';

        if (!$runId) {
            echo '<p>Keine Läufe vorhanden.</p></div>';
            return;
        }

        $table = $wpdb->prefix . 'osct_translation_log';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE run_id=%s ORDER BY id ASC", $runId),
            ARRAY_A
        );

        $started = $rows ? $rows[0]['created_at'] : '';
        $ended   = $rows ? $rows[count($rows) - 1]['created_at'] : '';

        $dateFormat = trim((string)get_option('date_format'));
        $timeFormat = trim((string)get_option('time_format'));
        $displayFormat = trim($dateFormat . ' ' . $timeFormat);
        if ($displayFormat === '') {
            $displayFormat = 'Y-m-d H:i:s';
        }

        $localize = static function (?string $utc, string $format): string {
            if (!$utc) {
                return '–';
            }
            $localized = get_date_from_gmt($utc, $format);
            return $localized ?: $utc;
        };

        $sumWords = 0;
        $sumChars = 0;
        foreach ($rows as $r) {
            $sumWords += (int)$r['words_title'] + (int)$r['words_content'];
            $sumChars += (int)$r['chars_title'] + (int)$r['chars_content'];
        }

        $jobSum = $this->logs->lastRunJobSummary();

        echo '<p><strong>Run-ID:</strong> ' . esc_html($runId) . ' &nbsp; ';
        echo '<strong>Start:</strong> ' . esc_html($localize($started, $displayFormat)) . ' &nbsp; ';
        echo '<strong>Ende:</strong> ' . esc_html($localize($ended, $displayFormat)) . '</p>';

        echo '<p><strong>Gesamt Wörter:</strong> ' . intval($sumWords) .
            ' &nbsp; <strong>Gesamt Zeichen:</strong> ' . intval($sumChars) . '</p>';

        echo '<h2>Stellenangebote – Zusammenfassung (dieser Lauf)</h2>';
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><td>Einträge (create/update)</td><td>' . intval($jobSum['entries'] ?? 0) . '</td></tr>';
        echo '<tr><td>Wörter</td><td>' . intval($jobSum['words'] ?? 0) . '</td></tr>';
        echo '<tr><td>Zeichen</td><td>' . intval($jobSum['chars'] ?? 0) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2>Schritte</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Zeit</th>';
        echo '<th>Post</th>';
        echo '<th>JobID</th>'; // NEU
        echo '<th>Typ</th>';
        echo '<th>Quelle→Ziel</th>';
        echo '<th>Provider</th>';
        echo '<th>Aktion</th>';
        echo '<th>Status</th>';
        echo '<th>SrcHash</th>';
        echo '<th>Wörter</th>';
        echo '<th>Zeichen</th>';
        echo '<th>Nachricht</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $postId = (int)$r['post_id'];
            $type   = $r['post_type'];

            $postLabel = ($postId === 0 && $type === 'job') ? '–' : ('#' . $postId);

            // JobID aus message extrahieren: [job <id>] oder job_id=<id>
            $jobId = '';
            $message = (string)$r['message'];
            if (preg_match('/\\[job\\s+([^\\]\\s]+)\\]/i', $message, $m)) {
                $jobId = trim($m[1]);
            } elseif (preg_match('/\\bjob_id=([a-z0-9\\-]+)/i', $message, $m)) {
                $jobId = trim($m[1]);
            }

            $words  = (int)$r['words_title'] + (int)$r['words_content'];
            $chars  = (int)$r['chars_title'] + (int)$r['chars_content'];
            $tsLocal = $localize($r['created_at'], $displayFormat);

            echo '<tr>';
            echo '<td>' . esc_html($tsLocal) . '</td>';
            echo '<td>' . esc_html($postLabel) . '</td>';
            echo '<td>' . esc_html($jobId ?: '–') . '</td>';
            echo '<td>' . esc_html($type) . '</td>';
            echo '<td>' . esc_html($r['source_lang'] . ' → ' . $r['target_lang']) . '</td>';
            echo '<td>' . esc_html($r['provider']) . '</td>';
            echo '<td>' . esc_html($r['action']) . '</td>';
            echo '<td>' . esc_html($r['status']) . '</td>';
            echo '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis">' . esc_html($r['src_hash']) . '</td>';
            echo '<td>' . intval($words) . '</td>';
            echo '<td>' . intval($chars) . '</td>';
            echo '<td>' . esc_html($r['message']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}
