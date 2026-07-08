<?php
/**
 * PayPal Checkout integration.
 *
 * @package CommentGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CommentGate_PayPal_Gateway {
	private $settings;
	private $payments;

	public function __construct( $settings, $payments ) {
		$this->settings = $settings;
		$this->payments = $payments;
	}

	public function redirect_to_checkout( $post_id, $price, $email = '', $access_type = 'duration', $comment_quantity = 1 ) {
		if ( ! $this->settings->get( 'paypal_client_id' ) || ! $this->settings->get( 'paypal_secret' ) ) {
			wp_die( esc_html__( 'PayPal is not configured.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 400 ) );
		}

		$pending = $this->payments->create_pending(
			array(
				'post_id'     => $post_id,
				'gateway'     => 'paypal',
				'guest_email' => $email,
				'amount'      => $price,
				'currency'    => $this->settings->get( 'currency' ),
				'access_type' => 'comments' === $access_type ? 'comments' : 'duration',
				'comment_limit' => 'comments' === $access_type ? max( 1, absint( $comment_quantity ) ) : 0,
			)
		);

		$return_url = add_query_arg(
			array(
				'action'     => 'commentgate_paypal_return',
				'post_id'    => $post_id,
				'payment_id' => $pending['id'],
				'access'     => $pending['token'],
			),
			admin_url( 'admin-post.php' )
		);

		$response = wp_remote_post(
			$this->api_base() . '/v2/checkout/orders',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'intent'              => 'CAPTURE',
						'purchase_units'      => array(
							array(
								'reference_id' => (string) $pending['id'],
								'amount'       => array(
									'currency_code' => $this->settings->get( 'currency' ),
									'value'         => number_format( (float) $price, 2, '.', '' ),
								),
								'description'  => sprintf(
									/* translators: %s: post title */
									'comments' === $access_type ? __( 'Comment credits for %s', 'commentgate' ) : __( 'Comment access for %s', 'commentgate' ),
									get_the_title( $post_id )
								),
							),
						),
						'application_context' => array(
							'return_url' => $return_url,
							'cancel_url' => add_query_arg( 'commentgate', 'failed', get_permalink( $post_id ) ),
						),
					)
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( $response->get_error_message() ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 500 ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['id'] ) ) {
			wp_die( esc_html__( 'PayPal order could not be created.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 500 ) );
		}

		$this->payments->update_gateway_payment_id( $pending['id'], $data['id'] );

		foreach ( (array) ( $data['links'] ?? array() ) as $link ) {
			if ( 'approve' === ( $link['rel'] ?? '' ) && ! empty( $link['href'] ) ) {
				$this->safe_gateway_redirect( esc_url_raw( $link['href'] ) );
				exit;
			}
		}

		wp_die( esc_html__( 'PayPal approval URL missing.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 500 ) );
	}

	private function safe_gateway_redirect( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			wp_die( esc_html__( 'PayPal approval URL is invalid.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 500 ) );
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

	public function capture_order( $payment_id, $order_id, $access_token ) {
		$payment = $this->payments->find_by_id( $payment_id );
		if (
			! $payment ||
			'paypal' !== $payment->gateway ||
			$order_id !== $payment->gateway_payment_id ||
			! $this->payments->payment_token_matches( $payment_id, $access_token )
		) {
			wp_die( esc_html__( 'Invalid PayPal return.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 403 ) );
		}

		$response = wp_remote_post(
			$this->api_base() . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token(),
					'Content-Type'  => 'application/json',
				),
				'body'    => '{}',
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( $response->get_error_message() ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 500 ) );
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$status = $data['status'] ?? '';

		if ( 'COMPLETED' !== $status ) {
			wp_die( esc_html__( 'PayPal payment was not completed.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 402 ) );
		}

		$capture_id = $data['purchase_units'][0]['payments']['captures'][0]['id'] ?? '';
		if ( $capture_id ) {
			$this->payments->update_gateway_capture_id( $payment_id, sanitize_text_field( $capture_id ) );
		}

		$this->payments->mark_paid( $payment_id, absint( $this->settings->get( 'access_duration' ) ) );
		$this->set_access_cookie( $access_token );
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$body = $request->get_body();

		if ( ! $this->verify_webhook( $request, $body ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 400 );
		}

		$event = json_decode( $body, true );
		$type  = $event['event_type'] ?? '';

		if ( 'PAYMENT.CAPTURE.REFUNDED' === $type ) {
			$resource = $event['resource'] ?? array();
			if ( ! empty( $resource['supplementary_data']['related_ids']['order_id'] ) ) {
				$this->payments->mark_refunded_by_gateway_id( 'paypal', sanitize_text_field( $resource['supplementary_data']['related_ids']['order_id'] ), sanitize_text_field( $resource['id'] ?? '' ), sanitize_text_field( $type ) );
			}
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public function refund_payment( $payment ) {
		if ( ! $this->settings->get( 'paypal_client_id' ) || ! $this->settings->get( 'paypal_secret' ) ) {
			return new WP_Error( 'commentgate_paypal_not_configured', __( 'PayPal is not configured.', 'commentgate' ) );
		}

		if ( empty( $payment->gateway_capture_id ) ) {
			return new WP_Error( 'commentgate_paypal_missing_capture', __( 'PayPal capture ID is missing. Refund this payment from PayPal, then let the webhook update CommentGate.', 'commentgate' ) );
		}

		$response = wp_remote_post(
			$this->api_base() . '/v2/payments/captures/' . rawurlencode( $payment->gateway_capture_id ) . '/refund',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token(),
					'Content-Type'  => 'application/json',
				),
				'body'    => '{}',
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $data['id'] ) ) {
			return new WP_Error( 'commentgate_paypal_refund_failed', __( 'PayPal refund could not be created.', 'commentgate' ) );
		}

		return sanitize_text_field( $data['id'] );
	}

	private function verify_webhook( WP_REST_Request $request, $body ) {
		$webhook_id = $this->settings->get( 'paypal_webhook_id' );
		if ( empty( $webhook_id ) ) {
			return false;
		}

		$response = wp_remote_post(
			$this->api_base() . '/v1/notifications/verify-webhook-signature',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'auth_algo'         => $request->get_header( 'paypal-auth-algo' ),
						'cert_url'          => $request->get_header( 'paypal-cert-url' ),
						'transmission_id'   => $request->get_header( 'paypal-transmission-id' ),
						'transmission_sig'  => $request->get_header( 'paypal-transmission-sig' ),
						'transmission_time' => $request->get_header( 'paypal-transmission-time' ),
						'webhook_id'        => $webhook_id,
						'webhook_event'     => json_decode( $body, true ),
					)
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $data['verification_status'] ) && 'SUCCESS' === $data['verification_status'];
	}

	private function access_token() {
		$response = wp_remote_post(
			$this->api_base() . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->settings->get( 'paypal_client_id' ) . ':' . $this->settings->get( 'paypal_secret' ) ),
				),
				'body'    => array( 'grant_type' => 'client_credentials' ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( $response->get_error_message() ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 500 ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			wp_die( esc_html__( 'PayPal access token could not be created.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 500 ) );
		}

		return $data['access_token'];
	}

	private function api_base() {
		return 'live' === $this->settings->get( 'paypal_mode' ) ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
	}

	private function set_access_cookie( $token ) {
		setcookie(
			'commentgate_access',
			$token,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE['commentgate_access'] = $token;
	}
}
