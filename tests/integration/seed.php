<?php
/**
 * Test fixtures for a LOCAL / STAGING site only. Run with:
 *   wp eval-file tests/integration/seed.php
 * Creates products (simple + variable), posts, pages, an Elementor-style page,
 * terms and generated images. Everything is tagged with meta _rss_seed = 1.
 *
 * @package RarStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
if ( 'production' === wp_get_environment_type() && ! getenv( 'RSS_ALLOW_SEED' ) ) {
	fwrite( STDERR, "Refusing to seed a production site.\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

/**
 * Remove previous seed data.
 */
function rss_seed_clean() {
	$old = get_posts(
		array(
			'post_type'   => array( 'product', 'product_variation', 'post', 'page', 'attachment' ),
			'post_status' => 'any',
			'numberposts' => -1,
			'meta_key'    => '_rss_seed',
			'fields'      => 'ids',
		)
	);
	foreach ( $old as $id ) {
		if ( 'attachment' === get_post_type( $id ) ) {
			wp_delete_attachment( $id, true );
		} else {
			wp_delete_post( $id, true );
		}
	}
}

/**
 * Draw a bottle on a background and save it.
 *
 * @param string $name   File name.
 * @param string $bg     white|grey|transparent|gradient|beige.
 * @param string $format jpg|png|webp.
 * @param int    $w      Width.
 * @param int    $h      Height.
 * @param string $label  Label text.
 * @return int Attachment ID.
 */
function rss_seed_image( $name, $bg, $format, $w, $h, $label, $parent = 0 ) {
	$img = imagecreatetruecolor( $w, $h );
	imagealphablending( $img, false );
	imagesavealpha( $img, true );
	switch ( $bg ) {
		case 'transparent':
			imagefill( $img, 0, 0, imagecolorallocatealpha( $img, 0, 0, 0, 127 ) );
			break;
		case 'grey':
			imagefill( $img, 0, 0, imagecolorallocate( $img, 214, 216, 220 ) );
			break;
		case 'beige':
			imagefill( $img, 0, 0, imagecolorallocate( $img, 242, 233, 216 ) );
			break;
		case 'gradient':
			for ( $y = 0; $y < $h; $y++ ) {
				for ( $x = 0; $x < $w; $x += 4 ) {
					$c = imagecolorallocate( $img, ( $x * 7 + $y ) % 255, ( $y * 3 ) % 255, ( $x + $y * 5 ) % 255 );
					imagefilledrectangle( $img, $x, $y, $x + 3, $y, $c );
				}
			}
			break;
		default:
			imagefill( $img, 0, 0, imagecolorallocate( $img, 255, 255, 255 ) );
	}
	imagealphablending( $img, true );
	$cx    = (int) ( $w / 2 );
	$bw    = (int) ( $w * 0.34 );
	$top   = (int) ( $h * 0.32 );
	$bot   = (int) ( $h * 0.86 );
	$glass = imagecolorallocate( $img, 28, 92, 74 );
	$cap   = imagecolorallocate( $img, 196, 154, 60 );
	$paper = imagecolorallocate( $img, 250, 247, 238 );
	$ink   = imagecolorallocate( $img, 20, 20, 20 );
	imagefilledrectangle( $img, (int) ( $cx - $bw / 2 ), $top, (int) ( $cx + $bw / 2 ), $bot, $glass );
	imagefilledrectangle( $img, (int) ( $cx - $bw / 6 ), (int) ( $top - $h * 0.08 ), (int) ( $cx + $bw / 6 ), $top, $glass );
	imagefilledrectangle( $img, (int) ( $cx - $bw / 5 ), (int) ( $top - $h * 0.16 ), (int) ( $cx + $bw / 5 ), (int) ( $top - $h * 0.08 ), $cap );
	imagefilledrectangle( $img, (int) ( $cx - $bw / 2 + 12 ), (int) ( $top + ( $bot - $top ) * 0.3 ), (int) ( $cx + $bw / 2 - 12 ), (int) ( $top + ( $bot - $top ) * 0.62 ), $paper );
	imagestring( $img, 5, (int) ( $cx - strlen( $label ) * 4.5 ), (int) ( $top + ( $bot - $top ) * 0.43 ), $label, $ink );
	// Soft shadow line under the bottle.
	imagefilledellipse( $img, (int) $cx, $bot + 6, (int) ( $bw + 30 ), 14, imagecolorallocatealpha( $img, 0, 0, 0, 100 ) );
	$upload = wp_upload_dir();
	$path   = trailingslashit( $upload['path'] ) . wp_unique_filename( $upload['path'], $name . '.' . $format );
	if ( 'png' === $format ) {
		imagepng( $img, $path );
		$mime = 'image/png';
	} elseif ( 'webp' === $format ) {
		imagewebp( $img, $path, 90 );
		$mime = 'image/webp';
	} else {
		imagejpeg( $img, $path, 92 );
		$mime = 'image/jpeg';
	}
	unset( $img ); // GD images are freed automatically on PHP 8+; imagedestroy() is deprecated in 8.5.
	$att = wp_insert_attachment(
		array(
			'post_mime_type' => $mime,
			'post_title'     => $name,
			'post_status'    => 'inherit',
			'post_parent'    => $parent,
		),
		$path,
		$parent
	);
	wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $path ) );
	update_post_meta( $att, '_rss_seed', 1 );
	return (int) $att;
}

