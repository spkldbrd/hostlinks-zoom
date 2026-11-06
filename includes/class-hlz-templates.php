<?php
/**
 * One plain-text template per class type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Templates {

	/**
	 * Most specific phrases first. "Subaward" matches "Subawards".
	 *
	 * @return array<int,array{slug:string,label:string,phrase:string,subject:string,thanks:string,materials:string}>
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
				'slug'      => 'grant-writing',
				'label'     => 'Grant Writing',
				'phrase'    => 'Grant Writing',
				'subject'   => 'Grant Writing Class Replay',
				'thanks'    => 'the Grant Writing USA Live Zoom Class',
				'materials' => 'including the current Grant Writing Workbook and other workshop resources.',
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
		);
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
	 * Class-type slug for a Cvent title, or empty when nothing matches.
	 */
	public static function detect( string $title ): string {
		foreach ( self::definitions() as $def ) {
			if ( $def['phrase'] !== '' && stripos( $title, $def['phrase'] ) !== false ) {
				return $def['slug'];
			}
		}
		return '';
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
