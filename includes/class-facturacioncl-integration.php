<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Clase de Integración de Facturacion.cl para WooCommerce.
 * Añade la configuración a WooCommerce > Ajustes > Integración.
 */
class WC_FacturacionCL_Integration extends WC_Integration
{

    /**
     * Constructor
     */
    public function __construct()
    {
        global $woocommerce;

        $this->id                 = 'facturacioncl'; // ID único para la integración
        $this->method_title       = __('Facturacion.cl Integration', 'wc-facturacioncl'); // Título en la lista de integraciones
        $this->method_description = __('Connects WooCommerce to Facturacion.cl API to generate electronic invoices (DTE). Requires credentials from Facturacion.cl.', 'wc-facturacioncl'); // Descripción

        // Cargar los ajustes (formulario y procesamiento)
        $this->init_form_fields();
        $this->init_settings();

        // Obtener los valores de los ajustes
        $this->enabled         = $this->get_option('enabled');
        $this->api_user        = $this->get_option('api_user');
        $this->api_pass        = $this->get_option('api_pass');
        $this->trigger_status  = $this->get_option('trigger_status', 'completed'); // Default 'completed'
        $this->emisor_rut      = $this->get_option('emisor_rut');
        $this->emisor_razon_social = $this->get_option('emisor_razon_social');
        $this->emisor_giro     = $this->get_option('emisor_giro');


        // Acción para guardar los ajustes
        add_action('woocommerce_update_options_integration_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Define los campos del formulario de configuración.
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'wc-facturacioncl'),
                'type'    => 'checkbox',
                'label'   => __('Enable Facturacion.cl Integration', 'wc-facturacioncl'),
                'default' => 'no',
            ),
            'api_user' => array(
                'title'       => __('API User', 'wc-facturacioncl'),
                'type'        => 'text',
                'description' => __('Enter your Facturacion.cl API User.', 'wc-facturacioncl'),
                'desc_tip'    => true,
                'default'     => '',
            ),
            'api_pass' => array(
                'title'       => __('API Password', 'wc-facturacioncl'),
                'type'        => 'password', // Tipo password para ocultar
                'description' => __('Enter your Facturacion.cl API Password.', 'wc-facturacioncl'),
                'desc_tip'    => true,
                'default'     => '',
            ),
            'trigger_status' => array(
                'title'       => __('Trigger Order Status', 'wc-facturacioncl'),
                'type'        => 'select',
                'description' => __('Select the order status that will trigger the DTE generation.', 'wc-facturacioncl'),
                'desc_tip'    => true,
                'default'     => 'completed',
                'options'     => $this->get_order_statuses(), // Función helper para obtener estados
            ),
            'emisor_title' => array(
                'title'       => __('Emisor Details (Store)', 'wc-facturacioncl'),
                'type'        => 'title',
                'description' => __('Information about the invoice issuer (your store). This data will be used in the DTE.', 'wc-facturacioncl'),
            ),
            'emisor_rut' => array(
                'title'       => __('Emisor RUT', 'wc-facturacioncl'),
                'type'        => 'text',
                'description' => __('Enter the RUT of your company (e.g., 76000000-K).', 'wc-facturacioncl'),
                'desc_tip'    => true,
                'default'     => '',
            ),
            'emisor_razon_social' => array(
                'title'       => __('Emisor Razon Social', 'wc-facturacioncl'),
                'type'        => 'text',
                'description' => __('Enter the legal name of your company.', 'wc-facturacioncl'),
                'desc_tip'    => true,
                'default'     => '',
            ),
            'emisor_giro' => array(
                'title'       => __('Emisor Giro', 'wc-facturacioncl'),
                'type'        => 'text',
                'description' => __('Enter the main business activity (Giro) of your company.', 'wc-facturacioncl'),
                'desc_tip'    => true,
                'default'     => '',
            ),
            // Podrías añadir más campos del emisor si son necesarios (Acteco, Dirección, Comuna, etc.)
            // o intentar obtenerlos de los ajustes generales de WooCommerce si es posible.
        );
    }

    /**
     * Helper para obtener los estados de orden de WooCommerce formateados para un select.
     *
     * @return array
     */
    private function get_order_statuses()
    {
        $wc_statuses = wc_get_order_statuses(); // Obtiene estados con prefijo 'wc-'
        $statuses = [];
        foreach ($wc_statuses as $key => $label) {
            $statuses[str_replace('wc-', '', $key)] = $label; // Quita el prefijo para guardar y comparar
        }
        return $statuses;
    }
}
