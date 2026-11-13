// ============================================================
// VARIABLES GLOBALES
// ============================================================
const rolUsuario = document.body.getAttribute('data-rol');
const esAdmin = rolUsuario === 'admin' || rolUsuario === 'superadmin';
const acceso_todas_salas = parseInt(document.body.getAttribute('data-acceso-todas-salas') || '1');
const salas_permitidas = JSON.parse(document.body.getAttribute('data-salas-permitidas') || '[]');

const totalColumnas = 8;   // Todos los roles ven la columna Pagado
let usuariosCargados = [];
let closersCargados = [];
let clienteActual = null;          // Cliente abierto en el modal
let timeoutBusqueda = null;

// ============================================================
// SOLUCIÓN RACE CONDITION: AbortController + Token de secuencia
// ============================================================
let abortController = null;        // Para cancelar fetch en vuelo
let secuenciaBusqueda = 0;         // Token para descartar respuestas obsoletas

// ====================== FUNCIONES UTILITARIAS ======================
function getElement(id) {
  const el = document.getElementById(id);
  if (!el) console.warn(`Elemento con ID ${id} no encontrado`);
  return el;
}

// Escapar texto antes de insertarlo con innerHTML
function escapeHtml(texto) {
  const div = document.createElement('div');
  div.textContent = texto;
  return div.innerHTML;
}

function toggleFilters() {
  const filterContent = getElement('filterContent');
  if (filterContent) {
    filterContent.classList.toggle('show');
  }
}

function configurarBotonLimpiar() {
  const buscarInput = getElement('buscar');
  const clearButton = getElement('clear-search');
  if (buscarInput && clearButton) {
    // La visibilidad del botón al escribir se controla en configurarBusqueda()
    clearButton.addEventListener('click', function () {
      buscarInput.value = '';
      clearButton.style.display = 'none';
      actualizarContadorClientes(totalClientesInicial, 'Total');
      restaurarVistaInicial();
    });
  }
}

function actualizarContadorClientes(cantidad, texto = 'Resultados') {
  const contador = getElement('contador-clientes');
  if (contador) contador.textContent = `${texto}: ${cantidad}`;
}

