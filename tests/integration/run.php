<?php
/**
 * Integration tests against a real WordPress + WooCommerce install.
 * LOCAL / STAGING / CI ONLY — it creates and changes test data.
 *
 *   wp eval-file tests/integration/seed.php
 *   wp eval-file tests/integration/run.php
 *
 * AI providers are never called: every HTTP request is answered by a mock
 * through the pre_http_request filter, which also records what would be sent.
 *
 * @package RarStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
if ( 'production' === wp_get_environment_type() && ! getenv( 'RSS_ALLOW_TESTS' ) ) {
	fwrite( STDERR, "Refusing to run integration tests on a production site.\n" );
	exit( 1 );
}

use RarStudio\Ai\Gateway;
use RarStudio\Ai\Registry;
use RarStudio\Ai\Usage;
use RarStudio\Bulk\Jobs;
use RarStudio\Content\Analyzer;
use RarStudio\Content\FactGuard;
use RarStudio\Content\Generator;
use RarStudio\Content\LocalWriter;
use RarStudio\Crypto;
use RarStudio\Drafts;
use RarStudio\Fields;
use RarStudio\Image\Processor;
use RarStudio\Image\Studio;
use RarStudio\Installer;
use RarStudio\Logger;
use RarStudio\Settings;

define( 'RAR_STUDIO_TESTING', true );

$GLOBALS['rss_t'] = array(
	'pass'    => 0,
	'fail'    => 0,
	'section' => '',
	'fails'   => array(),
);

function rss_section( $name ) {
	$GLOBALS['rss_t']['section'] = $name;
	echo "\n== {$name}\n";
}
function rss_ok( $cond, $label, $detail = '' ) {
	if ( $cond ) {
		++$GLOBALS['rss_t']['pass'];
		echo "  ✓ {$label}\n";
	} else {
		++$GLOBALS['rss_t']['fail'];
		$GLOBALS['rss_t']['fails'][] = $GLOBALS['rss_t']['section'] . ': ' . $label . ( $detail ? ' — ' . $detail : '' );
		echo "  ✗ {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}
}
function rss_eq( $a, $b, $label ) {
	rss_ok( $a === $b, $label, 'got ' . var_export( $a, true ) . ' expected ' . var_export( $b, true ) );
}
function rss_err( $res, $code, $label ) {
	rss_ok( is_wp_error( $res ) && $res->get_error_code() === $code, $label, is_wp_error( $res ) ? $res->get_error_code() . ': ' . $res->get_error_message() : 'no error' );
}
function rss_settings( array $patch ) {
	$s = Settings::all();
	$s = array_replace_recursive( $s, $patch );
	Settings::save( $s );
}
function rss_product_snapshot( $id ) {
	$p    = wc_get_product( $id );
	$snap = array(
		'price' => $p->get_regular_price( 'edit' ),
		'sale'  => $p->get_sale_price( 'edit' ),
		'sku'   => $p->get_sku( 'edit' ),
		'stock' => $p->get_stock_quantity( 'edit' ),
		'ss'    => $p->get_stock_status( 'edit' ),
		'type'  => $p->get_type(),
	);
	if ( $p->is_type( 'variable' ) ) {
		foreach ( $p->get_children() as $vid ) {
			$v                    = wc_get_product( $vid );
			$snap[ 'v' . $vid ] = array( $v->get_regular_price( 'edit' ), $v->get_stock_quantity( 'edit' ), $v->get_sku( 'edit' ) );
		}
	}
	return $snap;
}

/* ------------------------------------------------------------------ HTTP mock */
$GLOBALS['rss_http'] = array(
	'queue' => array(),
	'log'   => array(),
);
function rss_mock( array $responses ) {
	$GLOBALS['rss_http']['queue'] = $responses;
	$GLOBALS['rss_http']['log']   = array();
}
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$GLOBALS['rss_http']['log'][] = array(
			'url'     => $url,
			'method'  => $args['method'] ?? 'GET',
			'headers' => $args['headers'] ?? array(),
			'body'    => $args['body'] ?? '',
		);
		$next = array_shift( $GLOBALS['rss_http']['queue'] );
		if ( ! $next ) {
			return new WP_Error( 'rss_no_mock', 'Unexpected HTTP request to ' . $url );
		}
		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $next['headers'] ?? array() ),
			'body'     => is_string( $next['body'] ) ? $next['body'] : wp_json_encode( $next['body'] ),
			'response' => array(
				'code'    => $next['code'] ?? 200,
				'message' => 'mock',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
function rss_ai_text( $provider, $text, $in = 120, $out = 80 ) {
	switch ( $provider ) {
		case 'gemini':
			return array(
				'code' => 200,
				'body' => array(
					'candidates'    => array( array( 'content' => array( 'parts' => array( array( 'text' => 'thinking…', 'thought' => true ), array( 'text' => $text ) ) ) ) ),
					'usageMetadata' => array( 'promptTokenCount' => $in, 'candidatesTokenCount' => $out, 'thoughtsTokenCount' => 10 ),
					'modelVersion'  => 'gemini-test',
				),
			);
		case 'openai':
			return array(
				'code' => 200,
				'body' => array(
					'model'   => 'gpt-test',
					'choices' => array( array( 'message' => array( 'content' => $text ), 'finish_reason' => 'stop' ) ),
					'usage'   => array( 'prompt_tokens' => $in, 'completion_tokens' => $out ),
				),
			);
	}
	return array(
		'code' => 200,
		'body' => array(
			'model'   => 'claude-test',
			'content' => array( array( 'type' => 'text', 'text' => $text ) ),
			'usage'   => array( 'input_tokens' => $in, 'output_tokens' => $out ),
		),
	);
}

/* ------------------------------------------------------------------ setup */
$ids = get_option( 'rss_seed_ids' );
if ( ! is_array( $ids ) ) {
	fwrite( STDERR, "Run tests/integration/seed.php first.\n" );
	exit( 1 );
}
$admin = get_user_by( 'login', getenv( 'RSS_ADMIN' ) ? getenv( 'RSS_ADMIN' ) : 'admin' );
if ( ! $admin ) {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	$admin  = $admins[0];
}
wp_set_current_user( $admin->ID );
$started = microtime( true );
$hpos    = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
echo 'WordPress ' . get_bloginfo( 'version' ) . ' · WooCommerce ' . WC_VERSION . ' · PHP ' . PHP_VERSION . ' · DB ' . ( defined( 'DB_ENGINE' ) ? DB_ENGINE : 'mysql' ) . ' · HPOS ' . ( $hpos ? 'ON' : 'OFF' ) . "\n";

// Fresh state for repeatable runs.
global $wpdb;
foreach ( array( 'rar_studio_drafts', 'rar_studio_jobs', 'rar_studio_job_items', 'rar_studio_usage', 'rar_studio_log' ) as $t ) {
	$wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" ); // phpcs:ignore WordPress.DB
}
delete_option( Settings::OPTION );
delete_option( 'rar_studio_catalog_health' );
Settings::flush();
Registry::reset();

/* ================================================================== */
rss_section( 'Install, defaults, compatibility' );
foreach ( array( 'rar_studio_drafts', 'rar_studio_jobs', 'rar_studio_job_items', 'rar_studio_usage', 'rar_studio_log' ) as $t ) {
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) );
	rss_ok( (bool) $exists, "table {$t} exists" );
}
rss_eq( (int) Settings::get( 'ai.enabled' ), 0, 'AI master switch OFF by default' );
foreach ( Settings::providers() as $p ) {
	rss_eq( (int) Settings::get( "ai.providers.{$p}.enabled" ), 0, "{$p} disabled by default" );
}
rss_eq( RarStudio\Ai\Gateway::unavailable_reason() !== '', true, 'AI unavailable reason reported while OFF' );
$compat = wc_get_container()->get( Automattic\WooCommerce\Internal\Features\FeaturesController::class )->get_compatible_plugins_for_feature( 'custom_order_tables' );
rss_ok( in_array( 'rar-wp-commerce-studio-ai/rar-wp-commerce-studio-ai.php', $compat['compatible'] ?? array(), true ), 'declared HPOS-compatible to WooCommerce' );
rss_ok( has_action( 'rest_api_init', array( RarStudio\Rest::class, 'register' ) ) !== false, 'REST routes hooked' );
$links = RarStudio\Plugin::action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );
rss_eq( array_keys( $links ), array( 'rar_studio_dashboard', 'rar_studio_settings', 'deactivate' ), 'plugin row: Dashboard | Settings | Deactivate' );

