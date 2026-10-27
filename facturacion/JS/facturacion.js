//facturacion.js
// Variables globales
let plataformasData = [];
let usuariosData = [];

// Función para mostrar alertas
function showAlert(message, type) {
    const alertDiv = document.getElementById("alertMessage");
    const alertText = document.getElementById("alertText");
    
    if (!alertDiv || !alertText) {
        console.error('Elementos de alerta no encontrados');
        return;
    }
    
    alertText.textContent = message;
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.style.display = "block";
    
    setTimeout(() => {
        alertDiv.style.display = "none";
    }, 5000);
}

// Función para alternar entre select e input manual
function toggleManualInput(fieldName) {
    const selectElement = document.getElementById(fieldName);
    if (!selectElement) {
        console.error(`Elemento ${fieldName} no encontrado`);
        return;
    }
    
    const manualContainer = document.getElementById(fieldName + '_manual_container');
    const manualInput = document.getElementById(fieldName + '_manual');
    const toggleLink = selectElement.parentNode.querySelector('.toggle-input-type');

    if (!manualContainer || !manualInput || !toggleLink) {
        console.error(`Elementos para ${fieldName} no encontrados`);
        return;
    }

    if (manualContainer.style.display === 'none' || !manualContainer.style.display) {
        // Cambiar a entrada manual
        selectElement.style.display = 'none';
        selectElement.disabled = true;
        selectElement.removeAttribute('required');
        manualContainer.style.display = 'block';
        toggleLink.innerHTML = '<i class="fas fa-list"></i> Seleccionar de la lista';

        if ($(selectElement).length) {
            $(selectElement).val('').trigger('change');
        }

        if (selectElement.hasAttribute('data-original-required')) {
            manualInput.required = true;
        }
    } else {
        // Volver al select
        selectElement.style.display = 'block';
        selectElement.disabled = false;
        manualContainer.style.display = 'none';
        toggleLink.innerHTML = '<i class="fas fa-edit"></i> Ingresar manualmente';

        if (manualInput) {
            manualInput.value = '';
            manualInput.removeAttribute('required');
        }

        if (selectElement.hasAttribute('data-original-required')) {
            selectElement.required = true;
        }
    }
}

// Función para destruir Select2 de forma segura
function safeDestroySelect2(selector) {
    try {
        const element = $(selector);
        if (element.length && element.hasClass('select2-hidden-accessible')) {
            element.select2('destroy');
        }
    } catch (error) {
        console.warn('Error al destruir Select2:', selector, error);
    }
}

// Función para mostrar/ocultar campos según tipo de venta
function toggleTipoVenta() {
    const tipoVenta = document.querySelector('input[name="tipoVenta"]:checked');
    if (!tipoVenta) return;
    
    const fullStackDiv = document.getElementById("fullStackDiv");
    const linerClosersDiv = document.getElementById("linerClosersDiv");
    const fullstackSelect = document.getElementById("fullstack");
    const fullstackManual = document.getElementById("fullstack_manual");
    const linerSelect = document.getElementById("liner");
    const linerManual = document.getElementById("liner_manual");
    
    if (tipoVenta.value === "fullstack") {
        if (linerClosersDiv) linerClosersDiv.style.display = "none";
        if (fullStackDiv) fullStackDiv.style.display = "block";
        
        if (fullstackSelect) {
            fullstackSelect.setAttribute('data-original-required', 'true');
            if (!fullstackSelect.disabled) {
                fullstackSelect.required = true;
            }
        }
        
        if (fullstackManual && fullstackManual.parentElement && fullstackManual.parentElement.style.display !== 'none') {
            fullstackManual.required = true;
        }
        
        if (linerSelect) {
            linerSelect.required = false;
            linerSelect.removeAttribute('data-original-required');
        }
        if (linerManual) {
            linerManual.required = false;
        }
        
    } else {
        if (linerClosersDiv) linerClosersDiv.style.display = "flex";
        if (fullStackDiv) fullStackDiv.style.display = "none";
        
        if (fullstackSelect) {
            fullstackSelect.value = "";
            safeDestroySelect2('#fullstack');
            if ($('#fullstack').length) {
                $('#fullstack').val('').trigger('change');
            }
            
            fullstackSelect.required = false;
            fullstackSelect.removeAttribute('data-original-required');
        }
        
        if (fullstackManual) {
            fullstackManual.value = "";
            fullstackManual.required = false;
        }
        
        if (linerSelect) {
            linerSelect.setAttribute('data-original-required', 'true');
            if (!linerSelect.disabled) {
                linerSelect.required = true;
            }
        }
        
        if (linerManual && linerManual.parentElement && linerManual.parentElement.style.display !== 'none') {
            linerManual.required = true;
        }
    }
}

