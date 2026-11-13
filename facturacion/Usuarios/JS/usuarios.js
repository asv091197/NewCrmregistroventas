// usuarios.js - Gestión de usuarios con paginación y filtros
const adminAcceso = parseInt(document.body.getAttribute('data-admin-acceso'));
// Filtros aplicados (para la búsqueda y para recargar la página tras guardar)

// Variables para almacenar los valores originales
let valoresOriginales = {};
let estadoOriginalSalas = {
  acceso_todas: false,
  salas_seleccionadas: []
};

// Función para mostrar notificaciones toast
function showToast(title, message, type = 'info') {
  const toastEl = document.getElementById('liveToast');
  const toastTitle = document.getElementById('toast-title');
  const toastMessage = document.getElementById('toast-message');

  // Configurar colores según el tipo
  toastEl.classList.remove('bg-success', 'bg-danger', 'bg-info', 'bg-warning');
  switch (type) {
    case 'success':
      toastEl.classList.add('bg-success', 'text-white');
      break;
    case 'error':
      toastEl.classList.add('bg-danger', 'text-white');
      break;
    case 'warning':
      toastEl.classList.add('bg-warning', 'text-dark');
      break;
    default:
      toastEl.classList.add('bg-info', 'text-white');
  }

  toastTitle.textContent = title;
  toastMessage.textContent = message;

  const toast = new bootstrap.Toast(toastEl);
  toast.show();
}

// Función para mostrar/ocultar contraseña
function togglePasswordVisibility(inputId) {
  const passwordInput = document.getElementById(inputId);
  const toggleIcon = passwordInput.nextElementSibling.querySelector('i');

  if (passwordInput.type === 'password') {
    passwordInput.type = 'text';
    toggleIcon.classList.remove('bi-eye');
    toggleIcon.classList.add('bi-eye-slash');
  } else {
    passwordInput.type = 'password';
    toggleIcon.classList.remove('bi-eye-slash');
    toggleIcon.classList.add('bi-eye');
  }
}

// Función para mostrar/ocultar filtros
function toggleFilters() {
  const filterContent = document.getElementById('filterContent');
  filterContent.classList.toggle('show');
}

// Mostrar filtros si hay filtros activos
if (filtrosActivos || mostrarInactivos) {
  document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('filterContent').classList.add('show');
  });
}

// Función segura para obtener elementos del DOM
function getElement(id) {
  const element = document.getElementById(id);
  if (!element) {
    console.warn(`Elemento con ID ${id} no encontrado`);
  }
  return element;
}

// Función para verificar si un elemento existe
function elementExists(id) {
  return document.getElementById(id) !== null;
}

// Función para volver a usuarios.php manteniendo el filtro de inactivos
function volverAUsuarios() {
  let url = 'usuarios.php';
  if (mostrarInactivos) {
    url += '?mostrar_inactivos=1';
  }
  window.location.href = url;
}

// URL para recargar la página manteniendo inactivos y los filtros aplicados
function urlRecarga() {
  const params = new URLSearchParams();
  if (mostrarInactivos) params.append('mostrar_inactivos', '1');
  if (filtrosActivos) {
    Object.entries(filtrosAplicados).forEach(([k, v]) => params.append(k, v));
    params.append('filtrar', '1');
  }
  const query = params.toString();
  return 'usuarios.php' + (query ? '?' + query : '');
}

// Función para alternar entre mostrar activos/inactivos SIN enviar filtros
function toggleInactivos(checkbox) {
  // Crear nueva URL solo con el parámetro mostrar_inactivos
  const params = new URLSearchParams();
  params.append('mostrar_inactivos', checkbox.checked ? '1' : '0');

  // No incluir otros parámetros de filtro
  window.location.href = 'usuarios.php?' + params.toString();
}