/* ================================================================== */
rss_section( 'Upgrade from v0.1.x' );
$legacy_pid = $ids['webp'];
update_option( 'rar_studio_settings', array( 'api_enabled' => 1, 'provider' => 'openai', 'daily_limit' => 7, 'openai_enabled' => 1, 'openai_model' => 'gpt-4.1-mini', 'gemini_enabled' => 0, 'claude_enabled' => 0 ) );
delete_option( 'rar_studio_migrated_from' );
update_post_meta( $legacy_pid, '_yoast_wpseo_title', 'New SEO title from v0.1' );
update_post_meta(
	$legacy_pid,
	'_rar_studio_seo_snapshot',
	array(
		'_yoast_wpseo_title'    => array( 'exists' => true, 'value' => 'Old SEO title' ),
		'_yoast_wpseo_metadesc' => array( 'exists' => false, 'value' => '' ),
		'_yoast_wpseo_focuskw'  => array( 'exists' => false, 'value' => '' ),
	)
);
Installer::migrate_legacy();
Settings::flush();
rss_eq( (int) Settings::get( 'ai.enabled' ), 0, 'master switch forced OFF after upgrade' );
rss_eq( Settings::get( 'ai.providers.openai.model' ), 'gpt-4.1-mini', 'legacy model kept' );
rss_eq( (int) Settings::get( 'ai.daily_limit' ), 7, 'legacy daily limit kept' );
rss_eq( Settings::get( 'ai.primary' ), 'openai', 'legacy provider kept as primary' );
rss_ok( ! metadata_exists( 'post', $legacy_pid, '_rar_studio_seo_snapshot' ), 'legacy snapshot meta converted' );
$mig = Drafts::query( array( 'object_type' => 'product', 'object_id' => $legacy_pid, 'status' => 'applied' ) )['items'];
$mt  = array_values( array_filter( $mig, static function ( $d ) { return 'seo_title' === $d['field']; } ) );
rss_ok( ! empty( $mt ), 'legacy SEO restore point available as applied draft' );
if ( $mt ) {
	$r = Drafts::rollback( $mt[0]['id'] );
	rss_ok( ! is_wp_error( $r ), 'legacy restore point rolls back' );
	rss_eq( get_post_meta( $legacy_pid, '_yoast_wpseo_title', true ), 'Old SEO title', 'old SEO title restored' );
}
delete_option( 'rar_studio_settings' );
delete_option( Settings::OPTION );
Settings::flush();

/* ================================================================== */
rss_section( 'Field writes never touch price, stock, SKU or variations' );
$before_oud = rss_product_snapshot( $ids['oud'] );
$before_var = rss_product_snapshot( $ids['variable'] );
foreach ( array( 'title' => 'Arabian Oud Perfume 100ml', 'description' => '<p>Test body</p>', 'short_description' => '<p>Short</p>', 'tags' => 'Oud, Perfume' ) as $f => $v ) {
	$r = Fields::set( 'product', $ids['oud'], $f, $v );
	rss_ok( true === $r, "set {$f}", is_wp_error( $r ) ? $r->get_error_message() : '' );
}
rss_ok( true === Fields::set( 'product', $ids['variable'], 'description', '<p>Variable desc</p>' ), 'set variable product description' );
rss_eq( rss_product_snapshot( $ids['oud'] ), $before_oud, 'simple product price/stock/SKU unchanged' );
rss_eq( rss_product_snapshot( $ids['variable'] ), $before_var, 'variation prices/stock/SKUs unchanged' );
rss_err( Fields::set( 'product', $ids['oud'], 'categories', 'Category That Does Not Exist' ), 'rar_term_missing', 'categories: unknown term refused (never created)' );
rss_err( Fields::set( 'product', $ids['oud'], 'title', '   ' ), 'rar_empty', 'empty product title refused' );
rss_err( Fields::set( 'product', $ids['oud'], 'price', '1' ), 'rar_field', 'price is not a writable field' );
// Restore seed text.
Fields::set( 'product', $ids['oud'], 'title', 'ARABIAN OUD PERFUME 100ML' );
Fields::set( 'product', $ids['oud'], 'description', "Arabian Oud is a warm woody perfume with a smooth oud and amber character. It suits evening wear and special occasions.\n\n- Rich oud and amber notes\n- Elegant glass bottle with gold cap\n- Spray nozzle for even application\n\nVolume: 100 ml\nConcentration: Eau de Parfum" );
Fields::set( 'product', $ids['oud'], 'short_description', '' );
Fields::set( 'product', $ids['oud'], 'tags', '' );

/* ================================================================== */
rss_section( 'Fact guard' );
$src    = Fields::source_text( Fields::facts( 'product', $ids['oud'] ) );
$issues = FactGuard::check( 'This 100% organic, FDA approved perfume in a 50ml bottle lasts 24 hours. Only ৳500!', $src );
$texts  = wp_list_pluck( $issues, 'text' );
rss_ok( FactGuard::has_blockers( $issues ), 'invented claims produce blockers' );
rss_ok( in_array( '50ml', $texts, true ), 'unknown size 50ml flagged' );
rss_ok( (bool) preg_grep( '/organic/i', $texts ), 'organic flagged' );
rss_ok( (bool) preg_grep( '/fda/i', $texts ), 'FDA flagged' );
rss_ok( (bool) preg_grep( '/৳/u', $texts ), 'price (৳) flagged' );
rss_ok( ! FactGuard::has_blockers( FactGuard::check( 'Arabian Oud in a 100 ml bottle with rich oud and amber notes.', $src ) ), 'facts from product data pass (100 ml == Volume: 100 ml)' );
rss_ok( ! FactGuard::has_blockers( FactGuard::check( 'আতর মুসক আম্বার ১২ মিলি অ্যালকোহল ছাড়া।', Fields::source_text( Fields::facts( 'product', $ids['attar'] ) ) ) ), 'Bangla facts with Bangla digits pass' );
rss_ok( FactGuard::has_blockers( FactGuard::check( 'এটি হালাল সার্টিফাইড আতর।', Fields::source_text( Fields::facts( 'product', $ids['attar'] ) ) ) ), 'Bangla certification claim flagged' );
rss_settings( array( 'brand' => array( 'banned' => "miracle\ncheapest" ) ) );
rss_ok( FactGuard::has_blockers( FactGuard::check( 'A miracle scent', $src ) ), 'banned word from settings blocks' );
rss_settings( array( 'brand' => array( 'banned' => '' ) ) );

