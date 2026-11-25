<?php
/**
 * Cloudflare Turnstile adapter (TASK-029b, ADR-003 third-party API, D2).
 *
 * Available only when BOTH constants `VX_TURNSTILE_SITE_KEY` and `VX_TURNSTILE_SECRET_KEY` (wp-config.php) are non-empty;
 * the filter `vextra/core/turnstile/keys` ( array{site:string,secret:string} ) may supply them for tests, constants win.
 * Without keys nothing happens: no script, no widget, no outgoing request.
 *
 * Contract used by the form wiring (Security\TurnstileForms) and, later, by checkout (E6):
 * `render_widget( $action )`, `enqueue()`, `verify( $token, $ip, $action )`.
 *
 * SEC-049: a passed siteverify answer is also checked for the widget `action` (when the caller names one) and for `hostname`
 * (host of `home_url()`, list filterable through `vextra/core/turnstile/hostnames`), so a token minted on another site or for
 * another form is refused. The Cloudflare dummy test keys answer with hostname `example.com` and no action: those checks are
 * skipped ONLY when `wp_get_environment_type()` is `local` AND the secret is one of the documented dummy secrets.
 *
 * Provider failure (network error, non-200, malformed JSON, `internal-error`, a rejected secret) = fail OPEN by default,
 * fail CLOSED when `VX_TURNSTILE_FAIL` is `closed`. One log line per request. The secret is never logged, printed or sent
 * anywhere except the siteverify call.
 *
 * @package Vextra\Core
 */

declare(strict_types=1);

namespace Vextra\Core\Integrations\Turnstile;

use Vextra\Core\Integrations\AbstractIntegration;
use Vextra\Core\Integrations\Polylang\Polylang;
use Vextra\Core\Plugin;
use Vextra\Core\Support\Log;

final class Turnstile extends AbstractIntegration {

	public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

	/**
	 * Name of the form field the widget fills.
	 */
	public const FIELD = 'cf-turnstile-response';

	/**
	 * Allowed `data-action` values.
	 */
	public const ACTIONS = [ 'login', 'lostpassword', 'register', 'checkout', 'review', 'track' ];

	/**
	 * Documented Cloudflare testing secrets (always pass / always block / token already spent).
	 */
	private const DUMMY_SECRETS = [
		'1x0000000000000000000000000000000AA',
		'2x0000000000000000000000000000000AA',
		'3x0000000000000000000000000000000AA',
	];

	// Not prefixed `vx-`: the theme marks every `vx-*` script as an ES module, api.js must stay a classic script.
	private const HANDLE = 'cf-turnstile-api';

	/**
	 * Verification results of this request by token hash (a token is single-use at Cloudflare).
	 *
	 * @var array<string,array{ok:bool,reason:string}>
	 */
	private static array $memo = [];

	/**
	 * Provider failure already logged in this request.
	 *
	 * @var bool
	 */
	private static bool $logged = false;

	public function id(): string {
		return 'turnstile';
	}

	public function version(): ?string {
		return null;
	}

	protected function detect(): bool {
		return null !== $this->keys();
	}

	/**
	 * Output the widget container for a form (nothing when unavailable).
	 *
	 * @param string              $action  One of self::ACTIONS.
	 * @param array<string,mixed> $options Optional: `appearance` (`always`|`execute`|`interaction-only`, default Cloudflare's `always`),
	 *                                     `reserve` (bool, default true: keep 65 px so the form does not jump; false = zero height until
	 *                                     Cloudflare needs interaction, used by the checkout slot), `class` (extra CSS class).
	 */
	public function render_widget( string $action, array $options = [] ): void {
		$keys = $this->keys();
		if ( ! $this->is_available() || null === $keys || ! in_array( $action, self::ACTIONS, true ) ) {
			return;
		}
		$this->enqueue();

		$appearance = isset( $options['appearance'] ) && in_array( $options['appearance'], [ 'always', 'execute', 'interaction-only' ], true ) ? (string) $options['appearance'] : '';
		$reserve    = ! array_key_exists( 'reserve', $options ) || (bool) $options['reserve'];
		$class      = isset( $options['class'] ) && is_string( $options['class'] ) ? trim( preg_replace( '/[^A-Za-z0-9_\- ]/', '', $options['class'] ) ?? '' ) : '';

		// Default: fixed height of the widget (65 px) so the form does not jump when Cloudflare injects the iframe.
		printf(
			'<div class="cf-turnstile%1$s" data-sitekey="%2$s" data-action="%3$s" data-theme="light" data-language="%4$s"%5$s style="%6$s"></div>',
			'' !== $class ? ' ' . esc_attr( $class ) : '',
			esc_attr( $keys['site'] ),
			esc_attr( $action ),
			esc_attr( $this->language() ),
			'' !== $appearance ? ' data-appearance="' . esc_attr( $appearance ) . '" data-refresh-expired="auto"' : '',
			$reserve ? 'min-height:65px;margin:0 0 16px' : 'min-height:0;margin:0'
		);
		echo '<noscript>' . esc_html__( 'Увімкніть JavaScript, щоб пройти перевірку «я не робот».', 'vextra-core' ) . '</noscript>';
	}

