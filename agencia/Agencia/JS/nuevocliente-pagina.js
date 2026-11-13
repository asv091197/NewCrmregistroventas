document.addEventListener('DOMContentLoaded', function() {
  // Enlaza un botón con su sección plegable; la abre si su select ya tiene un valor
  function plegable(idBoton, idSeccion, idSelect, textoMostrar, textoOcultar) {
    const boton = document.getElementById(idBoton);
    const seccion = document.getElementById(idSeccion);
    const select = document.getElementById(idSelect);
    if (!boton || !seccion) return;

    const actualizarTexto = () => {
      boton.innerHTML = seccion.classList.contains('show') ? textoOcultar : textoMostrar;
    };
    if (select && select.value !== '') seccion.classList.add('show');
    actualizarTexto();
    boton.addEventListener('click', () => {
      seccion.classList.toggle('show');
      actualizarTexto();
    });
  }

  plegable('btnAgregarCloser', 'closerSection', 'selectCloser',
    '<i class="bi bi-plus-circle"></i> Agregar Closer', '<i class="bi bi-dash-circle"></i> Ocultar Closer');
  plegable('btnSeleccionarSala', 'salaAdminSection', 'salaSelectAdmin',
    '<i class="bi bi-building"></i> Asignar Sala', '<i class="bi bi-building-slash"></i> Ocultar Sala');

  // Al cambiar la sala, recargar el formulario para actualizar los closers
  const salaSelectAdmin = document.getElementById('salaSelectAdmin');
  if (salaSelectAdmin) {
    salaSelectAdmin.addEventListener('change', function() {
      const clienteForm = document.getElementById('clienteForm');
      const updateInput = document.createElement('input');
      updateInput.type = 'hidden';
      updateInput.name = 'actualizar_closers';
      updateInput.value = '1';
      clienteForm.appendChild(updateInput);
      clienteForm.submit();
    });
  }
});
