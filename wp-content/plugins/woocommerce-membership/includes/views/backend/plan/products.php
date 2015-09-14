<?php

/**
 * View for Membership Plan Edit page Related Products block
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

?>

<?php if (!empty($products)): ?>

    <table class="rpwcm_membership_plan_item_list">
        <thead>
            <tr>
                <th class="rpwcm_fourth_width rpwcm_membership_plan_item_list_product"><?php _e('Product Name', 'woocommerce-membership'); ?></th>
                <th class="rpwcm_fourth_width rpwcm_membership_plan_item_list_type"><?php _e('Type', 'woocommerce-membership'); ?></th>
                <th class="rpwcm_fourth_width rpwcm_membership_plan_item_list_since"><?php _e('Current Price', 'woocommerce-membership'); ?></th>
                <th class="rpwcm_fourth_width rpwcm_membership_plan_item_list_expires"><?php _e('Expiration Term', 'woocommerce-membership'); ?></th>
            </tr>
        </thead>

        <tbody>
            <?php foreach($products as $product_id => $product): ?>
                <tr>
                    <td class="rpwcm_fourth_width rpwcm_membership_plan_item_list_product">
                        <?php WooCommerce_Membership::print_link_to_post($product['main_id'], $product['title']); ?>
                    </td>
                    <td class="rpwcm_fourth_width rpwcm_membership_plan_item_list_type">
                        <?php echo $product['type']; ?>
                    </td>
                    <td class="rpwcm_fourth_width rpwcm_membership_plan_item_list_since">
                        <?php $product = new WC_Product($product_id); ?>
                        <?php if ($product): ?>
                            <?php echo WooCommerce_Membership::wc_version_gte('2.1') ? wc_price($product->get_price()) : woocommerce_price($product->get_price()); ?>
                        <?php endif; ?>
                    </td>
                    <td class="rpwcm_fourth_width rpwcm_membership_plan_item_list_expires">
                        <?php
                            $expiration_value = get_post_meta($product_id, '_rpwcm_expiration_value', true);
                            $expiration_unit  = get_post_meta($product_id, '_rpwcm_expiration_unit', true);

                            if (!empty($expiration_value) && !empty($expiration_unit)) {
                                $time_units = WooCommerce_Membership::get_time_units();

                                echo $expiration_value . ' ';

                                if (isset($time_units[$expiration_unit])) {
                                    echo call_user_func($time_units[$expiration_unit]['translation_callback'], $expiration_unit, $expiration_value);
                                }
                                else {
                                    echo $expiration_unit;
                                }
                            }
                            else {
                                echo '<span class="rpwcm_nothing_to_display">' . __('None', 'woocommerce-membership') . '</span>';
                            }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php else: ?>

    <p>
        <?php _e('No related products found.', 'woocommerce-membership'); ?>
    </p>

<?php endif; ?>