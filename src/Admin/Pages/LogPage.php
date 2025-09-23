<?php
namespace OSCT\Admin\Pages;
use OSCT\Domain\Repos\LogRepo;

if (!defined('ABSPATH')) exit;

final class LogPage {
    public function __construct(private LogRepo $repo) {}

    public function render(): void {
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $from   = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
        $to     = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : '';
        $paged  = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $type   = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : '';
        $allowedTypes = ['', 'job', 'page', 'wp-log'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = '';
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
        echo ' <a class="button" href="'.esc_url(add_query_arg(['page'=>'osct-logs'], admin_url('admin.php'))).'">Reset</a>';
        echo ' <a class="button button-secondary" href="'.esc_url(add_query_arg(['page'=>'osct-logs','s'=>$search,'from'=>$from,'to'=>$to,'post_type'=>$type,'export'=>'csv'], admin_url('admin.php'))).'">CSV export</a>';
        echo '</p>';
        echo '</form>';

        echo '<p><strong>Summen</strong>: Einträge '.(int)$sums['entries'].', Wörter '.(int)$sums['words'].', Zeichen '.(int)$sums['chars'];
        echo '. Geschätzte Google-Kosten: ' . esc_html($formatCost($googleCostAll)) . '.</p>';

        echo '<p><strong>Summen – Stellenanzeigen</strong>: Einträge '.(int)$sums['entries_job'].', Wörter '.(int)$sums['words_job'].', Zeichen '.(int)$sums['chars_job'];
        echo '. Geschätzte Google-Kosten: ' . esc_html($formatCost($googleCostJobs)) . '.</p>';

        $last = $this->repo->lastRunJobSummary();
        echo '<p><strong>Letzter Lauf – Stellenanzeigen:</strong> Übersetzt ' . (int)$last['entries'] . ' Einträge, Wörter ' . (int)$last['words'] . ', Zeichen ' . (int)$last['chars'] . '.</p>';

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
