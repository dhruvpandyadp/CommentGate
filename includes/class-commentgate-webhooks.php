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

	/**
	 * Constructor.
	 *
	 * @param CommentGate_Stripe_Gateway $stripe Stripe gateway instance.
	 * @param CommentGate_PayPal_Gateway $paypal PayPal gateway instance.
	 */
	public function __construct( $stripe, $paypal ) {
		$this->stripe = $stripe;
		$this->paypal = $paypal;
	}

	/**
	 * Hook the REST route registration into rest_api_init.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the public Stripe and PayPal webhook REST routes. Both endpoints
	 * are open to unauthenticated requests since gateway signature verification
	 * happens inside each handler.
	 */
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
