document.addEventListener('DOMContentLoaded', () => {

    const grids = {};

    // 1️⃣ Leer configuraciones
    document.querySelectorAll('[data-grid-config]').forEach(tag => {
        const cfg = JSON.parse(tag.textContent);
        grids[cfg.name] = {
            config: cfg,
            rows: 0
        };
        console.log(`✅ Grid "${cfg.name}" listo`);
    });

    // 2️⃣ Botones agregar fila
    document.querySelectorAll('[data-add-row]').forEach(btn => {
        btn.addEventListener('click', () => addRow(btn.dataset.addRow));
    });

    // 3️⃣ Agregar fila
    window.addRow = function (gridName) {

        const grid = grids[gridName];
        if (!grid) return;

        const tbody = document.querySelector(`[data-grid="${gridName}"] tbody`);
        const rowIndex = grid.rows++;

        const tr = document.createElement('tr');
        tr.dataset.rowIndex = rowIndex;

        grid.config.columns.forEach(col => {
            const td = document.createElement('td');
            td.className = 'border p-1';

            let el;

            const input = col.input || { type: 'text' };

            if (input.type === 'select') {
                el = document.createElement('select');
                (input.options || []).forEach(o => {
                    const opt = document.createElement('option');
                    opt.value = o.value;
                    opt.textContent = o.label;
                    el.appendChild(opt);
                });
            } else {
                el = document.createElement('input');
                el.type = input.type || 'text';
            }

            el.name = `${gridName}[${rowIndex}][${col.name}]`;
            el.className = 'w-full border rounded px-2 py-1 text-sm';
            el.dataset.grid = gridName;
            el.dataset.row = rowIndex;
            el.dataset.column = col.name;

            el.addEventListener('input', () => recalcGrid(gridName));

            td.appendChild(el);
            tr.appendChild(td);
        });

        // 🗑️ Botón eliminar
        const tdDel = document.createElement('td');
        tdDel.className = 'border text-center';

        const btnDel = document.createElement('button');
        btnDel.type = 'button';
        btnDel.textContent = '✕';
        btnDel.className = 'text-red-600 font-bold';
        btnDel.onclick = () => {
            tr.remove();
            recalcGrid(gridName);
        };

        tdDel.appendChild(btnDel);
        tr.appendChild(tdDel);

        tbody.appendChild(tr);
        recalcGrid(gridName);
    };

    // 4️⃣ Recalcular grid completo
    function recalcGrid(gridName) {

        const grid = grids[gridName];
        if (!grid) return;

        const rows = document.querySelectorAll(`[data-grid="${gridName}"] tbody tr`);

        rows.forEach(tr => {
            const ctx = {};

            // valores base
            tr.querySelectorAll('[data-column]').forEach(el => {
                ctx[el.dataset.column] = parseFloat(el.value) || 0;
            });

            // fórmulas por fila
            grid.config.columns.forEach(col => {
                if (!col.formula) return;

                try {
                    const fn = new Function(...Object.keys(ctx), `return ${col.formula.expression}`);
                    const val = fn(...Object.values(ctx)) || 0;

                    ctx[col.name] = val;

                    const target = tr.querySelector(`[data-column="${col.name}"]`);
                    if (target) target.value = val.toFixed(2);

                } catch (e) {
                    console.warn('⚠️ Error fórmula', col.name, e);
                }
            });
        });

        calcColumnTotals(gridName);
        recalcSummary();
    }

    // 5️⃣ Totales por columna
    function calcColumnTotals(gridName) {

        const grid = grids[gridName];
        const totals = {};

        grid.config.columns.forEach(col => {
            if (col.total) totals[col.name] = 0;
        });

        document.querySelectorAll(`[data-grid="${gridName}"] [data-column]`).forEach(el => {
            const col = el.dataset.column;
            if (!(col in totals)) return;
            totals[col] += parseFloat(el.value) || 0;
        });

        Object.entries(totals).forEach(([col, val]) => {
            const t = document.querySelector(`[data-total="${gridName}.${col}"]`);
            if (t) t.textContent = val.toFixed(2);
        });
    }

    // 6️⃣ Summary del formulario
    function recalcSummary() {

    if (!window.formSummaryConfig) return;

    const values = {};

    // 1️⃣ Resolver SUM primero
    window.formSummaryConfig.forEach(item => {
        if (item.type !== 'sum') return;

        const { grid, field } = item.source;
        let total = 0;

        document
            .querySelectorAll(`[data-grid="${grid}"] [data-column="${field}"]`)
            .forEach(el => {
                total += parseFloat(el.value) || 0;
            });

        values[item.name] = total;

        const span = document.querySelector(`[data-summary="${item.name}"]`);
        if (span) span.textContent = total.toFixed(2);
    });

    // 2️⃣ Resolver FORMULAS
    window.formSummaryConfig.forEach(item => {
        if (item.type !== 'formula') return;

        try {
            const fn = new Function(
                ...item.watch,
                `return ${item.expression}`
            );

            const args = item.watch.map(w => values[w] || 0);
            const result = fn(...args);

            values[item.name] = result;

            const span = document.querySelector(`[data-summary="${item.name}"]`);
            if (span) span.textContent = result.toFixed(2);

        } catch (e) {
            console.warn('⚠️ Error summary fórmula:', item.name, e);
        }
    });
}


});
