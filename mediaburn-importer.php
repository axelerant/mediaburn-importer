<?php
/*
Plugin Name: MediaBurn Importer
Plugin URI: http://aihr.us
Description: Easily assign Vzaar media records to WordPress videos.
Version: trunk
Author: Michael Cannon
Author URI: http://aihr.us/contact-aihrus/
License: GPL2

Copyright 2013  Michael Cannon  (email : mc@aihr.us)

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

/**
 * MediaBurn Importer
 *
 * @package mediaburn-importer
 */
class MediaBurn_Importer {
	private $menu_id               = null;
	private static $init           = false;
	private static $init_vzaar     = false;
	private static $vzaar_username = null;
	private static $wpdb           = null;

	// Plugin initialization
	public function __construct() {
		// Capability check
		if ( ! current_user_can( 'manage_options' ) )
			return;

		if ( ! function_exists( 'admin_url' ) )
			return;

		add_action( 'add_meta_boxes', array( $this, 'mediaburn_import_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueues' ) );
		add_action( 'admin_init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'wp_ajax_importtypo3record', array( $this, 'ajax_process_record' ) );
		add_filter( 'plugin_action_links', array( $this, 'add_plugin_action_links' ), 10, 2 );
		load_plugin_textdomain( 'mediaburn-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		
		$this->options_link		= '<a href="'.get_admin_url().'options-general.php?page=mbi-options">'.__('Settings', 'mediaburn-importer').'</a>';
	}

	public static function init() {
		if ( is_null( self::$wpdb ) ) {
			global $wpdb;
			self::$wpdb			= $wpdb;
		}

		self::$init				= true;
	}

	public function init_vzaar() {
		// Vzaar connecters
		require_once( 'lib/vzaar/Vzaar.php' );

		$vzaar_application_token	= get_mbi_options( 'vzaar_application_token' );
		Vzaar::$token			= $vzaar_application_token;
		self::$vzaar_username	= get_mbi_options( 'vzaar_username' );
		Vzaar::$secret			= self::$vzaar_username;

		self::$init_vzaar			= true;
	}

	// Display a Settings link on the main Plugins page
	public function add_plugin_action_links( $links, $file ) {
		if ( $file == plugin_basename( __FILE__ ) ) {
			array_unshift( $links, $this->options_link );

			$link				= '<a href="'.get_admin_url().'tools.php?page=mediaburn-importer">'.__('Import', 'mediaburn-importer').'</a>';
			array_unshift( $links, $link );
		}

		return $links;
	}


	// Register the management page
	public function add_admin_menu() {
		$this->menu_id = add_management_page( __( 'MediaBurn Importer', 'mediaburn-importer' ), __( 'MediaBurn Importer', 'mediaburn-importer' ), 'manage_options', 'mediaburn-importer', array($this, 'user_interface') );

		add_action( 'admin_print_styles-' . $this->menu_id, array( $this, 'styles' ) );
        add_screen_meta_link(
        	'mbi-options-link',
			__('MediaBurn Importer Settings', 'mediaburn-importer'),
			admin_url('options-general.php?page=mbi-options'),
			$this->menu_id,
			array('style' => 'font-weight: bold;')
		);
	}

	public function styles() {
		wp_register_style( 'mbi-admin', plugins_url( 'settings.css', __FILE__ ) );
		wp_enqueue_style( 'mbi-admin' );
	}
	
	// Enqueue the needed Javascript and CSS
	public function admin_enqueues( $hook_suffix ) {
		if ( $hook_suffix != $this->menu_id )
			return;

		wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'jquery-ui/jquery.ui.progressbar.min.js', __FILE__ ), array( 'jquery-ui-core', 'jquery-ui-widget' ), '1.8.6' );
		wp_enqueue_style( 'jquery-ui-mbiposts', plugins_url( 'jquery-ui/redmond/jquery-ui-1.7.2.custom.css', __FILE__ ), array(), '1.7.2' );
	}

