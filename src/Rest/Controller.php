<?php
/**
 * REST routes.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Rest;

use Noformat\Logger\Category;
use Noformat\Logger\Event;
use Noformat\Logger\Plugin;
use Noformat\Logger\Severity;
use Noformat\Logger\Source;
use Noformat\Logger\Storage\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace noformat-logger/v1 (PLAN §6). Every route uses the HMAC
 * Authenticator as permission_callback. Parameters are validated inside the
 * callbacks (scalars only; arrays are a 400), i.e. only after authentication:
 * REST `args` schemas would be checked by WordPress before the permission
 * callback and let unauthenticated callers probe the API.
 */
final class Controller {

	/**
	 * Constructor.
	 *
	 * @param Plugin        $plugin        Plugin services.
	 * @param Authenticator $authenticator Authenticator.
	 * @param Responder     $responder     Responder.
	 */
	public function __construct(
		private Plugin $plugin,
		private Authenticator $authenticator,
		private Responder $responder
	) {
	}

	/**
	 * Hook into WordPress.
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_filter( 'rest_post_dispatch', [ $this->responder, 'decorate' ], 10, 3 );
		add_filter( 'rest_index', [ $this, 'hideNamespace' ] );
		add_filter( 'rest_pre_dispatch', [ $this, 'blockNamespaceIndex' ], 10, 3 );
		add_filter( 'rest_authentication_errors', [ Authenticator::class, 'allowSignedRequests' ], PHP_INT_MAX, 1 );
	}

	/**
	 * Register routes.
	 */
	public function routes(): void {
		$permission = [ $this->authenticator, 'permission' ];
		$routes     = [
			'/manifest'              => [ 'GET', 'manifest' ],
			'/events'                => [ 'GET', 'events' ],
			'/events/(?P<id>[0-9]+)' => [ 'GET', 'event' ],
			'/summary'               => [ 'GET', 'summary' ],
			'/health'                => [ 'GET', 'health' ],
			'/verify'                => [ 'GET', 'verify' ],
			'/marker'                => [ 'POST', 'marker' ],
		];
		foreach ( $routes as $route => [ $method, $callback ] ) {
			register_rest_route(
				Authenticator::NAMESPACE,
				$route,
				[
					'methods'             => $method,
					'callback'            => [ $this, $callback ],
					'permission_callback' => $permission,
					'show_in_index'       => false,
				]
			);
		}
	}

