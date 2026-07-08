<?php
/**
 * Stripe Checkout integration.
 *
 * @package CommentGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CommentGate_Stripe_Gateway {
	private $settings;
	private $payments;

	public function __construct( $settings, $payments ) {
		$this->settings = $settings;
		$this->payments = $payments;
	}

	public function redirect_to_checkout( $post_id, $price, $email = '', $access_type = 'duration', $comment_quantity = 1 ) {
		$secret = $this->settings->get( 'stripe_secret' );
		if ( empty( $secret ) ) {
			wp_die( esc_html__( 'Stripe is not configured.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 400 ) );
		}

		$pending = $this->payments->create_pending(
			array(
				'post_id'     => $post_id,
				'gateway'     => 'stripe',
				'guest_email' => $email,
				'amount'      => $price,
				'currency'    => $this->settings->get( 'currency' ),
				'access_type' => 'comments' === $access_type ? 'comments' : 'duration',
				'comment_limit' => 'comments' === $access_type ? max( 1, absint( $comment_quantity ) ) : 0,
			)
		);

		$success_url = add_query_arg(
			array(
				'action'  => 'commentgate_stripe_return',
				'post_id' => $post_id,
				'access'  => $pending['token'],
			),
			admin_url( 'admin-post.php' )
		);
		$cancel_url  = add_query_arg( 'commentgate', 'failed', get_permalink( $post_id ) );

		$body = array(
			'mode'                                  => 'payment',
			'success_url'                           => $success_url,
			'cancel_url'                            => $cancel_url,
			'client_reference_id'                   => (string) $pending['id'],
			'metadata[payment_id]'                  => (string) $pending['id'],
			'metadata[post_id]'                     => (string) $post_id,
			'metadata[access_type]'                 => 'comments' === $access_type ? 'comments' : 'duration',
			'metadata[comment_quantity]'            => (string) max( 1, absint( $comment_quantity ) ),
			'line_items[0][quantity]'               => 1,
			'line_items[0][price_data][currency]'   => strtolower( $this->settings->get( 'currency' ) ),
			'line_items[0][price_data][unit_amount]' => (string) round( (float) $price * 100 ),
			'line_items[0][price_data][product_data][name]' => sprintf(
				/* translators: %s: post title */
				'comments' === $access_type ? __( 'Comment credits for %s', 'commentgate' ) : __( 'Comment access for %s', 'commentgate' ),
				get_the_title( $post_id )
			),
		);

		if ( $email ) {
			$body['customer_email'] = $email;
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/checkout/sessions',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
				'body'    => $body,
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( $response->get_error_message() ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 500 ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['url'] ) ) {
			wp_die( esc_html__( 'Stripe checkout could not be created.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 500 ) );
		}

		$this->payments->update_gateway_payment_id( $pending['id'], $data['id'] ?? '' );

		$this->safe_gateway_redirect( esc_url_raw( $data['url'] ) );
		exit;
	}

	private function safe_gateway_redirect( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			wp_die( esc_html__( 'Stripe checkout URL is invalid.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 500 ) );
		}

		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) use ( $host ) {
				$hosts[] = $host;
				return array_unique( $hosts );
			}
		);

		wp_safe_redirect( $url );
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$payload    = $request->get_body();
		$event      = json_decode( $payload, true );
		$secret     = $this->settings->get( 'stripe_webhook_secret' );
		$signature  = $request->get_header( 'stripe-signature' );

		if ( empty( $secret ) || ! $this->verify_signature( $payload, $signature, $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 400 );
		}

		if ( empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid event' ), 400 );
		}

		if ( 'checkout.session.completed' === $event['type'] ) {
			$session    = $event['data']['object'] ?? array();
			$payment_id = absint( $session['metadata']['payment_id'] ?? $session['client_reference_id'] ?? 0 );

			if ( $payment_id ) {
				$payment    = $this->payments->find_by_id( $payment_id );
				$session_id = sanitize_text_field( $session['id'] ?? '' );

				if ( $payment && 'stripe' === $payment->gateway && ( '' === $payment->gateway_payment_id || $session_id === $payment->gateway_payment_id ) ) {
					$this->payments->update_gateway_payment_id( $payment_id, sanitize_text_field( $session['payment_intent'] ?? $session_id ) );
					$this->payments->mark_paid( $payment_id, absint( $this->settings->get( 'access_duration' ) ) );
				}
			}
		}

		if ( in_array( $event['type'], array( 'charge.refunded', 'checkout.session.async_payment_failed' ), true ) ) {
			$object = $event['data']['object'] ?? array();
			if ( ! empty( $object['payment_intent'] ) ) {
				$this->payments->mark_refunded_by_gateway_id( 'stripe', sanitize_text_field( $object['payment_intent'] ), sanitize_text_field( $object['id'] ?? '' ), sanitize_text_field( $event['type'] ) );
			}
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public function refund_payment( $payment ) {
		$secret = $this->settings->get( 'stripe_secret' );
		if ( empty( $secret ) ) {
			return new WP_Error( 'commentgate_stripe_not_configured', __( 'Stripe is not configured.', 'commentgate' ) );
		}

		if ( empty( $payment->gateway_payment_id ) ) {
			return new WP_Error( 'commentgate_stripe_missing_payment', __( 'Stripe payment ID is missing.', 'commentgate' ) );
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/refunds',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
				'body'    => array( 'payment_intent' => $payment->gateway_payment_id ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $data['id'] ) ) {
			return new WP_Error( 'commentgate_stripe_refund_failed', __( 'Stripe refund could not be created.', 'commentgate' ) );
		}

		return sanitize_text_field( $data['id'] );
	}

	private function verify_signature( $payload, $signature, $secret ) {
		if ( ! $signature ) {
			return false;
		}

		$parts = array();
		foreach ( explode( ',', $signature ) as $piece ) {
			$pair = explode( '=', $piece, 2 );
			if ( 2 === count( $pair ) ) {
				$parts[ $pair[0] ] = $pair[1];
			}
		}

		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			return false;
		}

		$signed_payload = $parts['t'] . '.' . $payload;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		return hash_equals( $expected, $parts['v1'] );
	}
}
