<?php
/**
 * Admin payments screen.
 *
 * @package CommentGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

class CommentGate_Admin_Payments {
	private $payments;
	private $stripe;
	private $paypal;

	/**
	 * Constructor.
	 *
	 * @param CommentGate_Payments_Table  $payments Payment persistence.
	 * @param CommentGate_Stripe_Gateway  $stripe   Stripe gateway instance.
	 * @param CommentGate_PayPal_Gateway  $paypal   PayPal gateway instance.
	 */
	public function __construct( $payments, $stripe, $paypal ) {
		$this->payments = $payments;
		$this->stripe   = $stripe;
		$this->paypal   = $paypal;
	}

	/**
	 * Wire up the admin-post handlers for row/bulk payment actions and CSV export.
	 */
	public function register() {
		add_action( 'admin_post_commentgate_payments_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_commentgate_export_payments', array( $this, 'export_csv' ) );
	}

	/**
	 * Handle a row or bulk payment action (mark pending, refund, delete) from
	 * the Transaction History screen. Requires manage_options and a valid
	 * nonce; redirects back to the transaction history tab and exits.
	 */
	public function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage CommentGate payments.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 403 ) );
		}

		check_admin_referer( 'commentgate_payments_action' );

		$ids    = array_map( 'absint', (array) ( $_POST['payment_ids'] ?? array() ) );
		$action = sanitize_key( wp_unslash( $_POST['payment_action'] ?? '' ) );

		if ( 'bulk' !== $action && isset( $_POST['payment_id'] ) ) {
			$ids = array( absint( $_POST['payment_id'] ) );
		}

		if ( 'bulk' === $action ) {
			$action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) );
		}

		if ( 'mark_pending' === $action ) {
			$this->payments->update_status( $ids, 'pending' );
		}

		if ( 'refund' === $action ) {
			$this->refund_payment( reset( $ids ) );
		}

		if ( 'delete' === $action ) {
			$this->payments->delete( $ids );
		}

		wp_safe_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'page'    => 'commentgate',
						'tab'     => 'transaction-history',
						'updated' => '1',
					),
					admin_url( 'edit-comments.php' )
				)
			)
		);
		exit;
	}

	/**
	 * Render the Transaction History admin screen: summary cards, filters,
	 * paginated payment table, and bulk action form. Gated on manage_options.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$filters  = $this->get_filters();
		$paged    = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 25;
		$total    = $this->get_payment_count( $filters );
		$rows     = $this->get_payment_rows( $filters, $per_page, ( $paged - 1 ) * $per_page );
		$summary  = $this->get_summary( $filters );
		?>
			<h2><?php esc_html_e( 'Transaction History', 'commentgate' ); ?></h2>
			<?php $this->render_summary_cards( $summary ); ?>
			<?php $this->render_filters( $filters ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="commentgate_payments_action">
				<input type="hidden" name="payment_action" value="bulk">
				<?php wp_nonce_field( 'commentgate_payments_action' ); ?>
				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<label for="commentgate_bulk_action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'commentgate' ); ?></label>
						<select id="commentgate_bulk_action" name="bulk_action">
							<option value=""><?php esc_html_e( 'Bulk actions', 'commentgate' ); ?></option>
							<option value="mark_pending"><?php esc_html_e( 'Mark as pending', 'commentgate' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete', 'commentgate' ); ?></option>
						</select>
						<?php submit_button( __( 'Apply', 'commentgate' ), 'action', '', false ); ?>
					</div>
				</div>
				<table class="widefat striped">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column"><input type="checkbox" id="commentgate-select-all"></td>
							<th>ID</th>
							<th><?php esc_html_e( 'Post', 'commentgate' ); ?></th>
							<th><?php esc_html_e( 'Gateway', 'commentgate' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'commentgate' ); ?></th>
							<th><?php esc_html_e( 'Access', 'commentgate' ); ?></th>
							<th><?php esc_html_e( 'Status', 'commentgate' ); ?></th>
							<th><?php esc_html_e( 'User', 'commentgate' ); ?></th>
							<th><?php esc_html_e( 'Created', 'commentgate' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'commentgate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( $rows ) : ?>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<th scope="row" class="check-column"><input type="checkbox" name="payment_ids[]" value="<?php echo esc_attr( $row->id ); ?>"></th>
									<td><?php echo esc_html( $row->id ); ?></td>
									<td><a href="<?php echo esc_url( get_edit_post_link( $row->post_id ) ); ?>"><?php echo esc_html( get_the_title( $row->post_id ) ); ?></a></td>
									<td><?php echo esc_html( ucfirst( $row->gateway ) ); ?></td>
									<td><?php echo esc_html( $row->currency . ' ' . $row->amount ); ?></td>
									<td>
										<?php
										if ( 'comments' === $row->access_type ) {
											printf(
												/* translators: 1: remaining comments, 2: total comments */
												esc_html__( '%1$d of %2$d comments left', 'commentgate' ),
												absint( $row->comments_remaining ),
												absint( $row->comment_limit )
											);
										} else {
											esc_html_e( 'Duration', 'commentgate' );
										}
										?>
									</td>
									<td><span class="commentgate-status commentgate-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status ); ?></span></td>
									<td><?php echo $row->user_id ? esc_html( get_the_author_meta( 'user_login', $row->user_id ) ) : esc_html( $row->guest_email ); ?></td>
									<td><?php echo esc_html( $row->created_at ); ?></td>
									<td class="commentgate-row-actions">
										<?php if ( $this->payments->is_unused_refundable( $row ) ) : ?>
											<button type="submit" class="button button-small commentgate-row-action" name="payment_action" value="refund" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-payment-id="<?php echo esc_attr( $row->id ); ?>" data-confirm="<?php echo esc_attr__( 'Refund this payment? This should only be used when access has not been used.', 'commentgate' ); ?>"><?php esc_html_e( 'Refund', 'commentgate' ); ?></button>
										<?php endif; ?>
										<button type="submit" class="button button-small button-link-delete commentgate-row-action" name="payment_action" value="delete" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-payment-id="<?php echo esc_attr( $row->id ); ?>" data-confirm="<?php echo esc_attr__( 'Delete this payment record?', 'commentgate' ); ?>"><?php esc_html_e( 'Delete', 'commentgate' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="10"><?php esc_html_e( 'No payments yet.', 'commentgate' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<?php $this->render_pagination( $filters, $paged, $per_page, $total ); ?>
				<input type="hidden" name="payment_id" value="">
			</form>
		<?php
	}

	/**
	 * Refund a payment through its originating gateway (Stripe or PayPal) and
	 * mark it refunded locally. Calls wp_die() if the gateway returns an error;
	 * silently no-ops if the payment is not eligible for refund.
	 *
	 * @param int $payment_id Payment record ID.
	 */
	private function refund_payment( $payment_id ) {
		$payment = $this->payments->find_by_id( absint( $payment_id ) );
		if ( ! $this->payments->is_unused_refundable( $payment ) ) {
			return;
		}

		if ( 'stripe' === $payment->gateway ) {
			$result = $this->stripe->refund_payment( $payment );
		} elseif ( 'paypal' === $payment->gateway ) {
			$result = $this->paypal->refund_payment( $payment );
		} else {
			return;
		}

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'CommentGate refund error', 'commentgate' ), array( 'response' => 400 ) );
		}

		$refund_id = is_string( $result ) ? $result : '';
		$this->payments->mark_refunded( $payment->id, $refund_id, 'admin_refund' );
	}

	/**
	 * Read and sanitize the status/gateway/date-range filters from the query string.
	 *
	 * @return array
	 */
	private function get_filters() {
		$status = sanitize_key( wp_unslash( $_GET['payment_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $status, array( 'paid', 'pending', 'refunded' ), true ) ) {
			$status = '';
		}

		$gateway = sanitize_key( wp_unslash( $_GET['payment_gateway'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $gateway, array( 'stripe', 'paypal' ), true ) ) {
			$gateway = '';
		}

		$date_from = $this->sanitize_date( sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to   = $this->sanitize_date( sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $date_from && $date_to && $date_from > $date_to ) {
			$swap      = $date_from;
			$date_from = $date_to;
			$date_to   = $swap;
		}

		return array(
			'status'    => $status,
			'gateway'   => $gateway,
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);
	}

	/**
	 * Sanitize a Y-m-d date string, rejecting malformed or invalid calendar dates.
	 *
	 * @param string $date Raw date input.
	 * @return string Empty string if invalid.
	 */
	private function sanitize_date( $date ) {
		$date = sanitize_text_field( $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$parts = array_map( 'absint', explode( '-', $date ) );
		return checkdate( $parts[1], $parts[2], $parts[0] ) ? $date : '';
	}

	/**
	 * Build a prepared WHERE clause and its bound params from the active filters.
	 *
	 * @param array $filters        Filters from get_filters().
	 * @param bool  $include_status Whether to include the status filter (false lets callers append their own status condition).
	 * @return array {
	 *     @type string $sql    WHERE clause SQL with %s/%d placeholders.
	 *     @type array  $params Values to bind via $wpdb->prepare().
	 * }
	 */
	private function build_where_clause( $filters, $include_status = true ) {
		$where  = array( '1 = %d' );
		$params = array( 1 );

		if ( $include_status && $filters['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}

		if ( $filters['gateway'] ) {
			$where[]  = 'gateway = %s';
			$params[] = $filters['gateway'];
		}

		if ( $filters['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['date_from'] . ' 00:00:00';
		}

		if ( $filters['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['date_to'] . ' 23:59:59';
		}

		return array(
			'sql'    => ' WHERE ' . implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	/**
	 * Fetch a page of payment rows matching the active filters, newest first.
	 *
	 * @param array $filters Filters from get_filters().
	 * @param int   $limit   Max rows to return.
	 * @param int   $offset  Row offset for pagination.
	 * @return array
	 */
	private function get_payment_rows( $filters, $limit = 25, $offset = 0 ) {
		global $wpdb;

		$table             = CommentGate_Payments_Table::table_name();
		$where             = $this->build_where_clause( $filters );
		$sql               = "SELECT * FROM {$table}{$where['sql']} ORDER BY id DESC LIMIT %d OFFSET %d";
		$where['params'][] = max( 1, absint( $limit ) );
		$where['params'][] = max( 0, absint( $offset ) );

		return $wpdb->get_results( $wpdb->prepare( $sql, $where['params'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count payment rows matching the active filters, for pagination.
	 *
	 * @param array $filters Filters from get_filters().
	 * @return int
	 */
	private function get_payment_count( $filters ) {
		global $wpdb;

		$table = CommentGate_Payments_Table::table_name();
		$where = $this->build_where_clause( $filters );

		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$where['sql']}", $where['params'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Build the summary card data: status counts, paid/pending totals per
	 * currency, and remaining comment credits, for the active filters.
	 *
	 * @param array $filters Filters from get_filters().
	 * @return array
	 */
	private function get_summary( $filters ) {
		global $wpdb;

		$table           = CommentGate_Payments_Table::table_name();
		$filtered_where  = $this->build_where_clause( $filters );
		$earnings_where  = $this->build_where_clause( $filters, false );
		$earnings_params = array_merge( $earnings_where['params'], array( 'paid' ) );
		$pending_params  = array_merge( $earnings_where['params'], array( 'pending' ) );

		$status_counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS total FROM {$table}{$filtered_where['sql']} GROUP BY status",
				$filtered_where['params']
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$paid_totals = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT currency, SUM(amount) AS total FROM {$table}{$earnings_where['sql']} AND status = %s GROUP BY currency ORDER BY currency ASC",
				$earnings_params
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$pending_totals = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT currency, SUM(amount) AS total FROM {$table}{$earnings_where['sql']} AND status = %s GROUP BY currency ORDER BY currency ASC",
				$pending_params
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$comments_remaining = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(comments_remaining) FROM {$table}{$filtered_where['sql']} AND access_type = 'comments' AND status = 'paid'",
				$filtered_where['params']
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'status_counts'      => $status_counts,
			'paid_totals'        => $paid_totals,
			'pending_totals'     => $pending_totals,
			'comments_remaining' => absint( $comments_remaining ),
		);
	}

	/**
	 * Render the summary cards (earnings, pending value, transaction counts,
	 * comment credits left) at the top of the Transaction History screen.
	 *
	 * @param array $summary Summary data from get_summary().
	 */
	private function render_summary_cards( $summary ) {
		$counts = array(
			'paid'     => 0,
			'pending'  => 0,
			'refunded' => 0,
		);

		foreach ( (array) $summary['status_counts'] as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = absint( $row->total );
			}
		}
		?>
		<div class="commentgate-summary-grid">
			<div class="commentgate-summary-card">
				<span><?php esc_html_e( 'Paid earnings', 'commentgate' ); ?></span>
				<strong><?php echo esc_html( $this->format_currency_totals( $summary['paid_totals'] ) ); ?></strong>
			</div>
			<div class="commentgate-summary-card">
				<span><?php esc_html_e( 'Pending value', 'commentgate' ); ?></span>
				<strong><?php echo esc_html( $this->format_currency_totals( $summary['pending_totals'] ) ); ?></strong>
			</div>
			<div class="commentgate-summary-card">
				<span><?php esc_html_e( 'Transactions', 'commentgate' ); ?></span>
				<strong>
					<?php
					printf(
						/* translators: 1: paid count, 2: pending count, 3: refunded count */
						esc_html__( '%1$d paid / %2$d pending / %3$d refunded', 'commentgate' ),
						absint( $counts['paid'] ),
						absint( $counts['pending'] ),
						absint( $counts['refunded'] )
					);
					?>
				</strong>
			</div>
			<div class="commentgate-summary-card">
				<span><?php esc_html_e( 'Comment credits left', 'commentgate' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( $summary['comments_remaining'] ) ); ?></strong>
			</div>
		</div>
		<?php
	}

	/**
	 * Format a list of per-currency totals into a "USD 12.00 / EUR 5.00" string.
	 *
	 * @param array $totals Rows with currency and total properties.
	 * @return string
	 */
	private function format_currency_totals( $totals ) {
		$parts = array();

		foreach ( (array) $totals as $total ) {
			$parts[] = sprintf(
				'%s %s',
				sanitize_text_field( $total->currency ),
				number_format_i18n( (float) $total->total, 2 )
			);
		}

		return $parts ? implode( ' / ', $parts ) : __( 'None', 'commentgate' );
	}

	/**
	 * Render the status/gateway/date-range filter form and the CSV export link.
	 *
	 * @param array $filters Current filters from get_filters().
	 */
	private function render_filters( $filters ) {
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'          => 'commentgate_export_payments',
					'payment_status'  => $filters['status'],
					'payment_gateway' => $filters['gateway'],
					'date_from'       => $filters['date_from'],
					'date_to'         => $filters['date_to'],
				),
				admin_url( 'admin-post.php' )
			),
			'commentgate_export_payments'
		);
		?>
		<form class="commentgate-filters" method="get" action="<?php echo esc_url( admin_url( 'edit-comments.php' ) ); ?>">
			<input type="hidden" name="page" value="commentgate">
			<input type="hidden" name="tab" value="transaction-history">
			<label for="commentgate_payment_status"><?php esc_html_e( 'Status', 'commentgate' ); ?></label>
			<select id="commentgate_payment_status" name="payment_status">
				<option value=""><?php esc_html_e( 'All statuses', 'commentgate' ); ?></option>
				<option value="paid" <?php selected( $filters['status'], 'paid' ); ?>><?php esc_html_e( 'Paid', 'commentgate' ); ?></option>
				<option value="pending" <?php selected( $filters['status'], 'pending' ); ?>><?php esc_html_e( 'Pending', 'commentgate' ); ?></option>
				<option value="refunded" <?php selected( $filters['status'], 'refunded' ); ?>><?php esc_html_e( 'Refunded', 'commentgate' ); ?></option>
			</select>
			<label for="commentgate_payment_gateway"><?php esc_html_e( 'Gateway', 'commentgate' ); ?></label>
			<select id="commentgate_payment_gateway" name="payment_gateway">
				<option value=""><?php esc_html_e( 'All gateways', 'commentgate' ); ?></option>
				<option value="stripe" <?php selected( $filters['gateway'], 'stripe' ); ?>>Stripe</option>
				<option value="paypal" <?php selected( $filters['gateway'], 'paypal' ); ?>>PayPal</option>
			</select>
			<label for="commentgate_date_from"><?php esc_html_e( 'From', 'commentgate' ); ?></label>
			<input id="commentgate_date_from" type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
			<label for="commentgate_date_to"><?php esc_html_e( 'To', 'commentgate' ); ?></label>
			<input id="commentgate_date_to" type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
			<?php submit_button( __( 'Filter', 'commentgate' ), 'secondary', '', false ); ?>
			<a class="button button-secondary" href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page' => 'commentgate',
						'tab'  => 'transaction-history',
					),
					admin_url( 'edit-comments.php' )
				)
			);
			?>
														"><?php esc_html_e( 'Clear', 'commentgate' ); ?></a>
			<a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'commentgate' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Render the pagination links, preserving the active filters in each page's URL.
	 *
	 * @param array $filters  Current filters from get_filters().
	 * @param int   $paged    Current page number.
	 * @param int   $per_page Rows per page.
	 * @param int   $total    Total matching rows.
	 */
	private function render_pagination( $filters, $paged, $per_page, $total ) {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $total_pages < 2 ) {
			return;
		}

		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		for ( $page = 1; $page <= $total_pages; $page++ ) {
			$url = add_query_arg(
				array(
					'page'            => 'commentgate',
					'tab'             => 'transaction-history',
					'paged'           => $page,
					'payment_status'  => $filters['status'],
					'payment_gateway' => $filters['gateway'],
					'date_from'       => $filters['date_from'],
					'date_to'         => $filters['date_to'],
				),
				admin_url( 'edit-comments.php' )
			);
			printf(
				'<a class="button %1$s" href="%2$s">%3$d</a> ',
				esc_attr( $page === (int) $paged ? 'button-primary' : 'button-secondary' ),
				esc_url( $url ),
				absint( $page )
			);
		}
		echo '</div></div>';
	}

	/**
	 * Stream the filtered payment rows as a CSV download. Requires
	 * manage_options and a valid nonce; exits after writing output.
	 */
	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export CommentGate payments.', 'commentgate' ), esc_html__( 'CommentGate error', 'commentgate' ), array( 'response' => 403 ) );
		}

		check_admin_referer( 'commentgate_export_payments' );

		$filters = $this->get_filters();
		$rows    = $this->get_payment_rows( $filters, 5000, 0 );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=commentgate-transactions.csv' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $output, array( 'ID', 'Post ID', 'Post Title', 'Gateway', 'Amount', 'Currency', 'Status', 'User ID', 'Email', 'Access Type', 'Comment Limit', 'Comments Remaining', 'Created', 'Paid', 'Refunded', 'Refund ID', 'Refund Reason' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array_map(
					array( $this, 'csv_cell' ),
					array(
						$row->id,
						$row->post_id,
						get_the_title( $row->post_id ),
						$row->gateway,
						$row->amount,
						$row->currency,
						$row->status,
						$row->user_id,
						$row->guest_email,
						$row->access_type,
						$row->comment_limit,
						$row->comments_remaining,
						$row->created_at,
						$row->paid_at,
						$row->refunded_at,
						$row->refund_id,
						$row->refund_reason,
					)
				)
			);
		}
		exit;
	}

	/**
	 * Prefix a CSV cell value with a single quote if it starts with a formula
	 * trigger character, preventing CSV/formula injection when opened in spreadsheet software.
	 *
	 * @param mixed $value Raw cell value.
	 * @return string
	 */
	private function csv_cell( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^[=\-+@]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
