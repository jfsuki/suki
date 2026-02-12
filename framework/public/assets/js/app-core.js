/**
 * Utilidades Globales del Sistema Suki ERP
 */
const SukiCore = {
    // Detecta la URL de la API de forma dinámica
    getBaseApiUrl: () => {
        const origin = window.location.origin;
        const path = window.location.pathname.split('/')[1];
        
        if (window.location.hostname.endsWith('.test') || window.location.hostname.endsWith('.local')) {
            return `${origin}/api`;
        }
        
        const ignoredPaths = ['clientes', 'usuarios', 'dashboard']; 
        if (ignoredPaths.includes(path)) return `${origin}/api`; 

        return `${origin}/${path}/api`;
    },

    // Manejador genérico de envío de formularios
    handleFormSubmit: async (event, actionUrl) => {
        event.preventDefault();
        const form = event.target;
        const submitBtn = form.querySelector('button[type="submit"]');
        const formData = new FormData(form);

        if(submitBtn) {
            submitBtn.disabled = true;
            submitBtn.dataset.originalText = submitBtn.innerText;
            submitBtn.innerText = 'Procesando...';
        }

        try {
            const response = await fetch(`${SukiCore.getBaseApiUrl()}/${actionUrl}`, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.status === 'success') {
                Swal.fire({ icon: 'success', title: '¡Éxito!', text: result.message, timer: 1500, showConfirmButton: false });
                form.reset();
                window.dispatchEvent(new CustomEvent('reload-table'));
            } else {
                Swal.fire({ icon: 'warning', title: 'Atención', text: result.message });
            }
        } catch (error) {
            console.error(error);
            Swal.fire({ icon: 'error', title: 'Error', text: 'Error de conexión con el servidor.' });
        } finally {
            if(submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = submitBtn.dataset.originalText;
            }
        }
    }
};

SukiCore.collectGridData = (form) => {
    const grids = {};
    form.querySelectorAll('[data-grid]').forEach(table => {
        const gridName = table.dataset.grid;
        if (!gridName) return;
        const rows = [];
        table.querySelectorAll('tbody tr').forEach(tr => {
            const row = {};
            tr.querySelectorAll('[data-column]').forEach(input => {
                row[input.dataset.column] = input.value;
            });
            if (Object.keys(row).length > 0) {
                rows.push(row);
            }
        });
        grids[gridName] = rows;
    });
    return grids;
};

SukiCore.serializeContractForm = (form) => {
    const formData = new FormData(form);
    const data = {};
    for (const [key, value] of formData.entries()) {
        if (key.includes('[')) continue; // grids se serializan aparte
        data[key] = value;
    }

    form.querySelectorAll('input[type="checkbox"]').forEach(input => {
        if (!input.name) return;
        if (!Object.prototype.hasOwnProperty.call(data, input.name)) {
            data[input.name] = input.checked ? '1' : '0';
        }
    });

    const grids = SukiCore.collectGridData(form);
    return {
        data,
        grids,
        FORM_STORE: window.FORM_STORE || {},
        GRID_STORE: window.GRID_STORE || {}
    };
};

SukiCore.handleContractSubmit = async (event) => {
    event.preventDefault();
    const form = event.target;
    const endpoint = form.dataset.apiEndpoint || '';
    if (!endpoint) return;

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.dataset.originalText = submitBtn.innerText;
        submitBtn.innerText = 'Procesando...';
    }

    try {
        const baseApi = SukiCore.getBaseApiUrl();
        const cleaned = endpoint.replace(/^\/+/, '');
        const url = endpoint.startsWith('http') ? endpoint : `${baseApi}/${cleaned}`;
        const payload = SukiCore.serializeContractForm(form);

        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();

        if (result.status === 'success') {
            Swal.fire({ icon: 'success', title: '¡Éxito!', text: result.message, timer: 1500, showConfirmButton: false });
            form.reset();
            window.dispatchEvent(new CustomEvent('reload-table'));
        } else {
            Swal.fire({ icon: 'warning', title: 'Atención', text: result.message });
        }
    } catch (error) {
        console.error(error);
        Swal.fire({ icon: 'error', title: 'Error', text: 'Error de conexión con el servidor.' });
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = submitBtn.dataset.originalText;
        }
    }
};

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form && form.matches('form[data-api-endpoint]')) {
        SukiCore.handleContractSubmit(event);
    }
});
