<?php
/**
 * REST webhook routes.
 *
 * @package CommentGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CommentGate_Webhooks {
	private $stripe;
	private $paypal;

	public function __construct( $stripe, $paypal ) {
		$this->stripe = $stripe;
		$this->paypal = $paypal;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'commentgate/v1',
			'/stripe-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->stripe, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'commentgate/v1',
			'/paypal-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->paypal, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}
}
