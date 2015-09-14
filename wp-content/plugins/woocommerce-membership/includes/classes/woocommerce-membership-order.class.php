<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Methods related to WooCommerce Orders
 *
 * @class WooCommerce_Membership_Order
 * @package WooCommerce_Membership
 * @author RightPress
 */
if (!class_exists('WooCommerce_Membership_Order')) {

class WooCommerce_Membership_Order
{

    /**
     * Constructor class
     *
     * @access public
     * @param mixed $id
     * @return void
     */
    public function __construct($id = null)
    {
        // Save plan configuration on the checkout
        add_action('woocommerce_add_order_item_meta', array($this, 'save_order_item_plans'), 10, 2);
        add_filter('woocommerce_hidden_order_itemmeta', array($this, 'hide_membership_plans'));

        // Grant membership on these WooCommerce actions
        add_action('woocommerce_payment_complete', array($this, 'order_paid'), 9);
        add_action('woocommerce_order_status_processing', array($this, 'order_paid'), 9);
        add_action('woocommerce_order_status_completed', array($this, 'order_paid'), 9);

        // Remove membership on these WooCommerce actions
        add_action('woocommerce_order_status_cancelled', array($this, 'order_cancelled'));
        add_action('woocommerce_order_status_refunded', array($this, 'order_cancelled'));
        add_action('woocommerce_order_status_failed', array($this, 'order_cancelled'));

        // Trashed, untrashed and deleted
        add_action('before_delete_post', array($this, 'post_deleted'));
        add_action('trashed_post', array($this, 'post_trashed'));
        add_action('untrashed_post', array($this, 'post_untrashed'));
    }

    /**
     * Save membership plan IDs to order item meta
     *
     * @access public
     * @param int $item_id
     * @param array $cart_item
     * @return void
     */
    public function save_order_item_plans($item_id, $cart_item)
    {
        $id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];

        // Check if it's a membership product
        if (WooCommerce_Membership_Product::is_membership($id)) {
            foreach (WooCommerce_Membership_Product::get_membership_plans($id, 'enabled') as $plan_id => $plan) {
                WooCommerce_Membership::wc_version_gte('2.1') ? wc_add_order_item_meta($item_id, '_rpwcm_plans', $plan_id) : woocommerce_add_order_item_meta($item_id, '_rpwcm_plans', $plan_id);
            }
        }
    }

    /**
     * Hide membership keys on order items list
     *
     * @access public
     * @param array $keys
     * @return array
     */
    public function hide_membership_plans($keys)
    {
        $keys[] = '_rpwcm_plans';
        return $keys;
    }

    /**
     * Order paid - grant membership
     *
     * @access public
     * @param int $order_id
     * @return void
     */
    public function order_paid($order_id)
    {
        // Check if memberships have not been granted already
        if (get_post_meta($order_id, '_rpwcm_membership_granted', true)) {
            return;
        }
        else {
            update_post_meta($order_id, '_rpwcm_membership_granted', '1');
        }

        // Initialize order object
        $order = new WC_Order($order_id);

        foreach ($order->get_items() as $item_id => $item) {

            // Only proceed if we have any plan IDs set
            if (isset($item['item_meta']['_rpwcm_plans']) && is_array($item['item_meta']['_rpwcm_plans'])) {

                // Get correct ID
                $id = !empty($item['variation_id']) ? $item['variation_id'] : $item['product_id'];

                // Allow other plugins to cancel membership activation
                if (!apply_filters('woocommerce_membership_cancel_activation', false, $order_id, $item_id, $item, $id)) {

                    // Schedule expiration
                    $expiration_value = get_post_meta($id, '_rpwcm_expiration_value', true);
                    $expiration_unit  = get_post_meta($id, '_rpwcm_expiration_unit', true);

                    if (!empty($expiration_value) && !empty($expiration_unit)) {
                        $expiration_time  = WooCommerce_Membership_Plan::get_time_in_future($expiration_value, $expiration_unit);
                    }
                    else {
                        $expiration_time = null;
                    }

                    // Grant access now
                    foreach ($item['item_meta']['_rpwcm_plans'] as $plan_id) {

                        // Get plan key
                        $plan_key = get_post_meta($plan_id, 'key', true);

                        // Check if expiration was already set for this order and plan
                        $plan_expiration_set = get_post_meta($order_id, '_rpwcm_' . $plan_key . '_expiration', true);

                        // Check if user is already a member
                        if (WooCommerce_Membership_User::is_member($order->user_id, $plan_id)) {

                            // Get current expiration time
                            $current_expiration_time = get_user_meta($order->user_id, '_rpwcm_' . $plan_key . '_expires', true);

                            // Add new expiration time only if it wasn't set for this order and plan
                            if ($expiration_time && !$plan_expiration_set) {

                                // Calculate new expiration time
                                $new_expiration_time = $current_expiration_time + $expiration_time - time();

                                // Update expiration date of user
                                update_user_meta($order->user_id, '_rpwcm_' . $plan_key . '_expires', $new_expiration_time);

                                // Postpone expiration event
                                WooCommerce_Membership_Scheduler::schedule_expiration($plan_id, $order->user_id, $new_expiration_time);
                            }
                        }
                        else {

                            // Add member
                            WooCommerce_Membership_Plan::add_member($plan_id, $order->user_id, $expiration_time);

                            // Schedule expiration if set
                            if ($expiration_time) {
                                WooCommerce_Membership_Scheduler::schedule_expiration($plan_id, $order->user_id, $expiration_time);
                            }
                        }

                        // If expiration was set, save this in order meta to prevent double scheduling for one order
                        if ($expiration_time && !$plan_expiration_set) {
                            update_post_meta($order_id, '_rpwcm_' . $plan_key . '_expiration', 1);
                        }

                        // Schedule reminders only if subscription is not used
                        if (!apply_filters('woocommerce_membership_subscription_support', false)) {
                            if (!apply_filters('woocommerce_membership_product_is_subscription', false, $id)) {
                                WooCommerce_Membership_Scheduler::schedule_reminders($plan_id, $order->user_id);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Order cancelled - remove membership
     *
     * @access public
     * @param int $order_id
     * @return void
     */
    public function order_cancelled($order_id)
    {
        $order = new WC_Order($order_id);

        foreach ($order->get_items() as $item_id => $item) {

            // Only proceed if we have any plan IDs set
            if (isset($item['item_meta']['_rpwcm_plans']) && is_array($item['item_meta']['_rpwcm_plans'])) {

                // Get correct ID
                $id = isset($item['variation_id']) ? $item['variation_id'] : $item['product_id'];

                // Allow other plugins to cancel membership deactivation
                if (!apply_filters('woocommerce_membership_cancel_deactivation', false, $order_id, $item_id, $item, $id)) {

                    // Remove access now
                    foreach ($item['item_meta']['_rpwcm_plans'] as $plan_id) {
                        WooCommerce_Membership_Plan::remove_member($plan_id, $order->user_id);
                    }
                }
            }
        }
    }

    /**
     * Get array of membership plan objects from WooCommerce Order ID
     *
     * @access public
     * @param int $order_id
     * @return array
     */
    public static function get_membership_plans_from_order_id($order_id)
    {
        $memberships = array();

        if ($order = new WC_Order($order_id)) {
            foreach ($order->get_items() as $item) {

                // Get correct ID
                $product_id = !empty($item['variation_id']) ? $item['variation_id'] : $item['product_id'];

                if ($product_membership_ids = WooCommerce_Membership_Product::get_membership_plans($product_id)) {
                    foreach ($product_membership_ids as $product_membership_id) {
                        if (!isset($memberships[$product_membership_id])) {
                            $memberships[$product_membership_id] = WooCommerce_Membership_Plan::cache($product_membership_id);
                        }
                    }
                }
            }
        }

        return $memberships;
    }

    /**
     * Display granted membership plans on single order view page
     * Currently this function is not used, added for later versions
     *
     * @access public
     * @param object $order
     * @return void
     */
    public function display_frontend_order_granted_plans($order)
    {
        $plans = WooCommerce_Membership_Order::get_membership_plans_from_order_id($order->id);

        if (!empty($plans) && apply_filters('woocommerce_membership_display_order_granted_plans', true)) {
            WooCommerce_Membership::include_template('myaccount/membership-list', array(
                'plans' => $plans,
                'title' => __('My Memberships', 'woocommerce-membership'),
            ));
        }
    }

    /**
     * Order deleted
     *
     * @access public
     * @param int $post_id
     * @return void
     */
    public function post_deleted($post_id)
    {
        global $post_type;

        if ($post_type == 'shop_order') {
            $this->order_cancelled($post_id);
        }
    }

    /**
     * Order trashed
     *
     * @access public
     * @param int $post_id
     * @return void
     */
    public function post_trashed($post_id)
    {
        global $post_type;

        if ($post_type == 'shop_order') {
            $this->order_cancelled($post_id);
        }
    }

    /**
     * Order untrashed
     *
     * @access public
     * @param int $post_id
     * @return void
     */
    public function post_untrashed($post_id)
    {
        global $post_type;

        if ($post_type == 'shop_order') {

            $order = new WC_Order($post_id);

            if (in_array($order->status, array('processing', 'completed'))) {
                $this->order_paid($post_id);
            }
        }
    }

}

new WooCommerce_Membership_Order();

}