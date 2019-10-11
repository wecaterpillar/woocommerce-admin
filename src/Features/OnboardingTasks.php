<?php
/**
 * WooCommerce Onboarding Tasks
 * NOTE: DO NOT edit this file in WooCommerce core, this is generated from woocommerce-admin.
 *
 * @package Woocommerce Admin
 */

namespace Automattic\WooCommerce\Admin\Features;

use Automattic\WooCommerce\Admin\API\Reports\Taxes\Stats\DataStore;

/**
 * Contains the logic for completing onboarding tasks.
 */
class OnboardingTasks {
	/**
	 * Class instance.
	 *
	 * @var OnboardingTasks instance
	 */
	protected static $instance = null;

	/**
	 * Name of the active task transient.
	 *
	 * @var string
	 */
	const ACTIVE_TASK_TRANSIENT = 'wc_onboarding_active_task';

	/**
	 * Get class instance.
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'add_media_scripts' ) );
		// Old settings injection.
		// Run after Onboarding.
		add_filter( 'woocommerce_components_settings', array( $this, 'component_settings' ), 30 );
		// New settings injection.
		add_filter( 'woocommerce_shared_settings', array( $this, 'component_settings' ), 30 );
		add_action( 'admin_init', array( $this, 'set_active_task' ), 20 );
		add_action( 'current_screen', array( $this, 'check_active_task_completion' ), 1000 );
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public function add_media_scripts() {
		wp_enqueue_media();
	}

	/**
	 * Add task items to component settings.
	 *
	 * @param array $settings Component settings.
	 */
	public function component_settings( $settings ) {
		$products = wp_count_posts( 'product' );

		// @todo We may want to consider caching some of these and use to check against
		// task completion along with cache busting for active tasks.
		$settings['onboarding']['automatedTaxSupportedCountries'] = self::get_automated_tax_supported_countries();
		$settings['onboarding']['customLogo']                     = get_theme_mod( 'custom_logo', false );
		$settings['onboarding']['hasHomepage']                    = self::check_task_completion( 'homepage' );
		$settings['onboarding']['hasPhysicalProducts']            = count(
			wc_get_products(
				array(
					'virtual' => false,
					'limit'   => 1,
				)
			)
		) > 0;
		$settings['onboarding']['hasProducts']                    = self::check_task_completion( 'products' );
		$settings['onboarding']['isTaxComplete']                  = 'yes' === get_option( 'wc_connect_taxes_enabled' ) || count( DataStore::get_taxes( array() ) ) > 0;
		$settings['onboarding']['shippingZonesCount']             = count( \WC_Shipping_Zones::get_zones() );

		return $settings;
	}


	/**
	 * Temporarily store the active task.
	 */
	public static function set_active_task() {
		if ( isset( $_GET[ self::ACTIVE_TASK_TRANSIENT ] ) ) { // WPCS: csrf ok.
			$task = sanitize_title_with_dashes( wp_unslash( $_GET[ self::ACTIVE_TASK_TRANSIENT ] ) );

			if ( self::check_task_completion( $task ) ) {
				return;
			}

			set_transient(
				self::ACTIVE_TASK_TRANSIENT,
				$task,
				DAY_IN_SECONDS
			); // WPCS: csrf ok.
		}
	}

	/**
	 * Check for active task completion and redirect if complete.
	 */
	public static function check_active_task_completion() {
		$active_task = get_transient( self::ACTIVE_TASK_TRANSIENT );
		if ( ! $active_task ) {
			return;
		}

		if ( self::check_task_completion( $active_task ) ) {
			delete_transient( self::ACTIVE_TASK_TRANSIENT );
			wp_safe_redirect( wc_admin_url() );
			exit;
		}
	}

	/**
	 * Check for task completion of a given task.
	 *
	 * @param string $task Name of task.
	 * @return bool;
	 */
	public static function check_task_completion( $task ) {
		switch ( $task ) {
			case 'products':
				$products = wp_count_posts( 'product' );
				return (int) $products->publish > 0 || (int) $products->draft > 0;

			case 'homepage':
				// @todo This should be run client-side in a Gutenberg hook and add a notice
				// to return to the task list if complete.
				$homepage_id = get_option( 'woocommerce_onboarding_homepage_post_id', false );

				if ( ! $homepage_id ) {
					return false;
				}

				$post      = get_post( $homepage_id );
				$completed = $post && 'publish' === $post->post_status;
				if ( $completed ) {
					update_option( 'show_on_front', 'page' );
					update_option( 'page_on_front', $homepage_id );
				}

				return $completed;
		}

		return false;
	}

	/**
	 * Get an array of countries that support automated tax.
	 *
	 * @return array
	 */
	public static function get_automated_tax_supported_countries() {
		// https://developers.taxjar.com/api/reference/#countries .
		$tax_supported_countries = array_merge(
			array( 'US', 'CA', 'AU' ),
			WC()->countries->get_european_union_countries()
		);

		return $tax_supported_countries;
	}
}
