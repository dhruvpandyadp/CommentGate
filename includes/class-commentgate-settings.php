<?php
/**
 * Admin settings and post meta.
 *
 * @package CommentGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CommentGate_Settings {
	const OPTION_NAME = 'commentgate_settings';

	private $payments_page;

	/**
	 * Inject the admin payments screen instance so it can be rendered from the
	 * "Transaction History" tab.
	 *
	 * @param CommentGate_Admin_Payments $payments_page Admin payments screen instance.
	 */
	public function set_payments_page( $payments_page ) {
		$this->payments_page = $payments_page;
	}

	/**
	 * Wire up the settings page, registered setting, meta box, and post meta save hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post_meta' ) );
	}

	/**
	 * Default values for every stored setting.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'enabled'                  => '0',
			'price'                    => '5.00',
			'currency'                 => 'USD',
			'post_types'               => array( 'post' ),
			'gateways'                 => array( 'stripe' ),
			'free_roles'               => array( 'administrator' ),
			'auto_approve_paid'        => '1',
			'admin_payment_email'      => '1',
			'customer_payment_email'   => '1',
			'refund_email'             => '1',
			'payment_email_subject'    => __( 'Your comment access is ready', 'commentgate' ),
			'payment_email_body'       => __( "Thank you for your payment. Your comment access is ready.\n\nUse the secure access link below to reopen the paid comment area until your comment credit is used or access expires.", 'commentgate' ),
			'refund_email_subject'     => __( 'Your comment access payment was refunded', 'commentgate' ),
			'refund_email_body'        => __( 'Your payment was refunded.', 'commentgate' ),
			'email_footer'             => '',
			'email_logo_url'           => '',
			'email_format'             => 'html',
			'access_type'              => 'comments',
			'access_duration'          => '1',
			'comment_quantity'         => '1',
			'stripe_publishable'       => '',
			'stripe_secret'            => '',
			'stripe_webhook_secret'    => '',
			'paypal_client_id'         => '',
			'paypal_secret'            => '',
			'paypal_webhook_id'        => '',
			'paypal_mode'              => 'sandbox',
			'stripe_button_text'       => '',
			'paypal_button_text'       => '',
			'use_custom_button_colors' => '0',
			'button_bg_color'          => '',
			'button_text_color'        => '',
		);
	}

	/**
	 * Get all settings, merged with defaults and with the gateways list
	 * normalized to known values.
	 *
	 * @return array
	 */
	public function get_all() {
		$options = get_option( self::OPTION_NAME, array() );
		$options = wp_parse_args( is_array( $options ) ? $options : array(), $this->defaults() );

		$gateways            = array_values( array_intersect( array_map( 'sanitize_key', (array) $options['gateways'] ), array( 'stripe', 'paypal' ) ) );
		$options['gateways'] = $gateways ? $gateways : array( 'stripe' );

		return $options;
	}

	/**
	 * Get a single setting value by key.
	 *
	 * @param string $key Setting key.
	 * @return mixed Null if the key does not exist.
	 */
	public function get( $key ) {
		$options = $this->get_all();
		return isset( $options[ $key ] ) ? $options[ $key ] : null;
	}

	/**
	 * Register the CommentGate settings page as a Comments submenu page.
	 */
	public function add_settings_page() {
		add_submenu_page(
			'edit-comments.php',
			__( 'CommentGate', 'commentgate' ),
			__( 'CommentGate', 'commentgate' ),
			'manage_options',
			'commentgate',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the commentgate_settings option with WordPress Settings API,
	 * routing all saves through sanitize_settings().
	 */
	public function register_settings() {
		register_setting(
			'commentgate',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->defaults(),
			)
		);
	}

	/**
	 * Sanitize and merge settings form input, registered as the option's
	 * sanitize_callback. Only fields belonging to the submitted tab
	 * (commentgate_active_tab) are updated; all other tabs' values pass through
	 * unchanged so submitting one tab never clobbers another's settings.
	 *
	 * @param array $input Raw settings form input.
	 * @return array Sanitized settings ready to store.
	 */
	public function sanitize_settings( $input ) {
		$input      = is_array( $input ) ? wp_unslash( $input ) : array();
		$defaults   = $this->defaults();
		$current    = $this->get_all();
		$active_tab = $this->normalize_tab( $input['commentgate_active_tab'] ?? 'general-settings' );
		if ( 'general-settings' === $active_tab ) {
			if ( ! isset( $input['enabled'] ) ) {
				$input['enabled'] = '0';
			}
			if ( ! isset( $input['auto_approve_paid'] ) ) {
				$input['auto_approve_paid'] = '0';
			}
			if ( ! isset( $input['free_roles'] ) ) {
				$input['free_roles'] = array();
			}
			if ( ! isset( $input['post_types'] ) ) {
				$input['post_types'] = array();
			}
			if ( ! isset( $input['gateways'] ) ) {
				$input['gateways'] = array();
			}
		}
		if ( 'email-settings' === $active_tab ) {
			if ( ! isset( $input['admin_payment_email'] ) ) {
				$input['admin_payment_email'] = '0';
			}
			if ( ! isset( $input['customer_payment_email'] ) ) {
				$input['customer_payment_email'] = '0';
			}
			if ( ! isset( $input['refund_email'] ) ) {
				$input['refund_email'] = '0';
			}
		}
		if ( 'appearance' === $active_tab && ! isset( $input['use_custom_button_colors'] ) ) {
			$input['use_custom_button_colors'] = '0';
		}
		$input = wp_parse_args( $input, $current );

		$post_types = array_intersect( array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) ), get_post_types( array( 'public' => true ), 'names' ) );
		$gateways   = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['gateways'] ?? array() ) ), array( 'stripe', 'paypal' ) ) );
		if ( ! $gateways ) {
			$gateways = $current['gateways'];
		}
		$roles       = array_keys( wp_roles()->roles );
		$free_roles  = array_intersect( array_map( 'sanitize_key', (array) ( $input['free_roles'] ?? array() ) ), $roles );
		$access_type = sanitize_key( $input['access_type'] ?? $defaults['access_type'] );
		if ( ! in_array( $access_type, array( 'duration', 'comments' ), true ) ) {
			$access_type = $defaults['access_type'];
		}

		return array(
			'enabled'                  => 'general-settings' === $active_tab ? ( empty( $input['enabled'] ) ? '0' : '1' ) : $current['enabled'],
			'price'                    => $this->sanitize_price( $input['price'] ?? $defaults['price'] ),
			'currency'                 => $this->sanitize_currency( $input['currency'] ?? $defaults['currency'] ),
			'post_types'               => 'general-settings' === $active_tab ? array_values( $post_types ) : $current['post_types'],
			'gateways'                 => 'general-settings' === $active_tab ? $gateways : $current['gateways'],
			'free_roles'               => 'general-settings' === $active_tab ? array_values( $free_roles ) : $current['free_roles'],
			'auto_approve_paid'        => 'general-settings' === $active_tab ? ( empty( $input['auto_approve_paid'] ) ? '0' : '1' ) : $current['auto_approve_paid'],
			'admin_payment_email'      => 'email-settings' === $active_tab ? ( empty( $input['admin_payment_email'] ) ? '0' : '1' ) : $current['admin_payment_email'],
			'customer_payment_email'   => 'email-settings' === $active_tab ? ( empty( $input['customer_payment_email'] ) ? '0' : '1' ) : $current['customer_payment_email'],
			'refund_email'             => 'email-settings' === $active_tab ? ( empty( $input['refund_email'] ) ? '0' : '1' ) : $current['refund_email'],
			'payment_email_subject'    => 'email-settings' === $active_tab ? sanitize_text_field( $input['payment_email_subject'] ?? $defaults['payment_email_subject'] ) : $current['payment_email_subject'],
			'payment_email_body'       => 'email-settings' === $active_tab ? sanitize_textarea_field( $input['payment_email_body'] ?? $defaults['payment_email_body'] ) : $current['payment_email_body'],
			'refund_email_subject'     => 'email-settings' === $active_tab ? sanitize_text_field( $input['refund_email_subject'] ?? $defaults['refund_email_subject'] ) : $current['refund_email_subject'],
			'refund_email_body'        => 'email-settings' === $active_tab ? sanitize_textarea_field( $input['refund_email_body'] ?? $defaults['refund_email_body'] ) : $current['refund_email_body'],
			'email_footer'             => 'email-settings' === $active_tab ? sanitize_textarea_field( $input['email_footer'] ?? $defaults['email_footer'] ) : $current['email_footer'],
			'email_logo_url'           => 'email-settings' === $active_tab ? esc_url_raw( $input['email_logo_url'] ?? '' ) : $current['email_logo_url'],
			'email_format'             => 'email-settings' === $active_tab && 'html' === ( $input['email_format'] ?? '' ) ? 'html' : ( 'email-settings' === $active_tab ? 'plain' : $current['email_format'] ),
			'access_type'              => $access_type,
			'access_duration'          => max( 0, absint( $input['access_duration'] ?? 0 ) ),
			'comment_quantity'         => max( 1, absint( $input['comment_quantity'] ?? 1 ) ),
			'stripe_publishable'       => sanitize_text_field( $input['stripe_publishable'] ?? '' ),
			'stripe_secret'            => sanitize_text_field( $input['stripe_secret'] ?? '' ),
			'stripe_webhook_secret'    => sanitize_text_field( $input['stripe_webhook_secret'] ?? '' ),
			'paypal_client_id'         => sanitize_text_field( $input['paypal_client_id'] ?? '' ),
			'paypal_secret'            => sanitize_text_field( $input['paypal_secret'] ?? '' ),
			'paypal_webhook_id'        => sanitize_text_field( $input['paypal_webhook_id'] ?? '' ),
			'paypal_mode'              => 'live' === ( $input['paypal_mode'] ?? '' ) ? 'live' : 'sandbox',
			'stripe_button_text'       => sanitize_text_field( $input['stripe_button_text'] ?? '' ),
			'paypal_button_text'       => sanitize_text_field( $input['paypal_button_text'] ?? '' ),
			'use_custom_button_colors' => 'appearance' === $active_tab ? ( empty( $input['use_custom_button_colors'] ) ? '0' : '1' ) : $current['use_custom_button_colors'],
			'button_bg_color'          => sanitize_hex_color( $input['button_bg_color'] ?? '' ) ?? '',
			'button_text_color'        => sanitize_hex_color( $input['button_text_color'] ?? '' ) ?? '',
		);
	}

	/**
	 * Sanitize a price input to a positive two-decimal string.
	 *
	 * @param mixed $price Raw price input.
	 * @return string
	 */
	private function sanitize_price( $price ) {
		$price = preg_replace( '/[^0-9.]/', '', (string) $price );
		return number_format( max( 0.01, (float) $price ), 2, '.', '' );
	}

	/**
	 * Supported currency codes mapped to their translated display labels.
	 *
	 * @return array
	 */
	private function currencies() {
		return array(
			'USD' => __( 'US Dollar (USD)', 'commentgate' ),
			'EUR' => __( 'Euro (EUR)', 'commentgate' ),
			'GBP' => __( 'British Pound (GBP)', 'commentgate' ),
			'CAD' => __( 'Canadian Dollar (CAD)', 'commentgate' ),
			'AUD' => __( 'Australian Dollar (AUD)', 'commentgate' ),
			'NZD' => __( 'New Zealand Dollar (NZD)', 'commentgate' ),
			'INR' => __( 'Indian Rupee (INR)', 'commentgate' ),
			'SGD' => __( 'Singapore Dollar (SGD)', 'commentgate' ),
			'CHF' => __( 'Swiss Franc (CHF)', 'commentgate' ),
			'SEK' => __( 'Swedish Krona (SEK)', 'commentgate' ),
			'NOK' => __( 'Norwegian Krone (NOK)', 'commentgate' ),
			'DKK' => __( 'Danish Krone (DKK)', 'commentgate' ),
			'MXN' => __( 'Mexican Peso (MXN)', 'commentgate' ),
			'BRL' => __( 'Brazilian Real (BRL)', 'commentgate' ),
		);
	}

	/**
	 * Sanitize a currency code, falling back to USD if it is not a known currency.
	 *
	 * @param mixed $currency Raw currency input.
	 * @return string
	 */
	private function sanitize_currency( $currency ) {
		$currency = strtoupper( sanitize_text_field( $currency ) );
		return array_key_exists( $currency, $this->currencies() ) ? $currency : 'USD';
	}

	/**
	 * Sanitize a settings page tab slug.
	 *
	 * @param mixed $tab Raw tab input.
	 * @return string
	 */
	private function normalize_tab( $tab ) {
		return sanitize_key( $tab );
	}

	/**
	 * Render the CommentGate admin settings page, including all tab content.
	 * Gated on manage_options; the transaction-history tab delegates to the
	 * admin payments screen instead of a settings form.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options    = $this->get_all();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$roles      = wp_roles()->roles;
		$currencies = $this->currencies();
		$tab        = $this->normalize_tab( sanitize_key( wp_unslash( $_GET['tab'] ?? 'general-settings' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs       = array(
			'general-settings'          => __( 'General Settings', 'commentgate' ),
			'transaction-history'       => __( 'Transaction History', 'commentgate' ),
			'payment-api-configuration' => __( 'Payment API Configuration', 'commentgate' ),
			'email-settings'            => __( 'Email Settings', 'commentgate' ),
			'appearance'                => __( 'Appearance', 'commentgate' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general-settings';
		}
		?>
		<div class="wrap commentgate-admin">
			<h1><?php esc_html_e( 'CommentGate Dashboard', 'commentgate' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'CommentGate updated.', 'commentgate' ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper commentgate-tabs">
				<?php foreach ( $tabs as $tab_key => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $tab_key ? 'nav-tab-active' : ''; ?>" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page' => 'commentgate',
								'tab'  => $tab_key,
							),
							admin_url( 'edit-comments.php' )
						)
					);
					?>
										"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php if ( 'transaction-history' === $tab && $this->payments_page ) : ?>
				<?php $this->payments_page->render_page(); ?>
			<?php else : ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'commentgate' ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[commentgate_active_tab]" value="<?php echo esc_attr( $tab ); ?>">
				<?php if ( 'general-settings' === $tab ) : ?>
					<div class="commentgate-discussion-card">
						<div>
							<h2><?php esc_html_e( 'Website Discussion Settings', 'commentgate' ); ?></h2>
							<p><?php esc_html_e( 'Tune your site-wide comment rules, moderation queue, avatars, and notification behavior before CommentGate opens the gate.', 'commentgate' ); ?></p>
						</div>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'options-discussion.php' ) ); ?>"><?php esc_html_e( 'Open Discussion Settings', 'commentgate' ); ?></a>
					</div>
				<?php endif; ?>
				<?php if ( 'payment-api-configuration' === $tab ) : ?>
					<?php $this->render_diagnostics( $options ); ?>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<?php if ( 'general-settings' === $tab ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable paid comments', 'commentgate' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled]" value="1" <?php checked( $options['enabled'], '1' ); ?>> <?php esc_html_e( 'Charge before comment form unlocks.', 'commentgate' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default price', 'commentgate' ); ?></th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[price]" value="<?php echo esc_attr( $options['price'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Currency', 'commentgate' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[currency]">
								<?php foreach ( $currencies as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $options['currency'], $code ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Apply to post types', 'commentgate' ); ?></th>
						<td>
							<?php foreach ( $post_types as $type ) : ?>
								<label class="commentgate-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $options['post_types'], true ) ); ?>> <?php echo esc_html( $type->labels->singular_name ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment gateway', 'commentgate' ); ?></th>
						<td>
							<label class="commentgate-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gateways][]" value="stripe" <?php checked( in_array( 'stripe', $options['gateways'], true ) ); ?>> Stripe</label>
							<label class="commentgate-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gateways][]" value="paypal" <?php checked( in_array( 'paypal', $options['gateways'], true ) ); ?>> PayPal</label>
							<p class="description"><?php esc_html_e( 'Enable one or both gateways. When both are enabled, visitors can choose Stripe or PayPal on the payment wall.', 'commentgate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Free roles', 'commentgate' ); ?></th>
						<td>
							<?php foreach ( $roles as $role_key => $role ) : ?>
								<label class="commentgate-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[free_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $options['free_roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $role['name'] ) ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Paid comment moderation', 'commentgate' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auto_approve_paid]" value="1" <?php checked( $options['auto_approve_paid'], '1' ); ?>> <?php esc_html_e( 'Auto approve comments after CommentGate access is verified.', 'commentgate' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Access type', 'commentgate' ); ?></th>
						<td>
							<label class="commentgate-check"><input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[access_type]" value="comments" <?php checked( $options['access_type'], 'comments' ); ?>> <?php esc_html_e( 'Comment quantity based', 'commentgate' ); ?></label>
							<label class="commentgate-check"><input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[access_type]" value="duration" <?php checked( $options['access_type'], 'duration' ); ?>> <?php esc_html_e( 'Duration based', 'commentgate' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Comments per purchase', 'commentgate' ); ?></th>
						<td><input type="number" min="1" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[comment_quantity]" value="<?php echo esc_attr( $options['comment_quantity'] ); ?>"> <?php esc_html_e( 'comments. Used when comment quantity based access is selected.', 'commentgate' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Access duration', 'commentgate' ); ?></th>
						<td><input type="number" min="0" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[access_duration]" value="<?php echo esc_attr( $options['access_duration'] ); ?>"> <?php esc_html_e( 'minutes. Use 0 for no expiry when duration based access is selected.', 'commentgate' ); ?></td>
					</tr>
					<?php endif; ?>

					<?php if ( 'email-settings' === $tab ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Admin email alert', 'commentgate' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[admin_payment_email]" value="1" <?php checked( $options['admin_payment_email'], '1' ); ?>> <?php esc_html_e( 'Email the site administrator when a payment is completed.', 'commentgate' ); ?></label><p class="description"><?php esc_html_e( 'Uses the default WordPress mail configuration and sends to the site administration email address.', 'commentgate' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Customer emails', 'commentgate' ); ?></th>
						<td>
							<label class="commentgate-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[customer_payment_email]" value="1" <?php checked( $options['customer_payment_email'], '1' ); ?>> <?php esc_html_e( 'Email customers when payment completes.', 'commentgate' ); ?></label>
							<label class="commentgate-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[refund_email]" value="1" <?php checked( $options['refund_email'], '1' ); ?>> <?php esc_html_e( 'Email customers when payment is refunded.', 'commentgate' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Customer email format', 'commentgate' ); ?></th>
						<td>
							<label class="commentgate-check"><input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[email_format]" value="html" <?php checked( $options['email_format'], 'html' ); ?>> <?php esc_html_e( 'HTML invoice', 'commentgate' ); ?></label>
							<label class="commentgate-check"><input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[email_format]" value="plain" <?php checked( $options['email_format'], 'plain' ); ?>> <?php esc_html_e( 'Simple text-style email', 'commentgate' ); ?></label>
							<p class="description"><?php esc_html_e( 'HTML invoice enables the logo and button styling. Simple text-style email uses minimal HTML so the secure access link can show the public content URL.', 'commentgate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email logo URL', 'commentgate' ); ?></th>
						<td>
							<label class="screen-reader-text" for="commentgate_email_logo_url"><?php esc_html_e( 'Email logo URL', 'commentgate' ); ?></label>
							<input id="commentgate_email_logo_url" type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[email_logo_url]" value="<?php echo esc_url( $options['email_logo_url'] ); ?>" placeholder="https://example.com/logo.png">
							<button type="button" class="button commentgate-select-logo" data-target="commentgate_email_logo_url"><?php esc_html_e( 'Choose from Media Library', 'commentgate' ); ?></button>
							<button type="button" class="button commentgate-remove-logo" data-target="commentgate_email_logo_url"><?php esc_html_e( 'Remove logo', 'commentgate' ); ?></button>
							<p class="description"><?php esc_html_e( 'Optional. Shown in the HTML email header. Leave blank to send without a logo.', 'commentgate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment email template', 'commentgate' ); ?></th>
						<td>
							<label class="screen-reader-text" for="commentgate_payment_email_subject"><?php esc_html_e( 'Payment email subject', 'commentgate' ); ?></label>
							<input id="commentgate_payment_email_subject" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[payment_email_subject]" value="<?php echo esc_attr( $options['payment_email_subject'] ); ?>">
							<label class="screen-reader-text" for="commentgate_payment_email_body"><?php esc_html_e( 'Payment email body', 'commentgate' ); ?></label>
							<textarea id="commentgate_payment_email_body" class="large-text" rows="7" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[payment_email_body]"><?php echo esc_textarea( $options['payment_email_body'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Available tags: {amount}, {content}, {access}, {url}, {payment_id}, {footer}', 'commentgate' ); ?></p>
							<p class="description"><?php esc_html_e( 'In simple text-style email, {url} shows the public content URL. The signed access URL is used behind the public URL link.', 'commentgate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Refund email template', 'commentgate' ); ?></th>
						<td>
							<label class="screen-reader-text" for="commentgate_refund_email_subject"><?php esc_html_e( 'Refund email subject', 'commentgate' ); ?></label>
							<input id="commentgate_refund_email_subject" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[refund_email_subject]" value="<?php echo esc_attr( $options['refund_email_subject'] ); ?>">
							<label class="screen-reader-text" for="commentgate_refund_email_body"><?php esc_html_e( 'Refund email body', 'commentgate' ); ?></label>
							<textarea id="commentgate_refund_email_body" class="large-text" rows="7" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[refund_email_body]"><?php echo esc_textarea( $options['refund_email_body'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Available tags: {amount}, {content}, {refund_id}, {payment_id}, {footer}', 'commentgate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email footer', 'commentgate' ); ?></th>
						<td>
							<label class="screen-reader-text" for="commentgate_email_footer"><?php esc_html_e( 'Email footer', 'commentgate' ); ?></label>
							<textarea id="commentgate_email_footer" class="large-text" rows="3" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[email_footer]"><?php echo esc_textarea( $options['email_footer'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown at the bottom of customer payment and refund emails.', 'commentgate' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( 'appearance' === $tab ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stripe button text', 'commentgate' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[stripe_button_text]" value="<?php echo esc_attr( $options['stripe_button_text'] ); ?>" placeholder="<?php esc_attr_e( 'Pay with Stripe to Comment', 'commentgate' ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to use default text.', 'commentgate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'PayPal button text', 'commentgate' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[paypal_button_text]" value="<?php echo esc_attr( $options['paypal_button_text'] ); ?>" placeholder="<?php esc_attr_e( 'Pay with PayPal to Comment', 'commentgate' ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to use default text.', 'commentgate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Button colors', 'commentgate' ); ?></th>
						<td>
							<label class="commentgate-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[use_custom_button_colors]" value="1" <?php checked( $options['use_custom_button_colors'], '1' ); ?>> <?php esc_html_e( 'Use custom colors instead of theme button style.', 'commentgate' ); ?></label>
							<label><?php esc_html_e( 'Background', 'commentgate' ); ?> <input type="color" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[button_bg_color]" value="<?php echo esc_attr( ! empty( $options['button_bg_color'] ) ? $options['button_bg_color'] : '#111827' ); ?>"></label>
							<label class="commentgate-inline-color"><?php esc_html_e( 'Text', 'commentgate' ); ?> <input type="color" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[button_text_color]" value="<?php echo esc_attr( ! empty( $options['button_text_color'] ) ? $options['button_text_color'] : '#ffffff' ); ?>"></label>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( 'payment-api-configuration' === $tab ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Stripe docs', 'commentgate' ); ?></th><td><a href="https://docs.stripe.com/keys" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'How to find Stripe API keys', 'commentgate' ); ?></a></td></tr>
					<tr><th scope="row">Publishable key</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[stripe_publishable]" value="<?php echo esc_attr( $options['stripe_publishable'] ); ?>"></td></tr>
					<tr><th scope="row">Secret key</th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[stripe_secret]" value="<?php echo esc_attr( $options['stripe_secret'] ); ?>"></td></tr>
					<tr><th scope="row">Webhook secret</th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[stripe_webhook_secret]" value="<?php echo esc_attr( $options['stripe_webhook_secret'] ); ?>"><p class="description"><?php esc_html_e( 'Required for payment confirmation.', 'commentgate' ); ?> <?php echo esc_html( rest_url( 'commentgate/v1/stripe-webhook' ) ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'PayPal docs', 'commentgate' ); ?></th><td><a href="https://developer.paypal.com/api/rest/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'How to create PayPal REST API credentials', 'commentgate' ); ?></a></td></tr>
					<tr><th scope="row">Client ID</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[paypal_client_id]" value="<?php echo esc_attr( $options['paypal_client_id'] ); ?>"></td></tr>
					<tr><th scope="row">Secret</th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[paypal_secret]" value="<?php echo esc_attr( $options['paypal_secret'] ); ?>"></td></tr>
					<tr><th scope="row">Webhook ID</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[paypal_webhook_id]" value="<?php echo esc_attr( $options['paypal_webhook_id'] ); ?>"><p class="description"><?php esc_html_e( 'Required for webhook verification.', 'commentgate' ); ?> <?php echo esc_html( rest_url( 'commentgate/v1/paypal-webhook' ) ); ?></p></td></tr>
					<tr><th scope="row">Mode</th><td><select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[paypal_mode]"><option value="sandbox" <?php selected( $options['paypal_mode'], 'sandbox' ); ?>>Sandbox</option><option value="live" <?php selected( $options['paypal_mode'], 'live' ); ?>>Live</option></select></td></tr>
					<?php endif; ?>
				</table>
				<?php if ( 'email-settings' === $tab ) : ?>
					<?php $this->render_email_preview( $options ); ?>
				<?php endif; ?>
				<?php submit_button(); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the setup checklist showing which gateway credentials and site
	 * requirements are configured, on the Payment API Configuration tab.
	 *
	 * @param array $options Current settings.
	 */
	private function render_diagnostics( $options ) {
		$checks = array(
			array(
				'label' => __( 'Stripe publishable key saved', 'commentgate' ),
				'pass'  => ! empty( $options['stripe_publishable'] ),
			),
			array(
				'label' => __( 'Stripe secret key saved', 'commentgate' ),
				'pass'  => ! empty( $options['stripe_secret'] ),
			),
			array(
				'label' => __( 'Stripe webhook secret saved', 'commentgate' ),
				'pass'  => ! empty( $options['stripe_webhook_secret'] ),
			),
			array(
				'label' => __( 'PayPal client ID saved', 'commentgate' ),
				'pass'  => ! empty( $options['paypal_client_id'] ),
			),
			array(
				'label' => __( 'PayPal secret saved', 'commentgate' ),
				'pass'  => ! empty( $options['paypal_secret'] ),
			),
			array(
				'label' => __( 'PayPal webhook ID saved', 'commentgate' ),
				'pass'  => ! empty( $options['paypal_webhook_id'] ),
			),
			array(
				'label' => __( 'Site admin email is valid', 'commentgate' ),
				'pass'  => is_email( get_option( 'admin_email' ) ),
			),
			array(
				'label' => __( 'REST API webhook routes are available', 'commentgate' ),
				'pass'  => '' !== rest_url( 'commentgate/v1/stripe-webhook' ) && '' !== rest_url( 'commentgate/v1/paypal-webhook' ),
			),
		);
		?>
		<div class="commentgate-diagnostics">
			<h2><?php esc_html_e( 'Setup checklist', 'commentgate' ); ?></h2>
			<ul>
				<?php foreach ( $checks as $check ) : ?>
					<li class="<?php echo esc_attr( $check['pass'] ? 'is-pass' : 'is-missing' ); ?>">
						<span aria-hidden="true"><?php echo $check['pass'] ? esc_html__( 'Yes', 'commentgate' ) : esc_html__( 'No', 'commentgate' ); ?></span>
						<?php echo esc_html( $check['label'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p><?php esc_html_e( 'Use sandbox or test mode credentials first, complete one test payment, then confirm the transaction changes from pending to paid.', 'commentgate' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render a live preview of the payment and refund customer emails using
	 * sample placeholder data, on the Email Settings tab.
	 *
	 * @param array $options Current settings.
	 */
	private function render_email_preview( $options ) {
		$logo = '';
		if ( ! empty( $options['email_logo_url'] ) ) {
			$logo = sprintf(
				'<div style="padding:20px 24px 0;text-align:center;"><img src="%1$s" alt="%2$s" style="height:auto;max-height:64px;max-width:220px;"></div>',
				esc_url( $options['email_logo_url'] ),
				esc_attr( get_bloginfo( 'name' ) )
			);
		}

		$footer       = ! empty( $options['email_footer'] ) ? $options['email_footer'] : '';
		$replacements = array(
			'{amount}'     => 'USD 5.00',
			'{content}'    => __( 'Example paid discussion', 'commentgate' ),
			'{access}'     => __( '1 comment credit', 'commentgate' ),
			'{url}'        => home_url( '/example-paid-discussion/' ),
			'{payment_id}' => '123',
			'{refund_id}'  => 'R-123456',
			'{footer}'     => $footer,
		);

		$payment_body = strtr( ! empty( $options['payment_email_body'] ) ? $options['payment_email_body'] : $this->defaults()['payment_email_body'], $replacements );
		$refund_body  = strtr( ! empty( $options['refund_email_body'] ) ? $options['refund_email_body'] : $this->defaults()['refund_email_body'], $replacements );
		?>
		<div class="commentgate-email-preview">
			<h2><?php esc_html_e( 'Email preview', 'commentgate' ); ?></h2>
			<p><?php esc_html_e( 'Preview updates after saving changes.', 'commentgate' ); ?></p>
			<?php if ( 'html' === $options['email_format'] ) : ?>
				<?php $this->render_single_email_preview( __( 'Payment receipt', 'commentgate' ), $payment_body, $footer, $logo, false ); ?>
				<?php $this->render_single_email_preview( __( 'Refund receipt', 'commentgate' ), $refund_body, $footer, $logo, true ); ?>
			<?php else : ?>
				<?php $this->render_simple_email_preview( __( 'Payment receipt', 'commentgate' ), $payment_body, $footer, false ); ?>
				<?php $this->render_simple_email_preview( __( 'Refund receipt', 'commentgate' ), $refund_body, $footer, true ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the simple text-style email preview frame.
	 *
	 * @param string $title     Email title.
	 * @param string $body      Resolved template body text.
	 * @param string $footer    Resolved footer text.
	 * @param bool   $is_refund Whether this is the refund email preview.
	 */
	private function render_simple_email_preview( $title, $body, $footer, $is_refund ) {
		$lines = array(
			esc_html( $title ),
			'',
			wp_kses_post( nl2br( esc_html( $body ) ) ),
			'',
			esc_html__( 'Amount', 'commentgate' ) . ': USD 5.00',
			esc_html__( 'Content', 'commentgate' ) . ': ' . esc_html__( 'Example paid discussion', 'commentgate' ),
			esc_html__( 'Access', 'commentgate' ) . ': ' . esc_html__( '1 comment credit', 'commentgate' ),
			esc_html__( 'Payment ID', 'commentgate' ) . ': 123',
		);

		if ( $is_refund ) {
			$lines[] = esc_html__( 'Refund ID', 'commentgate' ) . ': R-123456';
			$lines[] = '';
			$lines[] = esc_html__( 'This payment has been refunded. If comment access is still needed, a new purchase is required.', 'commentgate' );
		} else {
			$lines[] = '';
			$lines[] = esc_html__( 'Open paid comment area:', 'commentgate' );
			$lines[] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( admin_url( 'admin-post.php?action=commentgate_access_link&post_id=123&payment_id=123&access_key=123:example' ) ),
				esc_html( home_url( '/example-paid-discussion/' ) )
			);
			$lines[] = '';
			$lines[] = esc_html__( 'You can reopen the paid comment area from this secure email link until your comment credit is used or your access expires.', 'commentgate' );
		}

		$lines[] = '';
		$lines[] = wp_kses_post( nl2br( esc_html( $footer ) ) );
		?>
		<div class="commentgate-email-preview-frame">
			<div class="commentgate-plain-email-preview"><?php echo wp_kses_post( implode( "<br>\n", $lines ) ); ?></div>
		</div>
		<?php
	}

	/**
	 * Render the HTML invoice-style email preview frame.
	 *
	 * @param string $title     Email title.
	 * @param string $body      Resolved template body text.
	 * @param string $footer    Resolved footer text.
	 * @param string $logo      Pre-rendered logo HTML, or an empty string.
	 * @param bool   $is_refund Whether this is the refund email preview.
	 */
	private function render_single_email_preview( $title, $body, $footer, $logo, $is_refund ) {
		?>
		<div class="commentgate-email-preview-frame">
			<div style="background:#f6f7f7;margin:0;padding:24px;font-family:Arial,sans-serif;color:#1d2327;">
				<div style="background:#ffffff;border:1px solid #dcdcde;border-radius:8px;margin:0 auto;max-width:640px;overflow:hidden;">
					<?php echo wp_kses_post( $logo ); ?>
					<div style="background:#1d2327;color:#ffffff;padding:20px 24px;"><h1 style="color:#ffffff!important;font-size:20px;line-height:1.3;margin:0;"><?php echo esc_html( $title ); ?></h1></div>
					<div style="padding:24px;">
						<p style="font-size:16px;line-height:1.6;margin:0 0 18px;"><?php echo wp_kses_post( nl2br( esc_html( $body ) ) ); ?></p>
						<table style="border-collapse:collapse;margin:18px 0;width:100%;"><tbody>
							<tr><th align="left" style="border-bottom:1px solid #dcdcde;padding:10px 0;"><?php esc_html_e( 'Amount', 'commentgate' ); ?></th><td align="right" style="border-bottom:1px solid #dcdcde;padding:10px 0;">USD 5.00</td></tr>
							<tr><th align="left" style="border-bottom:1px solid #dcdcde;padding:10px 0;"><?php esc_html_e( 'Content', 'commentgate' ); ?></th><td align="right" style="border-bottom:1px solid #dcdcde;padding:10px 0;"><?php esc_html_e( 'Example paid discussion', 'commentgate' ); ?></td></tr>
							<tr><th align="left" style="border-bottom:1px solid #dcdcde;padding:10px 0;"><?php esc_html_e( 'Access', 'commentgate' ); ?></th><td align="right" style="border-bottom:1px solid #dcdcde;padding:10px 0;"><?php esc_html_e( '1 comment credit', 'commentgate' ); ?></td></tr>
							<tr><th align="left" style="border-bottom:1px solid #dcdcde;padding:10px 0;"><?php esc_html_e( 'Payment ID', 'commentgate' ); ?></th><td align="right" style="border-bottom:1px solid #dcdcde;padding:10px 0;">123</td></tr>
							<?php if ( $is_refund ) : ?>
								<tr><th align="left" style="border-bottom:1px solid #dcdcde;padding:10px 0;"><?php esc_html_e( 'Refund ID', 'commentgate' ); ?></th><td align="right" style="border-bottom:1px solid #dcdcde;padding:10px 0;">R-123456</td></tr>
							<?php endif; ?>
						</tbody></table>
						<?php if ( ! $is_refund ) : ?>
							<p style="margin:24px 0;"><a href="#" style="background:#1d2327;border-radius:4px;color:#ffffff;display:inline-block;font-weight:600;padding:12px 18px;text-decoration:none;"><?php esc_html_e( 'Open paid comment area', 'commentgate' ); ?></a></p>
							<p style="background:#fff8e5;border-left:4px solid #dba617;margin:18px 0;padding:12px;"><?php esc_html_e( 'You can reopen the paid comment area from this secure email link until your comment credit is used or your access expires.', 'commentgate' ); ?></p>
						<?php else : ?>
							<p style="background:#fcf0f1;border-left:4px solid #d63638;margin:18px 0;padding:12px;"><?php esc_html_e( 'This payment has been refunded. If comment access is still needed, a new purchase is required.', 'commentgate' ); ?></p>
						<?php endif; ?>
					</div>
					<div style="background:#f0f0f1;color:#646970;font-size:12px;line-height:1.5;padding:16px 24px;text-align:center;"><?php echo wp_kses_post( nl2br( esc_html( $footer ) ) ); ?></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Add the CommentGate meta box to the editor sidebar of every public post type.
	 */
	public function add_meta_boxes() {
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			add_meta_box( 'commentgate', __( 'CommentGate', 'commentgate' ), array( $this, 'render_meta_box' ), $post_type, 'side' );
		}
	}

	/**
	 * Render the per-post CommentGate override fields (payment mode, price,
	 * access type, comment quantity) in the editor meta box.
	 *
	 * @param WP_Post $post Current post being edited.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'commentgate_save_meta', 'commentgate_meta_nonce' );

		$mode             = get_post_meta( $post->ID, '_commentgate_mode', true );
		$price            = get_post_meta( $post->ID, '_commentgate_price', true );
		$access_type      = get_post_meta( $post->ID, '_commentgate_access_type', true );
		$comment_quantity = get_post_meta( $post->ID, '_commentgate_comment_quantity', true );
		?>
		<p>
			<label for="commentgate_mode"><?php esc_html_e( 'Payment requirement', 'commentgate' ); ?></label>
			<select id="commentgate_mode" name="commentgate_mode" class="widefat">
				<option value="inherit" <?php selected( $mode, 'inherit' ); ?>><?php esc_html_e( 'Use global setting', 'commentgate' ); ?></option>
				<option value="enabled" <?php selected( $mode, 'enabled' ); ?>><?php esc_html_e( 'Require payment', 'commentgate' ); ?></option>
				<option value="disabled" <?php selected( $mode, 'disabled' ); ?>><?php esc_html_e( 'Free comments', 'commentgate' ); ?></option>
			</select>
		</p>
		<p>
			<label for="commentgate_price"><?php esc_html_e( 'Custom price', 'commentgate' ); ?></label>
			<input id="commentgate_price" name="commentgate_price" type="text" class="widefat" value="<?php echo esc_attr( $price ); ?>" placeholder="<?php echo esc_attr( $this->get( 'price' ) ); ?>">
		</p>
		<p>
			<label for="commentgate_access_type"><?php esc_html_e( 'Custom access type', 'commentgate' ); ?></label>
			<select id="commentgate_access_type" name="commentgate_access_type" class="widefat">
				<option value="inherit" <?php selected( $access_type, 'inherit' ); ?>><?php esc_html_e( 'Use global setting', 'commentgate' ); ?></option>
				<option value="duration" <?php selected( $access_type, 'duration' ); ?>><?php esc_html_e( 'Duration based', 'commentgate' ); ?></option>
				<option value="comments" <?php selected( $access_type, 'comments' ); ?>><?php esc_html_e( 'Comment quantity based', 'commentgate' ); ?></option>
			</select>
		</p>
		<p>
			<label for="commentgate_comment_quantity"><?php esc_html_e( 'Custom comments per purchase', 'commentgate' ); ?></label>
			<input id="commentgate_comment_quantity" name="commentgate_comment_quantity" type="number" min="1" class="widefat" value="<?php echo esc_attr( $comment_quantity ); ?>" placeholder="<?php echo esc_attr( $this->get( 'comment_quantity' ) ); ?>">
		</p>
		<?php
	}

	/**
	 * Save the per-post CommentGate override meta from the editor meta box.
	 * Verifies the meta box nonce, skips autosaves, and checks the current
	 * user can edit the post before writing anything.
	 *
	 * @param int $post_id Post being saved.
	 */
	public function save_post_meta( $post_id ) {
		if ( ! isset( $_POST['commentgate_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['commentgate_meta_nonce'] ) ), 'commentgate_save_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$mode = sanitize_key( wp_unslash( $_POST['commentgate_mode'] ?? 'inherit' ) );
		if ( ! in_array( $mode, array( 'inherit', 'enabled', 'disabled' ), true ) ) {
			$mode = 'inherit';
		}

		update_post_meta( $post_id, '_commentgate_mode', $mode );

		$price = sanitize_text_field( wp_unslash( $_POST['commentgate_price'] ?? '' ) );
		if ( '' === $price ) {
			delete_post_meta( $post_id, '_commentgate_price' );
		} else {
			update_post_meta( $post_id, '_commentgate_price', $this->sanitize_price( $price ) );
		}

		$access_type = sanitize_key( wp_unslash( $_POST['commentgate_access_type'] ?? 'inherit' ) );
		if ( ! in_array( $access_type, array( 'inherit', 'duration', 'comments' ), true ) || 'inherit' === $access_type ) {
			delete_post_meta( $post_id, '_commentgate_access_type' );
		} else {
			update_post_meta( $post_id, '_commentgate_access_type', $access_type );
		}

		$comment_quantity = absint( wp_unslash( $_POST['commentgate_comment_quantity'] ?? '' ) );
		if ( ! $comment_quantity ) {
			delete_post_meta( $post_id, '_commentgate_comment_quantity' );
		} else {
			update_post_meta( $post_id, '_commentgate_comment_quantity', max( 1, $comment_quantity ) );
		}
	}
}