/* ================================================================== */
rss_section( 'Free local engine → review → apply → rollback' );
$res = Generator::generate( 'product', $ids['oud'], array( 'title', 'short_description', 'description', 'tags', 'seo_title', 'seo_description', 'seo_focus' ), 'local', array( 'language' => 'en' ) );
$by  = array();
foreach ( $res['drafts'] as $d ) {
	$by[ $d['field'] ] = $d;
}
rss_ok( isset( $by['title'] ) && 'Al-Arab Arabian Oud Perfume 100ml' === $by['title']['proposed'], 'title cleaned + brand added', $by['title']['proposed'] ?? '' );
rss_ok( isset( $by['description'] ) && false !== strpos( $by['description']['proposed'], '<table' ) && false !== strpos( $by['description']['proposed'], 'Eau de Parfum' ), 'description gets spec table from own data' );
rss_ok( isset( $by['seo_title'] ) && Util_len( $by['seo_title']['proposed'] ) <= 60, 'SEO title within 60 chars' );
rss_ok( isset( $by['seo_description'] ) && Util_len( $by['seo_description']['proposed'] ) <= 155, 'meta description within 155 chars' );
foreach ( $by as $f => $d ) {
	rss_ok( ! $d['blockers'], "local {$f} has no fact blockers" );
}
rss_eq( wc_get_product( $ids['oud'] )->get_name(), 'ARABIAN OUD PERFUME 100ML', 'generation alone changes nothing' );
$a = Drafts::apply( $by['title']['id'] );
rss_ok( ! is_wp_error( $a ) && 'applied' === $a['status'], 'apply title' );
rss_eq( wc_get_product( $ids['oud'] )->get_name(), 'Al-Arab Arabian Oud Perfume 100ml', 'title written' );
rss_err( Drafts::apply( $by['title']['id'] ), 'rar_draft_state', 'second apply refused' );
$a = Drafts::apply( $by['seo_description']['id'] );
rss_eq( get_post_meta( $ids['oud'], '_yoast_wpseo_metadesc', true ), $by['seo_description']['proposed'], 'meta description written to Yoast field' );
$r = Drafts::rollback( $by['title']['id'] );
rss_ok( ! is_wp_error( $r ) && 'rolled_back' === $r['status'], 'rollback title' );
rss_eq( wc_get_product( $ids['oud'] )->get_name(), 'ARABIAN OUD PERFUME 100ML', 'original title restored' );
// Stale preview.
Fields::set( 'product', $ids['oud'], 'short_description', '<p>Edited by a human after preview.</p>' );
rss_err( Drafts::apply( $by['short_description']['id'] ), 'rar_stale', 'stale preview refused' );
$f = Drafts::apply( $by['short_description']['id'], array( 'force' => true ) );
rss_ok( ! is_wp_error( $f ), 'force apply works when the user confirms' );
// Rollback conflict.
Fields::set( 'product', $ids['oud'], 'short_description', '<p>Edited again after apply.</p>' );
rss_err( Drafts::rollback( $by['short_description']['id'] ), 'rar_conflict', 'rollback refused when field edited after apply' );
$f = Drafts::rollback( $by['short_description']['id'], array( 'force' => true ) );
rss_eq( wc_get_product( $ids['oud'] )->get_short_description(), '<p>Edited by a human after preview.</p>', 'forced rollback restores value present before apply' );
Fields::set( 'product', $ids['oud'], 'short_description', '' );
// Edit + re-guard.
$res2 = Generator::generate( 'product', $ids['oud'], array( 'seo_focus' ), 'local', array() );
$d    = $res2['drafts'][0];
$e    = Drafts::update_proposed( $d['id'], 'certified organic oud 50ml' );
rss_ok( $e['blockers'], 'editing a draft re-runs the fact guard' );
rss_err( Drafts::apply( $d['id'] ), 'rar_guard_ack', 'blocked draft needs acknowledgement' );
$ok = Drafts::apply( $d['id'], array( 'ack' => true ) );
rss_ok( ! is_wp_error( $ok ), 'acknowledged draft applies' );
Drafts::rollback( $d['id'] );
// Supersede.
$g1 = Generator::generate( 'product', $ids['spray'], array( 'tags' ), 'local', array() )['drafts'][0];
Fields::set( 'product', $ids['spray'], 'tags', 'X' );
$g2 = Generator::generate( 'product', $ids['spray'], array( 'tags' ), 'local', array() )['drafts'][0];
rss_eq( Drafts::get( $g1['id'] )['status'], 'superseded', 'older pending draft superseded by newer one' );
Fields::set( 'product', $ids['spray'], 'tags', '' );
// Categories suggestion: spray in Uncategorized → Body Spray.
$cat = Generator::generate( 'product', $ids['spray'], array( 'categories' ), 'local', array() );
rss_ok( isset( $cat['drafts'][0] ) && 'Body Spray' === $cat['drafts'][0]['proposed'], 'existing category suggested and Uncategorized dropped', $cat['drafts'][0]['proposed'] ?? wp_json_encode( $cat['skipped'] ) );

/* ================================================================== */
rss_section( 'SEO adapters' );
rss_settings( array( 'seo' => array( 'adapter' => 'rankmath' ) ) );
rss_ok( true === Fields::set( 'post', $ids['post_en'], 'seo_title', 'RM title' ), 'Rank Math adapter writes' );
rss_eq( get_post_meta( $ids['post_en'], 'rank_math_title', true ), 'RM title', 'rank_math_title meta set' );
Fields::set( 'post', $ids['post_en'], 'seo_title', '' );
rss_ok( ! metadata_exists( 'post', $ids['post_en'], 'rank_math_title' ), 'empty value removes meta (restores "not set")' );
rss_settings( array( 'seo' => array( 'adapter' => 'none' ) ) );
rss_ok( '' !== Fields::locked_reason( 'post', $ids['post_en'], 'seo_title' ), 'SEO fields locked when no SEO plugin selected' );
rss_settings( array( 'seo' => array( 'adapter' => 'auto' ) ) );

