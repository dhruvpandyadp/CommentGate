<?php
/**
 * WP-CLI commands.
 *
 * @package CommentGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

class CommentGate_CLI {
	private $settings;
	private $payments;
	private $stripe;
	private $paypal;

	public function __construct( $settings, $payments, $stripe, $paypal ) {
		$this->settings = $settings;
		$this->payments = $payments;
		$this->stripe   = $stripe;
		$this->paypal   = $paypal;
	}

	/**
	 * Show CommentGate configuration status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. table, json, csv, yaml.
	 *
	 * ## EXAMPLES
	 *
	 *     wp commentgate status
	 *     wp commentgate status --format=json
	 */
	public function status( $args, $assoc_args ) {
		$options = $this->settings->get_all();
		$rows    = array(
			array( 'setting' => 'enabled', 'value' => '1' === $options['enabled'] ? 'yes' : 'no' ),
			array( 'setting' => 'price', 'value' => $options['currency'] . ' ' . $options['price'] ),
			array( 'setting' => 'gateway', 'value' => implode( ',', (array) $options['gateways'] ) ),
			array( 'setting' => 'access_type', 'value' => $options['access_type'] ),
			array( 'setting' => 'access_duration_minutes', 'value' => (string) absint( $options['access_duration'] ) ),
			array( 'setting' => 'comments_per_purchase', 'value' => (string) absint( $options['comment_quantity'] ) ),
			array( 'setting' => 'customer_email_format', 'value' => $options['email_format'] ),
			array( 'setting' => 'post_types', 'value' => implode( ',', (array) $options['post_types'] ) ),
			array( 'setting' => 'stripe_configured', 'value' => ( ! empty( $options['stripe_secret'] ) && ! empty( $options['stripe_webhook_secret'] ) ) ? 'yes' : 'no' ),
			array( 'setting' => 'paypal_configured', 'value' => ( ! empty( $options['paypal_client_id'] ) && ! empty( $options['paypal_secret'] ) && ! empty( $options['paypal_webhook_id'] ) ) ? 'yes' : 'no' ),
			array( 'setting' => 'stripe_webhook_url', 'value' => rest_url( 'commentgate/v1/stripe-webhook' ) ),
			array( 'setting' => 'paypal_webhook_url', 'value' => rest_url( 'commentgate/v1/paypal-webhook' ) ),
		);

		$this->format_items( $assoc_args['format'] ?? 'table', $rows, array( 'setting', 'value' ) );
	}

	/**
	 * Show CommentGate settings.
	 *
	 * ## OPTIONS
	 *
	 * [--show-secrets]
	 * : Show API secret values. Secrets are masked by default.
	 *
	 * [--format=<format>]
	 * : Output format. table, json, csv, yaml.
	 *
	 * ## EXAMPLES
	 *
	 *     wp commentgate settings
	 *     wp commentgate settings --show-secrets --format=json
	 */
	public function settings( $args, $assoc_args ) {
		$options      = $this->settings->get_all();
		$show_secrets = ! empty( $assoc_args['show-secrets'] );
		$secret_keys  = array( 'stripe_secret', 'stripe_webhook_secret', 'paypal_secret' );
		$rows         = array();

		foreach ( $options as $key => $value ) {
			if ( in_array( $key, $secret_keys, true ) && ! $show_secrets ) {
				$value = empty( $value ) ? '' : '********';
			}

			if ( is_array( $value ) ) {
				$value = implode( ',', $value );
			}

			$rows[] = array(
				'key'   => $key,
				'value' => (string) $value,
			);
		}

		$this->format_items( $assoc_args['format'] ?? 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * List payment records.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by pending, paid, or refunded.
	 *
	 * [--gateway=<gateway>]
	 * : Filter by stripe or paypal.
	 *
	 * [--limit=<limit>]
	 * : Number of records. Default 100.
	 *
	 * [--offset=<offset>]
	 * : Offset. Default 0.
	 *
	 * [--format=<format>]
	 * : Output format. table, json, csv, yaml, count.
	 *
	 * ## EXAMPLES
	 *
	 *     wp commentgate payments --status=paid
	 *     wp commentgate payments --gateway=stripe --format=csv
	 */
	public function payments( $args, $assoc_args ) {
		global $wpdb;

		$table  = CommentGate_Payments_Table::table_name();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $assoc_args['status'] ) ) {
			$status = sanitize_key( $assoc_args['status'] );
			if ( ! in_array( $status, array( 'pending', 'paid', 'refunded' ), true ) ) {
				\WP_CLI::error( 'Invalid status. Use pending, paid, or refunded.' );
			}
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( ! empty( $assoc_args['gateway'] ) ) {
			$gateway = sanitize_key( $assoc_args['gateway'] );
			if ( ! in_array( $gateway, array( 'stripe', 'paypal' ), true ) ) {
				\WP_CLI::error( 'Invalid gateway. Use stripe or paypal.' );
			}
			$where[]  = 'gateway = %s';
			$params[] = $gateway;
		}

		$limit    = max( 1, min( 500, absint( $assoc_args['limit'] ?? 100 ) ) );
		$offset   = max( 0, absint( $assoc_args['offset'] ?? 0 ) );
		$params[] = $limit;
		$params[] = $offset;
		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		$items    = array_map( array( $this, 'payment_to_array' ), (array) $rows );

		$this->format_items(
			$assoc_args['format'] ?? 'table',
			$items,
			array( 'id', 'post_id', 'gateway', 'amount', 'currency', 'status', 'access_type', 'comment_limit', 'comments_remaining', 'created_at', 'paid_at', 'refunded_at' )
		);
	}

	/**
	 * Show one payment record.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Payment ID.
	 *
	 * [--format=<format>]
	 * : Output format. table, json, csv, yaml.
	 *
	 * ## EXAMPLES
	 *
	 *     wp commentgate payment 123
	 */
	public function payment( $args, $assoc_args ) {
		$payment = $this->payments->find_by_id( absint( $args[0] ?? 0 ) );
		if ( ! $payment ) {
			\WP_CLI::error( 'Payment not found.' );
		}

		$this->format_items( $assoc_args['format'] ?? 'table', array( $this->payment_to_array( $payment ) ), array_keys( $this->payment_to_array( $payment ) ) );
	}

	/**
	 * Refund unused paid access.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Payment ID.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp commentgate refund 123 --yes
	 */
	public function refund( $args, $assoc_args ) {
		$payment = $this->payments->find_by_id( absint( $args[0] ?? 0 ) );
		if ( ! $payment ) {
			\WP_CLI::error( 'Payment not found.' );
		}

		if ( ! $this->payments->is_unused_refundable( $payment ) ) {
			\WP_CLI::error( 'Payment is not refundable. Only unused paid access can be refunded.' );
		}

		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( 'Refund payment #' . absint( $payment->id ) . '?' );
		}

		if ( 'stripe' === $payment->gateway ) {
			$result = $this->stripe->refund_payment( $payment );
		} elseif ( 'paypal' === $payment->gateway ) {
			$result = $this->paypal->refund_payment( $payment );
		} else {
			\WP_CLI::error( 'Unsupported gateway.' );
		}

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		$refund_id = is_string( $result ) ? $result : '';
		$updated   = $this->payments->mark_refunded( $payment->id, $refund_id, 'cli_refund' );
		if ( ! $updated ) {
			\WP_CLI::error( 'Refund was created, but local payment record could not be updated.' );
		}

		\WP_CLI::success( 'Payment refunded.' . ( $refund_id ? ' Refund ID: ' . $refund_id : '' ) );
	}

	private function payment_to_array( $payment ) {
		return array(
			'id'                 => absint( $payment->id ),
			'user_id'            => absint( $payment->user_id ),
			'guest_email'        => (string) $payment->guest_email,
			'post_id'            => absint( $payment->post_id ),
			'gateway'            => (string) $payment->gateway,
			'gateway_payment_id' => (string) $payment->gateway_payment_id,
			'gateway_capture_id' => (string) $payment->gateway_capture_id,
			'amount'             => (string) $payment->amount,
			'currency'           => (string) $payment->currency,
			'status'             => (string) $payment->status,
			'comment_id'         => absint( $payment->comment_id ),
			'access_type'        => (string) $payment->access_type,
			'comment_limit'      => absint( $payment->comment_limit ),
			'comments_remaining' => absint( $payment->comments_remaining ),
			'refund_id'          => (string) $payment->refund_id,
			'refund_reason'      => (string) $payment->refund_reason,
			'created_at'         => (string) $payment->created_at,
			'paid_at'            => (string) $payment->paid_at,
			'refunded_at'        => (string) $payment->refunded_at,
			'expires_at'         => (string) $payment->expires_at,
		);
	}

	private function format_items( $format, $items, $fields ) {
		$format = sanitize_key( $format );
		if ( ! in_array( $format, array( 'table', 'json', 'csv', 'yaml', 'count' ), true ) ) {
			\WP_CLI::error( 'Invalid format.' );
		}

		if ( empty( $items ) && 'count' === $format ) {
			\WP_CLI::line( '0' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}
}
