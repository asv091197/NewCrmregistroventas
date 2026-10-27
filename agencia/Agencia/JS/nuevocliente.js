 document.querySelectorAll('input[name="nombre"], input[name="apepat"], input[name="apemat"]').forEach(input => {
      input.addEventListener('input', function() {
        this.value = this.value.replace(/[^A-Za-zÁÉÍÓÚáéíóúÑñ\s]/g, '');
      });
    });
    document.querySelectorAll('input[name="numero1"], input[name="numero2"]').forEach(input => {
      input.addEventListener('input', function() {
        this.value = this.value.replace(/\D/g, '');
      });
    });