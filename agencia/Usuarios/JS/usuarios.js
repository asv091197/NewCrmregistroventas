function agregarEventosFila() {
  document.querySelectorAll('#tabla-clientes tr').forEach(fila => {
    fila.addEventListener('click', function(e) {
      if (e.target.closest('button')) return;
      const id = this.getAttribute('data-id');
      if (id) abrirModalEditar(id);
    });
  });
}

document.getElementById('buscar').addEventListener('input', function() {
  const query = this.value.trim();
  if (query.length >= 1) {
    fetch(`buscar_usuario.php?buscar=${encodeURIComponent(query)}`)
      .then(response => response.text())
      .then(data => {
        document.getElementById('tabla-clientes').innerHTML = data;
        document.getElementById('mensaje').textContent = data.trim() === '' ? 'No se encontraron resultados.' : '';
        agregarEventosFila();
      });
  } else {
    document.getElementById('tabla-clientes').innerHTML = '';
    document.getElementById('mensaje').textContent = 'Escribe al menos un caracter para buscar.';
  }
});

function validarCamposEditar() {
  const camposObligatorios = [
    'editar_nombre',
    'editar_apepat',
    'editar_apemat',
    'editar_correo',
    'editar_rol'
  ];

  const todosLlenos = camposObligatorios.every(id => {
    const valor = document.getElementById(id).value.trim();
    return valor.length > 0;
  });

  const boton = document.querySelector('#formEditar button[type="submit"]');
  boton.disabled = !todosLlenos;
}

function abrirModalEditar(id) {
  fetch(`obtener_usuario.php?id=${id}`)
    .then(res => res.json())
    .then(data => {
      document.getElementById('editar_id').value = id;
      document.getElementById('editar_nombre').value = data.nombre;
      document.getElementById('editar_apepat').value = data.apepat;
      document.getElementById('editar_apemat').value = data.apemat;
      document.getElementById('editar_correo').value = data.correo;
      document.getElementById('editar_rol').value = data.rol;
      document.getElementById('editar_password').value = '';

      // Deshabilitar el botón al abrir el modal
      document.querySelector('#formEditar button[type="submit"]').disabled = true;

      // Validar campos en tiempo real
      const campos = [
        'editar_nombre',
        'editar_apepat',
        'editar_apemat',
        'editar_correo',
        'editar_rol',
        'editar_password' 
      ];

      campos.forEach(id => {
        document.getElementById(id).addEventListener('input', validarCamposEditar);
      });

      new bootstrap.Modal(document.getElementById('modalEditar')).show();
    });
}

document.getElementById('formEditar').addEventListener('submit', function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  fetch('actualizar_usuario.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.text())
  .then(msg => {
    alert(msg);
    document.getElementById('buscar').dispatchEvent(new Event('input'));
    bootstrap.Modal.getInstance(document.getElementById('modalEditar')).hide();
  });
});
