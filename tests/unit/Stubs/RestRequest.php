<?php
/**
 * Minimal WP_REST_Request / WP_Error stand-ins for unit tests (only when WordPress is absent).
 *
 * @package Noformat\Logger
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_REST_Request', false ) ) {
	class WP_REST_Request {
		public function __construct( private string $method = 'GET', private string $route = '/', private array $headers = [], private array $params = [], private string $body = '' ) {
		}
		public function get_method() { return $this->method; }
		public function get_route() { return $this->route; }
		public function get_header( $name ) { return $this->headers[ strtolower( (string) $name ) ] ?? null; }
		public function get_param( $name ) { return $this->params[ $name ] ?? null; }
		public function get_body() { return $this->body; }
	}
}
if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {
		public function __construct( public $code = '', public $message = '', public $data = '' ) {
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
