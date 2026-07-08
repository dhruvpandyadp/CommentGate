<?php
/**
 * Payment persistence.
 *
 * @package CommentGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

class CommentGate_Payments_Table {
	public static function table_name() {
		global $wpdb;
		return esc_sql( $wpdb->prefix . 'commentgate_payments' );
	}

	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			guest_email varchar(190) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL,
			gateway varchar(30) NOT NULL,
			gateway_payment_id varchar(190) NOT NULL DEFAULT '',
			gateway_capture_id varchar(190) NOT NULL DEFAULT '',
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			currency varchar(3) NOT NULL DEFAULT 'USD',
			status varchar(30) NOT NULL DEFAULT 'pending',
			comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			access_type varchar(20) NOT NULL DEFAULT 'duration',
			comment_limit int(10) unsigned NOT NULL DEFAULT 0,
			comments_remaining int(10) unsigned NOT NULL DEFAULT 0,
			refund_id varchar(190) NOT NULL DEFAULT '',
			refund_reason varchar(255) NOT NULL DEFAULT '',
			access_token_hash varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			paid_at datetime NULL,
			refunded_at datetime NULL,
			expires_at datetime NULL,
			PRIMARY KEY  (id),
			KEY post_status (post_id,status),
			KEY user_post (user_id,post_id),
			KEY token_hash (access_token_hash(191)),
			KEY gateway_payment (gateway,gateway_payment_id),
			KEY gateway_capture (gateway,gateway_capture_id)
		) {$charset_collate};";

		dbDelta( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function maybe_upgrade() {
		$installed_version = get_option( 'commentgate_db_version', '' );

		if ( COMMENTGATE_VERSION !== $installed_version ) {
			self::activate();
			update_option( 'commentgate_db_version', COMMENTGATE_VERSION );
		}
	}

	public function create_pending( $args ) {
		global $wpdb;

		$token = wp_generate_password( 32, false, false );
		$data  = wp_parse_args(
			$args,
			array(
				'user_id'            => get_current_user_id(),
				'guest_email'        => '',
				'post_id'            => 0,
				'gateway'            => '',
				'gateway_payment_id' => '',
				'gateway_capture_id' => '',
				'amount'             => 0,
				'currency'           => 'USD',
				'status'             => 'pending',
				'access_type'        => 'duration',
				'comment_limit'      => 0,
				'comments_remaining' => 0,
				'refund_id'          => '',
				'refund_reason'      => '',
				'access_token_hash'  => wp_hash_password( $token ),
				'created_at'         => current_time( 'mysql' ),
			)
		);

		$table = self::table_name();
		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return array(
			'id'    => (int) $wpdb->insert_id,
			'token' => $token,
		);
	}

	public function update_gateway_payment_id( $id, $gateway_payment_id ) {
		global $wpdb;

		return $wpdb->update(
			self::table_name(), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			array( 'gateway_payment_id' => sanitize_text_field( $gateway_payment_id ) ),
			array( 'id' => absint( $id ) )
		);
	}

	public function update_gateway_capture_id( $id, $gateway_capture_id ) {
		global $wpdb;

		return $wpdb->update(
			self::table_name(), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			array( 'gateway_capture_id' => sanitize_text_field( $gateway_capture_id ) ),
			array( 'id' => absint( $id ) )
		);
	}

	public function mark_paid( $id, $duration_minutes = 0 ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1',
				absint( $id )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row || 'paid' === $row->status ) {
			return false;
		}

		$expires_at = null;
		if ( ( ! $row || 'comments' !== $row->access_type ) && $duration_minutes > 0 ) {
			$expires_at = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() + ( MINUTE_IN_SECONDS * absint( $duration_minutes ) ) ) );
		}

		$data = array(
			'status'     => 'paid',
			'paid_at'    => current_time( 'mysql' ),
			'expires_at' => $expires_at,
		);

		if ( $row && 'comments' === $row->access_type ) {
			$data['comments_remaining'] = max( 1, absint( $row->comment_limit ) );
		}

		$updated = $wpdb->update( self::table_name(), $data, array( 'id' => absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false !== $updated ) {
			do_action( 'commentgate_payment_paid', absint( $id ) );
		}

		return $updated;
	}

	public function update_status( $ids, $status ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( empty( $ids ) ) {
			return false;
		}

		$status = sanitize_key( $status );
		if ( ! in_array( $status, array( 'pending', 'paid', 'refunded' ), true ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $status ), $ids );

		if ( 'paid' === $status ) {
			$sql = 'UPDATE ' . self::table_name() . " SET status = %s, paid_at = IF(paid_at IS NULL, %s, paid_at) WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_splice( $params, 1, 0, current_time( 'mysql' ) );
		} else {
			$sql = 'UPDATE ' . self::table_name() . " SET status = %s WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function delete( $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( empty( $ids ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table_name() . " WHERE id IN ({$placeholders})",
				$ids
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function mark_refunded_by_gateway_id( $gateway, $gateway_payment_id, $refund_id = '', $reason = '' ) {
		global $wpdb;

		$updated = $wpdb->update(
			self::table_name(), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			array(
				'status'        => 'refunded',
				'refund_id'     => sanitize_text_field( $refund_id ),
				'refund_reason' => sanitize_text_field( $reason ),
				'refunded_at'   => current_time( 'mysql' ),
			),
			array(
				'gateway'            => sanitize_key( $gateway ),
				'gateway_payment_id' => sanitize_text_field( $gateway_payment_id ),
				'status'             => 'paid',
			)
		);
		if ( $updated ) {
			$payment = $this->find_by_gateway_payment_id( $gateway, $gateway_payment_id );
			if ( $payment ) {
				do_action( 'commentgate_payment_refunded', absint( $payment->id ) );
			}
		}

		return $updated;
	}

	public function mark_refunded( $id, $refund_id = '', $reason = '' ) {
		global $wpdb;

		$updated = $wpdb->update(
			self::table_name(), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			array(
				'status'        => 'refunded',
				'refund_id'     => sanitize_text_field( $refund_id ),
				'refund_reason' => sanitize_text_field( $reason ),
				'refunded_at'   => current_time( 'mysql' ),
			),
			array(
				'id'     => absint( $id ),
				'status' => 'paid',
			)
		);
		if ( $updated ) {
			do_action( 'commentgate_payment_refunded', absint( $id ) );
		}

		return $updated;
	}

	public function is_unused_refundable( $payment ) {
		if ( ! $payment || 'paid' !== $payment->status ) {
			return false;
		}

		if ( 'comments' === $payment->access_type ) {
			return absint( $payment->comment_limit ) > 0 && absint( $payment->comments_remaining ) >= absint( $payment->comment_limit );
		}

		return 0 === absint( $payment->comment_id );
	}

	public function find_by_gateway_payment_id( $gateway, $gateway_payment_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE gateway = %s AND gateway_payment_id = %s LIMIT 1',
				sanitize_key( $gateway ),
				sanitize_text_field( $gateway_payment_id )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function find_by_id( $id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1',
				absint( $id )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function payment_token_matches( $id, $token ) {
		$payment = $this->find_by_id( $id );

		if ( ! $payment || '' === $token || empty( $payment->access_token_hash ) ) {
			return false;
		}

		return wp_check_password( $token, $payment->access_token_hash );
	}

	public function signed_access_value( $payment ) {
		if ( ! $payment || empty( $payment->access_token_hash ) ) {
			return '';
		}

		$id  = absint( $payment->id );
		$sig = hash_hmac(
			'sha256',
			$id . '|' . absint( $payment->post_id ) . '|' . $payment->access_token_hash,
			wp_salt( 'auth' )
		);

		return $id . ':' . $sig;
	}

	public function signed_access_matches( $payment, $value ) {
		if ( ! $payment || '' === $value ) {
			return false;
		}

		return hash_equals( $this->signed_access_value( $payment ), sanitize_text_field( $value ) );
	}

	public function find_valid_signed_access( $post_id, $value ) {
		$parts = explode( ':', sanitize_text_field( $value ), 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$payment = $this->find_by_id( absint( $parts[0] ) );
		if ( ! $payment || absint( $payment->post_id ) !== absint( $post_id ) || 'paid' !== $payment->status ) {
			return null;
		}

		$now = current_time( 'mysql' );
		if ( 'comments' === $payment->access_type && absint( $payment->comments_remaining ) < 1 ) {
			return null;
		}

		if ( 'comments' !== $payment->access_type && ! empty( $payment->expires_at ) && $payment->expires_at <= $now ) {
			return null;
		}

		return $this->signed_access_matches( $payment, $value ) ? $payment : null;
	}

	public function user_has_access( $post_id, $token = '' ) {
		global $wpdb;

		$user_id = get_current_user_id();
		$now     = current_time( 'mysql' );
		$table   = self::table_name();

		if ( $user_id ) {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE post_id = %d AND user_id = %d AND status = 'paid' AND ((access_type = 'comments' AND comments_remaining > 0) OR (access_type <> 'comments' AND (expires_at IS NULL OR expires_at > %s))) LIMIT 1",
					absint( $post_id ),
					$user_id,
					$now
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $found ) {
				return true;
			}
		}

		$signed_access = isset( $_COOKIE['commentgate_access_payment'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['commentgate_access_payment'] ) ) : '';
		if ( $signed_access && $this->find_valid_signed_access( $post_id, $signed_access ) ) {
			return true;
		}

		if ( '' === $token ) {
			$token = isset( $_COOKIE['commentgate_access'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['commentgate_access'] ) ) : '';
		}

		if ( '' === $token ) {
			return false;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT access_token_hash FROM {$table} WHERE post_id = %d AND status = 'paid' AND ((access_type = 'comments' AND comments_remaining > 0) OR (access_type <> 'comments' AND (expires_at IS NULL OR expires_at > %s)))",
				absint( $post_id ),
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $rows as $row ) {
			if ( wp_check_password( $token, $row->access_token_hash ) ) {
				return true;
			}
		}

		return false;
	}

	public function attach_comment( $post_id, $comment_id ) {
		global $wpdb;

		if ( ! get_current_user_id() ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . ' SET comment_id = %d WHERE post_id = %d AND user_id = %d AND status = %s AND comment_id = 0 ORDER BY id DESC LIMIT 1',
				absint( $comment_id ),
				absint( $post_id ),
				get_current_user_id(),
				'paid'
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function consume_comment_access( $post_id, $comment_id, $token = '' ) {
		global $wpdb;

		$user_id = get_current_user_id();
		$now     = current_time( 'mysql' );
		$table   = self::table_name();
		$row     = null;

		if ( $user_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE post_id = %d AND user_id = %d AND status = 'paid' AND ((access_type = 'comments' AND comments_remaining > 0) OR (access_type <> 'comments' AND (expires_at IS NULL OR expires_at > %s))) ORDER BY id DESC LIMIT 1",
					absint( $post_id ),
					$user_id,
					$now
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! $row ) {
			$signed_access = isset( $_COOKIE['commentgate_access_payment'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['commentgate_access_payment'] ) ) : '';
			if ( $signed_access ) {
				$row = $this->find_valid_signed_access( $post_id, $signed_access );
			}
		}

		if ( ! $row ) {
			if ( '' === $token ) {
				$token = isset( $_COOKIE['commentgate_access'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['commentgate_access'] ) ) : '';
			}

			if ( '' !== $token ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE post_id = %d AND status = 'paid' AND ((access_type = 'comments' AND comments_remaining > 0) OR (access_type <> 'comments' AND (expires_at IS NULL OR expires_at > %s))) ORDER BY id DESC",
						absint( $post_id ),
						$now
					)
				); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				foreach ( $rows as $candidate ) {
					if ( wp_check_password( $token, $candidate->access_token_hash ) ) {
						$row = $candidate;
						break;
					}
				}
			}
		}

		if ( ! $row ) {
			return false;
		}

		$data = array( 'comment_id' => absint( $comment_id ) );
		if ( 'comments' === $row->access_type ) {
			$data['comments_remaining'] = max( 0, absint( $row->comments_remaining ) - 1 );
		}

		return (bool) $wpdb->update( $table, $data, array( 'id' => absint( $row->id ) ) );
	}
}
