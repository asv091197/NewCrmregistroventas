//ventas.js - VERSIÓN MODIFICADA CON PERMISO DE PLATAFORMA EN MODAL Y TODOS LOS USUARIOS EN EL MODAL
// Variables globales
let todasLasVentas = [];
let ventasFiltradas = [];
let usuarios = [];
let plataformasData = [];
let ventasPorPagina = 10;
let paginaActual = 1;
let busquedaActual = '';
let tipoVentaActual = '';
let salaIdActual = 0;
let esAdmin = true;
let fechaInicioMesGlobal = '';
let fechaFinMesGlobal = '';
let salaUsuarioIdGlobal = null;
let rolUsuarioGlobal = '';
let tieneAccesoTodasSalas = true;
let salasPermitidas = [];
let esAdminConPermisoPlataforma = false;

// Variables globales para guardar las últimas plataformas seleccionadas por método
let ultimaPlataformaSPEI = null;
let ultimaPlataformaTDC = null;
let metodoPagoActual = null;

// Variables para guardar valores entre cambios de tipo
let valoresVentaNormal = {
    liner_id: '',
    closer1_id: '',
    closer2_id: ''
};

let valorFullStack = {
    fullstack_id: ''
};

// Función para inicializar la aplicación
function inicializarAplicacion(
    totalVentasInicial,
    ventasData,
    busquedaParam = '',
    plataformaIdsParam = [],
    tipoVentaParam = '',
    salaIdParam = 0,
    esUsuarioAdmin = true,
    fechaInicioMes = '',
    fechaFinMes = '',
    salaUsuarioIdParam = null,
    rolUsuarioParam = '',
    tieneAccesoTodasSalasParam = true,
    salasPermitidasParam = [],
    tienePermisoPlataformaParam = false
) {
    console.log('=== INICIALIZANDO VENTAS.JS ===');
    console.log('Rol usuario:', rolUsuarioParam);
    console.log('Es admin:', esUsuarioAdmin);
    console.log('Tiene acceso todas salas:', tieneAccesoTodasSalasParam);
    console.log('Tiene permiso plataforma:', tienePermisoPlataformaParam);

    todasLasVentas = procesarDatosVentas(ventasData);
    ventasFiltradas = [...todasLasVentas];
    busquedaActual = busquedaParam;
    tipoVentaActual = tipoVentaParam;
    salaIdActual = salaIdParam;
    esAdmin = esUsuarioAdmin;
    tieneAccesoTodasSalas = tieneAccesoTodasSalasParam;
    salasPermitidas = salasPermitidasParam;
    fechaInicioMesGlobal = fechaInicioMes;
    fechaFinMesGlobal = fechaFinMes;
    salaUsuarioIdGlobal = salaUsuarioIdParam;
    rolUsuarioGlobal = rolUsuarioParam;
    esAdminConPermisoPlataforma = esAdmin && tienePermisoPlataformaParam;

    configurarBotonLimpiar();
    configurarBusqueda();

    // Cargar datos iniciales para el modal
    cargarDatosIniciales();

    configurarEventListeners();
    actualizarEnlaceExportacion();

    // Configurar validación de fechas para usuarios no admin O admin sin acceso a todas salas
    if (!tieneAccesoTodasSalas && !esAdmin) {
        configurarValidacionFechas();
        mostrarMensajeRestriccion();
    }

    // Si hay un filtro de plataforma en la URL, aplicarlo
    if (plataformaIdsParam.length > 0) {
        plataformaIdsParam.forEach(id => {
            const checkbox = document.querySelector(`input.plataforma-checkbox[value="${id}"]`);
            if (checkbox) checkbox.checked = true;
        });
        document.getElementById('plataforma_todas').checked = false;
        filtrarPorPlataforma(plataformaIdsParam);
    }

    // Si hay un filtro de tipo_venta en la URL, aplicarlo
    if (tipoVentaParam) {
        const tipoVentaSelect = document.getElementById('tipo_venta');
        if (tipoVentaSelect) {
            tipoVentaSelect.value = tipoVentaParam;
        }
    }

    // Si hay un filtro de sala en la URL, aplicarlo
    if (salaIdParam > 0) {
        const salaSelect = document.getElementById('sala_id');
        if (salaSelect) {
            salaSelect.value = salaIdParam;
        }
    }

    actualizarPaginacion();
    mostrarPagina(1);

    // Inicializar búsqueda en selects cuando se cargue el modal
    const ventaModal = document.getElementById('ventaModal');
    if (ventaModal) {
        ventaModal.addEventListener('shown.bs.modal', function () {
            setTimeout(() => {
                inicializarBusquedaSelects();
                // Agregar mensaje informativo cuando se abra el modal
                agregarMensajeModalTodosUsuarios();
            }, 300);
        });
    }
}

// Función para cargar usuarios - MODIFICADA: siempre cargar TODOS los usuarios para el modal
function cargarUsuariosPorSala(salaId = null, esAdminAccesoTodasSalas = false, esParaModal = false) {
    console.log('🔗 Cargando usuarios para sala:', salaId, 'Admin acceso total:', esAdminAccesoTodasSalas, 'Es para modal:', esParaModal);
    
    let url = 'obtener_usuarios.php';
    
    // MODIFICACIÓN: Si es para el modal, SIEMPRE cargar TODOS los usuarios
    if (esParaModal) {
        url += '?todos=1';
        console.log('✅ Cargando TODOS los usuarios para el modal');
    } else if (esAdminAccesoTodasSalas) {
        url += '?todos=1';
        console.log('✅ Admin con acceso total: solicitando TODOS los usuarios');
    } else if (salaId) {
        url += `?sala_id=${salaId}`;
    }
    
    return fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('✅ Usuarios recibidos:', data.length, 'usuarios');
            
            if (Array.isArray(data)) {
                usuarios = data;
                
                usuarios = usuarios.map(usuario => {
                    return {
                        ...usuario,
                        nombre_mostrar: usuario.apemat || usuario.nombre_completo || `Usuario ${usuario.id}`
                    };
                });
            } else if (data.error) {
                throw new Error(data.error);
            } else {
                usuarios = [];
            }
            
            console.log(`👥 Usuarios disponibles: ${usuarios.length}`);
            
            if (usuarios.length > 0) {
                console.log('Primeros 5 usuarios:', usuarios.slice(0, 5));
            }
            
            return usuarios;
        })
        .catch(error => {
            console.error('❌ Error al cargar usuarios:', error);
            usuarios = [];
            return usuarios;
        });
}

// Función para cargar datos iniciales - MODIFICADA
function cargarDatosIniciales() {
    console.log('📥 Cargando datos iniciales...');
    console.log('Rol usuario:', rolUsuarioGlobal);
    console.log('Es admin:', esAdmin);
    console.log('Tiene acceso todas salas:', tieneAccesoTodasSalas);
    console.log('Admin con permiso plataforma:', esAdminConPermisoPlataforma);

    // Cargar plataformas primero
    cargarPlataformas()
        .then(() => {
            console.log('✅ Plataformas cargadas:', plataformasData.length);
            
            // MODIFICACIÓN: Cargar TODOS los usuarios para el modal desde el inicio
            return cargarUsuariosPorSala(null, false, true); // Último parámetro true = para modal
        })
        .then(() => {
            console.log('✅ Usuarios cargados para modal:', usuarios.length);
            poblarSelectUsuariosConBusqueda();
            poblarSelectFullStack();
        })
        .catch(error => {
            console.error('❌ Error al cargar datos iniciales:', error);
            alert('Error al cargar algunos datos. La funcionalidad puede estar limitada.');
        });
}

// Configurar validación de fechas para usuarios no admin
function configurarValidacionFechas() {
    const fechaInicioInput = document.getElementById('fecha_inicio');
    const fechaFinInput = document.getElementById('fecha_fin');
    const filtroForm = document.getElementById('filtroForm');

    if (fechaInicioInput && fechaFinInput) {
        fechaInicioInput.addEventListener('change', validarFechasMesActual);
        fechaFinInput.addEventListener('change', validarFechasMesActual);
    }

    if (filtroForm) {
        filtroForm.addEventListener('submit', function (e) {
            if (!validarFechasMesActual()) {
                e.preventDefault();
                return false;
            }
        });
    }
}

// Función para validar fechas dentro del mes actual
function validarFechasMesActual() {
    if (esAdmin) return true;

    const fechaInicioInput = document.getElementById('fecha_inicio');
    const fechaFinInput = document.getElementById('fecha_fin');

    if (fechaInicioInput.value && fechaInicioInput.value < fechaInicioMesGlobal) {
        alert('La fecha de inicio no puede ser anterior al ' + formatFecha(fechaInicioMesGlobal));
        fechaInicioInput.value = fechaInicioMesGlobal;
        return false;
    }

    if (fechaInicioInput.value && fechaInicioInput.value > fechaFinMesGlobal) {
        alert('La fecha de inicio no puede ser posterior al ' + formatFecha(fechaFinMesGlobal));
        fechaInicioInput.value = fechaInicioMesGlobal;
        return false;
    }

    if (fechaFinInput.value && fechaFinInput.value > fechaFinMesGlobal) {
        alert('La fecha fin no puede ser posterior al ' + formatFecha(fechaFinMesGlobal));
        fechaFinInput.value = fechaFinMesGlobal;
        return false;
    }

    if (fechaFinInput.value && fechaFinInput.value < fechaInicioMesGlobal) {
        alert('La fecha fin no puede ser anterior al ' + formatFecha(fechaInicioMesGlobal));
        fechaFinInput.value = fechaFinMesGlobal;
        return false;
    }

    if (fechaInicioInput.value && fechaFinInput.value && fechaInicioInput.value > fechaFinInput.value) {
        alert('La fecha de inicio no puede ser mayor que la fecha fin');
        fechaInicioInput.value = fechaInicioMesGlobal;
        fechaFinInput.value = fechaFinMesGlobal;
        return false;
    }

    return true;
}

