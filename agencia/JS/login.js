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

let formSubmitting = false;

document.getElementById('loginForm').addEventListener('submit', function(e) {
    const btnLogin = document.getElementById('btnLogin');

    if (formSubmitting) {
        e.preventDefault();
        return false;
    }

    if (!this.correo.value || !document.getElementById('contraseña').value) {
        e.preventDefault();
        return false;
    }

    formSubmitting = true;
    btnLogin.disabled = true;
    btnLogin.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verificando...';

    return true;
});

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('correo').focus();

    const bgImage = new Image();
    bgImage.src = '../images/agencia.avif';

    bgImage.onerror = function() {
        document.body.classList.add('no-bg-image');
    };
});
