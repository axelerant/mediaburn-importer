<?php
/*
Plugin Name: MediaBurn Importer
Plugin URI: http://wordpress.org/extend/plugins/mediaburn-importer/
Description: Easily import thousands of media records from TYPO3 into WordPress.
Version: trunk
Author: Michael Cannon
Author URI: http://typo3vagabond.com/contact-typo3vagabond/
License: GPL2

Copyright 2012  Michael Cannon  (email : michael@typo3vagabond.com)

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

$mbi_disable_main				= true;
if ( ! $mbi_disable_main ) {
	// Load dependencies
	// TYPO3 includes for helping parse typolink tags
	include_once( 'lib/class.t3lib_div.php' );
	include_once( 'lib/class.t3lib_parsehtml.php' );
	include_once( 'lib/class.t3lib_softrefproc.php' );
}


/**
 * MediaBurn Importer
 *
 * @package mediaburn-importer
 */
class MediaBurn_Importer {
	private $errors					= array();
	private $menu_id;
	private $newline_typo3			= "\r\n";
	private $newline_carriage		= "\r";
	private $newline_linefeed		= "\n";
	private $newline_wp				= "\n\n";
	private $post_status_options	= array( 'draft', 'publish', 'pending', 'future', 'private' );
	private $postmap				= array();
	private $t3db					= null;
	private $t3db_host				= null;
	private $t3db_name				= null;
	private $t3db_password			= null;
	private $t3db_username			= null;
	private $typo3_url				= null;
	private $wpdb					= null;
	private $collection				= 'collection';
	private $production_date		= 'production_date';
	private $publication_date		= 'publication_date';
	private $producer				= 'producer';
	private $genre					= 'genre';
	private $language				= 'language';

	private $meta_key_author		= 't3:author.uid';
	private $meta_key_editor		= 't3:editor';
	private $meta_key_doc			= 't3:doc.uid';
	private $meta_key_mbr			= 't3:mbr.uid';
	private $meta_key_media			= 't3:media';
	private $meta_key_user			= 't3:fe_user.uid';
	private $vzaar_username			= null;
	private $mbi_disable_main		= null;

	private $init					= false;
	private $init_vzaar				= false;

	private $date_today				= null;

	// Plugin initialization
	public function __construct() {
		global $mbi_disable_main;

		$this->mbi_disable_main		= $mbi_disable_main;
		$this->date_today			= date( 'Y-m-d', current_time( 'timestamp' ) - ( 60 * 60 * 24 ) );

		// Capability check
		if ( ! current_user_can( 'manage_options' ) )
			return;

		if ( ! function_exists( 'admin_url' ) )
			return false;

		// Load up the localization file if we're using WordPress in a different language
		// Place it in this plugin's "localization" folder and name it "mediaburn-importer-[value in wp-config].mo"
		load_plugin_textdomain( 'mediaburn-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action( 'add_meta_boxes', array( &$this, 'mediaburn_import_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueues' ) );
		add_action( 'admin_init', array( &$this, 'init' ) );
		add_action( 'admin_menu', array( &$this, 'add_admin_menu' ) );
		add_action( 'wp_ajax_importtypo3record', array( &$this, 'ajax_process_record' ) );
		add_filter( 'plugin_action_links', array( &$this, 'add_plugin_action_links' ), 10, 2 );
		
		$this->options_link		= '<a href="'.get_admin_url().'options-general.php?page=mbi-options">'.__('Settings', 'mediaburn-importer').'</a>';
	}

	public function init() {
		$this->_create_db_client();
		$this->_get_custom_sql();
		$this->no_media_import	= get_mbi_options( 'no_media_import' );

		$this->init				= true;
	}

	public function init_vzaar() {
		// Vzaar connecters
		require_once( 'lib/vzaar/Vzaar.php' );

		$vzaar_application_token	= get_mbi_options( 'vzaar_application_token' );
		Vzaar::$token			= $vzaar_application_token;
		$this->vzaar_username	= get_mbi_options( 'vzaar_username' );
		Vzaar::$secret			= $this->vzaar_username;

		$this->init_vzaar			= true;
	}

	public function _create_alfresco_session() {
		// Alfresco connectors
		require_once( 'lib/Alfresco/Service/Session.php' );
		require_once( 'lib/Alfresco/Service/SpacesStore.php' );
		require_once( 'lib/Alfresco/Service/Node.php' );

		try {
			$alf_url			= get_mbi_options( 'alfresco_url' );
			$this->alfresco_url	= $alf_url;
			if ( ! isset($_SESSION['sessionDetails']) ) {
				$alf_user		= get_mbi_options( 'alfresco_username' );			
				$alf_password	= get_mbi_options( 'alfresco_password' );			
				$alf_url		.= '/api';
				$session		= Session::create( $alf_user, $alf_password, $alf_url );
				$_SESSION['sessionDetails'] = $session->sessionDetails;
			} else {
				$session		= Session::createFromSessionDetails($_SESSION['sessionDetails']);
			}

			$ticket				= null;
			if ( ! isset($_SESSION['ticket']) ) {
				$ticket			= $session->getTicket();
				$_SESSION['ticket']	= $ticket;	
			} else {
				$ticket			= $_SESSION['ticket']; 	
			}

			$this->ticket		= '?ticket=' . $ticket;

			return true;
		} catch (Exception $e) {
			die( sprintf( __( 'Could not log in to Alfresco Repository. Aborting. Exception: %s'), $e->getMessage() ) );
		}
	
		return false;
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
		$this->menu_id = add_management_page( __( 'MediaBurn Importer', 'mediaburn-importer' ), __( 'MediaBurn Importer', 'mediaburn-importer' ), 'manage_options', 'mediaburn-importer', array(&$this, 'user_interface') );

		add_action( 'admin_print_styles-' . $this->menu_id, array( &$this, 'styles' ) );
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

		// WordPress 3.1 vs older version compatibility
		if ( wp_script_is( 'jquery-ui-widget', 'registered' ) )
			wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'jquery-ui/jquery.ui.progressbar.min.js', __FILE__ ), array( 'jquery-ui-core', 'jquery-ui-widget' ), '1.8.6' );
		else
			wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'jquery-ui/jquery.ui.progressbar.min.1.7.2.js', __FILE__ ), array( 'jquery-ui-core' ), '1.7.2' );

		wp_enqueue_style( 'jquery-ui-mbiposts', plugins_url( 'jquery-ui/redmond/jquery-ui-1.7.2.custom.css', __FILE__ ), array(), '1.7.2' );
	}

	public function user_interface() {
		if ( ! $this->init )
			$this->init();

		echo <<<EOD
<div id="message" class="updated fade" style="display:none"></div>

<div class="wrap mbiposts">
	<div class="icon32" id="icon-tools"></div>
	<h2>
EOD;
	_e('MediaBurn Importer', 'mediaburn-importer');
	echo '</h2>';

		// If the button was clicked
		if ( ! empty( $_POST['mediaburn-importer'] ) || ! empty( $_REQUEST['posts'] ) || get_mbi_options( 'debug_mode' ) ) {
			if ( ! get_mbi_options( 'debug_mode' ) ) {
				// Form nonce check
				check_admin_referer( 'mediaburn-importer' );
			}

			// check that TYPO3 login information is valid
			if ( ! $this->mbi_disable_main || $this->check_typo3_access() ) {
				// Create the list of image IDs
				if ( ! empty( $_REQUEST['posts'] ) ) {
					$posts			= array_map( 'intval', explode( ',', trim( $_REQUEST['posts'], ',' ) ) );
					$count			= count( $posts );
					$posts			= implode( ',', $posts );
				} else {
					$posts			= array();
					$count			= 0;

					if ( get_mbi_options( 'custom_update' ) ) {
						if ( ! get_mbi_options( 'no_documents_import' ) ) {
							$documents		= $this->get_documents();

							foreach ( $documents as $document ) {
								$posts[]	= "{$document->uid}:dc";
								$count++;
							}
						}

						if ( ! get_mbi_options( 'no_mbrecords_import' ) ) {
							$medias			= $this->get_mbrecords();

							// Generate the list of IDs
							foreach ( $medias as $media ) {
								$posts[]	= "{$media->uid}:mc";
								$count++;
							}
						}
					} elseif ( ! get_mbi_options( 'load_vzaar_media_only' ) ) {
						if ( ! get_mbi_options( 'no_users_import' ) ) {
							$users			= $this->get_users();

							foreach ( $users as $user ) {
								$posts[]	= "{$user->uid}:u";

								$count++;
							}
						}

						if ( ! get_mbi_options( 'no_documents_import' ) ) {
							$documents		= $this->get_documents();

							foreach ( $documents as $document ) {
								$posts[]	= "{$document->uid}:d";
								$count++;
							}

							// doc relations/relinking
							$posts[]	= '0:dr';
							$count++;
						}

						if ( ! get_mbi_options( 'no_mbrecords_import' ) ) {
							$medias			= $this->get_mbrecords();

							// Generate the list of IDs
							foreach ( $medias as $media ) {
								$posts[]	= $media->uid;
								$count++;
							}
						}

						// update related_records once everything is imported
						if ( ! get_mbi_options( 'no_documents_import' ) 
							|| ! get_mbi_options( 'no_mbrecords_import' ) ) {
							$posts[]	= '0:r';
							$count++;

							// reset Sara's admin perms
							$posts[]	= '0:p';
							$count++;
						}
					} else {
						// Load Vzaar Media Only
						$records        = $this->get_vzaar_records();

						foreach ( $records as $post => $value ) {
							$posts[]    = $post . ':v';
							$count++;
						}   
					}

					if ( ! get_mbi_options( 'debug_mode' ) && ! $count ) {
						echo '	<p>' . _e( 'All done. No further media records to import.', 'mediaburn-importer' ) . "</p></div>";
						return;
					}
				}

				if ( get_mbi_options( 'debug_mode' ) ) {
					$mbr_to_import		= get_mbi_options( 'mbr_to_import' );
					if ( $mbr_to_import ) {
						$mbr_to_import	= explode( ',', $mbr_to_import );
						$mbr_to_import	= array_merge( $mbr_to_import, $posts );
					} else {
						$mbr_to_import	= $posts;
					}
					$mbr_to_import		= array_unique( $mbr_to_import );
					print_r($mbr_to_import); echo '<br />'; echo '' . __LINE__ . ':' . basename( __FILE__ )  . '<br />';	
					foreach ( $mbr_to_import as $key => $mbr_uid ) {
						$this->mbr_uid		= $mbr_uid;
						$this->ajax_process_record();
					}

					exit( __LINE__ . ':' . basename( __FILE__ ) . " ERROR<br />\n" );	
				}

				$posts			= array_unique( $posts );
				$posts			= "'" . implode( "','", $posts ) . "'";
				$this->show_status( $count, $posts );
				delete_transient( 'MediaBurn_Importer-done_uids' );
			} else {
				$this->show_errors();
			}
		} else {
			// No button click? Display the form.
			$this->show_greeting();
		}
		
		echo '</div>';
	}


	public function get_tt_news_posts() {
		// get post ids with tt_news:uids meta
		// $this->meta_key_doc			= 't3:doc.uid';
		// $done_uids			= $this->wpdb->get_col( "SELECT meta_value FROM {$this->wpdb->postmeta} WHERE meta_key = '{$this->meta_key_doc}'" );
		// $this->meta_key_mbr			= 't3:mbr.uid';
		// grab their original cataloguer details and 
		// production/publication date
	}


	public function get_vzaar_records() {
		// do wp query for video posts where vzaar embed isn't done yet
		// empty wpzoom_post_embed_code & wpzoom_video_type
		// if update_vzaar_media, then all videos
		$query					= array(
			'post_status'		=> 'publish',
			'post_type'			=> 'video',
			'meta_key'			=> 'control_number',
			'meta_value'		=> '',
			'meta_compare'		=> '!=',
			'orderby'			=> 'post_modified',
			'order'				=> 'DESC',
			'post__not_in'		=> array(),
		);

		$meta_query_today	= array(
			array(
				'key'			=> 'wpzoom_video_update',
				'type'			=> 'DATE',
				'value'			=> $this->date_today,
				'compare'		=> '=',
			),
		);

		$query['posts_per_page'] = 1;
		$query['meta_query']     = $meta_query_today; 
		$results                 = new WP_Query( $query );
		$query_wp                = $results->request;
		$query_wp                = preg_replace( '#\bLIMIT 0,.*#', '', $query_wp );
		$done_ids                = $this->wpdb->get_col( $query_wp );;
		unset( $query['meta_query'] );

		if ( ! empty( $done_ids ) )
			$query[ 'post__not_in' ]	= $done_ids;

		$meta_query				= array(
			array(
				'key'			=> 'wpzoom_post_embed_code',
				'value'			=> '',
				'compare'		=> '!=',
			),
			array(
				'key'			=> 'wpzoom_video_type',
				'value'			=> '',
				'compare'		=> '!=',
			),
		);
		$query['meta_query']	= $meta_query; 

		$update					= get_mbi_options( 'update_vzaar_media' );
		if ( ! $update ) {
			// remove those which already have entries
			$results  = new WP_Query( $query );
			$query_wp = $results->request;
			$query_wp = preg_replace( '#\bLIMIT 0,.*#', '', $query_wp );
			$done_ids = $this->wpdb->get_col( $query_wp );;
			foreach ( $done_ids as $id )
				$query[ 'post__not_in' ][]	= $id;
		}

		$query['meta_query']     = $meta_query; 
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

		$results_array = $this->wpdb->get_col( $query_wp );
		$results_array = array_flip( $results_array );

		return $results_array;
	}

	// t3db is the database connection
	// for TYPO3 there's no API
	// only database and url requests
	// should be used for db connection and establishing valid website url
	public function _create_db_client() {
		if ( null === $this->wpdb ) {
			global $wpdb;
			$this->wpdb			= $wpdb;
		}

		if ( $this->mbi_disable_main )
			return;

		if ( $this->t3db ) return;

		if ( is_null( $this->t3db_host ) ) {
			$this->typo3_url	 	= get_mbi_options( 'typo3_url' );
			$this->t3db_host		= get_mbi_options( 't3db_host' );
			$this->t3db_name		= get_mbi_options( 't3db_name' );
			$this->t3db_username	= get_mbi_options( 't3db_username' );
			$this->t3db_password	= get_mbi_options( 't3db_password' );
		}

		$this->t3db				= new wpdb($this->t3db_username, $this->t3db_password, $this->t3db_name, $this->t3db_host);
	}

	public function _get_custom_sql() {
		if ( $this->mbi_disable_main )
			return;

		$this->refresh_data		= get_mbi_options( 'refresh_data' );
		$this->mbr_custom_where	= get_mbi_options( 'mbr_custom_where' );
		$this->mbr_custom_order	= get_mbi_options( 'mbr_custom_order' );

		$this->mbr_to_import	= get_mbi_options( 'mbr_to_import' );
		if ( '' == $this->mbr_to_import ) {
			if ( ! $this->refresh_data ) {
				// poll already imported and skip those
				$done_uids		= get_transient( 'MediaBurn_Importer-done_uids' );
				if ( false === $done_uids ) {
					$done_uids	= $this->wpdb->get_col( "SELECT meta_value FROM {$this->wpdb->postmeta} WHERE meta_key = '{$this->meta_key_mbr}'" );
					set_transient( 'MediaBurn_Importer-done_uids', $done_uids, 60 * 60 );
				}

				if ( count( $done_uids ) ) {
					$done_uids	= array_unique( $done_uids );
					$this->mbr_custom_where	.= " AND tx_tyfrescomedia_mediaburnRecord.uid NOT IN ( " . implode( ',', $done_uids ) . " ) ";
				}
			}
		} else {
			$this->mbr_custom_where	= " AND tx_tyfrescomedia_mediaburnRecord.uid IN ( " . $this->mbr_to_import . " ) ";
		}

		$this->mbr_to_skip			= get_mbi_options( 'mbr_to_skip' );
		if ( '' != $this->mbr_to_skip ) {
			$this->mbr_custom_where	.= " AND tx_tyfrescomedia_mediaburnRecord.uid NOT IN ( " . $this->mbr_to_skip . " ) ";
		}
	}

	public function check_typo3_access() {
		if ( $this->mbi_disable_main )
			return true;

		if ( ! $this->typo3_url ) {
			$this->errors[] 			= __( "TYPO3 website URL is missing", 'mediaburn-importer' );
		}

		if ( ! $this->t3db_host ) {
			$this->errors[] 			= __( "TYPO3 database host is missing", 'mediaburn-importer' );
		}

		if ( ! $this->t3db_name ) {
			$this->errors[] 			= __( "TYPO3 database name is missing", 'mediaburn-importer' );
		}

		if ( ! $this->t3db_username ) {
			$this->errors[] 			= __( "TYPO3 database username is missing", 'mediaburn-importer' );
		}

		if ( ! $this->t3db_password ) {
			$this->errors[] 			= __( "TYPO3 database password is missing", 'mediaburn-importer' );
		}
	
		if ( ! count( $this->errors ) ) {
			return true;
		}

		return false;
	}

	public function show_errors() {
		echo '<h3>';
		_e( 'Errors found, see below' , 'mediaburn-importer');
		echo '</h3>';
		echo '<ul class="error">';
		foreach ( $this->errors as $key => $error ) {
			echo '<li>' . $error . '</li>';
		}
		echo '</ul>';
		echo '<p>' . sprintf( __( 'Please review your %s before proceeding.', 'mediaburn-importer' ), $this->options_link ) . '</p>';
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

	<p><input type="button" class="button hide-if-no-js" name="mbiposts-stop" id="mbiposts-stop" value="<?php _e( 'Abort Importing TYPO3 Media', 'mediaburn-importer' ) ?>" /></p>

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

	<p><?php _e( "Use this tool to import media records from TYPO3 into WordPress.", 'mediaburn-importer' ); ?></p>

	<p><?php printf( __( 'Please review your %s before proceeding.', 'mediaburn-importer' ), $this->options_link ); ?></p>

	<p><?php _e( 'To begin, click the button below.', 'mediaburn-importer ', 'mediaburn-importer'); ?></p>

	<p><input type="submit" class="button hide-if-no-js" name="mediaburn-importer" id="mediaburn-importer" value="<?php _e( 'Import TYPO3 Media', 'mediaburn-importer' ) ?>" /></p>

	<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'mediaburn-importer' ) ?></em></p></noscript>

	</form>
