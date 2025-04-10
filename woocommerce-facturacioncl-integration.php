<?php

/**
 * Plugin Name:       WooCommerce Facturacion.cl Integration
 * Plugin URI:        https://ejemplo.com/plugin-info (Cambia esto)
 * Description:       Integra WooCommerce con la API de Facturacion.cl para emitir DTEs.
 * Version:           0.1.0
 * Author:            Tu Nombre / Empresa (Cambia esto)
 * Author URI:        https://ejemplo.com (Cambia esto)
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-facturacioncl
 * Domain Path:       /languages
 * Requires PHP:      7.2
 * WC requires at least: 3.0
 * WC tested up to:     (La última versión de WC que probaste)
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Asegurarse que WooCommerce está activo
if (! class_exists('WooCommerce')) {
    add_action('admin_notices', 'wc_facturacioncl_woocommerce_missing_notice');
    return;
}

function wc_facturacioncl_woocommerce_missing_notice()
{
?>
    <div class="error">
        <p><?php esc_html_e('WooCommerce Facturacion.cl Integration requires WooCommerce to be installed and active.', 'wc-facturacioncl'); ?></p>
    </div>
<?php
}

define('WC_FACTURACIONCL_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_FACTURACIONCL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_FACTURACIONCL_VERSION', '0.1.0');

// Incluir clases
require_once WC_FACTURACIONCL_PLUGIN_PATH . 'includes/class-facturacioncl-api-client.php';
require_once WC_FACTURACIONCL_PLUGIN_PATH . 'includes/class-facturacioncl-integration.php';

/**
 * Carga la integración después de que WooCommerce se inicialice.
 */
function wc_facturacioncl_load_integration()
{
    // Cargar textdomain para traducciones
    load_plugin_textdomain('wc-facturacioncl', false, dirname(plugin_basename(__FILE__)) . '/languages/');

    // Añadir la clase de integración a las integraciones de WooCommerce
    add_filter('woocommerce_integrations', 'wc_facturacioncl_add_integration');
}
add_action('plugins_loaded', 'wc_facturacioncl_load_integration');

/**
 * Añade la clase de integración a la lista de integraciones de WooCommerce.
 *
 * @param array $integrations Integraciones existentes.
 * @return array Integraciones actualizadas.
 */
function wc_facturacioncl_add_integration($integrations)
{
    $integrations[] = 'WC_FacturacionCL_Integration';
    return $integrations;
}

/**
 * Función principal que se ejecuta cuando una orden cambia de estado.
 *
 * @param int $order_id ID de la orden.
 * @param string $status_from Estado anterior.
 * @param string $status_to Nuevo estado.
 * @param WC_Order $order Objeto de la orden.
 */
