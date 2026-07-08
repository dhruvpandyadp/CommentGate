<?php
/**
 * Comment gating and checkout entry points.
 *
 * @package CommentGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CommentGate_Comment_Gate {
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

	public function register() {
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'comment_form_defaults', array( $this, 'filter_comment_form' ) );
		// Render-time hooks: these run on the final values AFTER a theme's own
		// comment_form() args are merged in, so themes such as GeneratePress,
		// OceanWP and Blocksy can no longer override the gate. comment_form_top
		// fires inside the <form>, which the payment box relies on for its
		// formaction submit trick.
		add_action( 'comment_form_top', array( $this, 'inject_payment_box' ) );
		add_filter( 'comment_form_fields', array( $this, 'gate_form_fields' ), 99 );
		add_filter( 'comment_form_field_comment', array( $this, 'gate_comment_field' ), 99 );
		add_filter( 'comment_form_submit_button', array( $this, 'gate_submit_button' ), 99 );
		add_filter( 'comment_form_submit_field', array( $this, 'gate_submit_field' ), 99 );
		add_filter( 'preprocess_comment', array( $this, 'block_unpaid_comment' ) );
		add_filter( 'pre_comment_approved', array( $this, 'auto_approve_paid_comment' ), 10, 2 );
		add_action( 'comment_post', array( $this, 'attach_payment_to_comment' ), 10, 2 );
		add_action( 'admin_post_commentgate_start_payment', array( $this, 'start_payment' ) );
		add_action( 'admin_post_nopriv_commentgate_start_payment', array( $this, 'start_payment' ) );
		add_action( 'admin_post_commentgate_stripe_return', array( $this, 'stripe_return' ) );
		add_action( 'admin_post_nopriv_commentgate_stripe_return', array( $this, 'stripe_return' ) );
		add_action( 'admin_post_commentgate_access_link', array( $this, 'access_link' ) );
		add_action( 'admin_post_nopriv_commentgate_access_link', array( $this, 'access_link' ) );
		add_action( 'admin_post_commentgate_paypal_return', array( $this, 'paypal_return' ) );
		add_action( 'admin_post_nopriv_commentgate_paypal_return', array( $this, 'paypal_return' ) );
	}

	public function body_class( $classes ) {
		$post = get_post();

		if ( $post && $this->requires_payment( $post->ID ) && ! $this->has_access( $post->ID ) ) {
			$classes[] = 'commentgate-comments-locked';
		}

		return $classes;
	}

	public function filter_comment_form( $defaults ) {
		if ( ! $this->is_locked_current() ) {
			return $defaults;
		}

		// First line of defense for themes that respect the defaults. Themes
		// that pass their own comment_form() args override these, which is why
		// the render-time filters below also empty the fields.
		$defaults['title_reply']   = __( 'Pay to comment', 'commentgate' );
		$defaults['comment_field'] = '';
		$defaults['fields']        = array();
		$defaults['submit_button'] = '';
		$defaults['submit_field']  = '';
		$defaults['class_form']    = trim( ( $defaults['class_form'] ?? 'comment-form' ) . ' commentgate-locked-form' );

		return $defaults;
	}

	/**
	 * Print the payment box inside the comment <form> (comment_form_top action).
	 */
	public function inject_payment_box() {
		if ( ! $this->is_locked_current() ) {
			return;
		}

		$post = get_post();
		// Already escaped within the template.
		echo $this->render_payment_box( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Remove the author/email/url/cookie fields when locked.
	 */
	public function gate_form_fields( $fields ) {
		return $this->is_locked_current() ? array() : $fields;
	}

	/**
	 * Remove the comment textarea when locked.
	 */
	public function gate_comment_field( $field ) {
		return $this->is_locked_current() ? '' : $field;
	}

	/**
	 * Remove the native submit button when locked.
	 */
	public function gate_submit_button( $submit_button ) {
		return $this->is_locked_current() ? '' : $submit_button;
	}

	/**
	 * Remove the native submit field wrapper when locked.
	 */
	public function gate_submit_field( $submit_field ) {
		return $this->is_locked_current() ? '' : $submit_field;
	}

	/**
	 * Whether the current post is payment-locked for the current visitor.
	 */
	private function is_locked_current() {
		$post = get_post();

		return $post && $this->requires_payment( $post->ID ) && ! $this->has_access( $post->ID );
	}

	public function block_unpaid_comment( $commentdata ) {
		$post_id = isset( $commentdata['comment_post_ID'] ) ? absint( $commentdata['comment_post_ID'] ) : 0;

		if ( $post_id && $this->requires_payment( $post_id ) && ! $this->has_access( $post_id ) ) {
			wp_die( esc_html__( 'Payment is required before commenting on this content.', 'commentgate' ), esc_html__( 'CommentGate payment required', 'commentgate' ), array( 'response' => 402 ) );
		}

		return $commentdata;
	}

	public function auto_approve_paid_comment( $approved, $commentdata ) {
		$post_id = isset( $commentdata['comment_post_ID'] ) ? absint( $commentdata['comment_post_ID'] ) : 0;

		if ( ! $post_id || ! $this->requires_payment( $post_id ) || ! $this->has_access( $post_id ) ) {
			return $approved;
		}

		if ( '1' !== $this->settings->get( 'auto_approve_paid' ) ) {
			return $approved;
		}

		return 1;
	}

	public function attach_payment_to_comment( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment ) {
			$this->payments->consume_comment_access( $comment->comment_post_ID, $comment_id );
		}
	}

	public function start_payment() {
		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( ! $post_id || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'commentgate_start_payment_' . $post_id ) ) {
			wp_die( esc_html__( 'Invalid payment request.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 403 ) );
		}

		if ( ! $this->requires_payment( $post_id ) ) {
			wp_safe_redirect( esc_url_raw( get_permalink( $post_id ) ) );
			exit;
		}

		$gateway = sanitize_key( wp_unslash( $_POST['gateway'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['guest_email'] ?? '' ) );
		$price   = $this->price_for_post( $post_id );
		$access_type      = $this->access_type_for_post( $post_id );
		$comment_quantity = $this->comment_quantity_for_post( $post_id );

		if ( 'stripe' === $gateway ) {
			$this->stripe->redirect_to_checkout( $post_id, $price, $email, $access_type, $comment_quantity );
		}

		if ( 'paypal' === $gateway ) {
			$this->paypal->redirect_to_checkout( $post_id, $price, $email, $access_type, $comment_quantity );
		}

		wp_die( esc_html__( 'Selected gateway is unavailable.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 400 ) );
	}

	public function paypal_return() {
		$post_id    = absint( wp_unslash( $_GET['post_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$payment_id = absint( wp_unslash( $_GET['payment_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id   = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$access     = sanitize_text_field( wp_unslash( $_GET['access'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! $payment_id || '' === $order_id || '' === $access ) {
			wp_die( esc_html__( 'Invalid PayPal return.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 400 ) );
		}

		$this->paypal->capture_order( $payment_id, $order_id, $access );
		wp_safe_redirect( esc_url_raw( add_query_arg( 'commentgate', 'paid', get_permalink( $post_id ) ) ) );
		exit;
	}

	public function stripe_return() {
		$post_id = absint( wp_unslash( $_GET['post_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$access  = sanitize_text_field( wp_unslash( $_GET['access'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || '' === $access ) {
			wp_die( esc_html__( 'Invalid Stripe return.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 400 ) );
		}

		$this->set_access_cookie( $access );
		wp_safe_redirect( esc_url_raw( add_query_arg( 'commentgate', 'pending', get_permalink( $post_id ) ) ) );
		exit;
	}

	public function access_link() {
		$post_id    = absint( wp_unslash( $_GET['post_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$payment_id = absint( wp_unslash( $_GET['payment_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$access_key = sanitize_text_field( wp_unslash( $_GET['access_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! $payment_id || '' === $access_key ) {
			wp_die( esc_html__( 'Invalid CommentGate access link.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 400 ) );
		}

		$payment = $this->payments->find_valid_signed_access( $post_id, $access_key );
		if ( ! $payment || absint( $payment->id ) !== $payment_id ) {
			wp_die( esc_html__( 'Invalid CommentGate access link.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 403 ) );
		}

		$this->set_signed_access_cookie( $access_key );
		wp_safe_redirect( esc_url_raw( add_query_arg( 'commentgate', 'paid', get_permalink( $post_id ) ) ) );
		exit;
	}

	public function requires_payment( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		if ( $this->current_user_is_free() ) {
			return false;
		}

		$mode = get_post_meta( $post_id, '_commentgate_mode', true );
		if ( 'enabled' === $mode ) {
			return true;
		}

		if ( 'disabled' === $mode ) {
			return false;
		}

		$options = $this->settings->get_all();

		return '1' === $options['enabled'] && in_array( $post->post_type, (array) $options['post_types'], true );
	}

	public function has_access( $post_id ) {
		return $this->payments->user_has_access( $post_id );
	}

	public function price_for_post( $post_id ) {
		$custom = get_post_meta( $post_id, '_commentgate_price', true );
		return $custom ? $custom : $this->settings->get( 'price' );
	}

	public function access_type_for_post( $post_id ) {
		$custom = get_post_meta( $post_id, '_commentgate_access_type', true );
		if ( in_array( $custom, array( 'duration', 'comments' ), true ) ) {
			return $custom;
		}

		$access_type = $this->settings->get( 'access_type' );
		return 'comments' === $access_type ? 'comments' : 'duration';
	}

	public function comment_quantity_for_post( $post_id ) {
		$custom = absint( get_post_meta( $post_id, '_commentgate_comment_quantity', true ) );
		return $custom ? $custom : max( 1, absint( $this->settings->get( 'comment_quantity' ) ) );
	}

	private function current_user_is_free() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user       = wp_get_current_user();
		$free_roles = (array) $this->settings->get( 'free_roles' );

		return (bool) array_intersect( (array) $user->roles, $free_roles );
	}

	private function render_payment_box( $post_id ) {
		$options  = $this->settings->get_all();
		$gateways = (array) $options['gateways'];
		$price    = $this->price_for_post( $post_id );
		$currency = $options['currency'];
		$access_type      = $this->access_type_for_post( $post_id );
		$comment_quantity = $this->comment_quantity_for_post( $post_id );
		$stripe_text       = $options['stripe_button_text'] ? $options['stripe_button_text'] : __( 'Pay with Stripe to Comment', 'commentgate' );
		$paypal_text       = $options['paypal_button_text'] ? $options['paypal_button_text'] : __( 'Pay with PayPal to Comment', 'commentgate' );
		$button_bg_color   = '1' === $options['use_custom_button_colors'] ? $options['button_bg_color'] : '';
		$button_text_color = '1' === $options['use_custom_button_colors'] ? $options['button_text_color'] : '';

		ob_start();
		include COMMENTGATE_DIR . 'templates/payment-box.php';
		return ob_get_clean();
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

	private function set_signed_access_cookie( $value ) {
		setcookie(
			'commentgate_access_payment',
			$value,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE['commentgate_access_payment'] = $value;
	}
}
