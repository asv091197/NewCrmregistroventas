// Validación del rango de fechas
const fechaInicio = document.getElementById('fecha_inicio');
const fechaFin    = document.getElementById('fecha_fin');
if (fechaInicio && fechaFin) {
    fechaInicio.addEventListener('change', () => {
        fechaFin.min = fechaInicio.value;
        if (fechaFin.value && fechaInicio.value > fechaFin.value) fechaFin.value = fechaInicio.value;
    });
    fechaFin.addEventListener('change', () => {
        fechaInicio.max = fechaFin.value;
        if (fechaInicio.value && fechaInicio.value > fechaFin.value) fechaInicio.value = fechaFin.value;
    });
}

// Click en fila abre el detalle
document.querySelectorAll('.fila-log').forEach(fila => {
    fila.addEventListener('click', e => {
        if (e.target.closest('a, button')) return;
        window.location.href = 'Logs?sesion=' + fila.dataset.id;
    });
});
