<?php
namespace OSCT\Domain\Repos;
if (!defined('ABSPATH')) exit;

final class LogRepo {
    private function table(): string {
        global $wpdb; return $wpdb->prefix . 'osct_translation_log';
    }

    public function insert(array $row): void {
        global $wpdb;
        $wpdb->insert($this->table(), [
            'run_id'         => $row['run_id'],
            'post_id'        => (int)$row['post_id'],
            'post_type'      => $row['post_type'],
            'source_lang'    => $row['source_lang'],
            'target_lang'    => $row['target_lang'],
            'provider'       => $row['provider'],
            'action'         => $row['action'],
            'status'         => $row['status'],
            'words_title'    => (int)$row['words_title'],
            'chars_title'    => (int)$row['chars_title'],
            'words_content'  => (int)$row['words_content'],
            'chars_content'  => (int)$row['chars_content'],
            'src_hash'       => $row['src_hash'],
            'message'        => $row['message'],
            'created_at'     => current_time('mysql', 1),
        ]);
    }

    public function list(array $args=[]): array {
        global $wpdb;
        $table = $this->table();
        $where = '1=1';
        $params = [];
        if (!empty($args['search'])) {
            $where .= ' AND (post_id = %d OR target_lang = %s)';
            $params[] = (int)$args['search'];
            $params[] = $args['search'];
        }
        if (!empty($args['from'])) {
            $where .= ' AND created_at >= %s';
            $params[] = $args['from'];
        }
        if (!empty($args['to'])) {
            $where .= ' AND created_at <= %s';
            $params[] = $args['to'];
        }
        $paged = max(1, (int)($args['paged'] ?? 1));
        $pp    = max(1, (int)($args['per_page'] ?? 50));
        $off   = ($paged-1)*$pp;

        $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = $pp;
        $params[] = $off;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $total = (int)$wpdb->get_var('SELECT FOUND_ROWS()');

        return ['rows'=>$rows,'total'=>$total,'paged'=>$paged,'per_page'=>$pp];
    }

    public function sums(array $args=[]): array {
        global $wpdb;
        $table = $this->table();
        $where = '1=1';
        $params = [];
        if (!empty($args['search'])) {
            $where .= ' AND (post_id = %d OR target_lang = %s)';
            $params[] = (int)$args['search'];
            $params[] = $args['search'];
        }
        if (!empty($args['from'])) { $where.=' AND created_at >= %s'; $params[]=$args['from']; }
        if (!empty($args['to']))   { $where.=' AND created_at <= %s'; $params[]=$args['to']; }
        $sql = "SELECT
            COUNT(*) as entries,
            SUM(words_title+words_content) as words,
            SUM(chars_title+chars_content) as chars,
            SUM(CASE WHEN post_type = 'job' THEN 1 ELSE 0 END) as entries_job,
            SUM(CASE WHEN post_type = 'job' THEN words_title+words_content ELSE 0 END) as words_job,
            SUM(CASE WHEN post_type = 'job' THEN chars_title+chars_content ELSE 0 END) as chars_job,
            SUM(CASE WHEN provider = 'google' THEN chars_title+chars_content ELSE 0 END) as chars_google,
            SUM(CASE WHEN provider = 'google' AND post_type = 'job' THEN chars_title+chars_content ELSE 0 END) as chars_google_job
            FROM $table WHERE $where";
        $row = $wpdb->get_row($wpdb->prepare($sql,$params), ARRAY_A);
        return [
            'entries'=>(int)($row['entries'] ?? 0),
            'words'  =>(int)($row['words'] ?? 0),
            'chars'  =>(int)($row['chars'] ?? 0),
            'entries_job'=>(int)($row['entries_job'] ?? 0),
            'words_job'  =>(int)($row['words_job'] ?? 0),
            'chars_job'  =>(int)($row['chars_job'] ?? 0),
            'chars_google'=>(int)($row['chars_google'] ?? 0),
            'chars_google_job'=>(int)($row['chars_google_job'] ?? 0),
        ];
    }

    public function exportCsv(array $args=[]): string {
        $data = $this->list(array_merge($args,['per_page'=>100000,'paged'=>1]));
        $rows = $data['rows'];
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['id','run_id','post_id','post_type','source_lang','target_lang','provider','action','status','words_title','chars_title','words_content','chars_content','src_hash','message','created_at']);
        foreach ($rows as $r) fputcsv($fh, $r);
        rewind($fh);
        return stream_get_contents($fh);
    }

    public function lastRunId(): ?string
    {
        global $wpdb;
        $table = $this->table();
        $run = $wpdb->get_var("SELECT run_id FROM $table ORDER BY id DESC LIMIT 1");
        return $run ?: null;
    }

    public function lastRunJobSummary(): array
    {
        global $wpdb;
        $table = $this->table();
        $runId = $this->lastRunId();
        if (!$runId) return ['entries' => 0, 'words' => 0, 'chars' => 0];
        $sql = "SELECT
        COUNT(*) as entries,
        SUM(words_title+words_content) as words,
        SUM(chars_title+chars_content) as chars
        FROM $table
        WHERE run_id = %s AND post_type = 'job' AND action IN ('create','update')";
        $row = $wpdb->get_row($wpdb->prepare($sql, $runId), ARRAY_A);
        return [
            'entries' => (int)($row['entries'] ?? 0),
            'words'  => (int)($row['words'] ?? 0),
            'chars'  => (int)($row['chars'] ?? 0),
        ];
    }
}
