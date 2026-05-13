<?php
/**
 * Plugin Name: MoBo WP Analytics
 * Description: افزونه حرفه‌ای و مینیمال آمار بازدید وردپرس با نمودار، UTM، کاربران آنلاین، منابع ورودی، خزنده‌ها، دستگاه‌ها، کشور، صفحات ورود/خروج، نرخ پرش، جستجوی داخلی، گزارش 404، خروجی CSV/JSON و REST API.
 * Version: 2.0.0
 * Author: MoBo
 * Text Domain: mobo-wp-analytics
 * Author URI: http://github.com/mojtababhs/
 */

if (!defined('ABSPATH')) exit;

final class MoBo_WP_Analytics {
    const VERSION = '2.0.0';
    const DB_VERSION = '2.0.0';
    const OPTION_DB_VERSION = 'mobo_analytics_db_version';
    const OPTION_SETTINGS = 'mobo_analytics_settings';
    const COOKIE_VISITOR = 'mobo_analytics_visitor';
    const COOKIE_SESSION = 'mobo_analytics_session';
    const CRON_CLEANUP = 'mobo_analytics_daily_cleanup';

    private static $instance = null;
    private $events_table;

    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->events_table = $wpdb->prefix . 'mobo_analytics_events';

        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'init_cookies'], 1);
        add_action('template_redirect', [$this, 'track_visit'], 99);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'handle_exports']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action(self::CRON_CLEANUP, [$this, 'cleanup_old_data']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        add_shortcode('mobo_wp_analytics', [$this, 'shortcode_stats']);
    }

    public function activate() {
        $this->create_tables();
        $this->ensure_default_settings();
        if (!wp_next_scheduled(self::CRON_CLEANUP)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_CLEANUP);
        }
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    public function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_CLEANUP);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_CLEANUP);
    }

    private function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_time DATETIME NOT NULL,
            event_date DATE NOT NULL,
            visitor_hash CHAR(64) NOT NULL,
            session_hash CHAR(64) NOT NULL,
            user_type VARCHAR(30) NOT NULL DEFAULT 'guest',
            user_role VARCHAR(80) NULL,
            post_id BIGINT UNSIGNED NULL,
            post_type VARCHAR(40) NULL,
            page_url TEXT NOT NULL,
            page_path TEXT NULL,
            page_title TEXT NULL,
            is_404 TINYINT(1) NOT NULL DEFAULT 0,
            internal_search_term VARCHAR(255) NULL,
            referrer TEXT NULL,
            source_type VARCHAR(40) NOT NULL DEFAULT 'direct',
            search_engine VARCHAR(60) NULL,
            utm_source VARCHAR(120) NULL,
            utm_medium VARCHAR(120) NULL,
            utm_campaign VARCHAR(180) NULL,
            utm_term VARCHAR(180) NULL,
            utm_content VARCHAR(180) NULL,
            is_crawler TINYINT(1) NOT NULL DEFAULT 0,
            crawler_name VARCHAR(80) NULL,
            os VARCHAR(80) NULL,
            device_type VARCHAR(30) NULL,
            browser VARCHAR(80) NULL,
            country CHAR(2) NULL,
            lang VARCHAR(20) NULL,
            user_agent TEXT NULL,
            PRIMARY KEY (id),
            KEY event_time (event_time),
            KEY event_date (event_date),
            KEY visitor_hash (visitor_hash),
            KEY session_hash (session_hash),
            KEY user_type (user_type),
            KEY post_id (post_id),
            KEY post_type (post_type),
            KEY source_type (source_type),
            KEY search_engine (search_engine),
            KEY is_crawler (is_crawler),
            KEY is_404 (is_404),
            KEY device_type (device_type),
            KEY country (country),
            KEY utm_campaign (utm_campaign)
        ) $charset;";

        dbDelta($sql);
    }

    private function defaults() {
        return [
            'track_admins' => 0,
            'track_guests' => 1,
            'track_logged_in' => 1,
            'track_crawlers' => 1,
            'anonymize_ua' => 0,
            'online_window_minutes' => 5,
            'retention_days' => 365,
            'enable_rest_api' => 1,
            'public_shortcode_show' => 'views,unique,online',
        ];
    }

    private function ensure_default_settings() {
        update_option(self::OPTION_SETTINGS, wp_parse_args(get_option(self::OPTION_SETTINGS, []), $this->defaults()));
    }

    private function settings() {
        return wp_parse_args(get_option(self::OPTION_SETTINGS, []), $this->defaults());
    }

    public function init_cookies() {
        if (is_admin() || wp_doing_ajax() || defined('REST_REQUEST') || headers_sent()) return;

        if (empty($_COOKIE[self::COOKIE_VISITOR])) {
            $visitor = wp_generate_uuid4();
            setcookie(self::COOKIE_VISITOR, $visitor, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE[self::COOKIE_VISITOR] = $visitor;
        }

        if (empty($_COOKIE[self::COOKIE_SESSION])) {
            $session = wp_generate_uuid4();
            setcookie(self::COOKIE_SESSION, $session, time() + 30 * MINUTE_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE[self::COOKIE_SESSION] = $session;
        } else {
            setcookie(self::COOKIE_SESSION, sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_SESSION])), time() + 30 * MINUTE_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
    }

    private function should_track($is_crawler) {
        $s = $this->settings();
        if ($is_crawler && empty($s['track_crawlers'])) return false;

        if (is_user_logged_in()) {
            if (current_user_can('manage_options') && empty($s['track_admins'])) return false;
            if (!current_user_can('manage_options') && empty($s['track_logged_in'])) return false;
            return true;
        }

        return !empty($s['track_guests']);
    }

    public function track_visit() {
        if (is_admin() || wp_doing_ajax() || defined('REST_REQUEST')) return;

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $crawler = $this->detect_crawler($ua);

        if (!$this->should_track($crawler['is_crawler'])) return;

        $s = $this->settings();
        $url = $this->current_url();
        $path = wp_parse_url($url, PHP_URL_PATH);
        $referrer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        $source = $this->detect_source($referrer);
        $utm = $this->get_utm();
        if (!empty($utm['utm_source'])) {
            $source['type'] = 'campaign';
        }

        $visitor_raw = !empty($_COOKIE[self::COOKIE_VISITOR]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_VISITOR])) : $this->get_ip() . '|' . $ua;
        $session_raw = !empty($_COOKIE[self::COOKIE_SESSION]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_SESSION])) : $visitor_raw . '|' . gmdate('Y-m-d-H-i');
        $visitor_hash = hash('sha256', wp_salt('auth') . '|' . $visitor_raw);
        $session_hash = hash('sha256', wp_salt('secure_auth') . '|' . $session_raw);

        $post_id = is_singular() ? get_queried_object_id() : null;
        $post_type = $post_id ? get_post_type($post_id) : null;
        $user_meta = $this->current_user_meta();
        $search_term = is_search() ? get_search_query(false) : null;

        global $wpdb;
        $wpdb->insert($this->events_table, [
            'event_time' => current_time('mysql'),
            'event_date' => current_time('Y-m-d'),
            'visitor_hash' => $visitor_hash,
            'session_hash' => $session_hash,
            'user_type' => $user_meta['type'],
            'user_role' => $user_meta['role'],
            'post_id' => $post_id,
            'post_type' => $post_type,
            'page_url' => $url,
            'page_path' => $path,
            'page_title' => $post_id ? get_the_title($post_id) : wp_get_document_title(),
            'is_404' => is_404() ? 1 : 0,
            'internal_search_term' => $search_term ? mb_substr($search_term, 0, 255) : null,
            'referrer' => $referrer,
            'source_type' => $source['type'],
            'search_engine' => $source['engine'],
            'utm_source' => $utm['utm_source'],
            'utm_medium' => $utm['utm_medium'],
            'utm_campaign' => $utm['utm_campaign'],
            'utm_term' => $utm['utm_term'],
            'utm_content' => $utm['utm_content'],
            'is_crawler' => $crawler['is_crawler'] ? 1 : 0,
            'crawler_name' => $crawler['name'],
            'os' => $this->detect_os($ua),
            'device_type' => $this->detect_device($ua),
            'browser' => $this->detect_browser($ua),
            'country' => $this->detect_country(),
            'lang' => isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])), 0, 20) : null,
            'user_agent' => !empty($s['anonymize_ua']) ? null : $ua,
        ], ['%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s']);
    }

    private function current_user_meta() {
        if (!is_user_logged_in()) return ['type' => 'guest', 'role' => null];
        if (current_user_can('manage_options')) return ['type' => 'admin', 'role' => 'administrator'];
        $u = wp_get_current_user();
        return ['type' => 'logged_in', 'role' => !empty($u->roles[0]) ? $u->roles[0] : 'member'];
    }

    private function get_utm() {
        $keys = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = isset($_GET[$k]) ? sanitize_text_field(wp_unslash($_GET[$k])) : null;
        }
        return $out;
    }

    private function current_url() {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : parse_url(home_url(), PHP_URL_HOST);
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        return esc_url_raw($scheme . $host . $uri);
    }

    private function get_ip() {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $value = sanitize_text_field(wp_unslash($_SERVER[$key]));
                if ($key === 'HTTP_X_FORWARDED_FOR') $value = trim(explode(',', $value)[0]);
                if (filter_var($value, FILTER_VALIDATE_IP)) return $value;
            }
        }
        return '0.0.0.0';
    }

    private function detect_country() {
        foreach (['HTTP_CF_IPCOUNTRY','HTTP_X_APPENGINE_COUNTRY'] as $h) {
            if (!empty($_SERVER[$h])) {
                $c = strtoupper(sanitize_text_field(wp_unslash($_SERVER[$h])));
                if (preg_match('/^[A-Z]{2}$/', $c)) return $c;
            }
        }
        return null;
    }

    private function detect_source($referrer) {
        if (!$referrer) return ['type' => 'direct', 'engine' => null];
        $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
        $site = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        if ($host && $site && str_contains($host, $site)) return ['type' => 'internal', 'engine' => null];

        $engines = [
            'google.' => 'Google', 'bing.com' => 'Bing', 'yahoo.' => 'Yahoo',
            'duckduckgo.com' => 'DuckDuckGo', 'yandex.' => 'Yandex',
            'baidu.com' => 'Baidu', 'ecosia.org' => 'Ecosia', 'search.brave.com' => 'Brave Search'
        ];
        foreach ($engines as $needle => $name) {
            if (str_contains($host, $needle)) return ['type' => 'search', 'engine' => $name];
        }
        $social = ['instagram.com','facebook.com','x.com','twitter.com','linkedin.com','t.me','telegram.me','youtube.com','pinterest.'];
        foreach ($social as $needle) {
            if (str_contains($host, $needle)) return ['type' => 'social', 'engine' => null];
        }
        return ['type' => 'referral', 'engine' => null];
    }

    private function detect_crawler($ua) {
        $bots = [
            'Googlebot'=>'Googlebot','Bingbot'=>'Bingbot','Slurp'=>'Yahoo Slurp','DuckDuckBot'=>'DuckDuckBot',
            'YandexBot'=>'YandexBot','Baiduspider'=>'Baiduspider','AhrefsBot'=>'AhrefsBot','SemrushBot'=>'SemrushBot',
            'MJ12bot'=>'MJ12bot','DotBot'=>'DotBot','Screaming Frog'=>'Screaming Frog','facebookexternalhit'=>'Facebook',
            'Twitterbot'=>'Twitterbot','LinkedInBot'=>'LinkedInBot','TelegramBot'=>'TelegramBot','bot'=>'Other Bot',
            'crawler'=>'Other Crawler','spider'=>'Other Spider'
        ];
        foreach ($bots as $needle => $name) {
            if ($ua && stripos($ua, $needle) !== false) return ['is_crawler' => true, 'name' => $name];
        }
        return ['is_crawler' => false, 'name' => null];
    }

    private function detect_os($ua) {
        $map = ['Windows NT 10'=>'Windows 10/11','Windows NT'=>'Windows','Mac OS X'=>'macOS','Android'=>'Android','iPhone'=>'iOS','iPad'=>'iPadOS','Linux'=>'Linux'];
        foreach ($map as $needle=>$name) if (stripos($ua,$needle)!==false) return $name;
        return 'Unknown';
    }

    private function detect_browser($ua) {
        $map = ['Edg/'=>'Microsoft Edge','OPR/'=>'Opera','Chrome/'=>'Chrome','Safari/'=>'Safari','Firefox/'=>'Firefox','MSIE'=>'Internet Explorer','Trident/'=>'Internet Explorer'];
        foreach ($map as $needle=>$name) if (stripos($ua,$needle)!==false) return $name;
        return 'Unknown';
    }

    private function detect_device($ua) {
        if (stripos($ua,'ipad')!==false || stripos($ua,'tablet')!==false) return 'tablet';
        if (stripos($ua,'mobile')!==false || stripos($ua,'iphone')!==false || stripos($ua,'android')!==false) return 'mobile';
        return 'desktop';
    }

    public function admin_menu() {
        add_menu_page('MoBo WP Analytics', 'MoBo Analytics', 'manage_options', 'mobo-wp-analytics', [$this, 'render_dashboard'], 'dashicons-chart-area', 26);
        add_submenu_page('mobo-wp-analytics', 'تنظیمات MoBo Analytics', 'تنظیمات', 'manage_options', 'mobo-wp-analytics-settings', [$this, 'render_settings']);
    }

    public function admin_assets($hook) {
        if (!in_array($hook, ['toplevel_page_mobo-wp-analytics', 'mobo-analytics_page_mobo-wp-analytics-settings'], true)) return;
        wp_enqueue_style('mobo-analytics-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);

        if ($hook === 'toplevel_page_mobo-wp-analytics') {
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.1', true);
            wp_enqueue_script('mobo-analytics-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['chart-js'], self::VERSION, true);
            [$range, $where] = $this->get_range();
            wp_localize_script('mobo-analytics-admin', 'MOBO_ANALYTICS', [
                'daily' => $this->daily_chart_data($where),
                'devices' => $this->simple_chart_data('device_type', "$where AND is_crawler=0"),
                'sources' => $this->simple_chart_data('source_type', "$where AND is_crawler=0"),
                'users' => $this->simple_chart_data('user_type', $where),
            ]);
        }
    }

    private function get_range() {
        $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '7days';
        $allowed = ['today','yesterday','7days','30days','90days','all'];
        if (!in_array($range, $allowed, true)) $range = '7days';

        $where = '1=1';
        if ($range === 'today') $where = "event_date = CURDATE()";
        elseif ($range === 'yesterday') $where = "event_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        elseif ($range === '7days') $where = "event_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        elseif ($range === '30days') $where = "event_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        elseif ($range === '90days') $where = "event_time >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
        return [$range, $where];
    }

    private function stats($where) {
        global $wpdb;
        $t = $this->events_table;
        $s = $this->settings();
        $online = max(1, absint($s['online_window_minutes']));

        $sessions = (int) $wpdb->get_var("SELECT COUNT(DISTINCT session_hash) FROM $t WHERE $where AND is_crawler=0");
        $bounced = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT session_hash, COUNT(*) c FROM $t WHERE $where AND is_crawler=0 GROUP BY session_hash HAVING c=1) x");
        $bounce_rate = $sessions ? round(($bounced / $sessions) * 100, 1) : 0;

        return [
            'views' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $where AND is_crawler=0"),
            'unique' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT visitor_hash) FROM $t WHERE $where AND is_crawler=0"),
            'sessions' => $sessions,
            'bounce_rate' => $bounce_rate,
            'online' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM $t WHERE event_time >= DATE_SUB(NOW(), INTERVAL %d MINUTE) AND is_crawler=0", $online)),
            'search' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $where AND source_type='search' AND is_crawler=0"),
            'campaigns' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $where AND source_type='campaign' AND is_crawler=0"),
            'crawlers' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $where AND is_crawler=1"),
            'not_found' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $where AND is_404=1"),
            'internal_searches' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $where AND internal_search_term IS NOT NULL AND internal_search_term!=''"),
        ];
    }

    private function top_rows($column, $where, $limit=10) {
        global $wpdb;
        $allowed = ['page_title','page_path','search_engine','crawler_name','os','device_type','country','browser','source_type','user_type','user_role','utm_source','utm_medium','utm_campaign','internal_search_term','post_type'];
        if (!in_array($column, $allowed, true)) return [];
        $limit = absint($limit);
        $t = $this->events_table;
        return $wpdb->get_results("SELECT COALESCE(NULLIF($column,''),'Unknown') label, COUNT(*) total FROM $t WHERE $where GROUP BY label ORDER BY total DESC LIMIT $limit");
    }

    private function daily_chart_data($where) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT event_date label, COUNT(*) total FROM {$this->events_table} WHERE $where AND is_crawler=0 GROUP BY event_date ORDER BY event_date ASC LIMIT 90");
        return ['labels'=>wp_list_pluck($rows,'label'), 'values'=>array_map('intval', wp_list_pluck($rows,'total'))];
    }

    private function simple_chart_data($column, $where) {
        $rows = $this->top_rows($column, $where, 8);
        return ['labels'=>wp_list_pluck($rows,'label'), 'values'=>array_map('intval', wp_list_pluck($rows,'total'))];
    }

    private function entry_pages($where, $limit=10) {
        global $wpdb;
        $t = $this->events_table;
        return $wpdb->get_results("SELECT e.page_title label, COUNT(*) total FROM $t e JOIN (SELECT session_hash, MIN(id) first_id FROM $t WHERE $where AND is_crawler=0 GROUP BY session_hash) s ON e.id=s.first_id GROUP BY e.page_title ORDER BY total DESC LIMIT " . absint($limit));
    }

    private function exit_pages($where, $limit=10) {
        global $wpdb;
        $t = $this->events_table;
        return $wpdb->get_results("SELECT e.page_title label, COUNT(*) total FROM $t e JOIN (SELECT session_hash, MAX(id) last_id FROM $t WHERE $where AND is_crawler=0 GROUP BY session_hash) s ON e.id=s.last_id GROUP BY e.page_title ORDER BY total DESC LIMIT " . absint($limit));
    }

    public function handle_exports() {
        if (empty($_GET['mobo_export'])) return;
        if (!current_user_can('manage_options')) wp_die('Access denied.');
        check_admin_referer('mobo_analytics_export');

        [$range, $where] = $this->get_range();
        global $wpdb;
        $rows = $wpdb->get_results("SELECT event_time,user_type,user_role,page_title,page_path,source_type,search_engine,utm_source,utm_medium,utm_campaign,is_404,internal_search_term,is_crawler,crawler_name,os,device_type,browser,country FROM {$this->events_table} WHERE $where ORDER BY event_time DESC LIMIT 10000", ARRAY_A);
        $format = sanitize_key($_GET['mobo_export']);

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename=mobo-wp-analytics-' . $range . '.json');
            echo wp_json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=mobo-wp-analytics-' . $range . '.csv');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        if (!empty($rows)) fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }

    public function cleanup_old_data() {
        $days = absint($this->settings()['retention_days']);
        if ($days <= 0) return;
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->events_table} WHERE event_time < DATE_SUB(NOW(), INTERVAL %d DAY)", $days));
    }

    public function register_rest_routes() {
        if (empty($this->settings()['enable_rest_api'])) return;
        register_rest_route('mobo-wp-analytics/v1', '/summary', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('manage_options'); },
            'callback' => function() {
                [$range, $where] = $this->get_range();
                return rest_ensure_response($this->stats($where));
            }
        ]);
    }

    public function render_dashboard() {
        if (!current_user_can('manage_options')) return;
        [$range, $where] = $this->get_range();
        $stats = $this->stats($where);
        $ranges = ['today'=>'امروز','yesterday'=>'دیروز','7days'=>'۷ روز اخیر','30days'=>'۳۰ روز اخیر','90days'=>'۹۰ روز اخیر','all'=>'همه زمان‌ها'];
        $csv = wp_nonce_url(admin_url('admin.php?page=mobo-wp-analytics&range='.$range.'&mobo_export=csv'), 'mobo_analytics_export');
        $json = wp_nonce_url(admin_url('admin.php?page=mobo-wp-analytics&range='.$range.'&mobo_export=json'), 'mobo_analytics_export');
        ?>
        <div class="wrap mobo-wrap" dir="rtl">
            <div class="mobo-header">
                <div>
                    <h1>MoBo WP Analytics</h1>
                    <p>داشبورد پیشرفته آمار وردپرس؛ سریع، مینیمال و مناسب تحلیل محتوایی.</p>
                </div>
                <div class="mobo-actions">
                    <a class="button" href="<?php echo esc_url($json); ?>">JSON</a>
                    <a class="button button-primary" href="<?php echo esc_url($csv); ?>">Excel/CSV</a>
                    <form method="get"><input type="hidden" name="page" value="mobo-wp-analytics"><select name="range" onchange="this.form.submit()"><?php foreach($ranges as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($range,$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></form>
                </div>
            </div>

            <div class="mobo-cards">
                <?php
                $cards = [
                    'بازدیدها'=>$stats['views'], 'بازدیدکننده یکتا'=>$stats['unique'], 'نشست‌ها'=>$stats['sessions'],
                    'نرخ پرش تقریبی'=>$stats['bounce_rate'].'٪', 'آنلاین‌ها'=>$stats['online'], 'ورودی جستجو'=>$stats['search'],
                    'کمپین‌ها'=>$stats['campaigns'], 'خزنده‌ها'=>$stats['crawlers'], 'خطاهای ۴۰۴'=>$stats['not_found'],
                    'جستجوی داخلی'=>$stats['internal_searches']
                ];
                foreach($cards as $label=>$value): ?>
                    <div class="mobo-card"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html(is_numeric($value) ? number_format_i18n($value) : $value); ?></strong></div>
                <?php endforeach; ?>
            </div>

            <div class="mobo-chart-grid">
                <section class="mobo-panel wide"><h2>روند بازدید</h2><canvas id="moboDailyChart"></canvas></section>
                <section class="mobo-panel"><h2>دستگاه‌ها</h2><canvas id="moboDeviceChart"></canvas></section>
                <section class="mobo-panel"><h2>منابع ورودی</h2><canvas id="moboSourceChart"></canvas></section>
                <section class="mobo-panel"><h2>نوع کاربران</h2><canvas id="moboUserChart"></canvas></section>
            </div>

            <div class="mobo-grid">
                <?php
                $this->render_table('پربازدیدترین صفحات', $this->top_rows('page_title', "$where AND is_crawler=0"));
                $this->render_table('صفحات ورود', $this->entry_pages($where));
                $this->render_table('صفحات خروج', $this->exit_pages($where));
                $this->render_table('کمپین‌های UTM', $this->top_rows('utm_campaign', "$where AND utm_campaign IS NOT NULL"));
                $this->render_table('منابع UTM', $this->top_rows('utm_source', "$where AND utm_source IS NOT NULL"));
                $this->render_table('عبارت‌های جستجوی داخلی', $this->top_rows('internal_search_term', "$where AND internal_search_term IS NOT NULL"));
                $this->render_table('موتورهای جستجو', $this->top_rows('search_engine', "$where AND source_type='search'"));
                $this->render_table('کشورها', $this->top_rows('country', "$where AND is_crawler=0"));
                $this->render_table('سیستم‌عامل', $this->top_rows('os', "$where AND is_crawler=0"));
                $this->render_table('خزنده‌های وب', $this->top_rows('crawler_name', "$where AND is_crawler=1"));
                $this->render_table('نوع محتوا', $this->top_rows('post_type', "$where AND post_type IS NOT NULL"));
                $this->render_table('خطاهای ۴۰۴', $this->top_rows('page_path', "$where AND is_404=1"));
                ?>
            </div>
        </div>
        <?php
    }

    private function render_table($title, $rows) {
        ?>
        <section class="mobo-panel">
            <h2><?php echo esc_html($title); ?></h2>
            <table><thead><tr><th>عنوان</th><th>تعداد</th></tr></thead><tbody>
            <?php if ($rows): foreach($rows as $r): ?>
                <tr><td><?php echo esc_html($r->label ?: 'Unknown'); ?></td><td><?php echo esc_html(number_format_i18n((int)$r->total)); ?></td></tr>
            <?php endforeach; else: ?>
                <tr><td colspan="2">داده‌ای وجود ندارد.</td></tr>
            <?php endif; ?>
            </tbody></table>
        </section>
        <?php
    }

    public function render_settings() {
        if (!current_user_can('manage_options')) return;
        $s = $this->settings();

        if (!empty($_POST['mobo_save_settings'])) {
            check_admin_referer('mobo_save_settings');
            $s = [
                'track_admins' => !empty($_POST['track_admins']) ? 1 : 0,
                'track_guests' => !empty($_POST['track_guests']) ? 1 : 0,
                'track_logged_in' => !empty($_POST['track_logged_in']) ? 1 : 0,
                'track_crawlers' => !empty($_POST['track_crawlers']) ? 1 : 0,
                'anonymize_ua' => !empty($_POST['anonymize_ua']) ? 1 : 0,
                'enable_rest_api' => !empty($_POST['enable_rest_api']) ? 1 : 0,
                'online_window_minutes' => max(1, absint($_POST['online_window_minutes'] ?? 5)),
                'retention_days' => max(0, absint($_POST['retention_days'] ?? 365)),
                'public_shortcode_show' => sanitize_text_field(wp_unslash($_POST['public_shortcode_show'] ?? 'views,unique,online')),
            ];
            update_option(self::OPTION_SETTINGS, $s);
            echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
        }
        ?>
        <div class="wrap mobo-wrap" dir="rtl">
            <div class="mobo-header"><div><h1>تنظیمات MoBo WP Analytics</h1><p>کنترل رهگیری، حریم خصوصی، API و نگهداری داده‌ها.</p></div></div>
            <form method="post" class="mobo-settings">
                <?php wp_nonce_field('mobo_save_settings'); ?>
                <label><input type="checkbox" name="track_admins" value="1" <?php checked($s['track_admins'],1); ?>> محاسبه بازدید مدیران</label>
                <label><input type="checkbox" name="track_guests" value="1" <?php checked($s['track_guests'],1); ?>> محاسبه بازدید مهمان‌ها</label>
                <label><input type="checkbox" name="track_logged_in" value="1" <?php checked($s['track_logged_in'],1); ?>> محاسبه بازدید کاربران عضو</label>
                <label><input type="checkbox" name="track_crawlers" value="1" <?php checked($s['track_crawlers'],1); ?>> ذخیره Web Crawlerها</label>
                <label><input type="checkbox" name="anonymize_ua" value="1" <?php checked($s['anonymize_ua'],1); ?>> ذخیره‌نکردن User-Agent کامل برای حریم خصوصی بیشتر</label>
                <label><input type="checkbox" name="enable_rest_api" value="1" <?php checked($s['enable_rest_api'],1); ?>> فعال‌بودن REST API مدیریتی</label>
                <label>بازه آنلاین‌ها، دقیقه<input type="number" min="1" max="120" name="online_window_minutes" value="<?php echo esc_attr($s['online_window_minutes']); ?>"></label>
                <label>حذف خودکار داده‌های قدیمی‌تر از چند روز؛ صفر یعنی غیرفعال<input type="number" min="0" max="3650" name="retention_days" value="<?php echo esc_attr($s['retention_days']); ?>"></label>
                <label>آیتم‌های شورت‌کد عمومی، جداشده با کاما<input type="text" name="public_shortcode_show" value="<?php echo esc_attr($s['public_shortcode_show']); ?>"></label>
                <button class="button button-primary" name="mobo_save_settings" value="1">ذخیره تنظیمات</button>
            </form>
            <div class="mobo-note">شورت‌کد جدید: <code>[mobo_wp_analytics range="7days"]</code></div>
        </div>
        <?php
    }

    public function shortcode_stats($atts) {
        $atts = shortcode_atts(['range'=>'7days'], $atts, 'mobo_wp_analytics');
        $_GET['range'] = sanitize_key($atts['range']);
        [$range, $where] = $this->get_range();
        $stats = $this->stats($where);
        $items = array_map('trim', explode(',', $this->settings()['public_shortcode_show']));
        $labels = ['views'=>'بازدید','unique'=>'بازدیدکننده یکتا','sessions'=>'نشست','online'=>'آنلاین','search'=>'ورودی جستجو','campaigns'=>'کمپین'];
        ob_start(); ?>
        <div class="mobo-public-stats" dir="rtl">
            <?php foreach($items as $key): if(isset($stats[$key], $labels[$key])): ?>
                <div><span><?php echo esc_html($labels[$key]); ?></span><strong><?php echo esc_html(number_format_i18n($stats[$key])); ?></strong></div>
            <?php endif; endforeach; ?>
        </div>
        <?php return ob_get_clean();
    }
}

MoBo_WP_Analytics::instance();