function wc_facturacioncl_trigger_invoice_generation($order_id, $status_from, $status_to, $order)
{
    // Obtener la instancia de la configuración de la integración
    $integration_options = get_option('woocommerce_facturacioncl_settings', []);
    $enabled = isset($integration_options['enabled']) && $integration_options['enabled'] === 'yes';
    $trigger_status = isset($integration_options['trigger_status']) ? $integration_options['trigger_status'] : 'completed'; // 'wc-' prefijo se añade a veces
    $api_user = $integration_options['api_user'] ?? '';
    $api_pass = $integration_options['api_pass'] ?? '';

    // Limpiar el prefijo 'wc-' si existe
    $trigger_status_clean = str_replace('wc-', '', $trigger_status);

    // Verificar si el plugin está habilitado, si las credenciales existen y si el estado coincide
    if (! $enabled || empty($api_user) || empty($api_pass) || $status_to !== $trigger_status_clean) {
        return;
    }

    // Evitar ejecuciones múltiples si ya se generó una factura
    if ($order->get_meta('_facturacioncl_dte_id') || $order->get_meta('_facturacioncl_error')) {
        $order->add_order_note(__('Facturacion.cl: Attempt to generate invoice skipped, already processed or failed.', 'wc-facturacioncl'));
        return;
    }

    $order->add_order_note(sprintf(__('Facturacion.cl: Order status changed to %s. Attempting DTE generation...', 'wc-facturacioncl'), $status_to));

    try {
        $api_client = new WC_FacturacionCL_API_Client($api_user, $api_pass);

        // 1. Autenticar
        $token = $api_client->authenticate();
        if (! $token) {
            throw new Exception(__('Authentication failed.', 'wc-facturacioncl'));
        }
        $order->add_order_note(__('Facturacion.cl: Authentication successful.', 'wc-facturacioncl'));

        // 2. Generar XML del DTE (¡¡ESTA ES LA PARTE COMPLEJA Y ES SOLO UN PLACEHOLDER!!)
        $dte_xml = wc_facturacioncl_generate_dte_xml($order);
        if (! $dte_xml) {
            throw new Exception(__('Failed to generate DTE XML for order.', 'wc-facturacioncl'));
        }
        // $order->add_order_note( 'Facturacion.cl XML Generado: ' . esc_html( $dte_xml ) ); // Descomentar para debug, puede ser muy largo

        // 3. Enviar DTE
        $result = $api_client->send_dte($token, $dte_xml);

        if (isset($result->Estado) && $result->Estado === 'OK' && isset($result->Id)) {
            // ¡Éxito! Guardar ID interno
            $internal_id = $result->Id;
            $message = isset($result->Mensaje) ? $result->Mensaje : __('DTE sent successfully.', 'wc-facturacioncl');
            $track_id = isset($result->TrackId) ? $result->TrackId : null; // Puede que no venga aquí

            $order->update_meta_data('_facturacioncl_dte_id', $internal_id);
            if ($track_id) {
                $order->update_meta_data('_facturacioncl_track_id', $track_id);
            }
            $order->add_order_note(sprintf(
                __('Facturacion.cl: DTE sent successfully. Internal ID: %s. %s %s', 'wc-facturacioncl'),
                $internal_id,
                $message,
                $track_id ? sprintf(__('TrackID SII: %s', 'wc-facturacioncl'), $track_id) : ''
            ));
            $order->save();
        } else {
            // Hubo un error en la API
            $error_message = isset($result->Mensaje) ? $result->Mensaje : __('Unknown API error after sending DTE.', 'wc-facturacioncl');
            if (isset($result->Error)) { // A veces el error viene en un campo 'Error'
                $error_message .= ' Detalles: ' . $result->Error;
            }
            throw new Exception($error_message);
        }
    } catch (SoapFault $e) {
        $error_msg = sprintf(__('Facturacion.cl SOAP Error: %s', 'wc-facturacioncl'), $e->getMessage());
        $order->add_order_note($error_msg);
        $order->update_meta_data('_facturacioncl_error', $error_msg);
        $order->save();
        // Log error (opcional)
        wc_get_logger()->error($error_msg, array('source' => 'wc-facturacioncl'));
    } catch (Exception $e) {
        $error_msg = sprintf(__('Facturacion.cl Error: %s', 'wc-facturacioncl'), $e->getMessage());
        $order->add_order_note($error_msg);
        $order->update_meta_data('_facturacioncl_error', $error_msg);
        $order->save();
        // Log error (opcional)
        wc_get_logger()->error($error_msg, array('source' => 'wc-facturacioncl'));
    }
}
// Enganchar la función al cambio de estado de la orden
// Puedes usar 'woocommerce_order_status_completed' si solo quieres que se ejecute al completar
// O 'woocommerce_order_status_changed' para más flexibilidad con el ajuste de 'trigger_status'
add_action('woocommerce_order_status_changed', 'wc_facturacioncl_trigger_invoice_generation', 10, 4);


/**
 * PLACEHOLDER: Genera el XML del DTE basado en la orden de WooCommerce.
 *
 * ¡¡ESTA ES LA PARTE MÁS COMPLEJA Y REQUIERE DESARROLLO DETALLADO!!
 * Debes consultar la documentación del SII para la estructura exacta del XML
 * y mapear los datos de $order a esa estructura.
 *
 * @param WC_Order $order Objeto de la orden de WooCommerce.
 * @return string|false El XML del DTE como string, o false en caso de error.
 */