// Función para formatear fecha en formato dd/mm/yyyy
function formatFecha(fechaISO) {
    const fecha = new Date(fechaISO);
    const dia = String(fecha.getDate()).padStart(2, '0');
    const mes = String(fecha.getMonth() + 1).padStart(2, '0');
    const año = fecha.getFullYear();
    return `${dia}/${mes}/${año}`;
}

// Agregar nueva función para mostrar mensaje de restricción
function mostrarMensajeRestriccion() {
    let mensajeExistente = document.getElementById('mensaje-restriccion');
    if (!mensajeExistente) {
        const filterSection = document.querySelector('.filter-section');
        if (filterSection) {
            const mensajeDiv = document.createElement('div');
            mensajeDiv.id = 'mensaje-restriccion';
            mensajeDiv.className = 'alert alert-warning alert-sm mt-2';
            mensajeDiv.innerHTML = `
                <i class="bi bi-shield-exclamation"></i>
                <strong>Vista restringida:</strong> Solo puedes ver y filtrar ventas del mes en curso y de tu sala asignada.
            `;
            filterSection.parentNode.insertBefore(mensajeDiv, filterSection.nextSibling);
        }
    }
}

// Procesar datos de ventas para el formato esperado
function procesarDatosVentas(ventasData) {
    return ventasData.map(venta => {
        const fecha = new Date(venta.fecha);
        const offset = fecha.getTimezoneOffset();
        fecha.setMinutes(fecha.getMinutes() + offset);

        const dia = String(fecha.getDate()).padStart(2, '0');
        const mes = String(fecha.getMonth() + 1).padStart(2, '0');
        const año = fecha.getFullYear();
        const fechaFormateada = `${dia}/${mes}/${año}`;

        return {
            id: venta.id,
            fecha: fechaFormateada,
            precio: '$' + parseFloat(venta.precio).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
            precio_neto: '$' + parseFloat(venta.precio_neto).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
            cliente: venta.cliente_nombre,
            producto: venta.producto,
            liner: venta.liner_nombre || 'No asignado',
            closer1: venta.closer1_nombre || 'No asignado',
            closer2: venta.closer2_nombre || 'No asignado',
            fullstack: venta.full_stack_nombre || 'No asignado',
            plataforma: venta.plataforma_nombre_completo || 'N/A',
            tipo_venta: venta.tipo_venta || 'N/A',
            usuario: venta.usuario_creacion_nombre || 'N/A',
            sala: venta.sala_nombre || 'N/A',
            datosCompletos: venta
        };
    });
}

function filtrarPorPlataforma(plataformaIds) {
    const salaSelect = document.getElementById('sala_id');
    const salaValue = salaSelect ? salaSelect.value : '';
    const salaValueNormalized = (salaValue === '' || salaValue === '0') ? '0' : salaValue;

    console.log('=== FILTRAR POR PLATAFORMA ===');
    console.log('Plataformas:', plataformaIds);
    console.log('Sala seleccionada:', salaValueNormalized);
    console.log('Tiene acceso todas salas:', tieneAccesoTodasSalas);

    if (!plataformaIds || plataformaIds.length === 0) {
        let ventasTemp = [...todasLasVentas];

        if (!esAdmin && salaUsuarioIdGlobal) {
            ventasTemp = ventasTemp.filter(venta => {
                return venta.datosCompletos.sala_id == salaUsuarioIdGlobal;
            });
        } else if (!esAdmin && !salaUsuarioIdGlobal) {
            ventasTemp = [];
        } else if (esAdmin && salaValueNormalized !== '0') {
            if (tieneAccesoTodasSalas) {
                ventasTemp = ventasTemp.filter(venta => {
                    return venta.datosCompletos.sala_id == salaValueNormalized;
                });
            } else {
                if (salasPermitidas.length > 0) {
                    ventasTemp = ventasTemp.filter(venta => {
                        return salasPermitidas.includes(String(venta.datosCompletos.sala_id));
                    });
                } else {
                    ventasTemp = [];
                }
            }
        }

        ventasFiltradas = ventasTemp;
    } else {
        ventasFiltradas = todasLasVentas.filter(venta => {
            return plataformaIds.includes(String(venta.datosCompletos.plataforma_id));
        });

        if (salaValueNormalized !== '0') {
            ventasFiltradas = ventasFiltradas.filter(venta => {
                return venta.datosCompletos.sala_id == salaValueNormalized;
            });
        } else if (!esAdmin && salaUsuarioIdGlobal) {
            ventasFiltradas = ventasFiltradas.filter(venta => {
                return venta.datosCompletos.sala_id == salaUsuarioIdGlobal;
            });
        } else if (esAdmin && !tieneAccesoTodasSalas && salasPermitidas.length > 0) {
            ventasFiltradas = ventasFiltradas.filter(venta => {
                return salasPermitidas.includes(String(venta.datosCompletos.sala_id));
            });
        }
    }

    if (tipoVentaActual) {
        ventasFiltradas = ventasFiltradas.filter(venta => {
            return venta.datosCompletos.tipo_venta === tipoVentaActual;
        });
    }

    mostrarPagina(1);
    actualizarContadorVentas(ventasFiltradas.length, plataformaIds.length > 0 ? 'Resultados' : 'Total');
}

// Función para mostrar una página específica
function mostrarPagina(pagina) {
    paginaActual = pagina;
    const inicio = (pagina - 1) * ventasPorPagina;
    const fin = inicio + ventasPorPagina;
    const ventasPagina = ventasFiltradas.slice(inicio, fin);

    actualizarTabla(ventasPagina);
    actualizarPaginacion();
    actualizarContadorVentas(ventasFiltradas.length, busquedaActual ? 'Resultados' : 'Total');
}

// Actualizar la tabla con las ventas de la página actual
function actualizarTabla(ventas) {
    const tablaVentas = document.getElementById('tabla-ventas');
    let html = '';

    if (ventas.length > 0) {
        ventas.forEach(venta => {
            html += `
                <tr data-venta='${JSON.stringify(venta.datosCompletos).replace(/'/g, "&#39;")}' style="cursor: pointer;">
                    <td>${venta.fecha}</td>
                    <td>${venta.precio}</td>
                    <td>${venta.precio_neto}</td>
                    <td>${resaltarTexto(venta.cliente, busquedaActual)}</td>
                    <td>${resaltarTexto(venta.producto, busquedaActual)}</td>
                    <td>${venta.liner}</td>
                    <td>${venta.closer1}</td>
                    <td>${venta.closer2}</td>
                    <td>${venta.fullstack}</td>
                    <td>${resaltarTexto(venta.plataforma, busquedaActual)}</td>
                    <td>${resaltarTexto(venta.tipo_venta, busquedaActual)}</td>
                    <td>${resaltarTexto(venta.usuario, busquedaActual)}</td>
                    <td>${venta.sala}</td>
                </tr>
            `;
        });
    } else {
        html = '<tr><td colspan="13" class="text-center">No hay ventas para mostrar</td></tr>';
    }

    tablaVentas.innerHTML = html;

    document.querySelectorAll('#tabla-ventas tr[data-venta]').forEach(fila => {
        const ventaData = JSON.parse(fila.getAttribute('data-venta'));
        fila.addEventListener('click', function () {
            mostrarModalVenta(ventaData);
        });
    });
}

// Actualizar la paginación
function actualizarPaginacion() {
    const totalPaginas = Math.ceil(ventasFiltradas.length / ventasPorPagina);
    const paginacionContainer = document.getElementById('paginacion-cliente');

    if (!paginacionContainer) return;

    if (totalPaginas <= 1) {
        document.getElementById('paginacion-container').style.display = 'none';
        return;
    } else {
        document.getElementById('paginacion-container').style.display = 'block';
    }

    let html = '';

    html += `<li class="page-item ${paginaActual === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="cambiarPagina(${paginaActual - 1}); return false;" aria-label="Anterior">
            <span aria-hidden="true">&laquo;</span>
        </a>
    </li>`;

    const paginasAMostrar = [];
    const paginasVisibles = 5;

    if (totalPaginas <= paginasVisibles) {
        for (let i = 1; i <= totalPaginas; i++) {
            paginasAMostrar.push(i);
        }
    } else {
        if (paginaActual <= 3) {
            for (let i = 1; i <= 4; i++) paginasAMostrar.push(i);
            paginasAMostrar.push('...');
            paginasAMostrar.push(totalPaginas);
        } else if (paginaActual >= totalPaginas - 2) {
            paginasAMostrar.push(1);
            paginasAMostrar.push('...');
            for (let i = totalPaginas - 3; i <= totalPaginas; i++) paginasAMostrar.push(i);
        } else {
            paginasAMostrar.push(1);
            paginasAMostrar.push('...');
            paginasAMostrar.push(paginaActual - 1);
            paginasAMostrar.push(paginaActual);
            paginasAMostrar.push(paginaActual + 1);
            paginasAMostrar.push('...');
            paginasAMostrar.push(totalPaginas);
        }
    }

    paginasAMostrar.forEach(pagina => {
        if (pagina === '...') {
            html += `<li class="page-item disabled">
                <a class="page-link" href="#">...</a>
            </li>`;
        } else {
            html += `<li class="page-item ${pagina === paginaActual ? 'active' : ''}">
                <a class="page-link" href="#" onclick="cambiarPagina(${pagina}); return false;">${pagina}</a>
            </li>`;
        }
    });

    html += `<li class="page-item ${paginaActual === totalPaginas ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="cambiarPagina(${paginaActual + 1}); return false;" aria-label="Siguiente">
            <span aria-hidden="true">&raquo;</span>
        </a>
    </li>`;

    paginacionContainer.innerHTML = html;
}

