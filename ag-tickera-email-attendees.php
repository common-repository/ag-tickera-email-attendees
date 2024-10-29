<?php
/*
Plugin Name: AG Tickera Email Attendees
Description: By default tickera sends email only to buyers of the ticket. This plugin sends tickets to attendees as well.
Author: Akash G
Author URI: https://www.linkedin.com/in/akashgovani/
Version: 1.0.2
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: agTEA
Domain Path: /languages

{Plugin Name} is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or any later version.
 
{Plugin Name} is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 
You should have received a copy of the GNU General Public License along with {Plugin Name}. If not, see {License URI}.
*/
if ( !defined('ABSPATH')) {
	exit;
}
define("AG_EMAIL_ATTENDEES_PLUGIN_PATH", __FILE__);
//Dependency Check
if ( !function_exists( 'agTEA_check_tickera_install' ) ) {
	function agTEA_check_tickera_install()
	{
		if ( ! class_exists('TC')) {
			deactive_plugins( plugin_basename( AG_EMAIL_ATTENDEES_PLUGIN_PATH ));
			wp_die( __('Tickera is a reuired plugin for this plugin to function', 'agTEA'));
		}
	}
	register_activation_hook( AG_EMAIL_ATTENDEES_PLUGIN_PATH ,'agTEA_check_tickera_install');
	add_action('admin_init', 'agTEA_check_tickera_install');
}

