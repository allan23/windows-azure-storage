<?php
/*
 * Plugin Name: Windows Azure Storage for WordPress
 * Plugin URI: https://wordpress.org/plugins/windows-azure-storage/
 * Description: Use the Windows Azure Storage service to host your website's media files.
 * Version: 3.0.1
 * Author: 10up, Microsoft Open Technologies
 * Author URI: http://10up.com/
 * License: BSD 2-Clause
 * License URI: http://www.opensource.org/licenses/bsd-license.php
 */

/*
 * Copyright (c) 2009-2016, Microsoft Open Technologies, Inc.
 * Copyright (c) 2016, 10up
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this list
 *   of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice, this
 *   list of conditions  and the following disclaimer in the documentation and/or
 *   other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A  PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS
 * OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)  HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN
 * IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * PHP Version 5
 *
 * @category  WordPress_Plugin
 * @package   Windows_Azure_Storage_For_WordPress
 * @author    Microsoft Open Technologies, Inc. <msopentech@microsoft.com>
 * @copyright Microsoft Open Technologies, Inc.
 * @license   New BSD license, (http://www.opensource.org/licenses/bsd-license.php)
 * @link      http://www.microsoft.com
 */

/*
 * 'Windows Azure SDK for PHP v0.4.0' and its dependencies are included
 * in the library directory. If another version of the SDK is installed
 * and USESDKINSTALLEDGLOBALLY is defined, that version will be used instead.
 * 'Windows Azure SDK for PHP' provides access to the Windows Azure
 * Blob Storage service that this plugin enables for WordPress.
 * See https://github.com/windowsazure/azure-sdk-for-php/ for updates to the SDK.
 */

define( 'MSFT_AZURE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MSFT_AZURE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MSFT_AZURE_PLUGIN_LEGACY_MEDIA_URL', get_admin_url( get_current_blog_id(), 'media-upload.php' ) );
define( 'MSFT_AZURE_PLUGIN_VERSION', '4.0.0' );
define( 'MSFT_AZURE_PLUGIN_DOMAIN_NAME', 'windows-azure-storage' );

require_once MSFT_AZURE_PLUGIN_PATH . 'windows-azure-storage-settings.php';
require_once MSFT_AZURE_PLUGIN_PATH . 'windows-azure-storage-dialog.php';
require_once MSFT_AZURE_PLUGIN_PATH . 'windows-azure-storage-util.php';
require_once MSFT_AZURE_PLUGIN_PATH . 'includes/class-windows-azure-rest-api-client.php';
require_once MSFT_AZURE_PLUGIN_PATH . 'includes/class-windows-azure-generic-list-response.php';
require_once MSFT_AZURE_PLUGIN_PATH . 'includes/class-windows-azure-list-containers-response.php';
require_once MSFT_AZURE_PLUGIN_PATH . 'includes/class-windows-azure-list-blobs-response.php';
require_once MSFT_AZURE_PLUGIN_PATH . 'includes/class-windows-azure-config-provider.php';
require_once MSFT_AZURE_PLUGIN_PATH . 'includes/class-windows-azure-filesystem-access-provider.php';
require_once MSFT_AZURE_PLUGIN_PATH . 'includes/class-windows-azure-file-contents-provider.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
require_once MSFT_AZURE_PLUGIN_PATH . 'includes/class-windows-azure-wp-filesystem-direct.php';
require_once MSFT_AZURE_PLUGIN_PATH . 'includes/class-windows-azure-helper.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MSFT_AZURE_PLUGIN_PATH . 'bin/wp-cli.php';
}

// Check prerequisite for plugin
register_activation_hook( __FILE__, 'check_prerequisite' );

add_action( 'admin_menu', 'windows_azure_storage_plugin_menu' );
add_filter( 'media_buttons_context', 'windows_azure_storage_media_buttons_context' );
add_action( 'load-settings_page_windows-azure-storage-plugin-options', 'windows_azure_storage_load_settings_page' );
add_action( 'load-settings_page_windows-azure-storage-plugin-options', 'windows_azure_storage_check_container_access_policy' );
add_action( 'wp_ajax_query-azure-attachments', 'windows_azure_storage_query_azure_attachments' );