// Función para cambiar de página
function cambiarPagina(pagina) {
    if (pagina >= 1 && pagina <= Math.ceil(ventasFiltradas.length / ventasPorPagina)) {
        mostrarPagina(pagina);
    }
}

// Buscar ventas en TODOS los datos
function buscarVentas(query) {
    busquedaActual = query.trim();

    const mensaje = document.getElementById('mensaje');
    const plataformaCheckboxes = document.querySelectorAll('input.plataforma-checkbox:checked');
    const plataformaIds = Array.from(plataformaCheckboxes).map(checkbox => checkbox.value);
    const tipoVentaSelect = document.getElementById('tipo_venta');
    const tipoVentaValue = tipoVentaSelect ? tipoVentaSelect.value : '';
    const salaSelect = document.getElementById('sala_id');
    const salaValue = salaSelect ? salaSelect.value : '';

    const salaValueNormalized = (salaValue === '' || salaValue === '0') ? '0' : salaValue;

    tipoVentaActual = tipoVentaValue;
    salaIdActual = parseInt(salaValueNormalized) || 0;

    console.log('=== BUSCAR VENTAS ===');
    console.log('Busqueda:', busquedaActual);
    console.log('Es admin:', esAdmin);
    console.log('Tiene acceso todas salas:', tieneAccesoTodasSalas);
    console.log('Sala seleccionada (raw):', salaValue);
    console.log('Sala seleccionada (normalized):', salaValueNormalized);
    console.log('Tipo venta:', tipoVentaValue);
    console.log('Plataformas seleccionadas:', plataformaIds);

    if (busquedaActual.length < 1 && plataformaIds.length === 0 && !tipoVentaValue && salaValueNormalized === '0') {
        let ventasTemp = [...todasLasVentas];

        if (!esAdmin && salaUsuarioIdGlobal) {
            ventasTemp = ventasTemp.filter(venta => {
                return venta.datosCompletos.sala_id == salaUsuarioIdGlobal;
            });
        } else if (!esAdmin && !salaUsuarioIdGlobal) {
            ventasTemp = [];
        }

        ventasFiltradas = ventasTemp;
        mostrarPagina(1);

        if (mensaje) {
            if (ventasFiltradas.length > 0) {
                if (esAdmin && tieneAccesoTodasSalas) {
                    mensaje.textContent = 'Escribe al menos un caracter para buscar o usa los filtros. Mostrando ventas de todas las salas.';
                } else if (esAdmin && !tieneAccesoTodasSalas && salasPermitidas.length > 0) {
                    mensaje.textContent = 'Escribe al menos un caracter para buscar o usa los filtros. Mostrando ventas de tus salas permitidas.';
                } else if (!esAdmin && salaUsuarioIdGlobal) {
                    mensaje.textContent = 'Escribe al menos un caracter para buscar o usa los filtros. Mostrando ventas de tu sala asignada.';
                } else {
                    mensaje.textContent = 'Escribe al menos un caracter para buscar o usa los filtros';
                }
            } else if (!esAdmin && !salaUsuarioIdGlobal) {
                mensaje.textContent = 'No tienes sala asignada. No puedes ver ventas.';
            } else if (!esAdmin && salaUsuarioIdGlobal) {
                mensaje.textContent = 'No hay ventas registradas en tu sala.';
            } else if (esAdmin && !tieneAccesoTodasSalas && salasPermitidas.length === 0) {
                mensaje.textContent = 'No tienes salas asignadas. No puedes ver ventas.';
            } else {
                mensaje.textContent = 'No hay ventas registradas';
            }
        }

        actualizarContadorVentas(ventasFiltradas.length, 'Total');
        return;
    }

    if (mensaje) {
        mensaje.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"></div> Buscando...';
    }

    let ventasFiltradasTemp = todasLasVentas;

    if (busquedaActual.length > 0) {
        ventasFiltradasTemp = todasLasVentas.filter(venta => {
            return Object.values(venta).some(valor => {
                const valorStr = String(valor || '').toLowerCase();
                return valorStr.includes(busquedaActual.toLowerCase());
            });
        });
    }

    if (plataformaIds.length > 0) {
        ventasFiltradasTemp = ventasFiltradasTemp.filter(venta => {
            return plataformaIds.includes(String(venta.datosCompletos.plataforma_id));
        });
    }

    if (tipoVentaValue) {
        ventasFiltradasTemp = ventasFiltradasTemp.filter(venta => {
            return venta.datosCompletos.tipo_venta === tipoVentaValue;
        });
    }

    if (busquedaActual.length === 0) {
        if (!esAdmin && salaUsuarioIdGlobal) {
            ventasFiltradasTemp = ventasFiltradasTemp.filter(venta => {
                return venta.datosCompletos.sala_id == salaUsuarioIdGlobal;
            });
        } else if (!esAdmin && !salaUsuarioIdGlobal) {
            ventasFiltradasTemp = [];
        } else if (esAdmin && salaValueNormalized !== '0') {
            if (tieneAccesoTodasSalas) {
                ventasFiltradasTemp = ventasFiltradasTemp.filter(venta => {
                    return venta.datosCompletos.sala_id == salaValueNormalized;
                });
            } else {
                if (salasPermitidas.length > 0) {
                    ventasFiltradasTemp = ventasFiltradasTemp.filter(venta => {
                        return salasPermitidas.includes(String(venta.datosCompletos.sala_id));
                    });
                } else {
                    ventasFiltradasTemp = [];
                }
            }
        } else if (esAdmin && salaValueNormalized === '0') {
            if (tieneAccesoTodasSalas) {
                console.log('Admin con acceso a todas salas - mostrando todas las ventas');
            } else {
                if (salasPermitidas.length > 0) {
                    ventasFiltradasTemp = ventasFiltradasTemp.filter(venta => {
                        return salasPermitidas.includes(String(venta.datosCompletos.sala_id));
                    });
                } else {
                    ventasFiltradasTemp = [];
                }
            }
        }
    } else {
        console.log('Búsqueda activa detectada:', busquedaActual);
        console.log('Sala seleccionada para filtro:', salaValueNormalized);

        if (salaValueNormalized !== '0') {
            console.log('Filtrando por sala seleccionada:', salaValueNormalized);
            ventasFiltradasTemp = ventasFiltradasTemp.filter(venta => {
                return venta.datosCompletos.sala_id == salaValueNormalized;
            });
        } else {
            console.log('No hay filtro de sala - mostrando todos los resultados de búsqueda');

            if (!esAdmin && salaUsuarioIdGlobal) {
                ventasFiltradasTemp = ventasFiltradasTemp.filter(venta => {
                    return venta.datosCompletos.sala_id == salaUsuarioIdGlobal;
                });
            } else if (!esAdmin && !salaUsuarioIdGlobal) {
                ventasFiltradasTemp = [];
            } else if (esAdmin && !tieneAccesoTodasSalas && salasPermitidas.length > 0) {
                ventasFiltradasTemp = ventasFiltradasTemp.filter(venta => {
                    return salasPermitidas.includes(String(venta.datosCompletos.sala_id));
                });
            }
        }
    }

    ventasFiltradas = ventasFiltradasTemp;
    mostrarPagina(1);

    console.log('Resultados encontrados:', ventasFiltradas.length);

    if (mensaje) {
        if (ventasFiltradas.length > 0) {
            if (busquedaActual.length > 0) {
                if (salaValueNormalized !== '0') {
                    mensaje.textContent = `Mostrando ${ventasFiltradas.length} resultados para "${busquedaActual}" en la sala seleccionada`;
                } else if (esAdmin && tieneAccesoTodasSalas) {
                    mensaje.textContent = `Mostrando ${ventasFiltradas.length} resultados para "${busquedaActual}" en todas las salas`;
                } else if (esAdmin && !tieneAccesoTodasSalas && salasPermitidas.length > 0) {
                    mensaje.textContent = `Mostrando ${ventasFiltradas.length} resultados para "${busquedaActual}" en tus salas permitidas`;
                } else if (!esAdmin && salaUsuarioIdGlobal) {
                    mensaje.textContent = `Mostrando ${ventasFiltradas.length} resultados para "${busquedaActual}" en tu sala asignada`;
                } else {
                    mensaje.textContent = `Mostrando ${ventasFiltradas.length} resultados para "${busquedaActual}"`;
                }
            } else if (plataformaIds.length > 0) {
                if (salaValueNormalized !== '0' && esAdmin && tieneAccesoTodasSalas) {
                    mensaje.textContent = `Mostrando ${ventasFiltradas.length} ventas con filtro de plataforma en la sala seleccionada`;
                } else if (esAdmin && tieneAccesoTodasSalas) {
                    mensaje.textContent = `Mostrando ${ventasFiltradas.length} ventas con filtro de plataforma en todas las salas`;
                } else {
                    mensaje.textContent = `Mostrando ${ventasFiltradas.length} ventas con filtro de plataforma`;
                }
            } else if (tipoVentaValue) {
                if (salaValueNormalized !== '0' && esAdmin && tieneAccesoTodasSalas) {
                    mensaje.textContent = `Mostrando ${ventasFiltradas.length} ventas tipo ${tipoVentaValue} en la sala seleccionada`;
                } else if (esAdmin && tieneAccesoTodasSalas) {
                    mensaje.textContent = `Mostrando ${ventasFiltradas.length} ventas tipo ${tipoVentaValue} en todas las salas`;
                } else {
                    mensaje.textContent = `Mostrando ${ventasFiltradas.length} ventas tipo ${tipoVentaValue}`;
                }
            } else if (salaValueNormalized !== '0' && esAdmin && tieneAccesoTodasSalas) {
                mensaje.textContent = `Mostrando ${ventasFiltradas.length} ventas de la sala seleccionada`;
            } else if (esAdmin && tieneAccesoTodasSalas) {
                mensaje.textContent = `Mostrando ${ventasFiltradas.length} ventas de todas las salas`;
            } else if (esAdmin && !tieneAccesoTodasSalas && salasPermitidas.length > 0) {
                mensaje.textContent = `Mostrando ${ventasFiltradas.length} ventas de tus salas permitidas`;
            } else if (!esAdmin && salaUsuarioIdGlobal) {
                mensaje.textContent = `Mostrando ${ventasFiltradas.length} ventas de tu sala asignada`;
            } else {
                mensaje.textContent = `Mostrando ${ventasFiltradas.length} ventas`;
            }
        } else {
            if (busquedaActual.length > 0) {
                if (salaValueNormalized !== '0') {
                    mensaje.textContent = `No se encontraron resultados para "${busquedaActual}" en la sala seleccionada`;
                } else if (esAdmin && tieneAccesoTodasSalas) {
                    mensaje.textContent = `No se encontraron resultados para "${busquedaActual}" en ninguna sala`;
                } else if (esAdmin && !tieneAccesoTodasSalas && salasPermitidas.length > 0) {
                    mensaje.textContent = `No se encontraron resultados para "${busquedaActual}" en tus salas permitidas`;
                } else if (!esAdmin && salaUsuarioIdGlobal) {
                    mensaje.textContent = `No se encontraron resultados para "${busquedaActual}" en tu sala asignada`;
                } else {
                    mensaje.textContent = `No se encontraron resultados para "${busquedaActual}"`;
                }
            } else if (!esAdmin && !salaUsuarioIdGlobal) {
                mensaje.textContent = 'No tienes sala asignada. No puedes ver ventas.';
            } else if (!esAdmin && salaUsuarioIdGlobal) {
                mensaje.textContent = 'No hay ventas registradas en tu sala.';
            } else if (esAdmin && salaValueNormalized !== '0' && tieneAccesoTodasSalas) {
                mensaje.textContent = 'No hay ventas en la sala seleccionada';
            } else if (esAdmin && !tieneAccesoTodasSalas && salasPermitidas.length === 0) {
                mensaje.textContent = 'No tienes salas asignadas. No puedes ver ventas.';
            } else if (esAdmin && !tieneAccesoTodasSalas && salasPermitidas.length > 0) {
                mensaje.textContent = 'No hay ventas en tus salas permitidas';
            } else if (esAdmin && tieneAccesoTodasSalas) {
                mensaje.textContent = 'No hay ventas registradas en el sistema';
            } else {
                mensaje.textContent = 'No hay ventas para mostrar';
            }
        }
    }

    actualizarContadorVentas(ventasFiltradas.length, busquedaActual ? 'Resultados' : 'Total');
}

