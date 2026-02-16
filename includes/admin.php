<?php
if (!defined('ABSPATH')) exit;

class MMCA_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_post_mmca_send_test', array(__CLASS__, 'send_test_email'));
        add_action('admin_post_mmca_clear_logs', array(__CLASS__, 'clear_logs'));
    }

    public static function menu() {
        add_management_page(
            'Critical alerts',
            'Critical alerts',
            'manage_options',
            'mm-critical-alerts',
            array(__CLASS__, 'page')
        );
    }

    public static function register_settings() {
        register_setting('mmca_settings_group', 'mmca_settings', array(
            'type' => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize_settings'),
            'default' => array(),
        ));

        add_settings_section('mmca_main', 'Alert settings', function () {
            echo '<p>Email notifications for fatal/critical PHP errors. Logged errors can be reviewed below.</p>';
        }, 'mmca');

        add_settings_field('to_email', 'Send alerts to', array(__CLASS__, 'field_to_email'), 'mmca', 'mmca_main');
        add_settings_field('subject_prefix', 'Subject prefix', array(__CLASS__, 'field_subject_prefix'), 'mmca', 'mmca_main');
        add_settings_field('throttle_minutes', 'Throttle minutes', array(__CLASS__, 'field_throttle_minutes'), 'mmca', 'mmca_main');
        add_settings_field('hosting_logs_url', 'Hosting error logs URL', array(__CLASS__, 'field_hosting_logs_url'), 'mmca', 'mmca_main');
        add_settings_field('include_request', 'Include request details', array(__CLASS__, 'field_include_request'), 'mmca', 'mmca_main');
        add_settings_field('include_user', 'Include user ID', array(__CLASS__, 'field_include_user'), 'mmca', 'mmca_main');
        add_settings_field('only_frontend', 'Only alert on front-end', array(__CLASS__, 'field_only_frontend'), 'mmca', 'mmca_main');
        add_settings_field('ignore_cli', 'Ignore CLI', array(__CLASS__, 'field_ignore_cli'), 'mmca', 'mmca_main');
        add_settings_field('ignore_wp_cron', 'Ignore WP-Cron', array(__CLASS__, 'field_ignore_wp_cron'), 'mmca', 'mmca_main');
    }

    public static function sanitize_settings($in) {
        $out = array();
        $out['to_email'] = isset($in['to_email']) ? sanitize_email($in['to_email']) : get_option('admin_email');
        $out['subject_prefix'] = isset($in['subject_prefix']) ? sanitize_text_field($in['subject_prefix']) : '🚨 Critical error';
        $out['throttle_minutes'] = isset($in['throttle_minutes']) ? max(0, (int)$in['throttle_minutes']) : 30;

        $out['hosting_logs_url'] = isset($in['hosting_logs_url']) ? esc_url_raw($in['hosting_logs_url']) : '';

        $out['include_request'] = !empty($in['include_request']) ? 1 : 0;
        $out['include_user'] = !empty($in['include_user']) ? 1 : 0;
        $out['only_frontend'] = !empty($in['only_frontend']) ? 1 : 0;
        $out['ignore_cli'] = !empty($in['ignore_cli']) ? 1 : 0;
        $out['ignore_wp_cron'] = !empty($in['ignore_wp_cron']) ? 1 : 0;

        return $out;
    }

    public static function get_settings() {
        $s = get_option('mmca_settings', array());
        return is_array($s) ? $s : array();
    }

    public static function field_to_email() {
        $s = self::get_settings();
        $val = isset($s['to_email']) ? esc_attr($s['to_email']) : esc_attr(get_option('admin_email'));
        echo '<input type="email" name="mmca_settings[to_email]" value="'.$val.'" class="regular-text" />';
        echo '<p class="description">Use a team inbox if you want multiple people to see alerts.</p>';
    }

    public static function field_subject_prefix() {
        $s = self::get_settings();
        $val = isset($s['subject_prefix']) ? esc_attr($s['subject_prefix']) : '🚨 Critical error';
        echo '<input type="text" name="mmca_settings[subject_prefix]" value="'.$val.'" class="regular-text" />';
        echo '<p class="description">Example: "🚨 Your website has a critical error 🚨"</p>';
    }

    public static function field_throttle_minutes() {
        $s = self::get_settings();
        $val = isset($s['throttle_minutes']) ? (int)$s['throttle_minutes'] : 30;
        echo '<input type="number" min="0" step="1" name="mmca_settings[throttle_minutes]" value="'.esc_attr($val).'" />';
        echo '<p class="description">Prevents spam if the same fatal error repeats. 0 disables throttling.</p>';
    }

    public static function field_hosting_logs_url() {
        $s = self::get_settings();
        $val = isset($s['hosting_logs_url']) ? esc_attr($s['hosting_logs_url']) : '';
        echo '<input type="url" name="mmca_settings[hosting_logs_url]" value="'.$val.'" class="regular-text" placeholder="https://..." />';
        echo '<p class="description">Paste the direct 20i error logs URL for this site (optional).</p>';
    }

    public static function field_include_request() {
        $s = self::get_settings();
        $checked = !empty($s['include_request']) ? 'checked' : '';
        echo '<label><input type="checkbox" name="mmca_settings[include_request]" value="1" '.$checked.' /> Include URL, method, IP, and user agent</label>';
    }

    public static function field_include_user() {
        $s = self::get_settings();
        $checked = !empty($s['include_user']) ? 'checked' : '';
        echo '<label><input type="checkbox" name="mmca_settings[include_user]" value="1" '.$checked.' /> Include current user ID (if available)</label>';
    }

    public static function field_only_frontend() {
        $s = self::get_settings();
        $checked = !empty($s['only_frontend']) ? 'checked' : '';
        echo '<label><input type="checkbox" name="mmca_settings[only_frontend]" value="1" '.$checked.' /> Only alert on front-end requests</label>';
        echo '<p class="description">Useful if you get noisy errors during plugin updates in wp-admin.</p>';
    }

    public static function field_ignore_cli() {
        $s = self::get_settings();
        $checked = !empty($s['ignore_cli']) ? 'checked' : '';
        echo '<label><input type="checkbox" name="mmca_settings[ignore_cli]" value="1" '.$checked.' /> Ignore WP-CLI and CLI runs</label>';
    }

    public static function field_ignore_wp_cron() {
        $s = self::get_settings();
        $checked = !empty($s['ignore_wp_cron']) ? 'checked' : '';
        echo '<label><input type="checkbox" name="mmca_settings[ignore_wp_cron]" value="1" '.$checked.' /> Ignore WP-Cron</label>';
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;

        $logs_per_page = 30;
        $paged = isset($_GET['mmca_page']) ? max(1, (int)$_GET['mmca_page']) : 1;
        $offset = ($paged - 1) * $logs_per_page;

        $total = MMCA_Logger::count_logs();
        $logs  = MMCA_Logger::get_logs($logs_per_page, $offset);
        $pages = max(1, (int)ceil($total / $logs_per_page));

        echo '<div class="wrap">';
        echo '<h1>Critical alerts</h1>';

        if (isset($_GET['mmca_notice']) && $_GET['mmca_notice'] === 'test_sent') {
            echo '<div class="notice notice-success is-dismissible"><p>Test email sent.</p></div>';
        }

        echo '<form method="post" action="options.php" style="max-width: 900px;">';
        settings_fields('mmca_settings_group');
        do_settings_sections('mmca');
        submit_button('Save settings');
        echo '</form>';

        echo '<hr/>';

        $test_url = wp_nonce_url(admin_url('admin-post.php?action=mmca_send_test'), 'mmca_send_test');
        $clear_url = wp_nonce_url(admin_url('admin-post.php?action=mmca_clear_logs'), 'mmca_clear_logs');

        echo '<h2>Tools</h2>';
        echo '<p>';
        echo '<a class="button" href="'.esc_url($test_url).'">Send test email</a> ';
        echo '<a class="button button-secondary" href="'.esc_url($clear_url).'" onclick="return confirm(\'Clear all logged errors?\');">Clear logs</a>';
        echo '</p>';

        echo '<h2>Logged errors</h2>';

        if (empty($logs)) {
            echo '<p>No errors logged yet.</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th style="width:160px;">Time (UTC)</th>';
            echo '<th>Message</th>';
            echo '<th style="width:180px;">File</th>';
            echo '<th style="width:80px;">Line</th>';
            echo '</tr></thead><tbody>';

            foreach ($logs as $row) {
                $msg = wp_strip_all_tags($row['message']);
                if (strlen($msg) > 160) $msg = substr($msg, 0, 160) . '…';

                $file = (string)$row['file'];
                $file_short = $file;
                if (strlen($file_short) > 55) $file_short = '…' . substr($file_short, -54);

                $detail_url = add_query_arg(array(
                    'page' => 'mm-critical-alerts',
                    'mmca_view' => (int)$row['id'],
                ), admin_url('tools.php'));

                echo '<tr>';
                echo '<td>'.esc_html($row['created_utc']).'</td>';
                echo '<td><a href="'.esc_url($detail_url).'">'.esc_html($msg).'</a></td>';
                echo '<td>'.esc_html($file_short).'</td>';
                echo '<td>'.esc_html($row['line']).'</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            if ($pages > 1) {
                echo '<p style="margin-top:10px;">';
                for ($i = 1; $i <= $pages; $i++) {
                    $u = add_query_arg(array('page'=>'mm-critical-alerts','mmca_page'=>$i), admin_url('tools.php'));
                    if ($i === $paged) {
                        echo '<strong style="margin-right:8px;">'.(int)$i.'</strong>';
                    } else {
                        echo '<a style="margin-right:8px;" href="'.esc_url($u).'">'.(int)$i.'</a>';
                    }
                }
                echo '</p>';
            }
        }

        if (isset($_GET['mmca_view'])) {
            $id = (int)$_GET['mmca_view'];
            $log = MMCA_Logger::get_log($id);
            if ($log) {
                echo '<hr/>';
                echo '<h2>Log detail #'.(int)$id.'</h2>';
                echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width: 900px;white-space: pre-wrap;">';
                echo esc_html(self::format_log_detail($log));
                echo '</pre>';
            }
        }

        echo '</div>';
    }

    private static function format_log_detail($log) {
        $lines = array();
        $lines[] = 'Time (UTC): ' . $log['created_utc'];
        $lines[] = 'Signature: ' . $log['signature'];
        $lines[] = 'Type: ' . MMCA_Logger::error_type_label((int)$log['error_type']) . ' (' . (int)$log['error_type'] . ')';
        $lines[] = 'Message: ' . $log['message'];
        $lines[] = 'File: ' . $log['file'];
        $lines[] = 'Line: ' . $log['line'];
        $lines[] = 'URL: ' . $log['url'];
        $lines[] = 'User ID: ' . $log['user_id'];
        $lines[] = 'PHP SAPI: ' . $log['php_sapi'];
        return implode("\n", $lines);
    }

    public static function send_test_email() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mmca_send_test')) {
            wp_die('Invalid nonce');
        }

        $s = self::get_settings();
        $to = !empty($s['to_email']) ? sanitize_email($s['to_email']) : get_option('admin_email');
        $subject_prefix = !empty($s['subject_prefix']) ? (string)$s['subject_prefix'] : '🚨 Critical error';

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        $subject = trim($subject_prefix) . ' - TEST - ' . $site_name;
        $body = "This is a test email from MM Critical Alerts.\n\nIf you received this, wp_mail is working for this site.\n";

        add_filter('wp_mail_content_type', function () { return 'text/plain; charset=UTF-8'; });
        wp_mail($to, $subject, $body);

        wp_safe_redirect(add_query_arg(array('page'=>'mm-critical-alerts','mmca_notice'=>'test_sent'), admin_url('tools.php')));
        exit;
    }

    public static function clear_logs() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mmca_clear_logs')) {
            wp_die('Invalid nonce');
        }
        MMCA_Logger::delete_all();
        wp_safe_redirect(admin_url('tools.php?page=mm-critical-alerts'));
        exit;
    }
}
