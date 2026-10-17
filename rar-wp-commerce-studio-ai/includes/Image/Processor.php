<?php
/**
 * Local image pipeline (PHP GD). Product pixels are only scaled and moved:
 * no repainting, no generative edits. Background removal works only on a
 * uniform backdrop connected to the image edges and refuses otherwise.
 *
 * @package RarStudio
 */

namespace RarStudio\Image;

defined( 'ABSPATH' ) || exit;

/**
 * GD processor.
 */
final class Processor {

	/**
	 * Normalise image options.
	 *
	 * @param array $o Raw options.
	 * @return array
	 */
	public static function sanitize_options( array $o ) {
		$size    = absint( $o['size'] ?? 800 );
		$format  = (string) ( $o['format'] ?? 'jpeg' );
		$format  = in_array( $format, array( 'jpeg', 'webp', 'png' ), true ) ? $format : 'jpeg';
		$canvas  = (string) ( $o['canvas'] ?? 'white' );
		$canvas  = in_array( $canvas, array( 'white', 'transparent', 'color', 'original' ), true ) ? $canvas : 'white';
		$bg      = isset( $o['background'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $o['background'] ) ? strtolower( $o['background'] ) : '#ffffff';
		$out     = array(
			'size'       => max( 100, min( 2400, $size ? $size : 800 ) ),
			'format'     => $format,
			'quality'    => max( 40, min( 100, absint( $o['quality'] ?? 86 ) ) ),
			'padding'    => max( 0, min( 30, absint( $o['padding'] ?? 6 ) ) ),
			'canvas'     => $canvas,
			'background' => $bg,
			'remove_bg'  => empty( $o['remove_bg'] ) ? 0 : 1,
			'tolerance'  => max( 4, min( 60, absint( $o['tolerance'] ?? 18 ) ) ),
			'trim'       => empty( $o['trim'] ) ? 0 : 1,
			'sharpen'    => empty( $o['sharpen'] ) ? 0 : 1,
			'upscale'    => empty( $o['upscale'] ) ? 0 : 1,
		);
		if ( 'transparent' === $out['canvas'] && 'jpeg' === $out['format'] ) {
			$out['format'] = 'png'; // JPEG cannot store transparency.
		}
		if ( 'webp' === $out['format'] && ! self::supports( 'webp' ) ) {
			$out['format'] = 'jpeg';
		}
		return $out;
	}

	/**
	 * Release a GD image (needed only before PHP 8.0; a no-op afterwards and deprecated in 8.5).
	 *
	 * @param \GdImage|resource|null $img Image.
	 * @return void
	 */
	public static function free( $img ) {
		if ( PHP_VERSION_ID < 80000 && is_resource( $img ) ) {
			imagedestroy( $img ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.imagedestroyDeprecated
		}
	}

	/**
	 * Server capabilities.
	 *
	 * @param string $what gd|webp|avif.
	 * @return bool
	 */
	public static function supports( $what ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}
		switch ( $what ) {
			case 'webp':
				return function_exists( 'imagewebp' ) && function_exists( 'imagecreatefromwebp' );
			case 'avif':
				return function_exists( 'imagecreatefromavif' );
		}
		return true;
	}

	/**
	 * Would processing fit into the PHP memory limit?
	 *
	 * @param int $pixels Total pixels held at once.
	 * @return bool
	 */
	public static function memory_ok( $pixels ) {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;
		if ( $limit <= 0 ) {
			return true;
		}
		return ( memory_get_usage( true ) + $pixels * 6 + 8 * 1024 * 1024 ) < $limit * 0.9;
	}

	/**
	 * Load an image file into a truecolor GD image with alpha preserved.
	 *
	 * @param string $file Path.
	 * @return array|\WP_Error [image, width, height]
	 */
	public static function load( $file ) {
		if ( ! self::supports( 'gd' ) ) {
			return new \WP_Error( 'rar_image_gd', __( 'The PHP GD extension is required for the Image Studio.', 'rar-wp-commerce-studio-ai' ) );
		}
		if ( ! $file || ! is_readable( $file ) ) {
			return new \WP_Error( 'rar_image_file', __( 'Image file is missing on the server.', 'rar-wp-commerce-studio-ai' ) );
		}
		if ( filesize( $file ) > 25 * 1024 * 1024 ) {
			return new \WP_Error( 'rar_image_large', __( 'Image file is larger than 25 MB.', 'rar-wp-commerce-studio-ai' ) );
		}
		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || $info[0] < 1 || $info[1] < 1 ) {
			return new \WP_Error( 'rar_image_type', __( 'Unsupported or damaged image.', 'rar-wp-commerce-studio-ai' ) );
		}
		if ( $info[0] * $info[1] > 40000000 ) {
			return new \WP_Error( 'rar_image_large', __( 'Image is larger than 40 megapixels.', 'rar-wp-commerce-studio-ai' ) );
		}
		if ( ! self::memory_ok( $info[0] * $info[1] * 2 ) ) {
			return new \WP_Error( 'rar_image_memory', __( 'Not enough PHP memory for this image on this server. Use a smaller source image or raise WP_MAX_MEMORY_LIMIT.', 'rar-wp-commerce-studio-ai' ) );
		}
		$src = false;
		// phpcs:disable WordPress.PHP.NoSilencedErrors
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				$src = @imagecreatefromjpeg( $file );
				break;
			case IMAGETYPE_PNG:
				$src = @imagecreatefrompng( $file );
				break;
			case IMAGETYPE_GIF:
				$src = @imagecreatefromgif( $file );
				break;
			case IMAGETYPE_WEBP:
				$src = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $file ) : false;
				break;
			default:
				if ( defined( 'IMAGETYPE_AVIF' ) && IMAGETYPE_AVIF === $info[2] && function_exists( 'imagecreatefromavif' ) ) { // phpcs:ignore PHPCompatibility.Constants.NewConstants.imagetype_avifFound, PHPCompatibility.FunctionUse.NewFunctions.imagecreatefromavifFound
					$src = @imagecreatefromavif( $file ); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.imagecreatefromavifFound
				}
		}
		// phpcs:enable
		if ( ! $src && function_exists( 'wp_get_image_editor' ) ) {
			// Imagick fallback (AVIF/HEIC/TIFF where supported) via a temporary PNG; the original is untouched.
			$editor = wp_get_image_editor( $file );
			if ( ! is_wp_error( $editor ) ) {
				$tmp = wp_tempnam( 'rar-studio' ) . '.png';
				$res = $editor->save( $tmp, 'image/png' );
				if ( ! is_wp_error( $res ) && ! empty( $res['path'] ) ) {
					$src = @imagecreatefrompng( $res['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					wp_delete_file( $res['path'] );
				}
				wp_delete_file( $tmp );
			}
		}
		if ( ! $src ) {
			return new \WP_Error( 'rar_image_type', __( 'This image format cannot be read on this server.', 'rar-wp-commerce-studio-ai' ) );
		}
		if ( ! imageistruecolor( $src ) ) {
			imagepalettetotruecolor( $src );
		}
		imagealphablending( $src, false );
		imagesavealpha( $src, true );
		return array( $src, imagesx( $src ), imagesy( $src ) );
	}

	/**
	 * RGBA components of a pixel.
	 *
	 * @param int $c Color int.
	 * @return int[] r,g,b,a (a: 0 opaque … 127 transparent)
	 */
	private static function rgba( $c ) {
		return array( ( $c >> 16 ) & 0xFF, ( $c >> 8 ) & 0xFF, $c & 0xFF, ( $c >> 24 ) & 0x7F );
	}

	/**
	 * Max channel distance.
	 *
	 * @param int[] $a RGBA.
	 * @param int[] $b RGBA.
	 * @return int
	 */
	private static function dist( array $a, array $b ) {
		return max( abs( $a[0] - $b[0] ), abs( $a[1] - $b[1] ), abs( $a[2] - $b[2] ) );
	}

	/**
	 * Dominant border colour and the share of border pixels close to it.
	 *
	 * @param \GdImage|resource $img Image.
	 * @param int               $tol Tolerance.
	 * @return array{color:int[],share:float,transparent:float}
	 */
	public static function border_profile( $img, $tol ) {
		$w     = imagesx( $img );
		$h     = imagesy( $img );
		$step  = max( 1, (int) floor( ( $w + $h ) / 800 ) );
		$px    = array();
		for ( $x = 0; $x < $w; $x += $step ) {
			$px[] = imagecolorat( $img, $x, 0 );
			$px[] = imagecolorat( $img, $x, $h - 1 );
		}
		for ( $y = 0; $y < $h; $y += $step ) {
			$px[] = imagecolorat( $img, 0, $y );
			$px[] = imagecolorat( $img, $w - 1, $y );
		}
		$hist        = array();
		$transparent = 0;
		foreach ( $px as $c ) {
			$rgba = self::rgba( $c );
			if ( $rgba[3] > 100 ) {
				++$transparent;
				continue;
			}
			$key            = ( $rgba[0] >> 3 ) . ',' . ( $rgba[1] >> 3 ) . ',' . ( $rgba[2] >> 3 );
			$hist[ $key ][] = $rgba;
		}
		$total = max( 1, count( $px ) );
		if ( ! $hist ) {
			return array(
				'color'       => array( 255, 255, 255, 0 ),
				'share'       => 0.0,
				'transparent' => $transparent / $total,
			);
		}
		uasort(
			$hist,
			static function ( $a, $b ) {
				return count( $b ) <=> count( $a );
			}
		);
		$top   = reset( $hist );
		$avg   = array( 0, 0, 0, 0 );
		foreach ( $top as $c ) {
			$avg[0] += $c[0];
			$avg[1] += $c[1];
			$avg[2] += $c[2];
		}
		$n     = count( $top );
		$color = array( (int) round( $avg[0] / $n ), (int) round( $avg[1] / $n ), (int) round( $avg[2] / $n ), 0 );
		$close = 0;
		foreach ( $px as $c ) {
			$rgba = self::rgba( $c );
			if ( $rgba[3] <= 100 && self::dist( $rgba, $color ) <= $tol ) {
				++$close;
			}
		}
		return array(
			'color'       => $color,
			'share'       => $close / $total,
			'transparent' => $transparent / $total,
		);
	}

	/**
	 * Bounding box of the subject (pixels different from the border colour or opaque).
	 *
	 * @param \GdImage|resource $img Image.
	 * @param int               $tol Tolerance.
	 * @return int[]|null x, y, w, h
	 */
	public static function subject_box( $img, $tol ) {
		$w       = imagesx( $img );
		$h       = imagesy( $img );
		$profile = self::border_profile( $img, $tol );
		$alpha   = $profile['transparent'] > 0.5;
		if ( ! $alpha && $profile['share'] < 0.6 ) {
			return null; // Border is not uniform — nothing safe to trim.
		}
		$bg    = $profile['color'];
		$step  = max( 1, (int) floor( max( $w, $h ) / 1000 ) );
		$is_fg = static function ( $c ) use ( $bg, $tol, $alpha ) {
			$a = ( $c >> 24 ) & 0x7F;
			if ( $alpha ) {
				return $a < 110;
			}
			if ( $a > 100 ) {
				return false;
			}
			return max( abs( ( ( $c >> 16 ) & 0xFF ) - $bg[0] ), abs( ( ( $c >> 8 ) & 0xFF ) - $bg[1] ), abs( ( $c & 0xFF ) - $bg[2] ) ) > $tol;
		};
		$top = null;
		for ( $y = 0; $y < $h && null === $top; $y++ ) {
			for ( $x = 0; $x < $w; $x += $step ) {
				if ( $is_fg( imagecolorat( $img, $x, $y ) ) ) {
					$top = $y;
					break;
				}
			}
		}
		if ( null === $top ) {
			return null;
		}
		$bottom = $top;
		for ( $y = $h - 1; $y > $top; $y-- ) {
			for ( $x = 0; $x < $w; $x += $step ) {
				if ( $is_fg( imagecolorat( $img, $x, $y ) ) ) {
					$bottom = $y;
					break 2;
				}
			}
		}
		$left = null;
		for ( $x = 0; $x < $w && null === $left; $x++ ) {
			for ( $y = $top; $y <= $bottom; $y += $step ) {
				if ( $is_fg( imagecolorat( $img, $x, $y ) ) ) {
					$left = $x;
					break;
				}
			}
		}
		$right = (int) $left;
		for ( $x = $w - 1; $x > (int) $left; $x-- ) {
			for ( $y = $top; $y <= $bottom; $y += $step ) {
				if ( $is_fg( imagecolorat( $img, $x, $y ) ) ) {
					$right = $x;
					break 2;
				}
			}
		}
		$bw = $right - (int) $left + 1;
		$bh = $bottom - $top + 1;
		if ( $bw * $bh < 0.01 * $w * $h ) {
			return null;
		}
		// Keep a 1% safety margin so soft edges and shadows are never cut.
		$m  = (int) ceil( 0.01 * max( $w, $h ) );
		$x0 = max( 0, (int) $left - $m );
		$y0 = max( 0, $top - $m );
		return array( $x0, $y0, min( $w, $right + $m + 1 ) - $x0, min( $h, $bottom + $m + 1 ) - $y0 );
	}

	/**
	 * Remove a uniform, edge-connected background (flood fill). Returns notes.
	 *
	 * @param \GdImage|resource $img Image (truecolor, alpha off for blending).
	 * @param int               $tol Tolerance.
	 * @return array{removed:bool,share:float,note:string}
	 */
	public static function remove_background( $img, $tol ) {
		$w       = imagesx( $img );
		$h       = imagesy( $img );
		$n       = $w * $h;
		$profile = self::border_profile( $img, $tol );
		if ( $profile['transparent'] > 0.5 ) {
			return array(
				'removed' => false,
				'share'   => 0.0,
				'note'    => __( 'Background is already transparent.', 'rar-wp-commerce-studio-ai' ),
			);
		}
		if ( $profile['share'] < 0.6 ) {
			return array(
				'removed' => false,
				'share'   => 0.0,
				'note'    => __( 'Background removal skipped: the backdrop is not a plain colour, so automatic removal could damage the product. Use a photo on a plain background.', 'rar-wp-commerce-studio-ai' ),
			);
		}
		if ( ! self::memory_ok( $n * 4 ) ) {
			return array(
				'removed' => false,
				'share'   => 0.0,
				'note'    => __( 'Background removal skipped: not enough PHP memory.', 'rar-wp-commerce-studio-ai' ),
			);
		}
		$bg      = $profile['color'];
		$visited = str_repeat( "\0", $n );
		$queue   = new \SplFixedArray( $n );
		$head    = 0;
		$tail    = 0;
		$push    = static function ( $x, $y ) use ( &$visited, $queue, &$tail, $w, $img, $bg, $tol ) {
			$i = $y * $w + $x;
			if ( "\0" !== $visited[ $i ] ) {
				return;
			}
			$c = imagecolorat( $img, $x, $y );
			if ( max( abs( ( ( $c >> 16 ) & 0xFF ) - $bg[0] ), abs( ( ( $c >> 8 ) & 0xFF ) - $bg[1] ), abs( ( $c & 0xFF ) - $bg[2] ) ) > $tol ) {
				$visited[ $i ] = "\2"; // Checked, foreground.
				return;
			}
			$visited[ $i ]    = "\1"; // Background.
			$queue[ $tail++ ] = $i;
		};
		for ( $x = 0; $x < $w; $x++ ) {
			$push( $x, 0 );
			$push( $x, $h - 1 );
		}
		for ( $y = 0; $y < $h; $y++ ) {
			$push( 0, $y );
			$push( $w - 1, $y );
		}
		while ( $head < $tail ) {
			$i = $queue[ $head++ ];
			$x = $i % $w;
			$y = intdiv( $i, $w );
			if ( $x > 0 ) {
				$push( $x - 1, $y );
			}
			if ( $x < $w - 1 ) {
				$push( $x + 1, $y );
			}
			if ( $y > 0 ) {
				$push( $x, $y - 1 );
			}
			if ( $y < $h - 1 ) {
				$push( $x, $y + 1 );
			}
		}
		$share = $tail / max( 1, $n );
		if ( $share < 0.01 || $share > 0.97 ) {
			return array(
				'removed' => false,
				'share'   => $share,
				'note'    => __( 'Background removal skipped: the product could not be separated safely from the backdrop.', 'rar-wp-commerce-studio-ai' ),
			);
		}
		imagealphablending( $img, false );
		$clear = imagecolorallocatealpha( $img, $bg[0], $bg[1], $bg[2], 127 );
		for ( $k = 0; $k < $tail; $k++ ) {
			$i = $queue[ $k ];
			imagesetpixel( $img, $i % $w, intdiv( $i, $w ), $clear );
		}
		// Soften the 1-pixel rim next to removed background (anti-aliasing); product pixels keep their colour.
		for ( $y = 1; $y < $h - 1; $y++ ) {
			$row = $y * $w;
			for ( $x = 1; $x < $w - 1; $x++ ) {
				$i = $row + $x;
				if ( "\1" === $visited[ $i ] ) {
					continue;
				}
				if ( "\1" !== $visited[ $i - 1 ] && "\1" !== $visited[ $i + 1 ] && "\1" !== $visited[ $i - $w ] && "\1" !== $visited[ $i + $w ] ) {
					continue;
				}
				$c = self::rgba( imagecolorat( $img, $x, $y ) );
				$d = self::dist( $c, $bg );
				if ( $d < 2 * $tol ) {
					$alpha = (int) max( 0, min( 110, round( 127 * ( 1 - ( $d - $tol ) / max( 1, $tol ) ) ) ) );
					imagesetpixel( $img, $x, $y, imagecolorallocatealpha( $img, $c[0], $c[1], $c[2], $alpha ) );
				}
			}
		}
		return array(
			'removed' => true,
			'share'   => $share,
			'note'    => sprintf( /* translators: %d: percent */ __( 'Plain background removed (%d%% of the image).', 'rar-wp-commerce-studio-ai' ), (int) round( 100 * $share ) ),
		);
	}

	/**
	 * Full pipeline: trim → fit → optional background removal → canvas → sharpen.
	 *
	 * @param string $file Source path.
	 * @param array  $o    Options (sanitised).
	 * @return array|\WP_Error image, width, height, notes[], removed_bg
	 */
	public static function process( $file, array $o ) {
		$o      = self::sanitize_options( $o );
		$loaded = self::load( $file );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $src, $sw, $sh ) = $loaded;
		$notes                 = array();

		$box = array( 0, 0, $sw, $sh );
		if ( $o['trim'] ) {
			$found = self::subject_box( $src, $o['tolerance'] );
			if ( $found && ( $found[2] < $sw || $found[3] < $sh ) ) {
				$box     = $found;
				$notes[] = __( 'Empty borders trimmed.', 'rar-wp-commerce-studio-ai' );
			}
		}

		$size  = $o['size'];
		$inner = max( 1, (int) round( $size * ( 1 - 2 * $o['padding'] / 100 ) ) );
		$scale = min( $inner / $box[2], $inner / $box[3] );
		if ( ! $o['upscale'] && $scale > 1 ) {
			$scale   = 1;
			$notes[] = __( 'Small source kept at its own size (upscaling is off).', 'rar-wp-commerce-studio-ai' );
		} elseif ( $scale > 1 ) {
			$notes[] = __( 'Upscaled by resampling — this does not add real detail.', 'rar-wp-commerce-studio-ai' );
		}
		$tw = max( 1, (int) round( $box[2] * $scale ) );
		$th = max( 1, (int) round( $box[3] * $scale ) );
		if ( ! self::memory_ok( $tw * $th * 2 + $size * $size ) ) {
			self::free( $src );
			return new \WP_Error( 'rar_image_memory', __( 'Not enough PHP memory for this output size on this server.', 'rar-wp-commerce-studio-ai' ) );
		}
		$subject = imagecreatetruecolor( $tw, $th );
		imagealphablending( $subject, false );
		imagesavealpha( $subject, true );
		imagefill( $subject, 0, 0, imagecolorallocatealpha( $subject, 0, 0, 0, 127 ) );
		imagecopyresampled( $subject, $src, 0, 0, $box[0], $box[1], $tw, $th, $box[2], $box[3] );
		self::free( $src );

		$removed = false;
		if ( $o['remove_bg'] ) {
			$res     = self::remove_background( $subject, $o['tolerance'] );
			$removed = $res['removed'];
			$notes[] = $res['note'];
		}

		$canvas = imagecreatetruecolor( $size, $size );
		imagesavealpha( $canvas, true );
		if ( 'transparent' === $o['canvas'] ) {
			imagealphablending( $canvas, false );
			imagefill( $canvas, 0, 0, imagecolorallocatealpha( $canvas, 0, 0, 0, 127 ) );
		} else {
			$hex = 'white' === $o['canvas'] ? '#ffffff' : $o['background'];
			if ( 'original' === $o['canvas'] ) {
				$profile = self::border_profile( $subject, $o['tolerance'] );
				$hex     = sprintf( '#%02x%02x%02x', $profile['color'][0], $profile['color'][1], $profile['color'][2] );
			}
			imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, hexdec( substr( $hex, 1, 2 ) ), hexdec( substr( $hex, 3, 2 ) ), hexdec( substr( $hex, 5, 2 ) ) ) );
		}
		imagealphablending( $canvas, 'transparent' !== $o['canvas'] );
		imagecopy( $canvas, $subject, (int) floor( ( $size - $tw ) / 2 ), (int) floor( ( $size - $th ) / 2 ), 0, 0, $tw, $th );
		self::free( $subject );
		imagealphablending( $canvas, false );

		if ( $o['sharpen'] && $scale < 1 ) {
			imageconvolution( $canvas, array( array( 0, -1, 0 ), array( -1, 12, -1 ), array( 0, -1, 0 ) ), 8, 0 );
			$notes[] = __( 'Light sharpening applied after downscaling.', 'rar-wp-commerce-studio-ai' );
		}
		return array(
			'image'      => $canvas,
			'width'      => $size,
			'height'     => $size,
			'notes'      => $notes,
			'removed_bg' => $removed,
			'subject'    => array( $tw, $th ),
			'options'    => $o,
		);
	}

	/**
	 * Encode a GD image to a file.
	 *
	 * @param \GdImage|resource $img     Image.
	 * @param string            $format  jpeg|webp|png.
	 * @param int               $quality 40–100.
	 * @param string            $path    Destination.
	 * @return bool
	 */
	public static function encode( $img, $format, $quality, $path ) {
		imagesavealpha( $img, true );
		switch ( $format ) {
			case 'webp':
				return self::supports( 'webp' ) && imagewebp( $img, $path, (int) $quality );
			case 'png':
				return imagepng( $img, $path, (int) max( 6, min( 9, round( 9 - ( $quality - 40 ) / 20 ) ) ) );
		}
		imageinterlace( $img, true );
		return imagejpeg( $img, $path, (int) $quality );
	}

	/**
	 * Small JPEG of an attachment as base64 for AI vision requests.
	 *
	 * @param int $attachment_id Attachment.
	 * @param int $max           Longest side.
	 * @return array|\WP_Error mime, data
	 */
	public static function thumbnail_base64( $attachment_id, $max = 512 ) {
		$loaded = self::load( get_attached_file( (int) $attachment_id ) );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $src, $w, $h ) = $loaded;
		$scale               = min( 1, $max / max( $w, $h ) );
		$tw                  = max( 1, (int) round( $w * $scale ) );
		$th                  = max( 1, (int) round( $h * $scale ) );
		$dst                 = imagecreatetruecolor( $tw, $th );
		imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
		imagealphablending( $dst, true );
		imagecopyresampled( $dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h );
		self::free( $src );
		ob_start();
		imagejpeg( $dst, null, 82 );
		$bytes = (string) ob_get_clean();
		self::free( $dst );
		return array(
			'mime' => 'image/jpeg',
			'data' => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	/**
	 * Place a transparent product cut-out on a generated scene (cover-fit, centred).
	 *
	 * @param \GdImage|resource $cutout      Square image with transparent background.
	 * @param string            $scene_bytes Scene image bytes.
	 * @return \GdImage|resource|\WP_Error
	 */
	public static function composite( $cutout, $scene_bytes ) {
		$scene = @imagecreatefromstring( $scene_bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $scene ) {
			return new \WP_Error( 'rar_image_scene', __( 'The generated scene image could not be read.', 'rar-wp-commerce-studio-ai' ) );
		}
		$size = imagesx( $cutout );
		$sw   = imagesx( $scene );
		$sh   = imagesy( $scene );
		$crop = min( $sw, $sh );
		$out  = imagecreatetruecolor( $size, $size );
		imagecopyresampled( $out, $scene, 0, 0, (int) ( ( $sw - $crop ) / 2 ), (int) ( ( $sh - $crop ) / 2 ), $size, $size, $crop, $crop );
		self::free( $scene );
		imagealphablending( $out, true );
		imagecopy( $out, $cutout, 0, 0, 0, 0, $size, $size );
		return $out;
	}
}
