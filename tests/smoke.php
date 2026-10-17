<?php
// Offline smoke: no WordPress connection and no writes.
define('ABSPATH', __DIR__ . '/../');
$GLOBALS['rar_test_actions'] = array();
function add_action($tag, $callback) { $GLOBALS['rar_test_actions'][] = $tag; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_html_excerpt($text, $count, $more = '') { return strlen($text) > $count ? substr($text, 0, $count) . $more : $text; }
function get_bloginfo($key) { return 'Example Shop'; }
require_once __DIR__ . '/../rar-wp-commerce-studio-ai/rar-wp-commerce-studio-ai.php';

$reflect = new ReflectionClass('RAR_WP_Commerce_Studio_AI');
$defaults = $reflect->getMethod('defaults');
$defaults->setAccessible(true);
$config = $defaults->invoke(null);
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
echo "PASS: default-off providers, limit, rollback registration and local SEO output\n";
