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