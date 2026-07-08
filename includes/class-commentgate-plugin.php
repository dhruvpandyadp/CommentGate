<?php
/**
 * Main plugin coordinator.
 *
 * @package CommentGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,PluginCheck.Security.DirectDB.UnescapedDBParameter

class CommentGate_Plugin {
	private static $instance = null;

	public $settings;
	public $payments;
	public $gate;
	public $stripe;
	public $paypal;
	public $webhooks;
	public $admin_payments;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->payments = new CommentGate_Payments_Table();
		$this->settings = new CommentGate_Settings();
		$this->stripe   = new CommentGate_Stripe_Gateway( $this->settings, $this->payments );
		$this->paypal   = new CommentGate_PayPal_Gateway( $this->settings, $this->payments );
		$this->gate     = new CommentGate_Comment_Gate( $this->settings, $this->payments, $this->stripe, $this->paypal );
		$this->webhooks = new CommentGate_Webhooks( $this->stripe, $this->paypal );
		$this->admin_payments = new CommentGate_Admin_Payments( $this->payments, $this->stripe, $this->paypal );
		$this->settings->set_payments_page( $this->admin_payments );
	}

	public function register() {
		CommentGate_Payments_Table::maybe_upgrade();

		$this->settings->register();
		$this->gate->register();
		$this->webhooks->register();
		$this->admin_payments->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'commentgate_payment_paid', array( $this, 'send_admin_payment_email' ) );
		add_action( 'commentgate_payment_paid', array( $this, 'send_customer_payment_email' ) );
		add_action( 'commentgate_payment_refunded', array( $this, 'send_customer_refund_email' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( COMMENTGATE_FILE ), array( $this, 'add_plugin_action_links' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'commentgate', new CommentGate_CLI( $this->settings, $this->payments, $this->stripe, $this->paypal ) );
		}
	}

	public function add_plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'edit-comments.php?page=commentgate' ) ),
			esc_html__( 'Settings', 'commentgate' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	public function enqueue_frontend_assets() {
		wp_enqueue_style( 'commentgate-frontend', COMMENTGATE_URL . 'assets/css/frontend.css', array(), COMMENTGATE_VERSION );
		wp_enqueue_script( 'commentgate-frontend', COMMENTGATE_URL . 'assets/js/frontend.js', array(), COMMENTGATE_VERSION, true );
	}

	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'commentgate' ) && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'commentgate-admin', COMMENTGATE_URL . 'assets/css/admin.css', array(), COMMENTGATE_VERSION );

		if ( false !== strpos( (string) $hook, 'commentgate' ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'commentgate-admin', COMMENTGATE_URL . 'assets/js/admin.js', array( 'jquery' ), COMMENTGATE_VERSION, true );
			wp_localize_script(
				'commentgate-admin',
				'commentGateAdmin',
				array(
					'chooseLogo' => __( 'Choose email logo', 'commentgate' ),
					'useLogo'    => __( 'Use this logo', 'commentgate' ),
				)
			);
		}
	}

	public function send_admin_payment_email( $payment_id ) {
		if ( '1' !== $this->settings->get( 'admin_payment_email' ) ) {
			return;
		}

		$payment = $this->payments->find_by_id( absint( $payment_id ) );
		if ( ! $payment || 'paid' !== $payment->status ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return;
		}

		$post_title = get_the_title( $payment->post_id );
		$subject    = sprintf(
			/* translators: %s: site name */
			__( '[%s] CommentGate payment received', 'commentgate' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$message    = sprintf(
			/* translators: 1: amount, 2: gateway, 3: post title, 4: payer, 5: payment ID */
			__( "A CommentGate payment was completed.\n\nAmount: %1\$s\nGateway: %2\$s\nContent: %3\$s\nPayer: %4\$s\nPayment ID: %5\$d\n", 'commentgate' ),
			$payment->currency . ' ' . number_format_i18n( (float) $payment->amount, 2 ),
			ucfirst( $payment->gateway ),
			$post_title ? $post_title : __( '(no title)', 'commentgate' ),
			$payment->user_id ? get_the_author_meta( 'user_login', $payment->user_id ) : $payment->guest_email,
			absint( $payment->id )
		);

		wp_mail( $admin_email, $subject, $message, $this->email_headers( false ) );
	}

	public function send_customer_payment_email( $payment_id ) {
		if ( '1' !== $this->settings->get( 'customer_payment_email' ) ) {
			return;
		}

		$payment = $this->payments->find_by_id( absint( $payment_id ) );
		if ( ! $payment || 'paid' !== $payment->status ) {
			return;
		}

		$this->send_customer_email( $payment, 'payment' );
	}

	public function send_customer_refund_email( $payment_id ) {
		if ( '1' !== $this->settings->get( 'refund_email' ) ) {
			return;
		}

		$payment = $this->payments->find_by_id( absint( $payment_id ) );
		if ( ! $payment || 'refunded' !== $payment->status ) {
			return;
		}

		$this->send_customer_email( $payment, 'refund' );
	}

	private function send_customer_email( $payment, $type ) {
		$email = $this->payment_email_address( $payment );
		if ( ! is_email( $email ) ) {
			return;
		}

		$subject_key = 'refund' === $type ? 'refund_email_subject' : 'payment_email_subject';
		$body_key    = 'refund' === $type ? 'refund_email_body' : 'payment_email_body';
		$is_invoice_html = 'html' === $this->settings->get( 'email_format' );
		$subject     = $this->replace_email_tags( $this->settings->get( $subject_key ), $payment );
		$body        = $this->replace_email_tags( $this->settings->get( $body_key ), $payment, ! $is_invoice_html );
		if ( 'refund' === $type ) {
			$body = preg_replace( '/^\s*Refund ID:\s*.*$/mi', '', $body );
		}
		$message         = $is_invoice_html ? $this->customer_email_html( $payment, $type, trim( $body ) ) : $this->customer_email_simple_html( $payment, $type, trim( $body ) );
		$headers         = $this->email_headers( true );

		wp_mail( $email, $subject, $message, $headers );
	}

	private function payment_email_address( $payment ) {
		if ( ! empty( $payment->guest_email ) && is_email( $payment->guest_email ) ) {
			return $payment->guest_email;
		}

		if ( $payment->user_id ) {
			$user = get_user_by( 'id', absint( $payment->user_id ) );
			if ( $user && is_email( $user->user_email ) ) {
				return $user->user_email;
			}
		}

		return '';
	}

	private function email_headers( $html = true ) {
		$admin_email = get_option( 'admin_email' );
		$site_name   = sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$headers     = $html ? array( 'Content-Type: text/html; charset=UTF-8' ) : array();

		if ( is_email( $admin_email ) ) {
			$headers[] = 'From: ' . $site_name . ' <' . $admin_email . '>';
			$headers[] = 'Reply-To: ' . $site_name . ' <' . $admin_email . '>';
		}

		return $headers;
	}

	private function replace_email_tags( $text, $payment, $use_content_url = false ) {
		$post_title = get_the_title( $payment->post_id );
		$url        = $use_content_url ? $this->customer_content_url( $payment ) : $this->customer_access_url( $payment );
		$footer     = $this->settings->get( 'email_footer' );

		$access = __( 'Duration based access', 'commentgate' );
		if ( 'comments' === $payment->access_type ) {
			$access = sprintf(
				/* translators: %d: comment count */
				_n( '%d comment credit', '%d comment credits', absint( $payment->comment_limit ), 'commentgate' ),
				absint( $payment->comment_limit )
			);
		} elseif ( ! empty( $payment->expires_at ) ) {
			$access = sprintf(
				/* translators: %s: expiry date */
				__( 'Access until %s', 'commentgate' ),
				$payment->expires_at
			);
		}

		$replacements = array(
			'{amount}'     => $payment->currency . ' ' . number_format_i18n( (float) $payment->amount, 2 ),
			'{content}'    => $post_title ? $post_title : __( '(no title)', 'commentgate' ),
			'{access}'     => $access,
			'{url}'        => $url ? $url : home_url( '/' ),
			'{payment_id}' => absint( $payment->id ),
			'{refund_id}'  => $payment->refund_id ? $payment->refund_id : __( '(not provided)', 'commentgate' ),
			'{footer}'     => $footer ? $footer : '',
		);

		return strtr( (string) $text, $replacements );
	}

	private function customer_access_url( $payment ) {
		$access_key = $this->payments->signed_access_value( $payment );
		if ( $access_key ) {
			return add_query_arg(
				array(
					'action'     => 'commentgate_access_link',
					'post_id'    => absint( $payment->post_id ),
					'payment_id' => absint( $payment->id ),
					'access_key' => $access_key,
				),
				admin_url( 'admin-post.php' )
			);
		}

		$url = get_permalink( $payment->post_id );
		return $url ? $url : home_url( '/' );
	}

	private function customer_content_url( $payment ) {
		$url = get_permalink( $payment->post_id );
		return $url ? $url : home_url( '/' );
	}

	private function customer_email_html( $payment, $type, $body ) {
		$title      = 'refund' === $type ? __( 'Refund receipt', 'commentgate' ) : __( 'Payment receipt', 'commentgate' );
		$notice     = 'refund' === $type ? __( 'This payment has been refunded. If you still need comment access, please make a new purchase from the content page.', 'commentgate' ) : __( 'You can reopen the paid comment area from this secure email link until your comment credit is used or your access expires.', 'commentgate' );
		$logo       = '';
		$logo_url   = $this->settings->get( 'email_logo_url' );
		if ( $logo_url ) {
			$logo = sprintf(
				'<div style="padding:20px 24px 0;text-align:center;"><img src="%1$s" alt="%2$s" style="height:auto;max-height:64px;max-width:220px;"></div>',
				esc_url( $logo_url ),
				esc_attr( get_bloginfo( 'name' ) )
			);
		}
		$button     = 'refund' === $type ? '' : sprintf(
			'<p style="margin:24px 0;"><a href="%1$s" style="background:#1d2327;border-radius:4px;color:#ffffff;display:inline-block;font-weight:600;padding:12px 18px;text-decoration:none;">%2$s</a></p>',
			esc_url( $this->customer_access_url( $payment ) ),
			esc_html__( 'Open paid comment area', 'commentgate' )
		);
		$footer     = $this->replace_email_tags( $this->settings->get( 'email_footer' ), $payment );
		$post_title = get_the_title( $payment->post_id );
		$access     = $this->replace_email_tags( '{access}', $payment );
		$refund_row = 'refund' === $type ? sprintf(
			'<tr><th align="left" style="border-bottom:1px solid #dcdcde;padding:10px 0;">%1$s</th><td align="right" style="border-bottom:1px solid #dcdcde;padding:10px 0;">%2$s</td></tr>',
			esc_html__( 'Refund ID', 'commentgate' ),
			esc_html( $payment->refund_id ? $payment->refund_id : __( '(not provided)', 'commentgate' ) )
		) : '';

		return sprintf(
			'<!doctype html><html><body style="background:#f6f7f7;margin:0;padding:24px;font-family:Arial,sans-serif;color:#1d2327;"><div style="background:#ffffff;border:1px solid #dcdcde;border-radius:8px;margin:0 auto;max-width:640px;overflow:hidden;">%1$s<div style="background:#1d2327;color:#ffffff;padding:20px 24px;"><h1 style="color:#ffffff!important;font-size:20px;line-height:1.3;margin:0;">%2$s</h1></div><div style="padding:24px;"><p style="font-size:16px;line-height:1.6;margin:0 0 18px;">%3$s</p><table style="border-collapse:collapse;margin:18px 0;width:100%%;"><tbody><tr><th align="left" style="border-bottom:1px solid #dcdcde;padding:10px 0;">%4$s</th><td align="right" style="border-bottom:1px solid #dcdcde;padding:10px 0;">%5$s</td></tr><tr><th align="left" style="border-bottom:1px solid #dcdcde;padding:10px 0;">%6$s</th><td align="right" style="border-bottom:1px solid #dcdcde;padding:10px 0;">%7$s</td></tr><tr><th align="left" style="border-bottom:1px solid #dcdcde;padding:10px 0;">%8$s</th><td align="right" style="border-bottom:1px solid #dcdcde;padding:10px 0;">%9$s</td></tr><tr><th align="left" style="border-bottom:1px solid #dcdcde;padding:10px 0;">%10$s</th><td align="right" style="border-bottom:1px solid #dcdcde;padding:10px 0;">%11$s</td></tr>%12$s</tbody></table>%13$s<p style="background:#fff8e5;border-left:4px solid #dba617;margin:18px 0;padding:12px;">%14$s</p></div><div style="background:#f0f0f1;color:#646970;font-size:12px;line-height:1.5;padding:16px 24px;text-align:center;">%15$s</div></div></body></html>',
			$logo,
			esc_html( $title ),
			wp_kses_post( nl2br( esc_html( $body ) ) ),
			esc_html__( 'Amount', 'commentgate' ),
			esc_html( $payment->currency . ' ' . number_format_i18n( (float) $payment->amount, 2 ) ),
			esc_html__( 'Content', 'commentgate' ),
			esc_html( $post_title ? $post_title : __( '(no title)', 'commentgate' ) ),
			esc_html__( 'Access', 'commentgate' ),
			esc_html( $access ),
			esc_html__( 'Payment ID', 'commentgate' ),
			esc_html( absint( $payment->id ) ),
			$refund_row,
			$button,
			esc_html( $notice ),
			wp_kses_post( nl2br( esc_html( $footer ) ) )
		);
	}

	private function customer_email_simple_html( $payment, $type, $body ) {
		$title      = 'refund' === $type ? __( 'Refund receipt', 'commentgate' ) : __( 'Payment receipt', 'commentgate' );
		$notice     = 'refund' === $type ? __( 'This payment has been refunded. If you still need comment access, please make a new purchase from the content page.', 'commentgate' ) : __( 'You can reopen the paid comment area from this secure email link until your comment credit is used or your access expires.', 'commentgate' );
		$footer     = $this->replace_email_tags( $this->settings->get( 'email_footer' ), $payment );
		$post_title = get_the_title( $payment->post_id );
		$access     = $this->replace_email_tags( '{access}', $payment );
		$lines      = array(
			esc_html( $title ),
			'',
			wp_kses_post( nl2br( esc_html( $body ) ) ),
			'',
			esc_html__( 'Amount', 'commentgate' ) . ': ' . esc_html( $payment->currency . ' ' . number_format_i18n( (float) $payment->amount, 2 ) ),
			esc_html__( 'Content', 'commentgate' ) . ': ' . esc_html( $post_title ? $post_title : __( '(no title)', 'commentgate' ) ),
			esc_html__( 'Access', 'commentgate' ) . ': ' . esc_html( $access ),
			esc_html__( 'Payment ID', 'commentgate' ) . ': ' . esc_html( absint( $payment->id ) ),
		);

		if ( 'refund' === $type ) {
			$lines[] = esc_html__( 'Refund ID', 'commentgate' ) . ': ' . esc_html( $payment->refund_id ? $payment->refund_id : __( '(not provided)', 'commentgate' ) );
		} else {
			$lines[] = '';
			$lines[] = esc_html__( 'Open paid comment area:', 'commentgate' );
			$lines[] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $this->customer_access_url( $payment ) ),
				esc_html( $this->customer_content_url( $payment ) )
			);
		}

		$lines[] = '';
		$lines[] = esc_html( $notice );

		if ( $footer ) {
			$lines[] = '';
			$lines[] = wp_kses_post( nl2br( esc_html( $footer ) ) );
		}

		return sprintf(
			'<!doctype html><html><body style="background:#ffffff;color:#1d2327;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;margin:0;padding:20px;"><div style="max-width:640px;">%s</div></body></html>',
			implode( "<br>\n", $lines )
		);
	}

	public static function uninstall() {
		global $wpdb;

		delete_option( CommentGate_Settings::OPTION_NAME );
		delete_option( 'commentgate_db_version' );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . CommentGate_Payments_Table::table_name() );

		$post_types = get_post_types( array(), 'names' );
		foreach ( $post_types as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_commentgate_mode',
				)
			);

			foreach ( $posts as $post_id ) {
				delete_post_meta( $post_id, '_commentgate_mode' );
				delete_post_meta( $post_id, '_commentgate_price' );
				delete_post_meta( $post_id, '_commentgate_access_type' );
				delete_post_meta( $post_id, '_commentgate_comment_quantity' );
			}
		}
	}
}
