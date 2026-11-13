document.addEventListener('DOMContentLoaded', function() {
    const todasSalas = document.getElementById('todas_salas');
    const salasEspecificas = document.getElementById('salas_especificas');
    const selectorSalas = document.getElementById('selector_salas');
    const radioSalas = document.querySelectorAll('input[name="salas_seleccionadas"]');

    // Solo configurar eventos si el admin es total
    if (adminAccesoTodas) {

        function toggleSalasSelector() {
            if (salasEspecificas.checked) {
                selectorSalas.style.display = 'block';
            } else {
                selectorSalas.style.display = 'none';
                // Desmarcar todos los radio buttons
                radioSalas.forEach(radio => {
                    radio.checked = false;
                });
            }
        }

        // Event listeners solo para admin total
        if (todasSalas && salasEspecificas) {
            todasSalas.addEventListener('change', toggleSalasSelector);
            salasEspecificas.addEventListener('change', toggleSalasSelector);
        }
    }

    // Validación del formulario
    document.getElementById('formRegistro').addEventListener('submit', function(e) {
        // Validar que se haya seleccionado una sala si es necesario
        if (adminAccesoTodas) {
            if (salasEspecificas && salasEspecificas.checked) {
                const algunaSeleccionada = Array.from(radioSalas).some(radio => radio.checked);
                if (!algunaSeleccionada) {
                    e.preventDefault();
                    alert('Por favor selecciona una sala o elige "Todas las salas"');
                    return false;
                }
            }
        } else {
            // Para admin limitado, siempre debe seleccionar una sala
            const algunaSeleccionada = Array.from(radioSalas).some(radio => radio.checked);
            if (!algunaSeleccionada) {
                e.preventDefault();
                alert('Por favor selecciona una sala para el usuario');
                return false;
            }
        }

        // Validar contraseña
        const password = document.getElementById('password');
        if (password.value.length < 6) {
            e.preventDefault();
            alert('La contraseña debe tener al menos 6 caracteres');
            password.focus();
            return false;
        }

        return true;
    });
});
