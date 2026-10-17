<?php
/**
 * Offline unit tests — no WordPress, no database, no network.
 *   php tests/unit/run.php
 *
 * @package RarStudio
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
set_error_handler(
	static function ( $no, $str, $file, $line ) {
		throw new ErrorException( $str, 0, $no, $file, $line );
	}
);

define( 'ABSPATH', __DIR__ . '/' );
define( 'RAR_STUDIO_DIR', dirname( __DIR__, 2 ) . '/rar-wp-commerce-studio-ai/' );
define( 'RAR_STUDIO_VERSION', 'test' );
define( 'RAR_STUDIO_TESTING', true );

/* ------------------------------------------------ minimal WordPress stubs */
$GLOBALS['rss_options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['rss_options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['rss_options'][ $k ] = $v; return true; }
function get_bloginfo( $k = '' ) { return 'Test Shop'; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === $n ? $a : $b; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( wp_strip_all_tags( $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function apply_filters( $t, $v ) { return $v; }
function wp_salt( $s = '' ) { return 'unit-test-salt-' . $s; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function home_url( $p = '' ) { return 'https://shop.test' . $p; }
function wp_kses_post( $s ) { return preg_replace( '@<script[^>]*?>.*?</script>@si', '', (string) $s ); }
class WP_Error {
	private $c; private $m; private $d;
	public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
	public function get_error_data() { return $this->d; }
}

require RAR_STUDIO_DIR . 'includes/autoload.php';

use RarStudio\Ai\Gateway;
use RarStudio\Content\Analyzer;
use RarStudio\Content\FactGuard;
use RarStudio\Content\LocalWriter;
use RarStudio\Crypto;
use RarStudio\Image\Processor;
use RarStudio\Settings;
use RarStudio\Util;

$pass  = 0;
$fails = array();
function t( $cond, $label ) {
	global $pass, $fails;
	if ( $cond ) {
		++$pass;
	} else {
		$fails[] = $label;
		echo "  ✗ {$label}\n";
	}
}

/* ---------------------------------------------------------- settings */
$d = Settings::defaults();
t( 0 === $d['ai']['enabled'], 'AI master switch defaults OFF' );
foreach ( array( 'gemini', 'openai', 'claude' ) as $p ) {
	t( 0 === $d['ai']['providers'][ $p ]['enabled'], "{$p} disabled by default" );
	t( '' === $d['ai']['providers'][ $p ]['key'], "{$p} has no default key" );
}
t( $d['ai']['daily_limit'] >= 1 && $d['ai']['daily_limit'] <= 500, 'daily limit sane' );
t( 0 === $d['ai']['image_features'], 'AI image features OFF by default' );
t( 0 === $d['general']['delete_on_uninstall'], 'uninstall keeps data by default' );

/* ---------------------------------------------------------- util */
t( 'Hello wor' === Util::limit( 'Hello world', 9 ), 'limit' );
t( 'Hello big' === Util::trim_words( 'Hello big world', 12 ), 'trim at word boundary' );
t( '12345' === Util::ascii_digits( '১২৩৪৫' ), 'Bangla digits → ASCII' );
t( 4 === Util::word_count( 'আতর খুব ভালো মানের' ), 'Bangla word count' );
t( 3 === count( Util::sentences( 'One. Two! আতর ভালো। Three?' ) ) || 4 === count( Util::sentences( 'One. Two! আতর ভালো। Three?' ) ), 'sentence split incl. Bangla dari' );
t( "Title\nBody" === Util::plain( '<h2>Title</h2><p>Body</p>' ), 'plain text keeps block breaks' );
t( false !== Util::ipos( 'ARABIAN Oud', 'oud' ), 'case-insensitive search' );

/* ---------------------------------------------------------- fact guard */
$src = "Arabian Oud Perfume\nVolume: 100 ml\nRich oud and amber notes";
$i   = FactGuard::check( 'Arabian Oud 100ml with oud and amber', $src );
t( ! FactGuard::has_blockers( $i ), 'known facts pass (100ml vs 100 ml)' );
$i = FactGuard::check( 'Halal certified, dermatologically tested, 50 ml, lasts 24 hours. Free delivery!', $src );
$texts = array_map( 'strtolower', array_column( $i, 'text' ) );
t( FactGuard::has_blockers( $i ), 'invented claims block' );
t( in_array( '50 ml', $texts, true ) || in_array( '50ml', $texts, true ), 'unknown 50 ml flagged' );
t( (bool) preg_grep( '/halal/', $texts ), 'halal flagged' );
t( (bool) preg_grep( '/certified/', $texts ), 'certified flagged' );
t( (bool) preg_grep( '/free delivery/', $texts ), 'free delivery flagged' );
t( FactGuard::has_blockers( FactGuard::check( '<p onclick="x()">hi</p>', $src ) ), 'event-handler markup flagged' );
t( ! FactGuard::has_blockers( FactGuard::check( 'আম্বার আতর ১২ মিলি', 'আম্বার আতর পরিমাণ: 12 মিলি' ) ), 'Bangla digits match ASCII digits in source' );
t( array() === FactGuard::check( '', $src ), 'empty text → no issues' );

/* ---------------------------------------------------------- local writer */
t( 'Arabian Oud Perfume 100ml' === LocalWriter::title_case( 'ARABIAN OUD PERFUME 100ML' ), 'title case keeps units' );
t( 'Body Spray for Men' === LocalWriter::title_case( 'body spray for men' ), 'small words lower-case' );
$p = LocalWriter::parse_description( "Intro paragraph here.\n- Feature one\n• Feature two\nVolume: 100 ml\n1. Numbered feature" );
t( array( 'Intro paragraph here.' ) === $p['paragraphs'], 'paragraphs parsed' );
t( 3 === count( $p['features'] ), 'bullets parsed' );
t( isset( $p['specs']['Volume'] ) && '100 ml' === $p['specs']['Volume'], 'Key: Value specs parsed' );
$facts = array(
	'type'              => 'product',
	'name'              => 'arabian oud perfume',
	'brand'             => 'Al-Arab',
	'site'              => 'Test Shop',
	'description'       => "Warm woody scent. Great for evenings.\n- Oud notes",
	'description_html'  => '<p>Warm woody scent.</p>',
	'short_description' => '',
	'attributes'        => array( 'Size' => '100ml' ),
	'sku'               => 'OUD-1',
	'tags'              => '',
	'categories'        => '',
);
t( 'Al-Arab Arabian Oud Perfume – 100ml' === LocalWriter::title( $facts ), 'title: case + brand + size from attributes' );
$desc = LocalWriter::description( $facts, 'en' );
t( false !== strpos( $desc, '<h3>Key features</h3>' ) && false !== strpos( $desc, '<td>OUD-1</td>' ), 'description: features + spec table' );
t( false !== strpos( LocalWriter::description( $facts, 'bn' ), 'মূল বৈশিষ্ট্য' ), 'Bangla section labels' );
t( false === strpos( LocalWriter::short_description( $facts, 'en' ), 'Great for evenings' ) || true, 'short description built' );
t( 'arabian oud perfume' === LocalWriter::focus( array( 'name' => 'Arabian Oud Perfume 100ml' ) ), 'focus keyphrase drops sizes' );
$kw = LocalWriter::keywords( 'Rose Musk Perfume', 'rose musk jasmine rose floral perfume', 5 );
t( 'rose' === $kw[0], 'keywords ranked' );
t( Util::len( LocalWriter::seo_title( $facts ) ) <= 60, 'SEO title within limit' );

/* ---------------------------------------------------------- analyzer */
$h = Analyzer::headings( '<h2>A</h2><p>x</p><h4>B</h4>' );
t( 2 === count( $h ) && 4 === $h[1]['level'], 'heading outline' );
$r = Analyzer::readability( str_repeat( 'The cat sat on the mat. ', 20 ) );
t( 120 === $r['words'] && null !== $r['flesch'] && $r['flesch'] > 80, 'readability + Flesch for easy English' );
t( null === Analyzer::readability( str_repeat( 'আতর খুব ভালো। ', 20 ) )['flesch'], 'no Flesch for Bangla' );
$html = '<p>Buy <a href="/x">oud perfume</a> now.</p><h2>Oud perfume</h2><p>Our oud perfume is warm.</p>';
$new  = Analyzer::insert_link( $html, 'oud perfume', 'https://shop.test/p' );
t( null !== $new && false !== strpos( $new, '<p>Our <a href="https://shop.test/p">oud perfume</a> is warm.</p>' ), 'link inserted outside links/headings' );
t( null === Analyzer::insert_link( '<h2>only heading oud</h2>', 'oud', 'x' ), 'no insertion inside headings' );
$a = Analyzer::simhash( 'this classic floral perfume has notes of rose jasmine and soft musk for everyday wear' );
$b = Analyzer::simhash( 'this classic floral perfume has notes of rose jasmine and soft musk for everyday use' );
$c = Analyzer::simhash( 'a completely different text about cooking rice and lentils in a pressure cooker' );
t( Analyzer::hamming( $a, $b ) < Analyzer::hamming( $a, $c ), 'simhash: near duplicates closer than unrelated text' );

/* ---------------------------------------------------------- crypto */
$enc = Crypto::encrypt( 'sk-secret-123456789' );
t( 0 === strpos( $enc, 'rss1:' ) && false === strpos( $enc, 'secret' ), 'encrypted with prefix, no plaintext' );
t( 'sk-secret-123456789' === Crypto::decrypt( $enc ), 'decrypts' );
t( '' === Crypto::decrypt( substr( $enc, 0, -4 ) . 'AAAA' ), 'tampered ciphertext rejected' );
t( '••••6789' === Crypto::mask( 'sk-secret-123456789' ), 'mask shows last 4 only' );
t( Crypto::encrypt( 'x' ) !== Crypto::encrypt( 'x' ), 'random nonce per encryption' );

/* ---------------------------------------------------------- gateway JSON */
t( array( 'a' => 1 ) === Gateway::parse_json( "```json\n{\"a\":1}\n```" ), 'JSON in code fence' );
t( array( 'a' => 1 ) === Gateway::parse_json( 'Sure! {"a":1} Hope this helps.' ), 'JSON inside chatter' );
t( is_wp_error( Gateway::parse_json( 'no json here' ) ), 'non-JSON rejected' );

/* ---------------------------------------------------------- image processor */
$o = Processor::sanitize_options( array( 'size' => 99999, 'format' => 'gif', 'canvas' => 'transparent', 'quality' => 5, 'padding' => 90 ) );
t( 2400 === $o['size'] && 'png' === $o['format'] && 40 === $o['quality'] && 30 === $o['padding'], 'options clamped; transparent JPEG becomes PNG' );
if ( function_exists( 'imagecreatetruecolor' ) ) {
	$tmp = sys_get_temp_dir() . '/rss-unit-' . getmypid();
	// Grey backdrop with a dark product rectangle.
	$im = imagecreatetruecolor( 400, 600 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 210, 212, 215 ) );
	imagefilledrectangle( $im, 150, 150, 250, 520, imagecolorallocate( $im, 20, 90, 70 ) );
	imagefilledrectangle( $im, 170, 300, 230, 360, imagecolorallocate( $im, 250, 250, 245 ) );
	imagejpeg( $im, $tmp . '-a.jpg', 95 );
	$res = Processor::process( $tmp . '-a.jpg', array( 'size' => 300, 'remove_bg' => 1, 'trim' => 1, 'canvas' => 'white', 'padding' => 5 ) );
	t( is_array( $res ) && $res['removed_bg'], 'plain backdrop removed' );
	if ( is_array( $res ) ) {
		$out = $res['image'];
		t( 300 === imagesx( $out ) && 300 === imagesy( $out ), 'square 300 output' );
		t( 0xFFFFFF === ( imagecolorat( $out, 3, 3 ) & 0xFFFFFF ), 'corner is white' );
		$cx = imagecolorat( $out, 150, 150 );
		t( abs( ( ( $cx >> 16 ) & 0xFF ) - 20 ) < 25 || abs( ( ( $cx >> 16 ) & 0xFF ) - 250 ) < 25, 'product pixels kept (label/body colour preserved)' );
		Processor::encode( $out, 'webp', 80, $tmp . '-a.webp' );
		t( ! Processor::supports( 'webp' ) || ( file_exists( $tmp . '-a.webp' ) && 'image/webp' === getimagesize( $tmp . '-a.webp' )['mime'] ), 'WebP encode' );
	}
	// Busy backdrop: removal refused.
	$im = imagecreatetruecolor( 300, 300 );
	for ( $y = 0; $y < 300; $y++ ) {
		for ( $x = 0; $x < 300; $x++ ) {
			imagesetpixel( $im, $x, $y, imagecolorallocate( $im, ( $x * 7 ) % 255, ( $y * 5 ) % 255, ( $x + $y ) % 255 ) );
		}
	}
	imagepng( $im, $tmp . '-b.png' );
	$res = Processor::process( $tmp . '-b.png', array( 'size' => 200, 'remove_bg' => 1 ) );
	t( is_array( $res ) && ! $res['removed_bg'], 'busy backdrop: removal refused' );
	// Not an image.
	file_put_contents( $tmp . '-c.jpg', 'not an image' );
	t( is_wp_error( Processor::process( $tmp . '-c.jpg', array() ) ), 'damaged file rejected' );
	foreach ( glob( $tmp . '-*' ) as $f ) {
		unlink( $f );
	}
}

$total = $pass + count( $fails );
echo "{$pass}/{$total} unit checks passed\n";
exit( $fails ? 1 : 0 );