// Configurar búsqueda
function configurarBusqueda() {
    const buscarInput = document.getElementById('buscar');
    const tipoVentaSelect = document.getElementById('tipo_venta');
    const salaSelect = document.getElementById('sala_id');
    const fechaInicio = document.getElementById('fecha_inicio');
    const fechaFin = document.getElementById('fecha_fin');

    if (buscarInput) {
        let timeout = null;

        buscarInput.addEventListener('input', function () {
            const query = this.value.trim();

            clearTimeout(timeout);

            timeout = setTimeout(() => {
                buscarVentas(query);
                actualizarEnlaceExportacion();
            }, 300);
        });
    }

    const filtros = [tipoVentaSelect, salaSelect, fechaInicio, fechaFin];
    filtros.forEach(filtro => {
        if (filtro) {
            filtro.addEventListener('change', function () {
                buscarVentas(busquedaActual);
                actualizarEnlaceExportacion();
            });
        }
    });
}

// Cargar plataformas desde el servidor
function cargarPlataformas() {
    console.log('🔗 Cargando plataformas...');
    return fetch('obtener_plataformas.php')
        .then(response => {
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('✅ Plataformas recibidas:', data);

            if (Array.isArray(data)) {
                plataformasData = data;
            } else {
                plataformasData = [];
            }

            console.log(`💳 Plataformas cargadas: ${plataformasData.length}`);
            return plataformasData;
        })
        .catch(error => {
            console.error('❌ Error al cargar las plataformas:', error);
            plataformasData = [];
            return plataformasData;
        });
}

// Poblar select de usuarios con funcionalidad de búsqueda
function poblarSelectUsuariosConBusqueda() {
    const linerSelect = document.getElementById('modal-liner');
    const closer1Select = document.getElementById('modal-closer1');
    const closer2Select = document.getElementById('modal-closer2');

    const selects = [linerSelect, closer1Select, closer2Select];

    selects.forEach((select, index) => {
        if (!select) {
            console.warn(`Select ${['modal-liner', 'modal-closer1', 'modal-closer2'][index]} no encontrado`);
            return;
        }

        const valorActual = select.value;

        select.innerHTML = '';

        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        if (index === 0) {
            defaultOption.textContent = 'Selecciona un liner';
            defaultOption.disabled = true;
        } else {
            defaultOption.textContent = 'Selecciona un closer';
        }
        select.appendChild(defaultOption);

        if (usuarios && usuarios.length > 0) {
            usuarios.forEach(usuario => {
                const option = document.createElement('option');
                option.value = usuario.id;
                option.textContent = usuario.nombre_mostrar || usuario.nombre_completo || `Usuario ${usuario.id}`;
                select.appendChild(option);
            });

            if (valorActual && Array.from(select.options).some(opt => opt.value === valorActual)) {
                select.value = valorActual;
            }
        } else {
            const noUsersOption = document.createElement('option');
            noUsersOption.value = '';
            noUsersOption.textContent = 'No hay usuarios disponibles';
            noUsersOption.disabled = true;
            select.appendChild(noUsersOption);
        }
    });

    console.log('✅ Selects de usuarios poblados');
}

// Poblar select de Full Stack
function poblarSelectFullStack() {
    const fullStackSelect = document.getElementById('modal-fullstack');

    if (!fullStackSelect) {
        console.warn('Select modal-fullstack no encontrado');
        return;
    }

    const valorActual = fullStackSelect.value;

    fullStackSelect.innerHTML = '<option value="" disabled>Selecciona un front</option>';

    if (usuarios && usuarios.length > 0) {
        usuarios.forEach(usuario => {
            const option = document.createElement('option');
            option.value = usuario.id;
            option.textContent = usuario.nombre_mostrar || usuario.nombre_completo || `Usuario ${usuario.id}`;
            fullStackSelect.appendChild(option);
        });

        if (valorActual && Array.from(fullStackSelect.options).some(opt => opt.value === valorActual)) {
            fullStackSelect.value = valorActual;
        }
    } else {
        const noUsersOption = document.createElement('option');
        noUsersOption.value = '';
        noUsersOption.textContent = 'No hay usuarios disponibles';
        noUsersOption.disabled = true;
        fullStackSelect.appendChild(noUsersOption);
    }

    console.log('✅ Select de Full Stack poblado');
}

