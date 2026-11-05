<?php

namespace CustomCRM\LocalTime;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Time math for local-time sending: which zone a contact is in, and when their email goes out.
 * Uses no WordPress or FluentCRM code.
 */
final class SendTime {

	/**
	 * Default delivery window, in minutes after local midnight.
	 */
	public const DEFAULT_WINDOW_START = 8 * 60;

	public const DEFAULT_WINDOW_END = 11 * 60;

	/**
	 * The first zone to reach any given local time (UTC+14), so a window there opens before it
	 * opens anywhere else.
	 */
	private const EARLIEST_ZONE = 'Pacific/Kiritimati';

	/**
	 * Countries that span several zones, mapped to the zone where most of their people live. PHP
	 * lists a country's zones alphabetically, so without this Spain resolves to Africa/Ceuta and
	 * the United States to America/Adak.
	 */
	private const COUNTRY_ZONES = [
		'AR' => 'America/Argentina/Buenos_Aires',
		'AU' => 'Australia/Sydney',
		'BR' => 'America/Sao_Paulo',
		'CA' => 'America/Toronto',
		'CD' => 'Africa/Kinshasa',
		'CL' => 'America/Santiago',
		'CN' => 'Asia/Shanghai',
		'DE' => 'Europe/Berlin',
		'EC' => 'America/Guayaquil',
		'ES' => 'Europe/Madrid',
		'GB' => 'Europe/London',
		'ID' => 'Asia/Jakarta',
		'KZ' => 'Asia/Almaty',
		'MN' => 'Asia/Ulaanbaatar',
		'MX' => 'America/Mexico_City',
		'MY' => 'Asia/Kuala_Lumpur',
		'NZ' => 'Pacific/Auckland',
		'PT' => 'Europe/Lisbon',
		'RU' => 'Europe/Moscow',
		'UA' => 'Europe/Kyiv',
		'US' => 'America/New_York',
	];

	/**
	 * Country names people and forms write instead of the two-letter code, by lower-case letters
	 * and spaces only.
	 */
	private const COUNTRY_ALIASES = [
		'usa'                      => 'US',
		'u s'                      => 'US',
		'u s a'                    => 'US',
		'united states'            => 'US',
		'united states of america' => 'US',
		'america'                  => 'US',
		'uk'                       => 'GB',
		'u k'                      => 'GB',
		'gbr'                      => 'GB',
		'united kingdom'           => 'GB',
		'great britain'            => 'GB',
		'england'                  => 'GB',
		'scotland'                 => 'GB',
		'wales'                    => 'GB',
		'northern ireland'         => 'GB',
		'can'                      => 'CA',
		'aus'                      => 'AU',
		'nzl'                      => 'NZ',
		'deu'                      => 'DE',
		'fra'                      => 'FR',
		'esp'                      => 'ES',
		'ind'                      => 'IN',
		'bra'                      => 'BR',
		'mex'                      => 'MX',
	];

