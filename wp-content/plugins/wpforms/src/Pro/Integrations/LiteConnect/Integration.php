<?php

namespace WPForms\Pro\Integrations\LiteConnect;

use WPForms\Emails\Mailer;
use WPForms\Emails\Templates\General;
use WPForms\Helpers\Crypto;
use WPForms\Helpers\Transient;

/**
 * Class Integration.
 *
 * Integration between Lite Connect API and WPForms Pro.
 *
 * @since 1.7.4
 */
class Integration extends \WPForms\Integrations\LiteConnect\Integration {

	/**
	 * Number of tries to import before notify the user to reach out to support.
	 *
	 * @since 1.7.4
	 *
	 * @var int
	 */
	const FAILS_LIMIT = 4;

	/**
	 * ID of the forms that were already validated.
	 *
	 * @since 1.7.4
	 *
	 * @var array
	 */
	private $form_ids = [];

	/**
	 * ID of the entries that were already imported.
	 *
	 * @since 1.7.4
	 *
	 * @var array
	 */
	private $entries = [];

	/**
	 * Get the entries from Lite Connect API.
	 *
	 * @since 1.7.4
	 *
	 * @param string $last_record_id The ID of the last imported entry.
	 *
	 * @return array|false
	 */
	private function get_entries( $last_record_id = null ) {

		// We have to start requesting site keys in ajax, turning on the LC functionality.
		// First, the request to the API server will be sent.
		// Second, the server will respond to our callback URL /wpforms/auth/key/nonce, and the site key will be stored in the DB.
		// Third, we have to get access via a separate HTTP request.
		$this->update_keys(); // Third request here.

		$response = ( new API() )->retrieve_site_entries( $this->auth['access_token'], $last_record_id );

		if ( ! $response ) {
			$this->log_get_entries_error( $last_record_id, $response );

			return false;
		}

		$response = json_decode( $response, true );

		if ( ! isset( $response['entries'] ) || ! empty( $response['error'] ) ) {
			$this->log_get_entries_error( $last_record_id, $response );

			return false;
		}

		return $response;
	}

	/**
	 * Log get entries error.
	 *
	 * @since 1.7.4
	 *
	 * @param mixed $last_record_id The ID of the last imported entry.
	 * @param mixed $response       Response data.
	 */
	private function log_get_entries_error( $last_record_id, $response ) {

		wpforms_log(
			'Lite Connect: error retrieving site entries',
			[
				'response' => $response,
				'request'  => [
					'domain'      => $this->domain,
					'site_id'     => $this->site_id,
					'last_record' => $last_record_id,
				],
			],
			[
				'type' => [ 'error' ],
			]
		);
	}

	/**
	 * Retrieve entries from Lite Connect API and decrypt them.
	 *
	 * @since 1.7.4
	 *
	 * @param string $last_import_id The ID of the last imported entry.
	 *
	 * @return bool|void False on error.
	 */
	public function retrieve_and_decrypt( $last_import_id = null ) {

		$response = $this->get_entries( $last_import_id );

		if ( ! $response ) {

			// Increase the count if the API is unavailable.
			$fails = Transient::get( 'lite_connect_error' );
			$fails = $fails ? (int) $fails + 1 : 1;

			Transient::set( 'lite_connect_error', $fails );

			return false;
		}

		$this->prepare_import();

		// Import entries to the database.
		foreach ( $response['entries'] as $encrypted_entry ) {
			// Decrypts information from API.
			$entry_args = json_decode( Crypto::decrypt( $encrypted_entry['data'] ), true );
			$backup_id  = $encrypted_entry['id'];

			// If entry already exists on database, then do not import it again.
			if ( $this->backup_id_exists( $backup_id ) ) {
				continue;
			}

			// Import the entry to the database.
			$status = $this->import_entry( $entry_args, $backup_id );

			if ( ! is_array( $status ) || ! isset( $status['fields'] ) ) {
				wpforms_log(
					'Lite Connect: error importing form entry',
					[
						'reason'     => ! get_post_status( $entry_args['form_id'] ) ?
							esc_html__( 'The form no longer exists', 'wpforms' ) :
							esc_html__( 'Unknown', 'wpforms' ),
						'backup_id'  => $backup_id,
						'entry_args' => $entry_args,
					],
					[
						'type'    => 'error',
						'form_id' => $entry_args['form_id'],
					]
				);
			}
		}

		$this->end_import( $response );

		// Creates the task to add the restored flag to the Lite Connect API.
		( new AddRestoredFlagTask() )->create();
	}

