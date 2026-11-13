// Pasar datos PHP a JavaScript

// Inicializar la aplicación
document.addEventListener('DOMContentLoaded', function() {
    if (typeof inicializarAplicacion === 'function') {
        inicializarAplicacion(totalVentas, datosVentas, busquedaInicial, plataformaIdsInicial, esUsuarioAdmin, fechaInicioMes, fechaFinMes);
    }
});
