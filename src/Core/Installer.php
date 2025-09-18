<?php
namespace OSCT\Core;
if (!defined('ABSPATH')) exit;

final class Installer {
    public static function install(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'osct_translation_log';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id VARCHAR(36) NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            post_type VARCHAR(32) NOT NULL,
            source_lang VARCHAR(10) NOT NULL,
            target_lang VARCHAR(10) NOT NULL,
            provider VARCHAR(16) NOT NULL,
            action VARCHAR(16) NOT NULL,
            status VARCHAR(16) NOT NULL,
            words_title INT UNSIGNED NOT NULL DEFAULT 0,
            chars_title INT UNSIGNED NOT NULL DEFAULT 0,
            words_content INT UNSIGNED NOT NULL DEFAULT 0,
            chars_content INT UNSIGNED NOT NULL DEFAULT 0,
            src_hash CHAR(32) DEFAULT NULL,
            message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY post_id_idx (post_id),
            KEY target_lang_idx (target_lang),
            KEY created_at_idx (created_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        global $wpdb;
        $table2 = $wpdb->prefix . 'jobs_i18n';
        $charset = $wpdb->get_charset_collate();
        $sql2 = "CREATE TABLE IF NOT EXISTS $table2 (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id varchar(64) NOT NULL,
            lang varchar(10) NOT NULL,
            job_name text NOT NULL,
            job_value longtext NOT NULL,
            link_slug varchar(200) NOT NULL,
            src_hash char(40) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_lang (job_id,lang)
            ) $charset;";
        dbDelta($sql2);
    }
}