// Configurar event listeners
function configurarEventListeners() {
    // Configurar tipo de venta
    configurarTipoVenta();

    // Configurar cambio de método de pago
    configurarCambioMetodoPago();

    // Configurar cambio de sala en el modal - MODIFICADO: No recargar usuarios
    const salaSelect = document.getElementById('modal-sala');
    if (salaSelect) {
        salaSelect.addEventListener('change', function() {
            const salaId = this.value;
            console.log('Sala cambiada en modal:', salaId);
            
            const hiddenSalaField = document.getElementById('modal-sala-hidden');
            if (hiddenSalaField) {
                hiddenSalaField.value = salaId;
            }
            
            // MODIFICACIÓN: Ya tenemos todos los usuarios cargados, no es necesario recargar
            console.log('✅ Usando usuarios ya cargados (todos) para el modal');
            
            // Solo actualizar los selects buscables
            setTimeout(() => {
                actualizarSelectsBuscable();
            }, 100);
        });
    }

    const hiddenSalaField = document.getElementById('modal-sala-hidden');
    if (salaSelect && hiddenSalaField) {
        salaSelect.addEventListener('change', function () {
            hiddenSalaField.value = this.value;

            if (rolUsuarioGlobal !== 'admin') {
                actualizarNombreSalaVisible();
            }
        });
    }

    const btnActualizar = document.getElementById('btnActualizar');
    if (btnActualizar) {
        btnActualizar.addEventListener('click', actualizarVenta);
    }

    const plataformaTodas = document.getElementById('plataforma_todas');
    const plataformaCheckboxes = document.querySelectorAll('input.plataforma-checkbox');

    if (plataformaTodas) {
        plataformaTodas.addEventListener('change', function () {
            if (this.checked) {
                plataformaCheckboxes.forEach(checkbox => {
                    checkbox.checked = false;
                });
                filtrarPorPlataforma([]);
            }
            actualizarEnlaceExportacion();
        });
    }

    if (plataformaCheckboxes) {
        plataformaCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function () {
                if (this.checked) {
                    plataformaTodas.checked = false;
                }

                const selectedCheckboxes = document.querySelectorAll('input.plataforma-checkbox:checked');
                const selectedIds = Array.from(selectedCheckboxes).map(cb => cb.value);

                if (selectedIds.length === 0) {
                    plataformaTodas.checked = true;
                }

                filtrarPorPlataforma(selectedIds);
                actualizarEnlaceExportacion();
            });
        });
    }
    
    // Configurar eventos del interruptor de permiso en el modal
    setupPermisoSwitchModalEvents();
}

// Configurar cambio de tipo de venta
function configurarTipoVenta() {
    const tipoNormal = document.getElementById('modal-tipoNormal');
    const tipoFullStack = document.getElementById('modal-tipoFullStack');

    if (tipoNormal && tipoFullStack) {
        tipoNormal.addEventListener('change', manejarCambioTipoVentaMejorada);
        tipoFullStack.addEventListener('change', manejarCambioTipoVentaMejorada);
    }
}

// Función para guardar valores actuales antes de cambiar tipo
function guardarValoresActuales() {
    const tipoActual = document.getElementById('modal-tipoNormal').checked ? 'normal' : 'fullstack';

    if (tipoActual === 'normal') {
        valoresVentaNormal.liner_id = document.getElementById('modal-liner').value;
        valoresVentaNormal.closer1_id = document.getElementById('modal-closer1').value;
        valoresVentaNormal.closer2_id = document.getElementById('modal-closer2').value;
    } else {
        valorFullStack.fullstack_id = document.getElementById('modal-fullstack').value;
    }
}

// Función para restaurar valores según el tipo seleccionado
function restaurarValores(tipoNuevo) {
    if (tipoNuevo === 'normal') {
        if (valoresVentaNormal.liner_id) {
            document.getElementById('modal-liner').value = valoresVentaNormal.liner_id;
        }
        if (valoresVentaNormal.closer1_id) {
            document.getElementById('modal-closer1').value = valoresVentaNormal.closer1_id;
        }
        if (valoresVentaNormal.closer2_id) {
            document.getElementById('modal-closer2').value = valoresVentaNormal.closer2_id;
        }
    } else {
        if (valorFullStack.fullstack_id) {
            document.getElementById('modal-fullstack').value = valorFullStack.fullstack_id;
        }
    }
}

// Función mejorada para manejar cambio de tipo de venta
function manejarCambioTipoVentaMejorada() {
    const tipoNormal = document.getElementById('modal-tipoNormal').checked;
    const tipoFullStack = document.getElementById('modal-tipoFullStack').checked;

    guardarValoresActuales();

    const linerSelect = document.getElementById('modal-liner');
    const closer1Select = document.getElementById('modal-closer1');
    const closer2Select = document.getElementById('modal-closer2');
    const fullStackSelect = document.getElementById('modal-fullstack');

    console.log('Cambio tipo venta:', { tipoNormal, tipoFullStack });

    if (tipoNormal) {
        mostrarTodosCamposVentaNormal();

        if (linerSelect) linerSelect.required = true;
        if (fullStackSelect) fullStackSelect.required = false;

        setTimeout(() => {
            restaurarValores('normal');
        }, 50);

    } else if (tipoFullStack) {
        mostrarSoloFullStack();

        if (linerSelect) linerSelect.required = false;
        if (fullStackSelect) fullStackSelect.required = true;

        setTimeout(() => {
            restaurarValores('fullstack');
        }, 50);
    }
}

// Función para mostrar los 3 campos de Venta Normal
function mostrarTodosCamposVentaNormal() {
    document.getElementById('modal-linerClosersDiv').style.display = 'flex';
    document.getElementById('modal-fullStackDiv').style.display = 'none';
}

// Función para mostrar solo Full Stack
function mostrarSoloFullStack() {
    document.getElementById('modal-linerClosersDiv').style.display = 'none';
    document.getElementById('modal-fullStackDiv').style.display = 'block';
}

// Función: Configurar cambio de método de pago
function configurarCambioMetodoPago() {
    const pagoSpei = document.getElementById('modal-pagoSpei');
    const pagoTarjeta = document.getElementById('modal-pagoTarjeta');

    if (pagoSpei && pagoTarjeta) {
        pagoSpei.addEventListener('change', manejarCambioMetodoPago);
        pagoTarjeta.addEventListener('change', manejarCambioMetodoPago);
    }
}

// Función: Manejar cambio de método de pago
function manejarCambioMetodoPago() {
    const spei = document.getElementById("modal-pagoSpei").checked;
    const tdc = document.getElementById("modal-pagoTarjeta").checked;

    metodoPagoActual = spei ? 'SPEI' : 'TDC';

    const plataformaDiv = document.getElementById("modal-plataformaDiv");
    if (plataformaDiv) {
        plataformaDiv.style.display = (spei || tdc) ? "block" : "none";
    }

    const plataformaSelect = document.getElementById('modal-plataforma');
    const permisoContainer = document.getElementById('modal-plataformaPermisoContainer');
    
    if (permisoContainer) {
        permisoContainer.style.display = 'none';
    }
    
    if (plataformaSelect && (spei || tdc)) {
        const metodoPagoDB = spei ? "SPEI/EFECTIVO" : "TDC";
        // SOLO mostrar plataformas con permiso_plataforma = 1
        const plataformasFiltradas = plataformasData.filter(p => 
            p.metodo_pago === metodoPagoDB && p.permiso_plataforma == 1
        );

        plataformaSelect.innerHTML = '<option value="">Seleccionar</option>';

        plataformasFiltradas.forEach(plataforma => {
            const option = document.createElement('option');
            option.value = plataforma.id;
            option.textContent = `${plataforma.nombre_ancronimo} (${plataforma.plataformaspei})`;
            option.dataset.permiso = plataforma.permiso_plataforma;
            plataformaSelect.appendChild(option);
        });
        
        // Configurar evento para mostrar/ocultar interruptor
        plataformaSelect.addEventListener('change', function() {
            const selectedId = this.value;
            mostrarOcultarInterruptorPermiso(selectedId);
        });
    }
}

// Función para mostrar/ocultar el interruptor de permiso
function mostrarOcultarInterruptorPermiso(plataformaId) {
    const toggleSwitch = document.getElementById('modal-togglePermisoPlataforma');
    const permisoContainer = document.getElementById('modal-plataformaPermisoContainer');
    
    console.log('Mostrar/ocultar interruptor para plataforma:', plataformaId);
    console.log('esAdminConPermisoPlataforma:', esAdminConPermisoPlataforma);
    
    if (plataformaId && esAdminConPermisoPlataforma) {
        const plataforma = plataformasData.find(p => p.id == plataformaId);
        if (plataforma) {
            // Siempre mostrar el interruptor para admin con permiso
            // El switch se marca si la plataforma tiene permiso=1
            toggleSwitch.checked = plataforma.permiso_plataforma == 1;
            if (permisoContainer) {
                permisoContainer.style.display = 'block';
                console.log('✅ Mostrando interruptor para admin con permiso');
            }
        } else {
            // Si la plataforma no está en los datos, ocultar el interruptor
            if (permisoContainer) {
                permisoContainer.style.display = 'none';
                console.log('❌ Plataforma no encontrada en datos, ocultando interruptor');
            }
        }
    } else {
        // Si no es admin con permiso, ocultar el interruptor
        if (permisoContainer) {
            permisoContainer.style.display = 'none';
            if (!esAdminConPermisoPlataforma) {
                console.log('❌ No es admin con permiso, ocultando interruptor');
            } else {
                console.log('❌ No hay plataforma seleccionada, ocultando interruptor');
            }
        }
    }
}