	/**
	 * Older zone names mapped to their current ones. PHP on the live server reads the system's zone
	 * data, which refuses these names even though browsers still report some (Chrome says
	 * Asia/Calcutta). Made from that system's tzdata.zi: the links it does not accept.
	 */
	private const OLD_ZONE_NAMES = [
		'Africa/Asmera' => 'Africa/Asmara',
		'America/Argentina/ComodRivadavia' => 'America/Argentina/Catamarca',
		'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
		'America/Catamarca' => 'America/Argentina/Catamarca',
		'America/Cordoba' => 'America/Argentina/Cordoba',
		'America/Fort_Wayne' => 'America/Indiana/Indianapolis',
		'America/Godthab' => 'America/Nuuk',
		'America/Indianapolis' => 'America/Indiana/Indianapolis',
		'America/Jujuy' => 'America/Argentina/Jujuy',
		'America/Knox_IN' => 'America/Indiana/Knox',
		'America/Louisville' => 'America/Kentucky/Louisville',
		'America/Mendoza' => 'America/Argentina/Mendoza',
		'America/Rosario' => 'America/Argentina/Cordoba',
		'Antarctica/South_Pole' => 'Antarctica/McMurdo',
		'Asia/Ashkhabad' => 'Asia/Ashgabat',
		'Asia/Calcutta' => 'Asia/Kolkata',
		'Asia/Chungking' => 'Asia/Shanghai',
		'Asia/Dacca' => 'Asia/Dhaka',
		'Asia/Katmandu' => 'Asia/Kathmandu',
		'Asia/Macao' => 'Asia/Macau',
		'Asia/Rangoon' => 'Asia/Yangon',
		'Asia/Saigon' => 'Asia/Ho_Chi_Minh',
		'Asia/Thimbu' => 'Asia/Thimphu',
		'Asia/Ujung_Pandang' => 'Asia/Makassar',
		'Asia/Ulan_Bator' => 'Asia/Ulaanbaatar',
		'Atlantic/Faeroe' => 'Atlantic/Faroe',
		'Australia/ACT' => 'Australia/Sydney',
		'Australia/LHI' => 'Australia/Lord_Howe',
		'Australia/NSW' => 'Australia/Sydney',
		'Australia/North' => 'Australia/Darwin',
		'Australia/Queensland' => 'Australia/Brisbane',
		'Australia/South' => 'Australia/Adelaide',
		'Australia/Tasmania' => 'Australia/Hobart',
		'Australia/Victoria' => 'Australia/Melbourne',
		'Australia/West' => 'Australia/Perth',
		'Brazil/Acre' => 'America/Rio_Branco',
		'Brazil/DeNoronha' => 'America/Noronha',
		'Brazil/East' => 'America/Sao_Paulo',
		'Brazil/West' => 'America/Manaus',
		'Canada/Atlantic' => 'America/Halifax',
		'Canada/Central' => 'America/Winnipeg',
		'Canada/Eastern' => 'America/Toronto',
		'Canada/Mountain' => 'America/Edmonton',
		'Canada/Newfoundland' => 'America/St_Johns',
		'Canada/Pacific' => 'America/Vancouver',
		'Canada/Saskatchewan' => 'America/Regina',
		'Canada/Yukon' => 'America/Whitehorse',
		'Chile/Continental' => 'America/Santiago',
		'Chile/EasterIsland' => 'Pacific/Easter',
		'Europe/Kiev' => 'Europe/Kyiv',
		'Europe/Uzhgorod' => 'Europe/Kyiv',
		'Europe/Zaporozhye' => 'Europe/Kyiv',
		'Mexico/BajaNorte' => 'America/Tijuana',
		'Mexico/BajaSur' => 'America/Mazatlan',
		'Mexico/General' => 'America/Mexico_City',
		'Pacific/Enderbury' => 'Pacific/Kanton',
		'Pacific/Ponape' => 'Pacific/Pohnpei',
		'Pacific/Truk' => 'Pacific/Chuuk',
		'US/Alaska' => 'America/Anchorage',
		'US/Aleutian' => 'America/Adak',
		'US/Arizona' => 'America/Phoenix',
		'US/Central' => 'America/Chicago',
		'US/East-Indiana' => 'America/Indiana/Indianapolis',
		'US/Eastern' => 'America/New_York',
		'US/Hawaii' => 'Pacific/Honolulu',
		'US/Indiana-Starke' => 'America/Indiana/Knox',
		'US/Michigan' => 'America/Detroit',
		'US/Mountain' => 'America/Denver',
		'US/Pacific' => 'America/Los_Angeles',
		'US/Samoa' => 'Pacific/Pago_Pago',
	];

	/**
	 * Whether a string names a time zone that can be used here. The list alone is not enough: with
	 * system zone data it also holds file names such as `leapseconds`, and those throw when used.
	 */
	public static function isZoneName( $zone ): bool {
		if ( ! is_string( $zone ) || '' === $zone || ! in_array( $zone, DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC ), true ) ) {
			return false;
		}