/* ================================================================== */
rss_section( 'Content Studio: posts, Elementor, links, article' );
rss_ok( '' !== Fields::locked_reason( 'page', $ids['elementor'], 'content' ), 'Elementor page content is read-only' );
$rep = Analyzer::seo_report( 'page', $ids['elementor'] );
rss_ok( in_array( 'Signature Scents', wp_list_pluck( $rep['headings'], 'text' ), true ), 'Elementor headings analysed from _elementor_data' );
$rep = Analyzer::seo_report( 'post', $ids['post_en'] );
rss_ok( count( $rep['checks'] ) >= 8 && $rep['score'] > 0, 'post SEO report computed' );
$skipped_h = array_filter( $rep['checks'], static function ( $c ) { return 'heading_order' === $c['id']; } );
rss_eq( array_values( $skipped_h )[0]['status'], 'warn', 'skipped heading level (H2→H4) detected' );
rss_ok( $rep['links']['images_missing_alt'] >= 1, 'content image without alt detected' );
$html = '<p>Our body spray fresh blue is light.</p><h2>body spray fresh blue</h2><p><a href="#">body spray fresh blue</a></p>';
$new  = Analyzer::insert_link( $html, 'body spray fresh blue', 'https://example.test/p' );
rss_ok( null !== $new && 1 === substr_count( $new, 'https://example.test/p' ) && false !== strpos( $new, '<p>Our <a href="https://example.test/p">body spray fresh blue</a>' ), 'link inserted once, outside headings and existing links' );
$lk = Analyzer::internal_links( 'post', $ids['post_en'] );
rss_ok( is_array( $lk ), 'internal link suggestions return' );
$art = Generator::article( 'brief', 'manual', wp_json_encode( array( 'title' => 'Storing attar in summer', 'content' => '<h2>Keep it cool</h2><p>Store it in a drawer.</p><script>alert(1)</script>', 'excerpt' => 'Tips', 'seo_title' => 'Storing attar', 'seo_description' => 'How to store attar.', 'seo_focus' => 'store attar' ) ), 'post' );
rss_ok( ! is_wp_error( $art ) && 'draft' === get_post_status( $art['post_id'] ), 'article saved as WordPress draft (not published)' );
rss_ok( ! is_wp_error( $art ) && false === strpos( get_post_field( 'post_content', $art['post_id'] ), '<script' ), 'article HTML sanitised' );
if ( ! is_wp_error( $art ) ) {
	wp_delete_post( $art['post_id'], true );
}
$ex = Generator::generate( 'post', $ids['post_bn'], array( 'excerpt', 'seo_description' ), 'local', array() );
rss_ok( count( $ex['drafts'] ) >= 1, 'Bangla post excerpt/meta generated locally' );

