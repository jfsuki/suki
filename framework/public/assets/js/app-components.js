/**
 * Componentes Dinámicos (AlpineJS)
 */

// Lógica para TableGenerator
function tableApp(endpoint) {
    return {
        data: [],
        search: '',
        loading: true,
        async init() {
            await this.fetchData();
            window.addEventListener('reload-table', () => this.fetchData());
        },
        async fetchData() {
            this.loading = true;
            try {
                const response = await fetch(`${SukiCore.getBaseApiUrl()}/${endpoint}`);
                const res = await response.json();
                this.data = Array.isArray(res.data) ? res.data : [];
            } catch (e) {
                console.error("Error en tabla:", e);
            } finally {
                this.loading = false;
            }
        },
        get filteredData() {
            if (this.search === "") return this.data;
            return this.data.filter(item => 
                Object.values(item).some(val => String(val).toLowerCase().includes(this.search.toLowerCase()))
            );
        }
    }
}

// Lógica unificada para Facturación y Grids de Detalle
function facturaManager() {
    return {
        master: { cliente_id: '', fecha: '' },
        items: [],
        searchResults: [],
        totalGeneral: 0,

        init() {
            this.addItem();
        },

        addItem() {
            this.items.push({
                id: Date.now(),
                nombre: '',
                cantidad: 1,
                precio: 0,
                subtotal: 0,
                showResults: false
            });
        },

        async buscarProducto(index) {
            let item = this.items[index];
            if (item.nombre.length < 2) {
                item.showResults = false;
                return;
            }
            
            try {
                const response = await fetch(`${SukiCore.getBaseApiUrl()}/producto/buscar?q=${item.nombre}`);
                const res = await response.json();
                if(res.status === 'success') {
                    this.searchResults = res.data;
                    item.showResults = true;
                }
            } catch (e) { console.error("Error búsqueda:", e); }
        },

        selectProducto(index, res) {
            // 'res' es el objeto que viene de la API (id, nombre, precio)
            this.items[index].nombre = res.nombre;
            this.items[index].precio = res.precio;
            this.items[index].showResults = false; // Ocultar lista
            this.recalcular(); // Actualizar el subtotal de la fila y el total general
        },

        recalcular() {
            this.totalGeneral = 0;
            this.items.forEach(item => {
                item.subtotal = (parseFloat(item.cantidad) || 0) * (parseFloat(item.precio) || 0);
                this.totalGeneral += item.subtotal;
            });
        },

        removeItem(index) {
            if (this.items.length > 1) {
                this.items.splice(index, 1);
                this.recalcular();
            }
        }
    }
}