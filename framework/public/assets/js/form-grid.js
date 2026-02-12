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

    // ---- Safe Expression Engine (no eval/new Function) ----
    function tokenizeExpression(expression) {
        const tokens = [];
        const expr = String(expression || '');
        let i = 0;
        const len = expr.length;

        while (i < len) {
            const ch = expr[i];
            if (/\s/.test(ch)) { i++; continue; }

            if (ch === '"' || ch === "'") {
                const quote = ch;
                i++;
                let value = '';
                while (i < len && expr[i] !== quote) {
                    if (expr[i] === '\\' && i + 1 < len) {
                        value += expr[i + 1];
                        i += 2;
                        continue;
                    }
                    value += expr[i];
                    i++;
                }
                if (expr[i] !== quote) throw new Error('String sin cierre');
                i++;
                tokens.push({ type: 'string', value });
                continue;
            }

            if (/[0-9.]/.test(ch)) {
                let num = '';
                while (i < len && /[0-9.]/.test(expr[i])) {
                    num += expr[i];
                    i++;
                }
                if (num === '.' || (num.split('.').length > 2)) {
                    throw new Error('Numero invalido');
                }
                tokens.push({ type: 'number', value: parseFloat(num) });
                continue;
            }

            if (/[a-zA-Z_]/.test(ch)) {
                let ident = '';
                while (i < len && /[a-zA-Z0-9_]/.test(expr[i])) {
                    ident += expr[i];
                    i++;
                }
                const upper = ident.toUpperCase();
                if (upper === 'AND') {
                    tokens.push({ type: 'operator', value: '&&' });
                } else if (upper === 'OR') {
                    tokens.push({ type: 'operator', value: '||' });
                } else if (upper === 'NOT') {
                    tokens.push({ type: 'operator', value: '!' });
                } else {
                    tokens.push({ type: 'identifier', value: ident });
                }
                continue;
            }

            const two = expr.slice(i, i + 2);
            if (['>=', '<=', '==', '!=', '&&', '||'].includes(two)) {
                tokens.push({ type: 'operator', value: two });
                i += 2;
                continue;
            }

            if (['+', '-', '*', '/', '>', '<', '(', ')', ','].includes(ch)) {
                if (ch === '(' || ch === ')') {
                    tokens.push({ type: 'paren', value: ch });
                } else if (ch === ',') {
                    tokens.push({ type: 'comma', value: ch });
                } else {
                    tokens.push({ type: 'operator', value: ch });
                }
                i++;
                continue;
            }

            throw new Error('Token invalido');
        }

        return tokens;
    }

    class ExpressionParser {
        constructor(tokens) {
            this.tokens = tokens;
            this.pos = 0;
        }

        peek() { return this.tokens[this.pos]; }
        next() { return this.tokens[this.pos++]; }
        isAtEnd() { return this.pos >= this.tokens.length; }

        match(type, value) {
            const token = this.peek();
            if (!token || token.type !== type) return false;
            if (value !== undefined && token.value !== value) return false;
            this.next();
            return true;
        }

        parseExpression() { return this.parseOr(); }

        parseOr() {
            let node = this.parseAnd();
            while (this.match('operator', '||')) {
                node = { type: 'binary', op: '||', left: node, right: this.parseAnd() };
            }
            return node;
        }

        parseAnd() {
            let node = this.parseEquality();
            while (this.match('operator', '&&')) {
                node = { type: 'binary', op: '&&', left: node, right: this.parseEquality() };
            }
            return node;
        }

        parseEquality() {
            let node = this.parseComparison();
            while (true) {
                if (this.match('operator', '==')) {
                    node = { type: 'binary', op: '==', left: node, right: this.parseComparison() };
                } else if (this.match('operator', '!=')) {
                    node = { type: 'binary', op: '!=', left: node, right: this.parseComparison() };
                } else {
                    break;
                }
            }
            return node;
        }

        parseComparison() {
            let node = this.parseTerm();
            while (true) {
                if (this.match('operator', '>=')) {
                    node = { type: 'binary', op: '>=', left: node, right: this.parseTerm() };
                } else if (this.match('operator', '<=')) {
                    node = { type: 'binary', op: '<=', left: node, right: this.parseTerm() };
                } else if (this.match('operator', '>')) {
                    node = { type: 'binary', op: '>', left: node, right: this.parseTerm() };
                } else if (this.match('operator', '<')) {
                    node = { type: 'binary', op: '<', left: node, right: this.parseTerm() };
                } else {
                    break;
                }
            }
            return node;
        }

        parseTerm() {
            let node = this.parseFactor();
            while (true) {
                if (this.match('operator', '+')) {
                    node = { type: 'binary', op: '+', left: node, right: this.parseFactor() };
                } else if (this.match('operator', '-')) {
                    node = { type: 'binary', op: '-', left: node, right: this.parseFactor() };
                } else {
                    break;
                }
            }
            return node;
        }

        parseFactor() {
            let node = this.parseUnary();
            while (true) {
                if (this.match('operator', '*')) {
                    node = { type: 'binary', op: '*', left: node, right: this.parseUnary() };
                } else if (this.match('operator', '/')) {
                    node = { type: 'binary', op: '/', left: node, right: this.parseUnary() };
                } else {
                    break;
                }
            }
            return node;
        }

        parseUnary() {
            if (this.match('operator', '!')) {
                return { type: 'unary', op: '!', value: this.parseUnary() };
            }
            if (this.match('operator', '-')) {
                return { type: 'unary', op: '-', value: this.parseUnary() };
            }
            if (this.match('operator', '+')) {
                return { type: 'unary', op: '+', value: this.parseUnary() };
            }
            return this.parsePrimary();
        }

        parsePrimary() {
            const token = this.peek();
            if (!token) throw new Error('Expresion incompleta');

            if (this.match('number')) {
                return { type: 'number', value: token.value };
            }
            if (this.match('string')) {
                return { type: 'string', value: token.value };
            }
            if (this.match('identifier')) {
                const name = token.value;
                if (this.match('paren', '(')) {
                    const args = [];
                    if (!this.match('paren', ')')) {
                        do {
                            args.push(this.parseExpression());
                        } while (this.match('comma'));
                        if (!this.match('paren', ')')) {
                            throw new Error('Falta )');
                        }
                    }
                    return { type: 'call', name, args };
                }
                if (name.toLowerCase() === 'true') return { type: 'bool', value: true };
                if (name.toLowerCase() === 'false') return { type: 'bool', value: false };
                return { type: 'var', name };
            }
            if (this.match('paren', '(')) {
                const expr = this.parseExpression();
                if (!this.match('paren', ')')) throw new Error('Falta )');
                return expr;
            }
            throw new Error('Token inesperado');
        }
    }

    function toNumber(value) {
        const num = Number(value);
        return Number.isFinite(num) ? num : 0;
    }

    function getBuiltins() {
        return {
            SUM: (...args) => args.reduce((acc, val) => acc + toNumber(val), 0),
            MIN: (...args) => {
                const nums = args.map(toNumber);
                return nums.length ? Math.min(...nums) : 0;
            },
            MAX: (...args) => {
                const nums = args.map(toNumber);
                return nums.length ? Math.max(...nums) : 0;
            },
            ROUND: (val, digits = 0) => {
                const factor = Math.pow(10, toNumber(digits));
                return Math.round(toNumber(val) * factor) / factor;
            },
            IF: (cond, truthy, falsy = 0) => (cond ? truthy : falsy),
        };
    }

    function evaluateAst(node, vars, functionsMap) {
        const builtins = getBuiltins();
        const fns = Object.assign({}, builtins, functionsMap || {});

        switch (node.type) {
            case 'number':
            case 'string':
            case 'bool':
                return node.value;
            case 'var':
                return vars && Object.prototype.hasOwnProperty.call(vars, node.name)
                    ? vars[node.name]
                    : 0;
            case 'unary': {
                const val = evaluateAst(node.value, vars, fns);
                if (node.op === '!') return !val;
                if (node.op === '-') return -toNumber(val);
                return toNumber(val);
            }
            case 'binary': {
                const left = evaluateAst(node.left, vars, fns);
                const right = evaluateAst(node.right, vars, fns);
                switch (node.op) {
                    case '+': return toNumber(left) + toNumber(right);
                    case '-': return toNumber(left) - toNumber(right);
                    case '*': return toNumber(left) * toNumber(right);
                    case '/': return toNumber(right) === 0 ? 0 : toNumber(left) / toNumber(right);
                    case '==': return left == right;
                    case '!=': return left != right;
                    case '>': return toNumber(left) > toNumber(right);
                    case '<': return toNumber(left) < toNumber(right);
                    case '>=': return toNumber(left) >= toNumber(right);
                    case '<=': return toNumber(left) <= toNumber(right);
                    case '&&': return !!left && !!right;
                    case '||': return !!left || !!right;
                    default: return 0;
                }
            }
            case 'call': {
                const fn = fns[node.name.toUpperCase()];
                if (typeof fn !== 'function') return 0;
                const args = node.args.map(arg => evaluateAst(arg, vars, fns));
                return fn(...args);
            }
            default:
                return 0;
        }
    }

    function compileExpression(expression) {
        if (!expression || typeof expression !== 'string') return null;
        try {
            const tokens = tokenizeExpression(expression);
            const parser = new ExpressionParser(tokens);
            const ast = parser.parseExpression();
            if (!parser.isAtEnd()) return null;
            return ast;
        } catch (e) {
            return null;
        }
    }

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

            if (col.formula && typeof col.formula.expression === 'string') {
                col.formula.compiled = compileExpression(col.formula.expression);
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
                normalized.compiled = compileExpression(normalized.expression);
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

    function getGridTotalsIndex() {
        const totalsByColumn = {};
        const duplicates = new Set();

        Object.keys(grids).forEach(gridName => {
            const cfg = grids[gridName]?.config;
            if (!cfg || !Array.isArray(cfg.columns)) return;

            cfg.columns.forEach(col => {
                const colName = col?.name;
                if (!colName) return;
                const total = getGridTotal(gridName, colName);
                if (Object.prototype.hasOwnProperty.call(totalsByColumn, colName)) {
                    duplicates.add(colName);
                } else {
                    totalsByColumn[colName] = total;
                }
            });
        });

        duplicates.forEach(name => {
            delete totalsByColumn[name];
        });

        return totalsByColumn;
    }

    function normalizeTotalsFormula(formula) {
        if (typeof formula !== 'string') return '';
        return formula.replace(/sum\s*\(\s*([a-zA-Z0-9_]+)\s*\)/g, (match, col) => {
            return `sum("${col}")`;
        });
    }

    function updateSummary() {
        if (!summaryState || summaryState.config.length === 0) return;

        const gridTotals = getGridTotalsIndex();
        const values = {};
        summaryState.config.forEach(item => values[item.name] = 0);

        summaryState.order.forEach(name => {
            const item = summaryState.byName.get(name);
            if (!item) return;

            let res = 0;
            if (item.type === 'sum') {
                res = getGridTotal(item.source?.grid, item.source?.field);
            } else if (item.type === 'formula') {
                const compiled = item.compiled || compileExpression(item.expression);
                if (compiled) {
                    const contextVars = Object.assign({}, gridTotals, values);
                    res = toNumber(evaluateAst(compiled, contextVars, {}));
                } else {
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
    function resolveGridName(target) {
        if (!target) return '';
        if (target.dataset && target.dataset.gridName) return target.dataset.gridName;
        const table = target.closest ? target.closest('[data-grid]') : null;
        return table ? table.dataset.grid : '';
    }

    document.addEventListener('input', (e) => {
        const gridName = resolveGridName(e.target);
        if (gridName) {
            window.recalcAll(gridName);
        }
    });

    document.addEventListener('change', (e) => {
        const gridName = resolveGridName(e.target);
        if (gridName) {
            window.recalcAll(gridName);
        }
    });

    // 5. Motor de Cálculos (Fila + Pie de Grid + Summary)
    window.recalcAll = function(gridName) {
        const grid = grids[gridName];
        const rows = document.querySelectorAll(`[data-grid="${gridName}"] tbody tr`);
        const totals = {};
        const rowsPayload = [];

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
                    const compiled = col.formula?.compiled || compileExpression(formulaExpr);
                    if (compiled) {
                        const res = toNumber(evaluateAst(compiled, rowData, {}));
                        rowData[col.name] = res;
                        const input = tr.querySelector(`[data-column="${col.name}"]`);
                        if (input) input.value = res.toFixed(2);
                    }
                }
                // Sumar para el total inferior
                totals[col.name] += rowData[col.name];
            });

            rowsPayload.push({ ...rowData });
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
                const compiled = def._compiled || compileExpression(expr);
                if (compiled) {
                    def._compiled = compiled;
                    value = toNumber(evaluateAst(compiled, {}, {
                        SUM: (col) => totals[col] || 0
                    }));
                }
                const label = document.querySelector(`[data-grid-total="${gridName}.${def.name}"]`);
                if (label) label.textContent = value.toFixed(2);
            });
        }

        // B. Actualizar GRID_STORE
        window.GRID_STORE[gridName] = { totals: totals, rows: rowsPayload };
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