// Configurar el botón de limpiar búsqueda
function configurarBotonLimpiar() {
  const buscarInput = getElement('buscar');
  const clearButton = getElement('clear-search');

  if (buscarInput && clearButton) {
    // Mostrar/ocultar botón de limpiar según si hay texto
    buscarInput.addEventListener('input', function() {
      clearButton.style.display = this.value ? 'block' : 'none';
    });

    // Limpiar búsqueda y volver a usuarios al hacer clic en la X
    clearButton.addEventListener('click', function() {
      buscarInput.value = '';
      clearButton.style.display = 'none';
      actualizarContadorUsuarios(totalUsuariosInicial, 'Total');
      volverAUsuarios();
    });

    // También volver a usuarios cuando se borra todo el texto manualmente
    buscarInput.addEventListener('keyup', function(e) {
      if (this.value === '' && e.key !== 'Tab' && e.key !== 'Shift') {
        actualizarContadorUsuarios(totalUsuariosInicial, 'Total');
        volverAUsuarios();
      }
    });
  }
}

// Función para actualizar el contador de usuarios
function actualizarContadorUsuarios(cantidad, texto = 'Resultados') {
  const contador = getElement('contador-usuarios');
  if (contador) {
    if (mostrarInactivos) {
      contador.textContent = `Inactivos: ${cantidad}`;
      contador.classList.remove('bg-light', 'text-dark');
      contador.classList.add('bg-warning', 'text-dark');
    } else {
      contador.textContent = `Activos: ${cantidad}`;
      contador.classList.remove('bg-warning', 'text-dark');
      contador.classList.add('bg-light', 'text-dark');
    }

    // Cambiar color temporalmente para indicar actualización
    if (texto === 'Resultados') {
      contador.classList.add('bg-info', 'text-white');

      setTimeout(() => {
        contador.classList.remove('bg-info', 'text-white');
        if (mostrarInactivos) {
          contador.classList.add('bg-warning', 'text-dark');
        } else {
          contador.classList.add('bg-light', 'text-dark');
        }
      }, 1000);
    }
  }
}

function agregarEventosFila() {
  document.querySelectorAll('#tabla-usuarios tr[data-id]').forEach(fila => {
    // Verificar si el usuario tiene acceso a todas las salas y el admin es limitado
    const salasCell = fila.querySelector('td:nth-child(4)');
    const tieneTodasSalas = salasCell && salasCell.textContent.includes('Todas las salas');

    // Evento para hacer clic en la fila (excepto en botones)
    fila.querySelectorAll('td:not(:last-child)').forEach(td => {
      td.style.cursor = 'pointer';
      td.addEventListener('click', function(e) {
        if (e.target.closest('button')) return;

        // Si el admin es limitado y el usuario tiene todas las salas, no permitir edición
        if (adminAcceso === 0 && tieneTodasSalas) {
          showToast('Permiso denegado', 'No tienes permiso para editar usuarios con acceso a todas las salas', 'warning');
          return;
        }

        const id = fila.getAttribute('data-id');
        if (id) abrirModalEditar(id);
      });
    });

    // Evento para botones de cambiar estado
    const btnEstado = fila.querySelector('.btn-cambiar-estado');
    if (btnEstado) {
      btnEstado.addEventListener('click', function(e) {
        e.stopPropagation(); // Prevenir que se active el evento de la fila

        // Si el admin es limitado y el usuario tiene todas las salas, no permitir cambio de estado
        if (adminAcceso === 0 && tieneTodasSalas) {
          showToast('Permiso denegado', 'No tienes permiso para cambiar el estado de usuarios con acceso a todas las salas', 'warning');
          return;
        }

        const id = this.getAttribute('data-id');
        const nombre = this.getAttribute('data-nombre');
        const accion = this.getAttribute('data-accion');
        confirmarCambioEstado(id, nombre, accion);
      });
    }
  });
}