// Función para convertir un select en buscable SIN expandir las opciones
function convertirSelectEnBuscable(selectElement, placeholderText) {
    if (selectElement.parentNode.classList.contains('select-buscable-container')) {
        return;
    }

    const container = document.createElement('div');
    container.className = 'select-buscable-container';
    container.style.position = 'relative';

    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'form-control select-search-input';
    searchInput.placeholder = placeholderText;
    searchInput.style.marginBottom = '0';

    selectElement.style.display = 'none';
    selectElement.style.position = 'absolute';
    selectElement.style.opacity = '0';
    selectElement.style.zIndex = '-1';

    const selectedDisplay = document.createElement('div');
    selectedDisplay.className = 'form-control selected-option-display';
    selectedDisplay.style.cursor = 'pointer';
    selectedDisplay.style.backgroundColor = 'white';
    selectedDisplay.style.minHeight = '38px';
    selectedDisplay.style.display = 'flex';
    selectedDisplay.style.alignItems = 'center';
    selectedDisplay.style.padding = '0.375rem 0.75rem';

    const dropdownIcon = document.createElement('span');
    dropdownIcon.innerHTML = '▼';
    dropdownIcon.style.marginLeft = 'auto';
    dropdownIcon.style.fontSize = '0.8em';
    dropdownIcon.style.color = '#6c757d';

    selectedDisplay.appendChild(dropdownIcon);

    const optionsContainer = document.createElement('div');
    optionsContainer.className = 'options-container';
    optionsContainer.style.display = 'none';
    optionsContainer.style.position = 'absolute';
    optionsContainer.style.top = '100%';
    optionsContainer.style.left = '0';
    optionsContainer.style.right = '0';
    optionsContainer.style.backgroundColor = 'white';
    optionsContainer.style.border = '1px solid #dee2e6';
    optionsContainer.style.borderTop = 'none';
    optionsContainer.style.borderRadius = '0 0 0.375rem 0.375rem';
    optionsContainer.style.maxHeight = '200px';
    optionsContainer.style.overflowY = 'auto';
    optionsContainer.style.zIndex = '1000';
    optionsContainer.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';

    selectElement.parentNode.insertBefore(container, selectElement);
    container.appendChild(searchInput);
    container.appendChild(selectedDisplay);
    container.appendChild(optionsContainer);
    container.appendChild(selectElement);

    let isOpen = false;

    function updateSelectedDisplay() {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        if (selectedOption && selectedOption.value && selectedOption.value !== "") {
            selectedDisplay.textContent = selectedOption.textContent;
            selectedDisplay.appendChild(dropdownIcon);
            searchInput.value = '';
        } else {
            selectedDisplay.textContent = 'Seleccionar...';
            selectedDisplay.appendChild(dropdownIcon);
            searchInput.value = '';
        }
        filterOptions();
    }

    function filterOptions() {
        const searchTerm = searchInput.value.toLowerCase();
        const options = selectElement.options;
        optionsContainer.innerHTML = '';

        let hasVisibleOptions = false;

        for (let i = 0; i < options.length; i++) {
            const option = options[i];
            const text = option.textContent.toLowerCase();

            if (option.value === '' || text.includes(searchTerm)) {
                const optionDiv = document.createElement('div');
                optionDiv.className = 'option-item';
                optionDiv.textContent = option.textContent;
                optionDiv.style.padding = '8px 12px';
                optionDiv.style.cursor = 'pointer';
                optionDiv.style.borderBottom = '1px solid #f8f9fa';

                if (searchTerm && text.includes(searchTerm)) {
                    const regex = new RegExp(`(${searchTerm})`, 'gi');
                    optionDiv.innerHTML = option.textContent.replace(regex, '<span class="highlight-match">$1</span>');
                }

                optionDiv.addEventListener('click', function () {
                    selectElement.value = option.value;
                    updateSelectedDisplay();
                    closeOptions();
                    searchInput.value = '';
                    
                    // Si es el select de plataforma, mostrar/ocultar interruptor
                    if (selectElement.id === 'modal-plataforma') {
                        mostrarOcultarInterruptorPermiso(option.value);
                    }
                });

                optionDiv.addEventListener('mouseenter', function () {
                    this.style.backgroundColor = '#f8f9fa';
                });

                optionDiv.addEventListener('mouseleave', function () {
                    this.style.backgroundColor = 'white';
                });

                optionsContainer.appendChild(optionDiv);
                hasVisibleOptions = true;
            }
        }

        if (!hasVisibleOptions) {
            const noResults = document.createElement('div');
            noResults.className = 'option-item';
            noResults.textContent = 'No se encontraron resultados';
            noResults.style.padding = '8px 12px';
            noResults.style.color = '#6c757d';
            noResults.style.fontStyle = 'italic';
            optionsContainer.appendChild(noResults);
        }
    }

    function openOptions() {
        isOpen = true;
        optionsContainer.style.display = 'block';
        selectedDisplay.style.borderRadius = '0.375rem 0.375rem 0 0';
        selectedDisplay.style.borderColor = '#86b7fe';
        searchInput.focus();
    }

    function closeOptions() {
        isOpen = false;
        optionsContainer.style.display = 'none';
        selectedDisplay.style.borderRadius = '0.375rem';
        selectedDisplay.style.borderColor = '#dee2e6';
    }

    selectedDisplay.addEventListener('click', function (e) {
        e.stopPropagation();
        if (isOpen) {
            closeOptions();
        } else {
            openOptions();
            filterOptions();
        }
    });

    searchInput.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!isOpen) {
            openOptions();
        }
        filterOptions();
    });

    searchInput.addEventListener('input', function () {
        if (!isOpen) {
            openOptions();
        }
        filterOptions();
    });

    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeOptions();
            searchInput.blur();
        } else if (e.key === 'Enter') {
            const firstOption = optionsContainer.querySelector('.option-item:not([style*="italic"])');
            if (firstOption) {
                firstOption.click();
            }
        }
    });

    document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) {
            closeOptions();
        }
    });

    updateSelectedDisplay();

    selectElement.addEventListener('change', function() {
        updateSelectedDisplay();
        // Si es el select de plataforma, mostrar/ocultar interruptor
        if (this.id === 'modal-plataforma') {
            mostrarOcultarInterruptorPermiso(this.value);
        }
    });
}

// Inicializar la búsqueda en selects
function inicializarBusquedaSelects() {
    const selects = [
        { id: 'modal-liner', placeholder: 'Buscar liner...' },
        { id: 'modal-closer1', placeholder: 'Buscar closer...' },
        { id: 'modal-closer2', placeholder: 'Buscar closer...' },
        { id: 'modal-fullstack', placeholder: 'Buscar front...' },
        { id: 'modal-plataforma', placeholder: 'Buscar plataforma...' }
    ];

    selects.forEach(selectInfo => {
        const selectElement = document.getElementById(selectInfo.id);
        if (selectElement) {
            const existingContainer = selectElement.parentNode;
            if (existingContainer.classList.contains('select-buscable-container')) {
                existingContainer.parentNode.insertBefore(selectElement, existingContainer);
                existingContainer.remove();
            }

            convertirSelectEnBuscable(selectElement, selectInfo.placeholder);
        }
    });
}

// Función para actualizar el nombre de la sala visible para usuarios no admin
function actualizarNombreSalaVisible() {
    if (rolUsuarioGlobal !== 'admin') {
        const salaSelect = document.getElementById('modal-sala');
        const salaNombreSpan = document.getElementById('sala-actual-nombre');

        if (salaSelect && salaNombreSpan) {
            const selectedOption = salaSelect.options[salaSelect.selectedIndex];
            const salaNombre = selectedOption ? selectedOption.textContent : 'No seleccionada';
            salaNombreSpan.textContent = salaNombre;
            salaNombreSpan.style.fontWeight = 'bold';
        }
    }
}

// Nueva función para actualizar todos los selects buscables
function actualizarSelectsBuscable() {
    const selects = [
        { id: 'modal-liner', placeholder: 'Buscar liner...' },
        { id: 'modal-closer1', placeholder: 'Buscar closer...' },
        { id: 'modal-closer2', placeholder: 'Buscar closer...' },
        { id: 'modal-fullstack', placeholder: 'Buscar front...' },
        { id: 'modal-plataforma', placeholder: 'Buscar plataforma...' }
    ];

    selects.forEach(selectInfo => {
        const selectElement = document.getElementById(selectInfo.id);
        if (selectElement) {
            const existingContainer = selectElement.parentNode;
            if (existingContainer.classList.contains('select-buscable-container')) {
                existingContainer.parentNode.insertBefore(selectElement, existingContainer);
                existingContainer.remove();
            }

            convertirSelectEnBuscable(selectElement, selectInfo.placeholder);
        }
    });
}

// Función para agregar mensaje informativo en el modal
function agregarMensajeModalTodosUsuarios() {
    const modalBody = document.querySelector('.modal-body');
    if (modalBody && !document.getElementById('info-modal-todos-usuarios')) {
        const infoDiv = document.createElement('div');
        infoDiv.id = 'info-modal-todos-usuarios';
        infoDiv.className = 'alert alert-info alert-sm mb-3';
        infoDiv.innerHTML = `
            <i class="bi bi-info-circle"></i>
            <strong>Nota:</strong> En este modal puedes ver y asignar <strong>TODOS los usuarios</strong> del sistema, 
            no solo los de la sala actual.
        `;
        
        // Insertar después del campo de sala
        const salaField = document.querySelector('#modal-sala').closest('.mb-3');
        if (salaField) {
            salaField.parentNode.insertBefore(infoDiv, salaField.nextSibling);
        } else {
            // Si no encuentra el campo de sala, insertar al inicio del modal
            const form = document.getElementById('formActualizarVenta');
            if (form) {
                form.insertBefore(infoDiv, form.firstChild);
            }
        }
    }
}

