// Variables globales
let valoresOriginales = {};
let usuariosCargados = [];
let closersCargados = [];
let promesaUsuarios = null;
let timeoutBusqueda = null;

// ====================== FUNCIONES UTILITARIAS ======================
function getElement(id) {
  const el = document.getElementById(id);
  if (!el) console.warn(`Elemento con ID ${id} no encontrado`);
  return el;
}

function volverAAgencia() {
  window.location.href = 'agencia.php';
}

function toggleFilters() {
  const filterContent = getElement('filterContent');
  if (filterContent) {
    filterContent.classList.toggle('show');
  }
}

// ====================== BOTÓN LIMPIAR BÚSQUEDA ======================
function configurarBotonLimpiar() {
  const buscarInput = getElement('buscar');
  const clearButton = getElement('clear-search');
  if (buscarInput && clearButton) {
    // La visibilidad del botón al escribir se controla en configurarBusqueda()
    clearButton.addEventListener('click', function () {
      buscarInput.value = '';
      clearButton.style.display = 'none';
      actualizarContadorClientes(totalClientesInicial, 'Total');
      volverAAgencia();
    });
  }
}

function actualizarContadorClientes(cantidad, texto = 'Resultados') {
  const contador = getElement('contador-clientes');
  if (contador) contador.textContent = `${texto}: ${cantidad}`;
}

// ====================== BÚSQUEDA EN TIEMPO REAL ======================
function configurarBusqueda() {
  const buscarInput = getElement('buscar');
  const tablaContainer = getElement('tabla-container');
  const mensaje = getElement('mensaje');

  if (!buscarInput || !tablaContainer || !mensaje) return;

  buscarInput.addEventListener('input', function() {
    const termino = this.value.trim();

    // Mostrar/ocultar botón de limpiar
    getElement('clear-search').style.display = termino ? 'block' : 'none';

    // Cancelar búsqueda anterior si todavía está pendiente
    if (timeoutBusqueda) {
      clearTimeout(timeoutBusqueda);
    }

    // Si el término está vacío, restaurar vista inicial
    if (termino === '') {
      restaurarVistaInicial();
      return;
    }

    // Mostrar indicador de carga
    mensaje.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Buscando...';
    tablaContainer.classList.add('loading');

    // Esperar 300ms después de que el usuario deje de escribir
    timeoutBusqueda = setTimeout(() => {
      buscarClientes(termino);
    }, 300);
  });
}

function restaurarVistaInicial() {
  window.location.reload(); // La forma más simple de restaurar
}

function buscarClientes(termino) {
  const tablaClientes = getElement('tabla-clientes');
  const mensaje = getElement('mensaje');
  const tablaContainer = getElement('tabla-container');
  const params = new URLSearchParams();

  params.append('buscar', termino);

  // También incluir filtros de fecha si están activos
  const fechaInicio = getElement('fecha_inicio')?.value || '';
  const fechaFin = getElement('fecha_fin')?.value || '';

  if (fechaInicio) params.append('fecha_inicio', fechaInicio);
  if (fechaFin) params.append('fecha_fin', fechaFin);

  // Incluir información del usuario para el filtrado
  params.append('usuario_id', usuarioIdSesion);
  params.append('rol', rolSesion);

  fetch(`buscar_clientes.php?${params.toString()}`)
    .then(response => response.text())
    .then(data => {
      // Extraer el contador de resultados si está presente
      const contadorMatch = data.match(/<div id="resultado-contador" data-total="(\d+)"><\/div>/);
      const totalResultados = contadorMatch ? parseInt(contadorMatch[1]) : 0;

      // Remover el div del contador para no mostrarlo en la tabla
      const htmlResultados = data.replace(/<div id="resultado-contador" data-total="\d+"><\/div>/, '');

      // Actualizar tabla y contador
      tablaClientes.innerHTML = htmlResultados;
      actualizarContadorClientes(totalResultados, 'Resultados');

      // Actualizar mensaje
      if (totalResultados > 0) {
        mensaje.textContent = `Se encontraron ${totalResultados} resultados`;
      } else {
        mensaje.textContent = 'No se encontraron resultados';
      }

      // Reagregar eventos a las filas
      agregarEventosFila();
    })
    .catch(error => {
      console.error('Error en la búsqueda:', error);
      mensaje.textContent = 'Error al realizar la búsqueda';
    })
    .finally(() => {
      tablaContainer.classList.remove('loading');
    });
}

// ====================== TABLA CLIENTES ======================
function agregarEventosFila() {
  document.querySelectorAll('#tabla-clientes tr[data-id]').forEach(fila => {
    fila.addEventListener('click', function (e) {
      if (e.target.closest('button')) return;
      const id = this.getAttribute('data-id');
      if (id) abrirModalEditar(id);
    });
  });
}

// ====================== USUARIOS / CLOSERS ======================
// Usuarios y closers salen del mismo endpoint: una sola petición compartida
function cargarUsuarios() {
  if (!promesaUsuarios) {
    promesaUsuarios = fetch('obtener_usuarios.php')
      .then(res => res.json())
      .then(data => { usuariosCargados = closersCargados = data; })
      .catch(() => {
        usuariosCargados = closersCargados = [];
        promesaUsuarios = null; // permitir reintentar
      });
  }
  return promesaUsuarios;
}

