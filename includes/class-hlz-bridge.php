<?php
/**
 * Read-only access to Hostlinks event rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Bridge {

	public static function is_hostlinks_active(): bool {
		return defined( 'HOSTLINKS_VERSION' ) && class_exists( 'Hostlinks_Roster', false );
	}

	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'event_details_list';
	}

	/**
	 * Past Zoom classes that ended on or after $since and before today.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function past_zoom_events( string $since ): array {
		global $wpdb;
		if ( ! self::is_hostlinks_active() ) {
			return array();
		}

		$today = wp_date( 'Y-m-d' );
		$table = self::events_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT eve_id, eve_location, eve_start, eve_end, eve_zoom, eve_status,
				        cvent_event_id, cvent_event_title, cvent_session_id, cvent_session_code
				 FROM `{$table}`
				 WHERE eve_status = 1
				   AND LOWER(eve_zoom) = 'yes'
				   AND cvent_event_id IS NOT NULL
				   AND cvent_event_id <> ''
				   AND eve_end IS NOT NULL
				   AND eve_end >= %s
				   AND eve_end < %s
				 ORDER BY eve_end DESC, eve_id DESC",
				$since,
				$today
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get_event( int $eve_id ): ?array {
		global $wpdb;
		if ( $eve_id <= 0 || ! self::is_hostlinks_active() ) {
			return null;
		}
		$table = self::events_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT eve_id, eve_location, eve_start, eve_end, eve_zoom, eve_status,
				        cvent_event_id, cvent_event_title, cvent_session_id, cvent_session_code
				 FROM `{$table}`
				 WHERE eve_id = %d",
				$eve_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<int,int> $ids
	 * @return array<int,array<string,mixed>> keyed by eve_id
	 */
	public static function get_events_by_ids( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) || ! self::is_hostlinks_active() ) {
			return array();
		}
		$table        = self::events_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT eve_id, eve_location, eve_start, eve_end, cvent_event_title
				 FROM `{$table}`
				 WHERE eve_id IN ($placeholders)",
				...$ids
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['eve_id'] ] = $row;
		}
		return $out;
	}

	public static function event_title( array $event ): string {
		$title = trim( (string) ( $event['cvent_event_title'] ?? '' ) );
		if ( $title === '' ) {
			$title = trim( (string) ( $event['eve_location'] ?? '' ) );
		}
		return $title;
	}

	/**
	 * Inclusive calendar days between start and end. Empty when a date is missing.
	 *
	 * @return array<int,string> Y-m-d
	 */
	public static function span_dates( array $event ): array {
		$start = self::parse_date( (string) ( $event['eve_start'] ?? '' ) );
		$end   = self::parse_date( (string) ( $event['eve_end'] ?? '' ) );
		if ( ! $start ) {
			return array();
		}
		if ( ! $end || $end < $start ) {
			$end = clone $start;
		}
		$dates = array();
		$cursor = clone $start;
		$guard  = 0;
		while ( $cursor <= $end && $guard < 14 ) {
			$dates[] = $cursor->format( 'Y-m-d' );
			$cursor->modify( '+1 day' );
			$guard++;
		}
		return $dates;
	}

	/**
	 * Slots the matcher and the email use.
	 * One or two calendar days auto-match. Any other span is manual, with two slots.
	 *
	 * @return array<int,array{day_index:int,date:string,auto:bool}>
	 */
	public static function slots( array $event ): array {
		$dates = self::span_dates( $event );
		$count = count( $dates );
		if ( $count === 1 || $count === 2 ) {
			$slots = array();
			foreach ( $dates as $i => $date ) {
				$slots[] = array(
					'day_index' => $i + 1,
					'date'      => $date,
					'auto'      => true,
				);
			}
			return $slots;
		}

		return array(
			array(
				'day_index' => 1,
				'date'      => $dates[0] ?? '',
				'auto'      => false,
			),
			array(
				'day_index' => 2,
				'date'      => $dates ? (string) $dates[ $count - 1 ] : '',
				'auto'      => false,
			),
		);
	}

	public static function expects_auto( array $event ): bool {
		$count = count( self::span_dates( $event ) );
		return $count === 1 || $count === 2;
	}

	/**
	 * January 30-31, 2025 / January 30, 2025 / July 31 - August 1, 2026.
	 */
	public static function format_date_range( string $start_raw, string $end_raw ): string {
		$start = self::parse_date( $start_raw );
		if ( ! $start ) {
			return $start_raw;
		}
		if ( $end_raw === '' || $end_raw === $start_raw ) {
			return $start->format( 'F j, Y' );
		}
		$end = self::parse_date( $end_raw );
		if ( ! $end ) {
			return $start->format( 'F j, Y' );
		}
		if ( $start->format( 'Y' ) === $end->format( 'Y' ) ) {
			if ( $start->format( 'm' ) === $end->format( 'm' ) ) {
				return $start->format( 'F' ) . ' ' . $start->format( 'j' ) . '-' . $end->format( 'j' ) . ', ' . $start->format( 'Y' );
			}
			return $start->format( 'F j' ) . ' - ' . $end->format( 'F j' ) . ', ' . $start->format( 'Y' );
		}
		return $start->format( 'F j, Y' ) . ' - ' . $end->format( 'F j, Y' );
	}

	public static function format_day( string $ymd ): string {
		$dt = self::parse_date( $ymd );
		return $dt ? $dt->format( 'F j, Y' ) : $ymd;
	}

	public static function shift_date( string $ymd, int $days ): string {
		$dt = self::parse_date( $ymd );
		if ( ! $dt ) {
			return $ymd;
		}
		if ( $days !== 0 ) {
			$dt->modify( ( $days > 0 ? '+' : '' ) . $days . ' day' );
		}
		return $dt->format( 'Y-m-d' );
	}

	public static function parse_date( string $ymd ): ?DateTime {
		$ymd = trim( $ymd );
		if ( $ymd === '' ) {
			return null;
		}
		$dt = DateTime::createFromFormat( 'Y-m-d', substr( $ymd, 0, 10 ), wp_timezone() );
		if ( ! $dt ) {
			return null;
		}
		$dt->setTime( 0, 0, 0 );
		return $dt;
	}

	/**
	 * Zoom start_time (UTC) as a site-local Y-m-d.
	 */
	public static function zoom_local_date( string $start_time ): string {
		$start_time = trim( $start_time );
		if ( $start_time === '' ) {
			return '';
		}
		try {
			$dt = new DateTime( $start_time );
		} catch ( Exception $e ) {
			return '';
		}
		$dt->setTimezone( wp_timezone() );
		return $dt->format( 'Y-m-d' );
	}
}