// Configurar evento de búsqueda
function configurarBusqueda() {
  const buscarInput = getElement('buscar');
  if (buscarInput) {
    buscarInput.addEventListener('input', function() {
      const query = this.value.trim();
      if (query.length >= 1) {
        // Agregar filtros de fecha y sala a la búsqueda si existen
        const params = new URLSearchParams(filtrosAplicados);
        params.set('buscar', query);
        if (mostrarInactivos) params.append('mostrar_inactivos', '1');

        fetch(`buscar_usuario.php?${params.toString()}`)
          .then(response => response.text())
          .then(data => {
            const tablaUsuarios = getElement('tabla-usuarios');
            const paginacion = document.querySelector('.pagination');
            if (!tablaUsuarios) return;

            tablaUsuarios.innerHTML = data;

            // Extraer el contador de resultados
            const contadorDiv = tablaUsuarios.querySelector('#resultado-contador');
            if (contadorDiv) {
              actualizarContadorUsuarios(contadorDiv.getAttribute('data-total'), 'Resultados');
              // Eliminar el div contador para no afectar la visualización
              contadorDiv.remove();
            }

            if (paginacion) paginacion.style.display = 'none';
            agregarEventosFila();
          })
          .catch(error => {
            console.error('Error en la búsqueda:', error);
          });
      } else {
        // Si la búsqueda está vacía, volver a usuarios.php
        actualizarContadorUsuarios(totalUsuariosInicial, 'Total');
        volverAUsuarios();
      }
    });
  }
}

// Configurar eventos para salas en el modal de edición (una sola vez, al iniciar)
function configurarSalasModal() {
  const todasSalas = getElement('editar_todas_salas');
  const salasEspecificas = getElement('editar_salas_especificas');
  const selectorSalas = getElement('editar_selector_salas');
  const radioSalas = document.querySelectorAll('#editar_selector_salas input[type="radio"]');

  // Validar cuando cambien los radio buttons (admin total y limitado)
  radioSalas.forEach(radio => {
    radio.addEventListener('change', validarCamposEditar);
  });

  // Alternar selector solo si el admin tiene acceso total
  if (adminAcceso === 1 && todasSalas && salasEspecificas) {
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
      validarCamposEditar();
    }

    todasSalas.addEventListener('change', toggleSalasSelector);
    salasEspecificas.addEventListener('change', toggleSalasSelector);
  }
}

// Configurar validación de los campos del modal (una sola vez, al iniciar)
function configurarCamposModal() {
  // Solo letras y espacios en nombre/apellidos
  ['editar_nombre', 'editar_apepat', 'editar_apemat'].forEach(id => {
    const input = getElement(id);
    if (input) {
      input.addEventListener('input', function() {
        this.value = this.value.replace(/[^A-Za-zÁÉÍÓÚáéíóúÑñ\s]/g, '');
      });
    }
  });

  // Revalidar el formulario al cambiar cualquier campo (incluida la contraseña)
  ['editar_nombre', 'editar_apepat', 'editar_apemat', 'editar_correo', 'editar_rol', 'editar_password'].forEach(id => {
    const input = getElement(id);
    if (input) input.addEventListener('input', validarCamposEditar);
  });

  // Feedback visual de la contraseña al salir del campo
  const passwordInput = getElement('editar_password');
  if (passwordInput) {
    passwordInput.addEventListener('blur', function() {
      this.classList.toggle('is-invalid', this.value.length > 0 && this.value.length < 6);
    });
  }
}

