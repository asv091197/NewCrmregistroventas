let formSubmitting = false;

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

document.getElementById('loginForm').addEventListener('submit', function(e) {
    if (formSubmitting) {
        e.preventDefault();
        return false;
    }

    const emailInput = document.getElementById('correo');
    const passwordInput = document.getElementById('contraseña');
    const btnLogin = document.getElementById('btnLogin');

    emailInput.classList.remove('is-invalid');
    passwordInput.classList.remove('is-invalid');

    let isValid = true;

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(emailInput.value.trim())) {
        emailInput.classList.add('is-invalid');
        isValid = false;
    }

    if (passwordInput.value.length < 1) {
        passwordInput.classList.add('is-invalid');
        isValid = false;
    }

    if (!isValid) {
        e.preventDefault();
        return false;
    }

    formSubmitting = true;
    btnLogin.innerHTML = '';
    btnLogin.classList.add('loading');
    btnLogin.disabled = true;

    return true;
});

document.getElementById('loginForm').addEventListener('input', function() {
    const emailInput = document.getElementById('correo');
    const passwordInput = document.getElementById('contraseña');
    const btnLogin = document.getElementById('btnLogin');

    if (emailInput.classList.contains('is-invalid')) {
        emailInput.classList.remove('is-invalid');
    }

    if (passwordInput.classList.contains('is-invalid')) {
        passwordInput.classList.remove('is-invalid');
    }

    if (btnLogin.disabled && !btnLogin.classList.contains('loading')) {
        btnLogin.disabled = false;
    }
});

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('correo').focus();

    const bgImage = new Image();
    bgImage.src = '../images/facturacion.avif';

    bgImage.onerror = function() {
        document.body.classList.add('no-bg-image');
        console.log('La imagen de fondo no pudo cargar, usando gradiente alternativo');
    };

    setTimeout(() => {
        const btnLogin = document.getElementById('btnLogin');
        if (btnLogin.classList.contains('loading')) {
            btnLogin.classList.remove('loading');
            btnLogin.disabled = false;
            btnLogin.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i>Acceder a Ventas';
            formSubmitting = false;

            showAlert('Error de conexión. Por favor intente nuevamente.', 'danger');
        }
    }, 10000);
});

function showAlert(message, type) {
    const oldAlerts = document.querySelectorAll('.alert.temporary');
    oldAlerts.forEach(alert => alert.remove());

    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show text-center shake temporary`;
    alertDiv.innerHTML = `
        <i class="fas ${type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;

    const form = document.getElementById('loginForm');
    if (form && form.parentNode) {
        form.parentNode.insertBefore(alertDiv, form);

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

document.getElementById('btnLogin').addEventListener('click', function(e) {
    if (formSubmitting) {
        e.preventDefault();
        e.stopPropagation();
        return false;
    }
});
// Enter en los campos usa el envío nativo del formulario (pasa por la validación del submit)
