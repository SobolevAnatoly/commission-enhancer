<?php


namespace Codeable\CommissionEnhancer\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if (
	! file_exists( WC_PRODUCT_VENDORS_PATH . '/includes/class-wc-product-vendors-utils.php' ) ||
	! file_exists( WC_PRODUCT_VENDORS_PATH . '/includes/class-wc-product-vendors-commission.php' ) ||
	! file_exists( WC_PRODUCT_VENDORS_PATH . '/includes/class-wc-product-vendors-order.php' ) ||
	! file_exists( WC_PRODUCT_VENDORS_PATH . '/includes/gateways/class-wc-product-vendors-paypal-masspay.php' )
) {
	exit( esc_html( 'The important WooCommerce Product Vendors file is missing or affected, reinstall plugin' ) );
}

require_once( WC_PRODUCT_VENDORS_PATH . '/includes/class-wc-product-vendors-utils.php' );
require_once( WC_PRODUCT_VENDORS_PATH . '/includes/gateways/class-wc-product-vendors-paypal-masspay.php' );
require_once( WC_PRODUCT_VENDORS_PATH . '/includes/class-wc-product-vendors-commission.php' );
require_once( WC_PRODUCT_VENDORS_PATH . '/includes/class-wc-product-vendors-order.php' );

use WC_Product_Vendors_PayPal_MassPay;
use WC_Product_Vendors_Commission;
use WC_Product_Vendors_Utils;

class VendorsCommission extends \WC_Product_Vendors_Order {

	public $instance = null;

	public function __construct() {
		$this->setCommission( new WC_Product_Vendors_Commission( new WC_Product_Vendors_PayPal_MassPay() ) );

		$this->instance = new parent( $this->getCommission() );

		parent::__construct( $this->getCommission() );

		add_action( 'woocommerce_order_status_on-hold_to_processing', [ $this, 'process' ], 9 );
		add_action( 'woocommerce_order_status_on-hold_to_completed', [ $this, 'process' ], 9 );
		add_action( 'woocommerce_order_status_pending_to_processing', [ $this, 'process' ], 9 );
		add_action( 'woocommerce_order_status_pending_to_completed', [ $this, 'process' ], 9 );
		add_action( 'woocommerce_order_status_failed_to_processing', [ $this, 'process' ], 9 );
		add_action( 'woocommerce_order_status_failed_to_completed', [ $this, 'process' ], 9 );
		add_action( 'woocommerce_bookings_create_booking_page_add_order_item', [ $this, 'process' ], 9 );

		add_action( 'woocommerce_order_action_wcpv_manual_create_commission', [
			$this,
			'process_manual_create_commission_action'
		], 9 );
		add_action( 'woocommerce_product_vendors_paypal_webhook_trigger', [ $this, 'process_paypal_webhook' ], 9 );

	}

	public function process_paypal_webhook( $notification ) {
		$resource_parts = explode( '_', $notification->resource->payout_item->sender_item_id );
		$order_id       = absint( $resource_parts[1] );
		$vendor_id      = absint( $resource_parts[3] );

		$commissions = $this->commission->get_commission_by_order_id( $order_id, 'unpaid' );

		if ( $commissions ) {
			foreach ( $commissions as $commission ) {
				// Only process the vendor in question.
				if ( absint( $commission->vendor_id ) === $vendor_id ) {
					$this->commission->update_status( $commission->id, $commission->order_item_id, 'paid' );
					WC_Product_Vendors_Utils::update_order_item_meta( $commission->order_item_id );
				}
			}
		}
	}

	public function process_manual_create_commission_action( $order ) {
		if ( version_compare( WC_VERSION, '3.0.0', '>=' ) ) {
			$order_id = $order->get_id();
		} else {
			$order_id = $order->id;
		}

		$this->process( $order_id );

		return true;
	}