	public function user_interface() {
		if ( ! self::$init )
			self::init();

		echo <<<EOD
<div id="message" class="updated fade" style="display:none"></div>

<div class="wrap mbiposts">
	<div class="icon32" id="icon-tools"></div>
	<h2>
EOD;
	_e('MediaBurn Importer', 'mediaburn-importer');
	echo '</h2>';

		// If the button was clicked
		if ( ! empty( $_POST['mediaburn-importer'] ) || ! empty( $_REQUEST['posts'] ) ) {
			// Create the list of image IDs
			if ( ! empty( $_REQUEST['posts'] ) ) {
				$posts			= array_map( 'intval', explode( ',', trim( $_REQUEST['posts'], ',' ) ) );
				$count			= count( $posts );
				$posts			= implode( ',', $posts );
			} else {
				$posts			= array();
				$count			= 0;

				// Load Vzaar Media Only
				$posts = $this->get_vzaar_records();
				$count = count( $posts );

				if ( empty( $count ) ) {
					echo '	<p>' . _e( 'All done. No further media records to import.', 'mediaburn-importer' ) . "</p></div>";
					return;
				}
			}

			$posts			= array_unique( $posts );
			$posts			= "'" . implode( "','", $posts ) . "'";
			$this->show_status( $count, $posts );
			delete_transient( 'MediaBurn_Importer-done_uids' );
		} else {
			// No button click? Display the form.
			$this->show_greeting();
		}
		
		echo '</div>';
	}


	public function get_vzaar_records() {
		// do wp query for video posts where vzaar embed isn't done yet
		// empty wpzoom_post_embed_code & wpzoom_video_type
		// if update_vzaar_media, then all videos
		$query					= array(
			'post_status'		=> array( 'publish', 'private' ),
			'post_type'			=> 'video',
			'orderby'			=> 'post_modified',
			'order'				=> 'DESC',
			'post__not_in'		=> array(),
		);
	
		$mbr_to_import		= get_mbi_options( 'mbr_to_import' );
		if ( $mbr_to_import ) {
			$query[ 'post__in' ] = array( $mbr_to_import );
		} else {
			$query['posts_per_page'] = 1;

			$query['meta_query']	= array(
				array(
					'key'			=> 'control_number',
					'value'			=> '',
					'compare'		=> '!=',
				),
				array(
					'key'			=> 'wpzoom_post_embed_code',
					'value'			=> '',
					'compare'		=> '=',
				),
			);

			$update					= get_mbi_options( 'update_vzaar_media' );
			if ( ! $update ) {
				// remove those which already have entries
				$results  = new WP_Query( $query );
				$query_wp = $results->request;
				$query_wp = preg_replace( '#\bLIMIT 0,.*#', '', $query_wp );
				$done_ids = self::$wpdb->get_col( $query_wp );;
				foreach ( $done_ids as $id )
					$query[ 'post__not_in' ][]	= $id;
			}

			$query['meta_query']	= array(
				array(
					'key'			=> 'control_number',
					'value'			=> '',
					'compare'		=> '!=',
				),
			);
		}

		$results                 = new WP_Query( $query );
		$query_wp                = $results->request;

		$limit					= get_mbi_options( 'import_limit' );
		if ( $limit ) {
			$query['posts_per_page']	= $limit;
			$query_wp = preg_replace( '#\bLIMIT 0,.*#', 'LIMIT 0,' . $limit, $query_wp );
		} else {
			$query['nopaging']			= true;
			$query_wp = preg_replace( '#\bLIMIT 0,.*#', '', $query_wp );
		}

		$results_array = self::$wpdb->get_col( $query_wp );
		sort( $results_array );

		return $results_array;
	}

	public function show_status( $count, $posts ) {
		echo '<p>' . __( "Please be patient while media records are processed. This can take a while, up to 2 minutes per individual media record to include related attachments. Do not navigate away from this page until this script is done or the import will not be completed. You will be notified via this page when the import is completed.", 'mediaburn-importer' ) . '</p>';

		echo '<p id="time-remaining">' . sprintf( __( 'Estimated time required to import is %1$s minutes.', 'mediaburn-importer' ), ( $count * .33 ) ) . '</p>';

		$text_goback = ( ! empty( $_GET['goback'] ) ) ? sprintf( __( 'To go back to the previous page, <a href="%s">click here</a>.', 'mediaburn-importer' ), 'javascript:history.go(-1)' ) : '';

		$text_failures = sprintf( __( 'All done! %1$s MediaBurn records were successfully processed in %2$s seconds and there were %3$s failure(s). To try importing the failed records again, <a href="%4$s">click here</a>. %5$s', 'mediaburn-importer' ), "' + rt_successes + '", "' + rt_totaltime + '", "' + rt_errors + '", esc_url( wp_nonce_url( admin_url( 'tools.php?page=mediaburn-importer&goback=1' ), 'mediaburn-importer' ) . '&posts=' ) . "' + rt_failedlist + '", $text_goback );

		$text_nofailures = sprintf( __( 'All done! %1$s MediaBurn records were successfully processed in %2$s seconds and there were no failures. %3$s', 'mediaburn-importer' ), "' + rt_successes + '", "' + rt_totaltime + '", $text_goback );
?>

	<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'mediaburn-importer' ) ?></em></p></noscript>

