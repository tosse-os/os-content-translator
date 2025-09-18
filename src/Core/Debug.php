<?php
namespace OSCT\Core;
if (!defined('ABSPATH')) exit;

final class Debug {
    private const KEY = 'osct_debug_last';

    public static function start(string $runId, array $context = []): void {
        set_transient(self::KEY, [
            'run_id'  => $runId,
            'started' => current_time('mysql', 1),
            'context' => $context,
            'steps'   => [],
            'ended'   => null,
        ], 60 * 60);
    }

    public static function add(array $step): void {
        $data = get_transient(self::KEY);
        if (!is_array($data)) return;
        $step['ts'] = current_time('mysql', 1);
        $data['steps'][] = $step;
        set_transient(self::KEY, $data, 60 * 60);
    }

    public static function finish(array $summary = []): void {
        $data = get_transient(self::KEY);
        if (!is_array($data)) return;
        $data['ended']   = current_time('mysql', 1);
        $data['summary'] = $summary;
        set_transient(self::KEY, $data, 60 * 60);
    }

    public static function read(): array {
        $data = get_transient(self::KEY);
        return is_array($data) ? $data : [];
    }
}
