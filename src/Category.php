<?php
/**
 * Category enum.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Event category (CONTRACT §1).
 */
enum Category: string {

	case CONTENT   = 'content';
	case MEDIA     = 'media';
	case TAXONOMY  = 'taxonomy';
	case MENU      = 'menu';
	case FIELDS    = 'fields';
	case USER      = 'user';
	case COMMENT   = 'comment';
	case SETTINGS  = 'settings';
	case THEME     = 'theme';
	case PLUGIN    = 'plugin';
	case CORE      = 'core';
	case COMMERCE  = 'commerce';
	case SECURITY  = 'security';
	case ERROR     = 'error';
	case STABILITY = 'stability';
	case MAIL      = 'mail';
	case HTTP      = 'http';
	case CRON      = 'cron';
	case PRIVACY   = 'privacy';
	case BUILD     = 'build';
	case DEPLOY    = 'deploy';
	case SYSTEM    = 'system';

	/**
	 * All values as strings.
	 *
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}
}
