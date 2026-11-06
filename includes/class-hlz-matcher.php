<?php
/**
 * Match Zoom cloud recordings to a Hostlinks class by date and title phrase.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Matcher {

	/**
	 * Fetch recordings for one class and store automatic matches.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function sync_event( array $event ) {
		$window = self::window( $event );
		$list   = HLZ_Zoom::list_recordings( $window['from'], $window['to'] );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		self::apply( $event, $list, true );
		return $list;
	}

	/**
	 * Fetch once for the whole list, then match each class.
	 *
	 * @param array<int,array<string,mixed>> $events
	 * @return array{matched:int,partial:int,unmatched:int}|WP_Error
	 */
	public static function sync_events( array $events ) {
		if ( empty( $events ) ) {
			return array(
				'matched'   => 0,
				'partial'   => 0,
				'unmatched' => 0,
			);
		}

		$from = '';
		$to   = '';
		foreach ( $events as $event ) {
			$window = self::window( $event );
			if ( $from === '' || $window['from'] < $from ) {
				$from = $window['from'];
			}
			if ( $to === '' || $window['to'] > $to ) {
				$to = $window['to'];
			}
		}

		$list = HLZ_Zoom::list_recordings( $from, $to );
		if ( is_wp_error( $list ) ) {
			return $list;
		}

		$counts = array(
			'matched'   => 0,
			'partial'   => 0,
			'unmatched' => 0,
		);
		foreach ( $events as $event ) {
			self::apply( $event, $list, true );
			$state = self::match_state( $event, HLZ_Shares::for_event( (int) $event['eve_id'] ) );
			if ( isset( $counts[ $state ] ) ) {
				$counts[ $state ]++;
			}
		}
		return $counts;
	}

	/**
	 * @param array<int,array<string,mixed>> $meetings
	 */
	public static function apply( array $event, array $meetings, bool $write_auto ): void {
		$eve_id  = (int) $event['eve_id'];
		$phrase  = self::phrase_for_event( $event );
		$slots   = HLZ_Bridge::slots( $event );
		$existing = HLZ_Shares::for_event( $eve_id );

		foreach ( $slots as $slot ) {
			$day     = (int) $slot['day_index'];
			$current = $existing[ $day ] ?? null;
			if ( ! $write_auto || ! $slot['auto'] || $phrase === '' ) {
				continue;
			}
			if ( $current && ( (string) $current->match_source === 'manual' || (string) $current->status !== '' ) ) {
				continue;
			}

			$hits = self::hits_for_day( $meetings, $phrase, (string) $slot['date'] );
			if ( count( $hits ) !== 1 ) {
				if ( $current && (string) $current->match_source === 'auto' && (string) $current->status === '' ) {
					HLZ_Shares::delete_day( $eve_id, $day );
				}
				continue;
			}

			$meeting = $hits[0];
			$zoom_date = HLZ_Bridge::zoom_local_date( (string) ( $meeting['start_time'] ?? '' ) );
			HLZ_Shares::upsert_day(
				$eve_id,
				$day,
				array(
					'meeting_id'     => (string) ( $meeting['id'] ?? '' ),
					'meeting_uuid'   => (string) ( $meeting['uuid'] ?? '' ),
					'topic'          => sanitize_text_field( (string) ( $meeting['topic'] ?? '' ) ),
					'recording_date' => $zoom_date !== '' ? $zoom_date : (string) $slot['date'],
					'match_source'   => 'auto',
					'last_error'     => '',
				)
			);
		}
	}

	/**
	 * Save dropdown choices. Locked rows (already prepared, shared, or off) stay put.
	 *
	 * @param array<int,string>              $chosen day_index => meeting id
	 * @param array<int,array<string,mixed>> $meetings
	 */
	public static function save_manual( array $event, array $chosen, array $meetings ): void {
		$eve_id   = (int) $event['eve_id'];
		$existing = HLZ_Shares::for_event( $eve_id );
		$by_key = array();
		foreach ( $meetings as $meeting ) {
			$uuid = trim( (string) ( $meeting['uuid'] ?? '' ) );
			$id   = trim( (string) ( $meeting['id'] ?? '' ) );
			if ( $uuid !== '' ) {
				$by_key[ $uuid ] = $meeting;
			}
			if ( $id !== '' && ! isset( $by_key[ $id ] ) ) {
				$by_key[ $id ] = $meeting;
			}
		}

		foreach ( HLZ_Bridge::slots( $event ) as $slot ) {
			$day     = (int) $slot['day_index'];
			$current = $existing[ $day ] ?? null;
			$pick    = trim( (string) ( $chosen[ $day ] ?? '' ) );
			$locked  = $current && (string) $current->status !== '';

			if ( $locked ) {
				continue;
			}

			if ( $pick === '' ) {
				if ( $current ) {
					HLZ_Shares::delete_day( $eve_id, $day );
				}
				continue;
			}

			$current_key = $current ? trim( (string) ( $current->meeting_uuid ?? '' ) ) : '';
			if ( $current_key === '' && $current ) {
				$current_key = trim( (string) $current->meeting_id );
			}
			if ( $current && $current_key === $pick && (string) $current->match_source === 'manual' ) {
				continue;
			}

			if ( $current ) {
				HLZ_Shares::delete_day( $eve_id, $day );
			}

			$meeting = $by_key[ $pick ] ?? null;
			$date    = $meeting ? HLZ_Bridge::zoom_local_date( (string) ( $meeting['start_time'] ?? '' ) ) : (string) $slot['date'];
			$fields  = array(
				'meeting_id'   => $meeting ? (string) ( $meeting['id'] ?? '' ) : '',
				'meeting_uuid' => $meeting ? (string) ( $meeting['uuid'] ?? '' ) : $pick,
				'topic'        => $meeting ? sanitize_text_field( (string) ( $meeting['topic'] ?? '' ) ) : '',
				'match_source' => 'manual',
				'last_error'   => '',
			);
			if ( $date !== '' ) {
				$fields['recording_date'] = $date;
			}
			HLZ_Shares::upsert_day( $eve_id, $day, $fields );
		}
	}

	/**
	 * Recordings in the dropdown window (day before through day after).
	 *
	 * @param array<int,array<string,mixed>> $meetings
	 * @return array<int,array<string,mixed>>
	 */
	public static function dropdown_meetings( array $event, array $meetings ): array {
		$window = self::window( $event );
		$out    = array();
		foreach ( $meetings as $meeting ) {
			$local = HLZ_Bridge::zoom_local_date( (string) ( $meeting['start_time'] ?? '' ) );
			if ( $local === '' || $local < $window['from'] || $local > $window['to'] ) {
				continue;
			}
			if ( trim( (string) ( $meeting['uuid'] ?? '' ) ) === '' && trim( (string) ( $meeting['id'] ?? '' ) ) === '' ) {
				continue;
			}
			$out[] = $meeting;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( (string) ( $a['start_time'] ?? '' ), (string) ( $b['start_time'] ?? '' ) );
			}
		);
		return $out;
	}

	/**
	 * @param array<int,object> $rows keyed by day
	 */
	public static function match_state( array $event, array $rows ): string {
		$slots = HLZ_Bridge::slots( $event );
		$auto  = HLZ_Bridge::expects_auto( $event );
		$need  = $auto ? count( $slots ) : 1;
		$have  = 0;
		foreach ( $slots as $slot ) {
			$row = $rows[ (int) $slot['day_index'] ] ?? null;
			if ( $row && trim( (string) $row->meeting_id ) !== '' ) {
				$have++;
			}
		}
		if ( $have === 0 ) {
			return 'unmatched';
		}
		if ( $have < $need ) {
			return 'partial';
		}
		return 'matched';
	}

	/**
	 * @return array{from:string,to:string}
	 */
	public static function window( array $event ): array {
		$dates = HLZ_Bridge::span_dates( $event );
		$start = $dates[0] ?? (string) ( $event['eve_start'] ?? wp_date( 'Y-m-d' ) );
		$end   = $dates ? (string) $dates[ count( $dates ) - 1 ] : (string) ( $event['eve_end'] ?? $start );
		return array(
			'from' => HLZ_Bridge::shift_date( $start, -1 ),
			'to'   => HLZ_Bridge::shift_date( $end, 1 ),
		);
	}

	public static function phrase_for_event( array $event ): string {
		$slug = HLZ_Templates::detect( HLZ_Bridge::event_title( $event ) );
		return $slug === '' ? '' : HLZ_Templates::phrase_for( $slug );
	}

	/**
	 * @param array<int,array<string,mixed>> $meetings
	 * @return array<int,array<string,mixed>>
	 */
	private static function hits_for_day( array $meetings, string $phrase, string $date ): array {
		$hits = array();
		foreach ( $meetings as $meeting ) {
			$topic = (string) ( $meeting['topic'] ?? '' );
			if ( $phrase === '' || stripos( $topic, $phrase ) === false ) {
				continue;
			}
			$local = HLZ_Bridge::zoom_local_date( (string) ( $meeting['start_time'] ?? '' ) );
			if ( $local !== $date ) {
				continue;
			}
			if ( trim( (string) ( $meeting['uuid'] ?? '' ) ) === '' && trim( (string) ( $meeting['id'] ?? '' ) ) === '' ) {
				continue;
			}
			$hits[] = $meeting;
		}
		return $hits;
	}
}
