<?php
/**
 * PayPal Standard Gateway
 *
 * @package     Easy Digital Downloads
 * @subpackage  PayPal Standard Gateway
 * @copyright   Copyright (c) 2013, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
*/


/**
 * PayPal Remove CC Form
 *
 * PayPal Standard does not need a CC form, so remove it.
 *
 * @access      private
 * @since       1.0
 * @return      void
*/

function edd_paypal_remove_cc_form() {
	// We only register the action so that the default CC form is not shown
}
add_action( 'edd_paypal_cc_form', 'edd_paypal_remove_cc_form' );


/**
 * Process PayPal Purchase
 *
 * @access      private
 * @since       1.0
 * @return      void
*/

function edd_process_paypal_purchase( $purchase_data, $is_ajax ) {

    global $edd_options;

    // Check there is a gateway name
    if ( ! isset( $purchase_data['post_data']['edd-gateway'] ) )
    return;

    /*
    Purchase data comes in like this:
    ////////////////////////////////

    $purchase_data = array(
        'downloads'     => array of download IDs,
        'tax' 			=> taxed amount on shopping cart
        'subtotal'		=> total price before tax
        'price'         => total price of cart contents after taxes,
        'purchase_key'  =>  // Random key
        'user_email'    => $user_email,
        'date'          => date( 'Y-m-d H:i:s' ),
        'user_id'       => $user_id,
        'post_data'     => $_POST,
        'user_info'     => array of user's information and used discount code
        'cart_details'  => array of cart details,
     );
    */

    // Collect payment data
    $payment_data = array(
        'price'         => $purchase_data['price'],
        'date'          => $purchase_data['date'],
        'user_email'    => $purchase_data['user_email'],
        'purchase_key'  => $purchase_data['purchase_key'],
        'currency'      => $edd_options['currency'],
        'downloads'     => $purchase_data['downloads'],
        'user_info'     => $purchase_data['user_info'],
        'cart_details'  => $purchase_data['cart_details'],
        'status'        => 'pending'
     );

    // Record the pending payment
    $payment = edd_insert_payment( $payment_data );

    // Check payment
    if ( ! $payment ) {
    	// Record the error
        edd_record_gateway_error( __( 'Payment Error', 'edd' ), sprintf( __( 'Payment creation failed before sending buyer to PayPal. Payment data: %s', 'edd' ), json_encode( $payment_data ) ), $payment );
        // Problems? send back
        edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
    } else {
        // Only send to PayPal if the pending payment is created successfully
        $listener_url = trailingslashit( home_url() ).'?edd-listener=IPN';

         // Get the success url
        $return_url = add_query_arg( 'payment-confirmation', 'paypal', get_permalink( $edd_options['success_page'] ) );

        // Get the complete cart cart_summary
        $summary = edd_get_purchase_summary( $purchase_data, false );

        // Get the PayPal redirect uri
        $paypal_redirect = trailingslashit( edd_get_paypal_redirect() ) . '?';

        // Setup PayPal arguments
        $paypal_args = array(
            'cmd'           => '_xclick',
            'amount'        => $purchase_data['price'] - $purchase_data['tax'],
            'business'      => $edd_options['paypal_email'],
            'item_name'     => stripslashes_deep( html_entity_decode( wp_strip_all_tags( $summary ), ENT_COMPAT, 'UTF-8' ) ),
            'email'         => $purchase_data['user_email'],
            'no_shipping'   => '1',
            'shipping'      => '0',
            'no_note'       => '1',
            'currency_code' => $edd_options['currency'],
            'item_number'   => $purchase_data['purchase_key'],
            'charset'       => get_bloginfo( 'charset' ),
            'custom'        => $payment,
            'rm'            => '2',
            'return'        => $return_url,
            'cancel_return' => edd_get_failed_transaction_uri(),
            'notify_url'    => $listener_url,
            'page_style'    => edd_get_paypal_page_style()
        );

        if( edd_use_taxes() )
        	$paypal_args['tax'] = $purchase_data['tax'];

        $paypal_args = apply_filters('edd_paypal_redirect_args', $paypal_args, $purchase_data );

		// Build query
		$paypal_redirect .= http_build_query( $paypal_args );

		// Get rid of cart contents
		edd_empty_cart();

		// Take care of those using Ajax
		if ( $is_ajax ) {
			echo "<script> window.location.replace(".$paypal_redirect."';</script>");
			exit;
		}		

		// Redirect to PayPal
		wp_redirect( $paypal_redirect );
		exit;
	}

}
add_action( 'edd_gateway_paypal', 'edd_process_paypal_purchase', 10 , 2 );




