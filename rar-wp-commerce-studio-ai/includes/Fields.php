<?php
/**
 * Field registry: read and write every value the Studio can change.
 * Prices, stock, SKU, variations, shipping and orders are never writable here.
 *
 * @package RarStudio
 */

namespace RarStudio;

use RarStudio\Seo\Manager as Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Object field access.
 */
final class Fields {

	/**
	 * Field definitions per object type.
	 *
	 * @param string $type product|post|page|attachment.
	 * @return array field => [label, kind, group]
	 */
	public static function definitions( $type ) {
		$seo = array(
			'seo_title'       => array( 'label' => __( 'SEO title', 'rar-wp-commerce-studio-ai' ), 'kind' => 'text', 'group' => 'seo' ),
			'seo_description' => array( 'label' => __( 'Meta description', 'rar-wp-commerce-studio-ai' ), 'kind' => 'text', 'group' => 'seo' ),
			'seo_focus'       => array( 'label' => __( 'Focus keyphrase', 'rar-wp-commerce-studio-ai' ), 'kind' => 'text', 'group' => 'seo' ),
		);
		switch ( $type ) {
			case 'product':
				$fields = array(
					'title'             => array( 'label' => __( 'Product title', 'rar-wp-commerce-studio-ai' ), 'kind' => 'text', 'group' => 'content' ),
					'short_description' => array( 'label' => __( 'Short description', 'rar-wp-commerce-studio-ai' ), 'kind' => 'html', 'group' => 'content' ),
					'description'       => array( 'label' => __( 'Long description', 'rar-wp-commerce-studio-ai' ), 'kind' => 'html', 'group' => 'content' ),
					'tags'              => array( 'label' => __( 'Tags', 'rar-wp-commerce-studio-ai' ), 'kind' => 'terms', 'group' => 'catalog' ),
					'categories'        => array( 'label' => __( 'Categories', 'rar-wp-commerce-studio-ai' ), 'kind' => 'terms', 'group' => 'catalog' ),
				);
				if ( taxonomy_exists( 'product_brand' ) ) {
					$fields['brand'] = array( 'label' => __( 'Brand', 'rar-wp-commerce-studio-ai' ), 'kind' => 'terms', 'group' => 'catalog' );
				}
				$fields                   = array_merge( $fields, $seo );
				$fields['featured_image'] = array( 'label' => __( 'Featured image', 'rar-wp-commerce-studio-ai' ), 'kind' => 'image', 'group' => 'image' );
				$fields['gallery']        = array( 'label' => __( 'Gallery', 'rar-wp-commerce-studio-ai' ), 'kind' => 'gallery', 'group' => 'image' );
				return $fields;
			case 'post':
			case 'page':
				return array_merge(
					array(
						'title'   => array( 'label' => __( 'Title', 'rar-wp-commerce-studio-ai' ), 'kind' => 'text', 'group' => 'content' ),
						'excerpt' => array( 'label' => __( 'Excerpt', 'rar-wp-commerce-studio-ai' ), 'kind' => 'text', 'group' => 'content' ),
						'content' => array( 'label' => __( 'Content', 'rar-wp-commerce-studio-ai' ), 'kind' => 'html', 'group' => 'content' ),
					),
					$seo
				);
			case 'attachment':
				return array(
					'alt'     => array( 'label' => __( 'Alt text', 'rar-wp-commerce-studio-ai' ), 'kind' => 'text', 'group' => 'media' ),
					'title'   => array( 'label' => __( 'Media title', 'rar-wp-commerce-studio-ai' ), 'kind' => 'text', 'group' => 'media' ),
					'caption' => array( 'label' => __( 'Caption', 'rar-wp-commerce-studio-ai' ), 'kind' => 'text', 'group' => 'media' ),
				);
		}
		return array();
	}

	/**
	 * Is the field known for this object type?
	 *
	 * @param string $type  Type.
	 * @param string $field Field.
	 * @return bool
	 */
	public static function exists( $type, $field ) {
		return array_key_exists( $field, self::definitions( $type ) );
	}

