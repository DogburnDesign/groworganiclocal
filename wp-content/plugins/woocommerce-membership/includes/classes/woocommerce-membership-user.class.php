<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Methods related to WordPress User
 *
 * @class WooCommerce_Membership_User
 * @package WooCommerce_Membership
 * @author RightPress
 */
if (!class_exists('WooCommerce_Membership_User')) {

class WooCommerce_Membership_User
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
        // Enforce user registration
        add_action('woocommerce_before_checkout_form', array($this, 'enforce_user_registration'), 99);
        add_action('woocommerce_before_checkout_process', array($this, 'enforce_user_registration'), 99);
        add_filter('wc_checkout_params', array($this, 'enforce_user_registration_js'), 99);

        // Intercept export to CSV call
        if (isset($_GET['wcm_members_csv'])) {
            add_action('init', array($this, 'push_members_csv'));
        }
    }

    /**
     * Allow no guest checkout when membership product is in cart
     *
     * @access public
     * @param object $checkout
     * @return void
     */
    public function enforce_user_registration($checkout)
    {
        // User already registered?
        if (is_user_logged_in()) {
            return;
        }

        if (!$checkout) {
            global $woocommerce;
            $checkout = &$woocommerce->checkout;
        }

        // Only proceed if cart contains membership
        if (WooCommerce_Membership_Checkout::cart_contains_membership()) {

            // Enable registration
            $checkout->enable_signup = true;

            // Enforce registration
            $checkout->enable_guest_checkout = false;

            // Must create account
            $checkout->must_create_account = true;
        }
    }

    /**
     * Allow no guest checkout (Javascript part)
     *
     * @access public
     * @param array $properties
     * @return array
     */
    public function enforce_user_registration_js($properties)
    {
        // User already registered?
        if (is_user_logged_in()) {
            return $properties;
        }

        // No membership in cart?
        if (!WooCommerce_Membership_Checkout::cart_contains_membership()) {
            return $properties;
        }

        $properties['option_guest_checkout'] = 'no';

        return $properties;
    }

    /**
     * Get link to user profile with a full name
     *
     * @access public
     * @param int $user_id
     * @param string $name
     * @return string
     */
    public static function get_user_full_name_link($user_id, $name = '')
    {
        $name = !empty($name) ? $name : self::get_user_full_name($user_id);
        return '<a href="user-edit.php?user_id=' . $user_id . '">' . $name . '</a>';
    }

    /**
     * Get user full name from database
     *
     * @access public
     * @param int $user_id
     * @return string
     */
    public static function get_user_full_name($user_id)
    {
        $name = __('Unknown', 'woocommerce-membership');

        if ($user = get_userdata($user_id)) {
            $first_name = get_the_author_meta('first_name', $user_id);
            $last_name = get_the_author_meta('last_name', $user_id);

            if ($first_name || $last_name) {
                $name = join(' ', array($first_name, $last_name));
            }
            else {
                $name = $user->display_name;
            }
        }

        return $name;
    }

    /**
     * Check if user has at least one of the provided roles
     *
     * @access public
     * @param array $roles
     * @param int|object|null $user
     * @return bool
     */
    public static function user_has_role($roles, $user = null)
    {
        // Get user
        if (!is_object($user)) {
            $user = empty($user) ? wp_get_current_user() : get_userdata($user);
        }

        // No user?
        if (empty($user)) {
            return false;
        }

        return array_intersect($roles, (array) $user->roles) ? true : false;
    }

    /**
     * Get all user capabilities
     *
     * @access public
     * @param int|object|null $user
     * @return array
     */
    public static function get_user_capabilities($user = null)
    {
        $capabilities = array();

        // Get user
        if (!is_object($user)) {
            $user = empty($user) ? wp_get_current_user() : get_userdata($user);
        }

        // No user?
        if (empty($user)) {
            return array();
        }

        // Extract capabilities
        foreach ($user->allcaps as $cap_key => $cap) {
            if ($cap) {
                $capabilities[] = $cap_key;
            }
        }

        return (array) apply_filters('woocommerce_membership_user_capabilities', $capabilities, $user);
    }

    /**
     * Get enabled plan keys of user
     *
     * @access public
     * @param mixed $user
     * @return array
     */
    public static function get_enabled_keys($user = null)
    {
        $capabilities = self::get_user_capabilities($user);
        return WooCommerce_Membership_Plan::enabled_keys_only($capabilities);
    }

    /**
     * Check if user is a member of given plan
     *
     * @access public
     * @param int $user_id
     * @param int $plan_id
     * @return array
     */
    public static function is_member($user_id, $plan_id)
    {
        // Get user
        $user = get_userdata($user_id);

        // Get plan key
        $plan_key = get_post_meta($plan_id, 'key', true);

        // Get user keys
        $enabled_keys = self::get_enabled_keys($user);

        return array_intersect($enabled_keys, (array) $plan_key) ? true : false;
    }

    /**
     * Get all plan keys of user
     *
     * @access public
     * @return array
     */
    public static function get_all_keys()
    {
        $capabilities = WooCommerce_Membership_User::get_user_capabilities();
        $all_plan_keys = WooCommerce_Membership_Plan::get_list_of_all_plan_keys();
        $user_plans = array();

        foreach (array_keys($all_plan_keys) as $plan_key) {

            if (in_array($plan_key, $capabilities)) {
                $user_plans[] = $plan_key;
            }
        }

        return $user_plans;
    }

    /**
     * Get list of all users
     *
     * @access public
     * @return array
     */
    public static function get_all_users()
    {
        $users = array('' => '');

        foreach(get_users() as $user) {
            $users[$user->ID] = '#' . $user->ID . ' - ' . $user->user_email;
        }

        return $users;
    }

    /**
     * Generate a CSV file containing members of specific plan and push it to browser
     *
     * @access public
     * @return void
     */
    public function push_members_csv()
    {
        // Check if current user can download a list of members
        if (!WooCommerce_Membership::is_authorized('csv_export')) {
            return;
        }

        $plan_key = $_GET['wcm_members_csv'];

        // Check if valid plan key was passed in
        if (!WooCommerce_Membership_Plan::key_exists($plan_key)) {
            return;
        }

        // Compose file name
        $filename = 'Members_' . $plan_key . '_' . date('Y-m-d') . '.csv';

        // Send headers
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=' . $filename);

        // Disable caching
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");

        // Open writable stream
        $output = fopen('php://output', 'w');

        // Output CSV headers
        fputcsv($output, array(
            __('User ID', 'woocommerce-membership'),
            __('Username', 'woocommerce-membership'),
            __('Full Name', 'woocommerce-membership'),
            __('Email', 'woocommerce-membership'),
            __('Member Since', 'woocommerce-membership'),
            __('Membership Expires', 'woocommerce-membership'),
        ));

        // Output members
        foreach (self::get_members_list_for_csv($plan_key) as $member) {
            fputcsv($output, $member);
        }

        // Close stream
        fclose($output);
        exit;
    }

    /**
     * Get list of members of specific membership plan ready to be used in CSV export
     *
     * @access public
     * @param string $plan_key
     * @return void
     */
    public static function get_members_list_for_csv($plan_key)
    {
        $members = array();

        // Get list of members and iterate over it
        foreach (WooCommerce_Membership_Plan::get_members_list($plan_key, array('user_login')) as $member) {

            // Get full name
            $full_name = self::get_user_full_name($member->ID);

            // Get Member Since date
            $member_since = get_user_meta($member->ID, '_rpwcm_' . $plan_key . '_since', true);
            $member_since = WooCommerce_Membership::get_adjusted_datetime($member_since);

            // Get Member Expires date
            if ($expires = get_user_meta($member->ID, '_rpwcm_' . $plan_key . '_expires', true)) {
                $expires = WooCommerce_Membership::get_adjusted_datetime($expires);
            }
            else {
                $expires = __('Never', 'woocommerce-membership');
            }

            $members[] = array(
                $member->ID,
                $member->user_login,
                $full_name,
                $member->user_email,
                $member_since,
                $expires,
            );
        }

        return $members;
    }

}

new WooCommerce_Membership_User();

}