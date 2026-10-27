// clientes.js - Gestión de clientes
const rolUsuario = document.body.getAttribute('data-rol');

// Variables para almacenar los valores originales
let valoresOriginales = {};
let usuariosCargados = []; // Para almacenar todos los usuarios

// Función segura para obtener elementos del DOM
function getElement(id) {
  const element = document.getElementById(id);
  if (!element) {
    console.warn(`Elemento con ID ${id} no encontrado`);
  }
  return element;
}

function agregarEventosFila() {
  document.querySelectorAll('#tabla-clientes tr').forEach(fila => {
    fila.addEventListener('click', function(e) {
      if (e.target.closest('button')) return;
      const id = this.getAttribute('data-id');
      if (id) abrirModalEditar(id);
    });
  });
}

// Cargar usuarios al iniciar la página
function cargarUsuarios() {
  return fetch('obtener_usuarios.php')
    .then(res => {
      if (!res.ok) throw new Error('Error al cargar usuarios');
      return res.json();
    })
    .then(usuarios => {
      usuariosCargados = usuarios;
      console.log('Usuarios cargados:', usuarios);
      return usuarios;
    })
    .catch(error => {
      console.error('Error al cargar usuarios:', error);
      alert('Error al cargar la lista de usuarios');
      return [];
    });
}

// Llenar el select de usuarios
function llenarSelectUsuarios(usuarioSeleccionadoId = '') {
  const selectUsuarios = getElement('usuarios_id');
  if (!selectUsuarios) return;

  selectUsuarios.innerHTML = '<option value="">-- Selecciona un usuario --</option>';

  usuariosCargados.forEach(usuario => {
    const option = document.createElement('option');
    option.value = usuario.id;

    // Usar nombre_completo si está disponible, si no, construirlo
    const nombreCompleto = usuario.nombre_completo ||
      `${usuario.nombre} ${usuario.apepat} ${usuario.apemat || ''}`.trim();

    option.textContent = nombreCompleto;
    option.selected = (usuario.id == usuarioSeleccionadoId);
    selectUsuarios.appendChild(option);
  });
}

// Configurar evento de búsqueda
function configurarBusqueda() {
  const buscarInput = getElement('buscar');
  if (buscarInput) {
    buscarInput.addEventListener('input', function() {
      const query = this.value.trim();
      if (query.length >= 1) {
        fetch(`buscar_clientes.php?buscar=${encodeURIComponent(query)}`)
          .then(response => response.text())
          .then(data => {
            const tablaClientes = getElement('tabla-clientes');
            const mensaje = getElement('mensaje');

            if (tablaClientes) tablaClientes.innerHTML = data;
            if (mensaje) mensaje.textContent = data.trim() === '' ? 'No se encontraron resultados.' : '';

            agregarEventosFila();
          });
      } else {
        const tablaClientes = getElement('tabla-clientes');
        const mensaje = getElement('mensaje');

        if (tablaClientes) tablaClientes.innerHTML = '';
        if (mensaje) mensaje.textContent = 'Escribe al menos un caracter para buscar.';
      }
    });
  }
}

function validarCamposEditar() {
  const camposRequeridos = [
    'editar_nombre',
    'editar_apepat',
    'editar_apemat',
    'editar_numero1',
    'editar_numero2',
    'editar_asunto',
    'editar_descripcion',
    'usuarios_id'
  ];

  const todosLlenos = camposRequeridos.every(id => {
    const campo = getElement(id);
    const valor = campo ? campo.value.trim() : '';
    return valor.length > 0;
  });

  // Verificar si hay cambios en los campos
  const hayCambios = Object.keys(valoresOriginales).some(key => {
    const campo = getElement(key);
    return campo && campo.value !== valoresOriginales[key];
  });

  const submitButton = document.querySelector('#formEditar button[type="submit"]');
  if (submitButton) {
    submitButton.disabled = !(todosLlenos && hayCambios);
  }
}

function abrirModalEditar(id) {
  // Primero asegurarse de que los usuarios están cargados
  if (usuariosCargados.length === 0) {
    cargarUsuarios().then(() => {
      // Después de cargar usuarios, obtener los datos del cliente
      obtenerDatosCliente(id);
    });
  } else {
    // Si ya tenemos los usuarios, obtener los datos del cliente directamente
    obtenerDatosCliente(id);
  }
}

