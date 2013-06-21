<?php

/**
 * MediaBurn Importer settings class
 *
 * @ref http://alisothegeek.com/2011/01/wordpress-settings-api-tutorial-1/
 */
class MBI_Settings {
	
	private $sections;
	private $reset;
	private $settings;
	private $required			= ' <span style="color: red;">*</span>';
	
	/**
	 * Construct
	 */
	public function __construct() {
		global $wpdb;
		
		// This will keep track of the checkbox options for the validate_settings function.
		$this->reset			= array();
		$this->settings			= array();
		$this->get_settings();
		
		$this->sections['mediaburn']	= __( 'Alfresco/TYPO3/Vzaar Access', 'mediaburn-importer');
		$this->sections['selection']	= __( 'Media Selection', 'mediaburn-importer');
		$this->sections['general']	= __( 'Import Settings', 'mediaburn-importer');
		$this->sections['testing']	= __( 'Testing Options', 'mediaburn-importer');
		// $this->sections['reset']	= __( 'Reset/Restore', 'mediaburn-importer');
		$this->sections['about']	= __( 'About MediaBurn Importer', 'mediaburn-importer');
		
		add_action( 'admin_menu', array( &$this, 'add_pages' ) );
		add_action( 'admin_init', array( &$this, 'register_settings' ) );

		load_plugin_textdomain( 'mediaburn-importer', false, '/mediaburn-importer/languages/' );
		
		if ( ! get_option( 'mbi_options' ) )
			$this->initialize_settings();

		$this->wpdb				= $wpdb;
	}
	
	/**
	 * Add options page
	 */
	public function add_pages() {
		
		$admin_page = add_options_page( __( 'MediaBurn Importer Settings', 'mediaburn-importer'), __( 'MediaBurn Importer', 'mediaburn-importer'), 'manage_options', 'mbi-options', array( &$this, 'display_page' ) );
		
		add_action( 'admin_print_scripts-' . $admin_page, array( &$this, 'scripts' ) );
		add_action( 'admin_print_styles-' . $admin_page, array( &$this, 'styles' ) );

		add_screen_meta_link(
        	'mediaburn-importer-link',
			__('MediaBurn Importer', 'mediaburn-importer'),
			admin_url('tools.php?page=mediaburn-importer'),
			$admin_page,
			array('style' => 'font-weight: bold;')
		);
		
	}
	
	/**
	 * Create settings field
	 */
	public function create_setting( $args = array() ) {
		
		$defaults = array(
			'id'      => 'default_field',
			'title'   => __( 'Default Field', 'mediaburn-importer'),
			'desc'    => __( '', 'mediaburn-importer'),
			'std'     => '',
			'type'    => 'text',
			'section' => 'general',
			'choices' => array(),
			'req'     => '',
			'class'   => ''
		);
			
		extract( wp_parse_args( $args, $defaults ) );
		
		$field_args = array(
			'type'      => $type,
			'id'        => $id,
			'desc'      => $desc,
			'std'       => $std,
			'choices'   => $choices,
			'label_for' => $id,
			'class'     => $class,
			'req'		=> $req
		);
		
		$this->reset[$id] = $std;

		if ( '' != $req )
			$req	= $this->required;
		
		add_settings_field( $id, $title . $req, array( $this, 'display_setting' ), 'mbi-options', $section, $field_args );
	}
	
