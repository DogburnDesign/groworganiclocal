<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Membership Plan object class
 *
 * @class WooCommerce_Membership_Plan
 * @package WooCommerce_Membership
 * @author RightPress
 */
if (!class_exists('WooCommerce_Membership_Plan')) {

class WooCommerce_Membership_Plan
{
    private static $post_type = 'membership_plan';
    private static $cache = array();
    private static $linked_plans_loop_protection = array();
    public static $all_plans;
    public static $all_plan_keys;
    public static $all_capabilities;

    /**
     * Constructor class
     *
     * @access public
     * @param mixed $id
     * @return void
     */
    public function __construct($id = null)
    {
        if ($id === null) {

            // Hook some actions on init
            add_action('init', array($this, 'on_init'), 99);

            // Actions related to this post type
            add_action('restrict_manage_posts', array($this, 'add_list_filters'));
            add_filter('parse_query', array($this, 'handle_list_filter_queries'));
            add_action('manage_membership_plan_posts_columns', array($this, 'manage_list_columns'));
            add_action('manage_membership_plan_posts_custom_column', array($this, 'manage_list_column_values'), 10, 2);
            add_filter('views_edit-membership_plan', array($this, 'manage_list_views'));
            add_filter('bulk_actions-edit-membership_plan', array($this, 'manage_list_bulk_actions'));
            add_filter('posts_join', array($this, 'expand_list_search_context_join'));
            add_filter('posts_where', array($this, 'expand_list_search_context_where'));
            add_filter('posts_groupby', array($this, 'expand_list_search_context_group_by'));
            add_action('save_post', array($this, 'save_meta_box'), 9, 2);
            add_action('before_delete_post', array($this, 'post_deleted'));
            add_action('trashed_post', array($this, 'post_trashed'));

            // Integration with WooCommerce Dynamic Pricing & Discounts plugin
            add_filter('rp_wcdpd_capability_list', array($this, 'extend_capability_list'));

            // Ajax handlers
            add_action('wp_ajax_get_membership_plan_key', array($this, 'ajax_get_membership_plan_key'));
            add_action('wp_ajax_change_expiration_date', array($this, 'ajax_change_expiration_date'));
            add_action('wp_ajax_change_expiration_never', array($this, 'ajax_change_expiration_never'));
            add_action('wp_ajax_rpwcm_user_search', array($this, 'ajax_user_search'));

            // Handle manual member / linked plan removal
            if ($_SERVER['REQUEST_METHOD'] == 'GET') {
                if (isset($_GET['rpwcm_remove_member'])) {
                    add_action('admin_init', array($this, 'remove_member_manually'));
                }
                if (isset($_GET['rpwcm_remove_linked_plan'])) {
                    add_action('admin_init', array($this, 'remove_linked_plan'));
                }
            }
        }
        else {
            $this->id = $id;
            $this->populate();
        }
    }

    /**
     * Run on WP init
     *
     * @access public
     * @return void
     */
    public function on_init()
    {
        $this->add_post_type();
    }

    /**
     * Add membership_plan post type
     *
     * @access public
     * @return void
     */
    public function add_post_type()
    {
        // Define labels
        $labels = array(
            'name'               => __('Membership Plans', 'woocommerce-membership'),
            'singular_name'      => __('Membership Plan', 'woocommerce-membership'),
            'add_new'            => __('Add Plan', 'woocommerce-membership'),
            'add_new_item'       => __('Add Membership Plan', 'woocommerce-membership'),
            'edit_item'          => __('Edit Plan', 'woocommerce-membership'),
            'new_item'           => __('New Membership Plan', 'woocommerce-membership'),
            'all_items'          => __(' Plans', 'woocommerce-membership'),
            'view_item'          => __('View Membership Plan', 'woocommerce-membership'),
            'search_items'       => __('Search Plans', 'woocommerce-membership'),
            'not_found'          => __('No Plans Found', 'woocommerce-membership'),
            'not_found_in_trash' => __('No Plans Found In Trash', 'woocommerce-membership'),
            'parent_item_colon'  => '',
            'menu_name'          => __('Membership', 'woocommerce-membership'),
        );

        // Define settings
        $args = array(
            'labels'               => $labels,
            'description'          => __('WooCommerce Membership Plans', 'woocommerce-membership'),
            'public'               => false,
            'show_ui'              => true,
            'menu_position'        => 56,
            'capability_type'      => 'post',
            'capabilities'         => array(
                //'create_posts'     => true,
            ),
            'map_meta_cap'         => true,
            'supports'             => array('title'),
            'register_meta_box_cb' => array($this, 'add_meta_boxes'),
        );

        // Register new post type
        register_post_type(self::$post_type, $args);

        // Register custom taxonomy (membership status)
        register_taxonomy('plan_status', self::$post_type, array(
            'label'             => __('Status', 'woocommerce-membership'),
            'labels'            => array(
                'name'          => __('Status', 'woocommerce-membership'),
                'singular_name' => __('Status', 'woocommerce-membership'),
            ),
            'public'            => false,
            'show_admin_column' => true,
            'query_var'         => true,
        ));

        // Register custom terms - membership plan status
        foreach (WooCommerce_Membership_Plan::get_statuses() as $status_key => $status) {
            if (!term_exists($status_key, 'plan_status')) {
                wp_insert_term($status['title'], 'plan_status', array(
                    'slug' => $status_key,
                ));
            }
        }

        // Change some default behavior, values etc.
        add_filter('enter_title_here', array($this, 'enter_title_here'));
    }

    /**
     * Change "Enter title here" text
     *
     * @access public
     * @param string $title
     * @return string
     */
    public function enter_title_here($title)
    {
        global $typenow;

        if ($typenow == self::$post_type) {
            $title = __('Enter plan name here', 'woocommerce-membership');
        }

        return $title;
    }

    /**
     * Return membership plan key from title
     *
     * @access public
     * @return string
     */
    public function ajax_get_membership_plan_key()
    {
        if (isset($_POST['data']) && $title = self::create_key_from_title($_POST['data'])) {
            echo json_encode(array(
                'error' => 0,
                'title' => $title,
            ));
            exit;
        }

        echo json_encode(array(
            'error' => 1
        ));
        exit;
    }

    /**
     * Change user's membership expire date
     *
     * @access public
     * @return void
     */
    public function ajax_change_expiration_date()
    {
        // Check if current user can edit membership plan settings
        if (!WooCommerce_Membership::is_authorized('plan_edit')) {
            return;
        }

        // Get variables
        $user_id    = $_POST['user_id'];
        $plan_key   = $_POST['plan_key'];
        $plan_id    = $_POST['plan_id'];
        $date       = $_POST['date'];

        // Get current expiration timestamp (if any; so we can get time from it)
        $current_expiration_date = get_user_meta($user_id, '_rpwcm_' . $plan_key . '_expires', true);

        // Either set timezone-adjusted time from the existing expiration time or set it to just before midnight
        if ($current_expiration_date) {
            $dt     = WooCommerce_Membership::get_datetime_object('@' . $current_expiration_date);
            $hour   = $dt->format('H');
            $minute = $dt->format('i');
            $second = $dt->format('s');
        }
        else {
            $hour   = 23;
            $minute = 59;
            $second = 59;
        }

        // Get timestamp of the new date
        $adjusted_expiration_date = WooCommerce_Membership::get_datetime_object($date);
        $adjusted_expiration_date->setTime($hour, $minute, $second);
        $adjusted_expiration_date = $adjusted_expiration_date->format('U');

        // Update user meta
        update_user_meta($user_id, '_rpwcm_' . $plan_key . '_expires', $adjusted_expiration_date);

        // Get current expiration event
        $old_scheduled_expiration = wp_next_scheduled('woocommerce_membership_scheduled_expiration', array((int) $plan_id, (int) $user_id));

        // Reschedule expiration event
        WooCommerce_Membership_Scheduler::unschedule_expiration($plan_id, $user_id);
        WooCommerce_Membership_Scheduler::schedule_expiration($plan_id, $user_id, $adjusted_expiration_date);

        // Reschedule reminders if no subscription support is set
        if (!apply_filters('woocommerce_membership_subscription_support', false)) {
            WooCommerce_Membership_Scheduler::unschedule_reminders($plan_id, $user_id, $old_scheduled_expiration);
            WooCommerce_Membership_Scheduler::schedule_reminders($plan_id, $user_id);
        }

        echo json_encode(array(
            'newdate' => WooCommerce_Membership::get_adjusted_datetime($adjusted_expiration_date),
        ));

        exit;
    }

    /**
     * Change user's membership expire date to never
     *
     * @access public
     * @return void
     */
    public function ajax_change_expiration_never()
    {
        // Check if current user can edit membership plan settings
        if (!WooCommerce_Membership::is_authorized('plan_edit')) {
            return;
        }

        // Get variables
        $user_id    = $_POST['user_id'];
        $plan_key   = $_POST['plan_key'];
        $plan_id    = $_POST['plan_id'];

        // Delete user meta
        delete_user_meta($user_id, '_rpwcm_' . $plan_key . '_expires');

        // Clear expiration event
        WooCommerce_Membership_Scheduler::unschedule_expiration($plan_id, $user_id);

        echo json_encode(array(
            'newdate' => __('Never', 'woocommerce-membership'),
        ));

        exit;
    }

    /**
     * Add meta boxes
     *
     * @access public
     * @param mixed $post
     * @return void
     */
    public function add_meta_boxes($post)
    {
        // General membership plan details block
        add_meta_box(
            'rpwcm_membership_plan_details',
            __('Membership Plan Details', 'woocommerce-membership'),
            array($this, 'render_meta_box_details'),
            'membership_plan',
            'normal',
            'high'
        );

        // Related products
        add_meta_box(
            'rpwcm_membership_plan_products',
            __('Related Products', 'woocommerce-membership'),
            array($this, 'render_meta_box_products'),
            'membership_plan',
            'normal',
            'high'
        );

        // Members
        add_meta_box(
            'rpwcm_membership_plan_members',
            __('Members', 'woocommerce-membership'),
            array($this, 'render_meta_box_members'),
            'membership_plan',
            'normal',
            'high'
        );

        // Linked Plans
        add_meta_box(
            'rpwcm_membership_plan_linked_plans',
            __('Linked Plans', 'woocommerce-membership'),
            array($this, 'render_meta_box_linked_plans'),
            'membership_plan',
            'normal',
            'high'
        );

        // Membership_plan actions
        add_meta_box(
            'rpwcm_membership_plan_actions',
            __('Plan Actions', 'woocommerce-membership'),
            array($this, 'render_meta_box_actions'),
            'membership_plan',
            'side',
            'default'
        );

        // Grant Access Manually (only display for existing plans)
        global $post;
        $plan = self::cache($post->ID);

        if ($plan && gettype($plan) === 'object' && isset($plan->status)) {
            add_meta_box(
                'rpwcm_membership_plan_grant_access',
                __('Grant Access Manually', 'woocommerce-membership'),
                array($this, 'render_meta_grant_access'),
                'membership_plan',
                'side',
                'default'
            );
        }
    }

    /**
     * Render membership_plan edit page meta box Membership Details content
     *
     * @access public
     * @param mixed $post
     * @return void
     */
    public function render_meta_box_details($post)
    {
        $plan = self::cache($post->ID);

        if (!$plan) {
            return;
        }

        // Get membership plan statuses
        $plan_statuses = WooCommerce_Membership_Plan::get_statuses();

        // Load view
        include RPWCM_PLUGIN_PATH . '/includes/views/backend/plan/details.php';
    }

    /**
     * Render membership plan edit page meta box Membership Products content
     *
     * @access public
     * @param mixed $post
     * @return void
     */
    public function render_meta_box_products($post)
    {
        $plan = self::cache($post->ID);

        if (!$plan) {
            return;
        }

        // Get membership plan products
        $products = $plan->get_products(true);

        // Load view
        include RPWCM_PLUGIN_PATH . '/includes/views/backend/plan/products.php';
    }

    /**
     * Render membership plan edit page meta box Membership Members content
     *
     * @access public
     * @param mixed $post
     * @return void
     */
    public function render_meta_box_members($post)
    {
        $plan = self::cache($post->ID);

        if (!$plan) {
            return;
        }

        // Check plan key
        $no_key = empty($plan->key) ? true : false;

        // Set number of users per page
        $users_per_page = apply_filters('woocommerce_membership_plan_users_per_page', 25);

        // Get the current page from GET (get_query_var('paged') is not working here)
        $paged = isset($_GET['paged']) ? $_GET['paged'] : 1;

        // Count the offset
        $offset = $paged ? $users_per_page * ($paged - 1) : 0;

        // Handle the search
        if (!empty($_GET['rpwcm_search'])) {

            // Save search query
            $search_query = $_GET['rpwcm_search'];

            // Count all found members
            $members_count = $no_key ? 0 : count(self::members_search($plan->key, $search_query, array(), 0, 0));

            // Make search with pagination support
            $members = $no_key ? array() : self::members_search($plan->key, $search_query, array(), $offset, $users_per_page);

            $pagination_title = sprintf(__('Showing search results (%s) ', 'woocommerce-membership'), $members_count);
        }
        // Display the regular list
        else {
            // Count all members of plan
            $members_count = $no_key ? 0 : self::count_members($plan->key);

            // Query the members list
            $members = $no_key ? array() : self::get_members_list($plan->key, array(), $offset, $users_per_page);
        }

        // Create the pagination links if the amount of members is more than page limit
        if ($members_count > $users_per_page) {

          $paginate_links = paginate_links(array(
              'base'       => add_query_arg('paged','%#%'),
              'format'     => '',
              'total'      => ceil($members_count / $users_per_page),
              'current'    => max(1, $paged),
          ));
        }

        // Check variables
        $paginate_links = isset($paginate_links) ? $paginate_links : '';
        $search_query =  isset($search_query) ? $search_query : '';
        $pagination_title =  isset($pagination_title) ? $pagination_title : '';

        // Load view
        include RPWCM_PLUGIN_PATH . '/includes/views/backend/plan/members.php';
    }

    /**
     * Render membership plan edit page meta box Membership Linked Plans content
     *
     * @access public
     * @param mixed $post
     * @return void
     */
    public function render_meta_box_linked_plans($post)
    {
        $plan = self::cache($post->ID);

        if (!$plan) {
            return;
        }

        // Load view
        include RPWCM_PLUGIN_PATH . '/includes/views/backend/plan/linked-plans.php';
    }

    /**
     * Render membership plan edit page meta box Membership Actions content
     *
     * @access public
     * @param mixed $post
     * @return void
     */
    public function render_meta_box_actions($post)
    {
        $plan = self::cache($post->ID);

        if (!$plan) {
            return;
        }

        // Get membership plan actions
        $actions = $plan->get_actions();

        // Load view
        include RPWCM_PLUGIN_PATH . '/includes/views/backend/plan/actions.php';
    }

    /**
     * Render membership plan edit page meta box Grant Access Manually content
     *
     * @access public
     * @param mixed $post
     * @return void
     */
    public function render_meta_grant_access($post)
    {
        $plan = self::cache($post->ID);

        if (!$plan) {
            return;
        }

        // Load view
        include RPWCM_PLUGIN_PATH . '/includes/views/backend/plan/grant-access.php';
    }

    /**
     * Save custom fields from edit page
     *
     * @access public
     * @param int $post_id
     * @param object $post
     * @return void
     */
    public function save_meta_box($post_id, $post)
    {
        // Check if required properties were passed in
        if (empty($post_id) || empty($post)) {
            return;
        }

        // Make sure user has permissions to edit this post
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Make sure the correct post ID was passed from form
        if (empty($_POST['post_ID']) || $_POST['post_ID'] != $post_id) {
            return;
        }

        // Make sure it is not a draft save action
        if (defined('DOING_AUTOSAVE') || is_int(wp_is_post_autosave($post)) || is_int(wp_is_post_revision($post))) {
            return;
        }

        // Proceed only if post type is membership plan
        if ($post->post_type != 'membership_plan') {
            return;
        }

        $plan = self::cache($post_id);

        if (!$plan) {
            return;
        }

        // Add member
        if (!empty($_POST['rpwcm_plan_button']) && $_POST['rpwcm_plan_button'] == 'members' && !empty($_POST['rpwcm_plan_grant_access_to_user'])) {
            self::add_member($post_id, (int) $_POST['rpwcm_plan_grant_access_to_user']);
        }

        // Link plan
        else if (!empty($_POST['rpwcm_plan_button']) && $_POST['rpwcm_plan_button'] == 'linked_plans' && !empty($_POST['rpwcm_plan_add_linked_plan'])) {
            self::link_plan($post_id, (int) $_POST['rpwcm_plan_add_linked_plan']);
        }

        // Other actions
        else {

            // Get action
            if (!empty($_POST['rpwcm_plan_button']) && $_POST['rpwcm_plan_button'] == 'actions' && !empty($_POST['rpwcm_plan_actions'])) {
                $action = $_POST['rpwcm_plan_actions'];
            }
            else {
                $action = 'save';
            }

            // Proceed depending on action
            switch ($action) {

                // Save
                case 'save':

                    if (empty($_POST['post_title'])) {
                        return;
                    }

                    // Prevent infinite loop
                    remove_action('save_post', array($this, 'save_meta_box'), 9, 2);

                    // New post?
                    if ($post->post_status == 'draft') {
                        wp_publish_post($post_id);
                        $plan->update_field('name', $_POST['post_title']);
                        $plan->update_field('status', 'enabled');
                        $plan->update_field('key', self::create_key_from_title($_POST['post_title']));
                    }

                    // Existing post
                    else if (isset($_POST['title']) && $_POST['title'] != $plan->name) {

                        // Update title only and only if it does not match current title
                        $plan->update_field('name', $_POST['post_title']);

                        // Update linked plan time values
                        if (isset($_POST['_rpwcm_linked_plan_time_value']) && !empty($_POST['_rpwcm_linked_plan_time_value'])) {
                            foreach ($_POST['_rpwcm_linked_plan_time_value'] as $linked_plan_key => $linked_plan_time_value) {
                                update_post_meta($plan->id, 'linked_plan_' . $linked_plan_key . '_time_value', $linked_plan_time_value);
                            }
                        }

                        // Update linked plan time units
                        $time_units = WooCommerce_Membership::get_time_units();

                        if (isset($_POST['_rpwcm_linked_plan_time_unit']) && !empty($_POST['_rpwcm_linked_plan_time_unit'])) {
                            foreach ($_POST['_rpwcm_linked_plan_time_unit'] as $linked_plan_key => $linked_plan_time_unit) {
                                $linked_plan_time_unit = isset($time_units[$linked_plan_time_unit]) ? $linked_plan_time_unit : 'day';
                                update_post_meta($plan->id, 'linked_plan_' . $linked_plan_key . '_time_unit', $linked_plan_time_unit);
                            }
                        }
                    }

                    add_action('save_post', array($this, 'save_meta_box'), 9, 2);

                    break;

                // Disable
                case 'disable':
                    $plan->update_field('status', 'disabled');
                    break;

                // Enable
                case 'enable':
                    $plan->update_field('status', 'enabled');
                    break;

                default:
                    break;
            }
        }

    }

    /**
     * Link plan to other plan
     *
     * @access public
     * @param int $parent_plan_id
     * @param int $child_plan_id
     * @return void
     */
    public static function link_plan($parent_plan_id, $child_plan_id)
    {
        $existing_plans = (array) WooCommerce_Membership::unwrap_post_meta(get_post_meta($parent_plan_id, 'linked_plans'));

        if (!in_array($child_plan_id, $existing_plans)) {
            add_post_meta($parent_plan_id, 'linked_plans', $child_plan_id);
            add_post_meta($parent_plan_id, 'linked_plan_' . $child_plan_id . '_time_value', 0);
            add_post_meta($parent_plan_id, 'linked_plan_' . $child_plan_id . '_time_unit', 'day');
        }
    }

    /**
     * Unlink plan from other plan
     *
     * @access public
     * @param int $parent_plan_id
     * @param int $child_plan_id
     * @return void
     */
    public static function unlink_plan($parent_plan_id, $child_plan_id)
    {
        delete_post_meta($parent_plan_id, 'linked_plans', $child_plan_id);
        delete_post_meta($parent_plan_id, 'linked_plan_' . $child_plan_id . '_time_value');
        delete_post_meta($parent_plan_id, 'linked_plan_' . $child_plan_id . '_time_unit');
    }

    /**
     * Get plan's name
     *
     * @access public
     * @param obj $plan
     * @return string
     */
    public static function get_plan_name($plan)
    {
        // Get the name of plan
        $plan_name = $plan->name;

        // Get name from post if not set
        if (!$plan_name) {
            $plan_name = get_the_title($plan->id);
        }

        return $plan_name;
    }

    /**
     * Add filtering capabilities
     *
     * @access public
     * @return void
     */
    public function add_list_filters()
    {
        global $typenow;
        global $wp_query;

        if ($typenow != self::$post_type) {
            return;
        }

        // Extract selected filter options
        $selected = array();

        foreach (array('plan_status') as $taxonomy) {
            if (!empty($wp_query->query[$taxonomy]) && is_numeric($wp_query->query[$taxonomy])) {
                $selected[$taxonomy] = $wp_query->query[$taxonomy];
            }
            else if (!empty($wp_query->query[$taxonomy])) {
                $term = get_term_by('slug', $wp_query->query[$taxonomy], $taxonomy);
                $selected[$taxonomy] = $term ? $term->term_id : 0;
            }
            else {
                $selected[$taxonomy] = 0;
            }
        }

        // Add statuses
        wp_dropdown_categories(array(
            'show_option_all'   =>  __('All statuses', 'woocommerce-membership'),
            'taxonomy'          =>  'plan_status',
            'name'              =>  'plan_status',
            'selected'          =>  $selected['plan_status'],
            'show_count'        =>  true,
            'hide_empty'        =>  false,
        ));
    }

    /**
     * Handle list filter queries
     *
     * @access public
     * @param object $query
     * @return void
     */
    public function handle_list_filter_queries($query)
    {
        global $pagenow;
        global $typenow;

        if ($pagenow != 'edit.php' || $typenow != self::$post_type) {
            return;
        }

        $qv = &$query->query_vars;

        foreach (array('plan_status') as $taxonomy) {
            if (isset($qv[$taxonomy]) && is_numeric($qv[$taxonomy]) && $qv[$taxonomy] != 0) {
                $term = get_term_by('id', $qv[$taxonomy], $taxonomy);
                $qv[$taxonomy] = $term->slug;
            }
        }
    }

    /**
     * Manage list columns
     *
     * @access public
     * @param array $columns
     * @return array
     */
    public function manage_list_columns($columns)
    {
        $new_columns = array();

        foreach ($columns as $column_key => $column) {
            $allowed_columns = array(
                'cb',
            );

            if (in_array($column_key, $allowed_columns)) {
                $new_columns[$column_key] = $column;
            }
        }

        $new_columns['name']        = __('Name', 'woocommerce-membership');
        $new_columns['key']         = __('Key', 'woocommerce-membership');
        $new_columns['status']      = __('Status', 'woocommerce-membership');
        $new_columns['products']    = __('Products', 'woocommerce-membership');
        $new_columns['members']     = __('Members', 'woocommerce-membership');
        $new_columns['plans']       = __('Linked Plans', 'woocommerce-membership');

        return $new_columns;
    }

    /**
     * Manage list column values
     *
     * @access public
     * @param array $column
     * @param int $post_id
     * @return void
     */
    public function manage_list_column_values($column, $post_id)
    {
        $plan = self::cache($post_id);

        switch ($column) {

            case 'name':
                WooCommerce_Membership::print_link_to_post($plan->id, $plan->name);
                break;

            case 'key':
                echo '<code>' . $plan->key . '</code>';
                break;

            case 'status':
                echo '<a class="membership_plan_status_' . $plan->status . '" href="edit.php?post_type=membership_plan&amp;plan_status=' . $plan->status . '">' . $plan->status_title . '</a>';
                break;

            case 'products':
                $product_count = $plan->get_product_count();

                if ($product_count == 0) {
                    echo 0;
                }
                else {
                    echo '<a href="edit.php?post_type=product&amp;membership_plan=' . $post_id . '">' . $product_count . '</a>';
                }
                break;

            case 'members':
                $member_count = $plan->get_member_count();

                if ($member_count == 0) {
                    echo 0;
                }
                else {
                    echo '<a href="users.php?role=' . $plan->key . '">' . $member_count . '</a>';
                }
                break;

            case 'plans':
                $plans_count = count($plan->linked_plans);

                echo $plans_count;
                break;

            default:
                break;
        }
    }

    /**
     * Manage list bulk actions
     *
     * @access public
     * @param array $actions
     * @return array
     */
    public function manage_list_bulk_actions($actions)
    {
        $new_actions = array();

        foreach ($actions as $action_key => $action) {
            if (in_array($action_key, array('trash', 'untrash', 'delete'))) {
                $new_actions[$action_key] = $action;
            }
        }

        return $new_actions;
    }

    /**
     * Manage list views
     *
     * @access public
     * @param array $views
     * @return array
     */
    public function manage_list_views($views)
    {
        $new_views = array();

        foreach ($views as $view_key => $view) {
            if (in_array($view_key, array('all', 'trash'))) {
                $new_views[$view_key] = $view;
            }
        }

        return $new_views;
    }

    /**
     * Expand list search context
     *
     * @access public
     * @param string $join
     * @return string
     */
    public function expand_list_search_context_join($join)
    {
        global $typenow;
        global $pagenow;
        global $wpdb;

        if ($pagenow == 'edit.php' && $typenow == 'membership_plan' && isset($_GET['s']) && $_GET['s'] != '') {
            $join .= 'LEFT JOIN ' . $wpdb->postmeta . ' ON ' . $wpdb->posts . '.ID = ' . $wpdb->postmeta . '.post_id ';
        }

        return $join;
    }

    /**
     * Expand list search context with more fields
     *
     * @access public
     * @param string $where
     * @return string
     */
    public function expand_list_search_context_where($where)
    {
        global $typenow;
        global $pagenow;
        global $wpdb;

        // Define post types with search contexts, meta field whitelist (searchable meta fields) etc
        $post_types = array(
            'membership_plan' => array(
                'contexts' => array(
                ),
                'meta_whitelist' => array(
                ),
            ),
        );

        // Search
        if ($pagenow == 'edit.php' && isset($_GET['post_type']) && isset($post_types[$_GET['post_type']]) && !empty($_GET['s'])) {

            $search_phrase = trim($_GET['s']);
            $exact_match = false;
            $context = null;

            // Exact match?
            if (preg_match('/^\".+\"$/', $search_phrase) || preg_match('/^\'.+\'$/', $search_phrase)) {
                $exact_match = true;
                $search_phrase = substr($search_phrase, 1, -1);
            }
            else if (preg_match('/^\\\\\".+\\\\\"$/', $search_phrase) || preg_match('/^\\\\\'.+\\\\\'$/', $search_phrase)) {
                $exact_match = true;
                $search_phrase = substr($search_phrase, 2, -2);
            }
            // Or search with context?
            else {
                foreach ($post_types[$_GET['post_type']]['contexts'] as $context_key => $context_value) {
                    if (preg_match('/^' . $context_key . '\:/i', $search_phrase)) {
                        $context = $context_value;
                        $search_phrase = trim(preg_replace('/^' . $context_key . '\:/i', '', $search_phrase));
                        break;
                    }
                }
            }

            // Search by ID?
            if ($context == 'ID') {
                $replacement = $wpdb->prepare(
                    '(' . $wpdb->posts . '.ID LIKE %s)',
                    $search_phrase
                );
            }

            // Search within other context
            else if ($context) {
                $replacement = $wpdb->prepare(
                    '(' . $wpdb->postmeta . '.meta_key LIKE %s) AND (' . $wpdb->postmeta . '.meta_value LIKE %s)',
                    $context,
                    $search_phrase
                );
            }

            // Regular search
            else {
                $whitelist = $wpdb->postmeta . '.meta_key IN (\'' . join('\', \'', $post_types[$_GET['post_type']]['meta_whitelist']) . '\')';

                // Exact match?
                if ($exact_match) {
                    $replacement = $wpdb->prepare(
                        '(' . $wpdb->posts . '.ID LIKE %s) OR (' . $wpdb->postmeta . '.meta_value LIKE %s)',
                        $search_phrase,
                        $search_phrase
                    );
                    $replacement = '(' . $whitelist . ' AND ' . $replacement . ')';

                }

                // Regular match
                else {
                    $replacement = '(' . $whitelist . ' AND ((' . $wpdb->posts . '.ID LIKE $1) OR (' . $wpdb->postmeta . '.meta_value LIKE $1)))';
                }
            }

            $where = preg_replace('/\(\s*' . $wpdb->posts . '.post_title\s+LIKE\s*(\'[^\']+\')\s*\)/', $replacement, $where);
        }

        return $where;
    }

    /**
     * Expand list search context with more fields - group results by id
     *
     * @access public
     * @param string $groupby
     * @return string
     */
    public function expand_list_search_context_group_by($groupby)
    {
        global $typenow;
        global $pagenow;
        global $wpdb;

        if ($pagenow == 'edit.php' && $typenow == 'membership_plan' && isset($_GET['s']) && $_GET['s'] != '') {
            $groupby = $wpdb->posts . '.ID';
        }

        return $groupby;
    }

    /**
     * Load object from cache
     *
     * @access public
     * @param string $type
     * @param int $id
     * @return object
     */
    public static function cache($id)
    {
        if (!isset(self::$cache[$id])) {

            $object = new self($id);

            if (!$object) {
                return false;
            }

            self::$cache[$id] = $object;
        }

        return self::$cache[$id];
    }

    /**
     * Popuplate existing plan object with properties
     *
     * @access public
     * @return void
     */
    public function populate()
    {
        if (!$this->id) {
            return false;
        }

        // Get post
        $post = get_post($this->id);

        if (!in_array($post->post_status, array('publish', 'trash'))) {
            return;
        }

        // Get status
        $statuses = self::get_statuses();
        $post_terms = wp_get_post_terms($this->id, 'plan_status');
        $this->status = $post_terms[0]->slug;
        $this->status_title = $statuses[$this->status]['title'];

        // Get other fields
        $post_meta = WooCommerce_Membership::unwrap_post_meta(get_post_meta($this->id));

        // Name
        $this->name = get_the_title($this->id);

        // Key
        $this->key = isset($post_meta['key']) ? $post_meta['key'] : '';

        // Linked plans
        $this->linked_plans = array();

        if (isset($post_meta['linked_plans']) && !empty($post_meta['linked_plans'])) {
            foreach ((array) $post_meta['linked_plans'] as $plan_id) {
                $plan = self::cache($plan_id);

                if (isset($plan->key)) {
                    $this->linked_plans[$plan_id] = array(
                        'name'          => $plan->name,
                        'key'           => $plan->key,
                        'time_value'    => !empty($post_meta['linked_plan_' . $plan_id . '_time_value']) ? $post_meta['linked_plan_' . $plan_id . '_time_value'] : 0,
                        'time_unit'     => !empty($post_meta['linked_plan_' . $plan_id . '_time_unit']) ? $post_meta['linked_plan_' . $plan_id . '_time_unit'] : 'day',
                    );
                }
            }
        }
    }

    /**
     * Get related product count
     *
     * @access public
     * @return int
     */
    public function get_product_count()
    {
        return count($this->get_products(false, false));
    }

    /**
     * Get member count
     *
     * @access public
     * @return int
     */
    public function get_member_count()
    {
        if (!empty($this->key)) {

            global $wpdb;

            // Fetch user count
            $query = new WP_User_Query(array(
                'number'        => 1,
                'offset'        => 0,
                'fields'        => 'ID',
                'count_total'   => true,
                'meta_key'       => $wpdb->prefix . 'capabilities',
                'meta_value'     => serialize(strval($this->key)),
                'meta_compare'   => 'LIKE',
            ));

            if ($query && $query->total_users) {
                return (int) $query->total_users;
            }

        }

        return 0;
    }

    /**
     * Update single Membership Plan field
     *
     * @access public
     * @return void
     */
    public function update_field($field, $value)
    {
        $this->$field = $value;

        switch ($field) {

            case 'status':

                $statuses = self::get_statuses();

                if (isset($statuses[$value])) {
                    $this->status_title = $statuses[$value]['title'];
                }

                wp_set_object_terms($this->id, $value, 'plan_status');

                break;

            case 'name':
                wp_update_post(array(
                    'ID'    => $this->id,
                    'title' => $value,
                ));
                break;

            default:
                update_post_meta($this->id, $field, $value);
                break;
        }
    }

    /**
     * Define and return all membership statuses
     *
     * @access public
     * @return array
     */
    public static function get_statuses()
    {
        return array(
            'enabled'   => array(
                'title' => __('enabled', 'woocommerce-membership'),
            ),
            'disabled'    => array(
                'title' => __('disabled', 'woocommerce-membership'),
            ),
        );
    }

    /**
     * Get array of actions available
     *
     * @access public
     * @return array
     */
    public function get_actions()
    {
        $actions = array();

        // Save plan details
        $actions['save'] = __('Save Plan', 'woocommerce-membership');

        // New plan?
        if (!isset($this->status)) {
            return $actions;
        }

        // Enable
        if ($this->status == 'disabled') {
            $actions['enable'] = __('Enable Plan', 'woocommerce-membership');
        }

        // Disable
        if ($this->status == 'enabled') {
            $actions['disable'] = __('Disable Plan', 'woocommerce-membership');
        }

        return $actions;
    }

    /**
     * Get array of IDs and names of WooCommerce Products that grant access to this membership plan
     *
     * @access public
     * @param bool $names
     * @param bool $include_trashed
     * @return array
     */
    public function get_products($names = true, $include_trashed = true)
    {
        $products = array();

        $statuses = array('publish', 'pending', 'draft', 'future', 'private');

        if ($include_trashed) {
            $statuses[] = 'trashed';
        }

        // Simple product IDs
        $query = new WP_Query(array(
            'post_type'         => 'product',
            'post_status'       => $statuses,
            'posts_per_page'    => -1,
            'fields'            => 'ids',
            'meta_query'        => array(
                array(
                    'key'       => '_rpwcm_plans',
                    'value'     => $this->id,
                    'compare'   => '=',
                ),
            ),
        ));

        // Iterate over simple product IDs and get their names
        foreach ($query->posts as $product_id) {
            $products[$product_id] = array(
                'main_id'   => $product_id,
                'title'     => $names ? self::get_formatted_product_name($product_id) : '',
                'type'      => __('Simple Product', 'woocommerce-membership'),
            );
        }

        // Product variation IDs
        $query = new WP_Query(array(
            'post_type'         => 'product_variation',
            'post_status'       => $statuses,
            'posts_per_page'    => -1,
            'fields'            => 'ids',
            'meta_query'        => array(
                array(
                    'key'       => '_rpwcm_plans',
                    'value'     => $this->id,
                    'compare'   => '=',
                ),
            ),
        ));

        // Iterate over variation IDs and get their names and parent IDs
        foreach ($query->posts as $variation_id) {
            $parent = get_post_ancestors($variation_id);

            if (!empty($parent[0])) {
                if ($include_trashed || !in_array(get_post_status($parent[0]), array('auto-draft', 'inherit', 'trash'))) {
                    $products[$variation_id] = array(
                        'main_id'   => (string) $parent[0],
                        'title'     => $names ? self::get_formatted_product_name($variation_id) : '',
                        'type'      => __('Product Variation', 'woocommerce-membership'),
                    );
                }
            }
        }

        return $products;
    }

    /**
     * Get formatted product name
     *
     * @access public
     * @param int $product_id
     * @return string
     */
    public function get_formatted_product_name($product_id)
    {
        $product = new WC_Product($product_id);
        return $product ? WooCommerce_Membership::get_product_name($product) : '';
    }


    /**
     * Count WordPress User IDs that are members of specific membership plan
     *
     * @access public
     * @param int $plan_key
     * @return array
     */
    public static function count_members($plan_key)
    {
        global $wpdb;

        // Fetch users
        $query = new WP_User_Query(array(
            'fields'        => array('ID'),
            'meta_key'      => $wpdb->prefix . 'capabilities',
            'meta_value'    => serialize(strval($plan_key)),
            'meta_compare'  => 'LIKE',
        ));

        return count($query->results);
    }

    /**
     * Get array of WordPress User IDs that are members of specific membership plan
     *
     * @access public
     * @param int $plan_key
     * @params array $additional_fields
     * @param int $offset
     * @param int $number
     * @return array
     */
    public static function get_members_list($plan_key, $additional_fields = array(), $offset = 0, $number = 25)
    {
        global $wpdb;

        // Fetch users
        $query = new WP_User_Query(array(
            'fields'        => array_unique(array_merge($additional_fields, array('ID', 'user_email'))),
            'meta_key'      => $wpdb->prefix . 'capabilities',
            'meta_value'    => serialize(strval($plan_key)),
            'meta_compare'  => 'LIKE',
            'offset'        => $offset,
            'number'        => $number,
        ));

        return $query->results;
    }

    /**
     * Search the members of specific membership plan by name and email
     *
     * @access public
     * @param int $plan_key
     * @param string $search_query
     * @params array $additional_fields
     * @param int $offset
     * @param int $number
     * @return array
     */
    public static function members_search($plan_key, $search_query, $additional_fields = array(), $offset = 0, $number = 25)
    {
        global $wpdb;

        if (!$search_query) {
            return false;
        }

        // Set the args
        $args = array(
            'fields'         => array_unique(array_merge($additional_fields, array('ID', 'user_email'))),
            'search'         => '*' . $search_query . '*',
            'search_columns' => array('user_login', 'user_email', 'user_nicename', 'display_name'),
            'meta_key'       => $wpdb->prefix . 'capabilities',
            'meta_value'     => serialize(strval($plan_key)),
            'meta_compare'   => 'LIKE',
            'offset'        => $offset,
        );

        // Check if limit should be set
        if ($number != 0) {
            $args['number'] = $number;
        }

        // Get the query
        $query = new WP_User_Query($args);

        return $query->results;
    }


    /**
     * AJAX search handler for Grant Access Manually box
     *
     * @access public
     * @return array
     */
    public function ajax_user_search()
    {
        // Check if query string is set
        if (isset($_POST['q'])) {

            // Get search query and plan key
            $search_query = $_POST['q'];
            $plan_key = isset($_POST['plan_key']) ? $_POST['plan_key'] : '';

            global $wpdb;

            // Perform search
            $query = new WP_User_Query(array(
                'fields'         => array('ID', 'user_email'),
                'search'         => '*' . $search_query . '*',
                'search_columns' => array('user_login', 'user_email', 'user_nicename', 'display_name'),
                'meta_key'       => $wpdb->prefix . 'capabilities',
                'meta_value'     => serialize(strval($plan_key)),
                'meta_compare'   => 'NOT LIKE',
            ));

            // Save results
            if (!empty($query->results)) {
                foreach ($query->results as $user) {
                    $user_title = '#' . $user->ID . ' - ' . $user->user_email;
                    $results[] = array('id' => $user->ID, 'text' => $user_title);
                }
            }
            // If no users found
            else {
                $results[] = array('id' => 0, 'text' => __('No users found', 'woocommerce-membership'), 'disabled' => 'disabled');
            }
        }
        // If no search query was sent
        else {
            $results[] = array('id' => 0, 'text' => __('No search query was sent', 'woocommerce-membership'), 'disabled' => 'disabled');
        }

        echo json_encode(array('results' => $results));
        die();
    }

    /**
     * Create key (used as a WordPress capability) from Membership Plan title
     *
     * @access public
     * @param string $title
     * @return string|bool
     */
    public static function create_key_from_title($title)
    {
        if (empty($title)) {
            return false;
        }

        $title = sanitize_title($title);
        $title = str_replace('-', '_', $title);

        $original_title = $title;
        $i = 1;

        while (self::capability_exists($title) || self::key_exists($title)) {
            $i++;
            $title = $original_title . '_' . $i;
        }

        return $title;
    }

    /**
     * Check if capability exists
     *
     * @access public
     * @param string $capability
     * @return bool
     */
    public static function capability_exists($capability)
    {
        if (in_array($capability, self::get_list_of_all_capabilities(), true)) {
            return true;
        }

        return false;
    }

    /**
     * Get list of all capabilities
     *
     * @access public
     * @return array
     */
    public static function get_list_of_all_capabilities()
    {
        if (!self::$all_capabilities) {
            self::$all_capabilities = array();

            global $wp_roles;

            foreach ($wp_roles->roles as $role) {
                foreach ($role['capabilities'] as $capability => $capability_enabled) {
                    self::$all_capabilities[$capability] = $capability;
                }
            }

        }

        return self::$all_capabilities;
    }

    /**
     * Check if such membership plan key exists
     *
     * @access public
     * @param string $key
     * @return bool
     */
    public static function key_exists($key)
    {
        $query = new WP_Query(array(
            'post_type'     => self::$post_type,
            'fields'        => 'ids',
            'meta_query'    => array(
                array(
                    'key'       => 'key',
                    'value'     => $key,
                    'compare'   => '=',
                ),
            ),
        ));

        return empty($query->posts) ? false : true;
    }

    /**
     * Get list of all plan keys for admin display
     *
     * @access public
     * @return array
     */
    public static function get_list_of_all_plan_keys()
    {
        if (!self::$all_plan_keys) {
            self::$all_plan_keys = array();

            foreach (self::get_list_of_all_plans() as $plan_id => $plan_title) {
                if ($key = get_post_meta($plan_id, 'key', true)) {
                    self::$all_plan_keys[$key] = $plan_title;
                }
            }
        }

        return self::$all_plan_keys;
    }

    /**
     * Get list of all plans for admin display
     *
     * @access public
     * @return array
     */
    public static function get_list_of_all_plans()
    {
        if (!self::$all_plans) {
            self::$all_plans = array();

            $query = new WP_Query(array(
                'post_type'         => self::$post_type,
                'post_status'       => array('publish', 'pending', 'draft', 'future', 'private', 'trash'),
                'posts_per_page'    => -1,
                'fields'            => 'ids',
            ));

            foreach ($query->posts as $id) {
                self::$all_plans[$id] = get_the_title($id);
            }
        }

        return self::$all_plans;
    }

    /**
     * Grant access
     *
     * @access public
     * @param int $plan_id
     * @param int $user_id
     * @param int $expiration_time
     * @param bool $self
     * @return void
     */
    public static function add_member($plan_id, $user_id, $expiration_time = null, $self = false)
    {
        // Load membership plan
        if ($plan = self::cache($plan_id)) {

            // Check if plan and user looks ok
            if (!empty($user_id) && !empty($plan->key) && $user = get_user_by('id', $user_id)) {

                // Add capability to user
                $user->add_cap($plan->key);

                // Add meta fields
                update_user_meta($user_id, '_rpwcm_' . $plan->key . '_since', time());

                // Set expiration time
                if ($expiration_time) {
                    update_user_meta($user_id, '_rpwcm_' . $plan->key . '_expires', $expiration_time);
                }

                // Any child plans?
                if (isset($plan->linked_plans) && !empty($plan->linked_plans)) {
                    foreach ($plan->linked_plans as $linked_plan_id => $linked_plan) {

                        // Get time when access should be granted to this child plan
                        $grant_access_time = self::get_time_in_future($linked_plan['time_value'], $linked_plan['time_unit']);

                        // If it's less than 20 minutes in the future, do it now
                        if ($grant_access_time < (time() + 1200)) {

                            // Prevent infinite loop
                            if (!in_array($plan_id . '_' . $linked_plan_id, self::$linked_plans_loop_protection)) {
                                self::$linked_plans_loop_protection[] = $plan_id . '_' . $linked_plan_id;

                                // Grant access to child membership plan
                                self::add_member($linked_plan_id, $user_id, $expiration_time, true);
                            }
                        }

                        // Make sure its not later than expiration time (if any) plus 20 minutes
                        else if (empty($expiration_time) || $expiration_time > ($grant_access_time + 1200)) {
                            WooCommerce_Membership_Scheduler::schedule_grant_access($linked_plan_id, $user_id, $expiration_time, $grant_access_time);
                        }
                    }
                }

                // Send email only on parent plan activation
                if (!$self) {
                    WooCommerce_Membership_Mailer::send('membership_granted', $plan->name, $user_id);
                }
            }
        }
    }

    /**
     * Remove access
     *
     * @access public
     * @param int $plan_id
     * @param int $user_id
     * @param bool $self
     * @return void
     */
    public static function remove_member($plan_id, $user_id, $self = false)
    {
        // Remove access
        if ($plan = self::cache($plan_id)) {
            if (!empty($user_id) && !empty($plan->key) && $user = get_user_by('id', $user_id)) {

                // Remove capability from user
                $user->remove_cap($plan->key);

                // Remove meta fields
                delete_user_meta($user_id, '_rpwcm_' . $plan->key . '_since');
                delete_user_meta($user_id, '_rpwcm_' . $plan->key . '_expires');

                // Any child plans?
                if (isset($plan->linked_plans) && !empty($plan->linked_plans)) {
                    foreach ($plan->linked_plans as $linked_plan_id => $linked_plan) {

                        // Prevent infinite loop
                        if (!in_array($plan_id . '_' . $linked_plan_id, self::$linked_plans_loop_protection)) {
                            self::$linked_plans_loop_protection[] = $plan_id . '_' . $linked_plan_id;

                            // Remove access to child plan
                            self::remove_member($linked_plan_id, $user_id, true);
                        }
                    }
                }

                // Send email only on parent plan activation
                if (!$self) {
                    WooCommerce_Membership_Mailer::send('membership_expired', $plan->name, $user_id);
                }
            }
        }

        // Delete any scheduled events
        $scheduled_expiration = wp_next_scheduled('woocommerce_membership_scheduled_expiration', array((int) $plan_id, (int) $user_id));
        WooCommerce_Membership_Scheduler::unschedule_reminders($plan_id, $user_id, $scheduled_expiration);
        WooCommerce_Membership_Scheduler::unschedule_expiration($plan_id, $user_id);
        // TBD: unscheduling not-yet-granted membership plans may also be a good idea
        //      (but may not work perfectly because of variable expiration time in args)
    }

    /**
     * Remove member manually
     *
     * @access public
     * @return void
     */
    public function remove_member_manually()
    {
        // Check if current user can edit membership plan settings
        if (!WooCommerce_Membership::is_authorized('plan_edit')) {
            return;
        }

        // Check if plan and membership keys were received
        if (!empty($_GET['plan']) && !empty($_GET['member'])) {
            self::remove_member(absint($_GET['plan']), absint($_GET['member']));
            wp_redirect(admin_url('post.php?post=' . absint($_GET['plan']) . '&action=edit&message=4'));
            exit;
        }
    }

    /**
     * Remove linked plan
     *
     * @access public
     * @return void
     */
    public function remove_linked_plan()
    {
        // Check if current user can edit membership plan settings
        if (!WooCommerce_Membership::is_authorized('plan_edit')) {
            return;
        }

        // Check if plan and membership keys were received
        if (!empty($_GET['parent_plan']) && !empty($_GET['linked_plan'])) {
            self::unlink_plan(absint($_GET['parent_plan']), absint($_GET['linked_plan']));
            wp_redirect(admin_url('post.php?post=' . absint($_GET['parent_plan']) . '&action=edit&message=4'));
            exit;
        }
    }

    /**
     * Remove linked plan
     *
     * @access public
     * @param int $post_id
     * @return void
     */
    public static function remove_plan_from_products($post_id)
    {
        $plan = self::cache($post_id);

        if (isset($plan->key)) {

            // Get related products
            $products = $plan->get_products(false);

            foreach ($products as $product_id => $product) {

                // Remove plan from product
                WooCommerce_Membership_Product::remove_plan($product_id, $post_id);

                // Recheck if simple product is still a membership
                WooCommerce_Membership_Product::recheck_membership_status($product_id);

                // Recheck if variable product is still a membership
                if ($product_id != $product['main_id']) {
                    WooCommerce_Membership_Product::recheck_membership_status($product['main_id']);
                }
            }
        }
    }

    /**
     * Remove plan from members
     *
     * @access public
     * @param int $post_id
     * @return void
     */
    public static function remove_plan_from_members($post_id)
    {
        global $wpdb;

        $plan = self::cache($post_id);

        if (isset($plan->key)) {

            // Fetch users
            $query = new WP_User_Query(array(
                'meta_key'      => $wpdb->prefix . 'capabilities',
                'meta_value'    => serialize(strval($plan->key)),
                'meta_compare'  => 'LIKE',
            ));

            foreach ($query->results as $user) {
                $user->remove_cap($plan->key);
                delete_user_meta($user->ID, '_rpwcm_' . $plan->key . '_since');
            }
        }
    }

    /**
     * Remove plan from posts (remove access restriction)
     * This is invoked when membership plan is deleted permanently
     *
     * @access public
     * @param int $post_id
     * @return void
     */
    public static function remove_plan_from_posts($post_id)
    {
        if ($key = get_post_meta($post_id, 'key', true)) {

            // Get all (and any) posts that have restriction by this key
            $query = new WP_Query(array(
                'post_status'       => array('publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash'),
                'posts_per_page'    => -1,
                'fields'            => 'ids',
                'meta_query'        => array(
                    array(
                        'key'       => '_rpwcm_only_caps',
                        'value'     => $key,
                        'compare'   => '=',
                    ),
                ),
            ));

            // Iterate over all found post IDs and delete restriction
            foreach ($query->posts as $post_id) {
                delete_post_meta($post_id, '_rpwcm_only_caps', $key);
            }
        }
    }

    /**
     * Get by key
     *
     * @access public
     * @param string $key
     * @return object|bool
     */
    public static function get_by_key($key)
    {
        $query = new WP_Query(array(
            'post_type'         => 'membership_plan',
            'posts_per_page'    => -1,
            'fields'            => 'ids',
            'meta_query'        => array(
                array(
                    'key'       => 'key',
                    'value'     => $key,
                    'compare'   => '=',
                ),
            ),
        ));

        if (!empty($query->posts)) {
            return self::cache(array_shift($query->posts));
        }

        return false;
    }

    /**
     * Plan deleted
     *
     * @access public
     * @param int $post_id
     * @return void
     */
    public function post_deleted($post_id)
    {
        global $post_type;

        if ($post_type == 'membership_plan') {
            self::remove_plan_from_products($post_id);
            self::remove_plan_from_members($post_id);
            self::remove_plan_from_posts($post_id);
        }
    }

    /**
     * Plan trashed
     *
     * @access public
     * @param int $post_id
     * @return void
     */
    public function post_trashed($post_id)
    {
        global $post_type;

        if ($post_type == 'membership_plan') {
            $plan = self::cache($post_id);
            $plan->update_field('status', 'disabled');
        }
    }

    /**
     * Leave only keys of enabled membership plans
     *
     * @access public
     * @param array $keys
     * @return array
     */
    public static function enabled_keys_only($keys)
    {
        if (empty($keys)) {
            return $keys;
        }

        $enabled_keys = array();

        $GLOBALS['rpwcm_getting_enabled_keys'] = true;

        // Simple product IDs
        $query = new WP_Query(array(
            'post_type'         => 'membership_plan',
            'post_status'       => 'publish',
            'posts_per_page'    => -1,
            'fields'            => 'ids',
            'meta_query'        => array(
                array(
                    'key'       => 'key',
                    'value'     => $keys,
                    'compare'   => 'IN',
                ),
            ),
            'tax_query' => array(
                array(
                    'taxonomy'  => 'plan_status',
                    'field'     => 'slug',
                    'terms'     => 'enabled',
                ),
            ),
        ));

        $GLOBALS['rpwcm_getting_enabled_keys'] = false;

        foreach ($query->posts as $id) {
            $enabled_keys[] = get_post_meta($id, 'key', true);
        }

        return $enabled_keys;
    }

    /**
     * Calculate time in future from current timestamp
     *
     * @access public
     * @return mixed
     */
    public static function get_time_in_future($value, $unit)
    {
        // Get period length in seconds
        $period_length_in_seconds = self::get_period_length_in('second', $unit, $value);

        // Something wrong with settings? Don't create a mess then..
        if ($period_length_in_seconds === false) {
            return false;
        }

        // Calculate event time
        $time = time() + $period_length_in_seconds;

        // Make sure it's at least 15 minutes in the future
        $time = ($time >= (time() + 900)) ? $time : (time() + 900);

        return $time;
    }

    /**
     * Convert time units
     * $units_to and $units_from should be passed in a singular form (e.g. day)
     *
     * @access public
     * @param string $units_to
     * @param string $units_from
     * @param int $value
     * @return mixed
     */
    public static function get_period_length_in($units_to, $units_from, $value)
    {
        // Get time units
        $time_units = WooCommerce_Membership::get_time_units();

        // Check if given units are supported
        if (!isset($time_units[$units_from])) {
            return false;
        }

        // Extend with more units to convert to
        $time_units = array_merge(array(
            'second'    => array(
                'seconds'   => 1,
            ),
            'minute'    => array(
                'seconds'   => 60,
            ),
            'hour'      => array(
                'seconds'   => 3600,
            ),
        ), WooCommerce_Membership::get_time_units());

        // Check if units to convert to are supported
        if (!isset($time_units[$units_to])) {
            return false;
        }

        // Check if $value is a number
        if (!is_numeric($value) || $value < 0) {
            return false;
        }

        // Calculate value in seconds
        $value_in_seconds = $value * $time_units[$units_from]['seconds'];

        // Calculate value in required units
        return round($value_in_seconds / $time_units[$units_to]['seconds']);
    }

    /**
     * Get reminder timestamps
     *
     * @access public
     * @param int $base_timestamp
     * @return array
     */
    public function get_reminders($base_timestamp)
    {
        $reminders = array();

        if (!WooCommerce_Membership::opt('reminders_enabled') || !WooCommerce_Membership::opt('reminders_days')) {
            return $reminders;
        }

        $days = explode(',', WooCommerce_Membership::opt('reminders_days'));

        // Iterate over days array and calculate timestamps for events
        foreach ($days as $day) {

            // Calculate offset in seconds
            $offset_in_seconds = $day * 86400;

            // Calculate current reminder event timestamp
            $timestamp = $base_timestamp - $offset_in_seconds;

            // Only proceed if this moment in time has not yet passed
            if (time() < $timestamp) {
                $reminders[] = $timestamp;
            }
        }

        return $reminders;
    }

    /**
     * Extend list of capabilities with membership plan keys
     *
     * @access public
     * @param array $capabilities
     * @return array
     */
    public function extend_capability_list($capabilities)
    {
        $new_capabilities = array_merge($capabilities, self::get_list_of_all_capabilities());

        foreach (self::get_list_of_all_plan_keys() as $plan_key => $plan_title) {
            $new_capabilities[$plan_key] = $plan_key;
        }

        return $new_capabilities;
    }

    /**
     * Get list of plan ids that current plan is child of
     *
     * @access public
     * @param int $plan_id
     * @return array
     */
    public static function get_parent_plans($plan_id)
    {
        $query = new WP_Query(array(
            'post_type'         => 'membership_plan',
            'post_status'       => array('publish', 'pending', 'draft', 'future', 'private', 'trashed'),
            'posts_per_page'    => -1,
            'fields'            => 'ids',
            'meta_query'        => array(
                array(
                    'key'       => 'linked_plans',
                    'value'     => $plan_id,
                    'compare'   => '=',
                ),
            ),
        ));

        return $query->posts;
    }

}

new WooCommerce_Membership_Plan();

}