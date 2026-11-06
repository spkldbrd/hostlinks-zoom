<?php
/**
 * Active registrants for a class. Canceled orders and canceled registrations are left off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Recipients {

	/**
	 * @return array<int,array{name:string,email:string,status:string}>|WP_Error
	 */
	public static function for_event( array $event ) {
		if ( ! class_exists( 'Hostlinks_Roster', false ) || ! class_exists( 'Hostlinks_CVENT_API', false ) ) {
			return new WP_Error( 'hlz_roster', 'Hostlinks roster is not available.' );
		}

		$cvent_id = Hostlinks_CVENT_API::sanitize_uuid( (string) ( $event['cvent_event_id'] ?? '' ) );
		if ( $cvent_id === '' ) {
			return new WP_Error( 'hlz_roster', 'This class has no Cvent event id.' );
		}

		$loaded = Hostlinks_Roster::load_order_items( $cvent_id, $event );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$items = isset( $loaded['items'] ) && is_array( $loaded['items'] ) ? $loaded['items'] : array();
		$active = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! ( $item['active'] ?? true ) ) {
				continue;
			}
			$uuid = Hostlinks_CVENT_API::sanitize_uuid(
				(string) ( $item['attendee']['id'] ?? $item['attendeeId'] ?? '' )
			);
			if ( $uuid !== '' ) {
				$active[ $uuid ] = true;
			}
		}

		$map = Hostlinks_Roster::resolve_attendees_map( $items, $cvent_id );
		$rows = array();
		$seen = array();

		foreach ( $map as $uuid => $att ) {
			if ( empty( $active[ $uuid ] ) || ! is_array( $att ) ) {
				continue;
			}
			$status = (string) ( $att['status'] ?? $att['attendeeStatus'] ?? '' );
			if ( in_array( $status, Hostlinks_Roster::SKIP_STATUSES, true ) ) {
				continue;
			}
			$contact = is_array( $att['contact'] ?? null ) ? $att['contact'] : array();
			$email   = sanitize_email( (string) ( $att['email'] ?? $contact['email'] ?? '' ) );
			if ( $email === '' || ! is_email( $email ) ) {
				continue;
			}
			$key = strtolower( $email );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$first = trim( (string) ( $att['firstName'] ?? $contact['firstName'] ?? '' ) );
			$last  = trim( (string) ( $att['lastName'] ?? $contact['lastName'] ?? '' ) );
			$name  = trim( $first . ' ' . $last );

			$rows[] = array(
				'name'   => $name,
				'email'  => $email,
				'status' => $status !== '' ? $status : 'Active',
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( $a['email'], $b['email'] );
			}
		);

		return $rows;
	}
}
