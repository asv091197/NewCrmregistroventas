document.addEventListener('DOMContentLoaded', function() {
    // Formulario Diario
    const formDiario = document.getElementById('formDiario');
    const loadingDiario = document.getElementById('loadingDiario');
    const contenidoDiario = document.getElementById('contenidoDiario');

    // Formulario Mensual
    const formMensual = document.getElementById('formMensual');
    const loadingMensual = document.getElementById('loadingMensual');
    const contenidoMensual = document.getElementById('contenidoMensual');

    // Manejar formulario diario
    formDiario.addEventListener('submit', function(e) {
        e.preventDefault();
        const fecha = document.getElementById('fecha').value;
        const sala = document.getElementById('sala_diario').value;

        // Mostrar loading
        loadingDiario.style.display = 'block';
        contenidoDiario.style.display = 'none';

        // Actualizar URL con fecha y sala
        const nuevaURL = `opcionesAdmin.php?fecha=${fecha}&sala=${sala}`;
        window.location.href = nuevaURL;
    });

    // Manejar formulario mensual
    formMensual.addEventListener('submit', function(e) {
        e.preventDefault();
        const mes = document.getElementById('mes').value;
        const sala = document.getElementById('sala_mensual').value;

        // Mostrar loading
        loadingMensual.style.display = 'block';
        contenidoMensual.style.display = 'none';

        // Actualizar URL con mes y sala
        const nuevaURL = `opcionesAdmin.php?mes=${mes}&sala=${sala}`;
        window.location.href = nuevaURL;
    });

    // Sincronizar selección de sala entre pestañas solo si ambos son select
    const salaDiario = document.getElementById('sala_diario');
    const salaMensual = document.getElementById('sala_mensual');

    if (salaDiario && salaMensual && salaDiario.tagName === 'SELECT' && salaMensual.tagName === 'SELECT') {
        salaDiario.addEventListener('change', function() {
            salaMensual.value = this.value;
        });

        salaMensual.addEventListener('change', function() {
            salaDiario.value = this.value;
        });
    }

    // Inicializar gráficas
    if (datosDashboard.diario.cantidades.length) {
        crearGraficaBarras('graficaBarras',
            datosDashboard.diario.etiquetas,
            datosDashboard.diario.cantidades,
            '65%', '50%');
    }
    if (datosDashboard.mensual.cantidades.length) {
        crearGraficaBarras('graficaBarrasMensual',
            datosDashboard.mensual.etiquetas,
            datosDashboard.mensual.cantidades,
            '55%', '40%');
    }
    inicializarGraficaLinealVentas();

    // Gráfica de barras de llamadas por agente (diaria y mensual)
    function crearGraficaBarras(canvasId, apellidos, cantidades, luz, luzBorde) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        const colores = cantidades.map((cantidad, index) => {
            const hue = (index * 137.508) % 360;
            return `hsl(${hue}, 70%, ${luz})`;
        });

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: apellidos,
                datasets: [{
                    label: 'Llamadas Realizadas',
                    data: cantidades,
                    backgroundColor: colores,
                    borderColor: colores.map(color => color.replace(luz, luzBorde)),
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return `Llamadas: ${context.parsed.y}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    },
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }

    function inicializarGraficaLinealVentas() {
        if (datosDashboard.lineal.ventas.length) {
            const ctxLineal = document.getElementById('graficaLinealVentas');
            if (ctxLineal) {
                const dias = datosDashboard.lineal.dias;
                const ventas = datosDashboard.lineal.ventas;

                // Formato de dinero para tooltips
                const formatoMXN = new Intl.NumberFormat('es-MX', {
                    style: 'currency',
                    currency: 'MXN',
                    minimumFractionDigits: 2
                });

                // Crear array de etiquetas para los días
                const labelsDias = dias.map(dia => `Día ${dia}`);

                new Chart(ctxLineal, {
                    type: 'line',
                    data: {
                        labels: labelsDias,
                        datasets: [{
                            label: 'Venta Bruta por Día',
                            data: ventas,
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#28a745',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    font: {
                                        size: 14,
                                        weight: 'bold'
                                    }
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                displayColors: false,
                                callbacks: {
                                    label: function(context) {
                                        return `Venta: ${formatoMXN.format(context.parsed.y)}`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return new Intl.NumberFormat('es-MX', {
                                            style: 'currency',
                                            currency: 'MXN',
                                            minimumFractionDigits: 0
                                        }).format(value);
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                },
                                title: {
                                    display: true,
                                    text: 'Venta Bruta (MXN)',
                                    font: {
                                        size: 14,
                                        weight: 'bold'
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                },
                                title: {
                                    display: true,
                                    text: 'Días del Mes',
                                    font: {
                                        size: 14,
                                        weight: 'bold'
                                    }
                                }
                            }
                        },
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },
                        elements: {
                            line: {
                                tension: 0.4
                            }
                        }
                    }
                });
            }
        }
    }
});
