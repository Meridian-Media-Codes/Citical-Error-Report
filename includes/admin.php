<?php
if (!defined('ABSPATH')) exit;

class MMCA_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_post_mmca_send_test', array(__CLASS__, 'send_test_email'));
        add_action('admin_post_mmca_send_test_alert', array(__CLASS__, 'send_test_alert'));
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
            echo '<p>Email notifications for fatal/critical PHP errors. Every fatal is logged below; these settings decide which ones are worth an email.</p>';
        }, 'mmca');

        add_settings_section('mmca_noise', 'Noise control', function () {
            echo '<p>Background requests (WP-Cron, MailPoet\'s own cron, XML-RPC, CLI) rarely mean the site is down. They only email if the same error keeps repeating.</p>';
        }, 'mmca');

        add_settings_field('to_email', 'Send alerts to', array(__CLASS__, 'field_to_email'), 'mmca', 'mmca_main');
        add_settings_field('subject_prefix', 'Subject prefix', array(__CLASS__, 'field_subject_prefix'), 'mmca', 'mmca_main');
        add_settings_field('throttle_minutes', 'Throttle minutes', array(__CLASS__, 'field_throttle_minutes'), 'mmca', 'mmca_main');
        add_settings_field('hosting_logs_url', 'Hosting error logs URL', array(__CLASS__, 'field_hosting_logs_url'), 'mmca', 'mmca_main');
        add_settings_field('include_request', 'Include request details', array(__CLASS__, 'field_include_request'), 'mmca', 'mmca_main');
        add_settings_field('include_user', 'Include user ID', array(__CLASS__, 'field_include_user'), 'mmca', 'mmca_main');

        add_settings_field('only_frontend', 'Only alert on front-end', array(__CLASS__, 'field_only_frontend'), 'mmca', 'mmca_noise');
        add_settings_field('ignore_cli', 'Ignore CLI', array(__CLASS__, 'field_ignore_cli'), 'mmca', 'mmca_noise');
        add_settings_field('ignore_wp_cron', 'Ignore WP-Cron', array(__CLASS__, 'field_ignore_wp_cron'), 'mmca', 'mmca_noise');
        add_settings_field('repeat', 'Background repeat rule', array(__CLASS__, 'field_repeat'), 'mmca', 'mmca_noise');
        add_settings_field('quiet_plugins', 'Quiet plugins', array(__CLASS__, 'field_quiet_plugins'), 'mmca', 'mmca_noise');
        add_settings_field('ignore_patterns', 'Ignore patterns', array(__CLASS__, 'field_ignore_patterns'), 'mmca', 'mmca_noise');
    }

    public static function sanitize_settings($in) {
        $d = mm_ca_default_settings();
        $out = array();
        $out['to_email'] = isset($in['to_email']) ? sanitize_email($in['to_email']) : get_option('admin_email');
        $out['subject_prefix'] = isset($in['subject_prefix']) ? sanitize_text_field($in['subject_prefix']) : $d['subject_prefix'];
        $out['throttle_minutes'] = isset($in['throttle_minutes']) ? max(0, (int)$in['throttle_minutes']) : $d['throttle_minutes'];

        $out['hosting_logs_url'] = isset($in['hosting_logs_url']) ? esc_url_raw($in['hosting_logs_url']) : '';

        $out['include_request'] = !empty($in['include_request']) ? 1 : 0;
        $out['include_user'] = !empty($in['include_user']) ? 1 : 0;
        $out['only_frontend'] = !empty($in['only_frontend']) ? 1 : 0;
        $out['ignore_cli'] = !empty($in['ignore_cli']) ? 1 : 0;
        $out['ignore_wp_cron'] = !empty($in['ignore_wp_cron']) ? 1 : 0;

        $out['repeat_count'] = isset($in['repeat_count']) ? max(1, (int)$in['repeat_count']) : $d['repeat_count'];
        $out['repeat_window'] = isset($in['repeat_window']) ? max(1, (int)$in['repeat_window']) : $d['repeat_window'];

        $out['quiet_plugins'] = isset($in['quiet_plugins'])
            ? strtolower(sanitize_textarea_field($in['quiet_plugins']))
            : $d['quiet_plugins'];
        $out['ignore_patterns'] = isset($in['ignore_patterns'])
            ? sanitize_textarea_field($in['ignore_patterns'])
            : $d['ignore_patterns'];

        return $out;
    }

    public static function get_settings() {
        return mm_ca_get_settings();
    }

    public static function field_to_email() {
        $s = self::get_settings();
        echo '<input type="email" name="mmca_settings[to_email]" value="'.esc_attr($s['to_email']).'" class="regular-text" />';
        echo '<p class="description">Use a team inbox if you want multiple people to see alerts.</p>';
    }

    public static function field_subject_prefix() {
        $s = self::get_settings();
        echo '<input type="text" name="mmca_settings[subject_prefix]" value="'.esc_attr($s['subject_prefix']).'" class="regular-text" />';
        echo '<p class="description">Example: "🚨 Your website has a critical error 🚨". The site name and likely culprit plugin are added automatically.</p>';
    }

    public static function field_throttle_minutes() {
        $s = self::get_settings();
        echo '<input type="number" min="0" step="1" name="mmca_settings[throttle_minutes]" value="'.esc_attr((int)$s['throttle_minutes']).'" />';
        echo '<p class="description">Minimum gap between emails for the same error. The next email says how many repeats were suppressed. 0 disables throttling.</p>';
    }

    public static function field_hosting_logs_url() {
        $s = self::get_settings();
        echo '<input type="url" name="mmca_settings[hosting_logs_url]" value="'.esc_attr($s['hosting_logs_url']).'" class="regular-text" placeholder="https://..." />';
        echo '<p class="description">Paste the direct 20i error logs URL for this site (optional).</p>';
    }

    public static function field_include_request() {
        $s = self::get_settings();
        echo '<label><input type="checkbox" name="mmca_settings[include_request]" value="1" '.checked(!empty($s['include_request']), true, false).' /> Include URL, method, IP, and user agent</label>';
    }

    public static function field_include_user() {
        $s = self::get_settings();
        echo '<label><input type="checkbox" name="mmca_settings[include_user]" value="1" '.checked(!empty($s['include_user']), true, false).' /> Include current user ID (if available)</label>';
    }

    public static function field_only_frontend() {
        $s = self::get_settings();
        echo '<label><input type="checkbox" name="mmca_settings[only_frontend]" value="1" '.checked(!empty($s['only_frontend']), true, false).' /> Don\'t email for wp-admin page loads (still logged)</label>';
        echo '<p class="description">Leave off if you want to know when a client\'s dashboard is broken.</p>';
    }

    public static function field_ignore_cli() {
        $s = self::get_settings();
        echo '<label><input type="checkbox" name="mmca_settings[ignore_cli]" value="1" '.checked(!empty($s['ignore_cli']), true, false).' /> Ignore WP-CLI and CLI runs (not logged)</label>';
    }

    public static function field_ignore_wp_cron() {
        $s = self::get_settings();
        echo '<label><input type="checkbox" name="mmca_settings[ignore_wp_cron]" value="1" '.checked(!empty($s['ignore_wp_cron']), true, false).' /> Never email for WP-Cron fatals (still logged)</label>';
        echo '<p class="description">If off, cron fatals follow the repeat rule below.</p>';
    }

    public static function field_repeat() {
        $s = self::get_settings();
        echo 'Email only after <input type="number" min="1" step="1" style="width:70px" name="mmca_settings[repeat_count]" value="'.esc_attr((int)$s['repeat_count']).'" /> ';
        echo 'occurrences within <input type="number" min="1" step="1" style="width:70px" name="mmca_settings[repeat_window]" value="'.esc_attr((int)$s['repeat_window']).'" /> minutes';
        echo '<p class="description">Applies to background requests (MailPoet router, XML-RPC, CLI, and cron if not ignored). Page loads, wp-admin, login, AJAX and REST fatals email straight away.</p>';
    }

    public static function field_quiet_plugins() {
        $s = self::get_settings();
        echo '<textarea name="mmca_settings[quiet_plugins]" rows="3" class="regular-text" style="width:25em">'.esc_textarea($s['quiet_plugins']).'</textarea>';
        echo '<p class="description">Plugin folder names, one per line. A fatal in one of these is logged but only emailed if it breaks a normal front-end or login page. Not emailed from wp-admin, AJAX, REST, cron or background requests.</p>';
    }

    public static function field_ignore_patterns() {
        $s = self::get_settings();
        echo '<textarea name="mmca_settings[ignore_patterns]" rows="3" class="regular-text" style="width:25em">'.esc_textarea($s['ignore_patterns']).'</textarea>';
        echo '<p class="description">Optional. Text to match in the error message or file path, one per line. Matching errors are logged but never emailed, so keep these specific.</p>';
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

        if (isset($_GET['mmca_notice'])) {
            $notice = sanitize_key($_GET['mmca_notice']);
            $status = isset($_GET['mmca_status']) ? sanitize_key($_GET['mmca_status']) : '';
            if ($notice === 'test_sent') {
                if ($status === 'failed') {
                    echo '<div class="notice notice-error"><p>Test email FAILED: neither wp_mail nor PHP mail() could send it. Alerts will not reach you from this site until mail is fixed.</p></div>';
                } elseif ($status === 'sent_fallback') {
                    echo '<div class="notice notice-warning"><p>wp_mail failed, but the PHP mail() fallback sent the test. Alerts will arrive, but check this site\'s SMTP plugin.</p></div>';
                } else {
                    echo '<div class="notice notice-success is-dismissible"><p>Test email sent.</p></div>';
                }
            } elseif ($notice === 'test_alert') {
                $class = ($status === 'sent') ? 'notice-success' : (($status === 'sent_fallback') ? 'notice-warning' : 'notice-error');
                echo '<div class="notice '.esc_attr($class).' is-dismissible"><p>Full-path test alert: logged and mail result was <strong>'.esc_html($status ?: 'unknown').'</strong>. Check the log below for the new entry.</p></div>';
            }
        }

        if (!defined('MMCA_LOADED_AS_MU')) {
            echo '<div class="notice notice-warning"><p><strong>Not loaded as a must-use plugin.</strong> A fatal error in any plugin that loads before this one (or while plugins are loading) can happen before alerts are armed. Copy <code>mu-plugin-loader/mm-critical-alerts-loader.php</code> to <code>wp-content/mu-plugins/</code> to close that gap.</p></div>';
        }

        $problems = MMCA_Logger::count_mail_problems();
        if ($problems > 0) {
            echo '<div class="notice notice-error"><p><strong>'.(int)$problems.' alert(s) in the last 7 days did not confirm as sent</strong> (mail status "pending" or "failed" in the log). Email delivery from this site may be broken.</p></div>';
        }

        echo '<form method="post" action="options.php" style="max-width: 900px;">';
        settings_fields('mmca_settings_group');
        do_settings_sections('mmca');
        submit_button('Save settings');
        echo '</form>';

        echo '<hr/>';

        $test_url  = wp_nonce_url(admin_url('admin-post.php?action=mmca_send_test'), 'mmca_send_test');
        $alert_url = wp_nonce_url(admin_url('admin-post.php?action=mmca_send_test_alert'), 'mmca_send_test_alert');
        $clear_url = wp_nonce_url(admin_url('admin-post.php?action=mmca_clear_logs'), 'mmca_clear_logs');

        echo '<h2>Tools</h2>';
        echo '<p>';
        echo '<a class="button" href="'.esc_url($test_url).'">Send test email</a> ';
        echo '<a class="button" href="'.esc_url($alert_url).'">Send full-path test alert</a> ';
        echo '<a class="button button-secondary" href="'.esc_url($clear_url).'" onclick="return confirm(\'Clear all logged errors?\');">Clear logs</a>';
        echo '</p>';
        echo '<p class="description">The full-path test runs the real alert code (logging, formatting, mail with fallback) using a simulated fatal. It does not test whether PHP calls the handler during a genuine crash.</p>';

        echo '<h2>Logged errors</h2>';

        if (empty($logs)) {
            echo '<p>No errors logged yet.</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th style="width:140px;">Time (UTC)</th>';
            echo '<th style="width:70px;">Request</th>';
            echo '<th style="width:150px;">Culprit</th>';
            echo '<th>Message</th>';
            echo '<th style="width:260px;">Decision</th>';
            echo '</tr></thead><tbody>';

            foreach ($logs as $row) {
                $msg = wp_strip_all_tags($row['message']);
                if (strlen($msg) > 140) $msg = substr($msg, 0, 140) . '…';

                $detail_url = add_query_arg(array(
                    'page' => 'mm-critical-alerts',
                    'mmca_view' => (int)$row['id'],
                ), admin_url('tools.php'));

                $decision = (string)$row['decision'];
                $is_alert = (strpos($decision, 'ALERT') === 0);
                $mail     = (string)$row['mail_status'];
                $mail_bad = in_array($mail, array('pending', 'failed'), true);

                echo '<tr>';
                echo '<td>'.esc_html($row['created_utc']).'</td>';
                echo '<td>'.esc_html($row['context']).'</td>';
                echo '<td>'.esc_html($row['culprit']).'</td>';
                echo '<td><a href="'.esc_url($detail_url).'">'.esc_html($msg).'</a></td>';
                echo '<td>'.($is_alert ? '<strong>' : '').esc_html($decision).($is_alert ? '</strong>' : '');
                if ($is_alert && $mail !== '') {
                    echo ' <span style="color:'.($mail_bad ? '#b32d2e' : '#1a7f37').';">['.esc_html($mail).']</span>';
                }
                echo '</td>';
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
        $lines[] = 'Request kind: ' . $log['context'];
        $lines[] = 'Likely culprit: ' . $log['culprit'];
        $lines[] = 'Decision: ' . $log['decision'];
        $lines[] = 'Mail status: ' . $log['mail_status'];
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
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        $subject = trim($s['subject_prefix']) . ' - TEST - ' . $site_name;
        $body = '<p>This is a test email from MM Critical Alerts.</p><p>If you received this, mail is working for this site.</p>';

        $status = mm_ca_send_mail($to, $subject, $body);

        wp_safe_redirect(add_query_arg(array('page'=>'mm-critical-alerts','mmca_notice'=>'test_sent','mmca_status'=>$status), admin_url('tools.php')));
        exit;
    }

    public static function send_test_alert() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mmca_send_test_alert')) {
            wp_die('Invalid nonce');
        }

        $r = mm_ca_handle_fatal(array(
            'type'    => E_ERROR,
            'message' => 'TEST ONLY - simulated fatal error from MM Critical Alerts. No action needed.',
            'file'    => MMCA_PLUGIN_FILE,
            'line'    => 1,
        ), array('force' => true, 'context' => 'frontend'));

        $status = !empty($r['mail']) ? $r['mail'] : 'unknown';
        wp_safe_redirect(add_query_arg(array('page'=>'mm-critical-alerts','mmca_notice'=>'test_alert','mmca_status'=>$status), admin_url('tools.php')));
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