	/**
	 * Public site key (empty when the adapter is unavailable). Safe to print: it is public by design.
	 */
	public function site_key(): string {
		$keys = $this->keys();
		return $this->is_available() && null !== $keys ? $keys['site'] : '';
	}

	/**
	 * Widget language code for the current request (`uk`, `ru` or `auto`).
	 */
	public function widget_language(): string {
		return $this->language();
	}

	/**
	 * Load api.js (footer, `async defer`). Callers invoke it only on pages that print a widget.
	 */
	public function enqueue(): void {
		if ( ! $this->is_available() || wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		// Null version: no `?ver=` on a third-party URL. No preconnect / dns-prefetch / preload hint is added anywhere.
		wp_enqueue_script(
			self::HANDLE,
			self::SCRIPT_URL,
			[],
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			[
				'strategy'  => 'async',
				'in_footer' => true,
			]
		);
		add_filter( 'script_loader_tag', [ $this, 'add_defer' ], 10, 2 );
	}

	/**
	 * Add `defer` next to WordPress's `async` for our handle only.
	 *
	 * @param mixed $tag    Script tag.
	 * @param mixed $handle Script handle.
	 * @return mixed
	 */
	public function add_defer( $tag, $handle = '' ) {
		if ( self::HANDLE !== $handle || ! is_string( $tag ) || str_contains( $tag, ' defer' ) ) {
			return $tag;
		}
		return preg_replace( '/<script\b/', '<script defer', $tag, 1 ) ?? $tag;
	}

	/**
	 * Verify a widget token with Cloudflare.
	 *
	 * @param string      $token  Value of the `cf-turnstile-response` field.
	 * @param string      $ip     Raw client IP (sent as `remoteip`).
	 * @param string|null $action Widget action of the form being verified (null = not compared).
	 * @return array{ok:bool,reason:string} `reason`: `off`, `missing`, `invalid`, `action`, `hostname`, `passed`, `provider_failure`.
	 */
	public function verify( string $token, string $ip, ?string $action = null ): array {
		$keys = $this->keys();
		if ( ! $this->is_available() || null === $keys ) {
			return [
				'ok'     => true,
				'reason' => 'off',
			];
		}

		$token = trim( $token );
		if ( '' === $token || strlen( $token ) > 2048 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $token ) ) {
			return [
				'ok'     => false,
				'reason' => 'missing',
			];
		}

		$memo = hash( 'sha256', $token . '|' . ( $action ?? '' ) );
		if ( isset( self::$memo[ $memo ] ) ) {
			return self::$memo[ $memo ];
		}

		$body = [
			'secret'   => $keys['secret'],
			'response' => $token,
		];
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$body['remoteip'] = $ip;
		}
		$response = wp_safe_remote_post(
			self::VERIFY_URL,
			[
				'timeout'     => 5,
				'redirection' => 0,
				'body'        => $body,
			]
		);

