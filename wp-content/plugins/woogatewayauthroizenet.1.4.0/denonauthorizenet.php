<?php
/*
Plugin Name: Authorize.net Gateway
Plugin URI: http://codecanyon.net/user/DenonStudio/portfolio
Description: Provides a Credit Card Payment Gateway through Authorize.net for woo-commerece.
Version: 1.4.0
Author: DenonStudio
Author URI: http://http://codecanyon.net/user/DenonStudio
Requires at least: 3.8
Tested up to: 4.1.1
*/

/*
 * Title   : Authorize.net Payment extension for Woo-Commerece
 * Author  : DenonStudio
 * Url     : http://codecanyon.net/user/DenonStudio/portfolio
 * License : http://codecanyon.net/wiki/support/legal-terms/licensing-terms/
 */

function init_authorizenet_gateway() 
{
    if (class_exists('WC_Payment_Gateway'))
    {
        include_once('class.authorizenetextension.php');
    }
}

add_action('plugins_loaded', 'init_authorizenet_gateway', 0);