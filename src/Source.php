<?php
/**
 * Source enum.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Where a request came from (CONTRACT §1 request.source).
 */
enum Source: string {

	case ADMIN        = 'admin';
	case REST         = 'rest';
	case AJAX         = 'ajax';
	case XMLRPC       = 'xmlrpc';
	case CRON         = 'cron';
	case CLI          = 'cli';
	case FRONTEND     = 'frontend';
	case APP_PASSWORD = 'app_password';
	case WEBHOOK      = 'webhook';
	case STUDIO       = 'studio';
	case BUILD        = 'build';
}