/* ================================================================== */
rss_section( 'AI gateway: OFF by default, keys, limits, providers (mocked HTTP)' );
rss_mock( array() );
$r = Generator::generate( 'product', $ids['oud'], array( 'seo_title' ), 'ai', array() );
rss_err( $r, 'rar_ai_off', 'AI request refused while master switch is OFF' );
rss_eq( count( $GLOBALS['rss_http']['log'] ), 0, 'no outbound HTTP while OFF' );
rss_err( Gateway::set_master( true, false ), 'rar_ai_ack', 'enabling requires cost acknowledgement' );
rss_ok( true === Gateway::set_master( true, true ), 'enable with acknowledgement' );
Settings::flush();
rss_err( Generator::generate( 'product', $ids['oud'], array( 'seo_title' ), 'ai', array() ), 'rar_ai_off', 'still refused with no provider/key' );
$fake = array(
	'gemini' => 'AIzaTESTKEY_gemini_1234567890',
	'openai' => 'sk-TESTKEY-openai-1234567890',
	'claude' => 'sk-ant-TESTKEY-claude-1234567890',
);
foreach ( $fake as $p => $k ) {
	rss_ok( true === Gateway::save_key( $p, $k ), "save {$p} key" );
}
Settings::flush();
$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Settings::OPTION ) );
rss_ok( false === strpos( $raw, 'TESTKEY' ), 'keys are not stored in plain text' );
rss_eq( Registry::get( 'openai' )->key(), $fake['openai'], 'stored key decrypts' );
rss_ok( false === strpos( wp_json_encode( Gateway::status() ), 'TESTKEY' ), 'status payload never contains keys' );
rss_ok( false === strpos( wp_json_encode( Settings::public_view() ), 'rss1:' ), 'settings payload strips encrypted keys' );
rss_settings(
	array(
		'ai' => array(
			'providers'   => array(
				'gemini' => array( 'enabled' => 1 ),
				'openai' => array( 'enabled' => 1 ),
				'claude' => array( 'enabled' => 1 ),
			),
			'daily_limit' => 50,
			'per_minute'  => 50,
		),
	)
);
$fields_json = wp_json_encode( array( 'seo_title' => 'Arabian Oud Perfume 100ml | Studio Test Shop', 'seo_description' => 'Arabian Oud is a warm woody perfume with oud and amber notes for evening wear.', 'seo_focus' => 'arabian oud perfume' ) );
foreach ( array( 'gemini', 'openai', 'claude' ) as $p ) {
	rss_settings( array( 'ai' => array( 'primary' => $p ) ) );
	rss_mock( array( rss_ai_text( $p, "```json\n{$fields_json}\n```" ) ) );
	$r = Generator::generate( 'product', $ids['oud'], array( 'seo_title', 'seo_description', 'seo_focus' ), 'ai', array( 'language' => 'en' ) );
	rss_ok( ! is_wp_error( $r ) && 3 === count( $r['drafts'] ), "{$p}: 3 drafts from JSON in code fences", is_wp_error( $r ) ? $r->get_error_message() : wp_json_encode( $r['skipped'] ) );
	$req = $GLOBALS['rss_http']['log'][0] ?? array();
	rss_ok( isset( $req['url'] ) && 0 === strpos( $req['url'], 'https://' ), "{$p}: HTTPS endpoint" );
	$body = (string) ( $req['body'] ?? '' );
	rss_ok( false === strpos( $body, '2450' ) && false === strpos( $body, 'stock' ), "{$p}: no price/stock sent" );
	rss_ok( false === strpos( $body, 'TESTKEY' ), "{$p}: key not in request body" );
	if ( 'gemini' === $p ) {
		rss_eq( $req['headers']['x-goog-api-key'] ?? '', $fake['gemini'], 'gemini: key in x-goog-api-key header (not URL)' );
		rss_ok( false === strpos( $req['url'], 'key=' ), 'gemini: key not in URL' );
	}
	if ( 'claude' === $p ) {
		rss_eq( $req['headers']['anthropic-version'] ?? '', '2023-06-01', 'claude: version header' );
	}
	if ( ! is_wp_error( $r ) ) {
		rss_ok( $r['usage']['input_tokens'] > 0 && $r['usage']['cost'] > 0, "{$p}: tokens and estimated cost recorded" );
	}
	foreach ( (array) ( $r['drafts'] ?? array() ) as $d ) {
		Drafts::reject( $d['id'] );
	}
}
rss_settings( array( 'ai' => array( 'primary' => 'gemini' ) ) );
// Retry on 429 then success.
rss_mock( array( array( 'code' => 429, 'body' => array( 'error' => array( 'message' => 'quota' ) ) ), rss_ai_text( 'gemini', $fields_json ) ) );
$r = Generator::generate( 'product', $ids['oud'], array( 'seo_title' ), 'ai', array() );
rss_ok( ! is_wp_error( $r ) && 2 === count( $GLOBALS['rss_http']['log'] ), '429 retried once then succeeded' );
// 401: no retry, clear error.
rss_mock( array( array( 'code' => 401, 'body' => array( 'error' => array( 'message' => 'API key not valid AIzaLEAKED1234567890' ) ) ) ) );
$r = Generator::generate( 'product', $ids['oud'], array( 'seo_title' ), 'ai', array() );
rss_err( $r, 'rar_ai_http_401', '401 reported' );
rss_ok( 1 === count( $GLOBALS['rss_http']['log'] ), '401 not retried' );
rss_ok( is_wp_error( $r ) && false === strpos( $r->get_error_message(), 'AIzaLEAKED' ), 'key-like strings redacted from provider errors' );
// Fallback.
rss_settings( array( 'ai' => array( 'fallback' => 1 ) ) );
rss_mock( array( array( 'code' => 503, 'body' => '' ), array( 'code' => 503, 'body' => '' ), rss_ai_text( 'openai', $fields_json ) ) );
$r = Generator::generate( 'product', $ids['oud'], array( 'seo_title' ), 'ai', array() );
rss_ok( ! is_wp_error( $r ) && 'openai' === $r['usage']['provider'], 'fallback to next provider after 503', is_wp_error( $r ) ? $r->get_error_message() : '' );
rss_settings( array( 'ai' => array( 'fallback' => 0 ) ) );
// Bad JSON.
rss_mock( array( rss_ai_text( 'gemini', 'Sorry, here are some ideas without JSON' ) ) );
rss_err( Generator::generate( 'product', $ids['oud'], array( 'seo_title' ), 'ai', array() ), 'rar_ai_json', 'non-JSON answer rejected' );
// AI invents a claim → blocker.
rss_mock( array( rss_ai_text( 'gemini', wp_json_encode( array( 'short_description' => '<p>Clinically tested, 100% natural oud. Lasts 48 hours.</p>' ) ) ) ) );
$r = Generator::generate( 'product', $ids['oud'], array( 'short_description' ), 'ai', array() );
rss_ok( ! is_wp_error( $r ) && $r['drafts'][0]['blockers'], 'AI-invented claims flagged as blockers' );
rss_err( Drafts::apply( $r['drafts'][0]['id'] ), 'rar_guard_ack', 'flagged AI draft cannot be applied without confirmation' );
// Invented category refused.
rss_mock( array( rss_ai_text( 'gemini', wp_json_encode( array( 'categories' => 'Luxury Oud Collection' ) ) ) ) );
$r = Generator::generate( 'product', $ids['oud'], array( 'categories' ), 'ai', array() );
rss_ok( ! is_wp_error( $r ) && ! $r['drafts'] && isset( $r['skipped']['categories'] ), 'AI-invented category is not created or drafted' );
// Limits.
$count_today = Usage::summary()['today_requests'];
rss_settings( array( 'ai' => array( 'daily_limit' => $count_today ) ) );
rss_mock( array( rss_ai_text( 'gemini', $fields_json ) ) );
rss_err( Generator::generate( 'product', $ids['oud'], array( 'seo_title' ), 'ai', array() ), 'rar_ai_limit', 'daily limit blocks before any HTTP' );
rss_eq( count( $GLOBALS['rss_http']['log'] ), 0, 'blocked request sent nothing' );
rss_settings( array( 'ai' => array( 'daily_limit' => 500, 'monthly_budget' => 0 ) ) );
rss_err( Generator::generate( 'product', $ids['oud'], array( 'seo_title' ), 'ai', array() ), 'rar_ai_limit', 'budget 0 blocks paid requests' );
rss_settings( array( 'ai' => array( 'providers' => array( 'gemini' => array( 'free_tier' => 1 ) ) ) ) );
rss_mock( array( rss_ai_text( 'gemini', $fields_json ) ) );
$r = Generator::generate( 'product', $ids['oud'], array( 'seo_title' ), 'ai', array() );
rss_ok( ! is_wp_error( $r ) && 0.0 === (float) $r['usage']['cost'], 'free-tier provider allowed with $0 budget, cost recorded as $0' );
rss_settings( array( 'ai' => array( 'monthly_budget' => 5, 'per_minute' => 1, 'providers' => array( 'gemini' => array( 'free_tier' => 0 ) ) ) ) );
rss_mock( array( rss_ai_text( 'gemini', $fields_json ) ) );
rss_err( Generator::generate( 'product', $ids['oud'], array( 'seo_title' ), 'ai', array() ), 'rar_ai_limit', 'per-minute limit blocks bursts' );
rss_settings( array( 'ai' => array( 'per_minute' => 60 ) ) );
// Roles.
$sm = get_user_by( 'login', 'rss_shop_manager' );
if ( ! $sm ) {
	$sm_id = wp_insert_user( array( 'user_login' => 'rss_shop_manager', 'user_pass' => wp_generate_password(), 'role' => 'shop_manager', 'user_email' => 'sm@example.test' ) );
	$sm    = get_user_by( 'id', $sm_id );
}
wp_set_current_user( $sm->ID );
rss_ok( RarStudio\Util::can_use_studio(), 'shop manager can open the Studio' );
rss_ok( ! RarStudio\Util::can_use_ai(), 'shop manager cannot run AI by default' );
rss_err( Generator::generate( 'product', $ids['oud'], array( 'seo_title' ), 'ai', array() ), 'rar_ai_off', 'shop manager AI request refused' );
rss_settings( array( 'general' => array( 'ai_roles' => 'shop_manager' ) ) );
rss_ok( RarStudio\Util::can_use_ai(), 'shop manager allowed when setting says so' );
rss_settings( array( 'general' => array( 'ai_roles' => 'administrator' ) ) );
wp_set_current_user( $admin->ID );
// Vision alt text.
rss_mock( array( rss_ai_text( 'gemini', wp_json_encode( array( 'alt' => 'Green glass perfume bottle with gold cap and white label reading OUD 100ML' ) ) ) ) );
$r = Generator::ai_alt( $ids['images']['oud'], array( 'product_id' => $ids['oud'] ) );
$body = json_decode( $GLOBALS['rss_http']['log'][0]['body'] ?? '{}', true );
rss_ok( ! is_wp_error( $r ) && 1 === count( $r['drafts'] ), 'AI vision alt draft', is_wp_error( $r ) ? $r->get_error_message() : '' );
rss_ok( ! empty( $body['contents'][0]['parts'][0]['inline_data']['data'] ), 'vision request carries a small inline JPEG' );
rss_ok( strlen( $body['contents'][0]['parts'][0]['inline_data']['data'] ?? '' ) < 200000, 'vision image is downscaled (<200 KB base64)' );
// Usage + logs.
$sum = Usage::summary();
rss_ok( $sum['today_requests'] > 0 && $sum['month_cost'] > 0, 'usage summary has requests and cost' );
$logs = wp_json_encode( Logger::query( array( 'per_page' => 200 ) ) );
rss_ok( false === strpos( $logs, 'TESTKEY' ) && false === strpos( $logs, 'AIzaLEAKED' ), 'activity log contains no keys' );
rss_ok( false !== strpos( $logs, 'ai_enabled' ), 'master switch change logged as security event' );
Gateway::set_master( false, false );
Settings::flush();

