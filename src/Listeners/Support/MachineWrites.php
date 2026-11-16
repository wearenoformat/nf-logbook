<?php
/**
 * MachineWrites.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure decision for OptionListener and MetaListener: whether an option or meta
 * write is machine state that is not stored at all.
 *
 * - `machine_state`: the write is technical (state-key pattern, or a write
 *   during a read request, cron or heartbeat; see Config::isTechnicalKey() and
 *   RequestContext::machineWriteReason()), whoever the actor is;
 * - `no_person`: the object type requires a person (options, user meta) and
 *   no logged-in user is behind the request (a visitor, cron, unauthenticated
 *   REST/AJAX, code running before `init`). WP-CLI counts as a person.
 *
 * Writes that are always recorded (notice and critical options, security user
 * meta) are passed with technical null and person not required. With the
 * `record_machine_writes` setting on,
 * nothing is skipped here and technical writes keep the 1.3 treatment
 * (`debug` + `context.technical`, stored only with `log_debug`).
 */
final class MachineWrites {

	/**
	 * Why a write is not recorded (null: record it).
	 *
	 * @param string|null $technical      Technical reason (`key_pattern`, `read_request`, `cron`, `heartbeat`) or null.
	 * @param bool        $personRequired Only a person's write is recorded (options, user meta; false for always-recorded keys).
	 * @param bool        $person         A logged-in user is behind the request.
	 * @param bool        $cli            The request is WP-CLI.
	 * @param bool        $recordMachine  Setting `record_machine_writes` (opt back in).
	 */
	public static function skipReason( ?string $technical, bool $personRequired, bool $person, bool $cli, bool $recordMachine ): ?string {
		if ( $recordMachine ) {
			return null;
		}
		if ( null !== $technical ) {
			return 'machine_state';
		}
		if ( $personRequired && ! $person && ! $cli ) {
			return 'no_person';
		}
		return null;
	}
}
