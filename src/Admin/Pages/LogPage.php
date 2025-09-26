<?php
namespace OSCT\Admin\Pages;
use OSCT\Domain\Repos\LogRepo;

if (!defined('ABSPATH')) exit;

final class LogPage {
    public function __construct(private LogRepo $repo) {}

    public function render(): void {
        $userId = get_current_user_id();
        $optionKey = 'osct_logs_filters';
        $stored = [];
        if ($userId) {
            $raw = get_user_option($optionKey, $userId);
            if (is_array($raw)) {
                $stored = $raw;
            }
        }

        $resetFilters = isset($_GET['reset']);
        if ($resetFilters && $userId) {
            delete_user_option($userId, $optionKey);
            $stored = [];
        }

        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : sanitize_text_field($stored['s'] ?? '');
        $from   = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : sanitize_text_field($stored['from'] ?? '');
        $to     = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : sanitize_text_field($stored['to'] ?? '');
        $paged  = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $type   = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : sanitize_text_field($stored['post_type'] ?? '');
        $allowedTypes = ['', 'job', 'page', 'wp-log'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = '';
        }

        $filterProvided = isset($_GET['s']) || isset($_GET['from']) || isset($_GET['to']) || isset($_GET['post_type']);
        if ($userId && !$resetFilters && $filterProvided) {
            update_user_option($userId, $optionKey, [
                's'         => $search,
                'from'      => $from,
                'to'        => $to,
                'post_type' => $type,
            ]);
        }

        if (isset($_GET['export']) && $_GET['export']==='csv') {
            $csv = $this->repo->exportCsv(['search'=>$search,'from'=>$from,'to'=>$to,'post_type'=>$type]);
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename=osct-logs.csv');
            echo $csv;
            exit;
        }

        $data = $this->repo->list(['search'=>$search,'from'=>$from,'to'=>$to,'paged'=>$paged,'per_page'=>50,'post_type'=>$type]);
        $sums = $this->repo->sums(['search'=>$search,'from'=>$from,'to'=>$to,'post_type'=>$type]);

        $googleRatePerMillion = 20; // USD per 1M characters (approx.)
        $googleCostAll = ($sums['chars_google'] / 1000000) * $googleRatePerMillion;
        $googleCostJobs = ($sums['chars_google_job'] / 1000000) * $googleRatePerMillion;
        $formatCost = static fn(float $amount): string => number_format($amount, 2, ',', '.') . ' USD';

        echo '<div class="wrap"><h1>OS Content Translator – Logs</h1>';

        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="osct-logs">';
        echo '<p>';
        echo '<input type="text" name="s" value="'.esc_attr($search).'" placeholder="Post-ID oder Sprache" class="regular-text" style="max-width:220px">';
        echo ' Von: <input type="date" name="from" value="'.esc_attr($from).'">';
        echo ' Bis: <input type="date" name="to" value="'.esc_attr($to).'">';
        echo ' <select name="post_type">';
        $typeLabels = [
            '' => 'Alle Typen',
            'job' => 'Stellenanzeigen',
            'page' => 'Seiten',
            'wp-log' => 'WP Logs',
        ];
        foreach ($typeLabels as $key => $label) {
            $selected = selected($type, $key, false);
            echo '<option value="'.esc_attr($key).'" '.$selected.'>'.esc_html($label).'</option>';
        }
        echo '</select>';
        echo ' <button class="button">Filtern</button>';
        echo ' <a class="button" href="'.esc_url(add_query_arg(['page'=>'osct-logs','reset'=>1], admin_url('admin.php'))).'">Reset</a>';
        echo ' <a class="button button-secondary" href="'.esc_url(add_query_arg(['page'=>'osct-logs','s'=>$search,'from'=>$from,'to'=>$to,'post_type'=>$type,'export'=>'csv'], admin_url('admin.php'))).'">CSV export</a>';
        echo '</p>';
        echo '</form>';

        echo '<p><strong>Summen</strong>: Einträge '.(int)$sums['entries'].', Wörter '.(int)$sums['words'].', Zeichen '.(int)$sums['chars'];
        echo '. Geschätzte Google-Kosten: ' . esc_html($formatCost($googleCostAll)) . '.</p>';

        echo '<p><strong>Summen – Stellenanzeigen</strong>: Einträge '.(int)$sums['entries_job'].', Wörter '.(int)$sums['words_job'].', Zeichen '.(int)$sums['chars_job'];
        echo '. Geschätzte Google-Kosten: ' . esc_html($formatCost($googleCostJobs)) . '.</p>';

        $last = $this->repo->lastRunJobSummary();
        $lastRows = $this->repo->lastRunJobEntries();
        echo '<h2>Letzter Lauf – Stellenanzeigen</h2>';
        echo '<p><strong>Summen:</strong> Übersetzt ' . (int)$last['entries'] . ' Einträge, Wörter ' . (int)$last['words'] . ', Zeichen ' . (int)$last['chars'] . '.</p>';

        if (!empty($lastRows)) {
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>Zeit</th><th>Job-ID</th><th>Quelle→Ziel</th><th>Provider</th><th>Aktion</th><th>Status</th><th>Wörter</th><th>Zeichen</th><th>Message</th>';
            echo '</tr></thead><tbody>';
            foreach ($lastRows as $row) {
                $words = (int)$row['words_total'];
                $chars = (int)$row['chars_total'];
                $jobId = $row['job_id'] !== '' ? $row['job_id'] : '–';
                $source = (string)($row['source_lang'] ?? '');
                $target = (string)($row['target_lang'] ?? '');
                $ts = esc_html(get_date_from_gmt($row['created_at'], 'Y-m-d H:i:s'));
                echo '<tr>';
                echo '<td>' . $ts . '</td>';
                echo '<td>' . esc_html($jobId) . '</td>';
                echo '<td>' . esc_html($source . ' → ' . $target) . '</td>';
                echo '<td>' . esc_html($row['provider']) . '</td>';
                echo '<td>' . esc_html($row['action']) . '</td>';
                echo '<td>' . esc_html($row['status']) . '</td>';
                echo '<td>' . esc_html($words) . '</td>';
                echo '<td>' . esc_html($chars) . '</td>';
                echo '<td>' . esc_html($row['message']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Keine Stellenanzeigen im letzten Lauf.</p>';
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>Zeit</th><th>Post</th><th>Typ</th><th>Sprache</th><th>Provider</th><th>Aktion</th><th>Status</th><th>Wörter</th><th>Zeichen</th><th>Message</th>';
        echo '</tr></thead><tbody>';

        foreach ($data['rows'] as $r) {
            $words = (int)$r['words_title'] + (int)$r['words_content'];
            $chars = (int)$r['chars_title'] + (int)$r['chars_content'];
            $postLink = get_edit_post_link((int)$r['post_id']);
            echo '<tr>';
            echo '<td>'.(int)$r['id'].'</td>';
            echo '<td>'.esc_html( get_date_from_gmt($r['created_at'],'Y-m-d H:i:s') ).'</td>';
            echo '<td>#'.(int)$r['post_id'].' '.($postLink?'<a href="'.esc_url($postLink).'" target="_blank">edit</a>':'').'</td>';
            echo '<td>'.esc_html($r['post_type']).'</td>';
            echo '<td>'.esc_html($r['source_lang']).' → '.esc_html($r['target_lang']).'</td>';
            echo '<td>'.esc_html($r['provider']).'</td>';
            echo '<td>'.esc_html($r['action']).'</td>';
            echo '<td>'.esc_html($r['status']).'</td>';
            echo '<td>'.$words.'</td>';
            echo '<td>'.$chars.'</td>';
            echo '<td>'.esc_html($r['message']).'</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        $total = $data['total'];
        $per   = $data['per_page'];
        $pages = max(1, (int)ceil($total/$per));
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            for ($i=1;$i<=$pages;$i++) {
                $url = add_query_arg(['page'=>'osct-logs','s'=>$search,'from'=>$from,'to'=>$to,'post_type'=>$type,'paged'=>$i], admin_url('admin.php'));
                $cls = $i==$data['paged'] ? 'class="page-numbers current"' : 'class="page-numbers"';
                echo '<a '.$cls.' href="'.esc_url($url).'">'.$i.'</a> ';
            }
            echo '</div></div>';
        }

        echo '</div>';
    }
}