/* ================================================================== */
rss_section( 'Image Studio' );
$before_img = (int) get_post_thumbnail_id( $ids['oud'] );
$orig_file  = get_attached_file( $before_img );
$orig_hash  = md5_file( $orig_file );
$r          = Studio::create( $before_img, array( 'size' => 800, 'format' => 'jpeg', 'remove_bg' => 1, 'trim' => 1 ), array( 'product_id' => $ids['oud'], 'role' => 'featured' ) );
rss_ok( ! is_wp_error( $r ) && $r['draft'], 'derivative + featured draft', is_wp_error( $r ) ? $r->get_error_message() : '' );
rss_eq( md5_file( $orig_file ), $orig_hash, 'original file bytes unchanged' );
rss_eq( (int) get_post_thumbnail_id( $ids['oud'] ), $before_img, 'product image unchanged until applied' );
$info = getimagesize( get_attached_file( $r['derivative']['id'] ) );
rss_ok( 800 === $info[0] && 800 === $info[1], 'output is 800×800' );
$px = imagecreatefromjpeg( get_attached_file( $r['derivative']['id'] ) );
rss_eq( imagecolorat( $px, 5, 5 ) & 0xFFFFFF, 0xFFFFFF, 'corner pixel is pure white' );
$a = Drafts::apply( $r['draft']['id'] );
rss_eq( (int) get_post_thumbnail_id( $ids['oud'] ), $r['derivative']['id'], 'apply sets new featured image' );
Drafts::rollback( $r['draft']['id'] );
rss_eq( (int) get_post_thumbnail_id( $ids['oud'] ), $before_img, 'rollback restores original featured image' );
// Grey background → white, WebP.
$g = Studio::create( $ids['images']['oud_g1'], array( 'size' => 600, 'format' => 'webp', 'remove_bg' => 1, 'canvas' => 'white' ), array( 'product_id' => $ids['oud'], 'role' => 'gallery' ) );
rss_ok( ! is_wp_error( $g ) && 'image/webp' === $g['derivative']['mime'] && $g['removed_bg'], 'grey backdrop removed, WebP output' );
// Transparent PNG.
$t = Studio::create( $ids['images']['oud_g2'], array( 'size' => 600, 'format' => 'png', 'canvas' => 'transparent' ), array( 'product_id' => $ids['oud'], 'role' => 'gallery' ) );
$im = imagecreatefrompng( get_attached_file( $t['derivative']['id'] ) );
rss_eq( ( imagecolorat( $im, 2, 2 ) >> 24 ) & 0x7F, 127, 'transparent canvas keeps alpha' );
$gal = Drafts::query( array( 'object_type' => 'product', 'object_id' => $ids['oud'], 'status' => 'pending' ) )['items'];
$gal = array_values( array_filter( $gal, static function ( $d ) { return 'gallery' === $d['field']; } ) );
rss_ok( 1 === count( $gal ) && 2 === count( array_intersect( explode( ',', $gal[0]['proposed'] ), array( (string) $g['derivative']['id'], (string) $t['derivative']['id'] ) ) ), 'two gallery slots combined in one pending draft' );
rss_ok( (bool) get_post( $g['derivative']['id'] ), 'first gallery derivative kept when draft was rebuilt' );
$gal_before = get_post_meta( $ids['oud'], '_product_image_gallery', true );
Drafts::apply( $gal[0]['id'] );
rss_ok( get_post_meta( $ids['oud'], '_product_image_gallery', true ) !== $gal_before, 'gallery updated on apply' );
Drafts::rollback( $gal[0]['id'] );
rss_eq( get_post_meta( $ids['oud'], '_product_image_gallery', true ), $gal_before, 'gallery restored on rollback' );
// Busy background refused.
$b = Studio::create( $ids['images']['busy'], array( 'size' => 600, 'remove_bg' => 1 ), array( 'product_id' => $ids['busy'], 'role' => 'featured' ) );
rss_ok( ! is_wp_error( $b ) && ! $b['removed_bg'] && preg_grep( '/not a plain colour/', $b['notes'] ), 'busy background: removal refused to protect the product' );
// Reject deletes unused derivative.
$file = get_attached_file( $b['derivative']['id'] );
Drafts::reject( $b['draft']['id'] );
rss_ok( ! get_post( $b['derivative']['id'] ) && ! file_exists( $file ), 'reject deletes the unused derivative file' );
// Tiny image no upscale.
$s = Studio::create( $ids['images']['tiny'], array( 'size' => 800 ), array( 'product_id' => $ids['variable'], 'role' => 'featured' ) );
rss_ok( ! is_wp_error( $s ) && preg_grep( '/upscaling is off/', $s['notes'] ), 'small source not upscaled by default' );
// Memory guard.
// With an unlimited PHP CLI (memory_limit=-1) WordPress sets WP_MAX_MEMORY_LIMIT to -1,
// so there is no cap to guard; run this check with a finite limit (CI uses 256M).
if ( -1 === wp_convert_hr_to_bytes( WP_MAX_MEMORY_LIMIT ) ) {
	echo "  - memory guard check skipped: PHP has no memory limit in this run\n";
} else {
	$old = ini_get( 'memory_limit' );
	ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 48 * 1048576 ) );
	rss_ok( ! Processor::memory_ok( 40000000 ), 'memory guard refuses work that would not fit' );
	rss_ok( Processor::memory_ok( 600 * 600 ), 'memory guard allows normal work' );
	ini_set( 'memory_limit', $old );
}
// Delete derivative in use refused.
rss_err( Studio::delete_derivative( $before_img ), 'rar_image', 'cannot delete non-Studio images' );
// Alt text local.
$al = LocalWriter::alt( $ids['images']['oud_g1'], array( 'product_id' => $ids['oud'] ) );
rss_ok( '' !== $al && false !== stripos( $al, 'oud' ), 'local alt text from product name' );
rss_eq( LocalWriter::media_title( $ids['images']['media2'] ), '', 'generic camera file name gives no alt text' );
rss_eq( LocalWriter::media_title( $ids['images']['media1'] ), 'Red Rose Attar Bottle', 'meaningful file name becomes title' );

/* ================================================================== */
rss_section( 'Catalog health' );
$hd = Analyzer::catalog_health( true );
rss_ok( $hd['products'] >= 9, 'scanned all products' );
$dupe_ok = false;
foreach ( $hd['duplicates'] as $grp ) {
	if ( in_array( $ids['dup1'], $grp['ids'], true ) && in_array( $ids['dup2'], $grp['ids'], true ) ) {
		$dupe_ok = true;
	}
}
rss_ok( $dupe_ok, 'duplicate descriptions detected' );
rss_ok( in_array( $ids['spray'], $hd['ids']['thin'], true ), 'thin description detected' );
rss_ok( in_array( $ids['noimg'], $hd['ids']['no_image'], true ), 'missing image detected' );