// Función para mostrar/ocultar plataforma según método de pago
function togglePlataforma() {
    const metodoPago = document.querySelector('input[name="metodoPago"]:checked');
    if (!metodoPago) return;
    
    const plataformaDiv = document.getElementById("plataformaDiv");
    const plataformaSelect = document.getElementById("plataforma");
    const permisoContainer = document.getElementById('plataformaPermisoContainer');
    
    if (!plataformaSelect) return;
    
    // Ocultar el interruptor inicialmente
    if (permisoContainer) {
        permisoContainer.style.display = 'none';
    }
    
    if (metodoPago.value === "SPEI" || metodoPago.value === "TDC") {
        if (plataformaDiv) plataformaDiv.style.display = "block";
        
        plataformaSelect.innerHTML = "";
        
        const defaultOption = document.createElement("option");
        defaultOption.value = "";
        defaultOption.textContent = "Selecciona una plataforma";
        defaultOption.selected = true;
        defaultOption.disabled = true;
        plataformaSelect.appendChild(defaultOption);
        
        const metodoPagoDB = metodoPago.value === "SPEI" ? "SPEI/EFECTIVO" : "TDC";
        
        // Siempre filtrar solo plataformas con permiso_plataforma = 1
        const plataformasFiltradas = Array.isArray(plataformasData) ? 
            plataformasData.filter(p => {
                // Primero filtrar por método de pago
                if (p.metodo_pago !== metodoPagoDB) return false;
                
                // SOLO mostrar las que tienen permiso_plataforma = 1
                return p.permiso_plataforma == 1;
            }) : [];
        
        if (plataformasFiltradas.length === 0) {
            const noOption = document.createElement("option");
            noOption.value = "";
            noOption.textContent = "No hay plataformas disponibles";
            noOption.disabled = true;
            plataformaSelect.appendChild(noOption);
        } else {
            plataformasFiltradas.forEach(plataforma => {
                const option = document.createElement("option");
                option.value = plataforma.id;
                
                // Solo mostrar el nombre y acrónimo, sin badges de permiso
                option.textContent = `${plataforma.nombre_ancronimo} (${plataforma.plataformaspei})`;
                
                // Agregar atributo para saber el estado del permiso
                option.dataset.permiso = plataforma.permiso_plataforma;
                
                plataformaSelect.appendChild(option);
            });
        }
        
        safeDestroySelect2('#plataforma');
        if ($('#plataforma').length) {
            $('#plataforma').select2({
                theme: 'bootstrap-5',
                placeholder: 'Buscar plataforma...',
                allowClear: true,
                width: '100%'
            });
            
            // Mostrar/ocultar interruptor cuando cambia la selección
            $('#plataforma').on('select2:select', function(e) {
                const selectedId = e.params.data.id;
                if (selectedId) {
                    const plataforma = plataformasData.find(p => p.id == selectedId);
                    const toggleSwitch = document.getElementById('togglePermisoPlataforma');
                    
                    // Mostrar interruptor solo para plataformas con permiso=1
                    if (plataforma && toggleSwitch && esAdminConPermisoPlataforma) {
                        toggleSwitch.checked = plataforma.permiso_plataforma == 1;
                        
                        // Mostrar el contenedor solo si es admin con permiso
                        if (permisoContainer) {
                            permisoContainer.style.display = 'block';
                        }
                    } else if (permisoContainer) {
                        permisoContainer.style.display = 'none';
                    }
                } else {
                    if (permisoContainer) permisoContainer.style.display = 'none';
                }
            });
            
            // Ocultar cuando se limpia la selección
            $('#plataforma').on('select2:clear', function() {
                if (permisoContainer) permisoContainer.style.display = 'none';
            });
            
            // Inicializar si ya hay una selección
            if (plataformaSelect.value && esAdminConPermisoPlataforma) {
                const plataforma = plataformasData.find(p => p.id == plataformaSelect.value);
                const toggleSwitch = document.getElementById('togglePermisoPlataforma');
                if (plataforma && toggleSwitch) {
                    toggleSwitch.checked = plataforma.permiso_plataforma == 1;
                    if (permisoContainer) permisoContainer.style.display = 'block';
                }
            }
        }
        
        plataformaSelect.required = true;
    } else {
        if (plataformaDiv) plataformaDiv.style.display = "none";
        plataformaSelect.value = "";
        safeDestroySelect2('#plataforma');
        if ($('#plataforma').length) {
            $('#plataforma').val('').trigger('change');
        }
        plataformaSelect.required = false;
        if (permisoContainer) permisoContainer.style.display = 'none';
    }
}

