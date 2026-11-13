class PlataformaManager {
    constructor() {
        this.searchTimeout = null;
        this.currentSearch = plataformasConfig.busqueda;
        this.currentPage = plataformasConfig.pagina;
        this.showInactive = plataformasConfig.inactivos;
        this.plataformaAProcesar = null;
        this.init();
    }

    init() {
        this.bindEvents();
        this.reasignarEventos();
    }

    bindEvents() {
        // Búsqueda
        $('#searchInput').on('input', () => this.handleSearch());
        $('#clearSearch').on('click', () => this.clearSearch());
        $('#showInactive').on('change', () => this.toggleInactive());

        // Modal nueva plataforma
        $('[data-bs-target="#modalNuevaPlataforma"]').click(() => this.resetForm());

        // Acciones de plataforma
        $('#btnGuardarPlataforma').click(() => this.guardarPlataforma());
        $('#btnConfirmarDesactivar').click(() => this.confirmarAccion('deactivate'));
        $('#btnConfirmarActivar').click(() => this.confirmarAccion('activate'));
    }

    handleSearch() {
        this.currentSearch = $('#searchInput').val().trim();
        $('#clearSearch').toggle(this.currentSearch !== '');
        this.debouncedSearch();
    }

    debouncedSearch() {
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => this.realizarBusqueda(), 500);
    }

    clearSearch() {
        $('#searchInput').val('').focus();
        this.currentSearch = '';
        $('#clearSearch').hide();
        this.realizarBusqueda();
    }

    toggleInactive() {
        this.showInactive = $('#showInactive').is(':checked');
        this.currentPage = 1;
        this.realizarBusqueda();
    }

    async realizarBusqueda() {
        try {
            const params = new URLSearchParams({
                busqueda: this.currentSearch,
                pagina: this.currentPage,
                inactivos: this.showInactive ? 1 : 0
            });

            const response = await fetch(`${window.location.pathname}?${params}`);
            const html = await response.text();

            this.actualizarVista(html);
            this.actualizarURL(params);

        } catch (error) {
            this.mostrarAlerta('Error al realizar la búsqueda: ' + error, 'danger');
        }
    }

    actualizarVista(html) {
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;

        ['#tableContainer', '#paginationContainer', '#resultsInfo'].forEach(selector => {
            const element = tempDiv.querySelector(selector);
            if (element) $(selector).html(element.innerHTML);
        });

        this.reasignarEventos();
    }

    actualizarURL(params) {
        const newUrl = `${window.location.pathname}?${params}`;
        window.history.replaceState({}, '', newUrl);
    }

    reasignarEventos() {
        // Eliminar eventos anteriores
        $('.edit-platform').off('click');
        $('.deactivate-platform').off('click');
        $('.activate-platform').off('click');
        $('.toggle-permiso').off('change');

        // Editar plataforma
        $('.edit-platform').on('click', (e) => {
            const plataformaData = e.currentTarget.dataset.plataforma;
            this.abrirModalEdicion(plataformaData);
        });

        // Desactivar
        $('.deactivate-platform').on('click', (e) => {
            const btn = e.currentTarget;
            this.plataformaAProcesar = {
                id: btn.dataset.id,
                name: btn.dataset.name
            };

            $('#plataformaNombreDesactivar').text(this.plataformaAProcesar.name);
            $('#modalConfirmarDesactivar').modal('show');
        });

        // Activar
        $('.activate-platform').on('click', (e) => {
            const btn = e.currentTarget;
            this.plataformaAProcesar = {
                id: btn.dataset.id,
                name: btn.dataset.name
            };

            $('#plataformaNombreActivar').text(this.plataformaAProcesar.name);
            $('#modalConfirmarActivar').modal('show');
        });

        // Toggle permiso
        if ($('.toggle-permiso').length > 0) {
            $('.toggle-permiso').on('change', (e) => {
                const checkbox = e.currentTarget;
                const plataformaId = checkbox.dataset.id;
                const plataformaName = checkbox.dataset.name;
                const permisoValue = checkbox.checked ? 1 : 0;

                if (confirm(`¿Está seguro de ${permisoValue ? 'permitir' : 'restringir'} la plataforma "${plataformaName}"?`)) {
                    this.togglePermiso(plataformaId, permisoValue);
                } else {
                    checkbox.checked = !checkbox.checked;
                }
            });
        }
    }

    abrirModalEdicion(plataformaJson) {
        try {
            const plataforma = JSON.parse(plataformaJson);

            $('#plataformaId').val(plataforma.id);
            $('#actionType').val('update');
            $('#metodoPago').val(plataforma.metodo_pago);
            $('#plataformaSpei').val(plataforma.plataformaspei);
            $('#nombreAcronimo').val(plataforma.nombre_ancronimo);
            $('#porcentFront').val(plataforma.porcent_front);
            $('#porcentLinea').val(plataforma.porcent_linea);
            $('#porcentCierre').val(plataforma.porcent_cierre);

            const permisoSwitch = $('#permisoPlataforma');
            if (permisoSwitch.length > 0) {
                permisoSwitch.prop('checked', plataforma.permiso_plataforma == 1);
            }

            $('#modalTitle').text('Editar Plataforma');
            $('#modalNuevaPlataforma').modal('show');

        } catch (error) {
            console.error('Error parsing platform data:', error);
            this.mostrarAlerta('Error al cargar los datos de la plataforma', 'danger');
        }
    }

    resetForm() {
        $('#formPlataforma')[0].reset();
        $('#plataformaId').val('');
        $('#actionType').val('create');
        $('#modalTitle').text('Agregar Nueva Plataforma');

        const permisoSwitch = $('#permisoPlataforma');
        if (permisoSwitch.length > 0) {
            permisoSwitch.prop('checked', true);
        }
    }

    async confirmarAccion(action) {
        if (!this.plataformaAProcesar) {
            console.error('No platform selected for action:', action);
            return;
        }

        try {
            const response = await fetch('guardar_plataforma.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: action, // Enviar la acción directamente
                    id: this.plataformaAProcesar.id
                })
            });

            const data = await response.json();

            if (data.success) {
                this.mostrarAlerta(data.message, 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                this.mostrarAlerta(data.message || 'Error desconocido', 'danger');
            }

        } catch (error) {
            console.error('Error:', error);
            this.mostrarAlerta(`Error al procesar la plataforma: ${error.message}`, 'danger');
        } finally {
            const modalId = action === 'deactivate' ?
                'modalConfirmarDesactivar' : 'modalConfirmarActivar';
            $(`#${modalId}`).modal('hide');
            this.plataformaAProcesar = null;
        }
    }

    async togglePermiso(plataformaId, permisoValue) {
        try {
            const response = await fetch('guardar_plataforma.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'toggle_permiso',
                    id: plataformaId,
                    permiso_plataforma: permisoValue
                })
            });

            const data = await response.json();

            if (data.success) {
                this.mostrarAlerta(data.message, 'success');

                const badge = $(`#permiso-${plataformaId}`).siblings('label').find('.badge');
                badge.removeClass('bg-primary bg-secondary')
                    .addClass(permisoValue ? 'bg-primary' : 'bg-secondary')
                    .text(permisoValue ? 'Permitida' : 'Restringida');

            } else {
                this.mostrarAlerta(data.message, 'danger');
                $(`#permiso-${plataformaId}`).prop('checked', !permisoValue);
            }

        } catch (error) {
            console.error('Error al cambiar permiso:', error);
            this.mostrarAlerta('Error al cambiar el permiso: ' + error.message, 'danger');
            $(`#permiso-${plataformaId}`).prop('checked', !permisoValue);
        }
    }

    async guardarPlataforma() {
        const form = $('#formPlataforma')[0];
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const formData = new FormData(form);
        const jsonData = Object.fromEntries(formData.entries());

        // Obtener el tipo de acción
        const actionType = $('#actionType').val();

        // Agregar el action a los datos
        jsonData.action = actionType;

        // Convertir porcentajes a números
        ['porcent_front', 'porcent_linea', 'porcent_cierre'].forEach(key => {
            jsonData[key] = parseFloat(jsonData[key]);
        });

        // Convertir permiso_plataforma a 1 o 0
        if (jsonData.permiso_plataforma !== undefined) {
            jsonData.permiso_plataforma = jsonData.permiso_plataforma === '1' ? 1 : 0;
        }

        try {
            const response = await fetch('guardar_plataforma.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(jsonData)
            });

            const data = await response.json();

            if (data.success) {
                this.mostrarAlerta(data.message, 'success');
                $('#modalNuevaPlataforma').modal('hide');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                this.mostrarAlerta(data.message, 'danger');
            }

        } catch (error) {
            this.mostrarAlerta('Error al guardar la plataforma: ' + error, 'danger');
        }
    }

    mostrarAlerta(mensaje, tipo) {
        const alerta = $('#alertMessage');
        alerta.removeClass().addClass(`alert alert-${tipo} alert-dismissible fade show`).removeClass('d-none');
        $('#alertText').text(mensaje);
        setTimeout(() => alerta.addClass('d-none'), 5000);
    }
}

// Inicializar cuando el documento esté listo
$(document).ready(function() {
    new PlataformaManager();
});
