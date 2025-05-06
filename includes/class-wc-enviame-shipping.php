<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Enviame_Shipping extends WC_Shipping_Method {
    
    /**
    * @var string Ciudad de origen por defecto
    */
    public $default_origin;
    /**
    * @var string Carrier por defecto
    */
    public $carrier;
    /**
    * @var string Servicio por defecto
    */
    public $service;
    
    /**
    * @var string API Key de Enviame
    */
    protected $api_key;
    
    /**
    * @var string Título del método de envío
    */
    public $title;
    
    /**
    * @var string Estado del método (enabled/disabled)
    */
    public $enabled;
    
    /**
    * Constructor
    */
    public function __construct($instance_id = 0) {
        $this->id = 'enviame';
        $this->instance_id = absint($instance_id);
        $this->title = __('Enviame Shipping', 'wc-enviame-shipping');
        $this->method_title = __('Enviame Shipping', 'wc-enviame-shipping');
        $this->method_description = __('Calcular costos de envío usando Enviame API', 'wc-enviame-shipping');
        
        $this->supports = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );
        
        // Inicializamos campos y configuraciones
        $this->init_form_fields();
        $this->init_settings();
        
        // Cargamos las opciones
        $this->title = $this->get_option('title', __('Enviame Shipping', 'wc-enviame-shipping'));
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->api_key = $this->get_option('api_key');
        $this->default_origin = $this->get_option('default_origin', __('Santiago', 'wc-enviame-shipping')); 
        
        // Cargar carrier y service desde las opciones con valores por defecto consistentes
        // BLX es un carrier más común - verificar con la documentación de Enviame
        $this->carrier = $this->get_option('carrier', 'BLX');
        // standard es un servicio más ampliamente soportado que nextday
        // $this->service = $this->get_option('service', 'standard');
        
        // Registramos hook para guardar configuraciones
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        
        // Log para depuración
        error_log('WC_Enviame_Shipping inicializado con ID de instancia: ' . $this->instance_id);
    }
    
    /**
    * Campos del formulario de configuración
    */
    public function init_form_fields() {
        $this->instance_form_fields = array(
            'enabled' => array(
                'title' => __('Habilitar/Deshabilitar', 'wc-enviame-shipping'),
                'type' => 'checkbox',
                'label' => __('Habilitar este método de envío', 'wc-enviame-shipping'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Título', 'wc-enviame-shipping'),
                'type' => 'text',
                'description' => __('Título que verá el cliente', 'wc-enviame-shipping'),
                'default' => __('Enviame Shipping', 'wc-enviame-shipping'),
                'desc_tip' => true
            ),
            'api_key' => array(
                'title' => __('API Key', 'wc-enviame-shipping'),
                'type' => 'password',
                'description' => __('Tu API Key de Enviame.', 'wc-enviame-shipping'), // Descripción actualizada
                'default' => ''
            ),
            // Nuevo campo para la ciudad de origen
            'default_origin' => array(
                'title' => __('Ciudad de Origen', 'wc-enviame-shipping'),
                'type' => 'text',
                'description' => __('La ciudad desde donde se envían los paquetes. Debe coincidir con un valor aceptado por la API de Enviame.', 'wc-enviame-shipping'),
                'default' => __('Santiago', 'wc-enviame-shipping'),
                'desc_tip' => true
            ),
            // Nuevo campo para Carrier
            'carrier' => array(
                'title' => __('Carriers', 'wc-enviame-shipping'),
                'type' => 'multiselect',
                'description' => __('Selecciona uno o más transportistas para ofrecer al cliente. Consulta la documentación de Enviame para los códigos correctos.', 'wc-enviame-shipping'),
                'default' => array('BLX'),
                'options' => array(
                    'BLX' => __('Blue Express', 'wc-enviame-shipping'),
                    'CHX' => __('Chilexpress', 'wc-enviame-shipping'),
                    'SKN' => __('Starken', 'wc-enviame-shipping'),
                    'CCH' => __('Correos Chile', 'wc-enviame-shipping'),
                    '99M' => __('99 Minutos', 'wc-enviame-shipping'),
                    'POST' => __('Correos de Chile', 'wc-enviame-shipping'),
                    'FDX' => __('Fedex', 'wc-enviame-shipping'),
                    'CKX' => __('Ckicks', 'wc-enviame-shipping'),
                    'CHD' => __('Falabella', 'wc-enviame-shipping'),
                ),
                'class' => 'wc-enhanced-select',
                'desc_tip' => true
            ),
            // Nuevo campo para Service
            'service' => array(
                'title' => __('Servicio por Defecto', 'wc-enviame-shipping'),
                'type' => 'text',
                'description' => __('El código del servicio a usar por defecto para el carrier seleccionado (ej. standard, sameday). Consulta la documentación de Enviame.', 'wc-enviame-shipping'),
                'default' => 'standard', // Cambiado valor por defecto a standard para evitar problemas con nextday
                'desc_tip' => true
                )
            );
        }
        
        /**
        * Calcula el costo de envío
        */
        public function calculate_shipping($package = array()) {
            if (empty($package['destination']['city'])) {
                return; // Si no hay ciudad de destino, no podemos calcular el envío
            }
            
            try {
                // Determinar dimensiones y peso
                $weight = 0;
                $length = 0;
                $width = 0;
                $height = 0;
                
                // Calcular peso y dimensiones totales
                foreach ($package['contents'] as $item_id => $values) {
                    $product = $values['data'];
                    $qty = $values['quantity'];
                    
                    // Acumular peso
                    $weight += (float) $product->get_weight() * $qty;
                    
                    // Para las dimensiones podríamos tomar las máximas o acumular volumen, aquí tomamos las máximas
                    $length = max($length, (float) $product->get_length());
                    $width = max($width, (float) $product->get_width());
                    $height = max($height, (float) $product->get_height());
                }
                
                // Parámetros comunes para todas las solicitudes
                $common_params = array(
                    'weight' => !empty($weight) ? $weight : 0.1, // Valor mínimo para evitar errores
                    'from_place' => $this->default_origin, 
                    'to_place' => $package['destination']['city'],
                    'length' => !empty($length) ? $length : 10, // Valores mínimos para evitar errores
                    'height' => !empty($height) ? $height : 10,
                    'width' => !empty($width) ? $width : 10
                );
                
                // URL base
                $api_url = 'https://api.enviame.io/api/v1/prices';
                
                // Obtener carriers seleccionados
                $selected_carriers = $this->get_option('carrier', array());
                if (!is_array($selected_carriers)) {
                    // Si por algún motivo no es un array, convertirlo a un array con un solo elemento
                    $selected_carriers = array($selected_carriers);
                }
                
                error_log('[Enviame Plugin] Carriers seleccionados: ' . print_r($selected_carriers, true));
                
                // Construir la URL adecuadamente según si hay uno o varios carriers
                if (count($selected_carriers) === 0) {
                    // Sin carriers, solo parámetros comunes
                    $final_url = add_query_arg($common_params, $api_url);
                    error_log('[Enviame Plugin] Consultando sin especificar carriers: ' . $final_url);
                } elseif (count($selected_carriers) === 1) {
                    // Solo un carrier: usar &carrier=XXX (singular)
                    $final_params = array_merge($common_params, array('carrier' => $selected_carriers[0]));
                    
                    // Cuando se especifica carrier, SE DEBE especificar service según la API
                    if (!empty($this->service)) {
                        $final_params['service'] = trim($this->service);
                    } else {
                        // Si no hay servicio configurado, usar uno por defecto para evitar el error
                        // $final_params['service'] = 'standard';
                        error_log('[Enviame Plugin] ADVERTENCIA: No hay servicio configurado, usando "standard" por defecto');
                    }
                    
                    $final_url = add_query_arg($final_params, $api_url);
                    error_log('[Enviame Plugin] Consultando un solo carrier: ' . $final_url);
                } else {
                    // Múltiples carriers: usar &carriers=XXX&carriers=YYY (múltiple, con la misma clave)
                    // No podemos usar add_query_arg para este caso porque sobrescribe las claves repetidas
                    $final_url = $api_url . '?' . http_build_query($common_params);
                    
                    // Añadir carriers uno por uno con la misma clave
                    foreach ($selected_carriers as $carrier) {
                        $final_url .= '&carriers=' . urlencode($carrier);
                    }
                    
                    // Para múltiples carriers, DEBES especificar service según la API
                    if (!empty($this->service)) {
                        $final_url .= '&services=' . urlencode(trim($this->service));
                    } else {
                        // Si no hay servicio configurado, usar uno por defecto para evitar el error
                        // $final_url .= '&service=standard';
                        error_log('[Enviame Plugin] ADVERTENCIA: No hay servicio configurado, usando "standard" por defecto');
                    }
                    
                    error_log('[Enviame Plugin] Consultando múltiples carriers: ' . $final_url);
                }
                
                // Debug: Mostrar URL final (solo en modo desarrollo/testing)
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    wc_add_notice('Enviame Request URL: ' . esc_html($final_url), 'notice');
                }
                
                // wc_add_notice(__('esto se llama a enviame: ', 'wc-enviame-shipping') . $final_url, 'error');
                // Realizar la solicitud HTTP
                $response = wp_remote_get(
                    $final_url,
                    array(
                        'headers' => array(
                            'Accept' => 'application/json', 
                            'x-api-key' => $this->api_key
                        ),
                        'timeout' => 15
                    )
                );
                
                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message();
                    wc_add_notice(__('Error al contactar API Enviame: ', 'wc-enviame-shipping') . $error_message, 'error');
                    error_log('[Enviame Plugin] WP Error: ' . $error_message);
                    return;
                }
                
                $response_code = wp_remote_retrieve_response_code($response);
                $body_raw = wp_remote_retrieve_body($response);
                $body = json_decode($body_raw, true);
                
                // Log de respuestas para debugging
                error_log('[Enviame Plugin] API Response Code: ' . $response_code);
                error_log('[Enviame Plugin] API Response Body: ' . $body_raw);
                
                // Debug: Mostrar cuerpo de la respuesta (solo en modo desarrollo/testing)
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    wc_add_notice('Enviame Response Code: ' . esc_html($response_code), 'notice');
                    wc_add_notice('Enviame Response Body: <pre>' . esc_html($body_raw) . '</pre>', 'notice');
                }
                
                // Manejo de errores HTTP
                if ($response_code >= 400) {
                    $error_message = sprintf(__('Error al consultar API Enviame (%d): %s. Detalles: %s', 'wc-enviame-shipping'), 
                        $response_code, 
                        wp_remote_retrieve_response_message($response),
                        $body_raw
                    );
                    
                    // Detectar error específico de carrier no encontrado
                    if ($response_code == 404 && isset($body['errors']) && is_array($body['errors'])) {
                        foreach ($body['errors'] as $error) {
                            if (isset($error['type']) && $error['type'] === 'CarrierNotFoundException') {
                                error_log('[Enviame Plugin] Error: Carrier no encontrado. Intentando sin especificar carrier.');
                                
                                // Reintentamos sin especificar carrier
                                $retry_url = add_query_arg($common_params, $api_url);
                                $response_retry = wp_remote_get(
                                    $retry_url,
                                    array(
                                        'headers' => array(
                                            'Accept' => 'application/json', 
                                            'x-api-key' => $this->api_key
                                        ),
                                        'timeout' => 15
                                    )
                                );
                                
                                if (!is_wp_error($response_retry)) {
                                    $response_code = wp_remote_retrieve_response_code($response_retry);
                                    $body_raw = wp_remote_retrieve_body($response_retry);
                                    $body = json_decode($body_raw, true);
                                    
                                    if ($response_code < 400) {
                                        // Si el reintento funciona, continuamos con el procesamiento normal
                                        error_log('[Enviame Plugin] Reintento exitoso sin carrier, continuando con opciones disponibles');
                                    } else {
                                        // Si el reintento también falla, mostramos error
                                        wc_add_notice(__('No se pudieron obtener opciones de envío. Por favor contacte al administrador.', 'wc-enviame-shipping'), 'error');
                                        error_log('[Enviame Plugin] Reintento también falló: ' . $body_raw);
                                        return;
                                    }
                                } else {
                                    // Error en el reintento
                                    wc_add_notice(__('Error al calcular opciones de envío. Por favor contacte al administrador.', 'wc-enviame-shipping'), 'error');
                                    error_log('[Enviame Plugin] Error en reintento: ' . $response_retry->get_error_message());
                                    return;
                                }
                                break; // Encontramos y manejamos el error, salimos del bucle
                            }
                        }
                    }
                    // Para otros errores HTTP
                    else {
                        wc_add_notice($error_message, 'error');
                        error_log('[Enviame Plugin] HTTP Error ' . $response_code . ': ' . $body_raw);
                        return;
                    }
                }
                
                // --- INICIO: Lógica para añadir tarifas basada en la respuesta ---
                if ($response_code < 400 && isset($body['data']) && is_array($body['data'])) {
                    
                    if (empty($body['data'])) {
                        wc_add_notice(__('Enviame no devolvió opciones de envío para esta cotización.', 'wc-enviame-shipping'), 'notice');
                    } else {
                        // Iterar sobre cada carrier devuelto
                        foreach ($body['data'] as $carrier_data) {
                            if (isset($carrier_data['services']) && is_array($carrier_data['services'])) {
                                // Iterar sobre cada servicio dentro del carrier
                                foreach ($carrier_data['services'] as $service) {
                                    // Verificar que tenemos los datos necesarios (precio, nombre, código)
                                    if (isset($service['price']) && isset($service['name']) && isset($service['code']) && isset($carrier_data['name']) && isset($carrier_data['carrier'])) {
                                        
                                        $carrier_code = strtolower($carrier_data['carrier']); // e.g., 'post', 'chd'
                                        $icon_filename_svg = $carrier_code . '.svg';
                                        $icon_path_svg = WC_ENVIAME_SHIPPING_PLUGIN_PATH . 'assets/images/carriers/' . $icon_filename_svg;
                                        $icon_url_svg = WC_ENVIAME_SHIPPING_PLUGIN_URL . 'assets/images/carriers/' . $icon_filename_svg;

                                        $icon_filename_png = $carrier_code . '.png';
                                        $icon_path_png = WC_ENVIAME_SHIPPING_PLUGIN_PATH . 'assets/images/carriers/' . $icon_filename_png;
                                        $icon_url_png = WC_ENVIAME_SHIPPING_PLUGIN_URL . 'assets/images/carriers/' . $icon_filename_png;

                                        $icon_html = '';
                                        $icon_url = '';

                                        // Prioritize SVG, fallback to PNG
                                        if (file_exists($icon_path_svg)) {
                                            $icon_url = $icon_url_svg;
                                        } elseif (file_exists($icon_path_png)) {
                                            $icon_url = $icon_url_png;
                                        }

                                        if (!empty($icon_url)) {
                                            // Basic inline style for icon size - consider moving to CSS
                                            $icon_html = '<img src="' . esc_url($icon_url) . '" alt="' . esc_attr($carrier_data['name']) . '" style="height: 20px; width: auto; vertical-align: middle; margin-right: 8px;" /> ';
                                        }

                                        // Original label format
                                        $rate_label_text = sprintf('%s (%s - %s)', $this->title, $carrier_data['name'], $service['name']);

                                        $rate = array(
                                            'id' => $this->get_rate_id($carrier_data['carrier'] . '_' . $service['code']), 
                                            'label' => $icon_html . $rate_label_text, // Prepend icon HTML
                                            'cost' => $service['price'],
                                            'calc_tax' => 'per_order'
                                        );
                                        $this->add_rate($rate);
                                    } else {
                                        // Log si falta algún dato esperado en el servicio/carrier
                                        error_log('[Enviame Plugin] Servicio/Carrier con datos incompletos: ' . print_r($service, true) . ' Carrier: ' . print_r($carrier_data, true));
                                    }
                                }
                            }
                        }
                    }
                    
                } elseif ($response_code < 400) { 
                    // Si la respuesta fue exitosa (2xx) pero no tiene la estructura esperada
                    wc_add_notice(__('La respuesta de Enviame no tiene el formato esperado. Respuesta: %s', 'wc-enviame-shipping') . '<pre>' . esc_html($body_raw) . '</pre>', 'notice');
                }
                // Si hubo error 4xx/5xx, el aviso ya se mostró antes
                // --- FIN: Lógica para añadir tarifas ---
                
            } catch (Exception $e) {
                wc_add_notice($e->getMessage(), 'error');
                error_log('[Enviame Plugin] Exception: ' . $e->getMessage()); // Log de excepciones
            }
        }
    }