	<div id="mbiposts-bar" style="position:relative;height:25px;">
		<div id="mbiposts-bar-percent" style="position:absolute;left:50%;top:50%;width:300px;margin-left:-150px;height:25px;margin-top:-9px;font-weight:bold;text-align:center;"></div>
	</div>

	<p><input type="button" class="button hide-if-no-js" name="mbiposts-stop" id="mbiposts-stop" value="<?php _e( 'Abort Importing Vzaar Data', 'mediaburn-importer' ) ?>" /></p>

	<h3 class="title"><?php _e( 'Debugging Information', 'mediaburn-importer' ) ?></h3>

	<p>
		<?php printf( __( 'Total media records: %s', 'mediaburn-importer' ), $count ); ?><br />
		<?php printf( __( 'Records Imported: %s', 'mediaburn-importer' ), '<span id="mbiposts-debug-successcount">0</span>' ); ?><br />
		<?php printf( __( 'Import Failures: %s', 'mediaburn-importer' ), '<span id="mbiposts-debug-failurecount">0</span>' ); ?>
	</p>

	<ol id="mbiposts-debuglist">
		<li style="display:none"></li>
	</ol>

	<script type="text/javascript">
	// <![CDATA[
		jQuery(document).ready(function($){
			var i;
			var rt_posts = [<?php echo $posts; ?>];
			var rt_total = rt_posts.length;
			var rt_count = 1;
			var rt_percent = 0;
			var rt_successes = 0;
			var rt_errors = 0;
			var rt_failedlist = '';
			var rt_resulttext = '';
			var rt_timestart = new Date().getTime();
			var rt_timeend = 0;
			var rt_totaltime = 0;
			var rt_continue = true;

			// Create the progress bar
			$("#mbiposts-bar").progressbar();
			$("#mbiposts-bar-percent").html( "0%" );

			// Stop button
			$("#mbiposts-stop").click(function() {
				rt_continue = false;
				$('#mbiposts-stop').val("<?php echo $this->esc_quotes( __( 'Stopping, please wait a moment.', 'mediaburn-importer' ) ); ?>");
			});

			// Clear out the empty list element that's there for HTML validation purposes
			$("#mbiposts-debuglist li").remove();

			// Called after each import. Updates debug information and the progress bar.
			function T3IPostsUpdateStatus( id, success, response ) {
				$("#mbiposts-bar").progressbar( "value", ( rt_count / rt_total ) * 100 );
				$("#mbiposts-bar-percent").html( Math.round( ( rt_count / rt_total ) * 1000 ) / 10 + "%" );
				rt_count = rt_count + 1;

				if ( success ) {
					rt_successes = rt_successes + 1;
					$("#mbiposts-debug-successcount").html(rt_successes);
					$("#mbiposts-debuglist").append("<li>" + response.success + "</li>");
				}
				else {
					rt_errors = rt_errors + 1;
					rt_failedlist = rt_failedlist + ',' + id;
					$("#mbiposts-debug-failurecount").html(rt_errors);
					$("#mbiposts-debuglist").append("<li>" + response.error + "</li>");
				}
			}

			// Called when all posts have been processed. Shows the results and cleans up.
			function T3IPostsFinishUp() {
				rt_timeend = new Date().getTime();
				rt_totaltime = Math.round( ( rt_timeend - rt_timestart ) / 1000 );

				$('#mbiposts-stop').hide();

				if ( rt_errors > 0 ) {
					rt_resulttext = '<?php echo $text_failures; ?>';
				} else {
					rt_resulttext = '<?php echo $text_nofailures; ?>';
				}

				$("#message").html("<p><strong>" + rt_resulttext + "</strong></p>");
				$("#message").show();
			}

			// Regenerate a specified image via AJAX
			function T3IPosts( id ) {
				$.ajax({
					type: 'POST',
					url: ajaxurl,
					data: { action: "importtypo3record", id: id },
					success: function( response ) {
						if ( response.success ) {
							T3IPostsUpdateStatus( id, true, response );
						}
						else {
							T3IPostsUpdateStatus( id, false, response );
						}

						if ( rt_posts.length && rt_continue ) {
							T3IPosts( rt_posts.shift() );
						}
						else {
							T3IPostsFinishUp();
						}
					},
					error: function( response ) {
						T3IPostsUpdateStatus( id, false, response );

						if ( rt_posts.length && rt_continue ) {
							T3IPosts( rt_posts.shift() );
						} 
						else {
							T3IPostsFinishUp();
						}
					}
				});
			}

			T3IPosts( rt_posts.shift() );
		});
	// ]]>
	</script>
<?php
	}


	public function show_greeting() {
?>
	<form method="post" action="">
<?php wp_nonce_field('mediaburn-importer') ?>

	<p><?php _e( "Use this tool to attached Vzaar data to MediaBurn videos.", 'mediaburn-importer' ); ?></p>

	<p><?php printf( __( 'Please review your %s before proceeding.', 'mediaburn-importer' ), $this->options_link ); ?></p>

	<p><?php _e( 'To begin, click the button below.', 'mediaburn-importer ', 'mediaburn-importer'); ?></p>

	<p><input type="submit" class="button hide-if-no-js" name="mediaburn-importer" id="mediaburn-importer" value="<?php _e( 'Import Vzaar Media', 'mediaburn-importer' ) ?>" /></p>

	<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'mediaburn-importer' ) ?></em></p></noscript>

	</form>