/**
 * Add Azure-specific tabs to the editor's media loader.
 *
 * @since    Unknown
 * @internal Callback for 'media_upload_tabs'.
 *
 * @param array $tabs Array of existing tabs.
 *
 * @return array Filtered array of tabs with our additions.
 */
function azure_storage_media_menu( $tabs ) {
	$tabs['browse'] = __( 'Browse Azure Storage', 'windows-azure-storage' );
	//$tabs['search'] = __( 'Search Azure Storage', 'windows-azure-storage' );
	//$tabs['upload'] = __( 'Upload to Azure Storage', 'windows-azure-storage' );

	return $tabs;
}

// Hook for adding tabs
add_filter( 'media_upload_tabs', 'azure_storage_media_menu' );
//TODO: Set 'Browse Azure Storage' as the default tab in the new media loader.

// Add callback for three tabs in the Windows Azure Storage Dialog
add_action( "media_upload_browse", "browse_tab" );

// Hooks for handling default file uploads
if ( (bool) get_option( 'azure_storage_use_for_default_upload' ) ) {
	add_filter(
		'wp_update_attachment_metadata',
		'windows_azure_storage_wp_update_attachment_metadata',
		9,
		2
	);//checked

	// Hook for handling blog posts via xmlrpc. This is not full proof check
	add_filter( 'content_save_pre', 'windows_azure_storage_content_save_pre' );//checked

	//TODO: implement wp_unique_filename filter once it is available in WordPress
	add_filter( 'wp_handle_upload_prefilter', 'windows_azure_storage_wp_handle_upload_prefilter' );//checked

	// Hook for handling media uploads
	add_filter( 'wp_handle_upload', 'windows_azure_storage_wp_handle_upload' );//checked

	// Filter to modify file name when XML-RPC is used
	//TODO: remove this filter when wp_unique_filename filter is available in WordPress
	add_filter( 'xmlrpc_methods', 'windows_azure_storage_xmlrpc_methods' ); //checked
}

// Hook for acecssing attachment (media file) URL
add_filter(
	'wp_get_attachment_url',
	'windows_azure_storage_wp_get_attachment_url',
	9,
	2
);//checked

// Hook for acecssing metadata about attachment (media file)
add_filter(
	'wp_get_attachment_metadata',
	'windows_azure_storage_wp_get_attachment_metadata',
	9,
	2
);

// Hook for handling deleting media files from standard WordpRess dialog
add_action( 'delete_attachment', 'windows_azure_storage_delete_attachment' );//checked

// Filter the 'srcset' attribute in 'the_content' introduced in WP 4.4.
if ( function_exists( 'wp_calculate_image_srcset' ) ) {
	add_filter( 'wp_calculate_image_srcset', 'windows_azure_storage_wp_calculate_image_srcset', 9, 5 );
}

/**
 * Check prerequisite for the plugin and report error
 *
 * @return void
 */
function check_prerequisite() {
	//TODO more robust activation checks. http://pento.net/2014/02/18/dont-let-your-plugin-be-activated-on-incompatible-sites/
}

/**
 * Replacing the callback for XML-RPC metaWeblog.newMediaObject
 *
 * @param array $methods XML-RPC methods
 *
 * @return array $methods Modified XML-RPC methods
 */
function windows_azure_storage_xmlrpc_methods( $methods ) {
	$methods['metaWeblog.newMediaObject'] = 'windows_azure_storage_newMediaObject';

	return $methods;
}

/**
 * Upload a file
 * Added unique blob name to the WordPress core mw_newMediaObject method
 *
 * @param array $args Method parameters
 *
 * @return array
 */