// Función para parsear respuesta JSON
function parseJSONResponse(response) {
    return response.text().then(text => {
        const cleanedText = text.trim();
        
        console.log('Respuesta del servidor (primeros 500 chars):', cleanedText.substring(0, 500));
        
        if (!cleanedText) {
            throw new Error('El servidor devolvió una respuesta vacía (Error 500)');
        }
        
        if (cleanedText.startsWith('<!DOCTYPE') || 
            cleanedText.startsWith('<html') || 
            cleanedText.includes('<br />') ||
            cleanedText.includes('<font') ||
            cleanedText.includes('xdebug-error') ||
            cleanedText.includes('PHP') ||
            cleanedText.startsWith('<br')) {
            
            console.error('Respuesta HTML recibida (error PHP):', cleanedText.substring(0, 500));
            throw new Error('Error interno del servidor. Por favor, revise los logs del servidor.');
        }
        
        try {
            const parsed = JSON.parse(cleanedText);
            console.log('JSON parseado exitosamente:', parsed);
            return parsed;
        } catch (e) {
            console.error('Error parsing JSON:', e);
            console.error('Respuesta completa:', cleanedText);
            
            if (cleanedText.includes('Internal Server Error') || cleanedText.includes('500')) {
                throw new Error('Error interno del servidor (500).');
            } else if (cleanedText.includes('Fatal error') || cleanedText.includes('Parse error')) {
                throw new Error('Error de PHP en el servidor.');
            } else if (cleanedText.includes('SQLSTATE') || cleanedText.includes('SQL syntax')) {
                throw new Error('Error de base de datos.');
            } else {
                throw new Error('Respuesta inválida del servidor: ' + cleanedText.substring(0, 100));
            }
        }
    });
}

