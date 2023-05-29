<?php

namespace WPForms\Pro\Integrations\LiteConnect;

use WPForms\Admin\Notice;
use WPForms\Helpers\Transient;
use WPForms\Integrations\LiteConnect\API;

/**
 * Class Admin.
 *
 * This class has been created to help the WPForms team to test the Lite Connection
 * solution while its UI is not ready yet.
 *
 * @since 1.7.4
 */
final class Admin {

	/**
	 * The new entries count.
	 *
	 * @since 1.7.4
	 *
	 * @var array|bool
	 */
	private $new_entries_count;

	/**
	 * The total entries count.
	 *
	 * @since 1.7.4
	 *
	 * @var array|bool
	 */
	private $total_entries_count;

	/**
	 * Import option data.
	 *
	 * @since 1.7.4
	 *
	 * @var array
	 */
	private $import_option;

	/**
	 * Import status.
	 *
	 * @since 1.7.4
	 *
	 * @var string|bool
	 */
	private $import_status;

	/**
	 * The Integration object.
	 *
	 * @since 1.7.4
	 *
	 * @var Integration
	 */
	private $integration;

	/**
	 * Current action.
	 *
	 * @since 1.7.4
	 *
	 * @var string
	 */
	private $action;

	/**
	 * Admin constructor.
	 *
	 * @since 1.7.4
	 */
	public function __construct() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->action = isset( $_GET['wpforms_lite_connect_action'] ) ? sanitize_key( $_GET['wpforms_lite_connect_action'] ) : '';

		$this->integration         = new Integration();
		$this->total_entries_count = Integration::get_entries_count();
		$this->new_entries_count   = Integration::get_new_entries_count();
		$this->import_status       = $this->get_import_status();