	/**
	 * Import a given entry to its respective form.
	 *
	 * @since 1.7.4
	 *
	 * @param array  $entry_args The entry data.
	 * @param string $backup_id  The ID of the entry on Firestore.
	 *
	 * @return array|false False on failure.
	 */
	private function import_entry( $entry_args, $backup_id ) {

		if ( ! $this->validate_form( $entry_args['form_id'] ) ) {
			return false;
		}

		$entry_id = wpforms()->get( 'entry' )->add( $entry_args );

		if ( ! $entry_id ) {
			return false;
		}

		$fields     = json_decode( $entry_args['fields'], true );
		$submission = wpforms()->get( 'submission' );

		$submission->register( $fields, [], $entry_args['form_id'], $entry_args['form_data'] );
		$submission->create_fields( $entry_id );

		// Add the ID of the entry on Firestore as an entry meta.
		wpforms()->get( 'entry_meta' )->add(
			[
				'entry_id' => $entry_id,
				'form_id'  => $entry_args['form_id'],
				'type'     => 'backup_id',
				'data'     => $backup_id,
			],
			'entry_meta'
		);

		// Add entry ID to a WPForms Transient, so we can revert in case a new import is required.
		$this->save_imported_id( $entry_id );

		return [
			'fields'    => $fields,
			'form_id'   => $entry_args['form_id'],
			'form_data' => $entry_args['form_data'],
		];
	}

	/**
	 * Remove from database all the entries that were imported from Lite
	 * Connect API.
	 *
	 * Please be very careful using this reset, given that Lite Connect API store
	 * the entries only for the past 365 days, which means some older entries
	 * may be unavailable remotely.
	 *
	 * @since 1.7.4
	 */
	public function reset_import() {

		$this->entries = Transient::get( 'lite_connect_imported_entries' );

		if ( ! is_array( $this->entries ) ) {
			return;
		}

		foreach ( $this->entries as $entry_id ) {
			if ( empty( $entry_id ) ) {
				continue;
			}

			wpforms()->get( 'entry' )->delete_where_in( 'entry_id', $entry_id );
			wpforms()->get( 'entry_meta' )->delete_where_in( 'entry_id', $entry_id );
			wpforms()->get( 'entry_fields' )->delete_where_in( 'entry_id', $entry_id );
		}
	}

	/**
	 * Confirm if the number of fails has reached the limit.
	 *
	 * @since 1.7.4
	 *
	 * @return bool
	 */
	public function has_reached_fail_limit() {

		$fails = Transient::get( 'lite_connect_error' );

		if ( ! $fails ) {
			return false;
		}

		return (int) $fails > self::FAILS_LIMIT;
	}

	/**
	 * Confirm if a given form exists.
	 *
	 * @since 1.7.4
	 *
	 * @param int $form_id The form ID.
	 *
	 * @return bool
	 */
	private function validate_form( $form_id ) {

		// If the form ID was already validated previously, won't call the database again.
		if ( in_array( $form_id, $this->form_ids, true ) ) {
			return true;
		}

		// If the post type is 'wpforms', then it will add the form ID to the list of validated forms and return.
		if ( trim( get_post_type( $form_id ) ) === 'wpforms' ) {
			$this->form_ids[] = $form_id;

			return true;
		}

		return false;
	}

	/**
	 * Saves the ID of an imported entry.
	 *
	 * @since 1.7.4
	 *
	 * @param int $id The ID of the entry.
	 */
	private function save_imported_id( $id ) {

		if ( empty( $this->entries ) ) {
			$this->entries = Transient::get( 'lite_connect_imported_entries' );

			if ( $this->entries === false ) {
				$this->entries = [];
			}
		}

		$this->entries[] = $id;

		Transient::set( 'lite_connect_imported_entries', $this->entries );
	}

