<?php

namespace CustomCRM\Migrations;

/**
 * One-time migration to fix Drip merge tags in imported FluentCRM campaigns.
 *
 * Replaces Drip merge tags with FluentCRM equivalents in campaign email bodies.
 * Run via: wp eval '\CustomCRM\Migrations\FixDripMergeTags::run();'
 * Or call FixDripMergeTags::run() from any admin context.
 */
class FixDripMergeTags {

	/**
	 * Drip → FluentCRM tag replacements.
	 *
	 * @var array<string,string>
	 */
	private static array $replacements = [
		// Footer links (full HTML versions).
		'{{ manage_subscriptions_link }}' => '{{crm.manage_subscription_html|Manage Preferences}}',
		'{{ unsubscribe_link }}'          => '{{crm.unsubscribe_html|Unsubscribe}}',

		// Footer links (URL-only versions).
		'{{ manage_subscriptions_url }}'  => '##crm.manage_subscription_url##',
		'{{ unsubscribe_url }}'           => '##crm.unsubscribe_url##',

		// Postal address and account.
		'{{ inline_postal_address }}'     => '{{crm.business_address}}',
		'{{ account.name }}'              => '{{crm.business_name}}',

		// Subscriber fields.
		'{{ subscriber.first_name }}'     => '{{contact.first_name}}',
		'{{ subscriber.last_name }}'      => '{{contact.last_name}}',
		'{{ subscriber.email }}'          => '{{contact.email}}',
	];

	/**
	 * Regex-based replacements for patterns with variable content.
	 *
	 * @var array<string,string>
	 */
	private static array $regex_replacements = [
		'/\{\{\s*manage_subscriptions_link\s*\}\}/i' => '{{crm.manage_subscription_html|Manage Preferences}}',
		'/\{\{\s*unsubscribe_link\s*\}\}/i'          => '{{crm.unsubscribe_html|Unsubscribe}}',
		'/\{\{\s*manage_subscriptions_url\s*\}\}/i'  => '##crm.manage_subscription_url##',
		'/\{\{\s*unsubscribe_url\s*\}\}/i'           => '##crm.unsubscribe_url##',
		'/\{\{\s*inline_postal_address\s*\}\}/i'     => '{{crm.business_address}}',
		'/\{\{\s*account\.name\s*\}\}/i'             => '{{crm.business_name}}',
		'/\{\{\s*subscriber\.first_name\s*\}\}/i'    => '{{contact.first_name}}',
		'/\{\{\s*subscriber\.last_name\s*\}\}/i'     => '{{contact.last_name}}',
		'/\{\{\s*subscriber\.email\s*\}\}/i'         => '{{contact.email}}',
		'/\{\{\s*subscriber\.custom_fields\.(\w+)\s*\}\}/i' => '{{contact.custom.$1}}',
		'/\{\{\s*subscriber\.(\w+)\s*\}\}/i'         => '{{contact.$1}}',
	];

	/**
	 * Run the migration.
	 *
	 * @param bool $dry_run If true, only report what would change without modifying data.
	 *
	 * @return array{updated: int, skipped: int, errors: int}
	 */
	public static function run( bool $dry_run = false ): array {
		$db = fluentCrmDb();

		// Find all campaigns with Drip merge tags in email_body.
		$campaigns = $db->table( 'fc_campaigns' )
			->where( 'email_body', 'LIKE', '%{{ %' )
			->where(function ( $q ) {
				$q->where( 'email_body', 'LIKE', '%subscriber.%' )
					->orWhere( 'email_body', 'LIKE', '%unsubscribe_link%' )
					->orWhere( 'email_body', 'LIKE', '%manage_subscriptions%' )
					->orWhere( 'email_body', 'LIKE', '%inline_postal_address%' )
					->orWhere( 'email_body', 'LIKE', '%account.name%' );
			} )
			->get();

		$stats = [
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
		];

		foreach ( $campaigns as $campaign ) {
			$original = $campaign->email_body;
			$updated  = self::convertMergeTags( $original );

			if ( $updated === $original ) {
				++$stats['skipped'];
				continue;
			}

			if ( $dry_run ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[FixDripMergeTags] DRY RUN — Would update campaign #%d (%s)',
					$campaign->id,
					$campaign->title ?? 'untitled'
				) );
				++$stats['updated'];
				continue;
			}

			$result = $db->table( 'fc_campaigns' )
				->where( 'id', $campaign->id )
				->update( [ 'email_body' => $updated ] );

			if ( $result !== false ) {
				++$stats['updated'];
			} else {
				++$stats['errors'];
			}
		}

		// Also fix campaign_emails (individual sent emails) if they exist.
		$emails = $db->table( 'fc_campaign_emails' )
			->where( 'email_body', 'LIKE', '%{{ %' )
			->where(function ( $q ) {
				$q->where( 'email_body', 'LIKE', '%subscriber.%' )
					->orWhere( 'email_body', 'LIKE', '%unsubscribe_link%' )
					->orWhere( 'email_body', 'LIKE', '%manage_subscriptions%' )
					->orWhere( 'email_body', 'LIKE', '%inline_postal_address%' )
					->orWhere( 'email_body', 'LIKE', '%account.name%' );
			} )
			->get();

		foreach ( $emails as $email ) {
			$original = $email->email_body;
			$updated  = self::convertMergeTags( $original );

			if ( $updated === $original ) {
				++$stats['skipped'];
				continue;
			}

			if ( $dry_run ) {
				++$stats['updated'];
				continue;
			}

			$result = $db->table( 'fc_campaign_emails' )
				->where( 'id', $email->id )
				->update( [ 'email_body' => $updated ] );

			if ( $result !== false ) {
				++$stats['updated'];
			} else {
				++$stats['errors'];
			}
		}

		// Also fix funnel sequence settings (automation email bodies).
		$sequences = $db->table( 'fc_funnel_sequences' )
			->where( 'action_name', 'send_custom_email' )
			->where( 'settings', 'LIKE', '%{{ %' )
			->get();

		foreach ( $sequences as $seq ) {
			$settings = maybe_unserialize( $seq->settings );
			if ( ! is_array( $settings ) || empty( $settings['email_body'] ) ) {
				continue;
			}

			$original = $settings['email_body'];
			$updated  = self::convertMergeTags( $original );

			if ( $updated === $original ) {
				continue;
			}

			if ( ! $dry_run ) {
				$settings['email_body'] = $updated;
				$db->table( 'fc_funnel_sequences' )
					->where( 'id', $seq->id )
					->update( [ 'settings' => maybe_serialize( $settings ) ] );
			}
		}

		return $stats;
	}

	/**
	 * Convert Drip merge tags to FluentCRM merge tags.
	 *
	 * @param string $text Email content with Drip merge tags.
	 *
	 * @return string Content with FluentCRM merge tags.
	 */
	public static function convertMergeTags( string $text ): string {
		foreach ( self::$regex_replacements as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $text );
			if ( $result !== null ) {
				$text = $result;
			}
		}

		return $text;
	}
}
