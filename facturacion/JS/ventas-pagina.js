// Pasar datos PHP a JavaScript (json_encode seguro dentro de <script>)

// Inicializar la aplicación
document.addEventListener('DOMContentLoaded', function() {
  if (typeof inicializarAplicacion === 'function') {
    inicializarAplicacion(
      totalVentas,
      datosVentas,
      busquedaInicial,
      plataformaIdsInicial,
      tipoVentaInicial,
      salaIdInicial,
      esUsuarioAdminParam,
      fechaInicioMesParam,
      fechaFinMesParam,
      salaUsuarioIdParam,
      rolUsuarioParam,
      tieneAccesoTodasSalasParam,
      salasPermitidasParam,
      tienePermisoPlataformaParam
    );
  }
});
