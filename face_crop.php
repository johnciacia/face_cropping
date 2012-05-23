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


add_filter( 'wp_generate_attachment_metadata', 'crop_faces', 10, 2 );

// For testing only
add_action( 'init', function() {
	if( empty( $_GET['testing'] ) ) return;
	$attach_data = unserialize('a:6:{s:5:"width";i:300;s:6:"height";i:400;s:14:"hwstring_small";s:22:"height=\'96\' width=\'72\'";s:4:"file";s:19:"2012/05/test141.jpg";s:5:"sizes";a:2:{s:9:"thumbnail";a:3:{s:4:"file";s:19:"test141-150x150.jpg";s:5:"width";i:150;s:6:"height";i:150;}s:6:"medium";a:3:{s:4:"file";s:19:"test141-225x300.jpg";s:5:"width";i:225;s:6:"height";i:300;}}s:10:"image_meta";a:10:{s:8:"aperture";i:0;s:6:"credit";s:0:"";s:6:"camera";s:0:"";s:7:"caption";s:0:"";s:17:"created_timestamp";i:0;s:9:"copyright";s:0:"";s:12:"focal_length";i:0;s:3:"iso";i:0;s:13:"shutter_speed";i:0;s:5:"title";s:0:"";}}');
	crop_faces( $attach_data, 33 );
	die("DONE");
});

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

}

function face_detect( $src ) {
	require_once("FaceDetector.php");
	$detector = new FaceDetector();
	$detector->scan( $src );
	return $detector->getFaces();
}


function transpose_origin( $bounds ) {
	return array( 
			'x' => round( $bounds['x'] + ( $bounds['width'] / 2 ) ),
			'y' => round( $bounds['y'] + ( $bounds['height'] / 2 ) )
		);
}

function normalize_points( $origin, $size ) {
	$x = $origin['x'] - ( $size['width'] / 2 );
	$y = $origin['y'] - ( $size['height'] / 2 );
	$x = $x < 0 ? 0 : $x;
	$y = $y < 0 ? 0 : $y;
	return array( $x, $y );
}

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

add_action( 'post-upload-ui', 'add_face_crop' );
function add_face_crop() {
	echo "Hello, World!";
}

?>