function llenarSelectUsuarios(usuarioSeleccionadoId = '') {
  const select = getElement('usuarios_id');
  if (!select) return;
  select.innerHTML = '<option value="">-- Selecciona un usuario --</option>';
  usuariosCargados.forEach(u => {
    const option = document.createElement('option');
    option.value = u.id;
    option.textContent = u.nombre_completo || `${u.nombre} ${u.apepat} ${u.apemat || ''}`.trim();
    option.selected = (u.id == usuarioSeleccionadoId);
    select.appendChild(option);
  });
}

function llenarSelectClosers(closerSeleccionadoId = '') {
  const select = getElement('usuario_closer');
  if (!select) return;
  select.innerHTML = '<option value="">-- Seleccione un closer --</option>';
  closersCargados.forEach(c => {
    const option = document.createElement('option');
    option.value = c.id;
    option.textContent = c.nombre_completo || `${c.nombre} ${c.apepat} ${c.apemat || ''}`.trim();
    option.selected = (c.id == closerSeleccionadoId);
    select.appendChild(option);
  });
}

function configurarBotonAgregarCloser() {
  const btn = getElement('btnAgregarCloser');
  const section = getElement('closerSection');
  if (btn && section) {
    btn.addEventListener('click', () => {
      section.style.display = (section.style.display === 'none' || section.style.display === '') ? 'block' : 'none';
    });
  }
}

// ====================== EDITAR CLIENTE ======================
function abrirModalEditar(id) {
  // Cargar closers (y usuarios) si aún no están
  if (closersCargados.length === 0) {
    cargarUsuarios().then(() => obtenerDatosCliente(id));
  } else {
    obtenerDatosCliente(id);
  }
}

function obtenerDatosCliente(id) {
  fetch(`obtener_cliente.php?id=${id}`)
    .then(res => res.json())
    .then(data => {
      getElement('editar_id').value = id;

      valoresOriginales = {
        'editar_nombre': data.nombre,
        'editar_apepat': data.apepat,
        'editar_apemat': data.apemat,
        'editar_numero1': data.numero1,
        'editar_numero2': data.numero2 || '',
        'editar_asunto': data.asunto,
        'editar_descripcion': data.descripcion,
        'usuarios_id': data.usuario_id,
        'usuario_closer': data.usuario_closer || ''
      };

      Object.keys(valoresOriginales).forEach(k => {
        const el = getElement(k);
        if (el) el.value = valoresOriginales[k];
      });

      // Llenar select de usuarios solo si es admin
      if (esAdmin) {
        llenarSelectUsuarios(data.usuario_id);
      }

      // Llenar select de closers para todos los usuarios
      llenarSelectClosers(data.usuario_closer);

      const modal = new bootstrap.Modal(getElement('modalEditar'));
      modal.show();
    })
    .catch(error => {
      console.error('Error al obtener datos del cliente:', error);
    });
}

// ====================== FORMULARIO ======================
function configurarFormulario() {
  const form = getElement('formEditar');
  if (!form) return;

  // Configurar el envío del formulario para administradores
  if (esAdmin) {
    form.addEventListener('submit', e => {
      e.preventDefault();
      guardarCambios();
    });
  } else {
    // Para usuarios no admin, configurar el botón de guardar closer
    const btnGuardarCloser = getElement('btnGuardarCloser');
    if (btnGuardarCloser) {
      btnGuardarCloser.addEventListener('click', e => {
        e.preventDefault();
        guardarSoloCloser();
      });
    }

    // Prevenir envío del formulario completo
    form.addEventListener('submit', e => {
      e.preventDefault();
    });
  }
}

function guardarCambios() {
  const id = getElement('editar_id').value;
  if (!id) {
    alert('Error: ID del cliente no encontrado');
    return;
  }

  const formData = new FormData(getElement('formEditar'));

  // Mostrar indicador de carga
  const submitBtn = getElement('formEditar').querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Guardando...';
  submitBtn.disabled = true;

  fetch('actualizar_cliente.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.text())
  .then(msg => {
    alert(msg);
    window.location.reload();
  })
  .catch(error => {
    console.error('Error:', error);
    alert('Error al actualizar el cliente');
  })
  .finally(() => {
    // Restaurar botón
    submitBtn.innerHTML = originalText;
    submitBtn.disabled = false;
  });
}

function guardarSoloCloser() {
  const id = getElement('editar_id').value;
  const usuarioCloser = getElement('usuario_closer').value;

  if (!id) {
    alert('Error: ID del cliente no encontrado');
    return;
  }

  // Mostrar indicador de carga
  const btnGuardarCloser = getElement('btnGuardarCloser');
  const originalText = btnGuardarCloser.innerHTML;
  btnGuardarCloser.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Guardando...';
  btnGuardarCloser.disabled = true;

  // Enviar solo el campo del closer
  const formData = new FormData();
  formData.append('id', id);
  formData.append('usuario_closer', usuarioCloser);

  fetch('actualizar_closer.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.text())
  .then(msg => {
    alert(msg);
    window.location.reload();
  })
  .catch(error => {
    console.error('Error:', error);
    alert('Error al actualizar el closer');
  })
  .finally(() => {
    // Restaurar botón
    btnGuardarCloser.innerHTML = originalText;
    btnGuardarCloser.disabled = false;
  });
}

// ====================== INICIALIZACIÓN ======================
function inicializar() {
  configurarBotonLimpiar();
  configurarBusqueda();
  configurarBotonAgregarCloser(); // Ahora disponible para todos
  cargarUsuarios(); // Closers para todos (y usuarios para admin)

  configurarFormulario();
  agregarEventosFila();
}

document.addEventListener('DOMContentLoaded', inicializar);