function windows_azure_storage_newMediaObject( $args ) {
	global $wpdb, $wp_xmlrpc_server;

	$blog_ID  = (int) $args[0];
	$username = $wp_xmlrpc_server->escape( $args[1] );
	$password = $wp_xmlrpc_server->escape( $args[2] );
	$data     = $args[3];

	$name = sanitize_file_name( $data['name'] );
	$type = $data['type'];
	$bits = $data['bits'];

	if ( ! $user = $wp_xmlrpc_server->login( $username, $password ) ) {
		return $wp_xmlrpc_server->error;
	}

	/** This action is documented in wp-includes/class-wp-xmlrpc-server.php */
	do_action( 'xmlrpc_call', 'metaWeblog.newMediaObject' );

	if ( ! current_user_can( 'upload_files' ) ) {
		$wp_xmlrpc_server->error = new IXR_Error( 401, __( 'You do not have permission to upload files.' ) );

		return $wp_xmlrpc_server->error;
	}

	/**
	 * Filter whether to preempt the XML-RPC media upload.
	 *
	 * Passing a truthy value will effectively short-circuit the media upload,
	 * returning that value as a 500 error instead.
	 *
	 * @since 2.1.0
	 *
	 * @param bool $error Whether to pre-empt the media upload. Default false.
	 */
	if ( $upload_err = apply_filters( 'pre_upload_error', false ) ) {
		return new IXR_Error( 500, $upload_err );
	}

	if ( ! empty( $data['overwrite'] ) && ( true === $data['overwrite'] ) ) {
		// Get postmeta info on the object.
		$old_file = $wpdb->get_row(
			$wpdb->prepare( "
				SELECT ID
				FROM %s
				WHERE post_title = %s
				  AND post_type = %s
				LIMIT 1;
		", $wpdb->posts, $name, 'attachment' )
		);


		// If query isn't successful, bail.
		if ( is_null( $old_file ) ) {
			return new WP_Error( 'Attachment not found', sprintf(
				__( 'Attachment not found in %s', 'windows-azure-storage' ),
				esc_html( $name )
			), $wpdb->print_error( $old_file ) );
		}

		// Delete previous file.
		wp_delete_attachment( $old_file->ID );

		// Make sure the new name is different by pre-pending the
		// previous post id.
		$filename = preg_replace( '/^wpid\d+-/', '', $name );
		$name     = "wpid{$old_file->ID}-{$filename}";
	}

	//default azure storage container
	$container = \Windows_Azure_Helper::get_default_container();

	$uploadDir = wp_upload_dir();
	if ( '/' === $uploadDir['subdir'][0] ) {
		$uploadDir['subdir'] = substr( $uploadDir['subdir'], 1 );
	}

	// Prepare blob name
	$blobName = ( '' === $uploadDir['subdir'] ) ? $name : $uploadDir['subdir'] . '/' . $name;

	$blobName = \Windows_Azure_Helper::get_unique_blob_name( $container, $blobName );

	$name = basename( $blobName );

	$upload = wp_upload_bits( $name, null, $bits );
	if ( ! empty( $upload['error'] ) ) {
		$errorString = sprintf( __( 'Could not write file %1$s (%2$s)' ), $name, $upload['error'] );

		return new IXR_Error( 500, $errorString );
	}
	// Construct the attachment array
	$post_id = 0;
	if ( ! empty( $data['post_id'] ) ) {
		$post_id = (int) $data['post_id'];

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new IXR_Error( 401, __( 'Sorry, you cannot edit this post.' ) );
		}
	}
	$attachment = array(
		'post_title'     => $name,
		'post_content'   => '',
		'post_type'      => 'attachment',
		'post_parent'    => $post_id,
		'post_mime_type' => $type,
		'guid'           => $upload['url'],
	);

	// Save the data
	$id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

	/**
	 * Fires after a new attachment has been added via the XML-RPC MovableType API.
	 *
	 * @since 3.4.0
	 *
	 * @param int   $id   ID of the new attachment.
	 * @param array $args An array of arguments to add the attachment.
	 */
	do_action( 'xmlrpc_call_success_mw_newMediaObject', $id, $args );

	$struct = array(
		'id'   => strval( $id ),
		'file' => $upload['file'],
		'url'  => $upload['url'],
		'type' => $type,
	;
	;

	/** This filter is documented in wp-admin/includes/file.php */
	return apply_filters( 'wp_handle_upload', $struct, 'upload' );
}

/**
 * Wordpress hook for wp_get_attachment_url
 *
 * @param string  $url    post url
 *
 * @param integer $postID post id
 *
 * @return string Returns metadata url
 */
function windows_azure_storage_wp_get_attachment_url( $url, $postID ) {
	$mediaInfo = get_post_meta( $postID, 'windows_azure_storage_info', true );

	if ( ! empty( $mediaInfo ) ) {
		return $mediaInfo['url'];
	} else {
		return $url;
	}
}

/**
 * Wordpress hook for wp_get_attachment_metadata. Cache mediainfo for
 * further reference.
 *
 * @param string  $data   attachment data
 *
 * @param integer $postID Associated post id
 *
 * @return array Return input data without modification
 */
function windows_azure_storage_wp_get_attachment_metadata( $data, $postID ) {
	if ( is_numeric( $postID ) ) {
		// Cache this metadata. Needed for deleting files
		$mediaInfo = get_post_meta( $postID, 'windows_azure_storage_info', true );
	}

	return $data;
}

/**
 * Wordpress hook for wp_update_attachment_metadata, hook for handling
 * default media file upload in wordpress.
 *
 * @param string  $data   attachment data
 *
 * @param integer $postID Associated post id
 *
 * @return array data after updating information about blob storage URL and tags
 */
function windows_azure_storage_wp_update_attachment_metadata( $data, $postID ) {
	$default_azure_storage_account_container_name = \Windows_Azure_Helper::get_default_container();
	$delete_local_file                            = \Windows_Azure_Helper::delete_local_file();
	$upload_file_name                             = get_attached_file( $postID, true );

	// If attachment metadata is empty (for video), generate correct blob names
	if ( empty( $data ) || empty( $data['file'] ) ) {
		// Get upload directory
		$upload_dir = wp_upload_dir();
		if ( '/' === $upload_dir['subdir']{0} ) {
			$upload_dir['subdir'] = substr( $upload_dir['subdir'], 1 );
		}

		// Prepare blob name
		$relative_file_name = ( '' === $upload_dir['subdir'] ) ?
			basename( $upload_file_name ) :
			$upload_dir['subdir'] . "/" . basename( $upload_file_name );
	} else {
		// Prepare blob name
		$relative_file_name = $data['file'];
	}

	try {
		// Get full file path of uploaded file
		$data['file'] = $upload_file_name;

		// Get mime-type of the file
		$mimeType = get_post_mime_type( $postID );

		try {
			$result = \Windows_Azure_Helper::put_media_to_blob_storage(
				$default_azure_storage_account_container_name,
				$relative_file_name,
				$data['file'],
				$mimeType
			);

		} catch ( Exception $e ) {
			echo "<p>Error in uploading file. Error: " . esc_html( $e->getMessage() ) . "</p><br/>";

			return $data;
		}

		$url = sprintf( '%1$s/%2$s',
			untrailingslashit( WindowsAzureStorageUtil::get_storage_url_base() ),
			$relative_file_name
		);

		// Set new url in returned data
		$data['url'] = $url;

		// Handle thumbnail and medium size files
		$thumbnails = array();
		if ( ! empty( $data["sizes"] ) ) {
			$file_upload_dir = substr( $relative_file_name, 0, strripos( $relative_file_name, "/" ) );

			foreach ( $data["sizes"] as $size ) {
				// Do not prefix file name with wordpress upload folder path
				$size_file_name = dirname( $data['file'] ) . "/" . $size["file"];

				// Move only if file exists. Some theme may use same file name for multiple sizes
				if ( file_exists( $size_file_name ) ) {
					$blob_name = ( '' === $file_upload_dir ) ? $size['file'] : $file_upload_dir . '/' . $size['file'];
					\Windows_Azure_Helper::put_media_to_blob_storage(
						$default_azure_storage_account_container_name,
						$blob_name,
						$size_file_name,
						$mimeType
					);

					$thumbnails[] = $blob_name;

					// Delete the local thumbnail file
					if ( $delete_local_file ) {
						unlink( $size_file_name );
					}
				}
			}
		}

		delete_post_meta( $postID, 'windows_azure_storage_info' );

		add_post_meta(
			$postID, 'windows_azure_storage_info',
			array(
				'container'  => $default_azure_storage_account_container_name,
				'blob'       => $relative_file_name,
				'url'        => $url,
				'thumbnails' => $thumbnails,
			)
		);

		// Delete the local file
		if ( $delete_local_file ) {
			unlink( $upload_file_name );
		}
	} catch ( Exception $e ) {
		echo "<p>Error in uploading file. Error: " . esc_html( $e->getMessage() ) . "</p><br/>";
	}

	return $data;
}

/**
 * Hook for updating post content prior to saving it in the database
 *
 * @param string $text post content
 *
 * @return string Updated post content
 */
function windows_azure_storage_content_save_pre( $text ) {
	return getUpdatedUploadUrl( $text );
}

/**
 * TODO: Implement wp_unique_filename filter once its available in WordPress.
 *
 * Hook for altering the file name.
 * Check whether the blob exists in the container and generate a unique file name for the blob.
 *
 * @param array $file An array of data for a single file.
 *
 * @return array Updated file data.
 */
function windows_azure_storage_wp_handle_upload_prefilter( $file ) {
	//default azure storage container
	$container = \Windows_Azure_Helper::get_default_container();

	$uploadDir = wp_upload_dir();
	if ( '/' === $uploadDir['subdir'][0] ) {
		$uploadDir['subdir'] = substr( $uploadDir['subdir'], 1 );
	}

	// Prepare blob name
	$blobName = ( '' === $uploadDir['subdir'] ) ? $file['name'] : $uploadDir['subdir'] . '/' . $file['name'];

	$blobName = \Windows_Azure_Helper::get_unique_blob_name( $container, $blobName );

	$file['name'] = basename( $blobName );

	return $file;
}

/**
 * Hook for handling media uploads
 *
 * @param array $uploads upload metadata
 *
 * @return array updated metadata
 */
function windows_azure_storage_wp_handle_upload( $uploads ) {
	$wp_upload_dir  = wp_upload_dir();
	$uploads['url'] = sprintf( '%1$s/%2$s/%3$s',
		untrailingslashit( WindowsAzureStorageUtil::get_storage_url_base() ),
		ltrim( $wp_upload_dir['subdir'], '/' ),
		basename( $uploads['file'] )
	);

	return $uploads;
}

/**
 * Common function to replace wordpress file uplaod url with
 * Windows Azure Storage URLs
 *
 * @param string $url original upload URL
 *
 * @return string Updated upload URL
 */
function getUpdatedUploadUrl( $url ) {
	$wp_upload_dir      = wp_upload_dir();
	$upload_dir_url     = $wp_upload_dir['baseurl'];
	$storage_url_prefix = WindowsAzureStorageUtil::get_storage_url_base();

	return str_replace( $upload_dir_url, $storage_url_prefix, $url );
}

/**
 * Hook for handling deleting media files from standard WordpRess dialog
 *
 * @param string $postID post id
 *
 * @return void
 */
function windows_azure_storage_delete_attachment( $postID ) {
	if ( is_numeric( $postID ) ) {
		$mediaInfo = get_post_meta( $postID, 'windows_azure_storage_info', true );

		if ( ! empty( $mediaInfo ) ) {
			// Delete media file from blob storage
			$containerName = $mediaInfo['container'];
			$blobName      = $mediaInfo['blob'];
			\Windows_Azure_Helper::delete_blob( $containerName, $blobName );

			// Delete associated thumbnails from blob storage (if any)
			$thumbnails = $mediaInfo['thumbnails'];
			if ( ! empty( $thumbnails ) ) {
				foreach ( $thumbnails as $thumbnail_blob ) {
					\Windows_Azure_Helper::delete_blob( $containerName, $thumbnail_blob );
				}
			}
		}
	}
}

/**
 * Add Browse tab to the popup windows
 *
 * @return void
 */
function browse_tab() {
	add_action( 'admin_enqueue_scripts', 'windows_azure_storage_dialog_scripts' );
	wp_enqueue_media();
	wp_enqueue_script( 'media-grid' );
	wp_enqueue_script( 'windows-azure-storage-media-browser', MSFT_AZURE_PLUGIN_URL . 'js/windows-azure-storage-media-browser.js', [ 'media-grid' ], MSFT_AZURE_PLUGIN_VERSION );
	wp_localize_script( 'media-grid', '_wpMediaGridSettings', [
		'adminUrl' => parse_url( self_admin_url(), PHP_URL_PATH ),
		'l10n'     => array(
			'selectText' => __( 'Insert into post', MSFT_AZURE_PLUGIN_DOMAIN_NAME ),
		)
	] );
	wp_iframe( 'windows_azure_storage_dialog_browse_tab' );
}

function windows_azure_storage_dialog_browse_tab() {
	//wp_enqueue_style( 'media' );
	?>
	<div id="windows-azure-storage-browser"></div>
	<?php
	wp_print_media_templates();
}

/**
 * Hook for adding new toolbar button in edit post page.
 *
 * @since    1.0.0
 * @since    3.0.0 Rewrote internals to only create a single element.
 * @internal Callback for 'media_buttons_context' filter.
 *
 * @param string $context Media buttons context.
 *
 * @return string Media buttons context with our button appended.
 */
function windows_azure_storage_media_buttons_context( $context ) {
	global $post_ID, $temp_ID;

	$uploading_iframe_ID = (int) ( 0 === $post_ID ? $temp_ID : $post_ID );

	$browse_iframe_src = apply_filters( 'browse_iframe_src',
		add_query_arg(
			array(
				'tab'       => 'browse', // 'browse', 'search', or 'upload'
				'post_id'   => $uploading_iframe_ID,
				'TB_iframe' => 'true',
				'height'    => 500,
				'width'     => 640,
			),
			MSFT_AZURE_PLUGIN_LEGACY_MEDIA_URL
		)
	);

	$azure_image_button_element = sprintf(
		'<a id="windows-azure-storage-media-button" role="button" href="javascript:void(0)" class="button" data-editor="content"
title="%2$s"><img src="%3$s" alt="%2$s" role="img" class="windows-azure-storage-media-icon" />%4$s</a>',
		esc_url( $browse_iframe_src ),
		esc_attr__( 'Windows Azure Storage', 'windows-azure-storage' ),
		esc_url( MSFT_AZURE_PLUGIN_URL . 'images/WindowsAzure.jpg' ),
		esc_html__( 'Add Media From Azure', 'windows-azure-storage' )
	);

	return $context . $azure_image_button_element;
}

/**
 * Add option page for Windows Azure Storage Plugin
 *
 * @return void
 */
function windows_azure_storage_plugin_menu() {
	if ( WindowsAzureStorageUtil::check_action_permissions( 'change_settings' ) ) {
		add_options_page(
			__( 'Windows Azure Storage Plugin Settings', MSFT_AZURE_PLUGIN_DOMAIN_NAME ),
			__( 'Windows Azure', MSFT_AZURE_PLUGIN_DOMAIN_NAME ),
			'manage_options',
			'windows-azure-storage-plugin-options',
			'windows_azure_storage_plugin_options_page'
		);
	}

	// Call register settings function
	add_action( 'admin_init', 'windows_azure_storage_plugin_register_settings' );
}

/**
 * Filter the image source URLs to point 'srcset' to Azure Storage blobs.
 *
 * @since    3.0.0
 * @internal Callback for 'wp_calculate_image_srcset' filter.
 * @see      wp_calculate_image_srcset()
 * @link     http://projectnami.org/fix-for-azure-storage-plugin-and-wp-4-4/
 *
 * @param array  $sources       {
 *                              One or more arrays of source data to include in the 'srcset'.
 *
 * @type array   $width         {
 * @type string  $url           The URL of an image source.
 * @type string  $descriptor    The descriptor type used in the image candidate string,
 *                                    either 'w' or 'x'.
 * @type int     $value         The source width if paired with a 'w' descriptor, or a
 *                                    pixel density value if paired with an 'x' descriptor.
 *      }
 * }
 *
 * @param array  $size_array    Array of width and height values in pixels (in that order).
 * @param string $image_src     The 'src' of the image.
 * @param array  $image_meta    The image meta data as returned by 'wp_get_attachment_metadata()'.
 * @param int    $attachment_id Image attachment ID or 0.
 *
 * @return array The filtered $sources array.
 */
function windows_azure_storage_wp_calculate_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
	$media_info = get_post_meta( $attachment_id, 'windows_azure_storage_info', true );

	// If a CNAME is configured, make sure only 'http' is used for the protocol.
	$azure_cname       = \Windows_Azure_Helper::get_cname();
	$esc_url_protocols = ! empty ( $azure_cname ) ? array( 'https', 'http', '//' ) : null;

	if ( ! empty( $media_info ) ) {
		$base_url = trailingslashit( WindowsAzureStorageUtil::get_storage_url_base( false ) .
		                             $media_info['container'] );

		foreach ( $sources as &$source ) {
			$img_filename = substr( $source['url'], strrpos( $source['url'], '/' ) + 1 );

			if ( substr( $media_info['blob'], strrpos( $media_info['blob'], '/' ) + 1 ) === $img_filename ) {
				$source['url'] = esc_url( $base_url . $media_info['blob'], $esc_url_protocols );
			} else {
				foreach ( $media_info['thumbnails'] as $thumbnail ) {
					if ( substr( $thumbnail, strrpos( $thumbnail, '/' ) + 1 ) === $img_filename ) {
						$source['url'] = esc_url( $base_url . $thumbnail, $esc_url_protocols );
						break;
					}

				}

			}

		}

	}

	return $sources;
}