/**
 * Term helper.
 */
function rss_seed_term( $name, $tax ) {
	if ( ! taxonomy_exists( $tax ) ) {
		return 0; // e.g. product_brand before WooCommerce 9.6.
	}
	$t = term_exists( $name, $tax );
	if ( ! $t ) {
		$t = wp_insert_term( $name, $tax );
	}
	return (int) ( is_array( $t ) ? $t['term_id'] : $t );
}

rss_seed_clean();

$cat_perfume = rss_seed_term( 'Perfume', 'product_cat' );
$cat_spray   = rss_seed_term( 'Body Spray', 'product_cat' );
$cat_attar   = rss_seed_term( 'Attar', 'product_cat' );
rss_seed_term( 'Air Freshener', 'product_cat' );
$brand_arab = rss_seed_term( 'Al-Arab', 'product_brand' );
$brand_nab  = rss_seed_term( 'Nabiad', 'product_brand' );
rss_seed_term( 'Signal', 'product_brand' );

$ids = array();

/**
 * Create a simple product.
 */
function rss_seed_product( array $a ) {
	$p = new WC_Product_Simple();
	$p->set_name( $a['name'] );
	$p->set_description( $a['description'] ?? '' );
	$p->set_short_description( $a['short'] ?? '' );
	$p->set_regular_price( $a['price'] ?? '1250' );
	$p->set_sku( $a['sku'] );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $a['stock'] ?? 37 );
	$p->set_status( 'publish' );
	if ( ! empty( $a['cats'] ) ) {
		$p->set_category_ids( $a['cats'] );
	}
	if ( ! empty( $a['attributes'] ) ) {
		$attrs = array();
		foreach ( $a['attributes'] as $label => $value ) {
			$attr = new WC_Product_Attribute();
			$attr->set_name( $label );
			$attr->set_options( array( $value ) );
			$attr->set_visible( true );
			$attrs[] = $attr;
		}
		$p->set_attributes( $attrs );
	}
	$id = $p->save();
	update_post_meta( $id, '_rss_seed', 1 );
	if ( ! empty( $a['brand'] ) && taxonomy_exists( 'product_brand' ) ) {
		wp_set_object_terms( $id, array( $a['brand'] ), 'product_brand' );
	}
	return $id;
}