// ============================================================
// BÚSQUEDA EN TIEMPO REAL - CON CANCELACIÓN Y CONTROL DE RACE CONDITION
// ============================================================
function configurarBusqueda() {
  const buscarInput = getElement('buscar');
  const clearButton = getElement('clear-search');
  const tablaContainer = getElement('tabla-container');
  const mensaje = getElement('mensaje');
  const tablaClientes = getElement('tabla-clientes');

  if (!buscarInput || !tablaContainer || !mensaje || !tablaClientes) return;

  buscarInput.addEventListener('input', function() {
      const termino = this.value.trim();

      if (clearButton) clearButton.style.display = termino ? 'block' : 'none';

      // Cancelar timeout pendiente
      if (timeoutBusqueda) {
          clearTimeout(timeoutBusqueda);
      }

      // Si está vacío, cancelar cualquier búsqueda en vuelo y restaurar
      if (termino === '') {
          if (abortController) {
              abortController.abort();
              abortController = null;
          }
          restaurarVistaInicial();
          return;
      }

      // Debounce más alto para evitar disparar en cada tecla
      // 1 caracter: 500ms | 2+ caracteres: 350ms
      const delay = termino.length === 1 ? 500 : 350;

      timeoutBusqueda = setTimeout(() => {
          realizarBusqueda(termino);
      }, delay);
  });

  // Enter dispara búsqueda inmediata
  buscarInput.addEventListener('keypress', function(e) {
      if (e.key === 'Enter') {
          const termino = this.value.trim();
          if (termino) {
              if (timeoutBusqueda) {
                  clearTimeout(timeoutBusqueda);
                  timeoutBusqueda = null;
              }
              realizarBusqueda(termino);
          }
      }
  });

  function realizarBusqueda(termino) {
      // Cancelar la petición anterior si sigue en vuelo
      if (abortController) {
          abortController.abort();
      }
      abortController = new AbortController();
      const signalLocal = abortController.signal;

      // Token de secuencia para descartar respuestas viejas
      const miSecuencia = ++secuenciaBusqueda;

      mensaje.innerHTML = '<div class="text-center"><span class="spinner-border spinner-border-sm" role="status"></span> Buscando clientes...</div>';
      tablaContainer.classList.add('loading');
      tablaClientes.innerHTML = `<tr><td colspan="${totalColumnas}" class="text-center">Buscando...</td></tr>`;

      const params = new URLSearchParams();
      params.append('buscar', termino);

      const salaId = document.getElementById('sala_id')?.value || '';
      const fechaInicio = document.getElementById('fecha_inicio')?.value || '';
      const fechaFin = document.getElementById('fecha_fin')?.value || '';

      if (salaId) params.append('sala_id', salaId);
      if (fechaInicio) params.append('fecha_inicio', fechaInicio);
      if (fechaFin) params.append('fecha_fin', fechaFin);

      const pagado = document.getElementById('pagado')?.value || '';
      if (pagado !== '') params.append('pagado', pagado);

      if (esAdmin) {
        params.append('acceso_todas_salas', acceso_todas_salas);
        if (acceso_todas_salas === 0 && salas_permitidas.length > 0) {
          params.append('salas_permitidas', JSON.stringify(salas_permitidas));
        }
      }

      params.append('t', new Date().getTime());

      fetch(`buscar_clientes.php?${params.toString()}`, {
          signal: signalLocal  // ← Cancelación automática
      })
      .then(response => {
          if (!response.ok) {
              throw new Error('Error en la respuesta del servidor');
          }
          return response.text();
      })
      .then(data => {
          // Si ya se hizo otra búsqueda, ignorar esta respuesta
          if (miSecuencia !== secuenciaBusqueda) {
              return;
          }

          const regexContador = /<div id="resultado-contador" data-total="(\d+)"><\/div>/;
          const regexLimite = /<div id="resultado-limite" data-limite="(\d+)" data-truncado="(\d+)"><\/div>/;
          const contadorMatch = data.match(regexContador);
          const limiteMatch = data.match(regexLimite);
          const totalResultados = contadorMatch ? parseInt(contadorMatch[1]) : 0;
          const truncado = limiteMatch ? parseInt(limiteMatch[2]) === 1 : false;
          const limite = limiteMatch ? parseInt(limiteMatch[1]) : 0;

          tablaClientes.innerHTML = data.replace(regexContador, '').replace(regexLimite, '');
          actualizarContadorClientes(totalResultados, 'Resultados');

          const terminoHtml = escapeHtml(termino);
          if (totalResultados > 0) {
              const mensajeExtra = truncado
                  ? ` <span class="text-warning">(Mostrando solo los primeros ${limite}. Refina tu búsqueda.)</span>`
                  : '';
              mensaje.innerHTML = `<div class="text-success">✓ Se encontraron ${totalResultados} resultados para "${terminoHtml}"${mensajeExtra}</div>`;
          } else {
              mensaje.innerHTML = `<div class="text-warning">No se encontraron resultados para "${terminoHtml}"</div>`;
          }

          agregarEventosFila();
      })
      .catch(error => {
          // Ignorar errores por cancelación
          if (error.name === 'AbortError') {
              return;
          }
          console.error('Error en la búsqueda:', error);

          // Solo mostrar error si sigue siendo la búsqueda actual
          if (miSecuencia === secuenciaBusqueda) {
              mensaje.innerHTML = '<div class="text-danger">✗ Error al realizar la búsqueda</div>';
              tablaClientes.innerHTML = `<tr><td colspan="${totalColumnas}" class="text-center text-danger">Error al cargar los resultados</td></tr>`;
          }
      })
      .finally(() => {
          // Solo quitar el loading si seguimos siendo la búsqueda actual
          if (miSecuencia === secuenciaBusqueda) {
              tablaContainer.classList.remove('loading');
          }
      });
  }
}

