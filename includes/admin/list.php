<?php

/**
 * Add Commissions link
 * *
 * @access      private
 * @since       1.0
 * @return      void
*/

function eddc_add_commissions_link() {
	global $edd_commissions_page;

	$edd_commissions_page = add_submenu_page('edit.php?post_type=download', __('Easy Digital Download Commissions', 'eddc'), __('Commissions', 'eddc'), 'manage_options', 'edd-commissions', 'edd_commissions_page');
}
add_action('admin_menu', 'eddc_add_commissions_link', 10);


function edd_commissions_page() {

	$js_dir = EDD_PLUGIN_URL . 'assets/js/';
	$css_dir = EDD_PLUGIN_URL . 'assets/css/';

	// Use minified libraries if SCRIPT_DEBUG is turned off
	$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
	wp_enqueue_script( 'jquery-ui-datepicker' );
	wp_register_script( 'eddc-admin-scripts', EDDC_PLUGIN_URL . 'assets/js/admin-scripts' . $suffix . '.js', array( 'jquery' ), EDD_COMMISSIONS_VERSION, true );
	wp_enqueue_script( 'eddc-admin-scripts' );

	$ui_style = ( 'classic' == get_user_option( 'admin_color' ) ) ? 'classic' : 'fresh';
	wp_enqueue_style( 'jquery-ui-css', $css_dir . 'jquery-ui-' . $ui_style . $suffix . '.css' );
	wp_enqueue_style( 'eddc-admin-styles', EDDC_PLUGIN_URL . 'assets/css/admin-styles' . $suffix . '.css', EDD_COMMISSIONS_VERSION );
	?>
	<div class="wrap">

		<?php

		if( isset( $_GET['action'] ) && $_GET['action'] == 'edit' ) {

			include( EDDC_PLUGIN_DIR . 'includes/admin/edit.php' );

		} elseif( isset( $_GET['action'] ) && $_GET['action'] == 'add' ) {

			include( EDDC_PLUGIN_DIR . 'includes/admin/add.php' );

		} else {

			$commissions_table = new EDD_C_List_Table();

			//Fetch, prepare, sort, and filter our data...
			$commissions_table->prepare_items();

			$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;

			$total_unpaid = edd_currency_filter( edd_format_amount( eddc_get_unpaid_totals( $user_id ) ) );

			?>

			<h2>
				<?php _e('Easy Digital Download Commissions', 'eddc'); ?> -  <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=download&page=edd-commissions&action=add' ) ); ?>" class="add-new-h2"><?php _e( 'Add Commission', 'eddc' ); ?></a>
			</h2>
			<div id="edd-commissions-export-wrap">
				<button class="button-primary eddc-commissions-export-toggle"><?php _e( 'Generate Payout File', 'eddc' ); ?></button>
				<button class="button-primary eddc-commissions-export-toggle" style="display:none"><?php _e( 'Close', 'eddc' ); ?></button>

				<?php do_action( 'eddc_commissions_page_buttons' ); ?>

				<form id="eddc-export-commissions" class="edd-export-form" method="post" style="display:none;">
					<?php echo EDD()->html->date_field( array( 'id' => 'edd-payment-export-start', 'name' => 'start', 'placeholder' => __( 'Choose start date', 'eddc' ) ) ); ?>
					<?php echo EDD()->html->date_field( array( 'id' => 'edd-payment-export-end','name' => 'end', 'placeholder' => __( 'Choose end date', 'eddc' ) ) ); ?>
					<input type="number" increment="0.01" class="eddc-medium-text" id="minimum" name="minimum" placeholder=" <?php _e( 'Minimum', 'eddc' ); ?>" />
					<?php wp_nonce_field( 'edd_ajax_export', 'edd_ajax_export' ); ?>
					<input type="hidden" name="edd-export-class" value="EDD_Batch_Commissions_Payout"/>
					<span>
						<input type="submit" value="<?php _e( 'Generate File', 'eddc' ); ?>" class="button-secondary"/>
						<span class="spinner"></span>
					</span>
				</form>

			</div>

			<form id="commissions-filter" method="get">

				<input type="hidden" name="post_type" value="download" />
				<input type="hidden" name="page" value="edd-commissions" />
				<!-- Now we can render the completed list table -->
				<?php $commissions_table->views() ?>

				<?php $commissions_table->display() ?>
			</form>

			<div class="commission-totals">
				<?php _e('Total Unpaid:', 'eddc'); ?>&nbsp;<strong><?php echo $total_unpaid; ?></strong>
			</div>
			<?php
		}
		?>
	</div>
	<?php

}

