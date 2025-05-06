<?php
/**
 * Plugin Name: WC Enviame Shipping
 * Description: Método de envío de Enviame para WooCommerce
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: wc-enviame-shipping
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes para rutas y URLs del plugin
define('WC_ENVIAME_SHIPPING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_ENVIAME_SHIPPING_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Verifica si WooCommerce está activo
 */
function wc_enviame_shipping_check_woocommerce() {
    if (!class_exists('WC_Shipping_Method')) {
        add_action('admin_notices', 'wc_enviame_shipping_missing_wc_notice');
        return;
    }
}
add_action('plugins_loaded', 'wc_enviame_shipping_check_woocommerce');

/**
 * Mensaje de error si WooCommerce no está instalado
 */
function wc_enviame_shipping_missing_wc_notice() {
    ?>
    <div class="error">
        <p><?php _e('WC Enviame Shipping requiere que WooCommerce esté instalado y activo.', 'wc-enviame-shipping'); ?></p>
    </div>
    <?php
}

/**
 * Inicializa el textdomain
 */
function wc_enviame_shipping_load_textdomain() {
    load_plugin_textdomain('wc-enviame-shipping', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('init', 'wc_enviame_shipping_load_textdomain');

/**
 * Agrega el método de envío
 */
function wc_enviame_shipping_add_method($methods) {
    if (!isset($methods['enviame'])) {
        $methods['enviame'] = 'WC_Enviame_Shipping';
    }
    return $methods;
}
add_filter('woocommerce_shipping_methods', 'wc_enviame_shipping_add_method');

/**
 * Registra la clase del método de envío
 */
function wc_enviame_shipping_init() {
    if (!class_exists('WC_Enviame_Shipping')) {
        require_once 'includes/class-wc-enviame-shipping.php';
    }
}
add_action('woocommerce_shipping_init', 'wc_enviame_shipping_init');

/**
 * Función para logs de debug
 */
function enviame_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (is_array($message) || is_object($message)) {
            error_log(print_r($message, true));
        } else {
            error_log($message);
        }
    }
}