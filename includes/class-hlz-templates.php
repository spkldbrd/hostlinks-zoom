<?php
/**
 * One plain-text template per class type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Templates {

	/**
	 * Most specific phrases first. "Grant Writing" is last because Cvent titles
	 * often start with the company name "Grant Writing USA".
	 *
	 * @return array<int,array{slug:string,label:string,phrase:string,subject:string,thanks:string,materials:string,unless?:array<int,string>}>
	 */
	public static function definitions(): array {
		return array(
			array(
				'slug'      => 'deep-dive-1',
				'label'     => 'Deep Dive Session 1',
				'phrase'    => 'Deep Dive Session 1',
				'subject'   => 'Deep Dive Session 1 Class Replay',
				'thanks'    => 'Deep Dive Session 1',
				'materials' => 'including the current class materials and other workshop resources.',
			),
			array(
				'slug'      => 'deep-dive-2',
				'label'     => 'Deep Dive Session 2',
				'phrase'    => 'Deep Dive Session 2',
				'subject'   => 'Deep Dive Session 2 Class Replay',
				'thanks'    => 'Deep Dive Session 2',
				'materials' => 'including the current class materials and other workshop resources.',
			),
			array(
				'slug'      => 'deep-dive-3',
				'label'     => 'Deep Dive Session 3',
				'phrase'    => 'Deep Dive Session 3',
				'subject'   => 'Deep Dive Session 3 Class Replay',
				'thanks'    => 'Deep Dive Session 3',
				'materials' => 'including the current class materials and other workshop resources.',
			),
			array(
				'slug'      => 'grant-management',
				'label'     => 'Grant Management',
				'phrase'    => 'Grant Management',
				'subject'   => 'Grant Management Class Replay',
				'thanks'    => 'the Grant Management USA Live Zoom Class',
				'materials' => 'including the current Grant Management Workbook and other workshop resources.',
			),
			array(
				'slug'      => 'subawards',
				'label'     => 'Managing Subawards',
				'phrase'    => 'Subaward',
				'subject'   => 'Managing Subawards Class Replay',
				'thanks'    => 'the Managing Subawards Training',
				'materials' => 'including the current class materials and other workshop resources.',
			),
			array(
				'slug'      => 'grant-writing',
				'label'     => 'Grant Writing',
				'phrase'    => 'Grant Writing',
				'subject'   => 'Grant Writing Class Replay',
				'thanks'    => 'the Grant Writing USA Live Zoom Class',
				'materials' => 'including the current Grant Writing Workbook and other workshop resources.',
				'unless'    => array( 'Deep Dive', 'Grant Management', 'Subaward' ),
			),
		);
	}

	/**
	 * Hyphens and extra spaces do not change a match. "Deep-Dive" = "Deep Dive".
	 */
	public static function normalize( string $text ): string {
		$text = strtolower( $text );
		$text = str_replace( array( '–', '—', '-', '/', '_' ), ' ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( (string) $text );
	}

	public static function seed_missing(): void {
		global $wpdb;
		$table = HLZ_DB::templates_table();
		foreach ( self::definitions() as $def ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE slug = %s", $def['slug'] )
			);
			if ( $exists > 0 ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'slug'         => $def['slug'],
					'label'        => $def['label'],
					'match_phrase' => $def['phrase'],
					'subject'      => $def['subject'],
					'body'         => self::starter_body( $def['thanks'], $def['materials'] ),
					'updated_at'   => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	public static function starter_body( string $thanks, string $materials ): string {
		return "Thank you for joining us for {$thanks} - {dates}\n"
			. "\n"
			. "{note}\n"
			. "Access on-demand recordings:\n"
			. "\n"
			. "You have access to on-demand recordings for 30 days.  They're for your viewing only so we kindly ask you not share the below info with others.\n"
			. "\n"
			. "{zoom}\n"
			. "\n"
			. "Download class materials and instructor resources:\n"
			. "PASTE MATERIALS URL HERE\n"
			. "All provided class materials are available for download through the link above, {$materials}\n"
			. "\n"
			. "Alumni Resource Center\n"
			. "\n"
			. "Join our Alumni Resource Center for access to sample grants, articles and more. Visit: https://www.grantwritingusa.com/arc/\n"
			. "Register for a new account using the invitation code is friends (ALL Lowercase)\n"
			. "\n"
			. "\n"
			. "\n"
			. "Discounts on future tuition\n"
			. "Use the code \"alumni\" to get \$100 off future tuition, and please share the \$30 discount code \"friends\" with your colleagues. Our schedule of future Zoom and In-Person classes is available online here.\n"
			. "\n"
			. "\n"
			. "\n"
			. "\n"
			. "All the best,\n"
			. " \n"
			. "\n"
			. "Client Services Team\n"
			. "Grant Writing USA\n"
			. "PO Box 50249\n"
			. "Henderson, NV 89016\n"
			. "800-814-8191 (PST)\n"
			. "http://GrantWritingUSA.com\n";
	}

	/**
	 * @return array<int,object>
	 */
	public static function all(): array {
		global $wpdb;
		$table = HLZ_DB::templates_table();
		$rows  = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id ASC" );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get( string $slug ): ?object {
		global $wpdb;
		$table = HLZ_DB::templates_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE slug = %s", $slug )
		);
		return is_object( $row ) ? $row : null;
	}

	public static function save( string $slug, string $subject, string $body ): bool {
		global $wpdb;
		$existing = self::get( $slug );
		if ( ! $existing ) {
			return false;
		}
		$updated = $wpdb->update(
			HLZ_DB::templates_table(),
			array(
				'subject'    => $subject,
				'body'       => $body,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'slug' => $slug ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
		return $updated !== false;
	}

	/**
	 * Class-type slug for a title, or empty when nothing matches.
	 */
	public static function detect( string $title ): string {
		$haystack = self::normalize( $title );
		if ( $haystack === '' ) {
			return '';
		}
		foreach ( self::definitions() as $def ) {
			$phrase = self::normalize( (string) $def['phrase'] );
			if ( $phrase === '' || strpos( $haystack, $phrase ) === false ) {
				continue;
			}
			$blocked = false;
			foreach ( (array) ( $def['unless'] ?? array() ) as $block ) {
				$needle = self::normalize( (string) $block );
				if ( $needle !== '' && strpos( $haystack, $needle ) !== false ) {
					$blocked = true;
					break;
				}
			}
			if ( $blocked ) {
				continue;
			}
			return $def['slug'];
		}
		return '';
	}

	/**
	 * Prefer Zoom recording titles (session number) over the Cvent event title
	 * (often "Grant Writing USA …").
	 */
	public static function detect_for_event( array $event ): string {
		$parts = array();
		$eve_id = (int) ( $event['eve_id'] ?? 0 );
		if ( $eve_id > 0 && class_exists( 'HLZ_Shares', false ) ) {
			foreach ( HLZ_Shares::for_event( $eve_id ) as $row ) {
				$topic = trim( (string) ( $row->topic ?? '' ) );
				if ( $topic !== '' ) {
					$parts[] = $topic;
				}
			}
		}
		$parts[] = HLZ_Bridge::event_title( $event );
		return self::detect( implode( "\n", $parts ) );
	}

	/**
	 * Broader phrase used to find Zoom recordings when the Cvent title names
	 * a series but not a session-specific template.
	 */
	public static function search_phrase_for_title( string $title ): string {
		$slug = self::detect( $title );
		if ( $slug !== '' ) {
			return self::phrase_for( $slug );
		}
		$haystack = self::normalize( $title );
		foreach ( array( 'Deep Dive', 'Grant Management', 'Subaward', 'Grant Writing' ) as $family ) {
			if ( strpos( $haystack, self::normalize( $family ) ) !== false ) {
				return $family;
			}
		}
		return '';
	}

	public static function label_for( string $slug ): string {
		if ( $slug === '' ) {
			return 'no title match';
		}
		$row = self::get( $slug );
		if ( $row && trim( (string) $row->label ) !== '' ) {
			return (string) $row->label;
		}
		foreach ( self::definitions() as $def ) {
			if ( $def['slug'] === $slug ) {
				return $def['label'];
			}
		}
		return $slug;
	}

	public static function phrase_for( string $slug ): string {
		$row = self::get( $slug );
		if ( $row && $row->match_phrase !== '' ) {
			return (string) $row->match_phrase;
		}
		foreach ( self::definitions() as $def ) {
			if ( $def['slug'] === $slug ) {
				return $def['phrase'];
			}
		}
		return '';
	}
}