// Función mejorada para mostrar modal de venta - MODIFICADA
function mostrarModalVenta(venta) {
    console.log('Mostrando modal para venta:', venta);
    console.log('✅ Cargando TODOS los usuarios para el modal');
    
    const fecha = new Date(venta.fecha);
    const fechaFormateada = fecha.toISOString().split('T')[0];
    
    ultimaPlataformaSPEI = null;
    ultimaPlataformaTDC = null;
    metodoPagoActual = null;
    
    valoresVentaNormal = {
        liner_id: '',
        closer1_id: '',
        closer2_id: ''
    };
    
    valorFullStack = {
        fullstack_id: ''
    };
    
    document.getElementById('venta-id').value = venta.id;
    document.getElementById('modal-id-texto').textContent = venta.id;
    document.getElementById('modal-fecha').value = fechaFormateada;
    
    const precioNumerico = parseFloat(venta.precio.replace('$', '').replace(/,/g, ''));
    document.getElementById('modal-precio').value = precioNumerico.toFixed(2);
    
    document.getElementById('modal-cliente').value = venta.cliente || '';
    document.getElementById('modal-apepat').value = venta.apepat || '';
    document.getElementById('modal-apemat').value = venta.apemat || '';
    document.getElementById('modal-celular').value = venta.celular || '';
    document.getElementById('modal-correo').value = venta.correo || '';
    document.getElementById('modal-producto').value = venta.producto || '';
    
    const hiddenSalaField = document.getElementById('modal-sala-hidden');
    if (hiddenSalaField && venta.sala_id) {
        hiddenSalaField.value = venta.sala_id;
        console.log('Sala ID establecida en campo oculto:', venta.sala_id);
    }
    
    if (rolUsuarioGlobal === 'admin') {
        const salaSelect = document.getElementById('modal-sala');
        if (salaSelect && venta.sala_id) {
            salaSelect.value = venta.sala_id;
            console.log('Sala seleccionada en select (admin):', venta.sala_id);
        }
    } else {
        const salaNombreSpan = document.getElementById('sala-actual-nombre');
        if (salaNombreSpan) {
            if (venta.sala_nombre && venta.sala_nombre !== 'N/A') {
                salaNombreSpan.textContent = venta.sala_nombre;
                console.log('Nombre de sala mostrado (desde datos):', venta.sala_nombre);
            } else {
                salaNombreSpan.textContent = 'Sala ' + (venta.sala_id || 'No asignada');
            }
            salaNombreSpan.style.color = '#198754';
            salaNombreSpan.style.fontWeight = 'bold';
        }
    }
    
    // MODIFICACIÓN: Ya tenemos todos los usuarios cargados desde el inicio
    // Solo poblamos los selects con los usuarios ya disponibles
    poblarSelectUsuariosConBusqueda();
    poblarSelectFullStack();
    
    // Establecer valores de usuarios seleccionados
    setTimeout(() => {
        if (venta.liner_id) {
            document.getElementById('modal-liner').value = venta.liner_id;
            valoresVentaNormal.liner_id = venta.liner_id;
        }
        
        if (venta.closer1_id) {
            document.getElementById('modal-closer1').value = venta.closer1_id;
            valoresVentaNormal.closer1_id = venta.closer1_id;
        }
        
        if (venta.closer2_id) {
            document.getElementById('modal-closer2').value = venta.closer2_id;
            valoresVentaNormal.closer2_id = venta.closer2_id;
        }
        
        if (venta.full_stack_id) {
            document.getElementById('modal-fullstack').value = venta.full_stack_id;
            valorFullStack.fullstack_id = venta.full_stack_id;
        }
    }, 100);
    
    const tipoClienteSelect = document.getElementById('tipoCliente');
    if (tipoClienteSelect) {
        tipoClienteSelect.value = venta.tipo_venta || '';
    }
    
    if (rolUsuarioGlobal !== 'admin' && venta.sala_id !== salaUsuarioIdGlobal) {
        alert('Esta venta pertenece a otra sala. Solo puedes ver información.');
        const form = document.getElementById('formActualizarVenta');
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            if (input.id !== 'venta-id') {
                input.disabled = true;
            }
        });
        
        document.getElementById('btnActualizar').style.display = 'none';
    } else {
        const form = document.getElementById('formActualizarVenta');
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.disabled = false;
        });
        
        document.getElementById('btnActualizar').style.display = 'block';
    }
    
    setTimeout(() => {
        const metodoPagoDB = venta.metodo_pago_plataforma;
        
        document.getElementById('modal-pagoSpei').checked = false;
        document.getElementById('modal-pagoTarjeta').checked = false;
        
        let metodoSeleccionado = null;
        if (metodoPagoDB === 'SPEI/EFECTIVO') {
            document.getElementById('modal-pagoSpei').checked = true;
            metodoPagoActual = 'SPEI';
            metodoSeleccionado = 'SPEI';
        } else if (metodoPagoDB === 'TDC') {
            document.getElementById('modal-pagoTarjeta').checked = true;
            metodoPagoActual = 'TDC';
            metodoSeleccionado = 'TDC';
        }
        
        const tieneVentaNormal = venta.liner_id || venta.closer1_id || venta.closer2_id;
        const tieneFullStack = venta.full_stack_id;
        
        let tipoVendedorSeleccionado = 'normal';
        
        if (tieneFullStack) {
            tipoVendedorSeleccionado = 'fullstack';
            document.getElementById('modal-tipoFullStack').checked = true;
        } else {
            document.getElementById('modal-tipoNormal').checked = true;
        }
        
        console.log('Tipo de vendedor detectado:', {
            tieneVentaNormal,
            tieneFullStack,
            tipoVendedorSeleccionado
        });
        
        if (tipoVendedorSeleccionado === 'normal') {
            document.getElementById('modal-tipoNormal').checked = true;
            setTimeout(() => {
                manejarCambioTipoVentaMejorada();
            }, 50);
        } else if (tipoVendedorSeleccionado === 'fullstack') {
            document.getElementById('modal-tipoFullStack').checked = true;
            setTimeout(() => {
                manejarCambioTipoVentaMejorada();
            }, 50);
        }
        
        if (metodoPagoDB) {
            document.getElementById('modal-plataformaDiv').style.display = 'block';
        } else {
            document.getElementById('modal-plataformaDiv').style.display = 'none';
        }
        
        const plataformaSelect = document.getElementById('modal-plataforma');
        
        if (plataformaSelect && metodoSeleccionado) {
            const metodoPagoDB = metodoSeleccionado === 'SPEI' ? "SPEI/EFECTIVO" : "TDC";
            const plataformasFiltradas = plataformasData.filter(p => 
                p.metodo_pago === metodoPagoDB && p.permiso_plataforma == 1
            );
            
            plataformaSelect.innerHTML = '<option value="">Seleccionar</option>';
            
            plataformasFiltradas.forEach(plataforma => {
                const option = document.createElement('option');
                option.value = plataforma.id;
                option.textContent = `${plataforma.nombre_ancronimo} (${plataforma.plataformaspei})`;
                option.dataset.permiso = plataforma.permiso_plataforma;
                plataformaSelect.appendChild(option);
            });
            
            if (venta.plataforma_id) {
                plataformaSelect.value = venta.plataforma_id;
                
                // Mostrar interruptor si es admin con permiso
                mostrarOcultarInterruptorPermiso(venta.plataforma_id);
                
                if (metodoSeleccionado === 'SPEI') {
                    ultimaPlataformaSPEI = venta.plataforma_id;
                } else if (metodoSeleccionado === 'TDC') {
                    ultimaPlataformaTDC = venta.plataforma_id;
                }
            } else {
                plataformaSelect.value = "";
                mostrarOcultarInterruptorPermiso("");
            }
            
            // Configurar evento change para el select
            plataformaSelect.addEventListener('change', function() {
                const selectedId = this.value;
                mostrarOcultarInterruptorPermiso(selectedId);
            });
        }
    }, 300);
    
    const ventaModal = new bootstrap.Modal(document.getElementById('ventaModal'));
    ventaModal.show();
    
    // Agregar mensaje informativo
    agregarMensajeModalTodosUsuarios();
    
    setTimeout(() => {
        actualizarSelectsBuscable();
    }, 500);
}