// FUNCIÓN PRINCIPAL DE VALIDACIÓN - MODIFICADA PARA INCLUIR CONTRASEÑA
function validarCamposEditar() {
  // Solo si el modal existe
  if (!elementExists('formEditar')) return;

  // Campos obligatorios (deben tener al menos un carácter)
  const camposObligatorios = [
    'editar_nombre',
    'editar_apepat',
    'editar_correo',
    'editar_rol'
  ];

  // Verificar que todos los campos obligatorios tengan al menos un carácter
  const todosLlenos = camposObligatorios.every(id => {
    const campo = getElement(id);
    const valor = campo ? campo.value.trim() : '';
    return valor.length > 0;
  });

  // Validar contraseña (opcional pero debe tener 6+ caracteres si se ingresa)
  const passwordInput = getElement('editar_password');
  let passwordValida = true;

  if (passwordInput && passwordInput.value.length > 0) {
    passwordValida = passwordInput.value.length >= 6;
    // Opcional: mostrar feedback visual
    if (!passwordValida) {
      passwordInput.classList.add('is-invalid');
    } else {
      passwordInput.classList.remove('is-invalid');
    }
  } else {
    // Si está vacío, es válido (opcional)
    if (passwordInput) {
      passwordInput.classList.remove('is-invalid');
    }
  }

  // Verificar salas según tipo de admin
  let salasValidas = false;
  let estadoActualSalas = {
    acceso_todas: false,
    salas_seleccionadas: []
  };

  if (adminAcceso === 1) {
    // Admin total: verificar radio buttons
    const todasSalas = getElement('editar_todas_salas');
    const salasEspecificas = getElement('editar_salas_especificas');

    if (todasSalas && salasEspecificas) {
      if (todasSalas.checked) {
        salasValidas = true;
        estadoActualSalas.acceso_todas = true;
      } else if (salasEspecificas.checked) {
        const radioSeleccionado = document.querySelector('#editar_selector_salas input[type="radio"]:checked');
        salasValidas = radioSeleccionado !== null;
        estadoActualSalas.acceso_todas = false;
        estadoActualSalas.salas_seleccionadas = radioSeleccionado ? [radioSeleccionado.value] : [];
      }
    }
  } else {
    // Admin limitado: siempre salas específicas, verificar radio button seleccionado
    const radioSeleccionado = document.querySelector('#editar_selector_salas input[type="radio"]:checked');
    salasValidas = radioSeleccionado !== null;
    estadoActualSalas.acceso_todas = false;
    estadoActualSalas.salas_seleccionadas = radioSeleccionado ? [radioSeleccionado.value] : [];
  }

  // Verificar si hay cambios en los campos (comparar con valores originales)
  // Excluimos 'editar_password' de esta validación porque es un caso especial
  const camposParaValidarCambios = Object.keys(valoresOriginales).filter(key => key !== 'editar_password');

  const hayCambiosEnCampos = camposParaValidarCambios.some(key => {
    const campo = getElement(key);
    return campo && campo.value !== valoresOriginales[key];
  });

  // Verificar si hay cambios en la contraseña (si se ingresó algo)
  const hayCambiosEnPassword = passwordInput && passwordInput.value.length > 0;

  // Verificar si hay cambios en las salas
  const hayCambiosEnSalas =
    estadoActualSalas.acceso_todas !== estadoOriginalSalas.acceso_todas ||
    JSON.stringify(estadoActualSalas.salas_seleccionadas.sort()) !==
    JSON.stringify(estadoOriginalSalas.salas_seleccionadas.sort());

  const hayCambiosTotales = hayCambiosEnCampos || hayCambiosEnSalas || hayCambiosEnPassword;

  // Habilitar el botón solo si:
  // 1. Todos los campos obligatorios están llenos
  // 2. Hay cambios (en campos obligatorios, salas O contraseña)
  // 3. Las salas son válidas
  // 4. La contraseña es válida (si se ingresó)
  const submitButton = document.querySelector('#formEditar button[type="submit"]');
  if (submitButton) {
    submitButton.disabled = !(todosLlenos && hayCambiosTotales && salasValidas && passwordValida);
  }
}

