<?php
/**
 * In-memory Store, fake rounds backend and scriptable providers for the ai-backend tests.
 *
 * @package BorduurPatchStudio
 */

use BorduurPatchStudio\AI\Store;
use BorduurPatchStudio\AI\Providers\Provider;
use BorduurPatchStudio\AI\Providers\Provider_Error;
use BorduurPatchStudio\AI\Providers\Provider_Result;

/**
 * Store on arrays, same semantics as Store_Wpdb (atomic status-guarded updates, unique owner+client_req).
 */
final class Memory_Store implements Store {

	public $jobs   = array();
	public $owners = array();
	public $costs  = array();

	public function job_insert( array $row ): bool {
		foreach ( $this->jobs as $j ) {
			if ( $j['owner_key'] === $row['owner_key'] && null !== $row['client_req'] && $j['client_req'] === $row['client_req'] ) {
				return false;
			}
		}
		if ( isset( $this->jobs[ $row['id'] ] ) ) {
			return false;
		}
		$this->jobs[ $row['id'] ] = $row;
		return true;
	}
	public function job_get( string $id ): ?array {
		return $this->jobs[ $id ] ?? null;
	}
	public function job_update( string $id, array $fields, ?string $expect_status = null ): int {
		if ( ! isset( $this->jobs[ $id ] ) || ( null !== $expect_status && $this->jobs[ $id ]['status'] !== $expect_status ) ) {
			return 0;
		}
		unset( $fields['id'] );
		$this->jobs[ $id ] = array_merge( $this->jobs[ $id ], $fields );
		return 1;
	}
	public function job_find_req( string $owner, string $client_req ): ?array {
		foreach ( $this->jobs as $j ) {
			if ( $j['owner_key'] === $owner && $j['client_req'] === $client_req ) {
				return $j;
			}
		}
		return null;
	}
	public function job_open( string $owner ): ?array {
		$open = array_filter( $this->jobs, fn( $j ) => $j['owner_key'] === $owner && in_array( $j['status'], array( 'queued', 'running' ), true ) );
		usort( $open, fn( $a, $b ) => strcmp( $b['created_at'], $a['created_at'] ) );
		return $open[0] ?? null;
	}
	public function job_count_status( string $status ): int {
		return count( array_filter( $this->jobs, fn( $j ) => $j['status'] === $status ) );
	}
	public function job_stale( string $status, string $column, string $before, int $limit ): array {
		return array_slice( array_values( array_filter( $this->jobs, fn( $j ) => $j['status'] === $status && null !== $j[ $column ] && $j[ $column ] < $before ) ), 0, $limit );
	}
	public function job_list( string $owner, string $since, int $limit ): array {
		$l = array_filter( $this->jobs, fn( $j ) => $j['owner_key'] === $owner && $j['created_at'] >= $since );
		usort( $l, fn( $a, $b ) => strcmp( $b['created_at'], $a['created_at'] ) );
		return array_slice( $l, 0, $limit );
	}
	public function job_expired( string $now, int $limit ): array {
		return array_slice( array_values( array_filter( $this->jobs, fn( $j ) => $j['keep_until'] < $now && ! in_array( $j['status'], array( 'queued', 'running' ), true ) ) ), 0, $limit );
	}
	public function job_blocked_with_input( string $before, int $limit ): array {
		return array_slice( array_values( array_filter( $this->jobs, fn( $j ) => 'blocked' === $j['status'] && $j['created_at'] < $before && ( false !== strpos( (string) $j['input'], '"description":"' ) || false !== strpos( (string) $j['input'], '"instruction":"' ) ) ) ), 0, $limit );
	}
	public function job_delete( string $id ): bool {
		$had = isset( $this->jobs[ $id ] );
		unset( $this->jobs[ $id ] );
		return $had;
	}
	public function job_reassign( string $from, string $to ): int {
		$n = 0;
		foreach ( $this->jobs as $id => $j ) {
			if ( $j['owner_key'] === $from ) {
				$this->jobs[ $id ]['owner_key'] = $to;
				++$n;
			}
		}
		return $n;
	}
	public function job_stats( string $since ): array {
		$out = array( 'total' => 0, 'done' => 0, 'chosen' => 0, 'blocked' => 0, 'failed' => 0, 'refine' => 0 );
		foreach ( $this->jobs as $j ) {
			if ( $j['created_at'] < $since ) {
				continue;
			}
			++$out['total'];
			$out['done']    += 'done' === $j['status'] ? 1 : 0;
			$out['chosen']  += null !== $j['chosen_index'] ? 1 : 0;
			$out['blocked'] += 'blocked' === $j['status'] ? 1 : 0;
			$out['failed']  += 'failed' === $j['status'] ? 1 : 0;
			$out['refine']  += 'refine' === $j['kind'] ? 1 : 0;
		}
		return $out;
	}
	public function owner_get( string $key ): ?array {
		return $this->owners[ $key ] ?? null;
	}
	public function owner_upsert( array $row ): void {
		$k = $row['owner_key'];
		if ( ! isset( $this->owners[ $k ] ) ) {
			$now               = gmdate( 'Y-m-d H:i:s' );
			$this->owners[ $k ] = array_merge(
				array( 'kind' => 'anon', 'user_id' => null, 'email' => null, 'email_verified_at' => null, 'merged_into' => null, 'ip_hash' => null, 'anon_granted_at' => null, 'created_at' => $now, 'last_seen_at' => $now ),
				$row
			);
			return;
		}
		unset( $row['created_at'] );
		$this->owners[ $k ] = array_merge( $this->owners[ $k ], $row );
	}
	public function owner_find( string $email, int $user_id ): array {
		return array_values( array_filter( $this->owners, fn( $o ) => ( '' !== $email && $o['email'] === $email ) || ( $user_id > 0 && (int) $o['user_id'] === $user_id ) ) );
	}
	public function owner_children( string $key ): array {
		return array_keys( array_filter( $this->owners, fn( $o ) => $o['merged_into'] === $key ) );
	}
	public function owner_clear_ip( string $before ): int {
		$n = 0;
		foreach ( $this->owners as $k => $o ) {
			if ( null !== $o['ip_hash'] && $o['created_at'] < $before ) {
				$this->owners[ $k ]['ip_hash'] = null;
				++$n;
			}
		}
		return $n;
	}
	public function cost_insert( array $row ): bool {
		$this->costs[] = $row;
		return true;
	}
	public function cost_sum( string $from, string $to ): int {
		$s = 0;
		foreach ( $this->costs as $c ) {
			if ( $c['at'] >= $from && $c['at'] < $to ) {
				$s += (int) $c['cost_eur_micro'];
			}
		}
		return $s;
	}
	public function cost_rows( string $from, string $to, int $limit ): array {
		return array_slice( array_values( array_filter( $this->costs, fn( $c ) => $c['at'] >= $from && $c['at'] < $to ) ), 0, $limit );
	}
}