// Función para validar el formulario antes del envío
function validarFormulario() {
    const metodoPago = document.querySelector('input[name="metodoPago"]:checked');
    const tipoVenta = document.querySelector('input[name="tipoVenta"]:checked');
    const tipoCliente = document.getElementById("tipoCliente");
    const plataformaSelect = document.getElementById("plataforma");
    const linerSelect = document.getElementById("liner");
    const linerManual = document.getElementById("liner_manual");
    const fullstackSelect = document.getElementById("fullstack");
    const fullstackManual = document.getElementById("fullstack_manual");
    
    if (!tipoCliente || !tipoCliente.value) {
        showAlert("Por favor, seleccione el tipo de venta.", "warning");
        if (tipoCliente) tipoCliente.focus();
        return false;
    }
    
    if (!tipoVenta) {
        showAlert("Por favor, seleccione el tipo de vendedor.", "warning");
        return false;
    }
    
    if (tipoVenta.value === "normal") {
        if (linerSelect) {
            const isLinerManualMode = linerSelect.style.display === 'none';
            const linerSelectValue = linerSelect.value;
            const linerManualValue = linerManual ? linerManual.value.trim() : '';
            
            if (isLinerManualMode) {
                if (!linerManualValue) {
                    showAlert("Por favor, ingrese un liner.", "warning");
                    if (linerManual) linerManual.focus();
                    return false;
                }
            } else {
                if (!linerSelectValue) {
                    showAlert("Por favor, seleccione un liner.", "warning");
                    if ($('#liner').length) $('#liner').select2('open');
                    return false;
                }
            }
        }
        
    } else if (tipoVenta.value === "fullstack") {
        if (fullstackSelect) {
            const isFullstackManualMode = fullstackSelect.style.display === 'none';
            const fullstackSelectValue = fullstackSelect.value;
            const fullstackManualValue = fullstackManual ? fullstackManual.value.trim() : '';
            
            if (isFullstackManualMode) {
                if (!fullstackManualValue) {
                    showAlert("Por favor, ingrese un front.", "warning");
                    if (fullstackManual) fullstackManual.focus();
                    return false;
                }
            } else {
                if (!fullstackSelectValue) {
                    showAlert("Por favor, seleccione un front.", "warning");
                    if ($('#fullstack').length) $('#fullstack').select2('open');
                    return false;
                }
            }
        }
    }
    
    if (metodoPago && (metodoPago.value === "SPEI" || metodoPago.value === "TDC")) {
        if (!plataformaSelect || !plataformaSelect.value) {
            showAlert("Por favor, seleccione una plataforma.", "warning");
            if ($('#plataforma').length) $('#plataforma').select2('open');
            return false;
        }
    }
    
    return true;
}

