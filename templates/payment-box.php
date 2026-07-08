<?php
/**
 * Payment box template.
 *
 * @package CommentGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$commentgate_button_style = '';
if ( ! empty( $button_bg_color ) ) {
	$commentgate_button_style .= 'background-color: ' . esc_attr( $button_bg_color ) . ';';
}
if ( ! empty( $button_text_color ) ) {
	$commentgate_button_style .= ' color: ' . esc_attr( $button_text_color ) . ';';
}

$commentgate_payment_notice = isset( $_GET['commentgate'] ) ? sanitize_key( wp_unslash( $_GET['commentgate'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="commentgate-box">
	<p class="commentgate-price">
		<?php
		if ( 'comments' === $access_type ) {
			printf(
				/* translators: 1: currency, 2: price, 3: comment quantity */
				esc_html__( 'Pay %1$s %2$s to unlock %3$d comment(s) on this content.', 'commentgate' ),
				esc_html( $currency ),
				esc_html( $price ),
				absint( $comment_quantity )
			);
		} else {
			printf(
				/* translators: 1: currency, 2: price */
				esc_html__( 'Pay %1$s %2$s to unlock comments on this content.', 'commentgate' ),
				esc_html( $currency ),
				esc_html( $price )
			);
		}
		?>
	</p>
	<?php if ( 'pending' === $commentgate_payment_notice ) : ?>
		<p class="commentgate-notice"><?php esc_html_e( 'Payment pending. Comment access unlocks after gateway confirmation. If this does not update, ask the site owner to check the payment webhook setup.', 'commentgate' ); ?></p>
	<?php endif; ?>
	<?php if ( 'paid' === $commentgate_payment_notice ) : ?>
		<p class="commentgate-notice"><?php esc_html_e( 'Payment complete. Refresh if the comment form does not appear.', 'commentgate' ); ?></p>
	<?php endif; ?>
	<?php if ( 'failed' === $commentgate_payment_notice ) : ?>
		<p class="commentgate-notice commentgate-notice-error"><?php esc_html_e( 'Payment was not completed. Please try again or contact the site owner.', 'commentgate' ); ?></p>
	<?php endif; ?>
	<div class="commentgate-form">
		<input type="hidden" name="action" value="commentgate_start_payment">
		<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
		<?php wp_nonce_field( 'commentgate_start_payment_' . $post_id ); ?>

		<?php if ( ! is_user_logged_in() ) : ?>
			<label class="commentgate-email">
				<span><?php esc_html_e( 'Email for receipt/access', 'commentgate' ); ?></span>
				<input type="email" name="guest_email" required>
			</label>
		<?php endif; ?>

		<?php foreach ( array_values( $gateways ) as $commentgate_index => $commentgate_gateway ) : ?>
			<?php if ( 0 < $commentgate_index ) : ?>
				<div class="commentgate-gateway-divider" role="separator" aria-label="<?php esc_attr_e( 'or', 'commentgate' ); ?>">
					<span class="commentgate-gateway-divider-line" aria-hidden="true"></span>
					<span class="commentgate-gateway-divider-text"><?php esc_html_e( 'Or', 'commentgate' ); ?></span>
					<span class="commentgate-gateway-divider-line" aria-hidden="true"></span>
				</div>
			<?php endif; ?>
			<button type="submit" name="gateway" value="<?php echo esc_attr( $commentgate_gateway ); ?>" class="button wp-block-button__link commentgate-button" formmethod="post" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php echo $commentgate_button_style ? 'style="' . esc_attr( $commentgate_button_style ) . '"' : ''; ?>>
				<span><?php echo 'stripe' === $commentgate_gateway ? esc_html( $stripe_text ) : esc_html( $paypal_text ); ?></span>
			</button>
		<?php endforeach; ?>
	</div>
</div>