function abrirModalEditar(id) {
  fetch(`obtener_usuario.php?id=${id}`)
    .then(res => {
      if (!res.ok) throw new Error('Error al cargar usuario');
      return res.json();
    })
    .then(data => {
      if (data.error) {
        throw new Error(data.error);
      }

      // Verificar permisos: si el admin es limitado y el usuario tiene acceso a todas las salas, no permitir edición
      if (adminAcceso === 0 && data.acceso_todas_salas == 1) {
        showToast('Permiso denegado', 'No tienes permiso para editar usuarios con acceso a todas las salas', 'warning');
        return;
      }

      // Almacenar valores originales
      valoresOriginales = {
        'editar_nombre': data.nombre,
        'editar_apepat': data.apepat || '',
        'editar_apemat': data.apemat || '',
        'editar_correo': data.correo,
        'editar_rol': data.rol
      };

      // Almacenar estado original de salas
      let salasPermitidas = [];
      if (data.salas_permitidas) {
        try {
          salasPermitidas = JSON.parse(data.salas_permitidas);
          if (!Array.isArray(salasPermitidas)) {
            salasPermitidas = [salasPermitidas];
          }
        } catch (e) {
          console.error('Error al parsear salas_permitidas:', e);
          salasPermitidas = [];
        }
      }

      // Como texto, igual que el value de los radio buttons
      salasPermitidas = salasPermitidas.map(String);

      estadoOriginalSalas = {
        acceso_todas: data.acceso_todas_salas == 1,
        salas_seleccionadas: salasPermitidas
      };

      // Establecer valores en los campos
      const campos = {
        'editar_id': id,
        'editar_nombre': data.nombre,
        'editar_apepat': data.apepat || '',
        'editar_apemat': data.apemat || '',
        'editar_correo': data.correo,
        'editar_rol': data.rol,
        'editar_password': ''
      };

      Object.keys(campos).forEach(key => {
        const element = getElement(key);
        if (element) element.value = campos[key];
      });

      getElement('editar_password').classList.remove('is-invalid');

      // Configurar salas según tipo de admin
      const selectorSalas = getElement('editar_selector_salas');
      // Limpiar la selección del usuario editado anteriormente
      document.querySelectorAll('#editar_selector_salas input[type="radio"]').forEach(radio => {
        radio.checked = false;
      });

      if (adminAcceso === 1 && data.acceso_todas_salas == 1) {
        // Admin total: usuario con todas las salas
        getElement('editar_todas_salas').checked = true;
        selectorSalas.style.display = 'none';
      } else {
        // Admin total con salas específicas, o admin limitado (solo sus salas)
        if (adminAcceso === 1) getElement('editar_salas_especificas').checked = true;
        selectorSalas.style.display = 'block';

        // Marcar la sala permitida si existe
        if (salasPermitidas.length > 0) {
          const radio = document.getElementById(`editar_sala_${salasPermitidas[0]}`);
          if (radio) radio.checked = true;
        }
      }

      // Deshabilitar el botón inicialmente
      const submitButton = document.querySelector('#formEditar button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
      }

      const modal = bootstrap.Modal.getOrCreateInstance(getElement('modalEditar'));
      modal.show();

      // Enfocar el primer campo cuando el modal esté completamente visible
      getElement('modalEditar').addEventListener('shown.bs.modal', function() {
        const primerCampo = document.querySelector('#modalEditar input:not([disabled])');
        if (primerCampo) {
          primerCampo.focus();
        }
      }, {
        once: true
      });
    })
    .catch(error => {
      console.error('Error al cargar datos del usuario:', error);
      showToast('Error', 'Error al cargar los datos del usuario', 'error');
    });
}

// Función para confirmar cambio de estado
function confirmarCambioEstado(id, nombre, accion) {
  const modal = bootstrap.Modal.getOrCreateInstance(getElement('modalConfirmarEstado'));
  const nombreElement = getElement('nombre-usuario-estado');
  const accionElement = getElement('accion-texto');
  const btnConfirmar = getElement('btn-confirmar-estado');

  if (nombreElement) nombreElement.textContent = nombre;
  if (accionElement) accionElement.textContent = accion === 'activar' ? 'activar' : 'desactivar';

  // Configurar el botón según la acción
  if (btnConfirmar) {
    btnConfirmar.disabled = false; // Puede quedar deshabilitado de un cambio anterior
    if (accion === 'activar') {
      btnConfirmar.classList.remove('btn-warning');
      btnConfirmar.classList.add('btn-success');
      btnConfirmar.innerHTML = '<i class="bi bi-check-circle"></i> Activar';
    } else {
      btnConfirmar.classList.remove('btn-success');
      btnConfirmar.classList.add('btn-warning');
      btnConfirmar.innerHTML = '<i class="bi bi-check-circle"></i> Desactivar';
    }
  }

  // Configurar evento para el botón de confirmar
  if (btnConfirmar) {
    const newBtnConfirmar = btnConfirmar.cloneNode(true);
    btnConfirmar.parentNode.replaceChild(newBtnConfirmar, btnConfirmar);

    newBtnConfirmar.addEventListener('click', function() {
      cambiarEstadoUsuario(id, accion);
    });
  }

  modal.show();
}