function windows_azure_storage_query_azure_attachments() {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error();
	}

	$query = isset( $_REQUEST['query'] ) ? (array) $_REQUEST['query'] : [ ];
	$query = array_intersect_key( $query, array_flip( array(
		's',
		'posts_per_page',
		'paged',
	) ) );

	$query = wp_parse_args( $query, array(
		's'              => '',
		'posts_per_page' => Windows_Azure_Rest_Api_Client::API_REQUEST_BULK_SIZE,
		'paged'          => 1,
	) );
	if ( 1 === (int) $query['paged'] ) {
		$next_marker = false;
	} else {
		$next_marker = $_COOKIE['azure_next_marker'];
	}
	$posts       = array();
	$credentials = Windows_Azure_Config_Provider::get_account_credentials();
	$client      = new Windows_Azure_Rest_Api_Client( $credentials['account_name'], $credentials['account_key'] );
	$blobs       = $client->list_blobs( Windows_Azure_Helper::get_default_container(), $query['s'], (int) $query['posts_per_page'], $next_marker );
	setcookie( 'azure_next_marker', $blobs->get_next_marker() );
	foreach ( $blobs->get_all() as $blob ) {
		if ('/' === $blob['Name'][strlen($blob['Name'])-1]) {
			continue;
		}
		$is_image = ( false !== strpos($blob['Properties']['Content-Type'], 'image/' ) );

		$blob_info = array(
			'id' => base64_encode($blob['Name']),
			'uploading' => false,
			'filename' => $blob['Name'],
			'dateFormatted' => $blob['Properties']['Last-Modified'],
			'icon' => $is_image ? Windows_Azure_Helper::get_full_blob_url( $blob['Name'] ) : wp_mime_type_icon(blob['Properties']['Content-Type']),
			'url' => Windows_Azure_Helper::get_full_blob_url( $blob['Name'] ),
			'filesizeHumanReadable' => size_format( $blob['Properties']['Content-Length'] ),
		);

		if ( current_user_can( 'delete_posts' ) ) {
			$blob_info['nonces']['delete'] = wp_create_nonce( 'delete-blob_' . $blob_info['id'] );
		}

		$posts[] = $blob_info;
	}
	wp_send_json_success( $posts );
}
