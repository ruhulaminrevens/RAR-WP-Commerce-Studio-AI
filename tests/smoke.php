<?php
// Offline smoke: no WordPress connection and no writes.
define('ABSPATH', __DIR__ . '/../');
$GLOBALS['rar_test_actions'] = array();
function add_action($tag, $callback) { $GLOBALS['rar_test_actions'][] = $tag; }
function add_filter($tag, $callback) { $GLOBALS['rar_test_filters'][$tag] = $callback; }
function plugin_basename($path) { return 'rar-wp-commerce-studio-ai/rar-wp-commerce-studio-ai.php'; }
function current_user_can($cap) { return true; }
function esc_url($value) { return $value; }
function esc_html__($value, $domain) { return $value; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_html_excerpt($text, $count, $more = '') { return strlen($text) > $count ? substr($text, 0, $count) . $more : $text; }
function get_bloginfo($key) { return 'Example Shop'; }
require_once __DIR__ . '/../rar-wp-commerce-studio-ai/rar-wp-commerce-studio-ai.php';
RAR_WP_Commerce_Studio_AI::boot(); // Simulate WordPress plugins_loaded callback.

$reflect = new ReflectionClass('RAR_WP_Commerce_Studio_AI');
$defaults = $reflect->getMethod('defaults');
$defaults->setAccessible(true);
$config = $defaults->invoke(null);
if (RAR_WP_Commerce_Studio_AI::VERSION !== '0.1.1') { throw new Exception('Plugin version mismatch.'); }
if ($config['api_enabled'] !== 0) { throw new Exception('API must be off by default.'); }
foreach (array('gemini', 'openai', 'claude') as $name) {
    if ($config[$name . '_enabled'] !== 0) { throw new Exception('Provider enabled unexpectedly: ' . $name); }
}
if ($config['daily_limit'] > 20 || $config['daily_limit'] < 1) { throw new Exception('Invalid daily limit'); }
if (!in_array('admin_post_rar_studio_restore_seo', $GLOBALS['rar_test_actions'], true)) { throw new Exception('Missing SEO restoration handler.'); }
class RAR_Test_Product {
    public function get_name() { return 'Test Product 100ml'; }
    public function get_short_description() { return '<p>A useful sample product description.</p>'; }
    public function get_description() { return 'Detailed description'; }
}
$local = $reflect->getMethod('local_seo');
$local->setAccessible(true);
$seo = $local->invoke(null, new RAR_Test_Product());
if (count($seo) !== 3 || strpos($seo['_yoast_wpseo_title'], 'Test Product') === false) { throw new Exception('Local SEO output incorrect.'); }
$actions = array('deactivate' => '<a href="#">Deactivate</a>');
$links = RAR_WP_Commerce_Studio_AI::plugin_action_links($actions);
if (array_keys($links) !== array('rar_studio_dashboard', 'rar_studio_settings', 'deactivate')) {
    throw new Exception('Plugin row links must show Dashboard, Settings and retain Deactivate.');
}
if (strpos($links['rar_studio_dashboard'], 'admin.php?page=rar-wp-commerce-studio-ai') === false) { throw new Exception('Dashboard shortcut target invalid.'); }
if (strpos($links['rar_studio_settings'], '#api') === false) {
    throw new Exception('Settings must target API settings section.');
}
echo "PASS: provider defaults, rollback handler, SEO output, and plugin action links\n";