function wc_facturacioncl_generate_dte_xml(WC_Order $order)
{
    // --- INICIO DE LA LÓGICA COMPLEJA ---
    // Obtener datos del pedido:
    $order_id = $order->get_id();
    $customer_rut = $order->get_meta('_billing_rut'); // Necesitarás un campo para el RUT (ej. usando plugin extra)
    $billing_first_name = $order->get_billing_first_name();
    $billing_last_name = $order->get_billing_last_name();
    $billing_company = $order->get_billing_company();
    $billing_address_1 = $order->get_billing_address_1();
    $billing_city = $order->get_billing_city(); // Comuna
    // ... obtener todos los datos necesarios del emisor (tienda) y receptor (cliente) ...

    // Determinar tipo de DTE (ej. 33 para Factura Electrónica, 39 para Boleta Electrónica)
    // Esto podría depender del RUT del cliente o de una opción en el checkout
    $tipo_dte = 33; // Asumir Factura por defecto, ¡debe ser dinámico!

    // Obtener folio (esto es MUY complejo, normalmente lo asigna el sistema de facturación o requiere gestión)
    // Para este ejemplo, asumiremos que lo gestiona facturacion.cl y no lo ponemos aquí o usamos un placeholder.
    $folio = $order_id; // ¡¡ESTO NO ES CORRECTO PARA PRODUCCIÓN!!

    // Obtener datos de la tienda (Emisor) desde los ajustes de WooCommerce o del plugin
    $integration_options = get_option('woocommerce_facturacioncl_settings', []);
    $emisor_rut = $integration_options['emisor_rut'] ?? ''; // Necesitas añadir esto a los ajustes
    $emisor_razon_social = $integration_options['emisor_razon_social'] ?? ''; // Necesitas añadir esto
    $emisor_giro = $integration_options['emisor_giro'] ?? ''; // Necesitas añadir esto
    $emisor_direccion = get_option('woocommerce_store_address'); // Dirección de la tienda
    $emisor_comuna = get_option('woocommerce_store_city'); // Ciudad/Comuna de la tienda

    // Validar que tienes RUT de emisor y receptor
    if (empty($emisor_rut) || empty($customer_rut)) {
        $order->add_order_note(__('Facturacion.cl: Missing RUT for Emisor or Receptor. Cannot generate DTE.', 'wc-facturacioncl'));
        return false;
    }

    // --- Construcción del XML usando DOMDocument (Recomendado) ---
    $doc = new DOMDocument('1.0', 'ISO-8859-1'); // O UTF-8 si es requerido por facturacion.cl
    $doc->formatOutput = false; // Sin formato para enviar
    $doc->preserveWhiteSpace = false;

    // Elemento Raíz <DTE>
    $dte = $doc->createElement('DTE');
    $dte->setAttribute('version', '1.0');
    $doc->appendChild($dte);

    // Elemento <Documento>
    $documento = $doc->createElement('Documento');
    $documento->setAttribute('ID', 'F' . $folio . 'T' . $tipo_dte); // ID único del documento
    $dte->appendChild($documento);

    // Elemento <Encabezado>
    $encabezado = $doc->createElement('Encabezado');
    $documento->appendChild($encabezado);

    // --- IdDoc ---
    $idDoc = $doc->createElement('IdDoc');
    $idDoc->appendChild($doc->createElement('TipoDTE', $tipo_dte));
    $idDoc->appendChild($doc->createElement('Folio', $folio));
    $idDoc->appendChild($doc->createElement('FchEmis', date('Y-m-d'))); // Fecha de Emisión
    // ... otros campos de IdDoc si son necesarios (IndServicio, FchVenc, etc.) ...
    $encabezado->appendChild($idDoc);

    // --- Emisor ---
    $emisor = $doc->createElement('Emisor');
    $emisor->appendChild($doc->createElement('RUTEmisor', $emisor_rut));
    $emisor->appendChild($doc->createElement('RznSoc', htmlspecialchars($emisor_razon_social)));
    $emisor->appendChild($doc->createElement('GiroEmis', htmlspecialchars($emisor_giro)));
    // ... Acteco, DirOrigen, CmnaOrigen ...
    $encabezado->appendChild($emisor);

    // --- Receptor ---
    $receptor = $doc->createElement('Receptor');
    $receptor->appendChild($doc->createElement('RUTRecep', $customer_rut));
    $receptor->appendChild($doc->createElement('RznSocRecep', htmlspecialchars($billing_company ?: $billing_first_name . ' ' . $billing_last_name)));
    // ... GiroRecep, DirRecep, CmnaRecep ...
    $encabezado->appendChild($receptor);

    // --- Totales ---
    $totales = $doc->createElement('Totales');
    // OJO: Los cálculos de Neto, IVA, Impuestos adicionales y Total deben ser precisos
    // WooCommerce guarda totales con impuestos incluidos o excluidos según configuración.
    $neto = $order->get_subtotal(); // Esto puede no ser el Neto real si hay descuentos a nivel de orden
    $iva = $order->get_total_tax();
    $total = $order->get_total();

    // ¡¡IMPORTANTE!! Recalcular Neto si WC lo guarda con IVA
    // Esto depende de tu configuración de impuestos en WC. Asumiendo IVA 19%
    // if ( wc_prices_include_tax() ) {
    //     $neto = $total / 1.19; // Simplificación, puede ser más complejo
    //     $iva = $total - $neto;
    // } else {
    // Si los precios no incluyen IVA, el subtotal podría ser el neto, pero cuidado con descuentos
    //      $neto = $order->get_subtotal() - $order->get_total_discount(); // Aprox
    //      $iva = $order->get_total_tax();
    //      $total = $order->get_total();
    // }
    // NECESITAS lógica robusta aquí para calcular Neto, IVA, Exento si aplica, etc.

    $totales->appendChild($doc->createElement('MntNeto', round($neto)));
    $totales->appendChild($doc->createElement('TasaIVA', '19')); // Asume IVA 19%
    $totales->appendChild($doc->createElement('IVA', round($iva)));
    $totales->appendChild($doc->createElement('MntTotal', round($total)));
    $encabezado->appendChild($totales);

    // --- Detalle (Items del Pedido) ---
    foreach ($order->get_items() as $item_id => $item) {
        $detalle = $doc->createElement('Detalle');
        $product = $item->get_product();
        $qty = $item->get_quantity();
        $product_name = $item->get_name();
        $item_subtotal = $item->get_subtotal(); // Precio unitario * cantidad (sin impuestos de item?)
        $item_total = $item->get_total();       // Precio con descuentos de item (sin impuestos de orden?)
        $item_tax = $item->get_total_tax();
        $unit_price = $qty ? ($item_subtotal / $qty) : 0; // Precio unitario Neto o Bruto? Depende de config WC

        // Calcular Precio Unitario Neto y Monto Neto del Item
        // De nuevo, depende de cómo WC almacena y calcula precios e impuestos
        $monto_item_neto = $item_total; // Asume que get_total es neto para el item, ¡REVISAR!
        if (wc_prices_include_tax()) {
            $monto_item_neto = $item_total / 1.19; // Simplificación
        }
        $precio_unitario_neto = $qty ? ($monto_item_neto / $qty) : 0;


        $detalle->appendChild($doc->createElement('NroLinDet', $item_id)); // O un contador 1, 2, 3...
        $detalle->appendChild($doc->createElement('NmbItem', htmlspecialchars($product_name)));
        $detalle->appendChild($doc->createElement('QtyItem', $qty));
        $detalle->appendChild($doc->createElement('PrcItem', round($precio_unitario_neto))); // Precio Unitario Neto
        $detalle->appendChild($doc->createElement('MontoItem', round($monto_item_neto))); // Cantidad * Precio Unitario Neto
        // ... descuentos, recargos, impuestos adicionales por item si aplican ...
        $documento->appendChild($detalle);
    }

    // ... Añadir otros elementos si son necesarios (Descuentos/Recargos globales, Referencias, etc.) ...

    // --- FIN DE LA LÓGICA COMPLEJA ---

    // Retornar el XML como string
    // Nota: saveXML() puede añadir el prólogo XML, verifica si facturacion.cl lo quiere o no.
    // Si no lo quiere, usa: $xml_string = $doc->saveXML($doc->documentElement);
    $xml_string = $doc->saveXML();

    // Log para debug (opcional, quitar en producción)
    // wc_get_logger()->debug( 'Generated DTE XML: ' . $xml_string, array( 'source' => 'wc-facturacioncl' ) );

    return $xml_string;
}
