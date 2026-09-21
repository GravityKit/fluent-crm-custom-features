<?php
/**
 * EnrichmentError DTO
 *
 * @package CustomCRM\Enrichment
 */

namespace CustomCRM\Enrichment;

/**
 * Normalized error returned by enrichment providers.
 */
class EnrichmentError {

	/** No matching record found. */
	public const NO_MATCH = 'no_match';

	/** Input data was invalid or insufficient. */
	public const INVALID_INPUT = 'invalid_input';

	/** API authentication failed (bad or missing key). */
	public const AUTH_FAILED = 'auth_failed';

	/** Request was rate-limited by the provider. */
	public const RATE_LIMITED = 'rate_limited';

	/** API quota or credit limit exceeded. */
	public const QUOTA_EXCEEDED = 'quota_exceeded';

	/** The provider returned an unexpected server error. */
	public const PROVIDER_ERROR = 'provider_error';

	/** A network-level error occurred before a response was received. */
	public const NETWORK_ERROR = 'network_error';

	/**
	 * Normalized error code (use class constants).
	 *
	 * @var string
	 */
	public string $code;

	/**
	 * Human-readable error message.
	 *
	 * @var string
	 */
	public string $message;

	/**
	 * Raw HTTP status from the provider.
	 *
	 * @var int|null
	 */
	public ?int $http_status;

	/**
	 * Whether the caller should retry.
	 *
	 * @var bool
	 */
	public bool $retryable;

	/**
	 * Create a new enrichment error.
	 *
	 * @param string   $code        One of the class constants.
	 * @param string   $message     Human-readable message.
	 * @param int|null $http_status Raw HTTP status code.
	 * @param bool     $retryable   Whether the error is retryable.
	 */
	public function __construct( string $code, string $message, ?int $http_status = null, bool $retryable = false ) {
		$this->code        = $code;
		$this->message     = $message;
		$this->http_status = $http_status;
		$this->retryable   = $retryable;
	}
}
