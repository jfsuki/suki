document.addEventListener('DOMContentLoaded', () => {
    // 1. Inicialización de Stores
    window.GRID_STORE = JSON.parse(localStorage.getItem('GRID_STORE')) || {};
    window.FORM_STORE = JSON.parse(localStorage.getItem('FORM_STORE')) || {};

    const grids = {};
    const selectOptionsCache = new Map();

    // 2. Cargar configuraciones de los Grids
    document.querySelectorAll('[data-grid-config]').forEach(tag => {
        try {
            const cfg = JSON.parse(tag.textContent);
            const normalized = normalizeGridConfig(cfg);
            grids[normalized.name] = { config: normalized };
            console.log(`✅ Grid detectado: ${cfg.name}`);
        } catch (e) { console.error("Error cargando config", e); }
    });

    function normalizeGridConfig(cfg) {
        if (!cfg || !Array.isArray(cfg.columns)) return cfg;

        const columnNames = new Set(cfg.columns.map(c => c.name).filter(Boolean));

        cfg.columns.forEach(col => {
            if (!col) return;

            if (!col.input) {
                col.input = { type: col.type || 'text' };
                if (col.options && col.input.type === 'select') {
                    col.input.options = col.options;
                }
            } else if (!col.input.type) {
                col.input.type = col.type || 'text';
            }

            if (col.input.type === 'select') {
                if (!col.input.optionsSource) {
                    col.input.optionsSource = Array.isArray(col.input.options) ? 'manual' : 'api';
                }
                if (col.input.optionsSource === 'api' && col.input.options && !col.input.options.map) {
                    col.input.options.map = { value: 'id', label: 'nombre' };
                }
            }

            if (typeof col.formula === 'string') {
                const expr = col.formula;
                col.formula = {
                    expression: expr,
                    watch: inferWatch(expr, columnNames)
                };
            } else if (col.formula && typeof col.formula.expression === 'string') {
                if (!Array.isArray(col.formula.watch)) {
                    col.formula.watch = inferWatch(col.formula.expression, columnNames);
                }
            }
        });

        if (!Array.isArray(cfg.totals)) {
            cfg.totals = [];
        }

        return cfg;
    }

    function inferWatch(expression, columnNames) {
        if (!expression) return [];
        const tokens = expression.match(/[a-zA-Z_][a-zA-Z0-9_]*/g) || [];
        const watch = [];
        tokens.forEach(token => {
            if (columnNames.has(token) && !watch.includes(token)) {
                watch.push(token);
            }
        });
        return watch;
    }

    function populateSelectOptions(selectEl, inputCfg, gridName, colName) {
        const optionsSource = inputCfg.optionsSource || (Array.isArray(inputCfg.options) ? 'manual' : 'manual');

        if (optionsSource === 'manual') {
            const options = Array.isArray(inputCfg.options) ? inputCfg.options : [];
            options.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.value;
                opt.textContent = o.label;
                selectEl.appendChild(opt);
            });
            return;
        }

        if (optionsSource !== 'api') return;

        const endpoint = inputCfg.options?.endpoint;
        if (!endpoint) return;

        const cacheKey = `${gridName}.${colName}::${endpoint}`;
        if (selectOptionsCache.has(cacheKey)) {
            const cached = selectOptionsCache.get(cacheKey);
            cached.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.value;
                opt.textContent = o.label;
                selectEl.appendChild(opt);
            });
            return;
        }

        const loadingOpt = document.createElement('option');
        loadingOpt.value = '';
        loadingOpt.textContent = 'Cargando...';
        selectEl.appendChild(loadingOpt);

        fetch(endpoint)
            .then(res => res.json())
            .then(data => {
                const list = Array.isArray(data)
                    ? data
                    : (Array.isArray(data?.data) ? data.data : (Array.isArray(data?.items) ? data.items : []));

                const map = inputCfg.options?.map || {};
                const valueKey = map.value || inputCfg.options?.valueKey || 'id';
                const labelKey = map.label || inputCfg.options?.labelKey || 'nombre';

                const normalized = list.map(item => ({
                    value: item?.[valueKey],
                    label: item?.[labelKey]
                })).filter(o => o.value !== undefined && o.label !== undefined);

                selectOptionsCache.set(cacheKey, normalized);
                selectEl.innerHTML = '';
                normalized.forEach(o => {
                    const opt = document.createElement('option');
                    opt.value = o.value;
                    opt.textContent = o.label;
                    selectEl.appendChild(opt);
                });
            })
            .catch(() => {
                selectEl.innerHTML = '';
            });
    }

    // 2.1 Summary config + grafo de dependencias
    const summaryState = initSummaryState();

    function initSummaryState() {
        let raw = [];

        document.querySelectorAll('[data-summary-config]').forEach(tag => {
            try {
                const cfg = JSON.parse(tag.textContent);
                if (Array.isArray(cfg)) raw = raw.concat(cfg);
            } catch (e) { console.error("Error cargando summary", e); }
        });

        if (raw.length === 0 && Array.isArray(window.FORM_SUMMARY_CONFIG)) {
            raw = window.FORM_SUMMARY_CONFIG;
        }

        const config = normalizeSummaryConfig(raw);
        window.FORM_SUMMARY_CONFIG = config;

        if (config.length === 0) {
            return { config: [], order: [], byName: new Map(), hasCycle: false };
        }

        return buildSummaryGraph(config);
    }

    function normalizeSummaryConfig(raw) {
        if (!Array.isArray(raw)) return [];

        const cleaned = [];
        const seen = new Set();

        raw.forEach(item => {
            if (!item || typeof item !== 'object') return;
            const name = String(item.name || '').trim();
            if (!name) {
                console.warn('[summary] Item sin name, omitido.');
                return;
            }
            if (seen.has(name)) {
                console.warn(`[summary] Nombre duplicado: ${name}`);
                return;
            }

            const normalized = { ...item, name };
            normalized.type = typeof item.type === 'string' ? item.type : '';

            if (normalized.type === 'formula') {
                normalized.expression = typeof item.expression === 'string' ? item.expression : '';
                normalized.watch = Array.isArray(item.watch) ? item.watch.map(String) : [];
                if (!normalized.expression) {
                    console.warn(`[summary] Fórmula vacía: ${name}`);
                }
            } else if (normalized.type === 'sum') {
                normalized.source = item.source || {};
                if (!normalized.source.grid || !normalized.source.field) {
                    console.warn(`[summary] Sum sin source válido: ${name}`);
                }
            } else {
                console.warn(`[summary] Tipo inválido: ${name}`);
            }

            cleaned.push(normalized);
            seen.add(name);
        });

        return cleaned;
    }

    function buildSummaryGraph(config) {
        const byName = new Map();
        const nameSet = new Set();

        config.forEach(item => {
            byName.set(item.name, item);
            nameSet.add(item.name);
        });

        const deps = new Map();
        const outgoing = new Map();

        nameSet.forEach(name => {
            deps.set(name, new Set());
            outgoing.set(name, new Set());
        });

        config.forEach(item => {
            const depSet = new Set();

            if (item.type === 'formula') {
                const watch = Array.isArray(item.watch) ? item.watch : [];
                watch.forEach(dep => {
                    const key = String(dep);
                    if (nameSet.has(key) && key !== item.name) depSet.add(key);
                });

                if (depSet.size === 0 && typeof item.expression === 'string' && item.expression) {
                    nameSet.forEach(candidate => {
                        if (candidate === item.name) return;
                        const re = new RegExp(`\\b${escapeRegExp(candidate)}\\b`);
                        if (re.test(item.expression)) depSet.add(candidate);
                    });
                }
            }

            deps.set(item.name, depSet);
            depSet.forEach(d => outgoing.get(d).add(item.name));
        });

        const inDegree = new Map();
        nameSet.forEach(name => inDegree.set(name, deps.get(name).size));

        const queue = [];
        inDegree.forEach((deg, name) => {
            if (deg === 0) queue.push(name);
        });

        const order = [];
        while (queue.length > 0) {
            const name = queue.shift();
            order.push(name);

            outgoing.get(name).forEach(next => {
                const deg = inDegree.get(next) - 1;
                inDegree.set(next, deg);
                if (deg === 0) queue.push(next);
            });
        }

        const hasCycle = order.length !== nameSet.size;
        if (hasCycle) {
            console.warn('[summary] Ciclo de dependencias detectado, usando orden original.');
        }

        return {
            config,
            byName,
            order: hasCycle ? config.map(item => item.name) : order,
            hasCycle
        };
    }

    function escapeRegExp(value) {
        return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function getGridTotal(gridName, colName) {
        if (!gridName || !colName) return 0;

        let sum = 0;
        document.querySelectorAll(
            `[data-grid="${gridName}"] [data-column="${colName}"]`
        ).forEach(el => {
            sum += parseFloat(el.value || 0) || 0;
        });

        return sum;
    }

    function normalizeTotalsFormula(formula) {
        if (typeof formula !== 'string') return '';
        return formula.replace(/sum\s*\(\s*([a-zA-Z0-9_]+)\s*\)/g, (match, col) => {
            return `sum("${col}")`;
        });
    }

    function updateSummary() {
        if (!summaryState || summaryState.config.length === 0) return;

        const values = {};
        summaryState.config.forEach(item => values[item.name] = 0);

        summaryState.order.forEach(name => {
            const item = summaryState.byName.get(name);
            if (!item) return;

            let res = 0;
            if (item.type === 'sum') {
                res = getGridTotal(item.source?.grid, item.source?.field);
            } else if (item.type === 'formula') {
                try {
                    const fn = new Function('s', `with(s){ return ${item.expression}; }`);
                    res = fn(values) || 0;
                } catch (e) {
                    res = 0;
                }
            }

            values[name] = res;

            const label = document.querySelector(`[data-summary="${name}"]`);
            if (label) label.textContent = res.toFixed(2);
        });

        window.FORM_STORE = values;
        localStorage.setItem('FORM_STORE', JSON.stringify(window.FORM_STORE));
    }

    // 3. Función para Agregar Fila
    window.addRow = function (gridName) {
        const grid = grids[gridName];
        if (!grid) return;

        const tbody = document.querySelector(`[data-grid="${gridName}"] tbody`);
        const rowIndex = tbody.querySelectorAll('tr').length;

        const tr = document.createElement('tr');
        tr.dataset.rowIndex = rowIndex;

        grid.config.columns.forEach(col => {
            const td = document.createElement('td');
            td.className = 'border p-2';

            const inputCfg = col.input || { type: 'text' };
            const type = (inputCfg.type || 'text').trim().toLowerCase();

            let el;
            if (type === 'select') {
                el = document.createElement('select');
                el.className = 'w-full border rounded px-2 py-1 text-sm';
                populateSelectOptions(el, inputCfg, gridName, col.name);
            } else {
                el = document.createElement('input');
                el.type = (type === 'number' || type === 'currency') ? 'number' : 'text';
                el.className = 'w-full border rounded px-2 py-1 text-sm' + (col.formula ? ' bg-gray-100 font-semibold' : '');
                if (col.formula) el.readOnly = true;
                el.value = 0;
            }

            el.dataset.column = col.name;
            el.dataset.gridName = gridName;
            el.dataset.rowIndex = rowIndex;

            td.appendChild(el);
            tr.appendChild(td);
        });

        // Botón eliminar
        const tdDel = document.createElement('td');
        tdDel.className = 'border text-center';
        tdDel.innerHTML = `<button type="button" class="text-red-500 font-bold" onclick="this.closest('tr').remove(); window.recalcAll('${gridName}')">&times;</button>`;
        tr.appendChild(tdDel);

        tbody.appendChild(tr);
    };

    // 4. Escuchar cambios
    document.addEventListener('input', (e) => {
        const gridName = e.target.dataset.gridName;
        if (gridName) {
            window.recalcAll(gridName);
        }
    });

    // 5. Motor de Cálculos (Fila + Pie de Grid + Summary)
    window.recalcAll = function(gridName) {
        const grid = grids[gridName];
        const rows = document.querySelectorAll(`[data-grid="${gridName}"] tbody tr`);
        const totals = {};

        // Inicializar sumadores para el pie del grid
        grid.config.columns.forEach(c => totals[c.name] = 0);

        rows.forEach(tr => {
            const rowData = {};
            // Capturar datos crudos
            tr.querySelectorAll('[data-column]').forEach(el => {
                rowData[el.dataset.column] = parseFloat(el.value) || 0;
            });

            // Procesar Fórmulas de Fila (Cantidad * Valor, etc.)
            grid.config.columns.forEach(col => {
                const formulaExpr = typeof col.formula === 'string'
                    ? col.formula
                    : col.formula?.expression;

                if (formulaExpr) {
                    try {
                        const fn = new Function('r', `with(r){ return ${formulaExpr}; }`);
                        const res = fn(rowData) || 0;
                        rowData[col.name] = res;
                        const input = tr.querySelector(`[data-column="${col.name}"]`);
                        if (input) input.value = res.toFixed(2);
                    } catch (e) {}
                }
                // Sumar para el total inferior
                totals[col.name] += rowData[col.name];
            });
        });

        // A. Actualizar Pie de Grid (Labels de 10.00, 1000.00, etc.)
        Object.entries(totals).forEach(([colName, val]) => {
            const footerLabel = document.querySelector(`[data-total="${gridName}.${colName}"]`);
            if (footerLabel) {
                footerLabel.textContent = val.toFixed(2);
            }
        });

        // A.1 Totales legacy con fórmula (sum(col))
        if (Array.isArray(grid.config.totals) && grid.config.totals.length > 0) {
            grid.config.totals.forEach(def => {
                if (!def || !def.name || !def.formula) return;
                const expr = normalizeTotalsFormula(def.formula);
                let value = 0;
                try {
                    const fn = new Function('sum', `return ${expr};`);
                    value = fn(col => totals[col] || 0) || 0;
                } catch (e) {
                    value = 0;
                }
                const label = document.querySelector(`[data-grid-total="${gridName}.${def.name}"]`);
                if (label) label.textContent = value.toFixed(2);
            });
        }

        // B. Actualizar GRID_STORE
        window.GRID_STORE[gridName] = { totals: totals };
        localStorage.setItem('GRID_STORE', JSON.stringify(window.GRID_STORE));

        // C. Sincronizar FORM_STORE y Resumen de Facturación
        updateSummary();
    };

    // Botones de agregar
    document.querySelectorAll('[data-add-row]').forEach(btn => {
        btn.onclick = () => window.addRow(btn.dataset.addRow);
    });

    // Carga inicial
    setTimeout(() => {
        Object.keys(grids).forEach(g => window.recalcAll(g));
    }, 500);
});