function obtenerDatosCliente(id) {
  fetch(`obtener_cliente.php?id=${id}`)
    .then(res => {
      if (!res.ok) throw new Error('Error al cargar cliente');
      return res.json();
    })
    .then(data => {
      if (data.error) {
        throw new Error(data.error);
      }

      console.log('Datos del cliente:', data);

      // Almacenar valores originales
      valoresOriginales = {
        'editar_nombre': data.nombre,
        'editar_apepat': data.apepat,
        'editar_apemat': data.apemat,
        'editar_numero1': data.numero1,
        'editar_numero2': data.numero2,
        'editar_asunto': data.asunto,
        'editar_descripcion': data.descripcion,
        'usuarios_id': data.usuario_id_real || data.usuario_id
      };

      // Establecer valores en los campos de forma segura
      const campos = {
        'editar_id': id,
        'editar_nombre': data.nombre,
        'editar_apepat': data.apepat,
        'editar_apemat': data.apemat,
        'editar_numero1': data.numero1,
        'editar_numero2': data.numero2,
        'editar_asunto': data.asunto,
        'editar_descripcion': data.descripcion
      };

      Object.keys(campos).forEach(key => {
        const element = getElement(key);
        if (element) element.value = campos[key];
      });

      // Llenar y seleccionar el usuario correcto
      llenarSelectUsuarios(data.usuario_id_real || data.usuario_id);

      // Configurar campos según el rol
      const camposEditables = ['editar_nombre', 'editar_apepat', 'editar_apemat', 'editar_numero1', 'editar_numero2', 'editar_asunto', 'editar_descripcion', 'usuarios_id'];
      camposEditables.forEach(id => {
        const input = getElement(id);
        if (input) {
          input.disabled = rolUsuario !== 'admin';
          input.addEventListener('input', validarCamposEditar);
        }
      });

      // Mostrar/ocultar footer según rol
      const modalFooter = document.querySelector('#formEditar .modal-footer');
      if (modalFooter) {
        modalFooter.style.display = rolUsuario === 'admin' ? 'block' : 'none';
      }

      // Validaciones de formato
      ['editar_nombre', 'editar_apepat', 'editar_apemat'].forEach(id => {
        const input = getElement(id);
        if (input) {
          input.oninput = function() {
            this.value = this.value.replace(/[^A-Za-zÁÉÍÓÚáéíóúÑñ\s]/g, '');
            validarCamposEditar();
          };
        }
      });

      ['editar_numero1', 'editar_numero2'].forEach(id => {
        const input = getElement(id);
        if (input) {
          input.oninput = function() {
            this.value = this.value.replace(/\D/g, '');
            validarCamposEditar();
          };
        }
      });

      // Deshabilitar el botón inicialmente
      const submitButton = document.querySelector('#formEditar button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
      }

      const modal = new bootstrap.Modal(getElement('modalEditar'));
      modal.show();

      // Enfocar el primer campo editable cuando el modal esté completamente visible
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
      console.error('Error al cargar datos del cliente:', error);
      alert('Error al cargar los datos del cliente: ' + error.message);
    });
}

// Configurar envío del formulario
function configurarFormulario() {
  const formEditar = getElement('formEditar');
  if (formEditar) {
    formEditar.addEventListener('submit', function(e) {
      e.preventDefault();
      const formData = new FormData(this);

      fetch('actualizar_cliente.php', {
          method: 'POST',
          body: formData
        })
        .then(res => res.text())
        .then(msg => {
          alert(msg);
          const buscarInput = getElement('buscar');
          if (buscarInput) buscarInput.dispatchEvent(new Event('input'));

          const modalEditar = getElement('modalEditar');
          if (modalEditar) {
            const modalInstance = bootstrap.Modal.getInstance(modalEditar);
            if (modalInstance) modalInstance.hide();
          }

          // Reiniciar estado después de enviar el formulario
          valoresOriginales = {};
        })
        .catch(error => {
          console.error('Error al actualizar:', error);
          alert('Error al actualizar el cliente');
        });
    });
  }
}

// Agregar eventos de validación a todos los campos requeridos
function configurarValidacionCampos() {
  const camposRequeridos = ['editar_nombre', 'editar_apepat', 'editar_apemat', 'editar_numero1', 'editar_numero2', 'editar_asunto', 'editar_descripcion', 'usuarios_id'];
  camposRequeridos.forEach(id => {
    const input = getElement(id);
    if (input) {
      input.addEventListener('input', validarCamposEditar);
    }
  });
}

// Gestionar el foco cuando se cierra el modal
function configurarCierreModal() {
  const modalEditar = getElement('modalEditar');
  if (modalEditar) {
    modalEditar.addEventListener('hidden.bs.modal', function() {
      valoresOriginales = {};

      // También forzar el foco a otro elemento fuera del modal
      setTimeout(() => {
        const buscarInput = getElement('buscar');
        if (buscarInput) buscarInput.focus();
      }, 100);
    });
  }
}

// Inicializar la aplicación
function inicializar() {
  configurarBusqueda();
  configurarFormulario();
  configurarValidacionCampos();
  configurarCierreModal();
  
  // Cargar usuarios cuando se inicia la página
  cargarUsuarios();
}

// Iniciar cuando el DOM esté completamente cargado
document.addEventListener('DOMContentLoaded', inicializar);