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
		// This will keep track of the checkbox options for the validate_settings function.
		$this->reset			= array();
		$this->settings			= array();
		
		$this->sections['mediaburn']	= __( 'Vzaar Access', 'mediaburn-importer');
		$this->sections['selection']	= __( 'Media Selection', 'mediaburn-importer');
		$this->sections['general']	= __( 'Import Settings', 'mediaburn-importer');
		$this->sections['testing']	= __( 'Testing Options', 'mediaburn-importer');
		$this->sections['reset']	= __( 'Reset/Restore', 'mediaburn-importer');
		$this->sections['about']	= __( 'About MediaBurn Importer', 'mediaburn-importer');
		
		add_action( 'admin_menu', array( &$this, 'add_pages' ) );
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		load_plugin_textdomain( 'mediaburn-importer', false, '/mediaburn-importer/languages/' );
	}

	public function admin_init() {
		global $wpdb;
		
		$this->wpdb = $wpdb;
		$this->register_settings();

		if ( ! get_option( 'mbi_options' ) )
			$this->initialize_settings();
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

		$copyright				= '<div class="copyright">Copyright %s <a href="http://aihr.us">Aihrus.</a></div>';
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
				<p><img class="alignright size-medium" title="Michael in Red Square, Moscow, Russia" src="/wp-content/plugins/mediaburn-importer/media/michael-cannon-red-square-300x2251.jpg" alt="Michael in Red Square, Moscow, Russia" width="300" height="225" /><a href="http://wordpress.org/extend/plugins/mediaburn-importer/">MediaBurn Importer</a> is by <a href="mailto:mc@aihr.us">Michael Cannon</a>.</p>
				<p>He's <a title="Lot's of stuff about Peichi Liu..." href="http://peimic.com/t/peichi-liu/">Peichi’s</a> smiling man, an adventurous&nbsp;<a title="Water rat" href="http://www.chinesezodiachoroscope.com/facebook/index1.php?user_id=690714457" target="_blank">water-rat</a>,&nbsp;<a title="Michael's poetic like literary ramblings" href="http://peimic.com/t/poetry/">poet</a>,&nbsp;<a title="Road biker, cyclist, biking; whatever you call, I love to ride" href="http://peimic.com/c/biking/">road biker</a>,&nbsp;<a title="My traveled to country list, is more than my age." href="http://peimic.com/c/travel/">world traveler</a>,&nbsp;<a title="World Wide Opportunities on Organic Farms" href="http://peimic.com/t/WWOOF/">WWOOF’er</a>&nbsp;and is the&nbsp;<a title="The MediaBurn Vagabond" href="http://aihr.us/c/featured/">MediaBurn Vagabond</a>&nbsp;with&nbsp;<a title="in2code. Wir leben MediaBurn" href="http://www.in2code.de/">in2code</a>.</p>
				<p>If you like this plugin, <a href="http://aihr.us/about-aihrus/donate/">please donate</a>.</p>
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
		$this->settings['set_featured_image'] = array(
			'section' => 'general',
			'title'   => __( 'Set Featured Image?', 'mediaburn-importer'),
			'desc'    => __( 'Set first image found in content or related as the Featured Image.', 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 1
		);
		
		// Testing
		$this->settings['import_limit'] = array(
			'section' => 'testing',
			'title'   => __( 'Import Limit', 'mediaburn-importer'),
			'desc'    => __( 'Number of records allowed to import at a time. 0 for all..', 'mediaburn-importer'),
			'std'     => '',
			'type'    => 'text'
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
		$this->settings['update_vzaar_media'] = array(
			'section' => 'selection',
			'title'   => __( 'Reload Vzaar Media?', 'mediaburn-importer'),
			'desc'    => __( 'Reload Vzaar video embed code and thumbnail.', 'mediaburn-importer'),
			'type'    => 'checkbox',
			'std'     => 1
		);

		$this->settings['mbr_to_import'] = array(
			'title'   => __( 'Media to Import' , 'mediaburn-importer'),
			'desc'    => __( "A CSV list of record uids to import, like '1,2,3'. Overrides 'Media Selection Criteria'. Key: N for original MediaBurn record; N:u for users; N:d for documents; N:v for new video record (reload Vzaar media). Example: 1,22,333,1:d,22:d,333:d,1:u,22:u,333:u,1:v,22:v,333:v" , 'mediaburn-importer'),
			'type'	=> 'text',
			'section' => 'selection'
		);
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
		
		$this->settings = get_transient( 'MBI_Settings-settings' );
		if ( false === $this->settings ) {
			$this->get_settings();
			set_transient( 'MBI_Settings-settings', $this->settings, 60 * 60 );
		}
		
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
		if ( '' != $input['import_limit'] ) {
			$input['import_limit']	= intval( $input['import_limit'] );
		}
		
		if ( '' != $input['mbr_to_import'] ) {
			$mbr_to_import		= $input['mbr_to_import'];
			$mbr_to_import		= preg_replace( '#\s+#', '', $mbr_to_import);

			$input['mbr_to_import']	= $mbr_to_import;
		}
		
		if ( ! empty( $input['reset_plugin'] ) ) {
			foreach ( $this->reset as $id => $std ) {
				$input[$id]	= $std;
			}
			
			unset( $input['reset_plugin'] );
		}

		return $input;

	}

}


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