		$this->hooks();
	}

	/**
	 * Initialize the hooks.
	 *
	 * @since 1.7.4
	 */
	private function hooks() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Maybe reset the import flag.
		add_action( 'wp_loaded', [ $this, 'maybe_reset_import' ], 2 );

		// Maybe restart the import flag.
		add_action( 'wp_loaded', [ $this, 'maybe_restart_import_flag' ], 2 );

		// Maybe refresh entries count.
		add_action( 'wp_loaded', [ $this, 'maybe_refresh_entries_count' ], 2 );

		// Maybe start the import process.
		add_action( 'wp_loaded', [ $this, 'maybe_start_import_process' ], 3 );

		// Display notice (CTA block) inside dashboard widget.
		add_filter( 'wpforms_pro_admin_dashboard_widget_content_html_chart_block_before', [ $this, 'dashboard_widget_notice' ] );

		add_filter( 'removable_query_args', [ $this, 'removable_query_args' ] );

		// Display notices only on WPForms pages.
		if ( wpforms_is_admin_page() ) {

			// Display admin notice if there are entries available to import.
			add_action( 'admin_notices', [ $this, 'display_import_notices' ] );

			// Display admin notice if the process fails for any reasons.
			add_action( 'admin_notices', [ $this, 'display_error_notices' ] );
		}
	}

	/**
	 * Get import status.
	 *
	 * @since 1.7.4
	 *
	 * @return string|bool
	 */
	private function get_import_status() {

		$this->import_option = wpforms_setting( 'import', false, Integration::get_option_name() );

		return isset( $this->import_option['status'] ) ? $this->import_option['status'] : false;
	}

	/**
	 * Displays admin notice with a button to import lite entries.
	 *
	 * @since 1.7.4
	 */
	public function display_import_notices() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		// Load the completed admin notice if the process is done.
		if ( $this->import_status === 'done' ) {
			if ( ! isset( $this->import_option['user_notified'] ) ) {
				$this->display_completed_notice();
			}

			return;
		}

		$message = '';

		if ( $this->action === 'import' || in_array( $this->import_status, [ 'scheduled', 'running' ], true ) ) {

			$message = wpforms_render(
				'admin/admin-fancy-notice',
				[
					'slug'  => 'lite-connect-entries-restore',
					'icon'  => '',
					'title' => esc_html__( 'Entry Restore in Progress', 'wpforms' ),
					'desc'  => esc_html__( 'Your entries are currently being imported. This should only take a few minutes. An admin notice will be displayed when the process is complete.', 'wpforms' ),
				],
				true
			);

		} elseif ( empty( $this->import_status ) ) {

			// Do not display the admin notice if there are no new entries are available to import.
			if ( (int) $this->new_entries_count <= 0 ) {
				return;
			}

			// Do not display the import notice if AS engine is not ready to use.
			if ( ! wpforms()->get( 'tasks' )->is_usable() ) {
				return;
			}

			$message = wpforms_render(
				'admin/admin-fancy-notice',
				[
					'slug'      => 'lite-connect-entries-restore',
					'icon'      => 'cloud_download',
					'title'     => esc_html__( 'Restore Your Form Entries', 'wpforms' ),
					'desc'      => $this->get_since_info_html(),
					'btn_title' => esc_html__( 'Restore Entries Now', 'wpforms' ),
					'btn_url'   => add_query_arg( [ 'wpforms_lite_connect_action' => 'import' ] ),
				],
				true
			);
		}

		Notice::add(
			$message,
			'fancy-info',
			[
				'autop' => false,
			]
		);
	}

	/**
	 * Displays admin notice if the import has been completed successfully.
	 *
	 * @since 1.7.4
	 */
	public function display_completed_notice() {

		if ( ! Transient::get( 'lite_connect_imported_entries' ) ) {
			return;
		}

		$imported_entries_count = count( Transient::get( 'lite_connect_imported_entries' ) );

		// In case it is a re-import, then reduce the number of entries imported previously from the count.
		if ( isset( $this->import_option['previous_import_count'] ) ) {
			$imported_entries_count -= (int) $this->import_option['previous_import_count'];
		}

		if ( $imported_entries_count <= 0 ) {
			return;
		}

		// Prepares the message and displays the admin notice.
		$desc = sprintf(
			esc_html( /* translators: %d - number of imported entries. */
				_n(
					'%d entry has been successfully imported.',
					'%d entries have been successfully imported.',
					$imported_entries_count,
					'wpforms'
				)
			),
			$imported_entries_count
		);

		$message = wpforms_render(
			'admin/admin-fancy-notice',
			[
				'slug'      => 'lite-connect-entries-complete',
				'icon'      => 'check',
				'title'     => esc_html__( 'Entry Restore Complete', 'wpforms' ),
				'desc'      => $desc,
				'btn_title' => esc_html__( 'View Entries', 'wpforms' ),
				'btn_url'   => admin_url( 'admin.php?page=wpforms-entries' ),
			],
			true
		);

		Notice::add(
			$message,
			'fancy-success',
			[
				'dismiss' => Notice::DISMISS_GLOBAL,
				'slug'    => 'lite_connect_import_success_notice_' . $this->total_entries_count,
				'autop'   => false,
			]
		);

		// Adds the user notified settings to database, so the admin notice won't be displayed again.
		$settings = get_option( Integration::get_option_name(), [] );

		$settings['import']['user_notified'] = true;

		update_option( Integration::get_option_name(), $settings );
	}

	/**
	 * Displays admin notice if the process fails for any reasons.
	 *
	 * @since 1.7.4
	 */
	public function display_error_notices() {

		if ( ! $this->integration->has_reached_fail_limit() ) {
			return;
		}

		Notice::error(
			esc_html__( 'Unfortunately, there were some issues during the entries importing process. Don\'t worry - data is not lost. Please try again later.', 'wpforms' ),
			[
				'dismiss' => Notice::DISMISS_GLOBAL,
				'slug'    => 'lite_connect_import_error_alert',
			]
		);
	}

	/**
	 * Starts the import process if needed.
	 *
	 * @since 1.7.4
	 */
	public function maybe_start_import_process() {

		if ( $this->action !== 'import' ) {
			return;
		}

		if ( ! in_array( $this->import_status, [ 'scheduled', 'running', 'done' ], true ) ) {
			( new ImportEntriesTask() )->create();
		}
	}

	/**
	 * Reset the import process if needed.
	 *
	 * @since 1.7.4
	 */
	public function maybe_reset_import() {

		if ( $this->action !== 'reset' ) {
			return;
		}

		// Clean up the entries that were imported from Lite Connect API.
		$this->integration->reset_import();

		// Reset the database flags.
		$this->reset_import_db_flag();
	}

	/**
	 * Refresh the entries count.
	 *
	 * @since 1.7.4
	 */
	public function maybe_refresh_entries_count() {

		if ( $this->action !== 'count' ) {
			return;
		}

		// Reset the database flags.
		$this->reset_import_db_flag();
	}

	/**
	 * Maybe restart the import flag.
	 *
	 * @since 1.7.4
	 */
	public function maybe_restart_import_flag() {

		if ( $this->action !== 'restart' ) {
			return;
		}

		Integration::maybe_restart_import_flag();

		$this->new_entries_count = Integration::get_new_entries_count();
	}

	/**
	 * Reset the import flags on database.
	 *
	 * @since 1.7.4
	 */
	private function reset_import_db_flag() {

		$settings = get_option( Integration::get_option_name() );

		unset( $settings['import'] );

		Transient::delete( 'lite_connect_error' );
		Transient::delete( 'lite_connect_imported_entries' );
		Transient::delete( API::LITE_CONNECT_SITE_KEY_LOCK );
		Transient::delete( API::LITE_CONNECT_ACCESS_TOKEN_LOCK );

		update_option( Integration::get_option_name(), $settings );

		$this->new_entries_count = Integration::get_new_entries_count();
	}

	/**
	 * Remove certain arguments from a query string that WordPress should always hide for users.
	 *
	 * @since 1.7.4
	 *
	 * @param array $removable_query_args An array of parameters to remove from the URL.
	 *
	 * @return array Extended/filtered array of parameters to remove from the URL.
	 */
	public function removable_query_args( $removable_query_args ) {

		$removable_query_args[] = 'wpforms_lite_connect_action';

		return $removable_query_args;
	}

	/**
	 * Add notice to the Dashboard Widget.
	 *
	 * @since 1.7.4
	 *
	 * @param string $content Content.
	 *
	 * @return string
	 */
	public function dashboard_widget_notice( $content ) {

		// Do not display the import notice on WPForms related admin pages, e.g. Entries.
		if ( wpforms_is_admin_page() ) {
			return $content;
		}

		// Do not display the import notice if AS engine is not ready to use.
		if ( ! wpforms()->get( 'tasks' )->is_usable() ) {
			return $content;
		}

		if ( $this->action === 'import' || in_array( $this->import_status, [ 'running', 'scheduled' ], true ) ) {
			return wpforms_render( 'admin/lite-connect/dashboard-widget-notice-in-progress' );
		}

		if ( empty( $this->import_status ) && (int) $this->new_entries_count > 0 ) {
			return wpforms_render(
				'admin/lite-connect/dashboard-widget-notice-restore',
				[
					'entries_since_info' => $this->get_since_info_html(),
				],
				true
			);
		}

		return $content;
	}

	/**
	 * Generate Lite Connect entries information.
	 *
	 * @since 1.7.4
	 *
	 * @return string
	 */
	private function get_since_info_html() {

		$enabled_since = Integration::get_enabled_since();

		$string = sprintf(
			esc_html( /* translators: %d - Backed up entries count. */
				_n(
					'%d form entry has been backed up',
					'%d form entries have been backed up',
					$this->new_entries_count,
					'wpforms'
				)
			),
			$this->new_entries_count
		);

		if ( ! empty( $enabled_since ) ) {
			$string .= ' ' . sprintf(
				/* translators: %s - Time when Lite Connect was enabled. */
				esc_html__( 'since you enabled Lite Connect on %s', 'wpforms' ),
				esc_html( date_i18n( 'M j, Y', $enabled_since + get_option( 'gmt_offset' ) * 3600 ) )
			);
		}

		$string .= '.';

		return $string;
	}
}