	/**
	 * Remove the namespace from the public REST index.
	 *
	 * @param mixed $response Index response.
	 */
	public function hideNamespace( mixed $response ): mixed {
		if ( $response instanceof \WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) {
				$data['namespaces'] = array_values( array_diff( $data['namespaces'], [ Authenticator::NAMESPACE ] ) );
				unset( $data['routes'][ '/' . Authenticator::NAMESPACE ] );
				$response->set_data( $data );
			}
		}
		return $response;
	}

	/**
	 * The automatic namespace index (/noformat-logger/v1) answers like any unauthenticated call.
	 *
	 * @param mixed                                  $result  Result so far.
	 * @param mixed                                  $server  Server.
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function blockNamespaceIndex( mixed $result, mixed $server, \WP_REST_Request $request ): mixed {
		if ( '/' . Authenticator::NAMESPACE === rtrim( $request->get_route(), '/' ) ) {
			return new \WP_Error( 'nflog_unauthorized', 'Unauthorized', [ 'status' => 401 ] );
		}
		return $result;
	}

	/**
	 * GET /manifest.
	 */
	public function manifest(): \WP_REST_Response {
		return $this->guard(
			fn() => $this->responder->ok( $this->plugin->manifest( $this->authenticator->authenticatedKey() ) )
		);
	}

	/**
	 * GET /events.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function events( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->guard(
			function () use ( $request ) {
				$after = self::intParam( $request, 'after', 0 );
				$limit = self::intParam( $request, 'limit', 100 );
				if ( null === $after || null === $limit || $limit < 1 || $limit > 500 ) {
					return $this->badRequest( 'after must be >= 0 and limit between 1 and 500' );
				}
				$filters = $this->filters( $request );
				if ( is_string( $filters ) ) {
					return $this->badRequest( $filters );
				}
				$ack = self::intParam( $request, 'ack', 0 );
				if ( null === $ack ) {
					return $this->badRequest( 'ack must be a non-negative integer' );
				}
				$compact  = 'compact' === $request->get_param( 'fields' );
				$result   = $this->plugin->repository()->after( $after, $limit, $filters );
				$events   = array_map( fn( array $row ) => $this->responder->event( $row, $compact ), $result['rows'] );
				$last     = [] === $events ? $after : (int) end( $events )['id'];
				$head     = $this->plugin->repository()->chainHead();
				$response = $this->responder->ok(
					[
						'events'     => $events,
						'next_after' => $last,
						'has_more'   => $result['has_more'],
						'chain_head' => $head,
					]
				);
				$this->recordDelivery( [] === $events ? 0 : $last, $ack, $head['id'] );
				return $response;
			}
		);
	}

	/**
	 * After a GET /events response is built: move the ack cursor (monotonic,
	 * never past the chain head), remember the highest id served, and close the
	 * aggregation windows of every event the collector now has, so later
	 * occurrences become new events instead of raising a count it already read.
	 *
	 * @param int $served Highest id in this response (0: none).
	 * @param int $ack    Acknowledged id (0: none).
	 * @param int $headId Chain head id.
	 */
	private function recordDelivery( int $served, int $ack, int $headId ): void {
		try {
			$meta    = $this->plugin->meta();
			$through = 0;
			if ( $ack > 0 ) {
				$ack     = min( $ack, $headId );
				$current = $meta->getInt( Meta::ACK_CURSOR );
				if ( $ack > $current ) {
					$meta->set( Meta::ACK_CURSOR, (string) $ack );
				}
				$through = $ack;
			}
			if ( $served > 0 ) {
				if ( $served > $meta->getInt( Meta::LAST_SERVED ) ) {
					$meta->set( Meta::LAST_SERVED, (string) $served );
				}
				$through = max( $through, $served );
			}
			if ( $through > 0 ) {
				$this->plugin->fingerprints()->closeServed( $through );
			}
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->recordFailure( 'rest.delivery', $e );
		}
	}

	/**
	 * GET /events/{id}.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function event( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->guard(
			function () use ( $request ) {
				$row = $this->plugin->repository()->find( (int) $request->get_param( 'id' ) );
				if ( null === $row ) {
					return $this->responder->error( 'nflog_not_found', 'Not found', 404 );
				}
				return $this->responder->ok( [ 'event' => $this->responder->event( $row ) ] );
			}
		);
	}

	/**
	 * GET /summary?window=24h.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function summary( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->guard(
			function () use ( $request ) {
				$window  = $request->get_param( 'window' ) ?? '24h';
				$seconds = is_string( $window ) ? self::windowSeconds( $window ) : null;
				if ( null === $seconds ) {
					return $this->badRequest( 'window must look like 1h, 24h, 7d (max 90d)' );
				}
				return $this->responder->ok( $this->plugin->repository()->summary( $seconds ) );
			}
		);
	}

	/**
	 * GET /health.
	 */
	public function health(): \WP_REST_Response {
		return $this->guard( fn() => $this->responder->ok( $this->plugin->health()->snapshot() ) );
	}

	/**
	 * GET /verify?from=&to=.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function verify( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->guard(
			function () use ( $request ) {
				$from = self::intParam( $request, 'from', 0 );
				$to   = self::intParam( $request, 'to', 0 );
				if ( null === $from || null === $to ) {
					return $this->badRequest( 'from and to must be non-negative integers' );
				}
				return $this->responder->ok( $this->plugin->repository()->verify( $from > 0 ? $from : null, $to > 0 ? $to : null ) );
			}
		);
	}

	/**
	 * POST /marker {"label": "...", "ref": "..."}.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function marker( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->guard(
			function () use ( $request ) {
				$body  = $request->get_json_params();
				$label = is_array( $body ) && is_string( $body['label'] ?? null ) ? trim( sanitize_text_field( $body['label'] ) ) : '';
				$ref   = is_array( $body ) && is_string( $body['ref'] ?? null ) ? substr( sanitize_text_field( $body['ref'] ), 0, 64 ) : null;
				if ( '' === $label ) {
					return $this->badRequest( 'label is required' );
				}
				$this->plugin->context()->overrideSource( Source::WEBHOOK );
				$event  = self::markerEvent( $label, $ref )->actor( 'app' );
				$stored = $this->plugin->logger()->logNow( $event );
				if ( null === $stored ) {
					return $this->responder->error( 'nflog_storage_unavailable', 'Event could not be stored', 503 );
				}
				return $this->responder->ok(
					[
						'id'   => $stored->getId(),
						'hash' => $stored->getHash(),
					],
					201
				);
			}
		);
	}

	/**
	 * Deploy marker event (shared with WP-CLI).
	 *
	 * @param string      $label Label.
	 * @param string|null $ref   Git ref.
	 */
	public static function markerEvent( string $label, ?string $ref ): Event {
		$label = Event::clip( $label, 200 );
		return Event::make( Category::DEPLOY, 'deploy.marker', Severity::NOTICE )
			->summary( 'Deploy marker: ' . $label )
			->context(
				array_filter(
					[
						'label' => $label,
						'ref'   => $ref,
					],
					static fn( $v ) => null !== $v && '' !== $v
				)
			);
	}

	/**
	 * Parse filters.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>|string Filters or an error message.
	 */
	private function filters( \WP_REST_Request $request ): array|string {
		$filters = [];
		foreach ( [ 'min_severity', 'category', 'since', 'until', 'fields' ] as $name ) {
			$value = $request->get_param( $name );
			if ( null !== $value && ! is_scalar( $value ) ) {
				return $name . ' must be a single value';
			}
		}
		$min = $request->get_param( 'min_severity' );
		if ( null !== $min && '' !== $min ) {
			$severity = Severity::parse( (string) $min );
			if ( null === $severity ) {
				return 'unknown min_severity';
			}
			$filters['min_severity'] = $severity;
		}
		$category = $request->get_param( 'category' );
		if ( null !== $category && '' !== $category ) {
			$list = array_filter( array_map( 'trim', explode( ',', (string) $category ) ) );
			foreach ( $list as $item ) {
				if ( null === Category::tryFrom( $item ) ) {
					return 'unknown category';
				}
			}
			$filters['categories'] = array_values( $list );
		}
		foreach ( [ 'since', 'until' ] as $name ) {
			$value = $request->get_param( $name );
			if ( null === $value || '' === $value ) {
				continue;
			}
			$ms = ctype_digit( (string) $value ) ? (int) $value * 1000 : Event::parseMs( (string) $value );
			if ( null === $ms ) {
				return $name . ' must be unix seconds or ISO-8601 UTC';
			}
			$filters[ $name . '_ms' ] = $ms;
		}
		return $filters;
	}

	/**
	 * Window like 15m, 24h, 7d (max 90 days).
	 *
	 * @param string $window Window.
	 */
	public static function windowSeconds( string $window ): ?int {
		if ( 1 !== preg_match( '/^([1-9][0-9]{0,4})([mhd])$/', trim( $window ), $m ) ) {
			return null;
		}
		$seconds = (int) $m[1] * [
			'm' => 60,
			'h' => 3600,
			'd' => 86400,
		][ $m[2] ];
		return $seconds > 90 * 86400 ? null : $seconds;
	}

	/**
	 * Non-negative integer param.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request  Request.
	 * @param string                                 $name     Name.
	 * @param int                                    $fallback Default.
	 */
	private static function intParam( \WP_REST_Request $request, string $name, int $fallback ): ?int {
		$value = $request->get_param( $name );
		if ( null === $value || '' === $value ) {
			return $fallback;
		}
		return is_scalar( $value ) && ctype_digit( (string) $value ) ? (int) $value : null;
	}

	/**
	 * 400.
	 *
	 * @param string $message Message.
	 */
	private function badRequest( string $message ): \WP_REST_Response {
		return $this->responder->error( 'nflog_bad_request', $message, 400 );
	}

	/**
	 * Never let an exception escape a handler.
	 *
	 * @param callable(): \WP_REST_Response $callback Handler.
	 */
	private function guard( callable $callback ): \WP_REST_Response {
		try {
			return $callback();
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->recordFailure( 'rest', $e );
			return $this->responder->error( 'nflog_storage_unavailable', 'Storage unavailable', 503 );
		}
	}
}
