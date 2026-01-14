document.addEventListener('DOMContentLoaded', () => {

    document.querySelectorAll('[data-grid-config]').forEach(tag => {

        const cfg   = JSON.parse(tag.textContent);
        const name  = cfg.name;
        const table = document.querySelector(`[data-grid="${name}"]`);
        const tbody = table.querySelector('tbody');
        const btn   = document.querySelector(`[data-add-row="${name}"]`);

        if (!table || !tbody || !btn) return;

        btn.addEventListener('click', addRow);

        

        function evalFormula(expression, scope) {
            try {
                return Function(
                    ...Object.keys(scope),
                    `"use strict"; return (${expression});`
                )(...Object.values(scope)) || 0;
            } catch {
                return 0;
            }
        }

        

    });

});


/**
 * Grid Calculator - Versión para cliente.form.json
 */

class GridCalculator {
    constructor() {
        this.grids = new Map();
        this.init();
    }

    init() {
        // Cargar configs con selector específico por nombre
        document.querySelectorAll('[data-grid-config]').forEach(script => {
            const gridName = script.getAttribute('data-grid-config');
            try {
                const config = JSON.parse(script.textContent);
                this.grids.set(gridName, config);
                this.initGrid(config);
                console.log(`✅ Grid "${gridName}" inicializado`, config);
            } catch (e) {
                console.error(`❌ Error parseando grid "${gridName}":`, e);
            }
        });
    }

    initGrid(config) {
        const gridName = config.name;
        const table = document.querySelector(`[data-grid="${gridName}"]`);
        
        if (!table) {
            console.warn(`⚠️ Grid "${gridName}" no encontrado en DOM`);
            return;
        }

        // Botón agregar fila
        const addButton = document.querySelector(`[data-add-row="${gridName}"]`);
        if (addButton) {
            addButton.addEventListener('click', () => this.addRow(gridName));
        }

        // Event delegation para inputs
        table.addEventListener('input', (e) => {
            if (e.target.matches('input, select')) {
                this.handleInput(gridName, e.target);
            }
        });

        // Event delegation para eliminar
        table.addEventListener('click', (e) => {
            if (e.target.matches('[data-remove-row]')) {
                this.removeRow(gridName, e.target);
            }
        });
    }

    addRow(gridName) {
        const config = this.grids.get(gridName);
        const table = document.querySelector(`[data-grid="${gridName}"]`);
        const tbody = table.querySelector('tbody');
        
        const rowIndex = tbody.children.length;
        const row = document.createElement('tr');
        row.dataset.rowIndex = rowIndex;

        // Generar celdas
        config.columns.forEach(col => {
            const td = document.createElement('td');
            td.className = 'border p-2';
            
            const input = this.createInput(col, gridName, rowIndex);
            td.appendChild(input);
            row.appendChild(td);
        });

        // Celda de acciones
        const actionTd = document.createElement('td');
        actionTd.className = 'border p-2 text-center';
        actionTd.innerHTML = `
            <button type="button" 
                    class="text-red-600 hover:text-red-800 text-sm font-bold"
                    data-remove-row>
                ✕
            </button>
        `;
        row.appendChild(actionTd);

        tbody.appendChild(row);
        this.calculateTotals(gridName);
    }

    createInput(col, gridName, rowIndex) {
        const name = `${gridName}[${rowIndex}][${col.name}]`;
        const hasFormula = !!col.formula;
        
        const input = document.createElement('input');
        input.type = col.input?.type || 'text';
        input.name = name;
        input.className = 'w-full border rounded px-2 py-1 text-sm';
        input.dataset.column = col.name;
        input.dataset.gridName = gridName;
        input.dataset.rowIndex = rowIndex;

        if (col.input?.type === 'number') {
            input.step = '0.01';
            input.value = '0';
        }

        if (hasFormula) {
            input.readOnly = true;
            input.classList.add('bg-gray-100', 'font-semibold');
            input.dataset.formula = JSON.stringify(col.formula);
        }

        return input;
    }

    removeRow(gridName, button) {
        const row = button.closest('tr');
        row.remove();
        this.calculateTotals(gridName);
        this.updateFormSummary();
    }

    handleInput(gridName, input) {
        const rowIndex = input.dataset.rowIndex;
        this.calculateRowFormulas(gridName, rowIndex);
        this.calculateTotals(gridName);
        this.updateFormSummary();
    }

    calculateRowFormulas(gridName, rowIndex) {
        const config = this.grids.get(gridName);
        const row = document.querySelector(`[data-grid="${gridName}"] tr[data-row-index="${rowIndex}"]`);
        
        if (!row) return;

        config.columns.forEach(col => {
            if (col.formula) {
                const input = row.querySelector(`[data-column="${col.name}"]`);
                if (input) {
                    const value = this.evaluateFormula(col.formula, row);
                    input.value = value.toFixed(2);
                }
            }
        });
    }

    evaluateFormula(formula, row) {
        const expression = formula.expression || formula;
        const variables = expression.match(/[a-zA-Z_]\w*/g) || [];
        
        const context = {};
        variables.forEach(varName => {
            const input = row.querySelector(`[data-column="${varName}"]`);
            context[varName] = parseFloat(input?.value || 0);
        });

        try {
            const func = new Function(...Object.keys(context), `return ${expression}`);
            return func(...Object.values(context));
        } catch (e) {
            console.error('Error en fórmula:', expression, e);
            return 0;
        }
    }

    calculateTotals(gridName) {
        const config = this.grids.get(gridName);
        const table = document.querySelector(`[data-grid="${gridName}"]`);
        
        // Calcular totales para columnas que lo especifiquen
        config.columns.forEach(col => {
            if (col.total) {
                const inputs = table.querySelectorAll(`[data-column="${col.name}"]`);
                const sum = Array.from(inputs).reduce((acc, input) => {
                    return acc + (parseFloat(input.value) || 0);
                }, 0);

                const totalDisplay = document.querySelector(`[data-total="${gridName}.${col.name}"]`);
                if (totalDisplay) {
                    totalDisplay.textContent = sum.toFixed(2);
                }
            }
        });
    }

    updateFormSummary() {
        const summaryItems = document.querySelectorAll('[data-summary]');
        
        summaryItems.forEach(item => {
            const summaryName = item.dataset.summary;
            const value = this.calculateSummaryValue(summaryName);
            item.textContent = value.toFixed(2);
        });
    }

    calculateSummaryValue(summaryName) {
        switch (summaryName) {
            case 'total_cantidad':
                return this.getGridTotal('cliente_facturacion', 'cantidad');
            
            case 'subtotal_general':
                return this.getGridTotal('cliente_facturacion', 'subtotal');
            
            case 'iva':
                const subtotal = this.getGridTotal('cliente_facturacion', 'subtotal');
                return subtotal * 0.19;
            
            case 'total_general':
                const sub = this.getGridTotal('cliente_facturacion', 'subtotal');
                return sub * 1.19;
            
            default:
                return 0;
        }
    }

    getGridTotal(gridName, columnName) {
        const totalDisplay = document.querySelector(`[data-total="${gridName}.${columnName}"]`);
        return parseFloat(totalDisplay?.textContent || 0);
    }
}

// Auto-inicialización
document.addEventListener('DOMContentLoaded', () => {
    window.gridCalculator = new GridCalculator();
});
