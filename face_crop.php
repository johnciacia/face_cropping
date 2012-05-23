<?php
/*
Plugin Name: Face Crop
Plugin URI: http://www.voceconnect.com
Description: Crops an image preserving faces
Author: John Ciacia
Version: 0.1-alpha
Author URI: http://wordpress.org/extend/plugins/health-check/

Copyright 2012  John Ciacia  (email : john@voceconnect.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

require_once( ABSPATH . 'wp-admin/includes/image-edit.php' );


// add_filter( 'wp_generate_attachment_metadata', 'crop_faces', 10, 2 );

/**
 * @todo: add error checking
 */
function crop_faces( $attach_data, $attach_id ) {
	global $_wp_additional_image_sizes; 
	ini_set( 'memory_limit', '512M' );

	$upload_dir = wp_upload_dir();
	$path = $upload_dir['basedir'];

	// Scan the image for faces and generate a set of bounds
	$bounds = face_detect( $path . DIRECTORY_SEPARATOR . $attach_data['file'] );

	// If no faces were detected, don't do anything
	if( 0 == count( $bounds) ) return;

	// Get a box bounding all the faces
	$bounding_box = bounding_box( $bounds );

	// Transpose the origin so it is centered around the faces
	$origin = transpose_origin( $bounding_box );

	$dir = $path . DIRECTORY_SEPARATOR . dirname( $attach_data['file']) . DIRECTORY_SEPARATOR;
	foreach( $attach_data['sizes'] as $s => $size ) {

		// Only do a face crop if the image size is set to hard crop
		if ( isset( $_wp_additional_image_sizes[$s]['crop'] ) )
			$crop = intval( $_wp_additional_image_sizes[$s]['crop'] );
		else
			$crop = get_option( "{$s}_crop" );

		if( 1 != $crop ) continue;

		// Normalize the points to get the upper left corner
		list( $x, $y ) = normalize_points( $origin, $size );

		// Create image resource
		$src = wp_load_image( $path . DIRECTORY_SEPARATOR . $attach_data['file'] );

		// Crop the image
		$src = _crop_image_resource( $src, $x, $y, $size['width'], $size['height'] );

		// Get the MIME type
		$mime_type = get_post_mime_type( $attach_id );

		// Save the image
		wp_save_image_file( $dir . $size['file'], $src, $mime_type, 0 );

		imagedestroy( $src );
	}

	return $attach_data;

}

/**
 *
 */
function face_detect( $src ) {
	require_once("FaceDetector.php");
	$detector = new FaceDetector();
	$detector->scan( $src );
	return $detector->getFaces();
}

/**
 *
 */
function transpose_origin( $bounds ) {
	return array( 
			'x' => round( $bounds['x'] + ( $bounds['width'] / 2 ) ),
			'y' => round( $bounds['y'] + ( $bounds['height'] / 2 ) )
		);
}

/**
 *
 */
function normalize_points( $origin, $size ) {
	$x = $origin['x'] - ( $size['width'] / 2 );
	$y = $origin['y'] - ( $size['height'] / 2 );
	$x = $x < 0 ? 0 : $x;
	$y = $y < 0 ? 0 : $y;
	return array( $x, $y );
}

/**
 *
 */
function bounding_box( $bounds ) {

	if( 1 == count( $bounds ) ) {
		return array( 
			'x' => $bounds[0]['x'],
			'y' => $bounds[0]['y'],
			'height' => $bounds[0]['height'],
			'width' => $bounds[0]['width']);
	}


	$xs = array();
	$ys = array();

	foreach( $bounds as $bound ) {
		$xs[] = $bound['x'] < 0 ? 0 : $bound['x'];
		$ys[] = $bound['y'] < 0 ? 0 : $bound['y'];
	}

	return array( 
		'x' => min($xs), 
		'y' => max($ys), 
		'height' => max($ys) - min($ys), 
		'width' => max($xs) - min($xs) );
}

/** 
 * Add crop buttons to the media.php screen
 */
add_filter( 'attachment_fields_to_edit', function( $form_fields ) {
	$form_fields['face_crop'] = array(
			'label'      => 'Image Crop',
			'input'      => 'html',
			'html'       => '<input type="submit" value="Face Cropping" class="button" id="crop_faces" /> <input type="submit" value="Normal Cropping" class="button" id="crop_normal" />',
			'helps'      => 'This may take a while'
		);
	return $form_fields;
});

/**
 * Add JavaScript to the footer for the ajax button calls
 */
add_action( 'admin_footer', function() {
	?>
	<script>
		jQuery(document).ready(function($) {
			$('#crop_faces').click(function(e) {
				$('.face_crop .help').prepend("<img src='/wp-admin/images/wpspin_light.gif' />");
				data = { 
					'attachment_id': $('#attachment_id').val(), 
					'action': 'face_crop' 
				}
				$.post(ajaxurl, data, function(data) {
					$('.face_crop .help img').remove();
				})
				e.preventDefault();
			});

			$('#crop_normal').click(function(e) {
				$('.face_crop .help').prepend("<img src='/wp-admin/images/wpspin_light.gif' />");
				data = { 
					'attachment_id': $('#attachment_id').val(), 
					'action': 'normal_crop' 
				}
				$.post(ajaxurl, data, function(data) {
					$('.face_crop .help img').remove();
				})
				e.preventDefault();
			});
		});
	</script>
	<?php
});

/**
 *
 */
add_action( 'wp_ajax_face_crop', function() {
	@set_time_limit( 900 );
	$file = get_attached_file( (int)$_POST['attachment_id'] );
	$meta = wp_generate_attachment_metadata( (int)$_POST['attachment_id'], $file );
	crop_faces( $meta, (int)$_POST['attachment_id'] );
	exit;
});

/**
 *
 */
add_action( 'wp_ajax_normal_crop', function() {
	@set_time_limit( 900 );
	$file = get_attached_file( (int)$_POST['attachment_id'] );
	$meta = wp_generate_attachment_metadata( (int)$_POST['attachment_id'], $file );
	wp_update_attachment_metadata( (int)$_POST['attachment_id'], $meta );
	exit;
});
?>