	/**
	 * Display options page
	 */
	public function display_page() {
		
		echo '<div class="wrap">
	<div class="icon32" id="icon-options-general"></div>
	<h2>' . __( 'MediaBurn Importer Settings', 'mediaburn-importer') . '</h2>';
	
		echo '<form action="options.php" method="post">';
	
		settings_fields( 'mbi_options' );
		echo '<div class="ui-tabs">
			<ul class="ui-tabs-nav">';
		
		foreach ( $this->sections as $section_slug => $section )
			echo '<li><a href="#' . $section_slug . '">' . $section . '</a></li>';
		
		echo '</ul>';
		do_settings_sections( $_GET['page'] );
		
		echo '</div>
		<p class="submit"><input name="Submit" type="submit" class="button-primary" value="' . __( 'Save Changes', 'mediaburn-importer') . '" /></p>

		<div class="ready">When ready, <a href="'.get_admin_url().'tools.php?page=mediaburn-importer">'.__('begin importing', 'mediaburn-importer').'</a>.</div>
		
	</form>';

		$copyright				= '<div class="copyright">Copyright %s <a href="http://typo3vagabond.com">TYPO3Vagabond.com.</a></div>';
		$copyright				= sprintf( $copyright, date( 'Y' ) );
		echo					<<<EOD
				$copyright
EOD;
	
	echo '<script type="text/javascript">
		jQuery(document).ready(function($) {
			var sections = [];';
			
			foreach ( $this->sections as $section_slug => $section )
				echo "sections['$section'] = '$section_slug';";
			
			echo 'var wrapped = $(".wrap h3").wrap("<div class=\"ui-tabs-panel\">");
			wrapped.each(function() {
				$(this).parent().append($(this).parent().nextUntil("div.ui-tabs-panel"));
			});
			$(".ui-tabs-panel").each(function(index) {
				$(this).attr("id", sections[$(this).children("h3").text()]);
				if (index > 0)
					$(this).addClass("ui-tabs-hide");
			});
			$(".ui-tabs").tabs({
				fx: { opacity: "toggle", duration: "fast" }
			});
			
			$("input[type=text], textarea").each(function() {
				if ($(this).val() == $(this).attr("placeholder") || $(this).val() == "")
					$(this).css("color", "#999");
			});
			
			$("input[type=text], textarea").focus(function() {
				if ($(this).val() == $(this).attr("placeholder") || $(this).val() == "") {
					$(this).val("");
					$(this).css("color", "#000");
				}
			}).blur(function() {
				if ($(this).val() == "" || $(this).val() == $(this).attr("placeholder")) {
					$(this).val($(this).attr("placeholder"));
					$(this).css("color", "#999");
				}
			});
			
			$(".wrap h3, .wrap table").show();
			
			// This will make the "warning" checkbox class really stand out when checked.
			// I use it here for the Reset checkbox.
			$(".warning").change(function() {
				if ($(this).is(":checked"))
					$(this).parent().css("background", "#c00").css("color", "#fff").css("fontWeight", "bold");
				else
					$(this).parent().css("background", "none").css("color", "inherit").css("fontWeight", "normal");
			});
			
			// Browser compatibility
			if ($.browser.mozilla) 
			         $("form").attr("autocomplete", "off");
		});
	</script>
</div>';
		
	}
	
	/**
	 * Description for section
	 */
	public function display_section() {
		// code
	}
	
	/**
	 * Description for About section
	 */
	public function display_about_section() {
		
		echo					<<<EOD
			<div style="width: 50%;">
				<p><img class="alignright size-medium" title="Michael in Red Square, Moscow, Russia" src="/wp-content/plugins/mediaburn-importer/media/michael-cannon-red-square-300x2251.jpg" alt="Michael in Red Square, Moscow, Russia" width="300" height="225" /><a href="http://wordpress.org/extend/plugins/mediaburn-importer/">MediaBurn Importer</a> is by <a href="mailto:michael@typo3vagabond.com">Michael Cannon</a>.</p>
				<p>He's <a title="Lot's of stuff about Peichi Liu..." href="http://peimic.com/t/peichi-liu/">Peichi’s</a> smiling man, an adventurous&nbsp;<a title="Water rat" href="http://www.chinesezodiachoroscope.com/facebook/index1.php?user_id=690714457" target="_blank">water-rat</a>,&nbsp;<a title="Michael's poetic like literary ramblings" href="http://peimic.com/t/poetry/">poet</a>,&nbsp;<a title="Road biker, cyclist, biking; whatever you call, I love to ride" href="http://peimic.com/c/biking/">road biker</a>,&nbsp;<a title="My traveled to country list, is more than my age." href="http://peimic.com/c/travel/">world traveler</a>,&nbsp;<a title="World Wide Opportunities on Organic Farms" href="http://peimic.com/t/WWOOF/">WWOOF’er</a>&nbsp;and is the&nbsp;<a title="The MediaBurn Vagabond" href="http://typo3vagabond.com/c/featured/">MediaBurn Vagabond</a>&nbsp;with&nbsp;<a title="in2code. Wir leben MediaBurn" href="http://www.in2code.de/">in2code</a>.</p>
				<p>If you like this plugin, <a href="http://typo3vagabond.com/about-mediaburn-vagabond/donate/">please donate</a>.</p>
			</div>
EOD;
		
	}
	