/**
 * Rounds backend with the duck-typed API of Services\Credits (balance/reserve/refund/grant_free_anon/merge/expire).
 */
final class Fake_Credits {
	public $free    = array();
	public $paid    = array();
	public $locked  = array();
	public $spent   = array(); // job_id => [owner, n, kind]
	public $refunds = array();
	public $anon    = array();
	public $merged  = array();

	public function balance( string $owner ): array {
		$f = (int) ( $this->free[ $owner ] ?? 0 );
		$p = (int) ( $this->paid[ $owner ] ?? 0 );
		return array( 'free' => $f, 'paid' => $p, 'paidLocked' => (int) ( $this->locked[ $owner ] ?? 0 ), 'total' => $f + $p, 'nextExpiry' => null, 'lockedUntil' => null );
	}
	public function reserve( string $owner, int $n, string $job_id, string $reason = 'generate' ): ?int {
		if ( ( $this->free[ $owner ] ?? 0 ) >= $n ) {
			$this->free[ $owner ] -= $n;
			$this->spent[ $job_id ] = array( $owner, $n, 'free' );
			return 11;
		}
		if ( ( $this->paid[ $owner ] ?? 0 ) >= $n ) {
			$this->paid[ $owner ] -= $n;
			$this->spent[ $job_id ] = array( $owner, $n, 'paid' );
			return 22;
		}
		return null;
	}
	public function refund( string $job_id, string $reason = 'job_failed' ): bool {
		if ( isset( $this->refunds[ $job_id ] ) || ! isset( $this->spent[ $job_id ] ) ) {
			return false;
		}
		list( $owner, $n, $kind ) = $this->spent[ $job_id ];
		if ( 'free' === $kind ) {
			$this->free[ $owner ] = ( $this->free[ $owner ] ?? 0 ) + $n;
		} else {
			$this->paid[ $owner ] = ( $this->paid[ $owner ] ?? 0 ) + $n;
		}
		$this->refunds[ $job_id ] = $reason;
		return true;
	}
	public function grant_free_anon( string $owner, string $ip_hash = '' ): int {
		if ( isset( $this->anon[ $owner ] ) ) {
			return 0;
		}
		$this->anon[ $owner ]  = $ip_hash;
		$this->free[ $owner ] = ( $this->free[ $owner ] ?? 0 ) + 1;
		return 1;
	}
	public function had_free( string $owner, string $reason ): bool {
		return false;
	}
	public function merge( string $from, string $to ): int {
		$this->merged[] = array( $from, $to );
		$this->free[ $to ] = ( $this->free[ $to ] ?? 0 ) + ( $this->free[ $from ] ?? 0 );
		$this->free[ $from ] = 0;
		return 1;
	}
	public function expire(): int {
		return 0;
	}
	public function export_owner( string $owner ): array {
		return array( array( array( 'name' => 'Rondes', 'value' => (string) ( $this->free[ $owner ] ?? 0 ) ) ) );
	}
	public function erase_owner( string $owner ): array {
		return array( 'removed' => true, 'retained' => false );
	}
}

