<?php
/**
 * Plugin Name: Carousel Cutter
 * Description: A free tool that turns a block of text into ready-to-post Instagram carousel slides (1080x1350). Add [carousel_cutter] to any page. If the MarkSui Signup plugin is active, visitors can also opt in to email updates, captured into MarkSui.
 * Version: 1.0.2
 * Author: Ayodeji Agboola
 * Author URI: https://ayodejiagboola.com
 * License: GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CAROUSEL_CUTTER_VERSION', '1.0.2');

// ---------------------------------------------------------------------------
// Updates: Plugin Update Checker (https://github.com/YahnisElsts/plugin-update-checker)
//
// Checks the GitHub repo for new releases (falling back to tags, then the
// main branch) and shows them on the Plugins screen like any other update.
// To ship an update: bump the version here and in the header above, then
// publish a GitHub release tagged with the same version (e.g. v1.0.2).
// ---------------------------------------------------------------------------

require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$carousel_cutter_updater = PucFactory::buildUpdateChecker(
    'https://github.com/ayodejiaaa/CarouselCutter/',
    __FILE__,
    'carousel-cutter'
);
$carousel_cutter_updater->setBranch('main');

// ---------------------------------------------------------------------------
// Shortcode: [carousel_cutter]
//
// The tool runs inside an iframe pointing at the plugin's own static page
// (app/index.html). That keeps the theme's and Elementor's CSS from bleeding
// into the tool, and the tool's CSS from bleeding into the site. The iframe
// grows to fit its content via postMessage, so it reads as part of the page.
// ---------------------------------------------------------------------------

add_shortcode('carousel_cutter', 'carousel_cutter_render_shortcode');
function carousel_cutter_render_shortcode($atts) {
    $atts = shortcode_atts([
        // Where the "Built by" credit links to.
        'credit_url'  => home_url('/services/'),
        'credit_name' => 'Ayodeji Agboola',
        // "auto" shows the email opt-in only when MarkSui Signup is active; "no" hides it.
        'subscribe'   => 'auto',
        // "no" hides the usage counter (people and slides) under the tool.
        'counter'     => 'yes',
    ], $atts, 'carousel_cutter');

    $params = [
        'ver'    => CAROUSEL_CUTTER_VERSION,
        'credit' => esc_url_raw($atts['credit_url']),
        'name'   => sanitize_text_field($atts['credit_name']),
    ];
    if ($atts['counter'] !== 'no') {
        $params['stats'] = esc_url_raw(rest_url('carousel-cutter/v1/stats'));
    }
    if ($atts['subscribe'] !== 'no' && function_exists('marksui_send_contact')) {
        $params['subscribe'] = esc_url_raw(rest_url('carousel-cutter/v1/subscribe'));
    }

    $src = add_query_arg(array_map('rawurlencode', $params), plugins_url('app/index.html', __FILE__));
    $id  = 'carousel-cutter-' . wp_rand(1000, 999999);

    ob_start();
    ?>
    <iframe id="<?php echo esc_attr($id); ?>" class="carousel-cutter-frame"
            src="<?php echo esc_url($src); ?>"
            title="Carousel Cutter: turn text into Instagram carousel slides"
            allow="web-share; clipboard-write"
            loading="lazy"
            style="display:block;width:100%;min-height:900px;border:0;background:transparent;"></iframe>
    <script>
    (function () {
        var frame = document.getElementById(<?php echo wp_json_encode($id); ?>);
        if (!frame) return;
        window.addEventListener('message', function (e) {
            if (e.source !== frame.contentWindow || !e.data || e.data.type !== 'carousel-cutter:height') return;
            var h = parseInt(e.data.height, 10);
            if (h > 0 && h < 20000) { frame.style.minHeight = '0'; frame.style.height = h + 'px'; }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ---------------------------------------------------------------------------
// Email opt-in endpoint: POST /wp-json/carousel-cutter/v1/subscribe
//
// Hands the email to the MarkSui Signup plugin's marksui_send_contact(), so
// the API key stays server-side and this plugin needs no settings of its own.
// No nonce on purpose: the page is likely cached, and a stale nonce would
// break the form for anonymous visitors. A honeypot field and a per-IP hourly
// limit keep bots out instead.
// ---------------------------------------------------------------------------

add_action('rest_api_init', function () {
    register_rest_route('carousel-cutter/v1', '/subscribe', [
        'methods'             => 'POST',
        'callback'            => 'carousel_cutter_handle_subscribe',
        'permission_callback' => '__return_true',
    ]);
});

function carousel_cutter_handle_subscribe(WP_REST_Request $request) {
    if (!function_exists('marksui_send_contact')) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Email signup is not available right now.'], 503);
    }

    // Honeypot: real visitors never see or fill this field.
    if (trim((string) $request->get_param('website')) !== '') {
        return new WP_REST_Response(['ok' => true], 200);
    }

    $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $key = 'carousel_cutter_rl_' . md5($ip);
    $hits = (int) get_transient($key);
    if ($hits >= 5) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Too many attempts. Try again in an hour.'], 429);
    }
    set_transient($key, $hits + 1, HOUR_IN_SECONDS);

    $email = sanitize_email((string) $request->get_param('email'));
    if (!is_email($email)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Enter a valid email address.'], 400);
    }

    marksui_send_contact($email, ['carousel-cutter', 'tool']);

    return new WP_REST_Response(['ok' => true], 201);
}

// ---------------------------------------------------------------------------
// Usage counter: GET/POST /wp-json/carousel-cutter/v1/stats
//
// GET returns the totals shown under the tool. POST records one finished
// export: how many slides were saved, and whether this browser has been
// counted as a person before. Only two numbers are stored, never any text,
// IP address or identifier. "People" are unique browsers (the tool remembers
// that it has counted a browser in that browser's own localStorage).
// Totals live in two options and are bumped with an atomic SQL update.
// ---------------------------------------------------------------------------

add_action('rest_api_init', function () {
    register_rest_route('carousel-cutter/v1', '/stats', [
        [
            'methods'             => 'GET',
            'callback'            => 'carousel_cutter_get_stats',
            'permission_callback' => '__return_true',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'carousel_cutter_track',
            'permission_callback' => '__return_true',
        ],
    ]);
});

function carousel_cutter_read_stats() {
    $stats = [];
    foreach (['users', 'slides'] as $name) {
        $key = 'carousel_cutter_' . $name;
        wp_cache_delete($key, 'options'); // another request may have bumped it
        $stats[$name] = max(0, (int) get_option($key, 0));
    }
    return $stats;
}

function carousel_cutter_bump($name, $by) {
    global $wpdb;
    $key = 'carousel_cutter_' . $name;
    add_option($key, 0, '', 'no'); // does nothing if it already exists
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = option_value + %d WHERE option_name = %s",
        $by,
        $key
    ));
    wp_cache_delete($key, 'options');
}

function carousel_cutter_get_stats() {
    $response = new WP_REST_Response(carousel_cutter_read_stats(), 200);
    $response->header('Cache-Control', 'no-store');
    return $response;
}

function carousel_cutter_track(WP_REST_Request $request) {
    $slides = (int) $request->get_param('slides');
    if ($slides < 1 || $slides > 30) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Invalid slide count.'], 400);
    }

    // Per-IP hourly limit so the numbers can't be run up by a script in a loop.
    $ip   = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $key  = 'carousel_cutter_track_' . md5($ip);
    $hits = (int) get_transient($key);
    if ($hits >= 60) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Too many requests.'], 429);
    }
    set_transient($key, $hits + 1, HOUR_IN_SECONDS);

    carousel_cutter_bump('slides', $slides);
    if (!rest_sanitize_boolean($request->get_param('returning'))) {
        carousel_cutter_bump('users', 1);
    }

    return new WP_REST_Response(['ok' => true] + carousel_cutter_read_stats(), 201);
}