// Manejar envío del formulario con AJAX
function setupFormSubmit() {
    const ventaForm = document.getElementById("ventaForm");
    
    if (!ventaForm) {
        console.error('Formulario no encontrado');
        return;
    }
    
    ventaForm.addEventListener("submit", function(e) {
        e.preventDefault();
        console.log('=== ENVÍO DE FORMULARIO INICIADO ===');
        
        // 1. VERIFICAR Y SINCRONIZAR SALA_ID
        const salaIdInput = document.getElementById('sala_id');
        const salaSelector = document.getElementById('salaSelector');
        
        console.log('🔍 Antes de sincronizar:');
        console.log('  - sala_id:', salaIdInput ? `${salaIdInput.value} (${salaIdInput.name})` : 'NO ENCONTRADO');
        console.log('  - salaSelector:', salaSelector ? `${salaSelector.value} (visible: ${salaSelector.style.display !== 'none'})` : 'NO ENCONTRADO');
        
        // Si hay selector visible (admin), sincronizar con campo hidden
        if (salaSelector && salaSelector.style.display !== 'none' && salaIdInput) {
            console.log('🎯 Es ADMIN - Sincronizando selector con campo hidden');
            
            // ACTUALIZAR EL CAMPO HIDDEN CON EL VALOR DEL SELECTOR
            salaIdInput.value = salaSelector.value;
            salaIdInput.setAttribute('value', salaSelector.value);
            
            console.log('✅ Después de sincronizar:');
            console.log('  - Nuevo valor de sala_id:', salaIdInput.value);
            
            // Disparar evento para que FormData capture el cambio
            const event = new Event('input', { bubbles: true });
            salaIdInput.dispatchEvent(event);
        } else {
            console.log('👤 Es USUARIO - Usando valor fijo de sesión:', salaIdInput ? salaIdInput.value : 'NO');
        }
        
        // 2. DEBUG: Mostrar todos los campos del formulario
        console.log('📋 TODOS LOS CAMPOS DEL FORMULARIO:');
        const formElements = this.elements;
        for (let i = 0; i < formElements.length; i++) {
            const element = formElements[i];
            if (element.name && element.type !== 'button' && element.type !== 'submit') {
                console.log(`  ${element.name}: "${element.value}" (${element.type})`);
            }
        }
        
        // 3. Crear FormData y mostrar contenido
        const formData = new FormData(this);
        console.log('📦 CONTENIDO DE FormData:');
        for (let [key, value] of formData.entries()) {
            console.log(`  ${key}: "${value}"`);
        }
        
        // 4. Validar formulario
        if (!validarFormulario()) {
            console.log('❌ Validación falló');
            return;
        }
        
        // 5. Quitar atributos required de campos deshabilitados
        const disabledRequiredFields = document.querySelectorAll('select[disabled][required], input[disabled][required]');
        disabledRequiredFields.forEach(field => {
            field.removeAttribute('required');
        });
        
        // 6. Validar con checkValidity nativo
        if (!this.checkValidity()) {
            showAlert("Por favor, complete todos los campos obligatorios correctamente.", "warning");
            
            // Restaurar atributos required
            disabledRequiredFields.forEach(field => {
                field.setAttribute('required', 'required');
            });
            return;
        }
        
        // 7. Restaurar atributos required
        disabledRequiredFields.forEach(field => {
            field.setAttribute('required', 'required');
        });
        
        // 8. Preparar envío
        const form = this;
        const submitBtn = form.querySelector('button[type="submit"]');
        if (!submitBtn) {
            console.error('Botón de enviar no encontrado');
            return;
        }
        
        const originalText = submitBtn.textContent;
        submitBtn.textContent = "Guardando...";
        submitBtn.disabled = true;
        form.classList.add('loading');
        
        // 9. Agregar datos adicionales al FormData
        const metodoPago = document.querySelector('input[name="metodoPago"]:checked');
        const tipoVenta = document.querySelector('input[name="tipoVenta"]:checked');
        const tipoCliente = document.getElementById('tipoCliente');
        
        if (metodoPago) formData.append('metodo_pago', metodoPago.value);
        if (tipoVenta) formData.append('tipo_venta', tipoVenta.value);
        
        if (tipoCliente && tipoCliente.value) {
            formData.append('tipoCliente', tipoCliente.value);
        } else {
            showAlert("Por favor, seleccione el tipo de cliente", "warning");
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
            form.classList.remove('loading');
            return;
        }
        
        console.log("🚀 Enviando datos al servidor...");
        
        // 10. Enviar datos
        fetch("guardar_factura.php", {
            method: "POST",
            body: formData
        })
        .then(response => {
            console.log("📥 Respuesta recibida, status:", response.status);
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status} ${response.statusText}`);
            }
            return parseJSONResponse(response);
        })
        .then(data => {
            console.log("✅ Respuesta parseada:", data);
            
            if (data && data.success) {
                showAlert(data.message, "success");
                setTimeout(() => {
                    window.location.href = "ventas.php?success=" + encodeURIComponent(data.message);
                }, 2000);
            } else {
                const errorMessage = data ? data.message : 'Error desconocido';
                showAlert(errorMessage, "danger");
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
                form.classList.remove('loading');
            }
        })
        .catch(error => {
            console.error("❌ Error completo en submit:", error);
            
            // Usar la función showAlert con verificación de existencia
            showAlert("Error del servidor: " + error.message, "danger");
            
            // Asegurarse de restaurar el botón incluso si showAlert falla
            if (submitBtn) {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            }
            if (form) {
                form.classList.remove('loading');
            }
        });
    });
}

// Función para cargar usuarios desde el servidor
function loadUsers() {
    const salaSelector = document.getElementById('salaSelector');
    const salaIdInput = document.getElementById('sala_id');
    
    // DEBUG: Verificar atributos
    if (salaSelector) {
        console.log('🔍 ATRIBUTOS DEL SELECTOR:');
        console.log('  - data-acceso-todas-salas:', salaSelector.getAttribute('data-acceso-todas-salas'));
        console.log('  - data-es-admin:', salaSelector.getAttribute('data-es-admin'));
        console.log('  - value:', salaSelector.value);
        console.log('  - display:', salaSelector.style.display);
        console.log('  - es admin?', salaSelector.getAttribute('data-es-admin') === '1' ? 'SI' : 'NO');
    }
    
    const esAdminAccesoTotal = salaSelector ? 
        (salaSelector.getAttribute('data-acceso-todas-salas') === '1') : 
        false;
    
    let salaId = null;
    let url = "obtener_usuarios.php";
    
    // Determinar qué sala usar
    if (salaSelector && salaSelector.style.display !== 'none') {
        // Si es admin con acceso a todas las salas, NO enviar sala_id
        if (esAdminAccesoTotal) {
            console.log('👑 ADMIN CON ACCESO TOTAL: Cargando TODOS los usuarios (sin filtrar por sala)');
            // Agregamos parámetro todos=1 para forzar todos los usuarios
            url += "?todos=1";
        } else {
            // Es admin con acceso restringido: enviar sala_id
            salaId = salaSelector.value;
            url += "?sala_id=" + encodeURIComponent(salaId);
            console.log('👑 ADMIN RESTRINGIDO: Cargando usuarios para sala:', salaId);
        }
    } else if (salaIdInput) {
        // Es usuario normal: usar valor del campo hidden
        salaId = salaIdInput.value;
        url += "?sala_id=" + encodeURIComponent(salaId);
        console.log('👤 USUARIO: Cargando usuarios para sala:', salaId);
    }
    
    console.log('🔗 URL para cargar usuarios:', url);
    
    return fetch(url)
        .then(response => {
            console.log('📥 Response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status} ${response.statusText}`);
            }
            return parseJSONResponse(response);
        })
        .then(data => {
            console.log('✅ Datos recibidos de usuarios:', data);
            
            if (data && data.error) {
                throw new Error(data.message || 'Error desconocido del servidor');
            }
            
            usuariosData = Array.isArray(data) ? data : [];
            
            console.log(`👥 Usuarios procesados: ${usuariosData.length} usuarios`);
            
            actualizarSelectsUsuarios(usuariosData);
            
            // Inicializar contenedores de entrada manual como ocultos
            const manualContainers = document.querySelectorAll('.manual-input-container');
            manualContainers.forEach(container => {
                if (container) container.style.display = 'none';
            });

            // Configurar atributos required originales
            const requiredSelects = document.querySelectorAll('.search-select[required]');
            requiredSelects.forEach(select => {
                if (select) select.setAttribute('data-original-required', 'true');
            });
            
            return usuariosData;
        })
        .catch(error => {
            console.error('❌ Error al cargar usuarios:', error);
            
            showAlert("Error al cargar la lista de usuarios: " + error.message, "danger");
            
            usuariosData = [];
            actualizarSelectsUsuarios([]);
            
            throw error;
        });
}