	/**
	 * HTML output for text field
	 */
	public function display_setting( $args = array() ) {
		
		extract( $args );
		
		$options = get_option( 'mbi_options' );
		
		if ( ! isset( $options[$id] ) && $type != 'checkbox' )
			$options[$id] = $std;
		elseif ( ! isset( $options[$id] ) )
			$options[$id] = 0;
		
		$field_class = '';
		if ( $class != '' )
			$field_class = ' ' . $class;
		
		switch ( $type ) {
			
			case 'heading':
				echo '</td></tr><tr valign="top"><td colspan="2"><h4>' . $desc . '</h4>';
				break;
			
			case 'checkbox':
				
				echo '<input class="checkbox' . $field_class . '" type="checkbox" id="' . $id . '" name="mbi_options[' . $id . ']" value="1" ' . checked( $options[$id], 1, false ) . ' /> <label for="' . $id . '">' . $desc . '</label>';
				
				break;
			
			case 'select':
				echo '<select class="select' . $field_class . '" name="mbi_options[' . $id . ']">';
				
				foreach ( $choices as $value => $label )
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $options[$id], $value, false ) . '>' . $label . '</option>';
				
				echo '</select>';
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'radio':
				$i = 0;
				foreach ( $choices as $value => $label ) {
					echo '<input class="radio' . $field_class . '" type="radio" name="mbi_options[' . $id . ']" id="' . $id . $i . '" value="' . esc_attr( $value ) . '" ' . checked( $options[$id], $value, false ) . '> <label for="' . $id . $i . '">' . $label . '</label>';
					if ( $i < count( $options ) - 1 )
						echo '<br />';
					$i++;
				}
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'textarea':
				echo '<textarea class="' . $field_class . '" id="' . $id . '" name="mbi_options[' . $id . ']" placeholder="' . $std . '" rows="5" cols="30">' . wp_htmledit_pre( $options[$id] ) . '</textarea>';
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'password':
				echo '<input class="regular-text' . $field_class . '" type="password" id="' . $id . '" name="mbi_options[' . $id . ']" value="' . esc_attr( $options[$id] ) . '" />';
				
				if ( $desc != '' )
					echo '<br /><span class="description">' . $desc . '</span>';
				
				break;
			
			case 'text':
			default:
		 		echo '<input class="regular-text' . $field_class . '" type="text" id="' . $id . '" name="mbi_options[' . $id . ']" placeholder="' . $std . '" value="' . esc_attr( $options[$id] ) . '" />';
		 		
		 		if ( $desc != '' )
		 			echo '<br /><span class="description">' . $desc . '</span>';
		 		
		 		break;
		 	
		}
		
	}
	
	/**
	 * Settings and defaults
	 */
	public function get_settings() {
		// MediaBurn Website Access
		$this->settings['typo3_url'] = array(
			'title'   => __( 'Website URL', 'mediaburn-importer'),
			'desc'    => __( 'e.g. http://example.com/', 'mediaburn-importer'),
			'std'     => '',
			'type'    => 'text',
			'req'	=> true,
			'section' => 'mediaburn'
		);
		
		$this->settings['t3db_host'] = array(
			'title'   => __( 'Database Host', 'mediaburn-importer'),
			'std'     => '',
			'type'    => 'text',
			'req'	=> true,
			'section' => 'mediaburn'
		);
		
		$this->settings['t3db_name'] = array(
			'title'   => __( 'Database Name', 'mediaburn-importer'),
			'std'     => '',
			'type'    => 'text',
			'req'	=> true,
			'section' => 'mediaburn'
		);
		
		$this->settings['t3db_username'] = array(
			'title'   => __( 'Database Username', 'mediaburn-importer'),
			'std'     => '',
			'type'    => 'text',
			'req'	=> true,
			'section' => 'mediaburn'
		);
		
		$this->settings['t3db_password'] = array(
			'title'   => __( 'Database Password', 'mediaburn-importer'),
			'type'    => 'password',
			'std'     => '',
			'req'	=> true,
			'section' => 'mediaburn'
		);
		
		$this->settings['alfresco_url'] = array(
			'title'   => __( 'Alfresco URL', 'mediaburn-importer'),
			'desc'    => __( 'e.g. http://example.com:8787/alfresco', 'mediaburn-importer'),
			'std'     => '',
			'type'    => 'text',
			'req'	=> true,
			'section' => 'mediaburn'
		);
		
		$this->settings['alfresco_username'] = array(
			'title'   => __( 'Alfresco Username', 'mediaburn-importer'),
			'std'     => '',
			'type'    => 'text',
			'req'	=> true,
			'section' => 'mediaburn'
		);
		
		$this->settings['alfresco_password'] = array(
			'title'   => __( 'Alfresco Password', 'mediaburn-importer'),
			'type'    => 'password',
			'std'     => '',
			'req'	=> true,
			'section' => 'mediaburn'
		);
		
		$this->settings['vzaar_username'] = array(
			'title'   => __( 'Vzaar Username', 'mediaburn-importer'),
			'std'     => '',
			'type'    => 'text',
			'req'	=> true,
			'section' => 'mediaburn'
		);
		
		$this->settings['vzaar_application_token'] = array(
			'title'   => __( 'Vzaar Application Token', 'mediaburn-importer'),
			'std'     => '',
			'type'    => 'text',
			'req'	=> true,
			'section' => 'mediaburn'
		);
		
		// Import Settings
		$this->settings['default_author'] = array(
			'section' => 'general',
			'title'   => __( 'Default Author', 'mediaburn-importer'),
			'desc'    => __( 'Select incoming post author when none is provided.', 'mediaburn-importer'),
			'type'    => 'select',
			'std'     => '',
			'choices' => array(
				'0'	=> __('Current user', 'mediaburn-importer'),
			)
		);

		$users					= get_transient( 'MBI_Settings-get_users' );
		if ( false === $users ) {
			$users				= get_users();
			set_transient( 'MBI_Settings-get_users', $users, 60 * 60 );
		}

		foreach( $users as $user ) {
			$user_name			= $user->display_name;
			$user_name			.= ' (' . $user->user_email . ')';
			$this->settings['default_author']['choices'][ $user->ID ]	= $user_name;
		}
		
		$this->settings['force_post_status'] = array(
			'section' => 'general',
			'title'   => __( 'Override Post Status as...?', 'mediaburn-importer'),
			'desc'    => __( 'Hidden records will remain as Draft.', 'mediaburn-importer'),
			'type'    => 'radio',
			'std'     => 'default',
			'choices' => array(
				'default'	=> __('No Change', 'mediaburn-importer'),
				'draft'		=> __('Draft', 'mediaburn-importer'),
				'publish'	=> __('Publish', 'mediaburn-importer'),
				'pending'	=> __('Pending', 'mediaburn-importer'),
				'future'	=> __('Future', 'mediaburn-importer'),
				'private'	=> __('Private', 'mediaburn-importer')
			)
		);

		$this->settings['set_featured_image'] = array(
			'section' => 'general',
			'title'   => __( 'Set Featured Image?', 'mediaburn-importer'),
			'desc'    => __( 'Set first image found in content or related as the Featured Image.', 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 1
		);
		
		$this->settings['related_files_header'] = array(
			'title'   => __( 'Related Files Header' , 'mediaburn-importer'),
			'std'     => __( 'Related Files', 'mediaburn-importer' ),
			'type'	=> 'text',
			'section' => 'general'
		);

		$this->settings['related_files_header_tag'] = array(
			'section' => 'general',
			'title'   => __( 'Related Files Header Tag', 'mediaburn-importer'),
			'type'    => 'select',
			'std'     => '3',
			'choices' => array(
				'0'	=> __('None', 'mediaburn-importer'),
				'1'	=> __('H1', 'mediaburn-importer'),
				'2'	=> __('H2', 'mediaburn-importer'),
				'3'	=> __('H3', 'mediaburn-importer'),
				'4'	=> __('H4', 'mediaburn-importer'),
				'5'	=> __('H5', 'mediaburn-importer'),
				'6'	=> __('H6', 'mediaburn-importer')
			)
		);
		
		$this->settings['download_text'] = array(
			'title'   => __( 'Download Text' , 'mediaburn-importer'),
			'std'     => __( 'Download %s', 'mediaburn-importer' ),
			'type'	=> 'text',
			'section' => 'general'
		);

		$this->settings['google_docs_viewer_url'] = array(
			'title'   => __( 'Google Docs Viewer URL' , 'mediaburn-importer'),
			'std'     => __( 'http://docs.google.com/viewer?url=', 'mediaburn-importer' ),
			'type'	=> 'text',
			'section' => 'general'
		);
		
		$this->settings['google_docs_viewer_text'] = array(
			'title'   => __( 'Google Docs Viewer Text' , 'mediaburn-importer'),
			'std'     => __( 'Read %s online', 'mediaburn-importer' ),
			'type'	=> 'text',
			'section' => 'general'
		);

		$this->settings['google_docs_viewer_width'] = array(
			'title'   => __( 'Google Docs Viewer Width' , 'mediaburn-importer'),
			'std'     => 690,
			'type'	=> 'text',
			'section' => 'general'
		);

		$this->settings['related_files_wrap'] = array(
			'title'   => __( 'Related Files Wrap' , 'mediaburn-importer'),
			'desc'   => __( 'Useful for adding membership oriented shortcodes around premium content. "|" separates before and after content. e.g. [paid]|[/paid]' , 'mediaburn-importer'),
			'type'	=> 'text',
			'section' => 'general'
		);

		// Testing
		$this->settings['no_mbrecords_import'] = array(
			'section' => 'testing',
			'title'   => __( "Don't Import MediaBurn Records" , 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 0
		);

		$this->settings['no_documents_import'] = array(
			'section' => 'testing',
			'title'   => __( "Don't Import Documents" , 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 0
		);

		$this->settings['no_users_import'] = array(
			'section' => 'testing',
			'title'   => __( "Don't Import Users" , 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 0
		);

		$this->settings['no_media_import'] = array(
			'section' => 'testing',
			'title'   => __( "Don't Import Attachments" , 'mediaburn-importer'),
			'desc'    => __( 'Skips importing any related images and other media files of records.', 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 0
		);

		$this->settings['import_limit'] = array(
			'section' => 'testing',
			'title'   => __( 'Import Limit', 'mediaburn-importer'),
			'desc'    => __( 'Number of records allowed to import at a time. 0 for all..', 'mediaburn-importer'),
			'std'     => '',
			'type'    => 'text'
		);
		
		$this->settings['debug_mode'] = array(
			'section' => 'testing',
			'title'   => __( 'Debug Mode' , 'mediaburn-importer'),
			'desc'	  => __( 'Bypass Ajax controller to handle mbr_to_import directly for testing purposes', 'mediaburn-importer' ),
			'type'    => 'checkbox',
			'std'     => 0
		);
		
		$desc_all				= __( "This will remove ALL imported records from MediaBurn. Related meta will also be deleted.", 'mediaburn-importer');
		$desc_attachments		= __( "This will remove ALL media without a related post. It's possible for non-imported attachments to be deleted.", 'mediaburn-importer');
		$desc_documents			= __( "This will remove ALL documents imported with the 't3:doc.uid' meta key. Related post media will also be deleted." , 'mediaburn-importer');
		$desc_imports			= __( "This will remove ALL posts imported with the 't3:mbr.uid' meta key. Related post media will also be deleted.", 'mediaburn-importer');
		$desc_taxonomy		= __( "This will remove ALL imported taxonomy records.", 'mediaburn-importer');
		$desc_users				= __( "This will remove ALL users imported with the 't3:author.uid' and 't3:fe_user.uid' meta keys." , 'mediaburn-importer');

		// Reset/restore
		$this->settings['delete'] = array(
			'section' => 'reset',
			'title'   => __( 'Delete...', 'mediaburn-importer'),
			'type'    => 'radio',
			'std'     => '',
			'choices' => array(
				'all'			=> __( 'All Imported', 'mediaburn-importer') . ': ' . $desc_all,
				'videos'		=> __( 'Imported videos', 'mediaburn-importer') . ': ' . $desc_imports,
				'documents'		=> __( 'Imported documents', 'mediaburn-importer') . ': ' . $desc_documents,
				'users'			=> __( 'Imported users', 'mediaburn-importer') . ': ' . $desc_users,
				'taxonomy'		=> __( 'Imported taxonomy', 'mediaburn-importer') . ': ' . $desc_taxonomy,
				'attachments'	=> __( 'Unattached attachments', 'mediaburn-importer') . ': ' . $desc_attachments
			)
		);
		
		$this->settings['reset_plugin'] = array(
			'section' => 'reset',
			'title'   => __( 'Reset plugin', 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 0,
			'class'   => 'warning', // Custom class for CSS
			'desc'    => __( 'Check this box and click "Save Changes" below to reset plugin options to their defaults.', 'mediaburn-importer')
		);


		// selection
		$this->settings['custom_update'] = array(
			'section' => 'selection',
			'title'   => __( 'Custom Updates Only?', 'mediaburn-importer'),
			'desc'    => __( 'Custom one-time only updates', 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 0
		);
		
		$this->settings['load_vzaar_media_only'] = array(
			'section' => 'selection',
			'title'   => __( 'Load Vzaar Media Only?', 'mediaburn-importer'),
			'desc'    => __( 'Bypass normal importing to only load video records needing Vzaar video embed code and thumbnail.', 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 0
		);
		
		$this->settings['update_vzaar_media'] = array(
			'section' => 'selection',
			'title'   => __( 'Reload Vzaar Media?', 'mediaburn-importer'),
			'desc'    => __( 'Reload Vzaar video embed code and thumbnail.', 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 1
		);

		$this->settings['refresh_data'] = array(
			'section' => 'selection',
			'title'   => __( 'Refresh MediaBurn Records?', 'mediaburn-importer'),
			'desc'    => __( 'Update local MediaBurn records with changes from remote system.', 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 0
		);

		$this->settings['mbr_custom_where'] = array(
			'title'   => __( 'Media WHERE Clause' , 'mediaburn-importer'),
			'desc'    => __( "WHERE clause used to select records from MediaBurn. e.g.: AND tx_tyfrescomedia_mediaburnRecord.deleted = 0 AND tx_tyfrescomedia_mediaburnRecord.pid > 0" , 'mediaburn-importer'),
			'std'     => 'AND tx_tyfrescomedia_mediaburnRecord.deleted = 0 AND tx_tyfrescomedia_mediaburnRecord.pid > 0',
			'type'	=> 'text',
			'section' => 'selection'
		);
		
		$this->settings['mbr_custom_order'] = array(
			'title'   => __( 'Media ORDER Clause' , 'mediaburn-importer'),
			'desc'    => __( "ORDER clause used to select records from MediaBurn. e.g.: ORDER BY tx_tyfrescomedia_mediaburnRecord.uid ASC" , 'mediaburn-importer'),
			'std'     => 'ORDER BY tx_tyfrescomedia_mediaburnRecord.uid ASC',
			'type'	=> 'text',
			'section' => 'selection'
		);

		$this->settings['mbr_to_import'] = array(
			'title'   => __( 'Media to Import' , 'mediaburn-importer'),
			'desc'    => __( "A CSV list of record uids to import, like '1,2,3'. Overrides 'Media Selection Criteria'. Key: N for original MediaBurn record; N:u for users; N:d for documents; N:v for new video record (reload Vzaar media). Example: 1,22,333,1:d,22:d,333:d,1:u,22:u,333:u,1:v,22:v,333:v" , 'mediaburn-importer'),
			'type'	=> 'text',
			'section' => 'selection'
		);
		
		$this->settings['mbr_to_skip'] = array(
			'title'   => __( 'Skip Importing Media' , 'mediaburn-importer'),
			'desc'    => __( "A CSV list of record uids not to import, like '1,2,3'." , 'mediaburn-importer'),
			'type'	=> 'text',
			'section' => 'selection'
		);
		
		// Here for reference
		if ( false ) {
		$this->settings['example_text'] = array(
			'title'   => __( 'Example Text Input', 'mediaburn-importer'),
			'desc'    => __( 'This is a description for the text input.', 'mediaburn-importer'),
			'std'     => 'Default value',
			'type'    => 'text',
			'section' => 'general'
		);
		
		$this->settings['example_textarea'] = array(
			'title'   => __( 'Example Textarea Input', 'mediaburn-importer'),
			'desc'    => __( 'This is a description for the textarea input.', 'mediaburn-importer'),
			'std'     => 'Default value',
			'type'    => 'textarea',
			'section' => 'general'
		);
		
		$this->settings['example_checkbox'] = array(
			'section' => 'general',
			'title'   => __( 'Example Checkbox', 'mediaburn-importer'),
			'desc'    => __( 'This is a description for the checkbox.', 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 1 // Set to 1 to be checked by default, 0 to be unchecked by default.
		);
		
		$this->settings['example_heading'] = array(
			'section' => 'general',
			'title'   => '', // Not used for headings.
			'desc'    => 'Example Heading',
			'type'    => 'heading'
		);
		
		$this->settings['example_radio'] = array(
			'section' => 'general',
			'title'   => __( 'Example Radio', 'mediaburn-importer'),
			'desc'    => __( 'This is a description for the radio buttons.', 'mediaburn-importer'),
			'type'    => 'radio',
			'std'     => '',
			'choices' => array(
				'choice1' => 'Choice 1',
				'choice2' => 'Choice 2',
				'choice3' => 'Choice 3'
			)
		);
		
		$this->settings['example_select'] = array(
			'section' => 'general',
			'title'   => __( 'Example Select', 'mediaburn-importer'),
			'desc'    => __( 'This is a description for the drop-down.', 'mediaburn-importer'),
			'type'    => 'select',
			'std'     => '',
			'choices' => array(
				'choice1' => 'Other Choice 1',
				'choice2' => 'Other Choice 2',
				'choice3' => 'Other Choice 3'
			)
		);
		}
	}
	
	/**
	 * Initialize settings to their default values
	 */
	public function initialize_settings() {
		
		$default_settings = array();
		foreach ( $this->settings as $id => $setting ) {
			if ( $setting['type'] != 'heading' )
				$default_settings[$id] = $setting['std'];
		}
		
		update_option( 'mbi_options', $default_settings );
		
	}
	
	/**
	* Register settings
	*/
	public function register_settings() {
		
		register_setting( 'mbi_options', 'mbi_options', array ( &$this, 'validate_settings' ) );
		
		foreach ( $this->sections as $slug => $title ) {
			if ( $slug == 'about' )
				add_settings_section( $slug, $title, array( &$this, 'display_about_section' ), 'mbi-options' );
			else
				add_settings_section( $slug, $title, array( &$this, 'display_section' ), 'mbi-options' );
		}
		
		$this->get_settings();
		
		foreach ( $this->settings as $id => $setting ) {
			$setting['id'] = $id;
			$this->create_setting( $setting );
		}
		
	}
	
	/**
	* jQuery Tabs
	*/
	public function scripts() {
		
		wp_print_scripts( 'jquery-ui-tabs' );
		
	}
	
	/**
	* Styling for the plugin options page
	*/
	public function styles() {
		
		wp_register_style( 'mbi-admin', plugins_url( 'settings.css', __FILE__ ) );
		wp_enqueue_style( 'mbi-admin' );
		
	}
	
	/**
	* Validate settings
	*/
	public function validate_settings( $input ) {
		
		// TODO validate for
		// MediaBurn db connectivity

		if ( false && $input['debug_mode'] && '' == $input['mbr_to_import'] ) {
			add_settings_error( 'mbi-options', 'mbr_to_import', __( 'Media to Import is required' , 'mediaburn-importer') );
		}

		if ( '' != $input['import_limit'] ) {
			$input['import_limit']	= intval( $input['import_limit'] );
		}
		
		if ( '' != $input['mbr_to_import'] ) {
			$mbr_to_import		= $input['mbr_to_import'];
			$mbr_to_import		= preg_replace( '#\s+#', '', $mbr_to_import);

			$input['mbr_to_import']	= $mbr_to_import;
		}
		
		if ( '' != $input['mbr_to_skip'] ) {
			$mbr_to_skip		= $input['mbr_to_skip'];
			$mbr_to_skip		= preg_replace( '#\s+#', '', $mbr_to_skip);

			$input['mbr_to_skip']	= $mbr_to_skip;
		}
		
		if ( '' == $input['typo3_url'] ) {
			add_settings_error( 'mbi-options', 'typo3_url', __('Website URL is required', 'mediaburn-importer') );
		} else {
			$typo3_url			= $input['typo3_url'];
			// append / if needed and save to options
			$typo3_url	= preg_replace('#(/{0,})?$#', '/',  $typo3_url);
			// silly // fix, above regex no matter what doesn't seem to work on 
			// this
			$typo3_url	= preg_replace('#//$#', '/',  $typo3_url);
			// Store details for later
			$input['typo3_url']	= $typo3_url;

			// check for typo3_url validity & reachability
			if ( false && ! $this->_is_typo3_website( $typo3_url ) ) {
				add_settings_error( 'mbi-options', 'typo3_url', __( "MediaBurn site not found at given Website URL", 'mediaburn-importer' ) );
			}
		}
		
		if ( '' == $input['t3db_host'] ) {
			add_settings_error( 'mbi-options', 't3db_host', __('Database Host is required', 'mediaburn-importer') );
		}
		
		if ( '' == $input['t3db_name'] ) {
			add_settings_error( 'mbi-options', 't3db_name', __('Database Name is required', 'mediaburn-importer') );
		}
		
		if ( '' == $input['t3db_username'] ) {
			add_settings_error( 'mbi-options', 't3db_username', __('Database Username is required', 'mediaburn-importer') );
		}
		
		if ( '' == $input['t3db_password'] ) {
			add_settings_error( 'mbi-options', 't3db_password', __('Database Password is required', 'mediaburn-importer') );
		}

		if ( isset( $input['delete'] ) && $input['delete'] ) {
			set_time_limit( 0 );

			switch ( $input['delete'] ) {
				case 'all' :
					$this->delete_videos();
					$this->delete_documents();
					$this->delete_taxonomy();
					$this->delete_users();
					$this->delete_attachments();
					break;

				case 'taxonomy' :
					$this->delete_taxonomy();
					break;

				case 'users' :
					$this->delete_users();
					break;

				case 'videos' :
					$this->delete_videos();
					break;

				case 'documents' :
					$this->delete_videos( 't3:doc.uid' );
					break;

				case 'attachments' :
					$this->delete_attachments();
					break;
			}

			unset( $input['delete'] );
			return $input;
		}

		if ( $input['reset_plugin'] ) {
			foreach ( $this->reset as $id => $std ) {
				$input[$id]	= $std;
			}
			
			unset( $input['reset_plugin'] );
		}

		return $input;

	}

	function _is_typo3_website( $url = null ) {
		// regex url
		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			// pull site's MediaBurn admin url, http://example.com/typo3
			$typo3_url			= preg_replace( '#$#', 'typo3/index.php', $url );

			// check for MediaBurn header code
			$html				= @file_get_contents( $typo3_url );

			// look for `<meta name="generator" content="MediaBurn`
			// looking for meta doesn't work as MediaBurn throws browser error
			// if exists, return true, else false
			if ( preg_match( '#typo3logo#', $html ) ) {
				return true;
			} else {
				// not typo3 site
				return false;
			}
		} else {
			// bad url
			return false;
		}
	}

	function delete_taxonomy() {
		$taxonomy_count				= 0;

		$taxonomys					= $this->wpdb->get_results( "SELECT term_id, taxonomy FROM {$this->wpdb->term_taxonomy} WHERE taxonomy IN ( 'languages', 'genres', 'collections', 'producers',  'language', 'genre', 'collection', 'producer', 'category', 'post_tag', 'author' )" );

		foreach( $taxonomys as $taxonomy ) {
			wp_delete_term( $taxonomy->term_id, $taxonomy->taxonomy );

			$taxonomy_count++;
		}

		add_settings_error( 'mbi-options', 'imports', sprintf( __( "Successfully removed %s MediaBurn taxonomy records." , 'mediaburn-importer'), number_format( $taxonomy_count ) ), 'updated' );
	}

	function delete_users() {
		$user_count				= 0;

		$users					= $this->wpdb->get_results( "SELECT user_id FROM {$this->wpdb->usermeta} WHERE meta_key IN ( 't3:author.uid', 't3:editor', 't3:fe_user.uid' ) AND user_id NOT IN (1, 2)" );

		foreach( $users as $user ) {
			// returns array of obj->ID
			$user_id			= $user->user_id;

			// dels user, meta & documents
			// true is force delete
			wp_delete_user( $user_id );

			$user_count++;
		}

		add_settings_error( 'mbi-options', 'imports', sprintf( __( "Successfully removed %s MediaBurn user records and their related data." , 'mediaburn-importer'), number_format( $user_count ) ), 'updated' );
	}

	function delete_documents() {
		$this->delete_videos( 't3:doc.uid' );
	}

	function delete_videos( $key = 't3:mbr.uid' ) {
		$post_count				= 0;

		// during botched imports not all postmeta is read successfully
		// pull post ids with typo3_uid as post_meta key
		$posts					= $this->wpdb->get_results( "SELECT post_id FROM {$this->wpdb->postmeta} WHERE meta_key = '$key'" );

		foreach( $posts as $post ) {
			// returns array of obj->ID
			$post_id			= $post->post_id;

			// remove media relationships
			$this->delete_attachments( $post_id, false );

			// dels post, meta & documents
			// true is force delete
			wp_delete_post( $post_id, true );

			$post_count++;
		}

		add_settings_error( 'mbi-options', 'imports', sprintf( __( "Successfully removed %s MediaBurn media records and their related data." , 'mediaburn-importer'), number_format( $post_count ) ), 'updated' );
	}

	function delete_attachments( $post_id = false, $report = true ) {
		$post_id				= $post_id ? $post_id : 0;

		if ( true || $post_id ) {
			$query				= "SELECT ID FROM {$this->wpdb->posts} WHERE post_type = 'attachment' AND post_parent = {$post_id}";
		} else {
			$query				= "SELECT post_id ID FROM {$this->wpdb->postmeta} WHERE meta_key = 't3:media'";
		}

		$attachments			= $this->wpdb->get_results( $query );

		$attachment_count		= 0;
		foreach( $attachments as $attachment ) {
			// true is force delete
			wp_delete_attachment( $attachment->ID, true );
			$attachment_count++;
		}

		if ( $report )
			add_settings_error( 'mbi-options', 'attachments', sprintf( __( "Successfully removed %s no-post attachments." , 'mediaburn-importer'), number_format( $attachment_count ) ), 'updated' );
	}
	
}

$MBI_Settings					= new MBI_Settings();

function get_mbi_options( $option, $default = false ) {
	$options					= get_option( 'mbi_options', $default );
	if ( isset( $options[$option] ) ) {
		return $options[$option];
	} else {
		return false;
	}
}

function update_mbi_options( $option, $value = null ) {
	$options					= get_option( 'mbi_options' );

	if ( ! is_array( $options ) ) {
		$options				= array();
	}

	$options[$option]			= $value;
	update_option( 'mbi_options', $options );
}
?>