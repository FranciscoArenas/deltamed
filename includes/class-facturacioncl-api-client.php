<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Cliente para interactuar con la API SOAP de Facturacion.cl
 */
class WC_FacturacionCL_API_Client
{

    private $api_user;
    private $api_pass;
    private $wsdl_url = 'https://www.facturacion.cl/wsFacturaelectronica/FacturaElectronica.asmx?wsdl';
    private $service_url = 'https://www.facturacion.cl/wsFacturaelectronica/FacturaElectronica.asmx';
    private $namespace = 'http://www.facturacion.cl/';
    private $soap_client = null;

    /**
     * Constructor.
     *
     * @param string $api_user Usuario de la API.
     * @param string $api_pass Contraseña de la API.
     */
    public function __construct($api_user, $api_pass)
    {
        $this->api_user = $api_user;
        $this->api_pass = $api_pass;

        // Opciones para el cliente SOAP
        $soap_options = [
            'trace' => 1, // Habilitar trace para debugging
            'exception' => 1, // Lanzar excepciones en errores SOAP
            'cache_wsdl' => WSDL_CACHE_NONE, // No cachear WSDL durante desarrollo
            'connection_timeout' => 15, // Timeout de conexión en segundos
            // 'stream_context' => stream_context_create([
            //     'ssl' => [
            //         // Opciones SSL si son necesarias (ej. verificar peer, CA cert)
            //         'verify_peer' => true,
            //         'verify_peer_name' => true,
            //     ]
            // ]),
            'user_agent' => 'WooCommerce FacturacionCL Integration/' . WC_FACTURACIONCL_VERSION,
        ];

        try {
            $this->soap_client = new SoapClient($this->wsdl_url, $soap_options);
        } catch (SoapFault $e) {
            // Log inicialización fallida
            wc_get_logger()->error('Failed to initialize SoapClient: ' . $e->getMessage(), array('source' => 'wc-facturacioncl'));
            $this->soap_client = null; // Asegurar que el cliente es nulo si falla
            throw $e; // Re-lanzar excepción para que el llamador sepa
        }
    }

    /**
     * Realiza una llamada SOAP genérica.
     *
     * @param string $operation El nombre de la operación SOAP.
     * @param array $params Parámetros para la operación.
     * @return mixed El resultado de la operación o lanza excepción.
     * @throws SoapFault Si la llamada SOAP falla.
     * @throws Exception Si el cliente SOAP no se inicializó.
     */
    private function _make_soap_call($operation, $params)
    {
        if (! $this->soap_client) {
            throw new Exception(__('SOAP client not initialized.', 'wc-facturacioncl'));
        }

        try {
            // El header SOAPAction es crucial para ASMX
            $soap_action_header = new SoapHeader($this->namespace, 'SOAPAction', $this->namespace . $operation);
            // $this->soap_client->__setSoapHeaders($soap_action_header); // SetHeader puede no ser necesario si se pasa bien en __soapCall

            // Realizar la llamada SOAP
            // El nombre de la operación y los parámetros deben coincidir EXACTAMENTE con el WSDL
            // SoapClient a menudo espera los parámetros dentro de un array asociativo que coincide con el nombre del elemento raíz de los parámetros en el WSDL
            $result = $this->soap_client->__soapCall($operation, [$params]); // Envolver params en un array

            // Debug: Log request and response (¡quitar en producción!)
            // wc_get_logger()->debug( 'SOAP Request Header: ' . print_r($this->soap_client->__getLastRequestHeaders(), true), array( 'source' => 'wc-facturacioncl' ) );
            // wc_get_logger()->debug( 'SOAP Request Body: ' . print_r($this->soap_client->__getLastRequest(), true), array( 'source' => 'wc-facturacioncl' ) );
            // wc_get_logger()->debug( 'SOAP Response Header: ' . print_r($this->soap_client->__getLastResponseHeaders(), true), array( 'source' => 'wc-facturacioncl' ) );
            // wc_get_logger()->debug( 'SOAP Response Body: ' . print_r($this->soap_client->__getLastResponse(), true), array( 'source' => 'wc-facturacioncl' ) );


            // El resultado suele estar anidado, ej. $result->{$operation.'Result'}
            // Verifica la estructura exacta de la respuesta en el WSDL o con pruebas
            if (isset($result->{$operation . 'Result'})) {
                return $result->{$operation . 'Result'};
            } else {
                // Si la estructura es diferente, ajusta aquí o devuelve el resultado directo
                // wc_get_logger()->warning( 'Unexpected SOAP response structure for operation ' . $operation . ': ' . print_r($result, true), array( 'source' => 'wc-facturacioncl' ) );
                // Puede que el resultado venga directo sin el 'Result' suffix.
                // O que esté dentro de un objeto con el nombre de la operación directamente.
                // Por ejemplo, si la respuesta es <AutenticacionResponse><AutenticacionResult>...</..>
                // O si es <Envio_DTEResponse><Envio_DTEResult><Estado>OK</Estado>...</...>
                // O incluso más simple <Envio_DTEResponse><Estado>OK</Estado>...</...>
                // ¡¡Revisar la respuesta real!!
                // Asumiremos por ahora que el resultado directo es lo que buscamos si no hay 'Result'
                return $result;
            }
        } catch (SoapFault $e) {
            // Log detallado del error SOAP
            wc_get_logger()->error(
                sprintf(
                    'SOAP Fault in operation %s: %s. Request: %s Response: %s',
                    $operation,
                    $e->getMessage(),
                    $this->soap_client->__getLastRequest(),
                    $this->soap_client->__getLastResponse()
                ),
                array('source' => 'wc-facturacioncl')
            );
            throw $e; // Re-lanzar para manejo superior
        }
    }

