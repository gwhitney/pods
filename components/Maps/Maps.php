<?php

/**
 * Name: Maps
 *
 * Menu Name: Maps
 *
 * Description:
 *
 * Version: 1.0
 *
 * Category: Field Types
 *
 * Class: Pods_Component_Maps
 */
class Pods_Component_Maps extends PodsComponent {

	static $component_path;
	static $component_file;
	static $options;
	static $provider;
	static $api_key = '';

	private static $nonce = 'pods_maps';

	public function __construct() {
		// See https://github.com/pods-framework/pods/pull/3711
		add_filter( 'pods_admin_setup_edit_address_additional_field_options', array( $this, 'maps_options' ), 10, 2 );

		// Add Maps input
		// do_action( 'pods_ui_field_address_input_view_extra', $view, $type, $name, $value, $options, $pod, $id );
		add_action( 'pods_ui_field_address_input_view_extra', array( $this, 'pods_ui_field_address_input_view_extra' ), 10, 7 );

		// Validate Address/Geo
		// apply_filters( 'pods_ui_field_address_validate', $errors, $value, $type, $name, $options, $fields, $pod, $id, $params );
		add_filter( 'pods_ui_field_address_validate', array( $this, 'pods_ui_field_address_validate' ), 10, 9 );

		// Add Address/Geo pre save
		// apply_filters( 'pods_ui_field_address_pre_save', $value, $type, $id, $name, $options, $fields, $pod, $params );
		add_filter( 'pods_ui_field_address_pre_save', array( $this, 'pods_ui_field_address_pre_save' ), 10, 8 );

		// Ajax call handler
		add_action( 'wp_ajax_pods_maps', array( $this, 'ajax_handler' ) );
		// Allow calls from frontend when needed (always verify nonce!)
		add_action( 'wp_ajax_nopriv_pods_maps', array( $this, 'ajax_handler' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'global_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'global_assets' ) );
	}

	/**
	 * Enqueue styles
	 *
	 * @since 1.0
	 */
	public function admin_assets() {

		wp_enqueue_style( 'pods-admin' );

	}

	public function global_assets() {
		wp_register_style( 'pods-maps', plugin_dir_url( __FILE__ ) . 'ui/css/pods-maps.css', array(), '1.0' );

		// @todo pods-maps is probably not needed anymore (also see pods-maps.js file)
		/*wp_register_script( 'pods-maps', plugin_dir_url( __FILE__ ) . 'ui/js/pods-maps.js', array( 'jquery' ), '1.0' );
		$provider = get_class( self::$provider );
		wp_localize_script( 'pods-maps', 'PodsMaps', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'_nonce' => wp_create_nonce( self::$nonce )
		) );*/
		self::$provider->assets();
	}

	/**
	 * Register the component
	 *
	 * @param $components
	 *
	 * @return array
	 * @since 1.0
	 */
	public static function component_register( $components ) {

		$components[] = array( 'File' => realpath( self::$component_file ) );

		return $components;

	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0
	 */
	public function handler( $options ) {

		self::$options = $options;

		if ( ! empty( $options['api_key'] ) ) {
			self::$api_key = $options['api_key'];
		}

		$this->load_provider();

	}

	/**
	 * Load the selected provider
	 *
	 * @since 2.7
	 */
	private function load_provider() {

		switch ( self::$options['provider'] ) {
			case 'google':
				if ( file_exists( plugin_dir_path( __FILE__ ) . 'Maps-Google.php' ) ) {
					include_once( plugin_dir_path( __FILE__ ) . 'Maps-Google.php' );
					self::$provider = new Pods_Component_Maps_Google();
				}
				break;
		}

	}

	/**
	 * Ajax handler for geocode calls
	 *
	 * AJAX call data setup:
	 * action           => pods_maps
	 * _pods_maps_nonce => PodsMaps._nonce
	 * pods_maps_action => 'string' (the maps action)
	 * pods_maps_data   => 'string|array' (the provided data)
	 */
	public function ajax_handler() {

		if (   ! defined('DOING_AJAX')
		       || ! DOING_AJAX
		       || ! isset( $_POST['_pods_maps_nonce'] )
		       || ! wp_verify_nonce( $_POST['_pods_maps_nonce'], self::$nonce )
		) {
			wp_send_json_error( __( 'Cheatin uh?', 'pods' ) );
			die();
		}

		if ( isset( $_POST['pods_maps_action'] ) ) {
			$return = false;
			$data = '';
			if ( ! empty( $_POST['pods_maps_data'] ) ) {
				if ( is_array( $_POST['pods_maps_data'] ) ) {
					$data = array_map( 'pods_sanitize', $_POST['pods_maps_data'] );
				} else {
					$data = pods_sanitize( $_POST['pods_maps_data'] );
				}
			}
			if ( ! empty( $data ) ) {
				switch ( pods_sanitize( $_POST['pods_maps_action'] ) ) {
					case 'geocode':
					case 'geocode_address':
						$return = self::geocode_address( $data );
						break;
					case 'geocode_address_to_latlng':
						$return = self::geocode_address_to_latlng( $data );
						break;
					case 'geocode_latlng_to_address':
						$return = self::geocode_latlng_to_address( $data );
						break;
				}
			}
			if ( ! empty( $return ) ) {
				wp_send_json_success( $return );
			} else {
				wp_send_json_error( __( 'Geocode error, please try again or type different address data.', 'pods' ) );
			}
		}
		die();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0
	 */
	public function options( $settings ) {

		$options = array(
			'provider'                => array(
				'label'      => __( 'Maps Provider', 'pods' ),
				'help'       => __( 'help', 'pods' ),
				'default'    => 'google',
				'type'       => 'pick',
				'data'       => apply_filters( 'pods_component_maps_providers', array(
					'google' => __( 'Google Maps', 'pods' ),
					//'bing' => __( 'Bing Maps', 'pods' ),
					//'openstreetmap' => __( 'OpenStreetMap', 'pods' ),
				) ),
				'dependency' => true
			),
			'api_key'                 => array(
				'label'   => __( 'Maps API Key', 'pods' ),
				'help'    => __( 'help', 'pods' ),
				'default' => '',
				'type'    => 'text'
			),
			'google_client_id'        => array(
				'label'       => __( 'Google Maps Client ID', 'pods' ),
				'help'        => __( 'For use with Google Maps API for Business and Geocoding; A Client ID does not come with the Free edition.', 'pods' ),
				'includes-on' => array( 'provider' => 'google' ),
				'default'     => '',
				'type'        => 'text'
			),
			'map_style'       => array(
				'label'   => __( 'Default Map Output Type', 'pods' ),
				'default' => 'static',
				'type'    => 'pick',
				'data'    => array(
					'static' => __( 'Static (Image)', 'pods' ),
					'js'     => __( 'Javascript (Interactive)', 'pods' )
				)
			),
			'map_type' => array(
				'label'   => __( 'Default Map Type', 'pods' ),
				'default' => 'roadmap',
				'type'    => 'pick',
				'data'    => array(
					'roadmap'   => __( 'Roadmap', 'pods' ),
					'satellite' => __( 'Satellite', 'pods' ),
					'terrain'   => __( 'Terrain', 'pods' ),
					'hybrid'    => __( 'Hybrid', 'pods' )
				)
			),
			'map_zoom'        => array(
				'label'   => __( 'Default Map Zoom Level', 'pods' ),
				'help'    => array(
					__( 'Google Maps has documentation on the different zoom levels you can use.', 'pods' ),
					'https://developers.google.com/maps/documentation/staticmaps/#Zoomlevels'
				),
				'default' => 12,
				'type'    => 'number',
				'options' => array(
					'number_decimals'   => 0, // 2
					'number_max_length' => 2,
					'number_min' => 1,
					'number_max' => 21,
					'number_format' => '9999.99',
					//'number_format_type' => 'slider'
				)
			),
			'map_marker'      => array(
				'label'   => __( 'Default Map Custom Marker', 'pods' ),
				'type'    => 'file',
				'options' => array(
					'file_uploader'          => 'plupload',
					'file_edit_title'        => 0,
					'file_restrict_filesize' => '1MB',
					'file_type'              => 'images',
					'file_add_button'        => 'Upload Marker Icon'
				)
			)
		);

		return $options;

	}

	/**
	 * Add map field options
	 *
	 * @param array $options
	 * @param string $type
	 *
	 * @return array
	 *
	 * @since 2.7
	 */
	public function maps_options( $options, $type ) {

		// Add lat/lng input type
		$options[ $type . '_type' ]['data']['lat-lng'] = __( 'Latitude / Longitude', 'pods' );

		// Add Map display types
		//$options[ $type . '_display_type' ]['data']['map'] = __( 'Map', 'pods' );
		//$options[ $type . '_display_type' ]['data']['default-map'] = __( 'Default and map', 'pods' );
		//$options[ $type . '_display_type' ]['data']['custom-map'] = __( 'Custom and map', 'pods' );

		// Add extra options

		$options[ $type . '_map' ] = array(
			'label'      => __( 'Display a map', 'pods' ),
			'default'    => 0,
			'type'       => 'boolean',
			'dependency' => true
		);
		$options[ $type . '_autocorrect' ] = array(
			'label'      => __( 'Autocorrect Address during save', 'pods' ),
			'depends-on' => array( $type . '_map' => true, $type . '_type' => array( 'address', 'text' ) ),
			'default'    => 0,
			'type'       => 'boolean'
		);
		$options[ $type . '_map_display' ] = array(
			'label'      => __( 'Map Display', 'pods' ),
			'depends-on' => array( $type . '_map' => true ),
			'default'    => 'replace',
			'type'       => 'pick',
			'data'       => array(
				'replace' => __( 'Replace default display', 'pods' ),
				'before'     => __( 'Before default display', 'pods' ),
				'after'     => __( 'After default display', 'pods' )
			)
		);
		$options[ $type . '_map_style' ] = array(
			'label'      => __( 'Map Output Type', 'pods' ),
			'depends-on' => array( $type . '_map' => true ),
			'default'    => pods_v( $type . '_style', self::$options, 'static', true ),
			'type'       => 'pick',
			'data'       => array(
				'static' => __( 'Static (Image)', 'pods' ),
				'js'     => __( 'Javascript (Interactive)', 'pods' )
			)
		);
		$options[ $type . '_map_type' ] = array(
			'label'      => __( 'Map Type', 'pods' ),
			'depends-on' => array( $type . '_map' => true ),
			'default'    => pods_v( $type . '_type', self::$options, 'roadmap', true ),
			'type'       => 'pick',
			'data'       => array(
				'roadmap'   => __( 'Roadmap', 'pods' ),
				'satellite' => __( 'Satellite', 'pods' ),
				'terrain'   => __( 'Terrain', 'pods' ),
				'hybrid'    => __( 'Hybrid', 'pods' )
			)
		);
		$options[ $type . '_map_zoom' ] = array(
			'label'      => __( 'Map Zoom Level', 'pods' ),
			'depends-on' => array( $type . '_map' => true ),
			'help'       => array(
				__( 'Google Maps has documentation on the different zoom levels you can use.', 'pods' ),
				'https://developers.google.com/maps/documentation/javascript/tutorial#zoom-levels'
				//'https://developers.google.com/maps/documentation/staticmaps/#Zoomlevels'
			),
			'default'    => pods_v( $type . '_zoom', self::$options, 12, true ),
			'type'       => 'number',
			'options'    => array(
				'number_decimals'   => 0, // 2
				'number_max_length' => 2,
				'number_min' => 1,
				'number_max' => 21,
				'number_format' => '9999.99',
				//'number_format_type' => 'slider'
			)
		);
		$options[ $type . '_map_info_window' ] = array(
			'label'      => __( 'Display an Info Window', 'pods' ),
			'default'    => 0,
			'type'       => 'boolean',
			'depends-on' => array( $type . '_map' => true ),
			'dependency' => true
		);
		$options[ $type . '_map_info_window_content' ] = array(
			'label'      => __( 'Map Info Window content', 'pods' ),
			'depends-on' => array( $type . '_map' => true, $type . '_map_info_window' => true ),
			'default'    => 'default',
			'type'       => 'pick',
			'data'       => array(
				'default'   => __( 'Default display', 'pods' ),
				// Custom will add a WYSIWYG window at the edit screen
				'custom' => __( 'Custom (WYSIWYG)', 'pods' )
			)
		);
		$options[ $type . '_map_marker' ] = array(
			'label'      => __( 'Map Custom Marker', 'pods' ),
			'depends-on' => array( $type . '_map' => true ),
			'default'    => pods_v( $type . '_marker', self::$options ),
			'type'       => 'file',
			'options'    => array(
				'file_uploader'          => 'plupload',
				'file_edit_title'        => 0,
				'file_restrict_filesize' => '1MB',
				'file_type'              => 'images',
				'file_add_button'        => 'Upload Marker Icon'
			)
		);

		// Add option dependencies
		/*if ( empty( $options[ $type . '_display_type_custom' ]['depends-on'][ $type . '_display_type' ] ) ) {
			$options[ $type . '_display_type_custom' ]['depends-on'][ $type . '_display_type' ] = array( 'custom-map' );
		} else {
			$options[ $type . '_display_type_custom' ]['depends-on'][ $type . '_display_type' ] = $this->append_dependency(
				$options[ $type . '_display_type_custom' ]['depends-on'][ $type . '_display_type' ],
				'custom-map'
			);
		}*/
		$options[ $type . '_microdata' ]['excludes-on'][ $type . '_map' ] = true;

		return $options;
	}

	/**
	 * Allow Map providers to add a map to the field input field
	 *
	 * @param $view
	 * @param $type
	 * @param $name
	 * @param $value
	 * @param $options
	 * @param $pod
	 * @param $id
	 */
	public function pods_ui_field_address_input_view_extra( $view, $type, $name, $value, $options, $pod, $id ) {

		if ( ! empty ( $options['address_map'] ) ) {
			$provider = get_class( self::$provider );
			if ( method_exists( $provider, 'pods_ui_field_view_extra' ) ) {
				$view = $provider::pods_ui_field_view_extra();
			}

			if ( $view && file_exists( $view ) ) {
				// Add hidden lat/lng fields for non latlng view types
				pods_view( $view, compact( array_keys( get_defined_vars() ) ) );
				if ( $type != 'lat-lng' ) {
					echo '<div style="display: none">';
					pods_view( plugin_dir_path( __FILE__ ) . 'ui/fields/lat-lng.php', compact( array_keys( get_defined_vars() ) ) );
					echo '</div>';
				}
			}

		}

	}

	/**
	 * Validate current value
	 *
	 * @param $errors
	 * @param $value
	 * @param $type
	 * @param $name
	 * @param $options
	 * @param $fields
	 * @param $pod
	 * @param $id
	 * @param $params
	 *
	 * @return array
	 */
	public function pods_ui_field_address_validate( $errors, $value, $type, $name, $options, $fields, $pod, $id, $params ) {

		// @todo: Validate based on address type ( lat / lon, address fields)

		// Get geocode from address fields
		if ( isset( $value['address'] ) ) {
			$geocode = self::geocode_address_to_latlng( $value['address'] );
			if ( empty( $geocode['lat'] ) && empty( $geocode['lng'] ) ) {
				$errors[] = __( 'Could not find geodata for this address', 'pods' );
			}
		}

		return $errors;

	}

	/**
	 * Save Additional geo data dependent on the field type
	 *
	 * @param $value
	 * @param $type
	 * @param $id
	 * @param $name
	 * @param $options
	 * @param $fields
	 * @param $pod
	 * @param $params
	 *
	 * @return mixed
	 */
	public function pods_ui_field_address_pre_save( $value, $type, $id, $name, $options, $fields, $pod, $params ) {

		// Get geocode from address fields
		if ( isset( $value['address'] ) ) {
			$geocode = self::geocode_address_to_latlng( $value['address'] );
			if ( isset( $geocode['lat'] ) && isset( $geocode['lng'] ) ) {
				$value['geo'] = $geocode;
			}
		}

		return $value;

	}

	/**
	 * @param string|array $data
	 *
	 * @return mixed
	 */
	public static function geocode_address( $data ) {
		if ( is_object( self::$provider ) ) {
			$provider = get_class( self::$provider );
			if ( method_exists( $provider, 'geocode_address' ) ) {
				return $provider::geocode_address( $data, self::$api_key );
			}
		}
		return false;
	}

	/**
	 * @param string|array $data
	 *
	 * @return mixed
	 */
	public static function geocode_address_to_latlng( $data ) {
		if ( is_object( self::$provider ) ) {
			$provider = get_class( self::$provider );
			if ( method_exists( $provider, 'geocode_address_to_latlng' ) ) {
				return $provider::geocode_address_to_latlng( $data, self::$api_key );
			}
		}
		return false;
	}

	/**
	 * @param string|array $data
	 *
	 * @return mixed
	 */
	public static function geocode_latlng_to_address( $data ) {
		if ( is_object( self::$provider ) ) {
			$provider = get_class( self::$provider );
			if ( method_exists( $provider, 'geocode_latlng_to_address' ) ) {
				return $provider::geocode_latlng_to_address( $data, self::$api_key );
			}
		}
		return false;
	}

	/**
	 * Append new dependency to existing data
	 *
	 * @param $value
	 * @param $new
	 *
	 * @return array
	 */
	public function append_dependency( $value, $new ) {
		if ( ! is_array( $value ) ) {
			$value = array(
				(string) $value,
				$new
			);
		} else {
			$value[] = $new;
		}
		return $value;
	}

}