/**
 * Listen For PayPal IPN
 *
 * Listens for a PayPal IPN requests and then sends to the processing function.
 *
 * @access      private
 * @since       1.0
 * @return      void
*/

function edd_listen_for_paypal_ipn() {
	global $edd_options;

	// Regular PayPal IPN
	if ( !isset( $edd_options['paypal_alternate_verification'] ) ) {

		if( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'IPN' ) {
			do_action( 'edd_verify_paypal_ipn' );
		}

	// Alternate purchase verification
	} else {
		if( isset( $_GET['tx'] ) && isset( $_GET['st'] ) && isset( $_GET['amt'] ) && isset( $_GET['cc'] ) && isset( $_GET['cm'] ) && isset( $_GET['item_number'] ) ) {
			// We are using the alternate method of verifying PayPal purchases

			// Setup each of the variables from PayPal
			$payment_status = $_GET['st'];
			$paypal_amount = $_GET['amt'];
			$payment_id = $_GET['cm'];
			$purchase_key = $_GET['item_number'];
			$currency = $_GET['cc'];

			// Retrieve the meta info for this payment
			$payment_meta = get_post_meta( $payment_id, '_edd_payment_meta', true );
			$payment_amount = edd_format_amount( $payment_meta['amount'] );

			if ( $currency != $edd_options['currency'] ) {
				return; // The currency code is invalid
			}
			if ( number_format((float)$paypal_amount, 2) != $payment_amount ) {
				return; // The prices don't match
			}
			if ( $purchase_key != $payment_meta['key'] ) {
				return; // Purchase keys don't match
			}
			if ( strtolower( $payment_status ) != 'completed' || edd_is_test_mode() ) {
				return; // Payment wasn't completed
			}

			// Everything has been verified, update the payment to "complete"
			edd_update_payment_status( $payment_id, 'publish' );
		}
	}
}
add_action( 'init', 'edd_listen_for_paypal_ipn' );


/**
 * Process PayPal IPN
 *
 * @access      private
 * @since       1.0
 * @return      void
*/

function edd_process_paypal_ipn() {
	global $edd_options;

	// Check the request method is POST
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] != 'POST' ) {
		return;
	}

	// Set initial post data to false
	$post_data = false;

	// Fallback just in case post_max_size is lower than needed
	if ( ini_get( 'allow_url_fopen' ) ) {
		$post_data = file_get_contents( 'php://input' );
	} else {
		// If allow_url_fopen is not enabled, then make sure that post_max_size is large enough
		ini_set( 'post_max_size', '12M' );
	}
	// Start the encoded data collection with notification command
	$encoded_data = 'cmd=_notify-validate';

	// Get current arg separator
	$arg_separator = edd_get_php_arg_separator_output();

	// Verify there is a post_data
	if ( $post_data || strlen( $post_data ) > 0 ) {
		// Append the data
		$encoded_data .= $arg_separator.$post_data;
	} else {
		// Check if POST is empty
		if ( empty( $_POST ) ) {
			// Nothing to do
			return;
		} else {
			// Loop trough each POST
			foreach ( $_POST as $key => $value ) {
				// Encode the value and append the data
				$encoded_data .= $arg_separator."$key=" . urlencode( $value );
			}
		}
	}

	// Convert collected post data to an array
	parse_str( $encoded_data, $encoded_data_array );

	// Get the PayPal redirect uri
	$paypal_redirect = edd_get_paypal_redirect(true);

	$remote_post_vars = array(
		'method' => 'POST',
		'timeout' => 45,
		'redirection' => 5,
		'httpversion' => '1.0',
		'blocking' => true,
		'headers' => array(),
		'sslverify' => false,
		'body' => $encoded_data_array
	);

	// Get response
	$api_response = wp_remote_post( edd_get_paypal_redirect(), $remote_post_vars );

	if( is_wp_error( $api_response ) ) {
		edd_record_gateway_error( __( 'IPN Error', 'edd' ), sprintf( __( 'Invalid IPN verification response. IPN data: ', 'edd' ), json_encode( $api_response ) ) );
		return; // Something went wrong
	}

	if( $api_response['body'] !== 'VERIFIED' && !isset( $edd_options['disable_paypal_verification'] ) ) {
		edd_record_gateway_error( __( 'IPN Error', 'edd' ), sprintf( __( 'Invalid IPN verification response. IPN data: ', 'edd' ), json_encode( $api_response ) ) );
		return; // Response not okay
	}

	// Check if $post_data_array has been populated
	if( !is_array( $encoded_data_array ) && !empty( $encoded_data_array ) )
		return;

	if( has_action( 'edd_paypal_' . $encoded_data_array['txn_type'] ) ) {
		// Allow PayPal IPN types to be processed separately
		do_action( 'edd_paypal_' . $encoded_data_array['txn_type'], $encoded_data_array );
	} else {
		// Fallback to web accept just in case the txn_type isn't present
		do_action( 'edd_paypal_web_accept', $encoded_data_array );
	}

}
add_action( 'edd_verify_paypal_ipn', 'edd_process_paypal_ipn' );