/* ================================================================== */
rss_section( 'Bulk Manager' );
rss_settings( array( 'bulk' => array( 'batch_size' => 3, 'time_budget' => 20 ) ) );
$job = Jobs::create( 'seo_local', array( 'ids' => implode( ',', array( $ids['oud'], $ids['spray'], $ids['attar'], $ids['dup1'], $ids['dup2'], $ids['busy'], 999999 ) ) ), array( 'batch_size' => 3 ) );
rss_ok( ! is_wp_error( $job ) && 6 === $job['total'], 'job created from IDs (unknown IDs ignored)', is_wp_error( $job ) ? $job->get_error_message() : (string) $job['total'] );
$t1 = Jobs::tick( $job['id'] );
rss_eq( $t1['processed'], 3, 'first tick processes one batch' );
Jobs::control( $job['id'], 'pause' );
$t2 = Jobs::tick( $job['id'] );
rss_eq( $t2['processed'], 0, 'paused job is not processed' );
Jobs::control( $job['id'], 'resume' );
// Lock: simulate another worker holding the job.
$wpdb->update( Jobs::table(), array( 'lock_token' => 'other', 'lock_expires' => time() + 60 ), array( 'id' => $job['id'] ) );
rss_eq( Jobs::tick( $job['id'] )['processed'], 0, 'locked job skipped (no double processing)' );
$wpdb->update( Jobs::table(), array( 'lock_token' => '', 'lock_expires' => 0 ), array( 'id' => $job['id'] ) );
Jobs::tick( $job['id'] );
$j = Jobs::get( $job['id'] );
rss_eq( $j['status'], 'completed', 'job completes' );
rss_eq( $j['processed'], 6, 'all items processed' );
rss_ok( ( $j['drafts']['pending'] ?? 0 ) >= 6, 'job produced pending drafts', wp_json_encode( $j['drafts'] ) );
$snapshot = rss_product_snapshot( $ids['oud'] );
$ap       = Jobs::drafts_action( $job['id'], 'apply_clean' );
rss_ok( $ap['done'] >= 6 && 0 === $ap['failed'], 'apply all clean drafts', wp_json_encode( $ap ) );
rss_eq( rss_product_snapshot( $ids['oud'] ), $snapshot, 'bulk apply leaves price/stock/SKU unchanged' );
rss_ok( '' !== get_post_meta( $ids['dup1'], '_yoast_wpseo_metadesc', true ), 'meta description written in bulk' );
$rb = Jobs::drafts_action( $job['id'], 'rollback_applied', 100 );
rss_ok( $rb['done'] === $ap['done'], 'roll back the whole job', wp_json_encode( $rb ) );
rss_eq( get_post_meta( $ids['dup1'], '_yoast_wpseo_metadesc', true ), '', 'bulk rollback restored empty meta' );
$csv = Jobs::report_csv( $job['id'] );
rss_ok( substr_count( $csv, "\n" ) === 7 && false !== strpos( $csv, 'item_id' ), 'CSV report has header + 6 rows' );
// Cancel + retry.
$job2 = Jobs::create( 'content_local', array( 'ids' => $ids['oud'] . ',' . $ids['spray'] . ',' . $ids['attar'] ), array( 'fields' => array( 'tags' ), 'batch_size' => 1 ) );
Jobs::tick( $job2['id'] );
Jobs::control( $job2['id'], 'cancel' );
$j2 = Jobs::get( $job2['id'] );
rss_ok( 'cancelled' === $j2['status'] && 2 === $j2['skipped'], 'cancel skips remaining items' );
Jobs::drafts_action( $job2['id'], 'reject_pending' );
$wpdb->update( Jobs::table( 'items' ), array( 'status' => 'failed', 'message' => 'simulated' ), array( 'job_id' => $job2['id'], 'object_id' => $ids['spray'] ) );
Jobs::recount( $job2['id'] );
$rt = Jobs::control( $job2['id'], 'retry' );
rss_eq( $rt['status'], 'running', 'retry re-queues failed items' );
Jobs::tick( $job2['id'] );
rss_eq( Jobs::get( $job2['id'] )['status'], 'completed', 'retried job completes' );
Jobs::drafts_action( $job2['id'], 'reject_pending' );
// AI job requires switch + confirmation.
rss_err( Jobs::create( 'content_ai', array( 'ids' => $ids['oud'] ), array( 'fields' => array( 'seo_title' ) ) ), 'rar_ai_off', 'AI bulk job refused while AI is OFF' );
Gateway::set_master( true, true );
Settings::flush();
rss_err( Jobs::create( 'content_ai', array( 'ids' => $ids['oud'] ), array( 'fields' => array( 'seo_title' ) ) ), 'rar_ai_ack', 'AI bulk job needs cost confirmation' );
rss_settings( array( 'ai' => array( 'daily_limit' => Usage::summary()['today_requests'] + 1, 'per_minute' => 60 ) ) );
$job3 = Jobs::create( 'content_ai', array( 'ids' => $ids['oud'] . ',' . $ids['spray'] . ',' . $ids['attar'] ), array( 'fields' => array( 'seo_title' ), 'confirm_ai' => 1, 'batch_size' => 5 ) );
rss_mock( array( rss_ai_text( 'gemini', $fields_json ), rss_ai_text( 'gemini', $fields_json ) ) );
Jobs::tick( $job3['id'] );
$j3 = Jobs::get( $job3['id'] );
rss_ok( 'paused' === $j3['status'] && false !== stripos( $j3['last_error'], 'limit' ), 'AI job pauses itself at the daily limit', $j3['status'] . ' ' . $j3['last_error'] );
rss_eq( $j3['processed'], 1, 'items after the limit stay pending (not failed)' );
Jobs::control( $job3['id'], 'cancel' );
Jobs::drafts_action( $job3['id'], 'reject_pending' );
Gateway::set_master( false, false );
Settings::flush();
// Image job.
$job4 = Jobs::create( 'image_standardize', array( 'ids' => $ids['webp'] . ',' . $ids['noimg'] ), array( 'image' => array( 'size' => 300, 'format' => 'webp' ), 'batch_size' => 2 ) );
Jobs::tick( $job4['id'] );
$j4 = Jobs::get( $job4['id'] );
rss_ok( 'completed' === $j4['status'] && 1 === $j4['succeeded'] && 1 === $j4['skipped'], 'image job: one product processed, one without image skipped', wp_json_encode( $j4 ) );
Jobs::drafts_action( $job4['id'], 'reject_pending' );
// Alt job.
$job5 = Jobs::create( 'alt_local', array( 'missing_alt' => 1, 'product_images' => 0, 'limit' => 50 ), array() );
rss_ok( ! is_wp_error( $job5 ) && $job5['total'] > 0, 'alt-text job over media without alt' );
for ( $i = 0; $i < 20 && 'running' === Jobs::get( $job5['id'] )['status']; $i++ ) {
	Jobs::tick( $job5['id'] );
}
rss_ok( 'completed' === Jobs::get( $job5['id'] )['status'], 'alt-text job completes over several batches' );
Jobs::drafts_action( $job5['id'], 'reject_pending' );
// Cron driver runs as the job creator.
$job6 = Jobs::create( 'seo_local', array( 'ids' => (string) $ids['attar'] ), array() );
wp_set_current_user( 0 );
Jobs::tick();
wp_set_current_user( $admin->ID );
rss_eq( Jobs::get( $job6['id'] )['status'], 'completed', 'WP-Cron tick (no logged-in user) runs with the creator’s permissions' );
Jobs::drafts_action( $job6['id'], 'reject_pending' );