		try {
			new DateTimeZone( $zone );
		} catch ( \Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * The current name of a zone, for a name that has been replaced; otherwise the name as given.
	 */
	public static function currentZoneName( string $zone ): string {
		return self::OLD_ZONE_NAMES[ $zone ] ?? $zone;
	}

	/**
	 * US states, Canadian provinces and Australian states by two-letter code, mapped to the zone
	 * most of their people live in.
	 */
	private const STATE_ZONES = [
		'US' => [
			'AL' => 'America/Chicago',
			'AK' => 'America/Anchorage',
			'AS' => 'Pacific/Pago_Pago',
			'AZ' => 'America/Phoenix',
			'AR' => 'America/Chicago',
			'CA' => 'America/Los_Angeles',
			'CO' => 'America/Denver',
			'CT' => 'America/New_York',
			'DC' => 'America/New_York',
			'DE' => 'America/New_York',
			'FL' => 'America/New_York',
			'GA' => 'America/New_York',
			'GU' => 'Pacific/Guam',
			'HI' => 'Pacific/Honolulu',
			'ID' => 'America/Boise',
			'IL' => 'America/Chicago',
			'IN' => 'America/Indiana/Indianapolis',
			'IA' => 'America/Chicago',
			'KS' => 'America/Chicago',
			'KY' => 'America/New_York',
			'LA' => 'America/Chicago',
			'ME' => 'America/New_York',
			'MD' => 'America/New_York',
			'MA' => 'America/New_York',
			'MI' => 'America/Detroit',
			'MN' => 'America/Chicago',
			'MS' => 'America/Chicago',
			'MO' => 'America/Chicago',
			'MT' => 'America/Denver',
			'NE' => 'America/Chicago',
			'NV' => 'America/Los_Angeles',
			'NH' => 'America/New_York',
			'NJ' => 'America/New_York',
			'NM' => 'America/Denver',
			'NY' => 'America/New_York',
			'NC' => 'America/New_York',
			'ND' => 'America/Chicago',
			'OH' => 'America/New_York',
			'OK' => 'America/Chicago',
			'OR' => 'America/Los_Angeles',
			'PA' => 'America/New_York',
			'PR' => 'America/Puerto_Rico',
			'RI' => 'America/New_York',
			'SC' => 'America/New_York',
			'SD' => 'America/Chicago',
			'TN' => 'America/Chicago',
			'TX' => 'America/Chicago',
			'UT' => 'America/Denver',
			'VT' => 'America/New_York',
			'VA' => 'America/New_York',
			'VI' => 'America/Puerto_Rico',
			'WA' => 'America/Los_Angeles',
			'WV' => 'America/New_York',
			'WI' => 'America/Chicago',
			'WY' => 'America/Denver',
		],
		'CA' => [
			'AB' => 'America/Edmonton',
			'BC' => 'America/Vancouver',
			'MB' => 'America/Winnipeg',
			'NB' => 'America/Halifax',
			'NL' => 'America/St_Johns',
			'NS' => 'America/Halifax',
			'NT' => 'America/Edmonton',
			'NU' => 'America/Winnipeg',
			'ON' => 'America/Toronto',
			'PE' => 'America/Halifax',
			'QC' => 'America/Toronto',
			'SK' => 'America/Regina',
			'YT' => 'America/Whitehorse',
		],
		'AU' => [
			'ACT' => 'Australia/Sydney',
			'NSW' => 'Australia/Sydney',
			'NT'  => 'Australia/Darwin',
			'QLD' => 'Australia/Brisbane',
			'SA'  => 'Australia/Adelaide',
			'TAS' => 'Australia/Hobart',
			'VIC' => 'Australia/Melbourne',
			'WA'  => 'Australia/Perth',
		],
	];

	/**
	 * Full state and province names (lower case, letters and spaces only) to their codes.
	 */
	private const STATE_NAMES = [
		'US' => [
			'alabama' => 'AL', 'alaska' => 'AK', 'american samoa' => 'AS', 'arizona' => 'AZ', 'arkansas' => 'AR',
			'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
			'district of columbia' => 'DC', 'florida' => 'FL', 'georgia' => 'GA', 'guam' => 'GU', 'hawaii' => 'HI',
			'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
			'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA',
			'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT',
			'nebraska' => 'NE', 'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ',
			'new mexico' => 'NM', 'new york' => 'NY', 'north carolina' => 'NC', 'north dakota' => 'ND',
			'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA', 'puerto rico' => 'PR',
			'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD', 'tennessee' => 'TN',
			'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA', 'virgin islands' => 'VI',
			'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
		],
		'CA' => [
			'alberta' => 'AB', 'british columbia' => 'BC', 'manitoba' => 'MB', 'new brunswick' => 'NB',
			'newfoundland and labrador' => 'NL', 'newfoundland' => 'NL', 'nova scotia' => 'NS',
			'northwest territories' => 'NT', 'nunavut' => 'NU', 'ontario' => 'ON',
			'prince edward island' => 'PE', 'quebec' => 'QC', 'saskatchewan' => 'SK', 'yukon' => 'YT',
		],
		'AU' => [
			'australian capital territory' => 'ACT', 'new south wales' => 'NSW', 'northern territory' => 'NT',
			'queensland' => 'QLD', 'south australia' => 'SA', 'tasmania' => 'TAS', 'victoria' => 'VIC',
			'western australia' => 'WA',
		],
	];

	/**
	 * The zone for a contact, and how it was worked out: the contact's own zone, then their state
	 * or province (US, Canada and Australia), then their country's central zone, then the fallback
	 * (the site's zone).
	 *
	 * @return array{zone: DateTimeZone, source: string} `source` is contact, state, country or site.
	 */
	public static function resolveZone( $timezone, $country, $state, DateTimeZone $fallback, array $country_names = [] ): array {
		$timezone = self::currentZoneName( trim( (string) $timezone ) );

		if ( self::isZoneName( $timezone ) ) {
			return [
				'zone'   => new DateTimeZone( $timezone ),
				'source' => 'contact',
			];
		}

		$country = self::countryCode( (string) $country, $country_names );

		if ( 2 !== strlen( $country ) ) {
			return [
				'zone'   => $fallback,
				'source' => 'site',
			];
		}

		$state_zone = self::stateZone( $country, $state );

		if ( null !== $state_zone ) {
			return [
				'zone'   => new DateTimeZone( $state_zone ),
				'source' => 'state',
			];
		}

		if ( isset( self::COUNTRY_ZONES[ $country ] ) ) {
			return [
				'zone'   => new DateTimeZone( self::COUNTRY_ZONES[ $country ] ),
				'source' => 'country',
			];
		}

		// listIdentifiers() throws a ValueError on PHP 8 for a code it does not know.
		try {
			$zones = DateTimeZone::listIdentifiers( DateTimeZone::PER_COUNTRY, $country );
		} catch ( \ValueError $e ) {
			$zones = [];
		}

		if ( ! $zones ) {
			return [
				'zone'   => $fallback,
				'source' => 'site',
			];
		}

		return [
			'zone'   => new DateTimeZone( $zones[0] ),
			'source' => 'country',
		];
	}

	/**
	 * Lower-case letters and single spaces, so "U.S.A." and "United  States" compare equal.
	 */
	private static function nameKey( string $name ): string {
		$key = strtolower( trim( $name ) );
		$key = trim( (string) preg_replace( '/[^a-z ]+/', ' ', $key ) );

		return (string) preg_replace( '/\s+/', ' ', $key );
	}

	/**
	 * Country names to codes, from a list of `['code' => 'US', 'title' => 'United States']` as
	 * FluentCRM's `fluent_crm/countries` filter returns.
	 *
	 * @return array<string, string> Name key to upper-case code.
	 */
	public static function countryNameMap( $countries ): array {
		$map = [];

		foreach ( is_array( $countries ) ? $countries : [] as $country ) {
			if ( ! is_array( $country ) || empty( $country['code'] ) || empty( $country['title'] ) ) {
				continue;
			}

			$key = self::nameKey( (string) $country['title'] );

			if ( '' !== $key ) {
				$map[ $key ] = strtoupper( trim( (string) $country['code'] ) );
			}
		}

		return $map;
	}

	/**
	 * A country as an upper-case two-letter code: the code itself, or the code for a name or
	 * three-letter code in the aliases or $names. Anything else comes back as written.
	 */
	private static function countryCode( string $country, array $names ): string {
		$country = strtoupper( trim( $country ) );
		$key     = self::nameKey( $country );

		// Before the two-letter return: "UK" is not a country code.
		if ( isset( self::COUNTRY_ALIASES[ $key ] ) ) {
			return self::COUNTRY_ALIASES[ $key ];
		}

		if ( 2 === strlen( $country ) ) {
			return $country;
		}

		return $names[ $key ] ?? $country;
	}

	/**
	 * The zone name for a state or province given as a code or a full name, or null.
	 *
	 * @param string $country Upper-case two-letter country code.
	 */
	private static function stateZone( string $country, $state ): ?string {
		if ( ! isset( self::STATE_ZONES[ $country ] ) ) {
			return null;
		}

		$key = strtolower( trim( (string) $state ) );
		$key = trim( (string) preg_replace( '/[^a-z ]+/', ' ', $key ) );
		$key = (string) preg_replace( '/\s+/', ' ', $key );

		if ( '' === $key ) {
			return null;
		}

		$code = strtoupper( $key );

		if ( ! isset( self::STATE_ZONES[ $country ][ $code ] ) ) {
			$code = self::STATE_NAMES[ $country ][ $key ] ?? '';
		}

		return self::STATE_ZONES[ $country ][ $code ] ?? null;
	}

	/**
	 * Minutes after local midnight this contact's email goes out, spread across the window so clicks
	 * by local hour can later show which part of it works best.
	 *
	 * Derived from the two ids rather than random, so spreading a campaign twice gives every row
	 * the same time.
	 *
	 * @return int Minutes, at least $window_start and less than $window_end.
	 */
	public static function slotMinutes( int $campaign_id, int $subscriber_id, int $window_start, int $window_end ): int {
		$length = max( 1, $window_end - $window_start );
		$hash   = (int) sprintf( '%u', crc32( $campaign_id . ':' . $subscriber_id ) );

		return $window_start + ( $hash % $length );
	}

	/**
	 * When the clock in $zone reads the slot time on $date, but never before $not_before: a zone
	 * whose slot has already passed gets the email as soon as the campaign starts.
	 *
	 * @param string $date Local delivery date, Y-m-d.
	 */
	public static function sendOn( string $date, DateTimeZone $zone, int $slot_minutes, DateTimeImmutable $not_before ): DateTimeImmutable {
		$send = ( new DateTimeImmutable( $date, $zone ) )->setTime( intdiv( $slot_minutes, 60 ), $slot_minutes % 60 );

		return $send < $not_before ? $not_before : $send;
	}

	/**
	 * The moment the window opens on $date in the first zone to reach it.
	 *
	 * @param string $date Local delivery date, Y-m-d.
	 */
	public static function firstWindowOpens( string $date, int $window_start ): DateTimeImmutable {
		return ( new DateTimeImmutable( $date, new DateTimeZone( self::EARLIEST_ZONE ) ) )
			->setTime( intdiv( $window_start, 60 ), $window_start % 60 );
	}

	/**
	 * The site wall-clock time to store as a row's `scheduled_at` so it cannot be claimed before
	 * $instant.
	 *
	 * FluentCRM claims a row when `scheduled_at <= current_time('mysql')`, a string compare of the
	 * site's wall clock. When clocks go back an hour the wall clock repeats, so a string from the
	 * second pass would match during the first, an hour early. An instant in the second pass
	 * therefore gets the first wall-clock string that has not yet occurred, which is the wall time
	 * at which the clock was turned back.
	 *
	 * @return string Site time, Y-m-d H:i:s.
	 */
	public static function siteTimeNotBefore( DateTimeImmutable $instant, DateTimeZone $site ): string {
		$local     = $instant->setTimezone( $site );
		$wall      = $local->format( 'Y-m-d H:i:s' );
		$timestamp = $instant->getTimestamp();

		$fold = self::repeatedHour( $timestamp, $site );

		return $fold ? gmdate( 'Y-m-d H:i:s', $fold['starts'] + $fold['offset_before'] ) : $wall;
	}

	/**
	 * Like siteTimeNotBefore(), for a send that must stay inside its window. The repeated hour cannot
	 * be stored, so a send in it normally waits until that hour ends. If that would pass the window's
	 * end, it goes in the last second before the clocks went back, when that is inside the window. A
	 * window that lies wholly inside the repeated hour has no time that can be stored, so it waits.
	 *
	 * @param DateTimeImmutable $send         When the row should go.
	 * @param DateTimeImmutable $window_start When the recipient's window opens.
	 * @param DateTimeImmutable $window_end   When it closes.
	 * @param DateTimeZone      $site         The site's zone.
	 *
	 * @return string Site time, Y-m-d H:i:s.
	 */
	public static function siteTimeInWindow( DateTimeImmutable $send, DateTimeImmutable $window_start, DateTimeImmutable $window_end, DateTimeZone $site ): string {
		$fold = self::repeatedHour( $send->getTimestamp(), $site );

		if ( ! $fold || $fold['ends'] < $window_end->getTimestamp() ) {
			return self::siteTimeNotBefore( $send, $site );
		}

		$last_first_pass = $fold['starts'] - 1;

		if ( $last_first_pass < $window_start->getTimestamp() ) {
			return self::siteTimeNotBefore( $send, $site );
		}

		return gmdate( 'Y-m-d H:i:s', $last_first_pass + $fold['offset_before'] );
	}

	/**
	 * The repeated hour $timestamp falls in the second pass of, when clocks went back. Read from the
	 * transitions: PHP parses a repeated wall time as the first pass west of Greenwich but as the
	 * second pass in zones such as Europe/Berlin.
	 *
	 * @return array{starts: int, ends: int, offset_before: int}|null `starts` is when the clocks went back, `ends` when the repeat ends.
	 */
	private static function repeatedHour( int $timestamp, DateTimeZone $site ): ?array {
		$transitions = $site->getTransitions( $timestamp - 2 * 86400, $timestamp );
		$count       = is_array( $transitions ) ? count( $transitions ) : 0;

		if ( $count < 2 ) {
			return null;
		}

		$latest    = $transitions[ $count - 1 ];
		$previous  = $transitions[ $count - 2 ];
		$went_back = $previous['offset'] - $latest['offset'];

		if ( $went_back <= 0 || $timestamp >= $latest['ts'] + $went_back ) {
			return null;
		}

		return [
			'starts'        => $latest['ts'],
			'ends'          => $latest['ts'] + $went_back,
			'offset_before' => $previous['offset'],
		];
	}

	/**
	 * Whether a contact is held out of local-time delivery and sent on the normal schedule.
	 *
	 * Salted differently from slotMinutes() so being held out says nothing about the send slot.
	 */
	public static function isHoldout( int $campaign_id, int $subscriber_id, int $percent ): bool {
		if ( $percent <= 0 ) {
			return false;
		}

		$hash = (int) sprintf( '%u', crc32( 'holdout:' . $campaign_id . ':' . $subscriber_id ) );

		return ( $hash % 100 ) < $percent;
	}
}