//modified version of Tickera function tc_order_created_email
if ( !function_exists( 'agTEA_order_paid_attendee_email' ) ) {

	function agTEA_order_paid_attendee_email($order_id, $status, $cart_contents = false, $cart_info = false, $payment_info = false, $send_email_to_admin = true){

		global $tc;
		$attendee_info_collected = get_option("tc_general_setting", false);

		//check Tickera settings to determine if attendee information is collected on the cart page. 
		if( $attendee_info_collected != false && $attendee_info_collected["show_owner_fields"] === "yes") {
			//Since attendee information is available proceed.
			
			//Determine Email type - wp_mail or PHP mail.
			$tc_email_settings = get_option('tc_email_setting', false);
			$email_send_type = isset($tc_email_settings['email_send_type']) ? $tc_email_settings['email_send_type'] : 'wp_mail';

			//Get cart details
			$order_id = strtoupper($order_id);
			$order = tc_get_order_id_by_name($order_id);

			if ($cart_contents === false) {
				$cart_contents = get_post_meta($order->ID, 'tc_cart_contents', true);
			}
			if ($cart_info === false) {
				$cart_info = get_post_meta($order->ID, 'tc_cart_info', true);
			}

			$owner_data = $cart_info['owner_data'];
			$order = new TC_Order($order->ID);
			
			foreach ($owner_data['owner_email_post_meta'] as $attendee_email  ) {
				if ($status == 'order_paid') {
					if (!isset($tc_email_settings['client_send_message']) || (isset($tc_email_settings['client_send_message']) && $tc_email_settings['client_send_message'] == 'yes')) {

						add_filter('wp_mail_from', 'client_email_from_email', 999);
						add_filter('wp_mail_from_name', 'client_email_from_name', 999);

						$subject = isset($tc_email_settings['client_order_subject']) ? stripslashes($tc_email_settings['client_order_subject']) : __('Tickets booked.', 'tc');

						$order_details = agTEA_tc_get_order_details_attendee_email($order->details->ID, $order->details->tc_order_date, true, $attendee_email);

						$to = $attendee_email;

						$message = 'Hey!<br /><br />' . 'Your ticket has been booked. Following are the order details. <br /><br />' . $order_details . '<br /><br /><em>Use the link under column "Ticket" to download your ticket.</em><br /> <br />Cheers<br/> Team ' . get_bloginfo( 'name' );
						$client_headers = '';

						if ($email_send_type == 'wp_mail') {
							wp_mail($to, $subject, html_entity_decode(stripcslashes(apply_filters('tc_order_completed_admin_email_message', wpautop($message)))), apply_filters('tc_order_completed_client_email_headers', $client_headers));
						} else {
							$headers = 'MIME-Version: 1.0' . "\r\n";
							$headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
							$headers .= 'From: ' . client_email_from_email('') . "\r\n" .
							'Reply-To: ' . client_email_from_email('') . "\r\n" .
							'X-Mailer: PHP/' . phpversion();

							mail($to, $subject, stripcslashes(wpautop($message)), apply_filters('tc_order_completed_client_email_headers', $headers));
						}
					}
				}
			}
		}
	}
	add_action("tc_order_created", "agTEA_order_paid_attendee_email", 999, 5);


	if ( !function_exists( 'agTEA_tc_get_order_details_attendee_email' ) ) {
		//modified version of Tickera function tc_get_order_details_email
		function agTEA_tc_get_order_details_attendee_email($order_id = '', $order_key = '', $return = false, $attendee_email) {
			global $tc;

			if ($return) {
				ob_start();
			}

			$tc_general_settings = get_option('tc_general_setting', false);

			$order = new TC_Order($order_id);

			if (empty($order_key)) {
				$order_key = strtotime($order->details->post_date);
			}

			if ($order->details->tc_order_date == $order_key || strtotime($order->details->post_date) == $order_key) {
				//key must match order creation date for security reasons
				if ($order->details->post_status == 'order_received') {
					$order_status = __('Pending Payment', 'tc');
				} else if ($order->details->post_status == 'order_fraud') {
					$order_status = __('Under Review', 'tc');
				} else if ($order->details->post_status == 'order_paid') {
					$order_status = __('Payment Completed', 'tc');
				} else if ($order->details->post_status == 'trash') {
					$order_status = __('Order Deleted', 'tc');
				} else if ($order->details->post_status == 'order_cancelled') {
					$order_status = __('Order Cancelled', 'tc');
				} else {
					$order_status = $order->details->post_status;
				}

				$fees_total = apply_filters('tc_cart_currency_and_format', $order->details->tc_payment_info['fees_total']);
				$tax_total = apply_filters('tc_cart_currency_and_format', $order->details->tc_payment_info['tax_total']);
				$subtotal = apply_filters('tc_cart_currency_and_format', $order->details->tc_payment_info['subtotal']);
				$total = apply_filters('tc_cart_currency_and_format', $order->details->tc_payment_info['total']);
				$transaction_id = isset($order->details->tc_payment_info['transaction_id']) ? $order->details->tc_payment_info['transaction_id'] : '';
				$order_id = strtoupper($order->details->post_name);
				$order_date = $payment_date = apply_filters('tc_order_date', tc_format_date($order->details->tc_order_date, true)); //date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $order->details->tc_order_date, false )

				$tc_style_email_label = '';
				$tc_style_email_label_span = '';

				do_action('tc_get_order_details_email_labels_before', $order_id);

				if (apply_filters('tc_get_order_details_email_show_order', true, $order_id) == true) {
					?>
					<label <?php echo apply_filters('tc_style_email_label', $tc_style_email_label); ?> ><span <?php echo apply_filters('tc_style_email_label_span', $tc_style_email_label_span); ?> class="order_details_title"><?php _e('Order: ', 'tc'); ?></span> <?php echo $order_id; ?></label>
					<?php } ?>
					<?php if (apply_filters('tc_get_order_details_email_show_order_date', true, $order_id) == true) { ?>
					<label <?php echo apply_filters('tc_style_email_label', $tc_style_email_label); ?> ><span <?php echo apply_filters('tc_style_email_label_span', $tc_style_email_label_span); ?> class="order_details_title"><?php _e('Order date: ', 'tc'); ?></span> <?php echo $order_date; ?></label>
					<?php } ?>
					<?php if (apply_filters('tc_get_order_details_email_show_order_status', true, $order_id) == true) { ?>
					<label <?php echo apply_filters('tc_style_email_label', $tc_style_email_label); ?> ><span <?php echo apply_filters('tc_style_email_label_span', $tc_style_email_label_span); ?> class="order_details_title"><?php _e('Order status: ', 'tc'); ?></span> <?php echo $order_status; ?></label>
					<?php } ?>
					<?php if (apply_filters('tc_get_order_details_email_show_transaction_id', true, $order_id) == true) { ?>
					<?php if (isset($transaction_id) && $transaction_id !== '') { ?>
					<label <?php echo apply_filters('tc_style_email_label', $tc_style_email_label); ?> ><span <?php echo apply_filters('tc_style_email_label', $tc_style_email_label_span); ?> class="order_details_title"><?php _e('Transaction ID: ', 'tc'); ?></span> <?php echo $transaction_id; ?></label>
					<?php } ?>
					<?php } ?>
					<?php if (apply_filters('tc_get_order_details_email_show_subtitle', true, $order_id) == true) { ?>
					<label <?php echo apply_filters('tc_style_email_label', $tc_style_email_label); ?> ><span <?php echo apply_filters('tc_style_email_label_span', $tc_style_email_label_span); ?> class="order_details_title"><?php _e('Subtotal: ', 'tc'); ?></span> <?php echo $subtotal; ?></label>
					<?php } ?>
					<?php if (apply_filters('tc_get_order_details_email_show_fees', true, $order_id) == true) { ?>
					<?php if (!isset($tc_general_settings['show_fees']) || isset($tc_general_settings['show_fees']) && $tc_general_settings['show_fees'] == 'yes') { ?>
					<label <?php echo apply_filters('tc_style_email_label', $tc_style_email_label); ?> ><span <?php echo apply_filters('tc_style_email_label_span', $tc_style_email_label_span); ?> class="order_details_title"><?php echo $tc_general_settings['fees_label']; ?></span> <?php echo $fees_total; ?></label>
					<?php } ?>
					<?php } ?>
					<?php if (apply_filters('tc_get_order_details_email_show_tax', true, $order_id) == true) { ?>
					<?php if (!isset($tc_general_settings['show_tax_rate']) || isset($tc_general_settings['show_tax_rate']) && $tc_general_settings['show_tax_rate'] == 'yes') { ?>
					<label <?php echo apply_filters('tc_style_email_label', $tc_style_email_label); ?> ><span <?php echo apply_filters('tc_style_email_label_span', $tc_style_email_label_span); ?> class="order_details_title"><?php echo $tc_general_settings['tax_label']; ?></span> <?php echo $tax_total; ?></label>
					<?php } ?>
					<?php } ?>
					<?php if (apply_filters('tc_get_order_details_email_show_total', true, $order_id) == true) { ?>
					<label <?php echo apply_filters('tc_style_email_label', $tc_style_email_label); ?> ><span <?php echo apply_filters('tc_style_email_label_span', $tc_style_email_label_span); ?> class="order_details_title"><?php _e('Total: ', 'tc'); ?></span> <?php echo $total; ?></label>
					<?php
				}
				do_action('tc_get_order_details_email_tickets_table_before', $order_id);
				?>

				<?php if (apply_filters('tc_get_order_details_email_show_tickets_table', true, $order_id) == true) { ?>
				<?php
				if ($order->details->post_status == 'order_paid') {
					$orders = new TC_Orders();

					$args = array(
						'posts_per_page' => -1,
						'orderby' => 'post_date',
						'order' => 'ASC',
						'post_type' => 'tc_tickets_instances',
						'post_parent' => $order->details->ID,
						'meta_query' => array(
							array(
								'key' => 'owner_email',
								'value' => $attendee_email,
								)
							),
						); //To enusre ticket owner only gets his/her ticket, $attendee_email is uses as a filter

					$tickets = get_posts($args);
					$columns = $orders->get_owner_info_fields_front();
					$style = '';

					$style_css_table = 'cellspacing="0" cellpadding="6" style="width: 100%; font-family: Helvetica, Roboto, Arial, sans-serif;" border="1"';
					$style_css_tr = '';
					$style_css_td = '';
					?>

					<table class="order-details widefat shadow-table" <?php echo apply_filters('tc_style_css_table', $style_css_table); ?> >
						<tr <?php echo apply_filters('tc_style_css_tr', $style_css_tr); ?> >
							<?php
							foreach ($columns as $column) {
								?>
								<th <?php echo apply_filters('tc_style_css_td', $style_css_td); ?> ><?php echo $column['field_title']; ?></th>
								<?php
							}
							?>
						</tr>

						<?php
						foreach ($tickets as $ticket) {
							$style = ( ' class="alternate"' == $style ) ? '' : ' class="alternate"';
							?>
							<tr <?php echo $style; ?> <?php echo apply_filters('tc_style_css_tr', $style_css_tr); ?> >
								<?php
								foreach ($columns as $column) {
									?>
									<td <?php echo apply_filters('tc_style_css_td', $style_css_td); ?> >
										<?php
										if ($column['field_type'] == 'function') {
											eval($column['function'] . '("' . $column['field_name'] . '", "' . (isset($column['field_id']) ? $column['field_id'] : '') . '", "' . $ticket->ID . '");');
										} else {
											if ($column['post_field_type'] == 'post_meta') {
												echo get_post_meta($ticket->ID, $column['field_name'], true);
											}
											if ($column['post_field_type'] == 'ID') {
												echo $ticket->ID;
											}
										}
										?>
									</td>
									<?php }
									?>
								</tr>
								<?php
							}
							?>
						</table>
						<?php
					}
				}
				do_action('tc_get_order_details_email_tickets_table_after', $order_id);
			}

			if ($return) {
				$content = wpautop(ob_get_clean(), true);
				return $content;
			}
		}
	}
}

?>