<?php
/**
 * PHPUnit bootstrap (Brain Monkey, no WordPress).
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

define( 'ABSPATH', sys_get_temp_dir() . '/nflog-abspath/' );
define( 'NOFORMAT_LOGGER_VERSION', '0.1.0' );
define( 'NOFORMAT_LOGGER_FILE', dirname( __DIR__, 2 ) . '/nf-logbook.php' );
define( 'NOFORMAT_LOGGER_DIR', dirname( __DIR__, 2 ) . '/' );

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
