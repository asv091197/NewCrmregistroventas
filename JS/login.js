// Evitar múltiples envíos del formulario
let formSubmitting = false;

// Toggle para mostrar/ocultar contraseña
document.getElementById('togglePassword').addEventListener('click', function() {
    const passwordInput = document.getElementById('contraseña');
    const icon = this.querySelector('i');

    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
});

// Validación del formulario antes de enviar
document.getElementById('loginForm').addEventListener('submit', function(e) {
    if (formSubmitting) {
        e.preventDefault();
        return false;
    }

    const emailInput = document.getElementById('correo');
    const passwordInput = document.getElementById('contraseña');
    const btnLogin = document.getElementById('btnLogin');

    // Limpiar estados previos
    emailInput.classList.remove('is-invalid');
    passwordInput.classList.remove('is-invalid');

    let isValid = true;

    // Validación de email
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(emailInput.value.trim())) {
        emailInput.classList.add('is-invalid');
        isValid = false;
    }

    // Validación de contraseña
    if (passwordInput.value.length < 1) {
        passwordInput.classList.add('is-invalid');
        isValid = false;
    }

    if (!isValid) {
        e.preventDefault();
        return false;
    }

    // Cambiar estado del botón durante el envío
    formSubmitting = true;
    btnLogin.innerHTML = '';
    btnLogin.classList.add('loading');
    btnLogin.disabled = true;

    // Permitir el envío del formulario
    return true;
});

// Resetear estado del formulario si hay errores de validación
document.getElementById('loginForm').addEventListener('input', function() {
    const emailInput = document.getElementById('correo');
    const passwordInput = document.getElementById('contraseña');
    const btnLogin = document.getElementById('btnLogin');

    // Remover estado de error al empezar a escribir
    if (emailInput.classList.contains('is-invalid')) {
        emailInput.classList.remove('is-invalid');
    }

    if (passwordInput.classList.contains('is-invalid')) {
        passwordInput.classList.remove('is-invalid');
    }

    // Si el botón está deshabilitado (por error previo) y hay contenido, habilitarlo
    if (btnLogin.disabled && !btnLogin.classList.contains('loading')) {
        btnLogin.disabled = false;
    }
});

// Auto-focus en el campo de correo al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('correo').focus();

    // Detectar si la imagen de fondo no carga
    const bgImage = new Image();
    bgImage.src = '../images/agencia.avif';

    bgImage.onerror = function() {
        document.body.classList.add('no-bg-image');
    };

    // Si hay un error de redirección (por ejemplo, si JavaScript bloqueó la redirección automática)
    // podemos agregar un botón de redirección manual
    setTimeout(() => {
        const btnLogin = document.getElementById('btnLogin');
        if (btnLogin.classList.contains('loading')) {
            // Si después de 10 segundos sigue en estado loading, restaurar botón
            btnLogin.classList.remove('loading');
            btnLogin.disabled = false;
            btnLogin.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i>Acceder a Agencia';
            formSubmitting = false;

            // Mostrar mensaje de error
            showAlert('Error de conexión. Por favor intente nuevamente.', 'danger');
        }
    }, 10000);
});

function showAlert(message, type) {
    // Remover alertas anteriores del mismo tipo
    const oldAlerts = document.querySelectorAll('.alert.temporary');
    oldAlerts.forEach(alert => alert.remove());

    // Crear alerta temporal
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show text-center shake temporary`;
    alertDiv.innerHTML = `
        <i class="fas ${type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;

    // Insertar antes del formulario
    const form = document.getElementById('loginForm');
    if (form && form.parentNode) {
        form.parentNode.insertBefore(alertDiv, form);

        // Auto-eliminar después de 5 segundos
        setTimeout(() => {
            if (alertDiv.parentNode) {
                try {
                    alertDiv.remove();
                } catch (e) {
                    console.log('Error al remover alerta:', e);
                }
            }
        }, 5000);
    }
}
