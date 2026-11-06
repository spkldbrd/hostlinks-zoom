<?php
/**
 * Hostlinks Zoom admin screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_head', array( __CLASS__, 'styles' ) );
	}

	public static function register_menus(): void {
		add_menu_page(
			'Hostlinks Zoom',
			'Hostlinks Zoom',
			'manage_options',
			'hostlinks-zoom',
			array( __CLASS__, 'render_screen' ),
			'dashicons-video-alt2',
			59
		);
		add_submenu_page(
			'hostlinks-zoom',
			'Zoom Recordings',
			'Recordings',
			'manage_options',
			'hostlinks-zoom',
			array( __CLASS__, 'render_screen' )
		);
		add_submenu_page(
			'hostlinks-zoom',
			'Zoom Email Templates',
			'Templates',
			'manage_options',
			'hostlinks-zoom-templates',
			array( __CLASS__, 'render_templates' )
		);
		add_submenu_page(
			'hostlinks-zoom',
			'Zoom Settings',
			'Settings',
			'manage_options',
			'hostlinks-zoom-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function styles(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! str_starts_with( $page, 'hostlinks-zoom' ) ) {
			return;
		}
		echo '<style>
			.hlz-wrap .hlz-card{background:#fff;border:1px solid #c3c4c7;padding:16px 18px;margin:16px 0}
			.hlz-wrap table.widefat td,.hlz-wrap table.widefat th{vertical-align:top}
			.hlz-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
			.hlz-actions form{margin:0}
			.hlz-preview{width:100%;max-width:760px;min-height:420px;font-family:Consolas,monospace}
			.hlz-note{width:100%;max-width:760px;min-height:80px}
			.hlz-mail-preview{max-width:760px;background:#fff;border:1px solid #c3c4c7;padding:18px 20px}
			.hlz-mail-preview a{word-break:break-all}
			.hlz-body{width:100%;min-height:480px;font-family:Consolas,monospace}
			.hlz-muted{color:#646970}
			.hlz-status{display:inline-block;padding:1px 8px;border-radius:10px;background:#f0f0f1}
			.hlz-status-shared{background:#d5f5e3}
			.hlz-status-prepared{background:#fff3cd}
			.hlz-status-off{background:#f8d7da}
			.hlz-status-matched{background:#d5f5e3}
			.hlz-status-partial{background:#fff3cd}
			.hlz-status-unmatched{background:#f8d7da}
		</style>';
	}

	public static function handle_post(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! str_starts_with( $page, 'hostlinks-zoom' ) ) {
			return;
		}
		if ( empty( $_POST['hlz_action'] ) ) {
			return;
		}

		$action       = sanitize_key( wp_unslash( $_POST['hlz_action'] ) );
		$nonce_action = $action;
		if ( $action === 'test_connection' ) {
			$nonce_action = 'save_settings';
		} elseif ( $action === 'resend_failures' || $action === 'update_preview' || $action === 'send_test' ) {
			$nonce_action = 'send';
		} elseif ( $action === 'replace_passcode' || $action === 'save_passcode' ) {
			$nonce_action = 'passcode';
		}
		check_admin_referer( 'hlz_' . $nonce_action );

		switch ( $action ) {
			case 'save_settings':
				self::post_save_settings( false );
				break;
			case 'test_connection':
				self::post_save_settings( true );
				break;
			case 'save_template':
				self::post_save_template();
				break;
			case 'match_all':
				self::post_match_all();
				break;
			case 'save_match':
				self::post_save_match();
				break;
			case 'save_passcode':
				self::post_save_passcode();
				break;
			case 'replace_passcode':
				self::post_replace_passcode();
				break;
			case 'prepare':
				self::post_prepare();
				break;
			case 'update_preview':
				self::post_update_preview();
				break;
			case 'send_test':
				self::post_send_test();
				break;
			case 'send':
				self::post_send( false );
				break;
			case 'resend_failures':
				self::post_send( true );
				break;
			case 'extend':
				self::post_share( 'extend' );
				break;
			case 'turn_off':
				self::post_share( 'turn_off' );
				break;
			case 'save_template_choice':
				self::post_template_choice();
				break;
		}
	}

	private static function post_save_settings( bool $test ): void {
		HLZ_Settings::update( wp_unslash( $_POST ) );
		if ( ! $test ) {
			self::redirect_settings( 'Settings saved.' );
		}
		$result = HLZ_Zoom::test_connection();
		self::redirect_settings( $result['message'], ! $result['ok'] );
	}

	private static function post_save_template(): void {
		$slug    = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$body    = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );
		if ( $subject === '' || trim( wp_strip_all_tags( $body ) ) === '' ) {
			self::redirect_template( $slug, 'Subject and body are required.', true );
		}
		$ok = HLZ_Templates::save( $slug, $subject, $body );
		self::redirect_template( $slug, $ok ? 'Template saved.' : 'Template was not found.', ! $ok );
	}

	private static function post_match_all(): void {
		$days   = self::days_from_request();
		$since  = HLZ_Bridge::shift_date( wp_date( 'Y-m-d' ), -1 * $days );
		$events = HLZ_Bridge::past_zoom_events( $since );
		$result = HLZ_Matcher::sync_events( $events );
		if ( is_wp_error( $result ) ) {
			self::redirect_list( $days, $result->get_error_message(), true );
		}
		$message = sprintf(
			'Matched %1$d, partial %2$d, still unmatched %3$d.',
			(int) $result['matched'],
			(int) $result['partial'],
			(int) $result['unmatched']
		);
		self::redirect_list( $days, $message );
	}

	private static function post_save_match(): void {
		$eve_id = absint( $_POST['eve_id'] ?? 0 );
		$event  = HLZ_Bridge::get_event( $eve_id );
		if ( ! $event ) {
			self::redirect_list( self::days_from_request(), 'Class not found.', true );
		}
		$window   = HLZ_Matcher::window( $event );
		$meetings = HLZ_Zoom::list_recordings( $window['from'], $window['to'] );
		if ( is_wp_error( $meetings ) ) {
			self::redirect_preview( $eve_id, $meetings->get_error_message(), true );
		}
		$chosen = array();
		foreach ( HLZ_Bridge::slots( $event ) as $slot ) {
			$day            = (int) $slot['day_index'];
			$chosen[ $day ] = sanitize_text_field( wp_unslash( $_POST[ 'meeting_' . $day ] ?? '' ) );
		}
		HLZ_Matcher::save_manual( $event, $chosen, $meetings );
		self::redirect_preview( $eve_id, 'Recording choice saved.' );
	}

	private static function post_prepare(): void {
		$eve_id = absint( $_POST['eve_id'] ?? 0 );
		$result = HLZ_Shares::prepare_event( $eve_id );
		if ( is_wp_error( $result ) ) {
			self::redirect_preview( $eve_id, $result->get_error_message(), true );
		}
		$needs = ! empty( $result['needs_passcode'] );
		self::redirect_preview( $eve_id, self::prepare_message( $result ), $needs );
	}

	/**
	 * @param array{kept:int,created:int,needs_passcode:int} $result
	 */
	private static function prepare_message( array $result ): string {
		$parts = array();
		if ( ! empty( $result['kept'] ) ) {
			$parts[] = 'Zoom already had a share with a passcode, so that link was left unchanged.';
		}
		if ( ! empty( $result['created'] ) ) {
			$parts[] = 'A new share link was created.';
		}
		if ( ! empty( $result['needs_passcode'] ) ) {
			$parts[] = 'Zoom already shared a recording but did not return the passcode. Enter it below, or create a new one.';
		}
		if ( empty( $parts ) ) {
			return 'Share links are ready. The 30-day clock starts when you send to the class.';
		}
		return implode( ' ', $parts );
	}

	private static function post_save_passcode(): void {
		$eve_id = absint( $_POST['eve_id'] ?? 0 );
		$result = HLZ_Shares::save_known_passcode(
			absint( $_POST['recording_id'] ?? 0 ),
			sanitize_text_field( wp_unslash( $_POST['passcode'] ?? '' ) )
		);
		if ( is_wp_error( $result ) ) {
			self::redirect_preview( $eve_id, $result->get_error_message(), true );
		}
		self::redirect_preview( $eve_id, 'Existing passcode saved. Zoom was not changed.' );
	}

	private static function post_replace_passcode(): void {
		$eve_id = absint( $_POST['eve_id'] ?? 0 );
		$row    = HLZ_Shares::get( absint( $_POST['recording_id'] ?? 0 ) );
		if ( ! $row ) {
			self::redirect_preview( $eve_id, 'Recording not found.', true );
		}
		$result = HLZ_Shares::prepare( $row, true );
		if ( is_wp_error( $result ) ) {
			self::redirect_preview( $eve_id, $result->get_error_message(), true );
		}
		self::redirect_preview( $eve_id, 'A new passcode was created. The previous Zoom passcode no longer works.' );
	}

	private static function post_update_preview(): void {
		$eve_id = absint( $_POST['eve_id'] ?? 0 );
		HLZ_Mailer::remember_note( $eve_id, self::note_from_post() );
		self::redirect_preview( $eve_id, 'Preview updated.' );
	}

	private static function post_send_test(): void {
		$eve_id = absint( $_POST['eve_id'] ?? 0 );
		$event  = HLZ_Bridge::get_event( $eve_id );
		if ( ! $event ) {
			self::redirect_list( self::days_from_request(), 'Class not found.', true );
		}

		$note = self::note_from_post();
		HLZ_Mailer::remember_note( $eve_id, $note );

		$template = HLZ_Mailer::template_for_event( $event );
		if ( ! $template ) {
			self::redirect_preview( $eve_id, 'Choose an email template for this class before sending a test.', true );
		}

		$to     = sanitize_email( wp_unslash( $_POST['hlz_test_email'] ?? '' ) );
		$result = HLZ_Mailer::send_test( $event, $template, HLZ_Shares::for_event( $eve_id ), $note, $to );
		if ( is_wp_error( $result ) ) {
			self::redirect_preview( $eve_id, $result->get_error_message(), true );
		}
		self::redirect_preview( $eve_id, 'Test sent to ' . $to . '. The class was not emailed.' );
	}

	private static function post_send( bool $failures_only ): void {
		$eve_id = absint( $_POST['eve_id'] ?? 0 );
		$event  = HLZ_Bridge::get_event( $eve_id );
		if ( ! $event ) {
			self::redirect_list( self::days_from_request(), 'Class not found.', true );
		}

		$note = self::note_from_post();
		HLZ_Mailer::remember_note( $eve_id, $note );

		$template = HLZ_Mailer::template_for_event( $event );
		if ( ! $template ) {
			self::redirect_preview( $eve_id, 'Choose an email template for this class before sending.', true );
		}

		$prepared = HLZ_Shares::prepare_event( $eve_id );
		if ( is_wp_error( $prepared ) ) {
			$rows = HLZ_Shares::for_event( $eve_id );
			if ( ! self::links_ready( $event, $rows ) ) {
				self::redirect_preview( $eve_id, $prepared->get_error_message(), true );
			}
		}

		$rows = HLZ_Shares::for_event( $eve_id );
		if ( ! self::links_ready( $event, $rows ) ) {
			$needs_passcode = false;
			foreach ( $rows as $row ) {
				if ( in_array( (string) ( $row->share_origin ?? '' ), array( 'needs_passcode', 'needs_passcode_internal' ), true ) ) {
					$needs_passcode = true;
				}
			}
			self::redirect_preview(
				$eve_id,
				$needs_passcode
					? 'Enter the existing Zoom passcode before sending to the class.'
					: 'Each day of this class needs a share link before sending.',
				true
			);
		}

		$people = HLZ_Recipients::for_event( $event );
		if ( is_wp_error( $people ) ) {
			self::redirect_preview( $eve_id, $people->get_error_message(), true );
		}
		if ( empty( $people ) ) {
			self::redirect_preview( $eve_id, 'No active registrants with an email address.', true );
		}

		$result = HLZ_Mailer::send( $event, $template, $rows, $people, $note, $failures_only );
		$message = sprintf(
			'Sent %1$d, failed %2$d, skipped %3$d.',
			$result['sent'],
			$result['failed'],
			$result['skipped']
		);
		self::redirect_preview( $eve_id, $message, $result['failed'] > 0 && $result['sent'] === 0 );
	}

	private static function post_share( string $which ): void {
		$id     = absint( $_POST['recording_id'] ?? 0 );
		$result = ( $which === 'extend' ) ? HLZ_Shares::extend( $id ) : HLZ_Shares::turn_off( $id );
		$eve_id = absint( $_POST['eve_id'] ?? 0 );
		$ok     = ! is_wp_error( $result );
		$text   = $ok
			? ( $which === 'extend' ? 'Share extended 30 days from today.' : 'Share link turned off.' )
			: $result->get_error_message();
		if ( $eve_id > 0 && isset( $_POST['hlz_return'] ) && sanitize_key( wp_unslash( $_POST['hlz_return'] ) ) === 'preview' ) {
			self::redirect_preview( $eve_id, $text, ! $ok );
		}
		self::redirect_list( self::days_from_request(), $text, ! $ok );
	}

	private static function post_template_choice(): void {
		$eve_id = absint( $_POST['eve_id'] ?? 0 );
		$slug   = sanitize_key( wp_unslash( $_POST['template_slug'] ?? '' ) );
		if ( $slug !== '' && ! HLZ_Templates::get( $slug ) ) {
			self::redirect_preview( $eve_id, 'That template does not exist.', true );
		}
		HLZ_Mailer::set_template_override( $eve_id, $slug );
		self::redirect_preview( $eve_id, 'Template choice saved.' );
	}

	public static function render_recordings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! HLZ_Bridge::is_hostlinks_active() ) {
			self::page_open( 'Hostlinks Zoom' );
			echo '<div class="notice notice-error"><p>Hostlinks Zoom needs the Hostlinks plugin to be active.</p></div>';
			self::page_close();
			return;
		}

		$days   = self::days_from_request();
		$since  = HLZ_Bridge::shift_date( wp_date( 'Y-m-d' ), -1 * $days );
		$events = HLZ_Bridge::past_zoom_events( $since );
		$ids    = array_map( static function ( $event ) {
			return (int) $event['eve_id'];
		}, $events );
		$stored = HLZ_Shares::for_events( $ids );
		$shared = HLZ_Shares::manageable();
		$shared_events = HLZ_Bridge::get_events_by_ids(
			array_map( static function ( $row ) {
				return (int) $row->eve_id;
			}, $shared )
		);

		self::page_open( 'Hostlinks Zoom' );
		self::render_flash();
		if ( ! HLZ_Settings::has_credentials() ) {
			echo '<div class="notice notice-warning"><p>Add the Zoom app credentials under <a href="' . esc_url( admin_url( 'admin.php?page=hostlinks-zoom-settings' ) ) . '">Settings</a> before matching recordings.</p></div>';
		}

		echo '<div class="hlz-card">';
		echo '<h2>Currently shared</h2>';
		echo '<p class="hlz-muted">Public links that were prepared or sent, plus links turned off in the last 120 days. Extend keeps the same passcode and sets a new 30 days from today.</p>';
		self::render_shared_table( $shared, $shared_events, 0 );
		echo '</div>';

		echo '<div class="hlz-card">';
		echo '<h2>Past Zoom classes</h2>';
		echo '<form method="get" class="hlz-actions" style="margin-bottom:12px">';
		echo '<input type="hidden" name="page" value="hostlinks-zoom">';
		echo '<label for="hlz-days">Ended within</label> ';
		echo '<select id="hlz-days" name="days">';
		foreach ( array( 30, 90, 120, 180, 365 ) as $option ) {
			echo '<option value="' . esc_attr( (string) $option ) . '" ' . selected( $days, $option, false ) . '>' . esc_html( (string) $option ) . ' days</option>';
		}
		echo '</select> ';
		submit_button( 'Show', 'secondary', '', false );
		echo '</form>';

		echo '<form method="post" class="hlz-actions" style="margin-bottom:12px">';
		wp_nonce_field( 'hlz_match_all' );
		echo '<input type="hidden" name="hlz_action" value="match_all">';
		echo '<input type="hidden" name="days" value="' . esc_attr( (string) $days ) . '">';
		submit_button( 'Match recordings', 'primary', 'submit', false );
		echo '<span class="hlz-muted">Matches by class date and title (Grant Writing, Grant Management, Subaward, Deep Dive Session 1–3).</span>';
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Class</th><th>Dates</th><th>Recordings</th><th>Match</th><th>Share</th><th></th>';
		echo '</tr></thead><tbody>';
		if ( empty( $events ) ) {
			echo '<tr><td colspan="6">No past Zoom classes with a Cvent link in this window.</td></tr>';
		}
		foreach ( $events as $event ) {
			$eve_id = (int) $event['eve_id'];
			$rows   = $stored[ $eve_id ] ?? array();
			$state  = HLZ_Matcher::match_state( $event, $rows );
			$preview = add_query_arg(
				array(
					'page'   => 'hostlinks-zoom',
					'eve_id' => $eve_id,
				),
				admin_url( 'admin.php' )
			);
			echo '<tr>';
			echo '<td>' . esc_html( HLZ_Bridge::event_title( $event ) ) . '</td>';
			echo '<td>' . esc_html( HLZ_Bridge::format_date_range( (string) $event['eve_start'], (string) $event['eve_end'] ) ) . '</td>';
			echo '<td>' . esc_html( self::recording_count_label( $event ) ) . '</td>';
			echo '<td><span class="hlz-status hlz-status-' . esc_attr( $state ) . '">' . esc_html( self::state_label( $state ) ) . '</span></td>';
			echo '<td>' . esc_html( self::share_label( $rows ) ) . '</td>';
			echo '<td><a class="button" href="' . esc_url( $preview ) . '">Preview</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		self::page_close();
	}

	public static function render_templates(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		HLZ_Templates::seed_missing();
		$templates = HLZ_Templates::all();
		$slug      = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';
		$current   = $slug !== '' ? HLZ_Templates::get( $slug ) : null;
		if ( ! $current && ! empty( $templates ) ) {
			$current = $templates[0];
		}

		self::page_open( 'Email templates' );
		self::render_flash();
		echo '<div class="hlz-card"><p>One template per class type. Use the bold button for emphasis. <code>{zoom}</code> becomes the link and passcode. <code>{dates}</code> is the class date. <code>{note}</code> is the one-off message from the preview, and is removed when that box is empty. Paste each class type’s materials URL over <code>PASTE MATERIALS URL HERE</code>.</p>';
		echo '<ul>';
		foreach ( $templates as $template ) {
			$url = add_query_arg(
				array(
					'page'     => 'hostlinks-zoom-templates',
					'template' => $template->slug,
				),
				admin_url( 'admin.php' )
			);
			$mark = ( $current && $current->slug === $template->slug ) ? ' <strong>(editing)</strong>' : '';
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $template->label ) . '</a>' . $mark . '</li>';
		}
		echo '</ul></div>';

		if ( $current ) {
			echo '<div class="hlz-card">';
			echo '<h2>' . esc_html( $current->label ) . '</h2>';
			echo '<p class="hlz-muted">Title match: ' . esc_html( $current->match_phrase ) . '</p>';
			echo '<form method="post">';
			wp_nonce_field( 'hlz_save_template' );
			echo '<input type="hidden" name="hlz_action" value="save_template">';
			echo '<input type="hidden" name="slug" value="' . esc_attr( $current->slug ) . '">';
			echo '<p><label for="hlz-subject"><strong>Subject</strong></label><br>';
			echo '<input id="hlz-subject" name="subject" type="text" class="regular-text" style="width:100%;max-width:760px" value="' . esc_attr( $current->subject ) . '"></p>';
			echo '<p><strong>Body</strong></p>';
			wp_editor(
				HLZ_Mailer::body_for_editor( (string) $current->body ),
				'hlzbody',
				array(
					'textarea_name' => 'body',
					'textarea_rows' => 22,
					'media_buttons' => false,
					'teeny'         => false,
					'quicktags'     => true,
					'tinymce'       => array(
						'toolbar1'     => 'bold,italic,link,unlink,bullist,numlist,undo,redo',
						'toolbar2'     => '',
						'block_formats'=> 'Paragraph=p',
					),
				)
			);
			submit_button( 'Save template' );
			echo '</form></div>';
		}
		self::page_close();
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = HLZ_Settings::get();
		self::page_open( 'Zoom settings' );
		self::render_flash();
		echo '<div class="hlz-card">';
		echo '<p>Create a Server-to-Server OAuth app in the Zoom App Marketplace. Give it these scopes:</p>';
		echo '<ul><li><code>cloud_recording:read:list_user_recordings:admin</code></li>';
		echo '<li><code>cloud_recording:read:list_recording_files:admin</code></li>';
		echo '<li><code>cloud_recording:read:recording_settings:admin</code></li>';
		echo '<li><code>cloud_recording:update:recording_settings:admin</code></li></ul>';
		echo '<p>The Zoom role also needs permission to view recording content. That permission lets the plugin read an existing passcode instead of replacing it. Viewers can see the chat is an account setting for newly generated recordings. Zoom’s per-recording API cannot turn that on.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'hlz_save_settings' );
		echo '<input type="hidden" name="hlz_form" value="settings">';
		echo '<table class="form-table"><tbody>';
		self::text_row( 'account_id', 'Account ID', $settings['account_id'] );
		self::text_row( 'client_id', 'Client ID', $settings['client_id'] );
		echo '<tr><th scope="row"><label for="client_secret">Client Secret</label></th><td>';
		echo '<input name="client_secret" id="client_secret" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="' . esc_attr( $settings['client_secret'] !== '' ? 'Saved — leave blank to keep' : '' ) . '">';
		echo '</td></tr>';
		self::text_row( 'host_email', 'Zoom host email', $settings['host_email'] );
		self::text_row( 'from_name', 'From name', $settings['from_name'] );
		self::text_row( 'from_email', 'From email', $settings['from_email'] );
		echo '</tbody></table>';
		echo '<p class="hlz-muted">Mail is sent with WordPress <code>wp_mail</code> through the SMTP2GO plugin already on this site.</p>';
		echo '<button type="submit" class="button button-primary" name="hlz_action" value="save_settings">Save settings</button> ';
		echo '<button type="submit" class="button" name="hlz_action" value="test_connection">Save and test connection</button>';
		echo '</form></div>';
		self::page_close();
	}

	private static function render_preview( int $eve_id ): void {
		$event = HLZ_Bridge::get_event( $eve_id );
		self::page_open( 'Preview email' );
		self::render_flash();
		if ( ! $event ) {
			echo '<div class="notice notice-error"><p>Class not found.</p></div>';
			self::page_close();
			return;
		}

		$meetings = array();
		if ( HLZ_Settings::has_credentials() ) {
			$synced = HLZ_Matcher::sync_event( $event );
			if ( is_wp_error( $synced ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $synced->get_error_message() ) . '</p></div>';
			} else {
				$meetings = $synced;
			}
		}

		$rows = HLZ_Shares::for_event( $eve_id );
		if ( self::should_prepare( $event, $rows ) ) {
			$prepared = HLZ_Shares::prepare_event( $eve_id );
			if ( is_wp_error( $prepared ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $prepared->get_error_message() ) . '</p></div>';
			}
			$rows = HLZ_Shares::for_event( $eve_id );
		}

		self::render_existing_share_notice( $rows );

		$choices = HLZ_Matcher::dropdown_meetings( $event, $meetings );

		$title = HLZ_Bridge::event_title( $event );
		$dates = HLZ_Bridge::format_date_range( (string) $event['eve_start'], (string) $event['eve_end'] );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=hostlinks-zoom' ) ) . '">&larr; All classes</a></p>';
		echo '<div class="hlz-card"><h2>' . esc_html( $title ) . '</h2>';
		echo '<p>' . esc_html( $dates ) . ' · ' . esc_html( self::recording_count_label( $event ) ) . '</p>';
		echo '<p class="hlz-muted">Share settings: anyone with the link, passcode required, download off, no registration to watch. The 30 days start when you press Send.</p>';
		echo '</div>';

		self::render_template_picker( $event );
		self::render_match_form( $event, $rows, $choices );
		self::render_link_summary( $event, $rows );

		$template = HLZ_Mailer::template_for_event( $event );
		$people   = HLZ_Recipients::for_event( $event );
		$sends    = HLZ_Shares::sends_for_event( $eve_id );
		$by_email = array();
		foreach ( $sends as $send ) {
			$by_email[ strtolower( (string) $send->email ) ] = $send;
		}

		echo '<div class="hlz-card"><h2>Attendees</h2>';
		if ( is_wp_error( $people ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $people->get_error_message() ) . '</p></div>';
		} else {
			echo '<p>' . count( $people ) . ' active registrant' . ( count( $people ) === 1 ? '' : 's' ) . '. Canceled orders and canceled registrations are excluded. Each person is emailed separately.</p>';
			echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Send</th></tr></thead><tbody>';
			if ( empty( $people ) ) {
				echo '<tr><td colspan="4">No one to email.</td></tr>';
			}
			foreach ( $people as $person ) {
				$prior = $by_email[ strtolower( $person['email'] ) ] ?? null;
				echo '<tr>';
				echo '<td>' . esc_html( $person['name'] !== '' ? $person['name'] : '—' ) . '</td>';
				echo '<td>' . esc_html( $person['email'] ) . '</td>';
				echo '<td>' . esc_html( $person['status'] ) . '</td>';
				echo '<td>' . esc_html( self::send_state_label( $prior ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		$note = HLZ_Mailer::remembered_note( $eve_id );
		echo '<div class="hlz-card"><h2>Email</h2>';
		if ( ! $template ) {
			echo '<p>No template matches this class title. Choose one above, or add the class phrase to the Cvent title.</p>';
		} else {
			echo '<p><strong>From:</strong> ' . esc_html( HLZ_Settings::get()['from_name'] . ' <' . HLZ_Settings::get()['from_email'] . '>' ) . '</p>';
			echo '<p><strong>Subject:</strong> ' . esc_html( HLZ_Mailer::render_subject( $template, $event ) ) . '</p>';
			$preview = HLZ_Mailer::render( $template, $event, $rows, $note );
			$me      = wp_get_current_user();
			echo '<form method="post">';
			wp_nonce_field( 'hlz_send' );
			echo '<input type="hidden" name="eve_id" value="' . esc_attr( (string) $eve_id ) . '">';
			echo '<p><label for="hlz-note"><strong>Special message</strong></label><br>';
			echo '<textarea id="hlz-note" name="hlz_note" class="hlz-note">' . esc_textarea( $note ) . '</textarea></p>';
			echo '<p><strong>Preview</strong></p>';
			echo '<div class="hlz-mail-preview">' . wp_kses_post( $preview ) . '</div>';
			echo '<p class="hlz-actions" style="margin-top:12px">';
			echo '<button type="submit" class="button" name="hlz_action" value="update_preview">Update preview</button>';
			echo '</p>';
			echo '<p class="hlz-actions">';
			echo '<label for="hlz-test-email">Test address</label> ';
			echo '<input id="hlz-test-email" name="hlz_test_email" type="email" class="regular-text" value="' . esc_attr( (string) $me->user_email ) . '"> ';
			echo '<button type="submit" class="button" name="hlz_action" value="send_test">Send test</button>';
			echo '</p>';
			echo '<p class="hlz-muted">The test goes only to that address. It does not email the class and does not start the 30-day clock.</p>';
			echo '<p class="hlz-actions">';
			echo '<button type="submit" class="button button-primary" name="hlz_action" value="send">Send</button> ';
			echo '<button type="submit" class="button" name="hlz_action" value="resend_failures">Resend failures</button>';
			echo '</p>';
			echo '<p class="hlz-muted">Send skips anyone already emailed, including earlier failures. Resend failures tries only the addresses that did not go out.</p>';
			echo '</form>';
			echo '<form method="post" style="margin-top:8px">';
			wp_nonce_field( 'hlz_prepare' );
			echo '<input type="hidden" name="hlz_action" value="prepare">';
			echo '<input type="hidden" name="eve_id" value="' . esc_attr( (string) $eve_id ) . '">';
			submit_button( 'Prepare share links again', 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
		self::page_close();
	}

	/**
	 * @param array<int,object> $rows
	 * @param array<int,array<string,mixed>> $choices
	 */
	private static function render_match_form( array $event, array $rows, array $choices ): void {
		$eve_id = (int) $event['eve_id'];
		echo '<div class="hlz-card"><h2>Recordings</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'hlz_save_match' );
		echo '<input type="hidden" name="hlz_action" value="save_match">';
		echo '<input type="hidden" name="eve_id" value="' . esc_attr( (string) $eve_id ) . '">';
		echo '<table class="widefat"><thead><tr><th>Day</th><th>Recording</th><th>Link</th></tr></thead><tbody>';
		foreach ( HLZ_Bridge::slots( $event ) as $slot ) {
			$day = (int) $slot['day_index'];
			$row = $rows[ $day ] ?? null;
			$locked = $row && (string) $row->status !== '';
			echo '<tr><td>';
			if ( $slot['date'] !== '' ) {
				echo esc_html( 'Day ' . $day . ' · ' . HLZ_Bridge::format_day( (string) $slot['date'] ) );
			} else {
				echo esc_html( 'Day ' . $day );
			}
			if ( ! $slot['auto'] ) {
				echo '<br><span class="hlz-muted">Manual — this class is not 1 or 2 days.</span>';
			}
			echo '</td><td>';
			if ( $locked ) {
				echo esc_html( (string) $row->topic !== '' ? $row->topic : (string) $row->meeting_id );
				echo '<br><span class="hlz-muted">Turn the share off before choosing a different recording.</span>';
			} else {
				$selected = $row ? (string) $row->meeting_id : '';
				echo '<select name="meeting_' . esc_attr( (string) $day ) . '">';
				echo '<option value="">— Not matched —</option>';
				$seen = array();
				foreach ( $choices as $meeting ) {
					$id = (string) $meeting['id'];
					$seen[ $id ] = true;
					echo '<option value="' . esc_attr( $id ) . '" ' . selected( $selected, $id, false ) . '>' . esc_html( self::meeting_label( $meeting ) ) . '</option>';
				}
				if ( $selected !== '' && empty( $seen[ $selected ] ) ) {
					$label = $row && (string) $row->topic !== '' ? (string) $row->topic : $selected;
					echo '<option value="' . esc_attr( $selected ) . '" selected>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
			}
			echo '</td><td>';
			if ( $row && trim( (string) $row->share_url ) !== '' ) {
				echo '<span class="hlz-status hlz-status-' . esc_attr( (string) $row->status ) . '">' . esc_html( self::status_label( (string) $row->status ) ) . '</span><br>';
				echo '<a href="' . esc_url( (string) $row->share_url ) . '">' . esc_html( (string) $row->share_url ) . '</a>';
				echo '<br>Passcode: ' . esc_html( (string) $row->passcode );
				if ( in_array( (string) ( $row->share_origin ?? '' ), array( 'existing', 'existing_internal' ), true ) ) {
					echo '<br><span class="hlz-muted">Existing Zoom share kept.</span>';
				}
				if ( (string) $row->expires_at !== '' ) {
					echo '<br>Expires ' . esc_html( (string) $row->expires_at );
				}
				if ( (string) $row->last_error !== '' ) {
					echo '<br><span class="hlz-muted">' . esc_html( (string) $row->last_error ) . '</span>';
				}
			} else {
				echo '<span class="hlz-muted">Not prepared</span>';
				if ( $row && (string) $row->last_error !== '' ) {
					echo '<br>' . esc_html( (string) $row->last_error );
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( 'Save recording choice' );
		echo '</form>';
		self::render_passcode_forms( $rows );
		echo '</div>';
	}

	/**
	 * @param array<int,object> $rows
	 */
	private static function render_existing_share_notice( array $rows ): void {
		$kept     = false;
		$internal = false;
		$needs    = false;
		foreach ( $rows as $row ) {
			$origin = (string) ( $row->share_origin ?? '' );
			if ( $origin === 'existing' || $origin === 'existing_internal' ) {
				$kept = true;
			}
			if ( $origin === 'existing_internal' || $origin === 'needs_passcode_internal' ) {
				$internal = true;
			}
			if ( $origin === 'needs_passcode' || $origin === 'needs_passcode_internal' ) {
				$needs = true;
			}
		}
		if ( $kept ) {
			echo '<div class="notice notice-info"><p>Zoom already shared this recording with a passcode. That link and passcode were left unchanged.</p></div>';
		}
		if ( $internal ) {
			echo '<div class="notice notice-warning"><p>Zoom has this share limited to people in your Zoom account. Attendees outside the account may not be able to open it.</p></div>';
		}
		if ( $needs ) {
			echo '<div class="notice notice-warning"><p>Zoom already shared this recording, but the passcode could not be read. Enter the current passcode, or create a new one. A new passcode replaces the one already in Zoom.</p></div>';
		}
	}

	/**
	 * @param array<int,object> $rows
	 */
	private static function render_passcode_forms( array $rows ): void {
		foreach ( $rows as $row ) {
			$origin = (string) ( $row->share_origin ?? '' );
			$needs  = $origin === 'needs_passcode' || $origin === 'needs_passcode_internal';
			if ( ! $needs && ! in_array( $origin, array( 'existing', 'existing_internal' ), true ) ) {
				continue;
			}
			echo '<div style="margin-top:12px">';
			echo '<strong>Day ' . (int) $row->day_index . '</strong>';
			if ( $needs ) {
				echo '<form method="post" class="hlz-actions" style="margin-top:6px">';
				wp_nonce_field( 'hlz_passcode' );
				echo '<input type="hidden" name="hlz_action" value="save_passcode">';
				echo '<input type="hidden" name="eve_id" value="' . esc_attr( (string) (int) $row->eve_id ) . '">';
				echo '<input type="hidden" name="recording_id" value="' . esc_attr( (string) (int) $row->id ) . '">';
				echo '<input type="text" name="passcode" placeholder="Existing passcode" autocomplete="off"> ';
				echo '<button type="submit" class="button">Use this passcode</button>';
				echo '</form>';
			}
			echo '<form method="post" style="margin-top:6px">';
			wp_nonce_field( 'hlz_passcode' );
			echo '<input type="hidden" name="hlz_action" value="replace_passcode">';
			echo '<input type="hidden" name="eve_id" value="' . esc_attr( (string) (int) $row->eve_id ) . '">';
			echo '<input type="hidden" name="recording_id" value="' . esc_attr( (string) (int) $row->id ) . '">';
			echo '<button type="submit" class="button" onclick="return confirm(\'This replaces the passcode already in Zoom. The old passcode will stop working.\');">Create a new passcode</button>';
			echo '</form></div>';
		}
	}

	/**
	 * @param array<int,object> $rows
	 */
	private static function render_link_summary( array $event, array $rows ): void {
		echo '<div class="hlz-card"><h2>Share links for this class</h2>';
		self::render_shared_table( array_values( $rows ), array( (int) $event['eve_id'] => $event ), (int) $event['eve_id'] );
		echo '</div>';
	}

	/**
	 * @param array<int,object> $rows
	 * @param array<int,array<string,mixed>> $events
	 */
	private static function render_shared_table( array $rows, array $events, int $return_eve ): void {
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Class</th><th>Day</th><th>Topic</th><th>Status</th><th>Expires</th><th></th>';
		echo '</tr></thead><tbody>';
		$any = false;
		foreach ( $rows as $row ) {
			if ( trim( (string) $row->status ) === '' ) {
				continue;
			}
			$any = true;
			$event = $events[ (int) $row->eve_id ] ?? null;
			$title = $event ? HLZ_Bridge::event_title( $event ) : ( 'Event ' . (int) $row->eve_id );
			echo '<tr>';
			echo '<td>' . esc_html( $title ) . '</td>';
			echo '<td>' . esc_html( 'Day ' . (int) $row->day_index ) . '</td>';
			echo '<td>' . esc_html( (string) $row->topic ) . '</td>';
			echo '<td><span class="hlz-status hlz-status-' . esc_attr( (string) $row->status ) . '">' . esc_html( self::status_label( (string) $row->status ) ) . '</span></td>';
			echo '<td>' . esc_html( (string) $row->expires_at !== '' ? (string) $row->expires_at : 'Starts when sent' ) . '</td>';
			echo '<td class="hlz-actions">';
			echo '<form method="post">';
			wp_nonce_field( 'hlz_extend' );
			echo '<input type="hidden" name="hlz_action" value="extend">';
			echo '<input type="hidden" name="recording_id" value="' . esc_attr( (string) $row->id ) . '">';
			echo '<input type="hidden" name="eve_id" value="' . esc_attr( (string) (int) $row->eve_id ) . '">';
			if ( $return_eve > 0 ) {
				echo '<input type="hidden" name="hlz_return" value="preview">';
			}
			echo '<input type="hidden" name="days" value="' . esc_attr( (string) self::days_from_request() ) . '">';
			submit_button( 'Extend', 'secondary', 'submit', false );
			echo '</form>';
			if ( (string) $row->status !== 'off' ) {
				echo '<form method="post">';
				wp_nonce_field( 'hlz_turn_off' );
				echo '<input type="hidden" name="hlz_action" value="turn_off">';
				echo '<input type="hidden" name="recording_id" value="' . esc_attr( (string) $row->id ) . '">';
				echo '<input type="hidden" name="eve_id" value="' . esc_attr( (string) (int) $row->eve_id ) . '">';
				if ( $return_eve > 0 ) {
					echo '<input type="hidden" name="hlz_return" value="preview">';
				}
				echo '<input type="hidden" name="days" value="' . esc_attr( (string) self::days_from_request() ) . '">';
				submit_button( 'Turn off', 'secondary', 'submit', false );
				echo '</form>';
			}
			echo '</td></tr>';
		}
		if ( ! $any ) {
			echo '<tr><td colspan="6">Nothing is shared right now.</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_template_picker( array $event ): void {
		$eve_id   = (int) $event['eve_id'];
		$map      = get_option( 'hlz_template_overrides', array() );
		$override = ( is_array( $map ) && ! empty( $map[ $eve_id ] ) ) ? (string) $map[ $eve_id ] : '';
		$detected = HLZ_Templates::detect( HLZ_Bridge::event_title( $event ) );
		echo '<div class="hlz-card"><h2>Template</h2>';
		echo '<form method="post" class="hlz-actions">';
		wp_nonce_field( 'hlz_save_template_choice' );
		echo '<input type="hidden" name="hlz_action" value="save_template_choice">';
		echo '<input type="hidden" name="eve_id" value="' . esc_attr( (string) $eve_id ) . '">';
		echo '<select name="template_slug">';
		echo '<option value="" ' . selected( $override, '', false ) . '>Automatic (' . esc_html( $detected !== '' ? $detected : 'no title match' ) . ')</option>';
		foreach ( HLZ_Templates::all() as $template ) {
			echo '<option value="' . esc_attr( $template->slug ) . '" ' . selected( $override, (string) $template->slug, false ) . '>' . esc_html( $template->label ) . '</option>';
		}
		echo '</select>';
		submit_button( 'Use this template', 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * @param array<int,object> $rows
	 */
	private static function should_prepare( array $event, array $rows ): bool {
		if ( ! HLZ_Settings::has_credentials() ) {
			return false;
		}
		$need = false;
		foreach ( HLZ_Bridge::slots( $event ) as $slot ) {
			if ( ! $slot['auto'] && ! HLZ_Bridge::expects_auto( $event ) ) {
				continue;
			}
			$row = $rows[ (int) $slot['day_index'] ] ?? null;
			if ( ! $row || trim( (string) $row->meeting_id ) === '' ) {
				return false;
			}
			if ( (string) $row->status === 'off' ) {
				return false;
			}
			if ( trim( (string) $row->share_url ) === '' ) {
				$need = true;
			}
		}
		if ( ! HLZ_Bridge::expects_auto( $event ) ) {
			$need = false;
			foreach ( $rows as $row ) {
				if ( trim( (string) $row->meeting_id ) === '' || (string) $row->status === 'off' ) {
					continue;
				}
				if ( trim( (string) $row->share_url ) === '' ) {
					$need = true;
				}
			}
		}
		return $need;
	}

	/**
	 * @param array<int,object> $rows
	 */
	private static function links_ready( array $event, array $rows ): bool {
		if ( HLZ_Bridge::expects_auto( $event ) ) {
			foreach ( HLZ_Bridge::slots( $event ) as $slot ) {
				$row = $rows[ (int) $slot['day_index'] ] ?? null;
				if ( ! $row || trim( (string) $row->share_url ) === '' || trim( (string) $row->passcode ) === '' || (string) $row->status === 'off' ) {
					return false;
				}
			}
			return true;
		}
		foreach ( $rows as $row ) {
			if ( trim( (string) $row->share_url ) !== '' && trim( (string) $row->passcode ) !== '' && (string) $row->status !== 'off' ) {
				return true;
			}
		}
		return false;
	}

	private static function recording_count_label( array $event ): string {
		$count = count( HLZ_Bridge::span_dates( $event ) );
		if ( $count === 1 ) {
			return '1 recording';
		}
		if ( $count === 2 ) {
			return 'Day 1 and Day 2';
		}
		return 'Choose manually';
	}

	private static function state_label( string $state ): string {
		if ( $state === 'matched' ) {
			return 'Matched';
		}
		if ( $state === 'partial' ) {
			return 'Partial';
		}
		return 'Needs match';
	}

	/**
	 * @param array<int,object> $rows
	 */
	private static function share_label( array $rows ): string {
		$expires = '';
		$shared  = false;
		$prepared = false;
		$off = false;
		foreach ( $rows as $row ) {
			if ( (string) $row->status === 'shared' ) {
				$shared = true;
				if ( (string) $row->expires_at !== '' ) {
					$expires = (string) $row->expires_at;
				}
			} elseif ( (string) $row->status === 'prepared' ) {
				$prepared = true;
			} elseif ( (string) $row->status === 'off' ) {
				$off = true;
			}
		}
		if ( $shared ) {
			return $expires !== '' ? 'Shared until ' . $expires : 'Shared';
		}
		if ( $prepared ) {
			return 'Prepared, not sent';
		}
		if ( $off ) {
			return 'Off';
		}
		return 'Not sent';
	}

	private static function status_label( string $status ): string {
		if ( $status === 'shared' ) {
			return 'Shared';
		}
		if ( $status === 'prepared' ) {
			return 'Prepared';
		}
		if ( $status === 'off' ) {
			return 'Off';
		}
		return 'Not prepared';
	}

	private static function send_state_label( ?object $prior ): string {
		if ( ! $prior ) {
			return 'Not sent';
		}
		if ( trim( (string) $prior->error ) !== '' ) {
			return 'Failed: ' . (string) $prior->error;
		}
		if ( trim( (string) $prior->sent_at ) !== '' ) {
			return 'Sent ' . (string) $prior->sent_at;
		}
		return 'Not sent';
	}

	/**
	 * @param array<string,mixed> $meeting
	 */
	private static function meeting_label( array $meeting ): string {
		$local = HLZ_Bridge::zoom_local_date( (string) ( $meeting['start_time'] ?? '' ) );
		$clock = '';
		try {
			$dt = new DateTime( (string) ( $meeting['start_time'] ?? '' ) );
			$dt->setTimezone( wp_timezone() );
			$clock = $dt->format( 'g:i a' );
		} catch ( Exception $e ) {
			$clock = '';
		}
		$when = trim( HLZ_Bridge::format_day( $local ) . ' ' . $clock );
		$topic = trim( (string) ( $meeting['topic'] ?? '' ) );
		return $when . ' — ' . $topic;
	}

	private static function text_row( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" type="text" class="regular-text" value="' . esc_attr( $value ) . '">';
		echo '</td></tr>';
	}

	private static function note_from_post(): string {
		$note = sanitize_textarea_field( wp_unslash( $_POST['hlz_note'] ?? '' ) );
		if ( strlen( $note ) > 2000 ) {
			$note = substr( $note, 0, 2000 );
		}
		return $note;
	}

	private static function days_from_request(): int {
		$days = absint( $_REQUEST['days'] ?? 120 );
		if ( ! in_array( $days, array( 30, 90, 120, 180, 365 ), true ) ) {
			return 120;
		}
		return $days;
	}

	public static function render_screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		HLZ_Templates::seed_missing();
		$eve_id = absint( $_GET['eve_id'] ?? 0 );
		if ( $eve_id > 0 ) {
			self::render_preview( $eve_id );
			return;
		}
		self::render_recordings();
	}

	private static function page_open( string $title ): void {
		echo '<div class="wrap hlz-wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
	}

	private static function page_close(): void {
		echo '</div>';
	}

	private static function render_flash(): void {
		$flash = get_transient( self::flash_key() );
		if ( ! is_array( $flash ) || empty( $flash['text'] ) ) {
			return;
		}
		delete_transient( self::flash_key() );
		$class = ! empty( $flash['error'] ) ? 'notice notice-error' : 'notice notice-success';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( (string) $flash['text'] ) . '</p></div>';
	}

	private static function flash( string $text, bool $error ): void {
		set_transient(
			self::flash_key(),
			array(
				'text'  => $text,
				'error' => $error,
			),
			60
		);
	}

	private static function flash_key(): string {
		return 'hlz_flash_' . get_current_user_id();
	}

	private static function redirect_list( int $days, string $text, bool $error = false ): void {
		self::flash( $text, $error );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'hostlinks-zoom',
					'days' => $days,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function redirect_preview( int $eve_id, string $text, bool $error = false ): void {
		self::flash( $text, $error );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'hostlinks-zoom',
					'eve_id' => $eve_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function redirect_settings( string $text, bool $error = false ): void {
		self::flash( $text, $error );
		wp_safe_redirect( admin_url( 'admin.php?page=hostlinks-zoom-settings' ) );
		exit;
	}

	private static function redirect_template( string $slug, string $text, bool $error = false ): void {
		self::flash( $text, $error );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'hostlinks-zoom-templates',
					'template' => $slug,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