function restaurarVistaInicial() {
  const mensaje = getElement('mensaje');
  const tablaContainer = getElement('tabla-container');

  if (mensaje) {
      mensaje.innerHTML = '<div class="text-info">↻ Restaurando vista inicial...</div>';
  }

  if (tablaContainer) {
      tablaContainer.classList.add('loading');
  }

  setTimeout(() => {
      window.location.href = 'agencia.php';
  }, 100);
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

// ====================== USUARIOS Y CLOSERS ======================
// tipo: 'usuarios' | 'closers'
function cargarLista(tipo, salaId = null) {
  const params = new URLSearchParams();
  if (salaId) params.append('sala_id', salaId);
  params.append('tipo', tipo);

  if (esAdmin && acceso_todas_salas === 0) {
      params.append('acceso_todas_salas', acceso_todas_salas);
      if (salas_permitidas.length > 0) {
          params.append('salas_permitidas', JSON.stringify(salas_permitidas));
      }
  }

  return fetch(`obtener_usuarios.php?${params.toString()}`)
    .then(res => {
      if (!res.ok) throw new Error(`Error HTTP: ${res.status}`);
      return res.json();
    })
    .then(data => {
      if (data.success && Array.isArray(data.usuarios)) {
        return data.usuarios;
      }
      throw new Error(data.message || 'Error en la respuesta');
    })
    .catch(error => {
      console.error(`Error cargando ${tipo}:`, error);
      return [];
    });
}

function cargarUsuarios(salaId = null) {
  return cargarLista('usuarios', salaId).then(lista => (usuariosCargados = lista));
}

function cargarClosers(salaId = null) {
  return cargarLista('closers', salaId).then(lista => (closersCargados = lista));
}

function llenarSelect(selectId, lista, placeholder, textoVacio, prefijo, seleccionadoId = '') {
  const select = getElement(selectId);
  if (!select) return;

  select.innerHTML = `<option value="">${placeholder}</option>`;

  if (!Array.isArray(lista) || lista.length === 0) {
    select.innerHTML += `<option value="" disabled>${textoVacio}</option>`;
    return;
  }

  lista.forEach(u => {
    const option = document.createElement('option');
    option.value = u.id;
    option.textContent = u.apemat || `${prefijo} ${u.id}`;
    option.selected = (u.id == seleccionadoId);
    select.appendChild(option);
  });
}

function llenarSelectUsuarios(usuarioSeleccionadoId = '') {
  llenarSelect('usuarios_id', usuariosCargados, '-- Selecciona un usuario --',
    'No hay usuarios disponibles para esta sala', 'Usuario', usuarioSeleccionadoId);
}

function llenarSelectClosers(closerSeleccionadoId = '') {
  llenarSelect('usuario_closer', closersCargados, '-- Seleccione un closer --',
    'No hay closers disponibles para esta sala', 'Closer', closerSeleccionadoId);
}

// ====================== CONFIGURACIÓN DE SALA ======================
function configurarSelectSala() {
  const btnSeleccionarSala = document.getElementById('btnSeleccionarSala');
  const salaAdminSection = document.getElementById('salaAdminSection');
  const salaSelectAdmin = document.getElementById('salaSelectAdmin');

  if (btnSeleccionarSala && salaAdminSection) {
    btnSeleccionarSala.addEventListener('click', () => {
      const visible = salaAdminSection.classList.toggle('show');
      btnSeleccionarSala.innerHTML = visible
        ? '<i class="bi bi-building-slash"></i> Ocultar Sala'
        : '<i class="bi bi-building"></i> Asignar Sala';
    });
  }

  if (salaSelectAdmin) {
    salaSelectAdmin.addEventListener('change', function() {
      const nuevaSalaId = this.value;

      if (esAdmin && acceso_todas_salas === 0 && nuevaSalaId && !salas_permitidas.includes(parseInt(nuevaSalaId))) {
          alert('No tienes permisos para asignar esta sala');
          this.value = '';
          return;
      }

      getElement('editar_sala_id').value = nuevaSalaId || '';
      getElement('mensajePermisos').style.display = 'none';

      // Recargar listas para la nueva sala conservando la selección del cliente abierto
      if (esAdmin) {
        getElement('usuarios_id').innerHTML = '<option value="">Cargando usuarios...</option>';
        cargarUsuarios(nuevaSalaId).then(() => llenarSelectUsuarios(clienteActual?.usuario_id));
      }

      getElement('usuario_closer').innerHTML = '<option value="">Cargando closers...</option>';
      cargarClosers(nuevaSalaId).then(() => llenarSelectClosers(clienteActual?.usuario_closer));
    });
  }
}

// ====================== EDITAR CLIENTE ======================
function abrirModalEditar(id) {
  const selectUsuario = document.getElementById('usuarios_id');
  const selectCloser = getElement('usuario_closer');
  if (selectUsuario) selectUsuario.innerHTML = '<option value="">-- Selecciona un usuario --</option>';
  if (selectCloser) selectCloser.innerHTML = '<option value="">-- Seleccione un closer --</option>';

  const closerSection = getElement('closerSection');
  const salaAdminSection = document.getElementById('salaAdminSection');
  if (closerSection) closerSection.style.display = 'none';
  if (salaAdminSection) salaAdminSection.classList.remove('show');

  const btnCloser = getElement('btnAgregarCloser');
  const btnSala = document.getElementById('btnSeleccionarSala');
  if (btnCloser) btnCloser.innerHTML = '<i class="bi bi-plus-circle"></i> Agregar/Modificar Closer';
  if (btnSala) btnSala.innerHTML = '<i class="bi bi-building"></i> Asignar Sala';

  const mensajePermisos = getElement('mensajePermisos');
  if (mensajePermisos) mensajePermisos.style.display = 'none';

  fetch(`obtener_cliente.php?id=${encodeURIComponent(id)}`)
    .then(response => response.json())
    .then(data => {
      if (!data.success) {
        throw new Error(data.message || 'Error al cargar cliente');
      }

      const cliente = data.data;
      clienteActual = cliente;

      getElement('editar_id').value = cliente.id;
      getElement('editar_nombre').value = cliente.nombre || '';
      getElement('editar_apepat').value = cliente.apepat || '';
      getElement('editar_apemat').value = cliente.apemat || '';
      getElement('editar_numero1').value = cliente.numero1 || '';
      getElement('editar_numero2').value = cliente.numero2 || '';
      getElement('editar_asunto').value = cliente.asunto || '';
      getElement('editar_descripcion').value = cliente.descripcion || '';

      const selectPagado = document.getElementById('editar_pagado');
      if (selectPagado) selectPagado.value = cliente.pagado == 1 ? '1' : '0';

      // Siempre reasignar (también a vacío) para no arrastrar la sala del cliente abierto antes
      getElement('editar_sala_id').value = cliente.sala_id || '';
      const salaSelectAdmin = document.getElementById('salaSelectAdmin');
      if (salaSelectAdmin) salaSelectAdmin.value = cliente.sala_id || '';

      const salaIdParaCargar = cliente.sala_id || null;

      Promise.all([
        esAdmin ? cargarUsuarios(salaIdParaCargar) : Promise.resolve([]),
        cargarClosers(salaIdParaCargar)
      ])
        .then(() => {
          if (esAdmin) {
            llenarSelectUsuarios(cliente.usuario_id);
          }
          llenarSelectClosers(cliente.usuario_closer);

          if (cliente.usuario_closer && closerSection && btnCloser) {
            closerSection.style.display = 'block';
            btnCloser.innerHTML = '<i class="bi bi-dash-circle"></i> Ocultar Closer';
          }
        })
        .catch(error => console.error('Error cargando datos:', error))
        .finally(() => bootstrap.Modal.getOrCreateInstance(getElement('modalEditar')).show());
    })
    .catch(error => {
      console.error('Error obteniendo cliente:', error);
      alert('Error al cargar los datos del cliente: ' + error.message);
    });
}

// ====================== FORMULARIO ======================
function configurarFormulario() {
  const form = getElement('formEditar');
  if (!form) return;

  form.addEventListener('submit', e => {
    e.preventDefault();
    if (esAdmin) guardarCambios();
  });

  if (!esAdmin) {
    const btnGuardarCloser = getElement('btnGuardarCloser');
    if (btnGuardarCloser) {
      btnGuardarCloser.addEventListener('click', e => {
        e.preventDefault();
        guardarSoloCloser();
      });
    }
  }
}

// Envía el formulario, muestra la respuesta y recarga la página
function enviarYRecargar(url, formData, boton, mensajeError) {
  const originalText = boton.innerHTML;
  boton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Guardando...';
  boton.disabled = true;

  fetch(url, {
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
    alert(mensajeError);
  })
  .finally(() => {
    boton.innerHTML = originalText;
    boton.disabled = false;
  });
}

function guardarCambios() {
  const id = getElement('editar_id').value;
  if (!id) {
    alert('Error: ID del cliente no encontrado');
    return;
  }

  if (acceso_todas_salas === 0) {
      const salaId = getElement('salaSelectAdmin').value;
      if (salaId && !salas_permitidas.includes(parseInt(salaId))) {
          alert('No tienes permisos para asignar esta sala');
          return;
      }
  }

  const form = getElement('formEditar');
  enviarYRecargar('actualizar_cliente.php', new FormData(form),
    form.querySelector('button[type="submit"]'), 'Error al actualizar el cliente');
}

function guardarSoloCloser() {
  const id = getElement('editar_id').value;
  if (!id) {
    alert('Error: ID del cliente no encontrado');
    return;
  }

  const formData = new FormData();
  formData.append('id', id);
  formData.append('usuario_closer', getElement('usuario_closer').value);

  enviarYRecargar('actualizar_closer.php', formData, getElement('btnGuardarCloser'), 'Error al actualizar el closer');
}

// ====================== CONFIGURACIÓN DE CLOSER ======================
function configurarBotonCloser() {
  const btnAgregarCloser = getElement('btnAgregarCloser');
  const closerSection = getElement('closerSection');

  if (btnAgregarCloser && closerSection) {
    btnAgregarCloser.addEventListener('click', () => {
      const isVisible = closerSection.style.display === 'block';
      closerSection.style.display = isVisible ? 'none' : 'block';

      btnAgregarCloser.innerHTML = isVisible ?
        '<i class="bi bi-plus-circle"></i> Agregar/Modificar Closer' :
        '<i class="bi bi-dash-circle"></i> Ocultar Closer';

      if (!isVisible && closersCargados.length === 0) {
        const salaSelectAdmin = document.getElementById('salaSelectAdmin');
        const salaId = salaSelectAdmin ? salaSelectAdmin.value : null;

        cargarClosers(salaId).then(() => llenarSelectClosers());
      }
    });
  }
}

// ====================== INICIALIZACIÓN ======================
function inicializar() {
  configurarBotonLimpiar();
  configurarBusqueda();
  configurarSelectSala();
  configurarBotonCloser();
  configurarFormulario();
  agregarEventosFila();
}

document.addEventListener('DOMContentLoaded', inicializar);