<?php
		$copyright				= '<div class="copyright">Copyright %s <a href="http://aihr.us">Aihrus.</a></div>';
		$copyright				= sprintf( $copyright, date( 'Y' ) );
		echo $copyright;
	}

	// Process a single image ID (this is an AJAX handler)
	public function ajax_process_record() {
		if ( ! self::$init )
			self::init();

		error_reporting( 0 ); // Don't break the JSON result
		header( 'Content-type: application/json' );
		$post_id = $_REQUEST['id'];
		self::load_vzaar_media( $post_id, true );

		die( json_encode( array( 'success' => sprintf( __( '&quot;<a href="%1$s" target="_blank">%2$s</a>&quot; Post ID %3$s was successfully processed in %4$s seconds.', 'mediaburn-importer' ), get_permalink( $post_id ), esc_html( get_the_title( $post_id ) ), $post_id, timer_stop() ) ) ) );
	}

	public static function load_vzaar_media( $post_id, $force = false ) {
		if ( ! self::$init )
			self::init();

		if ( ! self::$init_vzaar )
			self::init_vzaar();

		$vzaar_id_found			= false;
		$vzaar_id				= get_post_meta( $post_id, 'vzaar_id', true );

		if ( ! $vzaar_id || $force ) {
			// use control number to get video id from Vzaar
			// _ helps ensure title lookup is unique
			$control_number		= get_post_meta( $post_id, 'control_number', true );
			if ( ! $control_number )
				return;

			$title_lookup		= $control_number . '_';
			$videos				= Vzaar::searchVideoList( self::$vzaar_username, true, $title_lookup );

			if ( isset( $videos[ 0 ] ) && is_object( $videos[ 0 ] ) ) {
				$video			= $videos[ 0 ];
				$vzaar_id		= $video->id;

				delete_post_meta( $post_id, 'vzaar_id' );
				add_post_meta( $post_id, 'vzaar_id', $vzaar_id );
				unset( $video );
				
				$vzaar_id_found	= true;
			}
		}

		if ( ! $vzaar_id )
			return;

		$post					= get_post( $post_id );
		$slug					= $post->post_name;

		// load Vzaar components if not already done
		if ( $vzaar_id_found || ( $force && $vzaar_id ) || get_mbi_options( 'update_vzaar_media' ) ) {
			$video				= Vzaar::getVideoDetails( $vzaar_id, true );

			// get video code from Vzaar - wpzoom_post_embed_code
			$video_html			= $video->html;

			// apply apiOn=true for JavaScript API
			$find				= 'param name="flashvars" value="';
			$replace			= $find . 'apiOn=true&';
			$video_html			= str_replace( $find, $replace, $video_html );
			// MB remove autoplay
			$find				= '&autoplay=true';
			$video_html			= str_replace( $find, '', $video_html );

			delete_post_meta( $post_id, 'wpzoom_post_embed_code' );
			add_post_meta( $post_id, 'wpzoom_post_embed_code', $video_html );
			delete_post_meta( $post_id, 'wpzoom_video_type' );
			add_post_meta( $post_id, 'wpzoom_video_type', 'external' );

			self::delete_old_thumbnail( $post_id );

			// get thumbnail from Vzaar
			$video_image_url   = $video->framegrabUrl;
			$file              = $slug . '-video-thumbnail.jpg';
			$featured_image_id = self::_import_attachment( $post_id, $file, $video_image_url, $slug );
		}

		if ( $force && get_mbi_options( 'set_featured_image' ) && $featured_image_id ) {
			update_post_meta( $post_id, '_thumbnail_id', $featured_image_id );
		}
	}

	public static function delete_old_thumbnail( $post_id ) {
		$thumbnail_ids			= get_post_meta( $post_id, '_thumbnail_id' );

		foreach ( $thumbnail_ids as $key => $thumbnail_id ) {
			if ( $thumbnail_id ) {
				wp_delete_attachment( $thumbnail_id, true );
			}
		
			delete_post_meta( $post_id, '_thumbnail_id', $thumbnail_id );
		}
	}

	public static function _import_attachment( $post_id, $file, $original_file_uri, $slug ) {
		$file_move				= wp_upload_bits($file, null, file_get_contents($original_file_uri));
		$filename				= $file_move['file'];

		$wp_filetype			= wp_check_filetype($file, null);
		$attachment				= array(
			'post_content'		=> '',
			'post_mime_type'	=> $wp_filetype['type'],
			'post_status'		=> 'inherit',
			'post_title'		=> $slug,
		);
		$attach_id				= wp_insert_attachment( $attachment, $filename, $post_id );
		$attach_data			= wp_generate_attachment_metadata( $attach_id, $filename );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		return $attach_id;
	}

	// Helper to make a JSON error message
	public function die_json_error_msg( $id, $message ) {
		die( json_encode( array( 'error' => sprintf( __( '&quot;%1$s&quot; Post ID %2$s failed to be processed. The error message was: %3$s', 'mediaburn-importer' ), esc_html( get_the_title( $id ) ), $id, $message ) ) ) );
	}


	// Helper function to escape quotes in strings for use in Javascript
	public function esc_quotes( $string ) {
		return str_replace( '"', '\"', $string );
	}

	public function mediaburn_import_meta_boxes() {
		add_meta_box( 'mediaburn_import', __( 'MediaBurn Importer', 'mediaburn-importer' ), array( $this, 'post_mediaburn_import_meta_box' ), 'video', 'side', 'high' );
	}

	public function post_mediaburn_import_meta_box( $post ) {
		wp_nonce_field( 'mediaburn_import', 'mediaburn-importer' );
		echo '<label class="selectit">';
		$checked				= get_post_meta( $post->ID, 'load_vzaar_media', true );
		echo '<input name="mediaburn_import" type="checkbox" id="mediaburn_import" value="1" ' . checked( $checked, 1, false ) . ' /> ';
		echo __( 'Load Vzaar Media', 'mediaburn-importer' );
		echo '</label>';
	}
}


