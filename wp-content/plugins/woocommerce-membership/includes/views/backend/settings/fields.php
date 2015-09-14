<?php

/**
 * View for Settings page
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

?>

<div class="wrap woocommerce rpwcm_settings">
    <div class="rpwcm_settings_container">
        <form method="post" action="options.php" enctype="multipart/form-data">
            <input type="hidden" name="current_tab" value="<?php echo $current_tab; ?>" />
                <?php settings_fields('rpwcm_opt_group_' . preg_replace('/-/', '_', $current_tab)); ?>
                <?php do_settings_sections('rpwcm-admin-' . $current_tab); ?>
                <div></div>
                <?php submit_button(); ?>
        </form>
    </div>
</div>