$ids['oud'] = rss_seed_product(
	array(
		'name'        => 'ARABIAN OUD PERFUME 100ML',
		'sku'         => 'RSS-OUD-100',
		'price'       => '2450',
		'stock'       => 37,
		'cats'        => array( $cat_perfume ),
		'brand'       => $brand_arab,
		'attributes'  => array(
			'Size'             => '100ml',
			'Fragrance family' => 'Woody oriental',
		),
		'description' => "Arabian Oud is a warm woody perfume with a smooth oud and amber character. It suits evening wear and special occasions.\n\n- Rich oud and amber notes\n- Elegant glass bottle with gold cap\n- Spray nozzle for even application\n\nVolume: 100 ml\nConcentration: Eau de Parfum",
	)
);
$ids['spray'] = rss_seed_product(
	array(
		'name'        => 'body spray fresh blue',
		'sku'         => 'RSS-SPRAY-BLUE',
		'price'       => '450',
		'stock'       => 120,
		'description' => 'A fresh blue body spray for daily use.',
	)
);
$ids['attar'] = rss_seed_product(
	array(
		'name'        => 'আতর মুসক আম্বার ১২ মিলি',
		'sku'         => 'RSS-ATTAR-12',
		'price'       => '650',
		'stock'       => 15,
		'cats'        => array( $cat_attar ),
		'brand'       => $brand_nab,
		'attributes'  => array( 'পরিমাণ' => '১২ মিলি' ),
		'description' => 'মুসক আম্বার একটি মিষ্টি ও উষ্ণ সুগন্ধি আতর। এটি অ্যালকোহল ছাড়া তৈরি তেলভিত্তিক আতর। অল্প পরিমাণে ব্যবহার করুন।',
	)
);
$dup_text      = 'This classic floral perfume has notes of rose, jasmine and soft musk. It is a gentle everyday scent that works well for office and casual wear, and the compact bottle fits easily in a bag.';
$ids['dup1']   = rss_seed_product( array( 'name' => 'Rose Musk Perfume 50ml', 'sku' => 'RSS-DUP-1', 'cats' => array( $cat_perfume ), 'description' => $dup_text ) );
$ids['dup2']   = rss_seed_product( array( 'name' => 'Jasmine Musk Perfume 50ml', 'sku' => 'RSS-DUP-2', 'cats' => array( $cat_perfume ), 'description' => $dup_text ) );
$ids['busy']   = rss_seed_product( array( 'name' => 'Signal Night Air Freshener', 'sku' => 'RSS-BUSY', 'description' => 'Air freshener with a calm night fragrance for bedrooms and living rooms. Spray two to three times in the air.' ) );
$ids['noimg']  = rss_seed_product( array( 'name' => 'Gift Box Without Photo', 'sku' => 'RSS-NOIMG', 'description' => 'A gift box.' ) );
$ids['webp']   = rss_seed_product( array( 'name' => 'Amber Wood Body Spray 150ml', 'sku' => 'RSS-WEBP', 'cats' => array( $cat_spray ), 'description' => 'Amber Wood body spray with a warm amber and cedar scent. Light enough for daily use.' ) );

// Variable product with two variations (stock/price must never change).
$var = new WC_Product_Variable();
$var->set_name( 'Classic Attar Collection' );
$var->set_sku( 'RSS-VAR' );
$var->set_description( 'Classic attar collection in two sizes.' );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$attr->set_options( array( '6ml', '12ml' ) );
$attr->set_variation( true );
$attr->set_visible( true );
$var->set_attributes( array( $attr ) );
$var->set_category_ids( array( $cat_attar ) );
$var_id = $var->save();
update_post_meta( $var_id, '_rss_seed', 1 );
foreach ( array( '6ml' => array( '350', 11 ), '12ml' => array( '600', 4 ) ) as $size => $data ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $var_id );
	$v->set_attributes( array( 'size' => $size ) );
	$v->set_regular_price( $data[0] );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( $data[1] );
	$v->set_sku( 'RSS-VAR-' . $size );
	$vid = $v->save();
	update_post_meta( $vid, '_rss_seed', 1 );
}
$ids['variable'] = $var_id;