	/**
	 * Prepares the import metadata.
	 *
	 * @since 1.7.4
	 */
	private function prepare_import() {

		$settings = get_option( self::get_option_name(), [] );

		if ( ! isset( $settings['import'] ) ) {
			$settings['import'] = [];
		}

		if ( ! isset( $settings['import']['started_at'] ) ) {
			$settings['import']['started_at'] = time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		}

		if ( ! isset( $settings['import']['pages'] ) ) {
			$settings['import']['pages'] = 1;
		}

		// Change import status to 'running'.
		$settings['import']['status'] = 'running';

		update_option( self::get_option_name(), $settings );
	}

	/**
	 * Ends the current import and determines if there is a next page to import.
	 *
	 * @since 1.7.4
	 *
	 * @param array $response The API response.
	 */
	private function end_import( $response ) {

		// Update the settings as needed.
		$settings = get_option( self::get_option_name(), [] );

		// If size is equal to total, then go to next page.
		if ( (int) $response['size'] === (int) $response['total'] ) {
			$settings['import']['status']         = 'scheduled';
			$settings['import']['last_import_id'] = end( $response['entries'] )['id'];

			$settings['import']['pages']++;

			( new ImportEntriesTask() )->create( $settings['import']['last_import_id'] );

		} else {

			// Change import status to 'done'.
			$settings['import']['status']   = 'done';
			$settings['import']['ended_at'] = time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

			// Send email notification about import completion.
			$this->send_email_notification();
		}

		update_option( self::get_option_name(), $settings );
	}

	/**
	 * Checks if a given Firestore ID was already imported to the database previously.
	 *
	 * @since 1.7.4
	 *
	 * @param string $backup_id The backup ID.
	 *
	 * @return bool True if the backup ID exists on database.
	 */
	private function backup_id_exists( $backup_id ) {

		$entry_id = wpforms()->get( 'entry_meta' )->get_meta(
			[
				'type' => 'backup_id',
				'data' => $backup_id,
			],
			true
		);

		return ! empty( $entry_id );
	}

	/**
	 * Add restored flag to the Lite Connect API.
	 *
	 * @since 1.7.4
	 *
	 * @return bool True if the restored flag has been added successfully to the API.
	 */
	public function add_restored_flag() {

		// We have to start requesting site keys in ajax, turning on the LC functionality.
		// First, the request to the API server will be sent.
		// Second, the server will respond to our callback URL /wpforms/auth/key/nonce, and the site key will be stored in the DB.
		// Third, we have to get access via a separate HTTP request.
		$this->update_keys(); // Third request here.

		$response = ( new API() )->add_restored_flag( $this->auth['site_key'] );

		if ( ! $response ) {
			return false;
		}

		$response = json_decode( $response, true );

		return ( isset( $response['status'] ) && $response['status'] === 'success' );
	}

	/**
	 * Send an email notification when import is complete.
	 *
	 * @since 1.7.4
	 */
	private function send_email_notification() {

		$to_email = self::get_enabled_email();
		$subject  = esc_html__( 'Your form entries have been restored successfully!', 'wpforms' );

		$message = sprintf(
			'<strong>%s</strong><br><br>',
			$subject
		);

		$message .= sprintf(
			wp_kses( /* translators: %s - WPForms Entries Overview admin page URL. */
				__( 'You can view your form entries inside the WordPress Dashboard from the <a href="%s" rel="noreferrer noopener" target="_blank">Entries Overview report</a>.', 'wpforms' ),
				[
					'a' => [
						'href'   => [],
						'rel'    => [],
						'target' => [],
					],
				]
			),
			admin_url( 'admin.php?page=wpforms-entries' )
		);

		$template = new General( $message );

		( new Mailer() )
			->template( $template )
			->subject( $subject )
			->to_email( $to_email )
			->send();
	}
}