		self::$memo[ $memo ] = $this->parse( $response, $action );
		return self::$memo[ $memo ];
	}

	/**
	 * Fail-open unless `VX_TURNSTILE_FAIL` is `closed`.
	 */
	public function fails_open(): bool {
		return ! ( defined( 'VX_TURNSTILE_FAIL' ) && 'closed' === strtolower( (string) VX_TURNSTILE_FAIL ) );
	}

	/**
	 * Turn the siteverify answer into a verdict.
	 *
	 * @param mixed       $response Return value of wp_safe_remote_post().
	 * @param string|null $action   Expected widget action (null = not compared).
	 * @return array{ok:bool,reason:string}
	 */
	private function parse( $response, ?string $action = null ): array {
		if ( is_wp_error( $response ) ) {
			return $this->provider_failure( 'transport' );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->provider_failure( 'http' );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['success'] ) || ! is_bool( $data['success'] ) ) {
			return $this->provider_failure( 'malformed' );
		}
		if ( true === $data['success'] ) {
			return $this->check_answer( $data, $action );
		}

		$codes = isset( $data['error-codes'] ) && is_array( $data['error-codes'] ) ? array_map( 'strval', $data['error-codes'] ) : [];
		// Our own mistake or Cloudflare's: a rejected secret / internal error says nothing about the visitor.
		if ( array() !== array_intersect( $codes, [ 'invalid-input-secret', 'missing-input-secret', 'internal-error' ] ) ) {
			return $this->provider_failure( 'config' );
		}
		return [
			'ok'     => false,
			'reason' => 'invalid',
		];
	}

	/**
	 * SEC-049: action and hostname of a successful answer.
	 *
	 * @param array<string,mixed> $data   Decoded siteverify answer.
	 * @param string|null         $action Expected widget action (null = not compared).
	 * @return array{ok:bool,reason:string}
	 */
	private function check_answer( array $data, ?string $action ): array {
		$dummy = $this->uses_dummy_keys();
		if ( null !== $action && ( isset( $data['action'] ) || ! $dummy ) && ( ! isset( $data['action'] ) || ! is_string( $data['action'] ) || $data['action'] !== $action ) ) {
			return [
				'ok'     => false,
				'reason' => 'action',
			];
		}
		if ( ! $dummy ) {
			$host = isset( $data['hostname'] ) && is_string( $data['hostname'] ) ? strtolower( $data['hostname'] ) : '';
			if ( '' === $host || ! in_array( $host, $this->hostnames(), true ) ) {
				return [
					'ok'     => false,
					'reason' => 'hostname',
				];
			}
		}
		return [
			'ok'     => true,
			'reason' => 'passed',
		];
	}

	/**
	 * Hostnames a valid token may carry: the host of `home_url()` (+ the www / non-www twin) and the filter's additions.
	 *
	 * @return string[]
	 */
	private function hostnames(): array {
		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$hosts = '' === $host ? [] : [ $host ];
		if ( '' !== $host ) {
			$hosts[] = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
		}

		/**
		 * Filters the hostnames accepted in a Turnstile answer (default: `home_url()` host and its www / non-www twin).
		 *
		 * @param string[] $hosts Lower-case host names.
		 */
		$filtered = apply_filters( 'vextra/core/turnstile/hostnames', $hosts );
		return is_array( $filtered ) ? array_values( array_filter( array_map( static fn( $h ): string => is_string( $h ) ? strtolower( $h ) : '', $filtered ), 'strlen' ) ) : $hosts;
	}

	/**
	 * Local environment AND a documented Cloudflare dummy secret (the three published `1x`/`2x`/`3x` secrets).
	 */
	private function uses_dummy_keys(): bool {
		$keys = $this->keys();
		return null !== $keys && 'local' === wp_get_environment_type() && in_array( $keys['secret'], self::DUMMY_SECRETS, true );
	}

	/**
	 * Verdict for an unusable provider answer; logs once per request (no secret, no body).
	 *
	 * @param string $why Short cause.
	 * @return array{ok:bool,reason:string}
	 */
	private function provider_failure( string $why ): array {
		if ( ! self::$logged ) {
			self::$logged = true;
			Log::warning(
				'Turnstile provider failure (' . ( $this->fails_open() ? 'fail open' : 'fail closed' ) . ')',
				[ 'cause' => $why ],
				'security'
			);
		}
		return [
			'ok'     => $this->fails_open(),
			'reason' => 'provider_failure',
		];
	}

	/**
	 * Widget language: the current Polylang language when it is uk / ru, else `auto`.
	 */
	private function language(): string {
		$pll = Plugin::instance()->integration( 'polylang' );
		if ( ! $pll instanceof Polylang || ! $pll->is_ready() ) {
			return 'auto';
		}
		$slug  = $pll->current_language();
		$codes = $pll->locale_codes();
		$code  = '' !== $slug ? ( $codes[ $slug ] ?? $slug ) : '';
		return in_array( $code, [ 'uk', 'ru' ], true ) ? $code : 'auto';
	}

	/**
	 * Both keys, or null when either is missing / malformed.
	 *
	 * @return array{site:string,secret:string}|null
	 */
	private function keys(): ?array {
		$site   = defined( 'VX_TURNSTILE_SITE_KEY' ) ? trim( (string) constant( 'VX_TURNSTILE_SITE_KEY' ) ) : '';
		$secret = defined( 'VX_TURNSTILE_SECRET_KEY' ) ? trim( (string) constant( 'VX_TURNSTILE_SECRET_KEY' ) ) : '';

		if ( '' === $site || '' === $secret ) {
			/**
			 * Supplies the Turnstile keys when the constants are not both set (tests only; never commit keys).
			 *
			 * @param array<string,string> $keys `site` and `secret`.
			 */
			$filtered = apply_filters( 'vextra/core/turnstile/keys', [] );
			if ( is_array( $filtered ) && isset( $filtered['site'], $filtered['secret'] ) && is_string( $filtered['site'] ) && is_string( $filtered['secret'] ) ) {
				$site   = trim( $filtered['site'] );
				$secret = trim( $filtered['secret'] );
			}
		}

		$shape = '/^[0-9A-Za-z_\-]{8,100}$/';
		if ( 1 !== preg_match( $shape, $site ) || 1 !== preg_match( $shape, $secret ) ) {
			return null;
		}
		return [
			'site'   => $site,
			'secret' => $secret,
		];
	}
}