/**
 * Update a Commission
 *
 * @access      private
 * @since       1.2.0
 * @return      void
 */

function eddc_update_commission( $data ) {
	if ( wp_verify_nonce( $data['eddc_edit_nonce'], 'eddc_edit_nonce' ) ) {

		$id = absint( $data['commission'] );

		$commission_data = get_post_meta( $id, '_edd_commission_info', true );

		$rate = str_replace( '%', '', $data['rate'] );
		if ( $rate < 1 )
			$rate = $rate * 100;

		$amount = str_replace( '%', '', $data['amount'] );

		$commission_data['rate'] = (float)$rate;
		$commission_data['amount'] = (float) $amount;
		$commission_data['user_id'] = absint( $data['user_id'] );

		update_post_meta( $id, '_edd_commission_info', $commission_data );
		update_post_meta( $id, '_user_id', absint( $data['user_id'] ) );
		update_post_meta( $id, '_download_id', absint( $data['download_id'] ) );

		wp_redirect( admin_url( 'edit.php?post_type=download&page=edd-commissions' ) ); exit;

	}
}
add_action( 'edd_edit_commission', 'eddc_update_commission' );


/**
 * Add a Commission
 *
 * @access      private
 * @since       2.9
 * @return      void
 */

function eddc_add_manual_commission( $data ) {

	if ( ! wp_verify_nonce( $data['eddc_add_nonce'], 'eddc_add_nonce' ) ) {
		return;
	}

	if( ! current_user_can( 'edit_shop_payments' ) ) {
		wp_die( __( 'You do not have permission to record commissions', 'eddc' ) );
	}

	$user_info   = get_userdata( $data['user_id'] );
	$download_id = absint( $data['download_id'] );
	$payment_id  = isset( $data['payment_id'] ) ? absint( $data['payment_id'] ) : 0;
	$amount      = edd_sanitize_amount( $data['amount'] );
	$rate        = sanitize_text_field( $data['rate'] );

	$commission = array(
		'post_type'     => 'edd_commission',
		'post_title'    => $user_info->user_email . ' - ' . get_the_title( $download_id ),
		'post_status'   => 'publish'
	);

	$commission_id = wp_insert_post( apply_filters( 'edd_commission_post_data', $commission ) );

	$commission_info = apply_filters( 'edd_commission_info', array(
		'user_id'   => absint( $data['user_id'] ),
		'rate'      => $rate,
		'amount'    => $amount,
		'currency'  => edd_get_currency()
	), $commission_id, $payment_id, $download_id );

	eddc_set_commission_status( $commission_id, 'unpaid' );

	update_post_meta( $commission_id, '_edd_commission_info', $commission_info );
	update_post_meta( $commission_id, '_download_id', $download_id );
	update_post_meta( $commission_id, '_user_id', absint( $data['user_id'] ) );
	update_post_meta( $commission_id, '_edd_commission_payment_id', $payment_id );

	do_action( 'eddc_insert_commission', absint( $data['user_id'] ), $amount, $rate, $download_id, $commission_id, $payment_id );

	wp_redirect( admin_url( 'edit.php?post_type=download&page=edd-commissions' ) ); exit;

}
add_action( 'edd_add_commission', 'eddc_add_manual_commission' );

/**
 * Register the payouts batch exporter
 * @since  2.4.2
 */
function eddc_register_payouts_batch_export() {
	add_action( 'edd_batch_export_class_include', 'eddc_include_payouts_batch_processer', 10, 1 );
}
add_action( 'edd_register_batch_exporter', 'eddc_register_payouts_batch_export', 10 );

/**
 * Loads the commissions payouts batch process if needed
 *
 * @since  2.4.2
 * @param  string $class The class being requested to run for the batch export
 * @return void
 */
function eddc_include_payouts_batch_processer( $class ) {

	if ( 'EDD_Batch_Commissions_Payout' === $class ) {
		require_once EDDC_PLUGIN_DIR . 'includes/admin/class-batch-commissions-payout.php';
	}

}
