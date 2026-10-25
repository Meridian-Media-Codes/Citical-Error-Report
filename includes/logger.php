<?php
if (!defined('ABSPATH')) exit;

class MMCA_Logger {

    public static function init() {
        // nothing yet
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'mmca_errors';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_utc DATETIME NOT NULL,
            signature VARCHAR(32) NOT NULL,
            error_type INT(11) NOT NULL,
            message LONGTEXT NOT NULL,
            file TEXT NULL,
            line INT(11) NULL,
            url TEXT NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            php_sapi VARCHAR(32) NULL,
            context VARCHAR(20) NULL,
            culprit VARCHAR(120) NULL,
            decision VARCHAR(255) NULL,
            mail_status VARCHAR(20) NULL,
            PRIMARY KEY  (id),
            KEY signature (signature),
            KEY created_utc (created_utc)
        ) $charset;";

        dbDelta($sql);
    }

    public static function log($data) {
        global $wpdb;

        $table = self::table_name();

        $row = array(
            'created_utc' => gmdate('Y-m-d H:i:s'),
            'signature'   => isset($data['signature']) ? sanitize_text_field($data['signature']) : '',
            'error_type'  => isset($data['error_type']) ? (int)$data['error_type'] : 0,
            'message'     => isset($data['message']) ? substr((string)$data['message'], 0, 8000) : '',
            'file'        => isset($data['file']) ? (string)$data['file'] : '',
            'line'        => isset($data['line']) ? (int)$data['line'] : 0,
            'url'         => isset($data['url']) ? (string)$data['url'] : '',
            'user_id'     => isset($data['user_id']) ? (int)$data['user_id'] : 0,
            'php_sapi'    => isset($data['php_sapi']) ? sanitize_text_field($data['php_sapi']) : '',
            'context'     => isset($data['context']) ? sanitize_text_field($data['context']) : '',
            'culprit'     => isset($data['culprit']) ? substr(sanitize_text_field($data['culprit']), 0, 120) : '',
            'decision'    => isset($data['decision']) ? substr(sanitize_text_field($data['decision']), 0, 255) : '',
            'mail_status' => isset($data['mail_status']) ? sanitize_text_field($data['mail_status']) : '',
        );

        $inserted = $wpdb->insert($table, $row, array('%s','%s','%d','%s','%s','%d','%s','%d','%s','%s','%s','%s','%s'));

        // Keep the table small: occasionally drop rows older than 60 days.
        if ($inserted && mt_rand(1, 25) === 1) {
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created_utc < %s", gmdate('Y-m-d H:i:s', time() - 60 * 86400)));
        }

        return (int)$wpdb->insert_id;
    }

    public static function update_mail_status($id, $status) {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            array('mail_status' => sanitize_text_field($status)),
            array('id' => (int)$id),
            array('%s'),
            array('%d')
        );
    }

    /** Alerts from the last 7 days that never confirmed as sent (stuck 'pending' or 'failed'). */
    public static function count_mail_problems() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE mail_status IN ('pending','failed') AND created_utc > %s",
            gmdate('Y-m-d H:i:s', time() - 7 * 86400)
        ));
    }

    public static function error_type_label($type) {
        $map = array(
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_USER_ERROR => 'E_USER_ERROR',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        );
        return isset($map[$type]) ? $map[$type] : 'FATAL';
    }

    public static function get_logs($limit = 50, $offset = 0) {
        global $wpdb;
        $table = self::table_name();
        $limit = max(1, (int)$limit);
        $offset = max(0, (int)$offset);

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset),
            ARRAY_A
        );
    }

    public static function count_logs() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    }

    public static function get_log($id) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", (int)$id), ARRAY_A);
    }

    public static function delete_all() {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("TRUNCATE TABLE $table");
    }
}