// Start up this plugin
function MediaBurn_Importer() {
	if ( ! is_admin() )
		return; 

	require_once( 'class.options.php' );

	global $MBI_Settings;
	if ( is_null( $MBI_Settings ) )
		$MBI_Settings = new MBI_Settings();

	require_once( 'screen-meta-links.php' );

	global $MediaBurn_Importer;
	if ( is_null( $MediaBurn_Importer ) )
		$MediaBurn_Importer	= new MediaBurn_Importer();
}

add_action( 'plugins_loaded', 'MediaBurn_Importer' );

function mbi_save_post( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
		return;

	if ( ! is_numeric( $post_id ) )
		return;

	$post = get_post( $post_id );
	if ( ! in_array( $post->post_type, array( 'video', 'revision' ) ) )
		return;

	// the following replaces WPZooms mostly useless custom_add_save( $post_id );
	$fields = array(
		'wpzoom_is_featured',
		'wpzoom_post_template',
		'wpzoom_post_embed_location',
		'wpzoom_video_type',
		'wpzoom_post_embed_code',
		'wpzoom_post_embed_self',
		'wpzoom_post_embed_hd',
		'wpzoom_post_embed_skin',
	);

	foreach ( $fields as $field ) {
		if ( isset( $_POST[ $field ] ) )
			update_custom_meta( $post_id, $_POST[ $field ], $field );
		else
			delete_post_meta( $post_id, $field );
	}

	// check that post is wanting the MediaBurn Vzaar media imported
	if ( ! empty( $_POST['mediaburn-importer'] ) && ! wp_verify_nonce( $_POST['mediaburn-importer'], 'mediaburn_import' ) )
		return;

	// save checkbox or not
	$checked					= ( isset( $_POST['mediaburn_import'] ) && ! empty( $_POST['mediaburn_import'] ) ) ? 1 : 0;
	// update_post_meta( $post_id, 'load_vzaar_media', $checked );
	// one time run
	update_post_meta( $post_id, 'load_vzaar_media', false );

	if ( ! $checked )
		return;

	remove_action( 'save_post', 'mbi_save_post' );
	MediaBurn_Importer::load_vzaar_media( $post_id, true );
	
	add_action( 'save_post', 'mbi_save_post' );
}

add_action( 'save_post', 'mbi_save_post' );

?>