// Función para cambiar estado del usuario
function cambiarEstadoUsuario(id, accion) {
  const btnConfirmar = getElement('btn-confirmar-estado');
  if (btnConfirmar) {
    btnConfirmar.disabled = true;
    btnConfirmar.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Procesando...';
  }

  const formData = new FormData();
  formData.append('id', id);
  formData.append('accion', accion);

  fetch(`cambiar_estado_usuario.php`, {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      const modal = bootstrap.Modal.getInstance(getElement('modalConfirmarEstado'));
      if (modal) modal.hide();

      if (data.success) {
        showToast('Éxito', data.message, 'success');

        const buscarInput = getElement('buscar');
        if (buscarInput.value.trim() !== '') {
          buscarInput.dispatchEvent(new Event('input'));
        } else {
          window.location.href = urlRecarga();
        }
      } else {
        showToast('Error', data.message || 'No se pudo cambiar el estado del usuario', 'error');
      }
    })
    .catch(error => {
      console.error('Error al cambiar estado:', error);
      showToast('Error', 'Error al cambiar el estado del usuario', 'error');

      if (btnConfirmar) {
        btnConfirmar.disabled = false;
        btnConfirmar.innerHTML = '<i class="bi bi-check-circle"></i> Confirmar';
      }
    });
}

// Configurar envío del formulario
function configurarFormulario() {
  if (!elementExists('formEditar')) return;

  const formEditar = getElement('formEditar');
  if (formEditar) {
    formEditar.addEventListener('submit', function(e) {
      e.preventDefault();

      // Validación adicional antes de enviar
      const passwordInput = getElement('editar_password');
      if (passwordInput && passwordInput.value.length > 0 && passwordInput.value.length < 6) {
        showToast('Error', 'La contraseña debe tener al menos 6 caracteres', 'error');
        passwordInput.focus();
        return;
      }

      const submitButton = this.querySelector('button[type="submit"]');
      const originalText = submitButton.innerHTML;
      submitButton.disabled = true;
      submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Guardando...';

      const formData = new FormData(this);

      fetch('actualizar_usuario.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          submitButton.disabled = false;
          submitButton.innerHTML = originalText;

          if (data.success) {
            showToast('Éxito', data.message, 'success');
            const modal = bootstrap.Modal.getInstance(getElement('modalEditar'));
            if (modal) modal.hide();

            window.location.href = urlRecarga();
          } else {
            showToast('Error', data.message, 'error');
          }
        })
        .catch(error => {
          console.error('Error al actualizar:', error);
          submitButton.disabled = false;
          submitButton.innerHTML = originalText;
          showToast('Error', 'Error al actualizar el usuario', 'error');
        });
    });
  }
}

// Gestionar el foco cuando se cierra el modal
function configurarCierreModal() {
  if (!elementExists('modalEditar')) return;

  const modalEditar = getElement('modalEditar');
  if (modalEditar) {
    modalEditar.addEventListener('hidden.bs.modal', function() {
      valoresOriginales = {};
      estadoOriginalSalas = {
        acceso_todas: false,
        salas_seleccionadas: []
      };

      setTimeout(() => {
        const buscarInput = getElement('buscar');
        if (buscarInput) buscarInput.focus();
      }, 100);
    });
  }
}

// Inicializar la aplicación
function inicializar() {
  configurarBotonLimpiar();
  configurarBusqueda();

  if (elementExists('modalEditar')) {
    configurarFormulario();
    configurarCierreModal();
    configurarCamposModal();
    configurarSalasModal();
  }

  agregarEventosFila();
}

// Iniciar cuando el DOM esté completamente cargado
document.addEventListener('DOMContentLoaded', inicializar);
