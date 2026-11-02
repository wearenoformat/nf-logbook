<?php
/**
 * REST responses.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Rest;

use Noformat\Logger\Event;
use Noformat\Logger\Storage\EventRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Builds JSON responses and adds the namespace headers to every response of
 * the namespace (including WordPress-generated errors) via rest_post_dispatch.
 * X-NF-Chain-Head (one SELECT) is added only to authenticated responses, so a
 * 401 reveals nothing and costs no query.
 */
final class Responder {

	/**
	 * Constructor.
	 *
	 * @param EventRepository    $repository    Repository (chain head header).
	 * @param Authenticator|null $authenticator Authenticator (chain head only after authentication).
	 */
	public function __construct( private EventRepository $repository, private ?Authenticator $authenticator = null ) {
	}

	/**
	 * Success response.
	 *
	 * @param array<string, mixed> $data   Data.
	 * @param int                  $status Status.
	 */
	public function ok( array $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	/**
	 * Error response with a stable code.
	 *
	 * @param string $code    Code (nflog_*).
	 * @param string $message Generic message.
	 * @param int    $status  Status.
	 */
	public function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => $code,
				'message' => $message,
				'data'    => [ 'status' => $status ],
			],
			$status
		);
	}

	/**
	 * Event row to API shape.
	 *
	 * @param array<string, mixed> $row     Row.
	 * @param bool                 $compact Omit context.
	 * @return array<string, mixed>
	 */
	public function event( array $row, bool $compact = false ): array {
		return EventRepository::rowToArray( $row, home_url(), $compact );
	}

	/**
	 * Filter rest_post_dispatch: add headers to namespace responses.
	 *
	 * @param mixed                                  $response Response.
	 * @param mixed                                  $server   Server.
	 * @param \WP_REST_Request<array<string, mixed>> $request  Request.
	 */
	public function decorate( mixed $response, mixed $server, \WP_REST_Request $request ): mixed {
		if ( ! $response instanceof \WP_HTTP_Response || ! str_starts_with( $request->get_route(), '/' . Authenticator::NAMESPACE ) ) {
			return $response;
		}
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-NF-Schema', (string) Event::SCHEMA );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		if ( null === $this->authenticator || null === $this->authenticator->authenticatedKey() ) {
			return $response;
		}
		try {
			$head = $this->repository->chainHead();
			$response->header( 'X-NF-Chain-Head', $head['id'] . ':' . $head['hash'] );
		} catch ( \Throwable $e ) {
			$response->header( 'X-NF-Chain-Head', 'unavailable' );
		}
		return $response;
	}
}