<?php
		$copyright				= '<div class="copyright">Copyright %s <a href="http://typo3vagabond.com">TYPO3Vagabond.com.</a></div>';
		$copyright				= sprintf( $copyright, date( 'Y' ) );
		echo $copyright;
	}

	// Process a single image ID (this is an AJAX handler)
	public function ajax_process_record() {
		if ( ! $this->init )
			$this->init();

		if ( ! get_mbi_options( 'debug_mode' ) ) {
			error_reporting( 0 ); // Don't break the JSON result
			header( 'Content-type: application/json' );

			// record_id:type_of_record
			$parts				= $_REQUEST['id'];
		} else {
			$parts				= $this->mbr_uid;
		}

		if ( ! get_mbi_options( 'debug_mode' ) ) {
			error_log( $parts );
		} else {
			print_r($parts); echo '<br />'; echo '' . __LINE__ . ':' . basename( __FILE__ )  . '<br />';	
		}

		$orig_mbr_uid			= $parts;
		$parts					= explode( ':', $parts );
		$type					= isset( $parts[1] ) ? $parts[1] : false;
		$this->mbr_uid			= (int) $parts[0];

		$process_type			= '';
		// handle for video/doc ids and grab the record from TYPO3
		if ( false === $type ) {
			$mbr				= $this->get_mbrecord( $this->mbr_uid );
			$process_type		= 'post';
		} elseif ( 'd' == $type ) {
			$mbr				= $this->get_document( $this->mbr_uid );
			$process_type		= 'post';
		} elseif ( 'mc' == $type ) {
			$mbr				= $this->get_mbrecord( $this->mbr_uid );
			$process_type		= 'custom';
		} elseif ( 'dc' == $type ) {
			$mbr				= $this->get_document( $this->mbr_uid );
			$process_type		= 'custom';
		} elseif ( 'u' == $type ) {
			$mbr				= $this->get_user( $this->mbr_uid );
			$process_type		= 'user';
		} elseif ( 'dr' == $type ) {
			$mbr				= $this->document_relate_items();
			$process_type		= 'document_relate_items';
		} elseif ( 'r' == $type ) {
			$mbr				= $this->relate_items();
			$process_type		= 'relate_items';
		} elseif ( 'p' == $type ) {
			wp_update_user( array( 'ID' => 2, 'role' => 'administrator' ) );
			$process_type		= 'permissions';
		} elseif ( 'v' == $type ) {
			$process_type		= 'vzaar';
		} else {
			die( json_encode( array( 'error' => sprintf( __( "Undefined type: %s", 'mediaburn-importer' ), esc_html( $orig_mbr_uid ) ) ) ) );
		}

		switch( $process_type ) {
			case 'custom':
				if ( ! is_array( $mbr ) || $mbr['itemid'] != $this->mbr_uid ) {
					if ( ! get_mbi_options( 'debug_mode' ) ) {
						error_log( $orig_mbr_uid . '.' . $this->mbr_uid );
						error_log( print_r( $mbr, true ) );
						die( json_encode( array( 'error' => sprintf( __( "Failed import: %s isn't a TYPO3 media record.", 'mediaburn-importer' ), esc_html( $orig_mbr_uid ) ) ) ) );
					} else {
						echo sprintf( __( "Failed import: %s isn't a TYPO3 media record.", 'mediaburn-importer' ), esc_html( $_REQUEST['id'] ) ) . '<br />';
					}
				}

				$post_id 		= $this->update_author_date( $mbr );

				if ( ! get_mbi_options( 'debug_mode' ) )
					die( json_encode( array( 'success' => sprintf( __( '&quot;<a href="%1$s" target="_blank">%2$s</a>&quot; Post ID %3$s was successfully processed in %4$s seconds.', 'mediaburn-importer' ), get_permalink( $post_id ), esc_html( get_the_title( $post_id ) ), $post_id, timer_stop() ) ) ) );
				else
					echo sprintf( __( '&quot;<a href="%1$s" target="_blank">%2$s</a>&quot; Post ID %3$s was successfully processed in %4$s seconds.', 'mediaburn-importer' ), get_permalink( $post_id ), esc_html( get_the_title( $post_id ) ), $post_id, timer_stop() ) . '<br />';
				break;

			case 'post':
				if ( ! $this->_create_alfresco_session() ) {
					die( json_encode( array( 'error' => sprintf( __( 'Unable to connect to Alfresco. Please check your %s', 'mediaburn-importer' ), $this->options_link ) ) ) );
				}

				if ( ! is_array( $mbr ) || $mbr['itemid'] != $this->mbr_uid ) {
					if ( ! get_mbi_options( 'debug_mode' ) ) {
						error_log( $orig_mbr_uid . '.' . $this->mbr_uid );
						error_log( print_r( $mbr, true ) );
						die( json_encode( array( 'error' => sprintf( __( "Failed import: %s isn't a TYPO3 media record.", 'mediaburn-importer' ), esc_html( $orig_mbr_uid ) ) ) ) );
					} else {
						echo sprintf( __( "Failed import: %s isn't a TYPO3 media record.", 'mediaburn-importer' ), esc_html( $_REQUEST['id'] ) ) . '<br />';
					}
				}

				$this->featured_image_id	= false;

				// process and import media post
				$post_id 		= $this->import_media_as_post( $mbr );

				// replace original external images with internal
				$this->_typo3_replace_images( $post_id );

				// Handle all the metadata for this post
				$this->insert_postmeta( $post_id, $mbr );

				$this->load_vzaar_media( $post_id );

				if ( get_mbi_options( 'set_featured_image' ) && $this->featured_image_id ) {
					update_post_meta( $post_id, '_thumbnail_id', $this->featured_image_id );
				}

				if ( ! get_mbi_options( 'debug_mode' ) )
					die( json_encode( array( 'success' => sprintf( __( '&quot;<a href="%1$s" target="_blank">%2$s</a>&quot; Post ID %3$s was successfully processed in %4$s seconds.', 'mediaburn-importer' ), get_permalink( $post_id ), esc_html( get_the_title( $post_id ) ), $post_id, timer_stop() ) ) ) );
				else
					echo sprintf( __( '&quot;<a href="%1$s" target="_blank">%2$s</a>&quot; Post ID %3$s was successfully processed in %4$s seconds.', 'mediaburn-importer' ), get_permalink( $post_id ), esc_html( get_the_title( $post_id ) ), $post_id, timer_stop() ) . '<br />';
				break;

			case 'user':
				$user_id 		= $this->lookup_author( $mbr['author_email'], $mbr['full_name'], $mbr );
				if ( ! get_mbi_options( 'debug_mode' ) )
					die( json_encode( array( 'success' => sprintf( __( '&quot;<a href="%1$s" target="_blank">%1$s</a>&quot; User ID %2$s was successfully processed in %3$s seconds.', 'mediaburn-importer' ), get_author_posts_url( $user_id ), $user_id, timer_stop() ) ) ) );
				else
					echo sprintf( __( '&quot;<a href="%1$s" target="_blank">%1$s</a>&quot; User ID %2$s was successfully processed in %3$s seconds.', 'mediaburn-importer' ), get_author_posts_url( $user_id ), $user_id, timer_stop() ) . '<br />';
				break;

			case 'document_relate_items':
				if ( ! get_mbi_options( 'debug_mode' ) )
					die( json_encode( array( 'success' => __( 'Document relation records from TYPO3 have been linked to their WordPress objects.' ) ) ) );
				else
					echo __( 'Document relation records from TYPO3 have been linked to their WordPress objects.' ) . '<br />';
				break;

			case 'relate_items':
				if ( ! get_mbi_options( 'debug_mode' ) )
					die( json_encode( array( 'success' => __( 'Related records from TYPO3 have been linked to their WordPress objects.' ) ) ) );
				else
					echo __( 'Related records from TYPO3 have been linked to their WordPress objects.' ) . '<br />';
				break;

			case 'permissions':
				if ( ! get_mbi_options( 'debug_mode' ) )
					die( json_encode( array( 'success' => __( 'Sara granted Administrator access.' ) ) ) );
				else
					echo __( 'Sara granted Administrator access.' ) . '<br />';
				break;

			case 'vzaar':
				$post_id		= $this->mbr_uid;
				$this->load_vzaar_media( $post_id, true );

				if ( ! get_mbi_options( 'debug_mode' ) )
					die( json_encode( array( 'success' => sprintf( __( '&quot;<a href="%1$s" target="_blank">%2$s</a>&quot; Post ID %3$s was successfully processed in %4$s seconds.', 'mediaburn-importer' ), get_permalink( $post_id ), esc_html( get_the_title( $post_id ) ), $post_id, timer_stop() ) ) ) );
				else
					echo sprintf( __( '&quot;<a href="%1$s" target="_blank">%2$s</a>&quot; Post ID %3$s was successfully processed in %4$s seconds.', 'mediaburn-importer' ), get_permalink( $post_id ), esc_html( get_the_title( $post_id ) ), $post_id, timer_stop() ) . '<br />';
				break;

			default:
				if ( ! get_mbi_options( 'debug_mode' ) )
					die( json_encode( array( 'success' => __('Something was done') ) ) );
				else
					echo __('Something was done') . '<br />';
				break;
		}
	}

	public function update_author_date( $mbr ) {
		// lookup or create author for posts
		$post_author      		= $this->lookup_author( $mbr['author_email'], $mbr['author'] );
		$post_type				= $mbr['post_type'];
		$postdata				= compact( 'post_author' );

		if ( 'video' == $post_type ) {
			$post_key			= $this->meta_key_mbr;
		} else {
			$post_key			= $this->meta_key_doc;
		}

		$post_id				= $this->get_wp_post_ID( $mbr['itemid'], $post_key );

		if ( ! $post_id ) {
			$post_id			= 0;
		} else {
			$postdata['ID']		= $post_id;
			$post_id			= wp_update_post( $postdata );
		}

		if ( is_wp_error( $post_id ) ) {
			if ( 'empty_content' == $post_id->getErrorCode() )
				return; // Silent skip on "empty" posts
		}

		if ( 'video' == $post_type ) {
			wp_set_post_terms( $post_id, $mbr['meta']['production_date'], $this->production_date, true );
		} else {
			wp_set_post_terms( $post_id, $mbr['meta']['publication_date'], $this->publication_date, true );
		}

		return $post_id;
	}

	public function load_vzaar_media( $post_id, $force = false ) {
		if ( ! $this->init )
			$this->init();

		if ( ! $this->init_vzaar )
			$this->init_vzaar();

		$vzaar_id_found			= false;
		$vzaar_id				= get_post_meta( $post_id, 'vzaar_id', true );

		if ( ! $vzaar_id || $force ) {
			// use control number to get video id from Vzaar
			// _ helps ensure title lookup is unique
			$control_number		= get_post_meta( $post_id, 'control_number', true );
			if ( ! $control_number )
				return;

			$title_lookup		= $control_number . '_';
			$videos				= Vzaar::searchVideoList( $this->vzaar_username, true, $title_lookup );

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
			delete_post_meta( $post_id, 'wpzoom_video_update' );
			add_post_meta( $post_id, 'wpzoom_video_update', $this->date_today );

			$this->delete_old_thumbnail( $post_id );

			// get thumbnail from Vzaar
			$video_image_url			= $video->framegrabUrl;
			$file						= $slug . '-video-thumbnail.jpg';
			$this->featured_image_id	= $this->_import_attachment( $post_id, $file, $video_image_url, $slug );
		}

		if ( $force && get_mbi_options( 'set_featured_image' ) && $this->featured_image_id ) {
			update_post_meta( $post_id, '_thumbnail_id', $this->featured_image_id );
		}
	}

	public function delete_old_thumbnail( $post_id ) {
		$thumbnail_ids			= get_post_meta( $post_id, '_thumbnail_id' );

		foreach ( $thumbnail_ids as $key => $thumbnail_id ) {
			if ( $thumbnail_id ) {
				wp_delete_attachment( $thumbnail_id, true );
			}
		
			delete_post_meta( $post_id, '_thumbnail_id', $thumbnail_id );
		}
	}

	// Gets the post_ID that a TYPO3 post has been saved as within WP
	public function get_wp_post_ID( $post, $post_key = false ) {
		if ( ! $post_key ) {
			$post_key			= $this->meta_key_mbr;
		}

		if ( empty( $this->postmap[$post] ) ) {
		 	$this->postmap[$post]	= (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT post_id FROM {$this->wpdb->postmeta} WHERE meta_key = '{$post_key}' AND meta_value = %d", $post ) );
		}

		return $this->postmap[$post];
	}

	public function import_media_as_post( $mbr ) {
		// lookup or create author for posts
		$post_author      		= $this->lookup_author( $mbr['author_email'], $mbr['author'] );

		if ( in_array( $mbr['status'], $this->post_status_options ) ) {
			$post_status		= $mbr['status'];
		} else {
			$post_status		= 'draft';
		}

		// leave draft's alone to prevent publishing something that had been hidden on TYPO3
		$force_post_status		= get_mbi_options( 'force_post_status' );
		if ( 'default' != $force_post_status && 'draft' != $post_status ) {
			$post_status      	= $force_post_status;
		}

		$post_password    		= '';
		$post_category			= $mbr['category'];
		$post_type				= $mbr['post_type'];
		$post_date				= $mbr['datetime'];
		$post_title				= $mbr['title'];

		// Clean up content
		$post_content			= $this->_prepare_content( $mbr['description'] );
		if ( preg_match( "#^\d\d:\d\d\s#", $post_content ) ) {
			$post_excerpt		= '';
		} else {
			$post_content_array	= explode( $this->newline_wp, $post_content );
			// The first non-timestamp paragraph of $post_content is excerpt
			$post_excerpt		= array_shift( $post_content_array );
			$post_content		= implode( $this->newline_wp, $post_content_array );
			$post_content		= trim( $post_content );
		}

		// Handle any tags associated with the post
		$tags_input				= ! empty( $mbr['meta']['keywords'] ) ? $mbr['meta']['keywords'] : '';

		// Check if comments are closed on this post
		$comment_status			= $mbr['comments'];
		$ping_status			= 'closed';

		// add slug AKA url
		$post_name				= $mbr['slug'];

		$postdata				= compact( 'post_author', 'post_date', 'post_content', 'post_title', 'post_status', 'post_password', 'tags_input', 'comment_status', 'ping_status', 'post_excerpt', 'post_category', 'post_name', 'post_type' );

		if ( 'video' == $post_type ) {
			wp_update_user( array( 'ID' => $post_author, 'role' => 'editor' ) );
			$post_key			= $this->meta_key_mbr;
		} else {
			$post_key			= $this->meta_key_doc;
		}

		$post_id				= $this->get_wp_post_ID( $mbr['itemid'], $post_key );

		if ( ! $post_id ) {
			$post_id			= wp_insert_post( $postdata, true );
		} else {
			$postdata['ID']		= $post_id;
			$post_id			= wp_update_post( $postdata );
		}

		if ( is_wp_error( $post_id ) ) {
			if ( 'empty_content' == $post_id->getErrorCode() )
				return; // Silent skip on "empty" posts
		}

		update_post_meta( $post_id, $post_key, $mbr['itemid'] );

		return $post_id;
	}

	public function insert_postmeta( $post_id, $post ) {
		if ( ! is_array( $post['meta'] ) )
			return;

		$control_number			= $post['meta']['control_number'];
		$slug					= $post['meta']['slug'];

		foreach ( $post['meta'] as $prop => $value ) {
			if ( is_string( $value ) )
				$value			= trim( $value );

			if ( ! empty( $value ) ) {
				$add_post_meta	= false;

				switch ( $prop ) {
					case 'slug':
						break;

					case 'collection_name':
					case 'collections':
						wp_set_post_terms( $post_id, $value, $this->collection, true );
						break;

					case 'production_date':
						wp_set_post_terms( $post_id, $value, $this->production_date, true );
						break;

					case 'publication_date':
						wp_set_post_terms( $post_id, $value, $this->publication_date, true );
						break;

					case 'genres':
						wp_set_post_terms( $post_id, $value, $this->genre, true );
						break;

					case 'language':
						wp_set_post_terms( $post_id, $value, $this->language, true );
						break;

					case 'producers':
						wp_set_post_terms( $post_id, $value, $this->producer, true );
						break;

					case 'keywords':
						update_post_meta( $post_id, '_aioseop_keywords', $value );
						update_post_meta( $post_id, '_headspace_keywords', $value );
						update_post_meta( $post_id, '_yoast_wpseo_metakeywords', $value );
						update_post_meta( $post_id, 'bizzthemes_keywords', $value );
						update_post_meta( $post_id, 'thesis_keywords', $value );
						break;

					case 'flash_video_thumbnail':
					case 'video_thumbnail':
						$prop			= 't3:' . $prop;
						$exist			= get_post_meta( $post_id, $prop, true );
						if ( empty( $exist ) ) {
							// bring thumbnails in from Alfresco
							$type				= '.jpg';
							if ( $this->_typo3_import_thumbnails( $post_id, $value, $control_number, $type, $slug ) )
								$add_post_meta	= true;
						}
						break;

					case 'audio_assets':
						// none to worry about
					case 'video_assets':
					case 'uuid':
						// will be added outside of this import
						$prop			= 't3:' . $prop;
						$add_post_meta	= true;
						break;

					case 'document_asset':
						$prop			= 't3:' . $prop;
						$exist			= get_post_meta( $post_id, $prop, true );
						if ( empty( $exist ) ) {
							// pull document_asset from Alfresco
							// seems that documents are pdf
							$type				= '.pdf';
							if ( $this->_typo3_append_file( $post_id, $value, $control_number, $type, $slug ) )
								$add_post_meta	= true;
						}
						break;

					case 'related_records':
						update_post_meta( $post_id, 'related_records', $value );
						break;

					default:
						$add_post_meta	= true;
						break;
				}

				if ( $add_post_meta ) {
					update_post_meta( $post_id, $prop, $value );
				}
			}
		}
	}

	public function document_relate_items() {
		$query					= "SELECT post_id FROM {$this->wpdb->postmeta} WHERE meta_key = 'document_assets' AND meta_value <> ''";
		$posts					= $this->wpdb->get_col( $query );

		foreach ( $posts as $post_id ) {
			$doc_archive		= get_post_meta( $post_id, 'document_assets_archive', true );
			if ( ! empty( $doc_archive ) )
				continue;

			$document_assets	= get_post_meta( $post_id, 'document_assets', true );
			$values				= explode( ',', $document_assets );
			$new_doc_assets		= array();
			foreach ( $values as $uid ) {
				$args			= array(
					'post_type'				=> 'document',
					'meta_key'				=> 't3:doc.uid',
					'meta_value'			=> $uid,
				);
				$query						= new WP_Query( $args );
				if ( $query->have_posts() ) {
					$query->the_post();
					$new_doc_assets[]		= get_the_ID();
				}
			}
			$new_doc_assets		= implode( ',', $new_doc_assets );

			update_post_meta( $post_id, 'document_assets', $new_doc_assets );
			update_post_meta( $post_id, 'document_assets_archive', $document_assets );
		}
	}

	public function relate_items() {
		$query					= "SELECT post_id FROM {$this->wpdb->postmeta} WHERE meta_key = 'related_records' AND meta_value <> ''";
		$posts					= $this->wpdb->get_col( $query );

		foreach ( $posts as $post_id ) {
			$records			= get_post_meta( $post_id, 'related_records', true );
			$values				= explode( ',', $records );

			$related			= array();
			foreach ( $values as $uid ) {
				$related[]		= $this->get_wp_post_ID( $uid );
			}
			$related			= implode( ',', $related );

			update_post_meta( $post_id, 'related_items', $related );
		}
	}

	public function _typo3_import_thumbnails( $post_id, $uuid, $control_number, $type, $slug ) {
		if ( $this->no_media_import )
			return false;

		$attach_id				= $this->_import_alfresco_media( $post_id, $uuid, $control_number, $type, $slug );
		update_post_meta( $attach_id, $this->meta_key_media, $attach_id );

		if ( ! $this->featured_image_id ) {
			$this->featured_image_id	= $attach_id;
		}

		return $attach_id;
	}

	public function _typo3_append_file( $post_id, $uuid, $control_number, $type, $slug ) {
		if ( $this->no_media_import )
			return false;

		// create header
		$tag					= get_mbi_options( 'related_files_header_tag' );
		$new_content			= ( $tag ) ? '<h' . $tag . '>' : '';
		$new_content			.= __(  get_mbi_options( 'related_files_header' ), 'mediaburn-importer');
		$new_content			.= ( $tag ) ? '</h' . $tag . '>' : '';

		$wrap					= get_mbi_options( 'related_files_wrap' );
		$wrap					= explode( '|', $wrap );
		$new_content			.= ( isset( $wrap[0] ) ) ? $wrap[0] : '';

		// then create ul/li list of links
		$new_content			.= '<ul>';

		$attach_id				= $this->_import_alfresco_media( $post_id, $uuid, $control_number, $type, $slug );
		update_post_meta( $attach_id, $this->meta_key_media, $attach_id );
		$attach_src				= wp_get_attachment_url( $attach_id );

		// link title is either link base or some title text
		$download_text			= get_mbi_options( 'download_text' );
		$download_text			= sprintf( $download_text, $file );

		$new_content			.= '<li>';
		$new_content			.= '<a href="' . $attach_src . '" title="' . $download_text . '">' . $download_text . '</a>';
		$new_content			.= '</li>';

		$viewer					= get_mbi_options( 'google_docs_viewer_text' );
		$google_url				= get_mbi_options( 'google_docs_viewer_url' );
		$viewer_width			= get_mbi_options( 'google_docs_viewer_width' );
		$viewer_height			= $viewer_width * 1.3;
		$new_content			.= '<li>';
		$viewer_title			= sprintf( $viewer, $file );
		$google_url				.= urlencode( $attach_src );
		$new_content			.= '<a href="' . $google_url . '" title="' . $viewer_title . '">' . $viewer_title . '</a>';
		$new_content			.= '</li>';

		$new_content			.= '</ul>';
		$new_content			.= ( isset( $wrap[1] ) ) ? $wrap[1] : '';

		update_post_meta( $post_id, 'document_options', $new_content );

		// embed
		// <iframe src="http://docs.google.com/viewer?url=http%3A%2F%2Ffitv.localhost%2Fwp-content%2Fuploads%2F2012%2F01%2F0003.00021.pdf&embedded=true" width="600" height="780" style="border: none;"></iframe>
		$embed					= '<iframe src="' . $google_url . '&embedded=true" width="' . $viewer_width . '" height="' . $viewer_height . '" style="border: none;"></iframe>';
		update_post_meta( $post_id, 'document_embed', $embed );

		return $attach_id;
	}

	public function _import_alfresco_media( $post_id, $uuid, $control_number, $type, $slug ) {
		$original_file_uri		= "{$this->alfresco_url}/d/d/workspace/SpacesStore/{$uuid}/{$control_number}{$type}{$this->ticket}";
		$file					= $slug . $type;
		
		return $this->_import_attachment( $post_id, $file, $original_file_uri, $slug );
	}

	public function _import_attachment( $post_id, $file, $original_file_uri, $slug ) {
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

	// check for images in post_content
	public function _typo3_replace_images( $post_id ) {
		if ( $this->no_media_import )
			return false;

		$post					= get_post( $post_id );
		$post_content			= $post->post_content;

		if ( ! stristr( $post_content, '<img' ) ) {
			return;
		}

		// pull images out of post_content
		$doc					= new DOMDocument();
		if ( ! $doc->loadHTML( $post_content ) ) {
			return;
		}

		// grab img tag, src, title
		$image_tags				= $doc->getElementsByTagName('img');

		foreach ( $image_tags as $image ) {
			$src				= $image->getAttribute('src');
			$title				= $image->getAttribute('title');
			$alt				= $image->getAttribute('alt');

			// ignore file:// sources, they're none existant except on original 
			// computer
			$str_file			= 'file://';
			// disable image
			if ( 0 === strncasecmp( $str_file, $src, strlen( $str_file ) ) ) {
				$dom			= new DOMDocument;
				$node			= $dom->importNode( $image, true );
				$dom->appendChild( $node );
				$image_tag		= $dom->saveHTML();

				$find			= trim( $image_tag );
				$find2			= preg_replace( '#">$#', '" />', $find );
				$replace		= str_replace( '<img', '<img style="display: none;"', $find );
				$replace2		= str_replace( '<img', '<img style="display: none;"', $find2 );
				$post_content	= str_ireplace( array( $find, $find2 ), array( $replace, $replace2), $post_content );

				continue;
			}

			// check that src is locally referenced to post 
			if ( preg_match( '#^(https?://[^/]+/)#i', $src, $matches ) ) {
				if ( $matches[0] != $this->typo3_url ) {
					// external src, ignore importing
					continue;
				} else {
					// internal src, prep for importing
					$src		= str_ireplace( $this->typo3_url, '', $src );
				}
			}

			// try to figure out longest amount of the caption to keep
			// push src, title to like images, captions arrays
			if ( $title == $alt 
				|| strlen( $title ) > strlen( $alt ) ) {
				$caption	= $title;
			} elseif ( $alt ) {
				$caption	= $alt;
			} else {
				$caption	= '';
			}

			$file				= basename( $src );
			$original_file_uri	= $this->typo3_url . $src;

			// TODO file already uploaded
			$file_found			= false;
			if ( $file_found ) {
				continue;
			}

			$file_move			= wp_upload_bits($file, null, file_get_contents($original_file_uri));
			$filename			= $file_move['file'];

			$title				= $caption ? $caption : sanitize_title_with_dashes($file);

			$wp_filetype		= wp_check_filetype($file, null);
			$attachment			= array(
				'post_content'		=> '',
				'post_excerpt'		=> $caption,
				'post_mime_type'	=> $wp_filetype['type'],
				'post_status'		=> 'inherit',
				'post_title'		=> $title,
			);
			$attach_id			= wp_insert_attachment( $attachment, $filename, $post_id );

			if ( ! $this->featured_image_id ) {
				$this->featured_image_id	= $attach_id;
			}

			$attach_data		= wp_generate_attachment_metadata( $attach_id, $filename );
			wp_update_attachment_metadata( $attach_id, $attach_data );
			$attach_src			= wp_get_attachment_url( $attach_id );

			// then replace old image src with new in post_content
			$post_content		= str_ireplace( $src, $attach_src, $post_content );
		}

		$post					= array(
			'ID'			=> $post_id,
			'post_content'	=> $post_content
		);
	 
		wp_update_post( $post );
	}

	public function insert_postimages($post_id, $images, $captions = '') {
		if ( $this->no_media_import )
			return false;

		$post					= get_post( $post_id );
		$post_content			= $post->post_content;

		// images is a CSV string, convert images to array
		$images					= explode( ",", $images );
		$image_count			= count( $images );
		$captions				= explode( "\n", $captions );
		$uploads_dir_typo3		= $this->typo3_url . 'uploads/';

		// cycle through to create new post attachments
		foreach ( $images as $key => $file ) {
			// cp image from A to B
			// @ref http://codex.wordpress.org/Function_Reference/wp_upload_bits
			// $upload = wp_upload_bits($_FILES["field1"]["name"], null, file_get_contents($_FILES["field1"]["tmp_name"]));
			$original_file_uri	= $uploads_dir_typo3 . 'pics/' . $file;
			$file_move			= wp_upload_bits($file, null, file_get_contents($original_file_uri));
			$filename			= $file_move['file'];

			// @ref http://codex.wordpress.org/Function_Reference/wp_insert_attachment
			$caption			= isset($captions[$key]) ? $captions[$key] : '';
			$title				= $caption ? $caption : sanitize_title_with_dashes($file);

			$wp_filetype		= wp_check_filetype($file, null);
			$attachment			= array(
				'post_content'		=> '',
				'post_excerpt'		=> $caption,
				'post_mime_type'	=> $wp_filetype['type'],
				'post_status'		=> 'inherit',
				'post_title'		=> $title,
			);
			$attach_id			= wp_insert_attachment( $attachment, $filename, $post_id );

			if ( ! $this->featured_image_id ) {
				$this->featured_image_id	= $attach_id;
			}

			$attach_data		= wp_generate_attachment_metadata( $attach_id, $filename );
			wp_update_attachment_metadata( $attach_id, $attach_data );
		}

		// insert [gallery] into content after the second paragraph
		$post_content_array		= explode( $this->newline_wp, $post_content );
		$post_content_arr_size	= sizeof( $post_content_array );
		$new_post_content		= '';
		$gallery_code			= '[gallery]';
		$gallery_inserted		= false;

		// don't give single image galleries
		if ( 1 == $image_count && get_mbi_options( 'set_featured_image' ) ) {
			$gallery_code			= '';
		}

		$insert_gallery_shortcut	= get_mbi_options( 'insert_gallery_shortcut' );

		for ( $i = 0; $i < $post_content_arr_size; $i++ ) {
			if ( $insert_gallery_shortcut != $i ) {
				$new_post_content	.= $post_content_array[$i] . "{$this->newline_wp}";
			} else {
				if ( $insert_gallery_shortcut != 0 && $insert_gallery_shortcut == $i ) {
					$new_post_content	.= "{$gallery_code}{$this->newline_wp}";
					$gallery_inserted	= true;
				}

				$new_post_content	.= $post_content_array[$i] . "{$this->newline_wp}";
			}
		}

		if ( ! $gallery_inserted && 0 != $insert_gallery_shortcut ) {
			$new_post_content	.= $gallery_code;
		}
		
		$post					= array(
			'ID'			=> $post_id,
			'post_content'	=> $new_post_content
		);
	 
		wp_update_post( $post );
	}

	// Clean up content
	public function _prepare_content( $content ) {
		// convert LINK tags to A
		$content				= $this->_typo3_api_parse_typolinks($content);
		// remove broken link spans
		$content				= $this->_typo3_api_parse_link_spans($content);
		// clean up code samples
		$content				= $this->_typo3_api_parse_pre_code($content);
		// return carriage and newline used as line breaks, consolidate
		$content				= str_replace( $this->newline_typo3, $this->newline_wp, $content );
		$content				= str_replace( $this->newline_carriage, $this->newline_wp, $content );
		$content				= str_replace( $this->newline_linefeed, $this->newline_wp, $content );
		$content				= preg_replace( "#({$this->newline_wp})+#", $this->newline_wp, $content );
		// lowercase closing tags
		$content				= preg_replace_callback( '|<(/?[A-Z]+)|', array( &$this, '_normalize_tag' ), $content );
		// XHTMLize some tags
		$content				= str_replace( '<br>', '<br />', $content );
		$content				= str_replace( '<hr>', '<hr />', $content );

		// process read more linking
		$morelink_code			= '<!--more-->';
		
		// t3-cut ==>  <!--more-->
		$content				= preg_replace( '#<t3-cut text="([^"]*)">#is', $morelink_code, $content );
		$content				= str_replace( array( '<t3-cut>', '</t3-cut>' ), array( $morelink_code, '' ), $content );

		// remove line leading spaces
		$content				= preg_replace( "#{$this->newline_wp} +#is", $this->newline_wp, $content );

		// try to keep time stamps on own line
		$content				= preg_replace( "#( |\.)(\d+(:\d{2})+\s)#is", "$1{$this->newline_wp}$2", $content );

		// trim lines within content
		$content				= preg_replace( "# +{$this->newline_wp} +#is", $this->newline_wp, $content );
	
		$content				= trim( $content );

		return $content;
	}

	public function _normalize_tag( $matches ) {
		return '<' . strtolower( $matches[1] );
	}

	public function lookup_author( $author_email, $author, $meta = null ) {
		$author_email			= trim( $author_email );

		$author					= trim( $author );
		$author					= preg_replace( "#^By:? #i", '', $author );
		$author					= ucwords( strtolower( $author ) );
		$author					= str_replace( ' And ', ' and ', $author );

		// there's no information to create an author from
		if ( '' == $author_email && '' == $author ) {
			$default_author		= get_mbi_options( 'default_author' );

			if ( $default_author )
				return $default_author;
			else
				return false;
		}

		$username			= isset( $meta['username'] ) && ! empty( $meta['username'] ) ? $meta['username'] : false;

		// create unique emails for no email authors
		if ( '' != $author_email ) {
			$no_email			= false;
			$username			= $username ? $username : $this->_create_username( $author );
		} else {
			$no_email			= true;
			$domain				= preg_replace( '#(https?://)([^/]+)/#', '\2', $this->typo3_url );
			$domain				= trim( $domain );
			$username			= $username ? $username : $this->_create_username( $author );
			$author_email		= $username . '@' . $domain;
		}

		$post_author      		= email_exists( $author_email );
		if ( $post_author )
			return $post_author;

		$post_author      		= username_exists( $username );
		if ( $post_author )
			return $post_author;

		$url					= isset( $meta['author_url'] ) && ! empty( $meta['author_url'] ) ? $meta['author_url'] : '';

		$password				= isset( $meta['password'] ) && ! empty( $meta['password'] ) ? $meta['password'] : wp_generate_password();

		$author_arr				= explode( ' ', $author );
		$first					= isset( $meta['first_name'] ) && ! empty( $meta['first_name'] ) ? $meta['first_name'] : array_shift( $author_arr );
		$first					= ucwords( $first );
		$last					= isset( $meta['last_name'] ) && ! empty( $meta['last_name'] ) ? $meta['last_name'] : implode( ' ', $author_arr );
		$last					= ucwords( $last );

		if ( isset( $meta['biography_data'] ) && ! empty( $meta['biography_data'] ) ) {
			$description		= $meta['biography_data'];
			$description		= preg_replace( '#</?font[^>]*>#', '', $description );
		}
		unset( $meta['biography_data'] );

		$join_date				= null;
		if ( isset( $meta['crdate'] ) && ! empty( $meta['crdate'] ) ) {
			$join_date			= $meta['join_date'];
		}
		unset( $meta['crdate'] );

		$role					= isset( $meta['role'] ) && ! empty( $meta['role'] ) ? $meta['role'] : 'author';

		$user					= array(
			'description'		=> $description,
			'display_name'		=> $author,
			'first_name'		=> $first,
			'last_name'			=> $last,
			'nickname'			=> $author,
			'role'				=> $role,
			'user_email'		=> $author_email,
			'user_login'		=> $username,
			'user_pass'			=> $password,
			'user_registered'	=> $join_date,
			'user_url'			=> $url,
		);

		unset( $meta['author_email'] );
		unset( $meta['author_url'] );
		unset( $meta['first_name'] );
		unset( $meta['full_name'] );
		unset( $meta['last_name'] );
		unset( $meta['password'] );
		unset( $meta['role'] );
		unset( $meta['username'] );

		$post_author      		= wp_insert_user( $user );

		if ( isset( $meta['image'] ) && ! empty( $meta['image'] ) ) {
			update_user_meta( $post_author, 'image', $meta['image'] );
		}
		unset( $meta['image'] );

		if ( isset( $meta['uid'] ) && ! empty( $meta['uid'] ) ) {
			switch ( $role ) {
				case 'subscriber':
					update_user_meta( $post_author, $this->meta_key_user, $meta['uid'] );
					break;

				default:
					update_user_meta( $post_author, $this->meta_key_author, $meta['uid'] );
					break;
			}
		} else {
			update_user_meta( $post_author, $this->meta_key_editor, $author );
		}
		unset( $meta['uid'] );

		if ( count( $meta ) ) {
			foreach ( $meta as $key => $value ) {
				update_user_meta( $post_author, $key, $value );
			}
		}

		if ( $post_author ) {
			return $post_author;
		} else {
			false;
		}
	}

	public function _create_username( $author ) {
		$username_arr			= array();
		$author_arr				= explode( ' ', $author );

		foreach ( $author_arr as $key => $value ) {
			// remove all non word characters
			$value				= preg_replace( '#\W#', '', $value );
			$username_arr[]		= $value;
		}
		$username				= implode( '.', $username_arr );

		return $username;
	}

	public function get_mbrecords() {
		$query					= "
			SELECT uid
			FROM tx_tyfrescomedia_mediaburnRecord
			WHERE 1 = 1
				{$this->mbr_custom_where}
			{$this->mbr_custom_order}
		";

		$limit					= get_mbi_options( 'import_limit' );
		if ( $limit )
			$query				.= ' LIMIT ' . $limit;

		$results				= $this->t3db->get_results( $query );

		return $results;
	}

	public function get_users() {
		$where					= '';

		if ( ! $this->refresh_data ) {
			// poll already imported and skip those
			$done_uids			= $this->wpdb->get_col( "SELECT meta_value FROM {$this->wpdb->usermeta} WHERE meta_key = '{$this->meta_key_user}'" );

			if ( count( $done_uids ) ) {
				$done_uids		= array_unique( $done_uids );
				$where			.= " AND f.uid NOT IN ( " . implode( ',', $done_uids ) . " ) ";
			}
		}

		$query					= "
			SELECT
				f.uid
			FROM fe_users f
			WHERE 1 = 1
				AND NOT f.deleted
				AND NOT f.disable
				AND email NOT LIKE ''
				AND f.pid > 0
				{$where}
			ORDER BY f.uid ASC
		";

		$limit					= get_mbi_options( 'import_limit' );
		if ( $limit )
			$query				.= ' LIMIT ' . $limit;

		$results				= $this->t3db->get_results( $query );

		return $results;
	}

	public function get_user( $uid = null ) {
		if ( is_null( $uid ) ) {
			return;
		}

		$query					= "
			SELECT
				f.email author_email,
				f.name full_name,
				f.username,
				f.password,
				f.address,
				f.city,
				f.company,
				f.country,
				FROM_UNIXTIME(f.crdate) join_date,
				IF(f.date_of_birth > 0, FROM_UNIXTIME(f.date_of_birth), 0) date_of_birth,
				f.fax,
				f.first_name,
				f.last_name,
				f.static_info_country,
				f.telephone,
				f.title,
				f.uid,
				f.usergroup,
				f.www author_url,
				f.zip,
				f.zone state,
				'subscriber' role
			FROM fe_users f
			WHERE 1 = 1
				AND f.uid = {$uid}
		";

		$row					= $this->t3db->get_row($query, ARRAY_A);

		return $row;
	}

	public function get_documents() {
		$where					= '';

		if ( ! $this->refresh_data ) {
			// poll already imported and skip those
			$done_uids			= $this->wpdb->get_col( "SELECT meta_value FROM {$this->wpdb->postmeta} WHERE meta_key = '{$this->meta_key_doc}'" );

			if ( count( $done_uids ) ) {
				$done_uids		= array_unique( $done_uids );
				$where			.= " AND a.uid NOT IN ( " . implode( ',', $done_uids ) . " ) ";
			}
		}

		$query					= "
			SELECT a.uid
			FROM tx_tyfrescomedia_doc_assets a
			WHERE 1 = 1
				AND NOT a.deleted
				AND a.pid > 0
				{$where}
			ORDER BY a.uid ASC
		";

		$limit					= get_mbi_options( 'import_limit' );
		if ( $limit )
			$query				.= ' LIMIT ' . $limit;

		$results				= $this->t3db->get_results( $query );

		return $results;
	}

	public function get_document( $uid = null ) {
		if ( is_null( $uid ) ) {
			return;
		}

		$query				= "
			SELECT
				a.author_email,
				a.full_name author,
				'' category,
				'open' comments,
				FROM_UNIXTIME(d.crdate) datetime,
				d.description,
				d.uid itemid,
				CASE
					WHEN d.crdate > UNIX_TIMESTAMP() THEN 'future'
					WHEN d.hidden = 1 THEN 'draft'
					ELSE 'publish'
					END status,
				d.tx_tyfrescomediaextendeddocfields_title title
			FROM tx_tyfrescomedia_doc_assets d
				LEFT JOIN tx_tyfrescomedia_LOOKUP_authors a ON d.author = a.uid
			WHERE 1 = 1
				AND d.uid = {$uid}
		";

		$row					= $this->t3db->get_row($query, ARRAY_A);

		if ( is_null( $row ) )
			return $row;

		$row['post_type']		= 'document';

		// grab the meta data
		$query					= "
			SELECT
				'' keywords,
				FROM_UNIXTIME(d.date, '%Y-%c-%e') publication_date,
				d.actions,
				d.collection collection_name,
				d.date_notation,
				d.document_type,
				d.location,
				d.notes,
				d.tx_tyfrescomediaextendeddocfields_control_number control_number,
				d.tx_tyfrescomediaextendeddocfields_number_of_pages number_of_pages,
				d.uuid document_asset
			FROM tx_tyfrescomedia_doc_assets d
			WHERE 1 = 1
				AND d.uid = {$uid}
		";

		$row_meta				= $this->t3db->get_row($query, ARRAY_A);

		$itemids				= $this->_typo3_api_media_categories($row['category']);

		$item_arr				= array();
		foreach ($itemids as $item_name) {
			$item_arr[]			= wp_create_category($item_name);
		}

		$row['category']		= $item_arr;

		if ( ! empty( $row_meta['collection_name'] ) ) {
			$itemids			= explode( "\n", $row_meta['collection_name'] );
			$item_arr			= array();
			foreach ($itemids as $item_name) {
				$item_result	= $this->taxonomy_push($item_name, $this->collection);
				$item_arr[]		= intval( $item_result );
			}
		
			$row_meta['collections']	= $item_arr;
		}

		$producer_arr			= array();
		$name					= trim( $row['author'] );
		if ( ! empty( $name ) ) {
			$producer_result	= $this->taxonomy_push($name, $this->producer);
			$producer_arr[]		= intval( $producer_result );
		}
	
		$row_meta['producers']	= $producer_arr;

		$row['title']			= $this->title_cleanup( $row['title'], $row_meta['control_number'] );

		$slug					= sanitize_title( $row['title'] );
		$row_meta['slug']		= $slug;

		$row['meta']			= $row_meta;
		$row['slug']			= $slug;
		
		return $row;
	}

	public function title_cleanup( $title, $altTitle = '' ) {
		// Can't have tags in the title in WP
		$title					= strip_tags( $title );
		$title					= trim( $title );
		$title					= ! empty( $title ) ? $title : $altTitle;

		return $title;
	}

	public function get_mbrecord( $uid = null ) {
		if ( is_null( $uid ) ) {
			$uid				= $this->mbr_uid;
		}

		$query				= "
			SELECT
				'' author_email,
				m.cataloger author,
				m.category,
				'open' comments,
				FROM_UNIXTIME(m.crdate) datetime,
				m.description,
				m.uid itemid,
				CASE
					WHEN m.crdate > UNIX_TIMESTAMP() THEN 'future'
					WHEN m.inhouse = 1 THEN 'private'
					WHEN m.hidden = 1 THEN 'draft'
					ELSE 'publish'
					END status,
				m.title
			FROM tx_tyfrescomedia_mediaburnRecord m
			WHERE 1 = 1
				AND m.uid = {$uid}
		";

		$row					= $this->t3db->get_row($query, ARRAY_A);

		if ( is_null( $row ) )
			return $row;

		$row['post_type']		= 'video';

		// grab the meta data
		$query					= "
			SELECT
				CONCAT_WS('\n\n', m.general_note, m.original_location, m.distributor) general_note,
				m.acquisition_source,
				m.actions,
				m.additional_copies,
				m.additional_credits,
				m.alternative_titles,
				m.audio_assets,
				m.audioformat audio_format,
				m.authors producers,
				m.color,
				m.condition_,
				m.controlnumber control_number,
				m.corporate_names,
				m.date_notation,
				m.document_assets,
				m.filmformat film_format,
				m.generation,
				m.genres,
				m.keywords,
				m.language,
				m.location_of_product location_of_production,
				m.main_contributors,
				m.maincredits main_credits,
				m.not_for_sale,
				m.number_of_reels,
				m.performers,
				FROM_UNIXTIME(m.production_date, '%Y-%c-%e') production_date,
				m.reel_titles titles_on_reel,
				m.related_records,
				m.running_time,
				m.series_titles collections,
				m.sound,
				m.uuid,
				m.video_assets,
				m.video_thumbnail,
				m.videomakers_choice,
				m.videotape video_tape_format,
				m.viewers_choice
			FROM tx_tyfrescomedia_mediaburnRecord m
			WHERE 1 = 1
				AND m.uid = {$uid}
		";

		$row_meta				= $this->t3db->get_row($query, ARRAY_A);

		$itemids				= $this->_typo3_api_media_categories($row['category']);

		if ( ! empty( $row_meta['videomakers_choice'] ) ) {
			$itemids[]			= 'Video Makers Choice';
		}

		if ( ! empty( $row_meta['viewers_choice'] ) ) {
			$itemids[]			= 'Viewers Choice';
		}

		$item_arr				= array();
		foreach ($itemids as $item_name) {
			$item_arr[]			= wp_create_category($item_name);
		}

		$row['category']		= $item_arr;

		if ( ! empty( $row_meta['keywords'] ) ) {
			$keywords			= $row_meta['keywords'];
			$keywords			= explode( "\n", $keywords );
			sort( $keywords );
			$keywords			= array_unique( $keywords );
			$keywords			= implode( ',', $keywords );
			$keywords			= $this->keyword_cleanup( $keywords );
			$row_meta['keywords']	= $keywords;
		}

		if ( ! empty( $row_meta['collections'] ) ) {
			$itemids			= explode( "\n", $row_meta['collections'] );
			$item_arr			= array();
			foreach ($itemids as $item_name) {
				$item_result	= $this->taxonomy_push($item_name, $this->collection);
				$item_arr[]		= intval( $item_result );
			}
		
			$row_meta['collections']	= $item_arr;
		}

		$itemids				= $this->_typo3_api_media_genres($row_meta['genres']);

		$item_arr				= array();
		foreach ($itemids as $item_name) {
			$item_result		= $this->taxonomy_push($item_name, $this->genre);
			$item_arr[]			= intval( $item_result );
		}
	
		$row_meta['genres']		= $item_arr;

		$itemids				= $this->_typo3_api_media_languages($row_meta['language']);
		$item_arr				= array();
		foreach ($itemids as $item_name) {
			$item_result		= $this->taxonomy_push($item_name, $this->language);
			$item_arr[]			= intval( $item_result );
		}

		$row_meta['language']	= $item_arr;

		$producers				= $this->_typo3_api_media_authors($row_meta['producers']);
		$producer_arr			= array();
		foreach ($producers as $producer) {
			$name				= trim( $producer['full_name'] );
			$producer_result	= $this->taxonomy_push($name, $this->producer);
			$producer_arr[]		= intval( $producer_result );
		}
	
		$row_meta['producers']	= $producer_arr;

		$row_meta['condition']	= $row_meta['condition_'];
		unset( $row_meta['condition_'] );

		$row['title']			= $this->title_cleanup( $row['title'], $row_meta['control_number'] );

		$slug					= sanitize_title( $row['title'] );
		$row_meta['slug']		= $slug;

		$row['meta']			= $row_meta;
		$row['slug']			= $slug;

		return $row;
	}

	public function keyword_cleanup( $keywords ) {
		// some incoming keyword groupings aren't nicely csv'd, just tag after 
		// tag space separated, but wait, more than one word goes together
		// so trying to be nice and mostly fix this automatically

		$find					= array(
			'#(\byouth\b)#i',
			'#(\byouth vote\b)#i',
			'#(\byippies\b)#i',
			'#(\bwriter\b)#i',
			'#(\bwrigley field\b)#i',
			'#(\bworld war ii\b)#i',
			'#(\bworld war i\b)#i',
			'#(\bworld peace\b)#i',
			'#(\bwmur-tv\b)#i',
			'#(\bwfmt\b)#i',
			'#(\bwest bank\b)#i',
			'#(\bwatergate\b)#i',
			'#(\bwar on drugs\b)#i',
			'#(\bvoter registration\b)#i',
			'#(\bvoter participation\b)#i',
			'#(\bvito marzullo\b)#i',
			'#(\bvitamin c\b)#i',
			'#(\bvan gogh\b)#i',
			'#(\burban renewal\b)#i',
			'#(\burban high schools\b)#i',
			'#(\buniversity of chicago\b)#i',
			'#(\bunited states\b)#i',
			'#(\bunited church of christ\b)#i',
			'#(\bunion labor\b)#i',
			'#(\bunemployment\b)#i',
			'#(\btv on tv\b)#i',
			'#(\btropics\b)#i',
			'#(\btradition\b)#i',
			'#(\bthird world\b)#i',
			'#(\btelevision\b)#i',
			'#(\btechnology\b)#i',
			'#(\btattoo\b)#i',
			'#(\bsuburb\b)#i',
			'#(\bstress\b)#i',
			'#(\bsteve dahl\b)#i',
			'#(\bsprawl\b)#i',
			'#(\bsports memorabilia\b)#i',
			'#(\bspin\b)#i',
			'#(\bspecial education\b)#i',
			'#(\bsouth central\b)#i',
			'#(\bsouth america\b)#i',
			'#(\bsocialist\b)#i',
			'#(\bsoap opera\b)#i',
			'#(\bsitcoms\b)#i',
			'#(\bsit-in\b)#i',
			'#(\bsit in\b)#i',
			'#(\bsimeon high school\b)#i',
			'#(\bshout\b)#i',
			'#(\bsharecropper\b)#i',
			'#(\bsexual exploitation\b)#i',
			'#(\bseventies\b)#i',
			'#(\bscream\b)#i',
			'#(\bscandal\b)#i',
			'#(\bsan francisco\b)#i',
			'#(\bsafety\b)#i',
			'#(\bsafe sex\b)#i',
			'#(\brural\b)#i',
			'#(\brolling stone\b)#i',
			'#(\broller coasters\b)#i',
			'#(\brodney king\b)#i',
			'#(\brobert taylor homes\b)#i',
			'#(\brita mae brown\b)#i',
			'#(\briots\b)#i',
			'#(\briot\b)#i',
			'#(\breproductive rights\b)#i',
			'#(\breligion\b)#i',
			'#(\breality tv\b)#i',
			'#(\brat pack\b)#i',
			'#(\brape\b)#i',
			'#(\brap music\b)#i',
			'#(\bralph nader\b)#i',
			'#(\bracism\b)#i',
			'#(\bpunk\b)#i',
			'#(\bpublic transportation\b)#i',
			'#(\bpublic television\b)#i',
			'#(\bpublic health\b)#i',
			'#(\bprotest\b)#i',
			'#(\bprosthesis\b)#i',
			'#(\bpropaganda\b)#i',
			'#(\bprohibition\b)#i',
			'#(\bpro-choice\b)#i',
			'#(\bpro choice\b)#i',
			'#(\bpresidential election\b)#i',
			'#(\bpresidential campaigns\b)#i',
			'#(\bpresidential campaign\b)#i',
			'#(\bpresident\b)#i',
			'#(\bpoverty\b)#i',
			'#(\bpot\b)#i',
			'#(\bpolitics\b)#i',
			'#(\bpolitical representation\b)#i',
			'#(\bpoetry\b)#i',
			'#(\bplastic surgery\b)#i',
			'#(\bpitch\b)#i',
			'#(\bpiano\b)#i',
			'#(\bperot\b)#i',
			'#(\bpearl habor\b)#i',
			'#(\bpbs\b)#i',
			'#(\bpassion\b)#i',
			'#(\bpalestine\b)#i',
			'#(\borganic farming\b)#i',
			'#(\bopression\b)#i',
			'#(\bopinions\b)#i',
			'#(\bolympics\b)#i',
			'#(\bo.s.h.a.\b)#i',
			'#(\bnuclear war\b)#i',
			'#(\bnra\b)#i',
			'#(\bnpr\b)#i',
			'#(\bnfl\b)#i',
			'#(\bnewspaper\b)#i',
			'#(\bnew york\b)#i',
			'#(\bnew age\b)#i',
			'#(\bneighborhoods\b)#i',
			'#(\bnea\b)#i',
			'#(\bnbc\b)#i',
			'#(\bnba\b)#i',
			'#(\bnational debt\b)#i',
			'#(\bnational anthem\b)#i',
			'#(\bnasa\b)#i',
			'#(\bmusic\b)#i',
			'#(\bmovie\b)#i',
			'#(\bmountains\b)#i',
			'#(\bmonopoly\b)#i',
			'#(\bmolly bolt\b)#i',
			'#(\bmiss michigan\b)#i',
			'#(\bminority vote\b)#i',
			'#(\bmilitary research\b)#i',
			'#(\bmigration\b)#i',
			'#(\bmiddle east\b)#i',
			'#(\bmichigan\b)#i',
			'#(\bmedia burn\b)#i',
			'#(\bmayor\b)#i',
			'#(\bmaturity\b)#i',
			'#(\bmarijuana\b)#i',
			'#(\bmanchester\b)#i',
			'#(\bmagnate\b)#i',
			'#(\blove\b)#i',
			'#(\blos angeles\b)#i',
			'#(\bliving wage\b)#i',
			'#(\blittle orphan annie\b)#i',
			'#(\bliberal\b)#i',
			'#(\bleague of conservation voters\b)#i',
			'#(\bleadership\b)#i',
			'#(\blabor union\b)#i',
			'#(\blabor rights\b)#i',
			'#(\bjewish\b)#i',
			'#(\bjerry lewis\b)#i',
			'#(\bjazz\b)#i',
			'#(\bjapan\b)#i',
			'#(\bjane addams\b)#i',
			'#(\bjamaica\b)#i',
			'#(\bisrael\b)#i',
			'#(\biraq\b)#i',
			'#(\binternet\b)#i',
			'#(\bindigenous\b)#i',
			'#(\bindian\b)#i',
			'#(\bindependent video\b)#i',
			'#(\bhurricane mitch\b)#i',
			'#(\bhumbolt park\b)#i',
			'#(\bhumanitarian\b)#i',
			'#(\bhuman rights\b)#i',
			'#(\bhull house\b)#i',
			'#(\bhot dog\b)#i',
			'#(\bhonduras\b)#i',
			'#(\bhomelessness\b)#i',
			'#(\bholistic\b)#i',
			'#(\bhiv\b)#i',
			'#(\bhistorical preservation\b)#i',
			'#(\bharvard university\b)#i',
			'#(\bguinness world records\b)#i',
			'#(\bguatemala\b)#i',
			'#(\bgreenwich village\b)#i',
			'#(\bgreat depression\b)#i',
			'#(\bgrass roots\b)#i',
			'#(\bgraceland\b)#i',
			'#(\bgm\b)#i',
			'#(\bghetto\b)#i',
			'#(\bgeneral motors\b)#i',
			'#(\bgaza strip\b)#i',
			'#(\bgary meier\b)#i',
			'#(\bforeign policy\b)#i',
			'#(\bford\b)#i',
			'#(\bfootball\b)#i',
			'#(\bflint\b)#i',
			'#(\bfish house\b)#i',
			'#(\bfilmmaking\b)#i',
			'#(\bfibber mcgee\b)#i',
			'#(\bfeminism\b)#i',
			'#(\bfema\b)#i',
			'#(\bfamily business\b)#i',
			'#(\bfairy tales\b)#i',
			'#(\bexeter\b)#i',
			'#(\benvironmental\b)#i',
			'#(\bembargo\b)#i',
			'#(\belvis presley\b)#i',
			'#(\beducation for the blind\b)#i',
			'#(\bdwi\b)#i',
			'#(\bdrug legalization\b)#i',
			'#(\bdodge\b)#i',
			'#(\bdiving\b)#i',
			'#(\bdissident\b)#i',
			'#(\bdictator\b)#i',
			'#(\bdemorcrat\b)#i',
			'#(\bdemocratic national convention\b)#i',
			'#(\bdemocrat convention\b)#i',
			'#(\bdebate\b)#i',
			'#(\bdance\b)#i',
			'#(\bczechoslovakia\b)#i',
			'#(\bczech republic\b)#i',
			'#(\bcrime\b)#i',
			'#(\bcosmotology\b)#i',
			'#(\bconspiracy\b)#i',
			'#(\bconspiracy trial\b)#i',
			'#(\bconcert\b)#i',
			'#(\bcomputer graphics\b)#i',
			'#(\bcommunism\b)#i',
			'#(\bcomeuppance\b)#i',
			'#(\bcomedy\b)#i',
			'#(\bcollege athletics\b)#i',
			'#(\bcollectors\b)#i',
			'#(\bcold war\b)#i',
			'#(\bclaymation\b)#i',
			'#(\bcivil rights\b)#i',
			'#(\bchristopher cerf\b)#i',
			'#(\bchristianity\b)#i',
			'#(\bchristian\b)#i',
			'#(\bchinese\b)#i',
			'#(\bchina\b)#i',
			'#(\bchild rearing\b)#i',
			'#(\bchicago public schools\b)#i',
			'#(\bchicago daily news\b)#i',
			'#(\bchicago cubs\b)#i',
			'#(\bchicago bulls\b)#i',
			'#(\bchicago bears\b)#i',
			'#(\bcheckerboard\b)#i',
			'#(\bcancer\b)#i',
			'#(\bcampaign\b)#i',
			'#(\bcamcorder\b)#i',
			'#(\bcalifornia\b)#i',
			'#(\bcajun\b)#i',
			'#(\bcadillac\b)#i',
			'#(\bbus system\b)#i',
			'#(\bbully\b)#i',
			'#(\bbughouse square\b)#i',
			'#(\bbrutality\b)#i',
			'#(\bbronzeville\b)#i',
			'#(\bbody modification\b)#i',
			'#(\bbob smith\b)#i',
			'#(\bbob kerrey\b)#i',
			'#(\bbluegrass\b)#i',
			'#(\bblack\b)#i',
			'#(\bbetty aberlin\b)#i',
			'#(\bbeauty pageants\b)#i',
			'#(\bbeatnik\b)#i',
			'#(\bbeat\b)#i',
			'#(\bbasketball\b)#i',
			'#(\bbaseball\b)#i',
			'#(\bbars\b)#i',
			'#(\bbarcelona\b)#i',
			'#(\bbarbie\b)#i',
			'#(\bautomobile\b)#i',
			'#(\batlanta\b)#i',
			'#(\banti-nuclear\b)#i',
			'#(\bamerican samoa\b)#i',
			'#(\balternative medicine\b)#i',
			'#(\balligator\b)#i',
			'#(\ballen ginsberg\b)#i',
			'#(\balderman\b)#i',
			'#(\baids\b)#i',
			'#(\baging\b)#i',
			'#(\bafrican-american\b)#i',
			'#(\bafrican american\b)#i',
			'#(\badvertising\b)#i',
			'#(\bactivism\b)#i',
			'#(\bact up\b)#i',
			'#(\b3rd party\b)#i',
			'#(\b1992\b)#i',
			'#(\b1976\b)#i',
			'#(\b1968\b)#i',
		);

		$keywords				= preg_replace( $find, ',\1,', $keywords );
		$keywords				= preg_replace( '#,\s*,#', ',', $keywords );
		$keywords				= trim( $keywords, ',' );
		$keywords				= trim( $keywords );

		return $keywords;
	}

	public function taxonomy_push($term, $taxonomy) {
		$exist					= term_exists($term, $taxonomy);

		if ( isset( $exist['term_id'] ) ) {
			$term_id			= $exist['term_id'];
		} else {
			$exist				= wp_insert_term($term, $taxonomy);
			$term_id			= $exist['term_id'];
		}
		
		return $term_id;
	}

	// remove TYPO3's broken link span code
	public function _typo3_api_parse_link_spans($bodytext) {
		$parsehtml = t3lib_div::makeInstance('t3lib_parsehtml');
		$span_tags = $parsehtml->splitTags('span', $bodytext);

		foreach($span_tags as $k => $found_value)	{
			if ($k%2) {
				$span_value = preg_replace('/<span[[:space:]]+/i','',substr($found_value,0,-1));

				// remove the red border, yellow backgroun broken link code
				if ( preg_match( '#border: 2px solid red#i', $span_value ) ) {
					$span_tags[$k] = '';
					$span_value = str_ireplace('</span>', '', $span_tags[$k+1]);
					$span_tags[$k+1] = $span_value;
				}
			}
		}

		return implode( '', $span_tags );
	}

	// include t3lib_div, t3lib_softrefproc
	// look for getTypoLinkParts to parse out LINK tags into array
	public function _typo3_api_parse_typolinks( $bodytext ) {
		$softrefproc = t3lib_div::makeInstance('t3lib_softrefproc');
		$parsehtml = t3lib_div::makeInstance('t3lib_parsehtml');

		$link_tags = $parsehtml->splitTags('link', $bodytext);

		foreach($link_tags as $k => $found_value)	{
			if ($k%2) {
				$typolink_value = preg_replace('/<LINK[[:space:]]+/i','',substr($found_value,0,-1));
				$t_lP = $softrefproc->getTypoLinkParts($typolink_value);

				switch ( $t_lP['LINK_TYPE'] ) {
					case 'mailto':
						// internal page link, drop link
						$link_tags[$k] = '<a href="mailto:' . $t_lP['url'] . '" target="_blank">';
						$typolink_value = str_ireplace('</link>', '</a>', $link_tags[$k+1]);
						$link_tags[$k+1] = $typolink_value;
						break;

					case 'url':
						// internal page link, drop link
						$link_tags[$k] = '<a href="' . $t_lP['url'] . '">';
						$typolink_value = str_ireplace('</link>', '</a>', $link_tags[$k+1]);
						$link_tags[$k+1] = $typolink_value;
						break;

					case 'file':
					case 'page':
					default:
						// internal page link, drop link
						$link_tags[$k] = '';
						$typolink_value = str_ireplace('</link>', '', $link_tags[$k+1]);
						$link_tags[$k+1] = $typolink_value;
						break;
				}
			}
		}

		$return_links			= implode( '', $link_tags );
		return $return_links;
	}

	// remove <br /> from pre code and replace withnew lines
	public function _typo3_api_parse_pre_code($bodytext) {
		$parsehtml				= t3lib_div::makeInstance('t3lib_parsehtml');
		$pre_tags				= $parsehtml->splitTags('pre', $bodytext);

		// silly fix for editor color code parsing
		$match					= '#<br\s?/?'.'>#i';
		foreach($pre_tags as $k => $found_value)	{
			if ( 0 == $k%2 ) {
				$pre_value = preg_replace($match, "\n", $found_value);
				$pre_tags[$k]	= $pre_value;
			}
		}

		return implode( '', $pre_tags );
	}

	/*
	 * @param	integer	tx_tyfrescomedia_LOOKUP_categories id to lookup
	 * @return	array	names of tx_tyfrescomedia_LOOKUP_categories categories
	 */
	public function _typo3_api_media_categories($uid) {
		$sql					= sprintf("
			SELECT c.name
			FROM tx_tyfrescomedia_LOOKUP_categories c
			WHERE c.uid IN ( %s )
		", $uid);

		$results				= $this->t3db->get_results($sql);

		$items					= array();

		foreach( $results as $item ) {
			$items[]			= $item->name;
		}

		return $items;
	}

	public function _typo3_api_media_languages($uid) {
		$sql					= sprintf("
			SELECT l.lg_name_en name
			FROM static_languages l
			WHERE l.uid IN ( %s )
		", $uid);

		$results				= $this->t3db->get_results($sql);

		$items					= array();

		foreach( $results as $item ) {
			$items[]			= $item->name;
		}

		return $items;
	}

	public function _typo3_api_media_authors($uid) {
		$sql					= sprintf("
			SELECT
				a.uid,
				FROM_UNIXTIME(a.crdate) join_date,
				a.first_name,
				a.last_name,
				a.biography_data,
				a.author_thumnail image,
				a.author_url,
				a.author_email,
				a.full_name,
				'author' role
			FROM tx_tyfrescomedia_LOOKUP_authors a
			WHERE a.uid IN ( %s )
		", $uid);

		$results				= $this->t3db->get_results($sql, ARRAY_A);

		$items					= array();

		foreach( $results as $item ) {
			$items[]			= $item;
		}

		return $items;
	}

	public function _typo3_api_media_genres($uid) {
		$sql					= sprintf("
			SELECT g.name
			FROM tx_tyfrescomedia_LOOKUP_genres g
			WHERE g.uid IN ( %s )
		", $uid);

		$results				= $this->t3db->get_results($sql);

		$items					= array();

		foreach( $results as $item ) {
			$items[]			= $item->name;
		}

		return $items;
	}

	// Helper to make a JSON error message
	public function die_json_error_msg( $id, $message ) {
		die( json_encode( array( 'error' => sprintf( __( '&quot;%1$s&quot; Post ID %2$s failed to be processed. The error message was: %3$s', 'mediaburn-importer' ), esc_html( get_the_title( $id ) ), $id, $message ) ) ) );
	}


	// Helper function to escape quotes in strings for use in Javascript
	public function esc_quotes( $string ) {
		return str_replace( '"', '\"', $string );
	}


	/**
	 * Returns string of a filename or string converted to a spaced extension
	 * less header type string.
	 *
	 * @author Michael Cannon <michael@typo3vagabond.com>
	 * @param string filename or arbitrary text
	 * @return mixed string/boolean
	 */
	public function cbMkReadableStr($str) {
		if ( is_numeric( $str ) ) {
			return number_format( $str );
		}

		if ( is_string($str) )
		{
			$clean_str = htmlspecialchars($str);

			// remove file extension
			$clean_str = preg_replace('/\.[[:alnum:]]+$/i', '', $clean_str);

			// remove funky characters
			$clean_str = preg_replace('/[^[:print:]]/', '_', $clean_str);

			// Convert camelcase to underscore
			$clean_str = preg_replace('/([[:alpha:]][a-z]+)/', "$1_", $clean_str);

			// try to cactch N.N or the like
			$clean_str = preg_replace('/([[:digit:]\.\-]+)/', "$1_", $clean_str);

			// change underscore or underscore-hyphen to become space
			$clean_str = preg_replace('/(_-|_)/', ' ', $clean_str);

			// remove extra spaces
			$clean_str = preg_replace('/ +/', ' ', $clean_str);

			// convert stand alone s to 's
			$clean_str = preg_replace('/ s /', "'s ", $clean_str);

			// remove beg/end spaces
			$clean_str = trim($clean_str);

			// capitalize
			$clean_str = ucwords($clean_str);

			// restore previous entities facing &amp; issues
			$clean_str = preg_replace( '/(&amp ;)([a-z0-9]+) ;/i'
				, '&\2;'
				, $clean_str
			);

			return $clean_str;
		}

		return false;
	}

	public function mediaburn_import_meta_boxes() {
		add_meta_box( 'mediaburn_import', __( 'MediaBurn Importer', 'mediaburn-importer' ), array( &$this, 'post_mediaburn_import_meta_box' ), 'video', 'side', 'high' );
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
	$MBI_Settings = new MBI_Settings();

	require_once( 'screen-meta-links.php' );

	global $MediaBurn_Importer;
	$MediaBurn_Importer	= new MediaBurn_Importer();
}

add_action( 'plugins_loaded', 'MediaBurn_Importer' );

function mbi_save_post( $post_id ) {
	global $MediaBurn_Importer;

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
		return;

	if ( ! is_numeric( $post_id ) )
		return;

	$post = get_post( $post_id );
	if ( ! in_array( $post->post_type, array( 'video', 'revision' ) ) )
		return;

	// check that post is wanting the MediaBurn Vzaar media imported
	if ( ! wp_verify_nonce( $_POST['mediaburn-importer'], 'mediaburn_import' ) )
		return;

	// save checkbox or not
	$checked					= ( isset( $_POST['mediaburn_import'] ) && ! empty( $_POST['mediaburn_import'] ) ) ? 1 : 0;
	// update_post_meta( $post_id, 'load_vzaar_media', $checked );
	// one time run
	update_post_meta( $post_id, 'load_vzaar_media', false );

	if ( ! $checked )
		return;

	remove_action( 'save_post', 'mbi_save_post', 99 );

	$MediaBurn_Importer->load_vzaar_media( $post_id, true );
	
	add_action( 'save_post', 'mbi_save_post', 99 );
}

add_action( 'save_post', 'mbi_save_post', 99 );

?>