/* ================================================================== */
rss_section( 'REST permissions' );
$server = rest_get_server();
$routes = array(
	array( 'GET', '/rar-studio/v1/bootstrap' ),
	array( 'GET', '/rar-studio/v1/objects/product/' . $ids['oud'] ),
	array( 'POST', '/rar-studio/v1/generate' ),
	array( 'POST', '/rar-studio/v1/drafts/1/apply' ),
	array( 'POST', '/rar-studio/v1/ai/key' ),
	array( 'POST', '/rar-studio/v1/settings' ),
	array( 'POST', '/rar-studio/v1/jobs' ),
	array( 'GET', '/rar-studio/v1/logs' ),
);
$sub = get_user_by( 'login', 'rss_subscriber' );
if ( ! $sub ) {
	$sub = get_user_by( 'id', wp_insert_user( array( 'user_login' => 'rss_subscriber', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 'sub@example.test' ) ) );
}
foreach ( array( 0 => 'logged out', $sub->ID => 'subscriber' ) as $uid => $label ) {
	wp_set_current_user( $uid );
	$denied = 0;
	foreach ( $routes as $route ) {
		$resp = rest_do_request( new WP_REST_Request( $route[0], $route[1] ) );
		if ( in_array( $resp->get_status(), array( 401, 403 ), true ) ) {
			++$denied;
		}
	}
	rss_eq( $denied, count( $routes ), "{$label}: every Studio route denied" );
}
wp_set_current_user( $sm->ID );
$resp = rest_do_request( new WP_REST_Request( 'GET', '/rar-studio/v1/bootstrap' ) );
rss_eq( $resp->get_status(), 200, 'shop manager: dashboard allowed' );
foreach ( array( '/rar-studio/v1/ai/key', '/rar-studio/v1/settings', '/rar-studio/v1/ai/master' ) as $route ) {
	$resp = rest_do_request( new WP_REST_Request( 'POST', $route ) );
	rss_eq( $resp->get_status(), 403, "shop manager: {$route} denied" );
}
wp_set_current_user( $admin->ID );
$req = new WP_REST_Request( 'POST', '/rar-studio/v1/generate' );
$req->set_body_params( array( 'type' => 'product', 'id' => $ids['post_en'], 'fields' => array( 'title' ), 'engine' => 'local' ) );
rss_eq( rest_do_request( $req )->get_status(), 403, 'type mismatch (post ID as product) refused' );
$req = new WP_REST_Request( 'POST', '/rar-studio/v1/settings' );
$req->set_header( 'content-type', 'application/json' );
$req->set_body( wp_json_encode( array( 'ai' => array( 'enabled' => 1, 'providers' => array( 'openai' => array( 'key' => 'sk-should-not-save-123456789' ) ) ), 'image' => array( 'size' => 99999 ) ) ) );
rest_do_request( $req );
Settings::flush();
rss_eq( (int) Settings::get( 'ai.enabled' ), 0, 'settings endpoint cannot switch AI on' );
rss_ok( 'sk-should-not-save-123456789' !== Registry::get( 'openai' )->key(), 'settings endpoint cannot set keys' );
rss_eq( (int) Settings::get( 'image.size' ), 2400, 'out-of-range values clamped' );
$req = new WP_REST_Request( 'GET', '/rar-studio/v1/objects/search' );
$req->set_query_params( array( 'type' => 'product', 'q' => 'RSS-OUD' ) );
$found = rest_do_request( $req )->get_data();
rss_ok( ! empty( $found ) && (int) $found[0]['id'] === (int) $ids['oud'], 'product search by SKU' );

/* ================================================================== */
rss_section( 'Deleting an item removes its drafts' );
$tmp = new WC_Product_Simple();
$tmp->set_name( 'rss temporary item 30ml' );
$tmp->set_regular_price( '10' );
$tmp->save();
Generator::generate( 'product', $tmp->get_id(), array( 'title', 'tags' ), 'local', array() );
$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Drafts::table() . " WHERE object_type = 'product' AND object_id = %d", $tmp->get_id() ) );
wp_delete_post( $tmp->get_id(), true );
$after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Drafts::table() . " WHERE object_type = 'product' AND object_id = %d", $tmp->get_id() ) );
rss_ok( $before > 0 && 0 === $after, 'drafts of a permanently deleted product are removed (' . $before . ' → ' . $after . ')' );
$trash = new WC_Product_Simple();
$trash->set_name( 'rss trashed item' );
$trash->save();
Generator::generate( 'product', $trash->get_id(), array( 'title' ), 'local', array() );
wp_trash_post( $trash->get_id() );
rss_ok( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Drafts::table() . " WHERE object_type = 'product' AND object_id = %d", $trash->get_id() ) ) > 0, 'trashing keeps drafts (item can be restored)' );
wp_delete_post( $trash->get_id(), true );

/* ================================================================== */
rss_section( 'Logs' );
$q = Logger::query( array( 'level' => 'security', 'per_page' => 50 ) );
rss_ok( $q['total'] > 0, 'security events recorded' );
Logger::log( 'test', 'token sk-ant-SHOULDHIDE123456 here', array( 'api_key' => 'x', 'nested' => array( 'password' => 'y' ) ) );
$last = Logger::query( array( 'per_page' => 1 ) )['items'][0];
rss_ok( false === strpos( wp_json_encode( $last ), 'SHOULDHIDE' ) && '[redacted]' === $last['context']['api_key'] && '[redacted]' === $last['context']['nested']['password'], 'secrets redacted in messages and context' );

/* ================================================================== */
$total = $GLOBALS['rss_t']['pass'] + $GLOBALS['rss_t']['fail'];
printf( "\n%d/%d checks passed in %.1fs (peak memory %d MB)\n", $GLOBALS['rss_t']['pass'], $total, microtime( true ) - $started, memory_get_peak_usage( true ) / 1048576 );
if ( $GLOBALS['rss_t']['fail'] ) {
	echo "FAILURES:\n - " . implode( "\n - ", $GLOBALS['rss_t']['fails'] ) . "\n";
	exit( 1 );
}

/**
 * Multibyte length helper for assertions.
 *
 * @param string $s String.
 * @return int
 */
function Util_len( $s ) {
	return RarStudio\Util::len( $s );
}