/**
 * Process web accept (one time) payment IPNs
 *
 * @access      private
 * @since       1.3.4
 * @return      void
*/

function edd_process_paypal_web_accept( $data ) {

	global $edd_options;

	if( $data['txn_type'] != 'web_accept' )
		return;

	// Collect payment details
	$payment_id     = $data['custom'];
	$purchase_key   = $data['item_number'];
	$paypal_amount  = $data['mc_gross'];
	$payment_status = $data['payment_status'];
	$currency_code  = strtolower( $data['mc_currency'] );

	// Retrieve the meta info for this payment
	$payment_meta = get_post_meta( $payment_id, '_edd_payment_meta', true );
	$payment_amount = edd_format_amount( $payment_meta['amount'] );

	if( edd_get_payment_gateway( $payment_id ) != 'paypal' )
		return; // this isn't a PayPal standard IPN

	// Verify details
	if( $currency_code != strtolower( $edd_options['currency'] ) ) {
		// The currency code is invalid

		edd_record_gateway_error( __( 'IPN Error', 'edd' ), sprintf( __( 'Invalid currency in IPN response. IPN data: ', 'edd' ), json_encode( $data ) ), $payment_id );
		edd_update_payment_status( $payment_id, 'failed' );
		return;
	}

	if( strtolower( $payment_status ) == 'refunded' ) {

		// Process a refund
		edd_process_paypal_refund( $data );

	} else {

		if( number_format((float)$paypal_amount, 2) != $payment_amount ) {
			// The prices don't match
			edd_record_gateway_error( __( 'IPN Error', 'edd' ), sprintf( __( 'Invalid payment amount in IPN response. IPN data: ', 'edd' ), json_encode( $data ) ), $payment_id );
		   //return;
		}
		if( $purchase_key != $payment_meta['key'] ) {
			// Purchase keys don't match
			edd_record_gateway_error( __( 'IPN Error', 'edd' ), sprintf( __( 'Invalid purchase key in IPN response. IPN data: ', 'edd' ), json_encode( $data ) ), $payment_id );
		   	edd_update_payment_status( $payment_id, 'failed' );
		   	return;
		}

		$status = strtolower( $payment_status );

		if ( $status == 'completed' || edd_is_test_mode() ) {
			edd_insert_payment_note( $payment_id, sprintf( __( 'PayPal Transaction ID: %s', 'edd' ) , $data['txn_id'] ) );
			edd_update_payment_status( $payment_id, 'publish' );
		}
	}

}
add_action( 'edd_paypal_web_accept', 'edd_process_paypal_web_accept' );


/**
 * Process IPN Refunds
 *
 * @access      private
 * @since       1.3.4
 * @return      void
*/

function edd_process_paypal_refund( $data ) {

	global $edd_options;

	// Collect payment details
	$payment_id = $data['custom'];

	edd_insert_payment_note( $payment_id, sprintf( __( 'PayPal Payment #%s Refunded', 'edd' ) , $data['txn_id'] ) );
	edd_update_payment_status( $payment_id, 'refunded' );

}


/**
 * Get Paypal Redirect
 *
 * @access      private
 * @since       1.0.8.2
 * @return      string
*/

function edd_get_paypal_redirect( $ssl_check = false ) {
	global $edd_options;

	if( is_ssl() || ! $ssl_check ) {
		$protocal = 'https://';
	} else {
		$protocal = 'http://';
	}

	// Check the current payment mode
	if( edd_is_test_mode() ) {
		// Test mode
		$paypal_uri = $protocal . 'www.sandbox.paypal.com/cgi-bin/webscr';
	} else {
		// Live mode
		$paypal_uri = $protocal . 'www.paypal.com/cgi-bin/webscr';
	}

	return $paypal_uri;
}


/**
 * Set the Page Style for PayPal Purchase page
 *
 * @access      private
 * @since       1.4.1
 * @return      string
*/

function edd_get_paypal_page_style() {
	global $edd_options;
	$page_style = 'PayPal';
	if( isset( $edd_options['paypal_page_style'] ) )
		$page_style = trim( $edd_options['paypal_page_style'] );
	return apply_filters( 'edd_paypal_page_style', $page_style );
}