	/**
	 * Built with Elementor? Then post_content is not what visitors see.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function is_elementor( $id ) {
		return 'builder' === get_post_meta( (int) $id, '_elementor_edit_mode', true );
	}

	/**
	 * Why a field cannot be written ('' when writable).
	 *
	 * @param string $type  Type.
	 * @param int    $id    ID.
	 * @param string $field Field.
	 * @return string
	 */
	public static function locked_reason( $type, $id, $field ) {
		if ( in_array( $field, array( 'content', 'description' ), true ) && self::is_elementor( $id ) ) {
			return __( 'This content is built with Elementor. Edit it in Elementor; the Studio only analyses it.', 'rar-wp-commerce-studio-ai' );
		}
		if ( 0 === strpos( $field, 'seo_' ) && '' === Seo::active() ) {
			return __( 'No SEO plugin is active or selected (Settings → SEO).', 'rar-wp-commerce-studio-ai' );
		}
		return '';
	}

	/**
	 * Load a WooCommerce product.
	 *
	 * @param int $id ID.
	 * @return \WC_Product|null
	 */
	private static function product( $id ) {
		if ( ! Util::wc_active() ) {
			return null;
		}
		$product = wc_get_product( (int) $id );
		return $product ? $product : null;
	}

	/**
	 * Term names for an object as a comma-separated string.
	 *
	 * @param int    $id       ID.
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private static function term_names( $id, $taxonomy ) {
		$terms = get_the_terms( (int) $id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return '';
		}
		$names = wp_list_pluck( $terms, 'name' );
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
		return implode( ', ', $names );
	}

	/**
	 * Split a comma list into unique trimmed names.
	 *
	 * @param string $value Comma list.
	 * @return string[]
	 */
	public static function split_names( $value ) {
		$out = array();
		foreach ( preg_split( '/[,،]+/u', (string) $value ) as $name ) {
			$name = trim( sanitize_text_field( $name ) );
			if ( '' !== $name && ! in_array( Util::lower( $name ), array_map( array( Util::class, 'lower' ), $out ), true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Read a field value as a string.
	 *
	 * @param string $type  Type.
	 * @param int    $id    ID.
	 * @param string $field Field.
	 * @return string|\WP_Error
	 */
	public static function get( $type, $id, $field ) {
		$id = absint( $id );
		if ( ! self::exists( $type, $field ) ) {
			return new \WP_Error( 'rar_field', __( 'Unknown field.', 'rar-wp-commerce-studio-ai' ) );
		}
		if ( 0 === strpos( $field, 'seo_' ) ) {
			return Seo::get( $id, substr( $field, 4 ) );
		}
		if ( 'product' === $type ) {
			$product = self::product( $id );
			if ( ! $product ) {
				return new \WP_Error( 'rar_product', __( 'Product not found.', 'rar-wp-commerce-studio-ai' ) );
			}
			switch ( $field ) {
				case 'title':
					return (string) $product->get_name( 'edit' );
				case 'short_description':
					return (string) $product->get_short_description( 'edit' );
				case 'description':
					return (string) $product->get_description( 'edit' );
				case 'tags':
					return self::term_names( $id, 'product_tag' );
				case 'categories':
					return self::term_names( $id, 'product_cat' );
				case 'brand':
					return self::term_names( $id, 'product_brand' );
				case 'featured_image':
					return (string) absint( $product->get_image_id( 'edit' ) );
				case 'gallery':
					return implode( ',', array_map( 'absint', $product->get_gallery_image_ids( 'edit' ) ) );
			}
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return new \WP_Error( 'rar_post', __( 'Item not found.', 'rar-wp-commerce-studio-ai' ) );
		}
		switch ( $field ) {
			case 'title':
				return (string) $post->post_title;
			case 'excerpt':
			case 'caption':
				return (string) $post->post_excerpt;
			case 'content':
				return (string) $post->post_content;
			case 'alt':
				return (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		}
		return '';
	}

	/**
	 * Write a field value. Only the targeted field is changed.
	 *
	 * @param string $type  Type.
	 * @param int    $id    ID.
	 * @param string $field Field.
	 * @param string $value Value.
	 * @return true|\WP_Error
	 */
	public static function set( $type, $id, $field, $value ) {
		$id    = absint( $id );
		$value = (string) $value;
		if ( ! self::exists( $type, $field ) ) {
			return new \WP_Error( 'rar_field', __( 'Unknown field.', 'rar-wp-commerce-studio-ai' ) );
		}
		$locked = self::locked_reason( $type, $id, $field );
		if ( $locked ) {
			return new \WP_Error( 'rar_locked', $locked );
		}
		if ( 0 === strpos( $field, 'seo_' ) ) {
			return Seo::set( $id, substr( $field, 4 ), $value );
		}

		if ( 'product' === $type ) {
			$product = self::product( $id );
			if ( ! $product ) {
				return new \WP_Error( 'rar_product', __( 'Product not found.', 'rar-wp-commerce-studio-ai' ) );
			}
			switch ( $field ) {
				case 'title':
					$title = trim( sanitize_text_field( $value ) );
					if ( '' === $title ) {
						return new \WP_Error( 'rar_empty', __( 'A product title cannot be empty.', 'rar-wp-commerce-studio-ai' ) );
					}
					$product->set_name( $title );
					break;
				case 'short_description':
					$product->set_short_description( wp_kses_post( $value ) );
					break;
				case 'description':
					$product->set_description( wp_kses_post( $value ) );
					break;
				case 'tags':
					$names = self::split_names( $value );
					foreach ( $names as $name ) {
						if ( ! term_exists( $name, 'product_tag' ) && ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_categories' ) ) {
							return new \WP_Error( 'rar_terms', __( 'You are not allowed to create new product tags.', 'rar-wp-commerce-studio-ai' ) );
						}
					}
					$result = wp_set_object_terms( $id, $names, 'product_tag', false );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					wc_delete_product_transients( $id );
					return true;
				case 'categories':
				case 'brand':
					$taxonomy = 'categories' === $field ? 'product_cat' : 'product_brand';
					$ids      = self::resolve_existing_terms( $value, $taxonomy );
					if ( is_wp_error( $ids ) ) {
						return $ids;
					}
					if ( 'product_cat' === $taxonomy ) {
						if ( ! $ids ) {
							$default = (int) get_option( 'default_product_cat', 0 );
							$ids     = $default ? array( $default ) : array();
						}
						$product->set_category_ids( $ids );
						break;
					}
					$result = wp_set_object_terms( $id, $ids, $taxonomy, false );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					wc_delete_product_transients( $id );
					return true;
				case 'featured_image':
					$attachment = absint( $value );
					if ( $attachment && ! wp_attachment_is_image( $attachment ) ) {
						return new \WP_Error( 'rar_image', __( 'The image no longer exists in the Media Library.', 'rar-wp-commerce-studio-ai' ) );
					}
					$product->set_image_id( $attachment );
					break;
				case 'gallery':
					$ids = array_values( array_filter( array_map( 'absint', explode( ',', $value ) ) ) );
					foreach ( $ids as $attachment ) {
						if ( ! wp_attachment_is_image( $attachment ) ) {
							return new \WP_Error( 'rar_image', __( 'A gallery image no longer exists in the Media Library.', 'rar-wp-commerce-studio-ai' ) );
						}
					}
					$product->set_gallery_image_ids( $ids );
					break;
				default:
					return new \WP_Error( 'rar_field', __( 'Field cannot be written.', 'rar-wp-commerce-studio-ai' ) );
			}
			$product->save();
			return true;
		}

		$post = get_post( $id );
		if ( ! $post ) {
			return new \WP_Error( 'rar_post', __( 'Item not found.', 'rar-wp-commerce-studio-ai' ) );
		}
		$update = array( 'ID' => $id );
		switch ( $field ) {
			case 'title':
				$update['post_title'] = sanitize_text_field( $value );
				break;
			case 'excerpt':
			case 'caption':
				$update['post_excerpt'] = 'caption' === $field ? sanitize_text_field( $value ) : sanitize_textarea_field( $value );
				break;
			case 'content':
				$update['post_content'] = wp_kses_post( $value );
				break;
			case 'alt':
				$alt = sanitize_text_field( $value );
				if ( '' === $alt ) {
					delete_post_meta( $id, '_wp_attachment_image_alt' );
				} else {
					update_post_meta( $id, '_wp_attachment_image_alt', $alt );
				}
				return true;
		}
		$result = wp_update_post( wp_slash( $update ), true );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Resolve names to EXISTING term IDs (the Studio never invents categories or brands).
	 *
	 * @param string $value    Comma list of names.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]|\WP_Error
	 */
	public static function resolve_existing_terms( $value, $taxonomy ) {
		$ids = array();
		foreach ( self::split_names( $value ) as $name ) {
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
			}
			if ( ! $term ) {
				/* translators: %s: term name */
				return new \WP_Error( 'rar_term_missing', sprintf( __( '"%s" does not exist. Create it in WooCommerce first; the Studio only assigns existing categories and brands.', 'rar-wp-commerce-studio-ai' ), $name ) );
			}
			$ids[] = (int) $term->term_id;
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Source facts used by generators, prompts and the fact guard.
	 *
	 * @param string $type Type.
	 * @param int    $id   ID.
	 * @return array
	 */
	public static function facts( $type, $id ) {
		$id    = absint( $id );
		$facts = array(
			'type'  => $type,
			'id'    => $id,
			'site'  => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
			'brand' => '',
		);
		if ( 'product' === $type ) {
			$product = self::product( $id );
			if ( ! $product ) {
				return $facts;
			}
			$attributes = array();
			foreach ( $product->get_attributes() as $attribute ) {
				if ( ! is_object( $attribute ) ) {
					continue;
				}
				$label = wc_attribute_label( $attribute->get_name() );
				if ( $attribute->is_taxonomy() ) {
					$values = wc_get_product_terms( $id, $attribute->get_name(), array( 'fields' => 'names' ) );
				} else {
					$values = $attribute->get_options();
				}
				$values = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $values ) ) ) );
				if ( $values ) {
					$attributes[ $label ] = implode( ', ', $values );
				}
			}
			$weight = $product->get_weight();
			$dims   = array_filter( array( $product->get_length(), $product->get_width(), $product->get_height() ) );
			$facts  = array_merge(
				$facts,
				array(
					'name'              => (string) $product->get_name( 'edit' ),
					'short_description' => Util::plain( $product->get_short_description( 'edit' ) ),
					'description'       => Util::plain( $product->get_description( 'edit' ) ),
					'description_html'  => (string) $product->get_description( 'edit' ),
					'sku'               => (string) $product->get_sku( 'edit' ),
					'product_type'      => (string) $product->get_type(),
					'attributes'        => $attributes,
					'weight'            => $weight ? $weight . ' ' . get_option( 'woocommerce_weight_unit' ) : '',
					'dimensions'        => count( $dims ) === 3 ? implode( ' × ', $dims ) . ' ' . get_option( 'woocommerce_dimension_unit' ) : '',
					'categories'        => self::term_names( $id, 'product_cat' ),
					'tags'              => self::term_names( $id, 'product_tag' ),
					'brand'             => taxonomy_exists( 'product_brand' ) ? self::term_names( $id, 'product_brand' ) : '',
					'permalink'         => get_permalink( $id ),
				)
			);
			if ( '' === $facts['brand'] ) {
				foreach ( $attributes as $label => $value ) {
					if ( preg_match( '/^(brand|ব্র্যান্ড)$/iu', $label ) ) {
						$facts['brand'] = $value;
					}
				}
			}
			return $facts;
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return $facts;
		}
		$content = (string) $post->post_content;
		if ( self::is_elementor( $id ) ) {
			$content = Content\Analyzer::elementor_html( $id );
		}
		return array_merge(
			$facts,
			array(
				'name'             => (string) $post->post_title,
				'description'      => Util::plain( $content ),
				'description_html' => $content,
				'excerpt'          => (string) $post->post_excerpt,
				'permalink'        => get_permalink( $id ),
				'mime'             => (string) $post->post_mime_type,
			)
		);
	}

	/**
	 * Source text for the fact guard: everything the store owner already wrote.
	 *
	 * @param array $facts Facts.
	 * @return string
	 */
	public static function source_text( array $facts ) {
		$parts = array();
		foreach ( array( 'name', 'short_description', 'description', 'excerpt', 'sku', 'weight', 'dimensions', 'categories', 'tags', 'brand', 'site' ) as $k ) {
			if ( ! empty( $facts[ $k ] ) ) {
				$parts[] = (string) $facts[ $k ];
			}
		}
		if ( ! empty( $facts['attributes'] ) && is_array( $facts['attributes'] ) ) {
			foreach ( $facts['attributes'] as $label => $value ) {
				$parts[] = $label . ': ' . $value;
			}
		}
		$parts[] = (string) Settings::get( 'brand.name' );
		return implode( "\n", $parts );
	}
}