// Función para actualizar los selects con usuarios
function actualizarSelectsUsuarios(usuarios) {
    const selects = [
        { id: 'liner', placeholder: 'Selecciona un liner', required: true },
        { id: 'closer1', placeholder: 'Selecciona un closer', required: false },
        { id: 'closer2', placeholder: 'Selecciona un closer', required: false },
        { id: 'fullstack', placeholder: 'Selecciona un front', required: true }
    ];
    
    selects.forEach(selectInfo => {
        const select = document.getElementById(selectInfo.id);
        if (!select) {
            console.warn(`Select ${selectInfo.id} no encontrado`);
            return;
        }
        
        safeDestroySelect2(`#${selectInfo.id}`);
        
        select.innerHTML = '';
        
        if (Array.isArray(usuarios) && usuarios.length > 0) {
            const defaultOption = document.createElement("option");
            defaultOption.value = "";
            defaultOption.textContent = selectInfo.placeholder;
            defaultOption.disabled = selectInfo.required;
            defaultOption.selected = true;
            select.appendChild(defaultOption);
            
            usuarios.forEach(usuario => {
                const option = document.createElement("option");
                option.value = usuario.id;
                option.textContent = usuario.nombre_completo || usuario.nombre || 'Usuario sin nombre';
                select.appendChild(option);
            });
        } else {
            const noUsersOption = document.createElement("option");
            noUsersOption.value = "";
            noUsersOption.textContent = "No hay usuarios disponibles";
            noUsersOption.disabled = true;
            noUsersOption.selected = true;
            select.appendChild(noUsersOption);
        }
        
        // Reinicializar Select2 después de un breve retraso
        setTimeout(() => {
            if ($(`#${selectInfo.id}`).length) {
                $(`#${selectInfo.id}`).select2({
                    theme: 'bootstrap-5',
                    placeholder: selectInfo.placeholder,
                    allowClear: !selectInfo.required,
                    width: '100%'
                });
            }
        }, 100);
    });
}

