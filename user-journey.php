<?php
/**
 * Plugin Name: User Journey
 * Description: ثبت مسیر بازدید کاربران و خروجی Excel؛ هر کاربر در یک ردیف و هر بازدید در یک ستون.
 * Version: 1.2.2
 * Author: Delaram
 * Author URI: https://github.com/Delaram
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: user-journey-main
 */

if (!defined('ABSPATH')) { exit; }

final class User_Journey {
    const VERSION = '1.2.2';
    const DB_VERSION = '1.2';
    const VISITOR_COOKIE = 'uj_visitor_id';
    const JOURNEY_COOKIE = 'uj_journey';
    const MAX_BATCH_SIZE = 20;

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));
        add_action('plugins_loaded', array($this, 'maybe_upgrade'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_tracker'));
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_post_uj_export', array($this, 'export_xlsx'));
        add_action('admin_post_uj_delete_data', array($this, 'delete_data'));
        add_action('uj_daily_cleanup', array($this, 'cleanup_old_data'));
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'uj_user_journey';
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            visitor_id varchar(64) NOT NULL,
            visit_order int(10) unsigned NOT NULL DEFAULT 0,
            event_key varchar(32) DEFAULT NULL,
            url text NOT NULL,
            referrer text NULL,
            visited_at datetime(3) NOT NULL,
            browser varchar(80) NOT NULL DEFAULT '',
            device varchar(40) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY visitor_event (visitor_id, event_key),
            KEY visitor_time (visitor_id, visited_at),
            KEY visited_at (visited_at)
        ) {$charset};";
        dbDelta($sql);
        update_option('uj_db_version', self::DB_VERSION);
        if (!wp_next_scheduled('uj_daily_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'uj_daily_cleanup');
        }
    }

    public function maybe_upgrade() {
        if (get_option('uj_db_version') !== self::DB_VERSION) {
            self::activate();
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('uj_daily_cleanup');
    }

    public function enqueue_tracker() {
        if (is_admin() || wp_doing_ajax() || is_feed() || is_robots() || current_user_can('manage_options')) { return; }
        wp_enqueue_script(
            'user-journey',
            plugins_url('assets/tracker.js', __FILE__),
            array(),
            self::VERSION,
            true
        );
        wp_localize_script('user-journey', 'UJ_CONFIG', array(
            'endpoint' => esc_url_raw(rest_url('user-journey/v1/visit')),
            'visitorCookie' => self::VISITOR_COOKIE,
            'journeyCookie' => self::JOURNEY_COOKIE,
            'cookieDays' => 365,
        ));
    }

    public function register_routes() {
        register_rest_route('user-journey/v1', '/visit', array(
            'methods' => 'POST',
            'callback' => array($this, 'record_visit'),
            'permission_callback' => '__return_true',
            'args' => array('visitor_id' => array('required' => true, 'type' => 'string')),
        ));
    }

    public function record_visit(WP_REST_Request $request) {
        global $wpdb;

        $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';
        if (preg_match('/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|headless/i', $user_agent)) {
            return rest_ensure_response(array('success' => true, 'ignored' => 'bot'));
        }

        $visitor_id = sanitize_text_field($request->get_param('visitor_id'));
        if (!preg_match('/^[a-f0-9-]{36}$/i', $visitor_id)) {
            return new WP_Error('invalid_visitor', 'شناسه کاربر معتبر نیست.', array('status' => 400));
        }

        $visits = $request->get_param('visits');
        if (!is_array($visits)) {
            // سازگاری با Payload نسخه‌های قبلی افزونه.
            $visits = array(array(
                'url' => $request->get_param('url'),
                'referrer' => $request->get_param('referrer'),
                'timestamp' => $request->get_param('timestamp'),
                'browser' => $request->get_param('browser'),
                'device' => $request->get_param('device'),
            ));
        }
        $visits = array_slice($visits, 0, self::MAX_BATCH_SIZE);
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $records = array();

        foreach ($visits as $visit) {
            if (!is_array($visit)) { continue; }
            $url = esc_url_raw(isset($visit['url']) ? $visit['url'] : '');
            $url = substr($url, 0, 4096);
            $url_host = wp_parse_url($url, PHP_URL_HOST);
            if (!$url || !$url_host || strtolower($site_host) !== strtolower($url_host)) { continue; }

            try {
                $date = new DateTimeImmutable(sanitize_text_field(isset($visit['timestamp']) ? $visit['timestamp'] : ''));
                $utc_date = $date->setTimezone(new DateTimeZone('UTC'));
                $visited_at = $utc_date->format('Y-m-d H:i:s.v');
            } catch (Exception $e) {
                continue;
            }

            $browser = substr(sanitize_text_field(isset($visit['browser']) ? $visit['browser'] : ''), 0, 80);
            $device = substr(sanitize_text_field(isset($visit['device']) ? $visit['device'] : ''), 0, 40);
            $referrer = empty($visit['referrer']) ? '' : substr(esc_url_raw($visit['referrer']), 0, 4096);
            $five_second_bucket = (string) floor((float) $utc_date->format('U.u') / 5);
            $event_key = md5($url . '|' . $five_second_bucket);

            $records[] = array(
                $visitor_id,
                $event_key,
                $url,
                $referrer,
                $visited_at,
                $browser,
                $device,
                current_time('mysql', true),
            );
        }

        if (!$records) {
            return new WP_Error('invalid_visits', 'هیچ بازدید معتبری دریافت نشد.', array('status' => 400));
        }

        $inserted = 0;
        foreach ($records as $record) {
            // A direct write is required because visits are stored in the plugin's custom table.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO %i
                    (visitor_id, visit_order, event_key, url, referrer, visited_at, browser, device, created_at)
                    VALUES (%s, 0, %s, %s, %s, %s, %s, %s, %s)',
                    self::table_name(),
                    $record[0],
                    $record[1],
                    $record[2],
                    $record[3],
                    $record[4],
                    $record[5],
                    $record[6],
                    $record[7]
                )
            );
            if (false === $result) {
                return new WP_Error('db_error', 'ذخیره بازدید انجام نشد.', array('status' => 500));
            }
            $inserted += (int) $result;
        }

        if ($inserted > 0) {
            wp_cache_delete('dashboard_stats', 'user_journey');
            wp_cache_delete('export_rows', 'user_journey');
        }
        return rest_ensure_response(array('success' => true, 'accepted' => (int) $inserted));
    }

    public function admin_menu() {
        add_menu_page(
            'مسیر کاربران', 'مسیر کاربران', 'manage_options', 'user-journey',
            array($this, 'admin_page'), 'dashicons-randomize', 58
        );
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) { return; }
        global $wpdb;
        $stats = wp_cache_get('dashboard_stats', 'user_journey');
        if (false === $stats) {
            // Direct reads are required because this information lives in the plugin's custom table.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $users = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT visitor_id) FROM %i', self::table_name()));
            $visits = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', self::table_name()));
            $last = $wpdb->get_var($wpdb->prepare('SELECT MAX(visited_at) FROM %i', self::table_name()));
            $recent = $wpdb->get_results($wpdb->prepare('SELECT visitor_id, COUNT(*) visits, MIN(visited_at) first_visit, MAX(visited_at) last_visit FROM %i GROUP BY visitor_id ORDER BY last_visit DESC LIMIT %d', self::table_name(), 50));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $stats = array('users' => $users, 'visits' => $visits, 'last' => $last, 'recent' => $recent);
            wp_cache_set('dashboard_stats', $stats, 'user_journey', MINUTE_IN_SECONDS);
        }
        $users = $stats['users'];
        $visits = $stats['visits'];
        $last = $stats['last'];
        $recent = $stats['recent'];
        ?>
        <div class="wrap" dir="rtl">
            <h1>مسیر بازدید کاربران</h1>
            <?php if (get_transient('uj_deleted_' . get_current_user_id())): delete_transient('uj_deleted_' . get_current_user_id()); ?><div class="notice notice-success"><p>داده‌های مسیر کاربران حذف شد.</p></div><?php endif; ?>
            <p><strong>تعداد کاربران:</strong> <?php echo esc_html(number_format_i18n($users)); ?> &nbsp; | &nbsp;
               <strong>تعداد بازدیدها:</strong> <?php echo esc_html(number_format_i18n($visits)); ?> &nbsp; | &nbsp;
               <strong>آخرین ثبت:</strong> <?php echo esc_html($last ?: '—'); ?> (UTC)</p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=uj_export'), 'uj_export')); ?>">خروجی Excel</a>
            </p>
            <table class="widefat striped">
                <thead><tr><th>شناسه کاربر</th><th>تعداد بازدید</th><th>اولین بازدید</th><th>آخرین بازدید</th></tr></thead>
                <tbody>
                <?php if (!$recent): ?><tr><td colspan="4">هنوز بازدیدی ثبت نشده است.</td></tr><?php endif; ?>
                <?php foreach ($recent as $row): ?>
                    <tr><td><code><?php echo esc_html($row->visitor_id); ?></code></td><td><?php echo esc_html($row->visits); ?></td><td><?php echo esc_html($row->first_visit); ?></td><td><?php echo esc_html($row->last_visit); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <hr>
            <h2>حذف همه داده‌ها</h2>
            <p>این عملیات برگشت‌پذیر نیست. قبل از حذف، خروجی Excel بگیرید.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('همه مسیرهای ثبت‌شده حذف شوند؟');">
                <input type="hidden" name="action" value="uj_delete_data">
                <?php wp_nonce_field('uj_delete_data'); ?>
                <button class="button button-link-delete" type="submit">حذف همه داده‌ها</button>
            </form>
        </div>
        <?php
    }

    public function export_xlsx() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز.'); }
        check_admin_referer('uj_export');
        if (!class_exists('ZipArchive')) { wp_die('افزونه ZipArchive روی PHP فعال نیست.'); }

        global $wpdb;
        // Direct reads are required because export data lives in the plugin's custom table.
        // The export must always contain current data, so deliberately do not cache it.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results($wpdb->prepare('SELECT visitor_id, url, referrer, visited_at, browser, device FROM %i ORDER BY visitor_id ASC, visited_at ASC, id ASC', self::table_name()), ARRAY_A);
        $grouped = array();
        $max_visits = 0;
        foreach ($rows as $row) {
            $grouped[$row['visitor_id']][] = $row;
            $max_visits = max($max_visits, count($grouped[$row['visitor_id']]));
        }

        $matrix = array();
        $header = array('شناسه کاربر');
        for ($i = 1; $i <= $max_visits; $i++) { $header[] = 'بازدید ' . $i; }
        $matrix[] = $header;
        foreach ($grouped as $visitor_id => $visits) {
            $line = array($visitor_id);
            foreach ($visits as $visit) {
                $line[] = wp_json_encode(array(
                    'url' => $visit['url'],
                    'referrer' => $visit['referrer'] ?: null,
                    'timestamp' => str_replace(' ', 'T', $visit['visited_at']) . 'Z',
                    'browser' => $visit['browser'],
                    'device' => $visit['device'],
                ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $matrix[] = $line;
        }

        $tmp = wp_tempnam('user-journey.xlsx');
        if (!$tmp) {
            wp_die('ساخت فایل موقت Excel ممکن نشد.');
        }
        $this->build_xlsx($tmp, $matrix);
        $filename = 'user-journey-' . gmdate('Y-m-d-His') . '.xlsx';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!WP_Filesystem()) {
            wp_delete_file($tmp);
            wp_die('دسترسی به فایل موقت Excel ممکن نشد.');
        }
        global $wp_filesystem;
        $contents = $wp_filesystem->get_contents($tmp);
        if (false === $contents) {
            wp_delete_file($tmp);
            wp_die('خواندن فایل Excel ممکن نشد.');
        }
        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($contents));
        // Binary XLSX output cannot be escaped without corrupting the file.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $contents;
        wp_delete_file($tmp);
        exit;
    }

    private function build_xlsx($path, array $matrix) {
        $zip = new ZipArchive();
        if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) { wp_die('ساخت فایل Excel ممکن نشد.'); }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="مسیر کاربران" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2271B1"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf></cellXfs></styleSheet>');

        $max_column = max(2, count($matrix[0]));
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="40" customWidth="1"/><col min="2" max="' . $max_column . '" width="70" customWidth="1"/></cols><sheetData>';
        foreach ($matrix as $rIndex => $row) {
            $r = $rIndex + 1;
            $sheet .= '<row r="' . $r . '"' . ($r === 1 ? ' ht="24" customHeight="1"' : '') . '>';
            foreach ($row as $cIndex => $value) {
                $ref = $this->column_name($cIndex + 1) . $r;
                $safe = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $sheet .= '<c r="' . $ref . '" t="inlineStr" s="' . ($r === 1 ? '1' : '0') . '"><is><t xml:space="preserve">' . $safe . '</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $last_col = $this->column_name(max(1, count($matrix[0])));
        $sheet .= '</sheetData><autoFilter ref="A1:' . $last_col . '1"/></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
    }

    private function column_name($number) {
        $name = '';
        while ($number > 0) { $number--; $name = chr(65 + ($number % 26)) . $name; $number = intdiv($number, 26); }
        return $name;
    }

    public function delete_data() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز.'); }
        check_admin_referer('uj_delete_data');
        global $wpdb;
        // A direct write is required because data is stored in the plugin's custom table.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare('TRUNCATE TABLE %i', self::table_name()));
        wp_cache_delete('dashboard_stats', 'user_journey');
        wp_cache_delete('export_rows', 'user_journey');
        set_transient('uj_deleted_' . get_current_user_id(), 1, 30);
        wp_safe_redirect(admin_url('admin.php?page=user-journey'));
        exit;
    }

    public function cleanup_old_data() {
        global $wpdb;
        $days = max(30, (int) apply_filters('uj_retention_days', 365));
        // A direct write is required because data is stored in the plugin's custom table.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE visited_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', self::table_name(), $days));
        if (false !== $deleted && $deleted > 0) {
            wp_cache_delete('dashboard_stats', 'user_journey');
            wp_cache_delete('export_rows', 'user_journey');
        }
    }
}

User_Journey::instance();
