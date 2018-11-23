<?php

// For debugging purposes this will set all Micropub posts to Draft
if ( ! defined( 'MICROPUB_DRAFT_MODE' ) ) {
	define( 'MICROPUB_DRAFT_MODE', '0' );
}

add_action( 'plugins_loaded', array( 'Micropub_Admin', 'init' ), 10 );
add_action( 'admin_menu', array( 'Micropub_Admin', 'admin_menu' ) );

/**
 * Micropub Admin Class
 */
class Micropub_Admin {
	/**
	 * Initialize the admin screens.
	 */
	public static function init() {
		$cls = get_called_class();

		add_action( 'admin_init', array( $cls, 'admin_init' ) );

		// Register Setting
		register_setting(
			'micropub',
			'micropub_default_post_status', // Setting Name
			array(
				'type'              => 'string',
				'description'       => 'Default Post Status for Micropub Server',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => false,
			)
		);
	}

	public static function admin_init() {
		$cls  = get_called_class();
		$page = 'micropub';
		add_settings_section(
			'micropub_writing',
			'Micropub Writing Settings',
			array( 'Micropub_Admin', 'writing_settings' ),
			$page
		);

		add_settings_field(
			'micropub_default_post_status',
			__( 'Default Status for Micropub Posts', 'micropub' ),
			array( $cls, 'default_post_status_setting' ),
			$page,
			'micropub_writing'
		);
	}

	/**
	 * Add admin menu entry
	 */
	public static function admin_menu() {
		$title = 'Micropub';
		$cls   = get_called_class();
		// If the IndieWeb Plugin is installed use its menu.
		if ( class_exists( 'IndieWeb_Plugin' ) ) {
			$options_page = add_submenu_page(
				'indieweb',
				$title,
				$title,
				'manage_options',
				'micropub',
				array( $cls, 'settings_page' )
			);
		} else {
			$options_page = add_options_page(
				$title,
				$title,
				'manage_options',
				'micropub',
				array( $cls, 'settings_page' )
			);
		}

	}

	public static function settings_page() {
		load_template( plugin_dir_path( __DIR__ ) . 'templates/micropub-settings.php' );
	}

	public static function writing_settings() {
		echo 'Default Settings for the Writing of Posts';
	}

	public static function default_post_status_setting() {
		load_template( plugin_dir_path( __DIR__ ) . 'templates/micropub-post-status-setting.php' );
	}
}
