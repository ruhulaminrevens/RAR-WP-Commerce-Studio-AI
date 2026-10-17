<?php
/**
 * Image Studio: creates NEW derivative attachments (originals are never
 * modified) and turns them into reviewable featured/gallery drafts.
 *
 * @package RarStudio
 */

namespace RarStudio\Image;

use RarStudio\Ai\Gateway;
use RarStudio\Content\LocalWriter;
use RarStudio\Content\Prompts;
use RarStudio\Drafts;
use RarStudio\Logger;
use RarStudio\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Derivative manager.
 */
final class Studio {

	/**
	 * Is an attachment used as a featured image, in a product gallery or in content?
	 *
	 * @param int $attachment_id Attachment.
	 * @return bool
	 */
	public static function attachment_in_use( $attachment_id ) {
		global $wpdb;
		$id = (string) absint( $attachment_id );
		// phpcs:disable WordPress.DB
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1", $id ) ) ) {
			return true;
		}
		$galleries = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value LIKE %s LIMIT 200", '%' . $wpdb->esc_like( $id ) . '%' ) );
		foreach ( $galleries as $gallery ) {
			if ( in_array( $id, explode( ',', (string) $gallery ), true ) ) {
				return true;
			}
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status <> 'inherit' AND post_type <> 'revision' AND post_content LIKE %s LIMIT 1", '%wp-image-' . $wpdb->esc_like( $id ) . '%' ) ) ) {
			return true;
		}
		// phpcs:enable
		return false;
	}

	/**
	 * Images of a product (featured first, then gallery).
	 *
	 * @param int $product_id Product.
	 * @return array[]
	 */
	public static function product_images( $product_id ) {
		if ( ! Util::wc_active() ) {
			return array();
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}
		$out   = array();
		$items = array();
		if ( $product->get_image_id() ) {
			$items[] = array( 'featured', -1, (int) $product->get_image_id() );
		}
		foreach ( array_values( $product->get_gallery_image_ids() ) as $i => $att ) {
			$items[] = array( 'gallery', $i, (int) $att );
		}
		foreach ( $items as $item ) {
			$out[] = array_merge(
				self::describe( $item[2] ),
				array(
					'role'  => $item[0],
					'index' => $item[1],
				)
			);
		}
		return $out;
	}

	/**
	 * Attachment details for the UI.
	 *
	 * @param int $att Attachment.
	 * @return array
	 */
	public static function describe( $att ) {
		$file  = get_attached_file( $att );
		$full  = wp_get_attachment_image_src( $att, 'full' );
		$thumb = wp_get_attachment_image_src( $att, 'medium' );
		return array(
			'id'         => (int) $att,
			'url'        => $thumb ? $thumb[0] : '',
			'full'       => $full ? $full[0] : '',
			'width'      => $full ? (int) $full[1] : 0,
			'height'     => $full ? (int) $full[2] : 0,
			'bytes'      => ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0,
			'mime'       => (string) get_post_mime_type( $att ),
			'alt'        => (string) get_post_meta( $att, '_wp_attachment_image_alt', true ),
			'title'      => get_the_title( $att ),
			'derivative' => (int) get_post_meta( $att, '_rar_studio_derivative', true ),
		);
	}

	/**
	 * Save a processed GD image as a new attachment.
	 *
	 * @param \GdImage|resource $img     Image.
	 * @param array             $o       Options.
	 * @param int               $source  Source attachment.
	 * @param int               $parent  Parent post.
	 * @param string            $suffix  File suffix.
	 * @return int|\WP_Error Attachment ID.
	 */
	private static function save_attachment( $img, array $o, $source, $parent, $suffix ) {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			Processor::free( $img );
			return new \WP_Error( 'rar_upload', (string) $upload['error'] );
		}
		$ext  = 'jpeg' === $o['format'] ? 'jpg' : $o['format'];
		$base = sanitize_file_name( pathinfo( (string) get_attached_file( $source ), PATHINFO_FILENAME ) );
		$base = preg_replace( '/-studio-\d+.*$/', '', $base );
		$name = wp_unique_filename( $upload['path'], $base . '-studio-' . $suffix . '.' . $ext );
		$path = trailingslashit( $upload['path'] ) . $name;
		$ok   = Processor::encode( $img, $o['format'], $o['quality'], $path );
		Processor::free( $img );
		if ( ! $ok || ! file_exists( $path ) ) {
			return new \WP_Error( 'rar_image_save', __( 'Could not write the new image file. Check that the uploads folder is writable.', 'rar-wp-commerce-studio-ai' ) );
		}
		$mime = array(
			'jpeg' => 'image/jpeg',
			'webp' => 'image/webp',
			'png'  => 'image/png',
		);
		$att  = wp_insert_attachment(
			array(
				'post_mime_type' => $mime[ $o['format'] ],
				'post_title'     => sanitize_text_field( get_the_title( $source ) . ' (Studio ' . $suffix . ')' ),
				'post_status'    => 'inherit',
				'post_parent'    => absint( $parent ),
			),
			$path,
			absint( $parent ),
			true
		);
		if ( is_wp_error( $att ) || ! $att ) {
			wp_delete_file( $path );
			return is_wp_error( $att ) ? $att : new \WP_Error( 'rar_image_save', __( 'Could not create the media item.', 'rar-wp-commerce-studio-ai' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $path ) );
		update_post_meta( $att, '_rar_studio_derivative', absint( $source ) );
		update_post_meta( $att, '_rar_studio_preset', wp_json_encode( $o ) );
		$alt = (string) get_post_meta( $source, '_wp_attachment_image_alt', true );
		if ( '' === $alt ) {
			$alt = LocalWriter::alt( $source, $parent && 'product' === get_post_type( $parent ) ? array( 'product_id' => $parent ) : array() );
		}
		if ( '' !== $alt ) {
			update_post_meta( $att, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return (int) $att;
	}

	/**
	 * Create a derivative of one image and, for product images, a pending draft.
	 *
	 * @param int   $attachment_id Source attachment.
	 * @param array $o             Image options.
	 * @param array $ctx           product_id, role (featured|gallery|media), index, job_id.
	 * @return array|\WP_Error draft (or null), derivative, source, notes
	 */
	public static function create( $attachment_id, array $o, array $ctx = array() ) {
		$attachment_id = absint( $attachment_id );
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new \WP_Error( 'rar_image', __( 'Not an image in the Media Library.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$o       = Processor::sanitize_options( $o );
		$started = microtime( true );
		$result  = Processor::process( get_attached_file( $attachment_id ), $o );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$product_id = absint( $ctx['product_id'] ?? 0 );
		$parent     = $product_id ? $product_id : (int) wp_get_post_parent_id( $attachment_id );
		$att        = self::save_attachment( $result['image'], $result['options'], $attachment_id, $parent, $o['size'] . 'px' );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		$draft = self::draft_for( $att, $attachment_id, $ctx, $result['options'], $result['notes'] );
		if ( is_wp_error( $draft ) ) {
			wp_delete_attachment( $att, true );
			return $draft;
		}
		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		Logger::log( 'image_derivative', sprintf( 'New %dpx %s image created from #%d (original unchanged).', $o['size'], strtoupper( $result['options']['format'] ), $attachment_id ), array( 'derivative' => $att, 'ms' => $ms, 'removed_bg' => $result['removed_bg'] ), 'info', $product_id ? 'product' : 'attachment', $product_id ? $product_id : $attachment_id );
		return array(
			'draft'      => $draft,
			'derivative' => self::describe( $att ),
			'source'     => self::describe( $attachment_id ),
			'notes'      => $result['notes'],
			'removed_bg' => $result['removed_bg'],
			'ms'         => $ms,
		);
	}

	/**
	 * Draft that swaps the product image (featured or one gallery slot).
	 *
	 * @param int    $att    Derivative.
	 * @param int    $source Source attachment.
	 * @param array  $ctx    Context.
	 * @param array  $o      Options.
	 * @param array  $notes  Notes.
	 * @return array|null|\WP_Error
	 */
	private static function draft_for( $att, $source, array $ctx, array $o, array $notes ) {
		$product_id = absint( $ctx['product_id'] ?? 0 );
		$role       = $ctx['role'] ?? 'media';
		if ( ! $product_id || ! Util::wc_active() || 'media' === $role ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error( 'rar_product', __( 'Product not found.', 'rar-wp-commerce-studio-ai' ) );
		}
		$meta = array(
			'derivative' => $att,
			'source'     => $source,
			'preset'     => $o,
			'notes'      => $notes,
			'role'       => $role,
		);
		$args = array(
			'job_id' => absint( $ctx['job_id'] ?? 0 ),
			'meta'   => $meta,
			'guard'  => false,
		);
		if ( 'gallery' === $role ) {
			$ids = array_map( 'absint', $product->get_gallery_image_ids( 'edit' ) );
			$pos = array_search( $source, $ids, true );
			// Build on a pending gallery draft so several slots can be replaced in one reviewable change.
			$pending = Drafts::query(
				array(
					'object_type' => 'product',
					'object_id'   => $product_id,
					'status'      => 'pending',
					'per_page'    => 20,
				)
			);
			foreach ( $pending['items'] as $item ) {
				if ( 'gallery' === $item['field'] && false !== $pos ) {
					$prev = array_map( 'absint', explode( ',', $item['proposed'] ) );
					if ( count( $prev ) === count( $ids ) ) {
						$ids                         = $prev;
						$args['meta']['derivatives'] = array_values( array_unique( array_merge( (array) ( $item['meta']['derivatives'] ?? array() ), array( (int) ( $item['meta']['derivative'] ?? 0 ) ) ) ) );
					}
					break;
				}
			}
			if ( false === $pos ) {
				return new \WP_Error( 'rar_image', __( 'That image is no longer in the product gallery.', 'rar-wp-commerce-studio-ai' ) );
			}
			$ids[ $pos ]                 = $att;
			$args['meta']['index']       = $pos;
			$args['meta']['derivatives'] = array_values( array_filter( array_unique( array_merge( (array) ( $args['meta']['derivatives'] ?? array() ), array( $att ) ) ) ) );
			return Drafts::create( 'product', $product_id, 'gallery', implode( ',', $ids ), __( 'Image Studio', 'rar-wp-commerce-studio-ai' ), $args );
		}
		return Drafts::create( 'product', $product_id, 'featured_image', (string) $att, __( 'Image Studio', 'rar-wp-commerce-studio-ai' ), $args );
	}

	/**
	 * Optional AI background scene: the product cut-out (real pixels) is placed on a generated scene.
	 *
	 * @param int    $attachment_id Source.
	 * @param string $scene         Scene description.
	 * @param array  $o             Options.
	 * @param array  $ctx           product_id, role, index.
	 * @return array|\WP_Error
	 */
	public static function scene( $attachment_id, $scene, array $o, array $ctx = array() ) {
		$o              = Processor::sanitize_options( $o );
		$o['remove_bg'] = 1;
		$o['canvas']    = 'transparent';
		$o['format']    = 'png';
		$cut            = Processor::process( get_attached_file( absint( $attachment_id ) ), $o );
		if ( is_wp_error( $cut ) ) {
			return $cut;
		}
		if ( empty( $cut['removed_bg'] ) ) {
			Processor::free( $cut['image'] );
			return new \WP_Error( 'rar_scene_cutout', __( 'A scene needs a clean product cut-out, but the background could not be removed safely from this photo. Use a photo on a plain background.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		$prompt = Prompts::render( 'bg_scene', array( 'scene' => Util::limit( sanitize_text_field( $scene ), 300 ) ) );
		$image  = Gateway::image( $prompt, array( 'object_type' => 'product', 'object_id' => absint( $ctx['product_id'] ?? 0 ) ) );
		if ( is_wp_error( $image ) ) {
			Processor::free( $cut['image'] );
			return $image;
		}
		$out = Processor::composite( $cut['image'], $image['bytes'] );
		Processor::free( $cut['image'] );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$final           = $o;
		$final['format'] = 'jpeg';
		$final['canvas'] = 'white';
		$parent          = absint( $ctx['product_id'] ?? 0 );
		$att             = self::save_attachment( $out, $final, absint( $attachment_id ), $parent, 'scene-' . $o['size'] . 'px' );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		update_post_meta( $att, '_rar_studio_ai_scene', 1 );
		$notes = array_merge( $cut['notes'], array( __( 'Background generated by AI; the product itself is the original photo (no AI changes to product pixels).', 'rar-wp-commerce-studio-ai' ) ) );
		$draft = self::draft_for( $att, absint( $attachment_id ), $ctx, $final, $notes );
		if ( is_wp_error( $draft ) ) {
			wp_delete_attachment( $att, true );
			return $draft;
		}
		return array(
			'draft'      => $draft,
			'derivative' => self::describe( $att ),
			'source'     => self::describe( absint( $attachment_id ) ),
			'notes'      => $notes,
			'usage'      => array(
				'provider' => $image['provider'],
				'cost'     => $image['cost'],
			),
		);
	}

	/**
	 * Delete a Studio-made derivative that is not used anywhere.
	 *
	 * @param int $att Attachment.
	 * @return true|\WP_Error
	 */
	public static function delete_derivative( $att ) {
		$att = absint( $att );
		if ( ! get_post_meta( $att, '_rar_studio_derivative', true ) ) {
			return new \WP_Error( 'rar_image', __( 'Only images created by the Studio can be deleted here.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'delete_post', $att ) ) {
			return new \WP_Error( 'rar_forbidden', __( 'You cannot delete this media item.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 403 ) );
		}
		if ( self::attachment_in_use( $att ) ) {
			return new \WP_Error( 'rar_image_in_use', __( 'This image is in use. Roll back the change first.', 'rar-wp-commerce-studio-ai' ), array( 'status' => 409 ) );
		}
		wp_delete_attachment( $att, true );
		Logger::log( 'image_deleted', sprintf( 'Unused Studio image #%d deleted.', $att ), array(), 'info', 'attachment', $att );
		return true;
	}
}