	public function process( $order_id ) {
		global $wpdb;

		$commission_added = false;

		$check_commission_added = get_post_meta( $order_id, '_wcpv_commission_added', true );

		$this->order = wc_get_order( $order_id );

		$parent_id = $this->order->get_parent_id();

		$items = ( $parent_id > 0 ) ? wc_get_order( $parent_id )->get_items( 'line_item' ) : $this->order->get_items( 'line_item' );

		if ( is_a( $this->order, 'WC_Order' ) && $items ) {
			$order_status   = $this->order->get_status();
			$commission_ids = array();

			foreach ( $items as $order_item_id => $item ) {
				$product = wc_get_product( $item['product_id'] );
				if ( ! is_object( $product ) ) {
					continue;
				}

				$vendor_id = WC_Product_Vendors_Utils::get_vendor_id_from_product( $product->get_id() );

				// check if it is a vendor product
				if ( $vendor_id ) {
					$_product_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
					$_product    = wc_get_product( $_product_id );

					do_action( 'wcpv_processing_vendor_order_item', $order_item_id, $item, $this->order );

					// check first to see if meta has already been added
					$check_sql = "SELECT `meta_value`";
					$check_sql .= " FROM {$wpdb->prefix}woocommerce_order_itemmeta";
					$check_sql .= " WHERE `order_item_id` = %d";
					$check_sql .= " AND `meta_key` = %s";

					$result = $wpdb->get_results( $wpdb->prepare( $check_sql, $order_item_id, '_fulfillment_status' ) );

					if ( empty( $result ) ) {

						// add ship status to order item meta
						$sql = "INSERT INTO {$wpdb->prefix}woocommerce_order_itemmeta ( `order_item_id`, `meta_key`, `meta_value` )";
						$sql .= " VALUES ( %d, %s, %s )";

						$fulfillment_status = 'unfulfilled';

						if ( $_product->is_virtual() ) {
							$fulfillment_status = 'fulfilled';
						}

						$fulfillment_status = apply_filters( 'wcpv_processing_init_fulfillment_status', $fulfillment_status, $order_item_id, $item, $this->order );

						$wpdb->query( $wpdb->prepare( $sql, $order_item_id, '_fulfillment_status', $fulfillment_status ) );
					}

					// create commission.
					$vendor_data = WC_Product_Vendors_Utils::get_vendor_data_by_id( $vendor_id );

					// Get Partial order total || Product total if it is not a partial order
					$order_sum = ( $parent_id > 0 ) ? $this->order->get_total() : $item['line_total'];

					$product_commission = $this->commission->calc_order_product_commission( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'], $vendor_id, $order_sum, $item['qty'] );

					$total_commission    = $product_commission;
					$shipping_amount     = '';
					$shipping_tax_amount = '';

					$product_settings = WC_Product_Vendors_Utils::get_product_vendor_settings( $product, $vendor_data );
					// get the per product shipping title.
					$pp_shipping_title = get_option( 'woocommerce_wcpv_per_product_settings', '' );
					$pp_shipping_title = ! empty( $pp_shipping_title ) ? $pp_shipping_title['title'] : '';

					// calculate shipping amount and shipping tax ( per product shipping ).
					$pp_shipping_method = $this->order->get_shipping_method();
					if ( ! empty( $pp_shipping_method ) && ! empty( $pp_shipping_title ) && false !== strpos( $pp_shipping_method, $pp_shipping_title ) && 'yes' === $product_settings['pass_shipping'] ) {
						$shipping_data       = $this->calc_per_product_shipping( $item );
						$shipping_amount     = $shipping_data['shipping_cost'];
						$shipping_tax_amount = $shipping_data['taxes'];
						$shipping_total      = round( $shipping_amount + $shipping_tax_amount, wc_get_rounding_precision() );

						$total_commission = round( $total_commission + $shipping_total, wc_get_rounding_precision() );
					}

					// calculate tax into total commission.
					if ( wc_tax_enabled() ) {
						$tax_total = $item['line_tax'];

						if ( 'pass-tax' === $product_settings['taxes'] ) {
							$total_commission = round( $total_commission + $tax_total, wc_get_rounding_precision() );
						} elseif ( 'split-tax' === $product_settings['taxes'] ) {
							$commission_array = WC_Product_Vendors_Utils::get_product_commission( $_product_id, $vendor_data );

							if ( 'percentage' === $commission_array['type'] ) {
								$tax_commission   = round( $tax_total * ( abs( $commission_array['commission'] ) / 100 ), wc_get_rounding_precision() );
								$total_commission = round( $total_commission + $tax_commission, wc_get_rounding_precision() );
							}
						}
					}

					$attributes = '';

					if ( 'variation' === $_product->get_type() ) {
						// get variation attributes.
						$variation_attributes = $_product->get_variation_attributes();

						if ( ! empty( $variation_attributes ) ) {
							$attributes = array();

							foreach ( $variation_attributes as $name => $value ) {
								$name = ucfirst( str_replace( 'attribute_', '', $name ) );

								$attributes[ $name ] = $value;
							}

							$attributes = maybe_serialize( $attributes );
						}
					}

					// check for existing commission data.
					$check_sql = 'SELECT `id`';
					$check_sql .= ' FROM ' . WC_PRODUCT_VENDORS_COMMISSION_TABLE;
					$check_sql .= ' WHERE `order_item_id` = %d';
					$check_sql .= ' AND `order_id` = %d';
					$check_sql .= ' AND `commission_status` != %s';

					$last_commission_id = $wpdb->get_var( $wpdb->prepare( $check_sql, $order_item_id, $order_id, 'paid' ) );

					if ( version_compare( WC_VERSION, '3.0.0', '>=' ) ) {
						$order_date = $this->order->get_date_created() ? gmdate( 'Y-m-d H:i:s', $this->order->get_date_created()->getOffsetTimestamp() ) : '';
					} else {
						$order_date = $this->order->order_date;
					}

					// initial commission status.
					$init_status = apply_filters( 'wcpv_processing_init_commission_status', 'unpaid' );

					if ( 0 === $total_commission ) {
						$init_status = 'void';
					}

					if ( empty( $last_commission_id ) && 'yes' !== $check_commission_added ) {
						$last_commission_id = $this->commission->insert( $order_id, $order_item_id, $order_date, $vendor_id, $vendor_data['name'], $item['product_id'], $item['variation_id'], $item['name'], $attributes, $item['line_total'], $item['qty'], $shipping_amount, $shipping_tax_amount, $item['line_tax'], wc_format_decimal( $product_commission ), wc_format_decimal( $total_commission ), $init_status, null );

						$commission_added = true;
					}

					// check if we need to pay vendor commission instantly.
					if ( ! empty( $vendor_data['instant_payout'] ) && 'yes' === $vendor_data['instant_payout'] && ! empty( $vendor_data['paypal'] ) && ( 'completed' === $order_status || 'processing' === $order_status ) && 0 != $total_commission ) {
						$commission_ids[ $last_commission_id ] = absint( $last_commission_id );
					}

					// check first to see if meta has already been added.
					$check_sql = 'SELECT `meta_value`';
					$check_sql .= " FROM {$wpdb->prefix}woocommerce_order_itemmeta";
					$check_sql .= ' WHERE `order_item_id` = %d';
					$check_sql .= ' AND `meta_key` = %s';

					$result = $wpdb->get_results( $wpdb->prepare( $check_sql, $order_item_id, '_commission_status' ) );

					if ( empty( $result ) ) {
						// add initial paid status to order item meta.
						$sql = "INSERT INTO {$wpdb->prefix}woocommerce_order_itemmeta ( `order_item_id`, `meta_key`, `meta_value` )";
						$sql .= " VALUES ( %d, %s, %s )";

						$wpdb->query( $wpdb->prepare( $sql, $order_item_id, '_commission_status', $init_status ) );
					}

					if ( version_compare( WC_VERSION, '3.0.0', '>=' ) ) {
						$customer_user = $this->order->get_user_id();
					} else {
						$customer_user = $this->order->customer_user;
					}

					// add vendor id to customer meta.
					if ( ! empty( $customer_user ) ) {
						WC_Product_Vendors_Utils::update_user_related_vendors( $customer_user, absint( $vendor_id ) );
					}
				}
			}

			if ( $commission_added ) {
				// flag order that commission was added.
				update_post_meta( $order_id, '_wcpv_commission_added', 'yes' );

				do_action( 'wcpv_commission_added', $this->order );
			}

			// process mass payment.
			if ( ! empty( $commission_ids ) ) {
				try {
					$this->commission->pay( $commission_ids );

				} catch ( Exception $e ) {
					WC_Product_Vendors_Logger::log( $e->getMessage() );
				}
			}
		}

		return true;
	}

	/**
	 * @param WC_Product_Vendors_Commission $commission
	 */
	public function setCommission( $commission ) {
		$this->commission = $commission;
	}

	/**
	 * @return WC_Product_Vendors_Commission
	 */
	public function getCommission() {
		return $this->commission;
	}


	public function removeAction() {
		error_log( print_r( remove_action( 'woocommerce_order_action_wcpv_manual_create_commission', [
			$this->instance,
			'process_manual_create_commission_action'
		] ) ? 'remove_action - true' : 'remove_action - false', true ) );
		remove_action( 'woocommerce_order_action_wcpv_manual_create_commission', [
			$this->instance,
			'process_manual_create_commission_action'
		] );
	}

}