/**
 * Provider whose behaviour is a closure (for fallback/policy/error tests).
 */
final class Script_Provider implements Provider {
	public $calls = 0;
	public $edits = 0;
	public $last  = null;
	private $id;
	private $fn;
	private $edit;
	public function __construct( string $id, callable $fn, bool $edit = true ) {
		$this->id   = $id;
		$this->fn   = $fn;
		$this->edit = $edit;
	}
	public function id(): string {
		return $this->id;
	}
	public function label(): string {
		return ucfirst( $this->id );
	}
	public function is_configured(): bool {
		return true;
	}
	public function capabilities(): array {
		return array( 'transparent_bg' => true, 'vector_output' => false, 'edit' => $this->edit, 'edit_mask' => false, 'text_quality' => 3, 'max_n' => 4 );
	}
	public function generate( array $req ): Provider_Result {
		++$this->calls;
		$this->last = $req;
		return ( $this->fn )( $req );
	}
	public function edit( array $req ): Provider_Result {
		++$this->edits;
		$this->last = $req;
		return ( $this->fn )( $req );
	}
	public function vectorize( string $image_path ): Provider_Result {
		throw new Provider_Error( 'unsupported' );
	}
	public function estimate_usd( string $op, array $params ): float {
		return 0.05 * max( 1, (int) ( $params['n'] ?? 1 ) );
	}
}

/**
 * A valid 4x4 RGBA PNG.
 */
function bps_png(): string {
	$raw = '';
	for ( $y = 0; $y < 4; $y++ ) {
		$raw .= "\0" . str_repeat( "\xff\x00\x00\xff", 4 );
	}
	$chunk = static function ( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	};
	return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', 4, 4, 8, 6, 0, 0, 0 ) ) . $chunk( 'IDAT', gzcompress( $raw ) ) . $chunk( 'IEND', '' );
}