// Images.
$img = array(
	'oud'      => rss_seed_image( 'arabian-oud-perfume', 'white', 'jpg', 900, 1300, 'OUD 100ML', $ids['oud'] ),
	'oud_g1'   => rss_seed_image( 'IMG_20240101_101010', 'grey', 'jpg', 1000, 1000, 'OUD BACK', $ids['oud'] ),
	'oud_g2'   => rss_seed_image( 'oud-cutout', 'transparent', 'png', 700, 900, 'OUD', $ids['oud'] ),
	'spray'    => rss_seed_image( 'fresh-blue-spray', 'beige', 'jpg', 640, 960, 'BLUE', $ids['spray'] ),
	'attar'    => rss_seed_image( 'musk-amber-attar', 'white', 'png', 600, 800, 'MUSK', $ids['attar'] ),
	'busy'     => rss_seed_image( 'night-air-busy-background', 'gradient', 'jpg', 800, 800, 'NIGHT', $ids['busy'] ),
	'webp'     => rss_seed_image( 'amber-wood-spray', 'white', 'webp', 1200, 1200, 'AMBER', $ids['webp'] ),
	'dup1'     => rss_seed_image( 'rose-musk', 'white', 'jpg', 500, 500, 'ROSE', $ids['dup1'] ),
	'tiny'     => rss_seed_image( 'small-attar-sample', 'white', 'jpg', 240, 320, 'MINI', $ids['variable'] ),
	'media1'   => rss_seed_image( 'red-rose-attar-bottle', 'white', 'jpg', 600, 600, 'ROSE', 0 ),
	'media2'   => rss_seed_image( 'IMG_5531', 'white', 'jpg', 600, 600, 'X', 0 ),
);
foreach ( array( 'oud' => 'oud', 'spray' => 'spray', 'attar' => 'attar', 'busy' => 'busy', 'webp' => 'webp', 'dup1' => 'dup1', 'variable' => 'tiny' ) as $pkey => $ikey ) {
	set_post_thumbnail( $ids[ $pkey ], $img[ $ikey ] );
}
update_post_meta( $ids['oud'], '_product_image_gallery', $img['oud_g1'] . ',' . $img['oud_g2'] );
update_post_meta( $img['oud'], '_wp_attachment_image_alt', 'Arabian Oud perfume bottle' );
wc_delete_product_transients( $ids['oud'] );

// Posts and pages.
$post_html = '<p>Choosing a perfume for hot weather is easier than it looks. Light citrus and green notes feel fresh, while heavy oud can feel strong in humid air.</p>'
	. '<h2>Why season matters</h2><p>Heat makes fragrance evaporate faster, so the top notes appear stronger and fade sooner. Many people in Dhaka prefer fresh blue or citrus scents during the summer months because they feel clean and light during long commutes, office hours and evening gatherings with family and friends.</p>'
	. '<h4>Quick tips</h4><ul><li>Spray on pulse points.</li><li>Store bottles away from sunlight.</li></ul>'
	. '<p>Our body spray fresh blue and the amber wood body spray are good examples of lighter everyday scents that suit warm days.</p>'
	. '<p><img src="' . esc_url( wp_get_attachment_url( $img['media1'] ) ) . '" class="wp-image-' . $img['media1'] . '"></p>';
$ids['post_en'] = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'How to choose a perfume for summer',
		'post_content' => $post_html,
	)
);
$ids['post_bn'] = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'আতর ব্যবহারের সঠিক নিয়ম',
		'post_content' => '<p>আতর খুব অল্প পরিমাণে ব্যবহার করতে হয়। কব্জি ও কানের পেছনে এক ফোঁটা যথেষ্ট।</p><h2>সংরক্ষণ</h2><p>আতরের বোতল ঠান্ডা ও অন্ধকার জায়গায় রাখুন।</p>',
	)
);
$ids['page'] = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'About our fragrances',
		'post_content' => '<p>We sell perfume, attar and body spray across Bangladesh.</p>',
	)
);
$ids['elementor'] = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Elementor Landing Page',
		'post_content' => '<p>Fallback content.</p>',
	)
);
update_post_meta( $ids['elementor'], '_elementor_edit_mode', 'builder' );
update_post_meta(
	$ids['elementor'],
	'_elementor_data',
	wp_slash(
		wp_json_encode(
			array(
				array(
					'elType'   => 'section',
					'elements' => array(
						array(
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array(
								'title'       => 'Signature Scents',
								'header_size' => 'h1',
							),
						),
						array(
							'elType'     => 'widget',
							'widgetType' => 'text-editor',
							'settings'   => array( 'editor' => '<p>Discover our signature perfume and attar range.</p>' ),
						),
					),
				),
			)
		)
	)
);
foreach ( array( 'post_en', 'post_bn', 'page', 'elementor' ) as $k ) {
	update_post_meta( $ids[ $k ], '_rss_seed', 1 );
}

update_option( 'rss_seed_ids', array_merge( $ids, array( 'images' => $img ) ), false );
echo wp_json_encode( array_merge( $ids, array( 'images' => $img ) ) ) . "\n";