// Función para actualizar el permiso de una plataforma desde el modal
function actualizarPermisoPlataformaModal(plataformaId, permiso) {
    if (!esAdminConPermisoPlataforma) {
        alert("No tienes permisos para modificar los permisos de plataforma");
        return;
    }
    
    const data = {
        action: 'toggle_permiso',
        id: plataformaId,
        permiso_plataforma: permiso ? 1 : 0
    };
    
    fetch("guardar_plataforma.php", {
        method: "POST",
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            
            const plataformaIndex = plataformasData.findIndex(p => p.id == plataformaId);
            if (plataformaIndex !== -1) {
                plataformasData[plataformaIndex].permiso_plataforma = permiso ? 1 : 0;
            }
            
            const metodoPagoDB = metodoPagoActual === 'SPEI' ? "SPEI/EFECTIVO" : "TDC";
            const plataformaSelect = document.getElementById('modal-plataforma');
            
            if (!permiso) {
                // Si se desactivó el permiso
                if (plataformaSelect && plataformaSelect.value == plataformaId) {
                    plataformaSelect.value = "";
                    
                    const permisoContainer = document.getElementById('modal-plataformaPermisoContainer');
                    if (permisoContainer) {
                        permisoContainer.style.display = 'none';
                    }
                    
                    alert("La plataforma ha sido restringida y eliminada de la selección.");
                }
                
                // Actualizar la lista de plataformas (quitar la que se restringió)
                const plataformasFiltradas = plataformasData.filter(p => 
                    p.metodo_pago === metodoPagoDB && p.permiso_plataforma == 1
                );
                
                plataformaSelect.innerHTML = '<option value="">Seleccionar</option>';
                plataformasFiltradas.forEach(plataforma => {
                    const option = document.createElement('option');
                    option.value = plataforma.id;
                    option.textContent = `${plataforma.nombre_ancronimo} (${plataforma.plataformaspei})`;
                    option.dataset.permiso = plataforma.permiso_plataforma;
                    plataformaSelect.appendChild(option);
                });
            } else {
                // Si se activó el permiso, actualizar el estado del switch
                const toggleSwitch = document.getElementById('modal-togglePermisoPlataforma');
                if (toggleSwitch) {
                    toggleSwitch.checked = true;
                }
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error al actualizar permiso:', error);
        alert("Error al actualizar el permiso");
    });
}

// Configurar eventos para el interruptor en el modal
function setupPermisoSwitchModalEvents() {
    document.addEventListener('change', function(e) {
        if (e.target && e.target.id === 'modal-togglePermisoPlataforma') {
            const plataformaSelect = document.getElementById('modal-plataforma');
            const plataformaId = plataformaSelect ? plataformaSelect.value : null;
            
            if (plataformaId) {
                const nuevoPermiso = e.target.checked;
                actualizarPermisoPlataformaModal(plataformaId, nuevoPermiso);
            }
        }
    });
}

// Actualizar venta
function actualizarVenta() {
    const tipoFullStack = document.getElementById('modal-tipoFullStack').checked;

    if (tipoFullStack) {
        const fullstackId = document.getElementById('modal-fullstack').value;
        if (!fullstackId) {
            alert('Para venta Full Stack debe seleccionar un Front');
            return;
        }
    } else {
        const linerId = document.getElementById('modal-liner').value;
        if (!linerId) {
            alert('Para venta Normal debe seleccionar un Liner');
            return;
        }
    }

    const form = document.getElementById('formActualizarVenta');
    const formData = new FormData(form);

    const btn = document.getElementById('btnActualizar');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Actualizando...';
    btn.disabled = true;

    fetch('actualizar_venta.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.message.includes('sin cambios')) {
                    alert('✅ La venta ya estaba actualizada. No se realizaron cambios.');
                } else {
                    alert(data.message);
                }
                window.location.href = data.redirect;
            } else {
                alert('Error: ' + data.message);
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al actualizar la venta');
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
}

// Toggle filters
function toggleFilters() {
    const filterContent = document.getElementById('filterContent');
    if (filterContent) {
        filterContent.classList.toggle('show');
    }
}

// Configurar botón limpiar búsqueda
function configurarBotonLimpiar() {
    const buscarInput = document.getElementById('buscar');
    const clearButton = document.getElementById('clear-search');

    if (buscarInput && clearButton) {
        buscarInput.addEventListener('input', function () {
            clearButton.style.display = this.value ? 'block' : 'none';
        });

        clearButton.addEventListener('click', function () {
            buscarInput.value = '';
            clearButton.style.display = 'none';
            buscarVentas('');
            actualizarEnlaceExportacion();
        });
    }
}

// Actualizar contador de ventas
function actualizarContadorVentas(cantidad, texto = 'Resultados') {
    const contador = document.getElementById('contador-ventas');
    if (contador) {
        let textoBase = '';

        if (busquedaActual.length > 0) {
            textoBase = 'Resultados búsqueda';
        } else if (esAdmin) {
            if (tieneAccesoTodasSalas) {
                const salaSelect = document.getElementById('sala_id');
                if (salaSelect && salaSelect.value && salaSelect.value !== '0') {
                    textoBase = 'Ventas sala';
                } else {
                    textoBase = 'Total general';
                }
            } else {
                textoBase = 'Ventas salas permitidas';
            }
        } else {
            textoBase = 'Mes Actual';
        }

        contador.textContent = `${textoBase}: ${cantidad}`;

        if (busquedaActual.length > 0) {
            contador.classList.remove('bg-light');
            contador.classList.add('bg-info', 'text-white');

            setTimeout(() => {
                contador.classList.remove('bg-info', 'text-white');
                contador.classList.add('bg-light');
            }, 1000);
        } else {
            contador.classList.remove('bg-info', 'text-white');
            contador.classList.add('bg-light');
        }
    }
}

// Resaltar texto en resultados de búsqueda
function resaltarTexto(texto, busqueda) {
    if (!busqueda) return texto;

    const textoStr = String(texto);
    const regex = new RegExp(`(${busqueda.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return textoStr.replace(regex, '<span class="highlight">$1</span>');
}

// Actualizar enlace de exportación
function actualizarEnlaceExportacion() {
    const buscarInput = document.getElementById('buscar');
    const plataformaCheckboxes = document.querySelectorAll('input.plataforma-checkbox:checked');
    const tipoVentaSelect = document.getElementById('tipo_venta');
    const salaSelect = document.getElementById('sala_id');
    const exportarExcelBtn = document.getElementById('exportar-excel-btn');
    const exportarPdfBtn = document.getElementById('exportar-pdf-btn');

    if (exportarExcelBtn && buscarInput) {
        const url = new URL(exportarExcelBtn.href);
        url.searchParams.set('busqueda', buscarInput.value);

        url.searchParams.delete('plataforma_id[]');
        const selectedIds = Array.from(plataformaCheckboxes).map(cb => cb.value);
        selectedIds.forEach(id => {
            url.searchParams.append('plataforma_id[]', id);
        });

        if (tipoVentaSelect && tipoVentaSelect.value) {
            url.searchParams.set('tipo_venta', tipoVentaSelect.value);
        } else {
            url.searchParams.delete('tipo_venta');
        }

        if (esAdmin && salaSelect && salaSelect.value) {
            url.searchParams.set('sala_id', salaSelect.value);
        } else {
            url.searchParams.delete('sala_id');
        }

        exportarExcelBtn.href = url.toString();
    }

    if (exportarPdfBtn && buscarInput) {
        const url = new URL(exportarPdfBtn.href);
        url.searchParams.set('busqueda', buscarInput.value);

        url.searchParams.delete('plataforma_id[]');
        const selectedIds = Array.from(plataformaCheckboxes).map(cb => cb.value);
        selectedIds.forEach(id => {
            url.searchParams.append('plataforma_id[]', id);
        });

        if (tipoVentaSelect && tipoVentaSelect.value) {
            url.searchParams.set('tipo_venta', tipoVentaSelect.value);
        } else {
            url.searchParams.delete('tipo_venta');
        }

        if (esAdmin && salaSelect && salaSelect.value) {
            url.searchParams.set('sala_id', salaSelect.value);
        } else {
            url.searchParams.delete('sala_id');
        }

        exportarPdfBtn.href = url.toString();
    }
}

// Agregar estilos CSS para los selects buscables
function agregarEstilosSelectsBuscable() {
    const styles = `
        .select-buscable-container {
            position: relative;
        }
        
        .select-search-input {
            border-radius: 0.375rem;
            margin-bottom: 0;
        }
        
        .selected-option-display {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            cursor: pointer;
            background-color: white;
            min-height: 38px;
            display: flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .selected-option-display:hover {
            border-color: #86b7fe;
        }
        
        .options-container {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: white;
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 0.375rem 0.375rem;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .option-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f8f9fa;
            transition: background-color 0.15s ease;
        }
        
        .option-item:hover {
            background-color: #f8f9fa;
        }
        
        .option-item:last-child {
            border-bottom: none;
        }
        
        .highlight-match {
            background-color: #fff3cd;
            font-weight: bold;
            padding: 1px 2px;
            border-radius: 2px;
        }
        
        .select-buscable-container:focus-within .selected-option-display {
            border-color: #86b7fe;
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        
        .select-buscable-container select {
            display: none !important;
        }
        
        .highlight {
            background-color: yellow;
            font-weight: bold;
            padding: 1px 2px;
            border-radius: 2px;
        }
        
        .form-control:disabled,
        .form-select:disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
            opacity: 0.7;
        }
        
        #info-modal-todos-usuarios {
            background-color: #e7f1ff;
            border-color: #b6d4fe;
            color: #084298;
        }
    `;

    const styleSheet = document.createElement('style');
    styleSheet.textContent = styles;
    document.head.appendChild(styleSheet);
}

// Llamar a la función de estilos cuando se cargue el documento
document.addEventListener('DOMContentLoaded', function () {
    agregarEstilosSelectsBuscable();
});