// Función para cargar plataformas desde el servidor
function loadPlatforms() {
    console.log('🔗 Cargando plataformas...');
    
    return fetch("obtener_plataformas.php")
        .then(response => {
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status} ${response.statusText}`);
            }
            return parseJSONResponse(response);
        })
        .then(data => {
            console.log('✅ Plataformas recibidas:', data);
            
            plataformasData = Array.isArray(data) ? data : [];
            
            console.log(`💳 Plataformas procesadas: ${plataformasData.length} plataformas`);
            
            // Configurar eventos para los radio buttons de método de pago
            const radios = document.querySelectorAll('input[name="metodoPago"]');
            if (radios.length > 0) {
                radios.forEach(radio => {
                    radio.addEventListener('change', togglePlataforma);
                });
            }
            
            togglePlataforma();
            console.log(`✅ Plataformas cargadas: ${plataformasData.length} plataformas`);
            return plataformasData;
        })
        .catch(error => {
            console.error("❌ Error al cargar las plataformas:", error);
            showAlert("Error al cargar la lista de plataformas", "danger");
            throw error;
        });
}

// Función para actualizar el permiso de una plataforma
function actualizarPermisoPlataforma(plataformaId, permiso) {
    if (!esAdminConPermisoPlataforma) {
        showAlert("No tienes permisos para modificar los permisos de plataforma", "warning");
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
            showAlert(data.message, "success");
            
            // Actualizar los datos locales
            const plataformaIndex = plataformasData.findIndex(p => p.id == plataformaId);
            if (plataformaIndex !== -1) {
                plataformasData[plataformaIndex].permiso_plataforma = permiso ? 1 : 0;
            }
            
            // Actualizar la lista de plataformas inmediatamente
            const metodoPago = document.querySelector('input[name="metodoPago"]:checked');
            if (metodoPago && (metodoPago.value === "SPEI" || metodoPago.value === "TDC")) {
                // Si se desactiva el permiso (permiso = 0), la plataforma desaparecerá de la lista
                // Si se activa el permiso (permiso = 1), la plataforma aparecerá en la lista
                togglePlataforma();
                
                // Si se desactivó el permiso y la plataforma estaba seleccionada, limpiar selección
                if (!permiso) {
                    const plataformaSelect = document.getElementById('plataforma');
                    if (plataformaSelect && plataformaSelect.value == plataformaId) {
                        plataformaSelect.value = "";
                        safeDestroySelect2('#plataforma');
                        if ($('#plataforma').length) {
                            $('#plataforma').val('').trigger('change');
                        }
                        
                        // Ocultar el interruptor
                        const permisoContainer = document.getElementById('plataformaPermisoContainer');
                        if (permisoContainer) {
                            permisoContainer.style.display = 'none';
                        }
                        
                        showAlert("La plataforma ha sido restringida y eliminada de la selección.", "info");
                    }
                }
            }
        } else {
            showAlert(data.message, "danger");
        }
    })
    .catch(error => {
        console.error('Error al actualizar permiso:', error);
        showAlert("Error al actualizar el permiso", "danger");
    });
}

// Configurar eventos para el interruptor
function setupPermisoSwitchEvents() {
    document.addEventListener('change', function(e) {
        if (e.target && e.target.id === 'togglePermisoPlataforma') {
            const plataformaSelect = document.getElementById('plataforma');
            const plataformaId = plataformaSelect ? plataformaSelect.value : null;
            
            if (plataformaId) {
                const nuevoPermiso = e.target.checked;
                actualizarPermisoPlataforma(plataformaId, nuevoPermiso);
            }
        }
    });
}

// Establecer fecha actual por defecto
function setDefaultDate() {
    const fechaInput = document.getElementById('fecha');
    if (fechaInput && !fechaInput.value) {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        fechaInput.value = `${yyyy}-${mm}-${dd}`;
    }
}

// Inicializar la aplicación cuando el DOM esté listo
document.addEventListener("DOMContentLoaded", function () {
    console.log('=== INICIALIZANDO APLICACIÓN ===');
    
    // Establecer fecha por defecto
    setDefaultDate();
    
    // Configurar selector de sala para admin
    const salaSelector = document.getElementById('salaSelector');
    const salaIdInput = document.getElementById('sala_id');
    
    if (salaSelector && salaSelector.style.display !== 'none') {
        // Es admin: configurar eventos del selector
        console.log('👑 MODO ADMIN detectado');
        console.log('  - Selector value inicial:', salaSelector.value);
        console.log('  - sala_id value inicial:', salaIdInput ? salaIdInput.value : 'NO');
        console.log('  - Acceso a todas salas:', salaSelector.getAttribute('data-acceso-todas-salas'));
        console.log('  - Es admin:', salaSelector.getAttribute('data-es-admin'));
        
        // Sincronizar inicialmente
        if (salaIdInput && salaSelector.value) {
            salaIdInput.value = salaSelector.value;
            console.log('🔄 Sincronizado inicialmente:', salaIdInput.value);
        }
        
        // Actualizar cuando cambie el selector - FORZAR RECARGA
        salaSelector.addEventListener('change', function() {
            const nuevoValor = this.value;
            const esAdminAccesoTotal = this.getAttribute('data-acceso-todas-salas') === '1';
            
            console.log('🔄 ====== SELECTOR CAMBIADO ======');
            console.log('Nuevo valor:', nuevoValor);
            console.log('Admin acceso total:', esAdminAccesoTotal ? 'SÍ' : 'NO');
            console.log('Atributo data-acceso-todas-salas:', this.getAttribute('data-acceso-todas-salas'));
            
            // Actualizar campo hidden
            if (salaIdInput) {
                salaIdInput.value = nuevoValor;
                salaIdInput.setAttribute('value', nuevoValor);
                console.log('✅ sala_id actualizado a:', salaIdInput.value);
            }
            
            // FORZAR recarga de usuarios cuando cambia la sala
            console.log('🔄 Forzando recarga de usuarios...');
            
            if (esAdminAccesoTotal) {
                console.log('📋 ADMIN CON ACCESO TOTAL: Se cargarán TODOS los usuarios');
            } else {
                showAlert("🔄 Cargando usuarios de la sala seleccionada...", "info");
                console.log('📋 ADMIN RESTRINGIDO: Se cargarán usuarios de sala:', nuevoValor);
            }
            
            // Llamar inmediatamente a loadUsers
            loadUsers()
                .then(() => {
                    console.log('✅ Usuarios cargados exitosamente después de cambiar sala');
                    toggleTipoVenta();
                })
                .catch((error) => {
                    console.error('❌ Error después de cambiar sala:', error);
                    showAlert("❌ Error al cargar usuarios: " + error.message, "danger");
                });
        });
        
    } else {
        // No es admin
        console.log('👤 MODO USUARIO detectado');
        console.log('  - sala_id value:', salaIdInput ? salaIdInput.value : 'NO');
    }
    
    // Configurar eventos del interruptor de permiso
    setupPermisoSwitchEvents();
    
    // Cargar datos iniciales
    console.log('📥 Cargando datos iniciales...');
    
    // Forzar carga inicial en secuencia para mejor debug
    loadUsers()
        .then(() => {
            console.log('✅ Usuarios iniciales cargados');
            return loadPlatforms();
        })
        .then(() => {
            console.log('✅ Plataformas cargadas');
            
            // Configurar eventos de tipo de venta
            const radiosTipoVenta = document.querySelectorAll('input[name="tipoVenta"]');
            if (radiosTipoVenta.length > 0) {
                radiosTipoVenta.forEach(radio => {
                    radio.addEventListener('change', toggleTipoVenta);
                });
                // Establecer estado inicial
                toggleTipoVenta();
            }
            
            // Configurar envío del formulario
            setupFormSubmit();
            
            console.log('✅ Aplicación inicializada correctamente');
        })
        .catch(error => {
            console.error("❌ Error crítico al inicializar la aplicación:", error);
            showAlert("Error al cargar los datos iniciales. Por favor, recargue la página.", "danger");
        });
});