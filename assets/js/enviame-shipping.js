jQuery(function($) {
    // Actualizar cuando cambie la ciudad
    $(document.body).on('change', '#shipping_city, #calc_shipping_city', function() {
        $(document.body).trigger('update_checkout');
    });

    // Forzar actualización cuando se calcule el envío
    $(document.body).on('click', 'button[name="calc_shipping"]', function() {
        setTimeout(function() {
            $(document.body).trigger('update_checkout');
        }, 500);
    });

    // Actualizar cuando cambie el país o estado
    $(document.body).on('change', '#shipping_country, #shipping_state, #calc_shipping_country, #calc_shipping_state', function() {
        setTimeout(function() {
            $(document.body).trigger('update_checkout');
        }, 100);
    });
});