    /**
     * Autentica contra la API.
     *
     * @return string|false El token de autenticación o false si falla.
     * @throws SoapFault | Exception
     */
    public function authenticate()
    {
        $params = [
            'Usuario' => $this->api_user,
            'Password' => $this->api_pass
        ];
        // La estructura esperada por __soapCall debe coincidir con el elemento <Autenticacion> del WSDL
        $response = $this->_make_soap_call('Autenticacion', ['Autenticacion' => $params]);

        // Analizar la respuesta específica de Autenticacion
        // Asumiendo que la respuesta tiene una estructura como <AutenticacionResult><Token>...</Token><Estado>...</Estado></AutenticacionResult>
        // O podría ser simplemente el token directamente si el estado es OK. ¡VERIFICAR RESPUESTA REAL!
        if (isset($response->Estado) && $response->Estado === 'OK' && isset($response->Token)) {
            return $response->Token;
        } elseif (is_string($response) && !empty($response)) {
            // Quizás devuelve solo el token si es exitoso? Menos probable.
            // wc_get_logger()->info('Authentication returned a string directly: ' . $response, array('source' => 'wc-facturacioncl'));
            // return $response; // Considerar si este es un caso válido
            return false; // Asumir fallo si no es la estructura esperada
        } else {
            $error_msg = isset($response->Mensaje) ? $response->Mensaje : __('Authentication failed, unknown reason.', 'wc-facturacioncl');
            wc_get_logger()->error('Facturacion.cl Authentication failed: ' . $error_msg, array('source' => 'wc-facturacioncl'));
            return false;
        }
    }

    /**
     * Envía un DTE a la API.
     *
     * @param string $token El token de autenticación.
     * @param string $dte_xml El XML completo del DTE.
     * @return object|false El objeto de respuesta de la API o false si falla.
     * @throws SoapFault | Exception
     */
    public function send_dte($token, $dte_xml)
    {
        // Los parámetros deben coincidir con la operación Envio_DTE del WSDL
        $params = [
            'Token' => $token,
            'Xml' => $dte_xml // El XML debe ir aquí, tal cual como string
        ];

        // La estructura esperada por __soapCall debe coincidir con el elemento <Envio_DTE> del WSDL
        $response = $this->_make_soap_call('Envio_DTE', ['Envio_DTE' => $params]);

        // La respuesta de Envio_DTE suele ser un objeto con Estado, Mensaje, Id, TrackId, etc.
        // No hay un sufijo 'Result' común aquí según la documentación de ejemplo.
        return $response; // Devolver el objeto de respuesta completo
    }

    // --- Otras funciones de la API (Opcional) ---
    // public function get_dte_status($token, $rut_empresa, $tipo_dte, $folio_dte) { ... }
    // public function get_dte_status_by_id($token, $internal_id) { ... }
    // public function get_dte_status_by_trackid($token, $track_id) { ... }

}
