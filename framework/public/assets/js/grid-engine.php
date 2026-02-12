<?php
header('Content-Type: application/javascript');
$configName = basename($_GET['config'] ?? '');

$frameworkRoot = dirname(__DIR__, 3);
$projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname($frameworkRoot) . '/project';

// Buscar JSON (nuevo layout primero, legacy después)
$candidates = [
    $projectRoot . '/contracts/forms/' . $configName,
    $projectRoot . '/views/clientes/' . $configName,
    $frameworkRoot . '/contracts/forms/' . $configName, // legacy kernel sample
    $frameworkRoot . '/views/clientes/' . $configName,
];

$jsonPath = null;
foreach ($candidates as $candidate) {
    if (file_exists($candidate)) {
        $jsonPath = $candidate;
        break;
    }
}

$formConfig = $jsonPath ? json_decode(file_get_contents($jsonPath), true) : [];
?>
// ---- Safe Expression Engine (no eval/new Function) ----
function sukiTokenizeExpression(expression) {
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

class SukiExpressionParser {
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

function sukiToNumber(value) {
    const num = Number(value);
    return Number.isFinite(num) ? num : 0;
}

function sukiBuiltins() {
    return {
        SUM: (...args) => args.reduce((acc, val) => acc + sukiToNumber(val), 0),
        MIN: (...args) => {
            const nums = args.map(sukiToNumber);
            return nums.length ? Math.min(...nums) : 0;
        },
        MAX: (...args) => {
            const nums = args.map(sukiToNumber);
            return nums.length ? Math.max(...nums) : 0;
        },
        ROUND: (val, digits = 0) => {
            const factor = Math.pow(10, sukiToNumber(digits));
            return Math.round(sukiToNumber(val) * factor) / factor;
        },
        IF: (cond, truthy, falsy = 0) => (cond ? truthy : falsy),
    };
}

function sukiEvaluateAst(node, vars, functionsMap) {
    const builtins = sukiBuiltins();
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
            const val = sukiEvaluateAst(node.value, vars, fns);
            if (node.op === '!') return !val;
            if (node.op === '-') return -sukiToNumber(val);
            return sukiToNumber(val);
        }
        case 'binary': {
            const left = sukiEvaluateAst(node.left, vars, fns);
            const right = sukiEvaluateAst(node.right, vars, fns);
            switch (node.op) {
                case '+': return sukiToNumber(left) + sukiToNumber(right);
                case '-': return sukiToNumber(left) - sukiToNumber(right);
                case '*': return sukiToNumber(left) * sukiToNumber(right);
                case '/': return sukiToNumber(right) === 0 ? 0 : sukiToNumber(left) / sukiToNumber(right);
                case '==': return left == right;
                case '!=': return left != right;
                case '>': return sukiToNumber(left) > sukiToNumber(right);
                case '<': return sukiToNumber(left) < sukiToNumber(right);
                case '>=': return sukiToNumber(left) >= sukiToNumber(right);
                case '<=': return sukiToNumber(left) <= sukiToNumber(right);
                case '&&': return !!left && !!right;
                case '||': return !!left || !!right;
                default: return 0;
            }
        }
        case 'call': {
            const fn = fns[node.name.toUpperCase()];
            if (typeof fn !== 'function') return 0;
            const args = node.args.map(arg => sukiEvaluateAst(arg, vars, fns));
            return fn(...args);
        }
        default:
            return 0;
    }
}

function sukiCompileExpression(expression) {
    if (!expression || typeof expression !== 'string') return null;
    try {
        const tokens = sukiTokenizeExpression(expression);
        const parser = new SukiExpressionParser(tokens);
        const ast = parser.parseExpression();
        if (!parser.isAtEnd()) return null;
        return ast;
    } catch (e) {
        return null;
    }
}

function normalizeSummaryConfig(raw) {
    if (!Array.isArray(raw)) return [];

    const cleaned = [];
    const seen = new Set();

    raw.forEach(item => {
        if (!item || typeof item !== 'object') return;
        const name = String(item.name || '').trim();
        if (!name || seen.has(name)) return;

        const normalized = { ...item, name };
        normalized.type = typeof item.type === 'string' ? item.type : '';

        if (normalized.type === 'formula') {
            normalized.expression = typeof item.expression === 'string' ? item.expression : '';
            normalized.watch = Array.isArray(item.watch) ? item.watch.map(String) : [];
            normalized.compiled = sukiCompileExpression(normalized.expression);
        } else if (normalized.type === 'sum') {
            normalized.source = item.source || {};
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


class GridCalculator {

    constructor() {
        this.rules = <?php echo json_encode($formConfig['grids'] ?? []); ?>;
        this.summaryConfig = <?php echo json_encode($formConfig['summary'] ?? []); ?>;
        this.summaryState = buildSummaryGraph(normalizeSummaryConfig(this.summaryConfig));
        this.init();
    }

    init() {
        this.rules.forEach(grid => this.initGrid(grid.name));
    }

    initGrid(gridName) {
        const table = document.querySelector(`[data-grid="${gridName}"]`);
        if (!table) return;

        const btnAdd = document.querySelector(`[data-add-row="${gridName}"]`);
        if (btnAdd) btnAdd.onclick = () => this.addRow(gridName);

        table.addEventListener('input', e => {
            if (e.target.matches('input, select')) {
                const row = e.target.closest('tr');
                this.calculateRow(gridName, row);
                this.updateFormSummary();
            }
        });

        table.addEventListener('click', e => {
            if (e.target.dataset.removeRow !== undefined) {
                e.target.closest('tr').remove();
                this.updateGridTotals(gridName);
                this.updateFormSummary();
            }
        });
    }

    addRow(gridName) {
        const gridConfig = this.rules.find(g => g.name === gridName);
        if (!gridConfig) return;

        const tbody = document.querySelector(`[data-grid="${gridName}"] tbody`);
        const rowIndex = tbody.children.length;
        const tr = document.createElement('tr');

        gridConfig.columns.forEach(col => {

            const td = document.createElement('td');
            td.className = 'border p-2';

            const inputCfg = col.input || {};
            const type = (inputCfg.type || 'text').toLowerCase();
            const hasFormula = !!col.formula;

            let field;

            /* ===============================
               ✅ SELECT REAL
            =============================== */
            if (type === 'select') {

                field = document.createElement('select');
                field.className = 'w-full border rounded px-2 py-1 text-sm bg-white';

                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Seleccione...';
                field.appendChild(placeholder);

                if (Array.isArray(inputCfg.options)) {
                    inputCfg.options.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.label;
                        field.appendChild(option);
                    });
                }

            } 
            /* ===============================
               ✅ INPUT REAL
            =============================== */
            else {

                field = document.createElement('input');
                field.type = (type === 'currency') ? 'number' : type;
                field.value = '0';
                field.className = 'w-full border rounded px-2 py-1 text-sm';

                if (type === 'number' || type === 'currency') {
                    field.step = '0.01';
                }
            }

            // Atributos comunes
            field.name = `${gridName}[${rowIndex}][${col.name}]`;
            field.dataset.column = col.name;
            field.dataset.gridName = gridName;
            field.dataset.rowIndex = rowIndex;

            if (hasFormula) {
                field.readOnly = true;
                field.classList.add('bg-gray-100', 'font-semibold');
            }

            td.appendChild(field);
            tr.appendChild(td);
        });

        // Botón eliminar
        const tdRemove = document.createElement('td');
        tdRemove.className = 'border p-2 text-center';
        const btnRemove = document.createElement('button');
        btnRemove.type = 'button';
        btnRemove.dataset.removeRow = '';
        btnRemove.textContent = '×';
        btnRemove.className = 'text-red-600 font-bold text-lg';
        tdRemove.appendChild(btnRemove);
        tr.appendChild(tdRemove);

        tbody.appendChild(tr);

        this.updateGridTotals(gridName);
        this.updateFormSummary();
    }

    calculateRow(gridName, row) {
        const gridConfig = this.rules.find(g => g.name === gridName);
        if (!gridConfig) return;

        const getVal = col =>
            parseFloat(row.querySelector(`[data-column="${col}"]`)?.value || 0);

        const setVal = (col, val) => {
            const el = row.querySelector(`[data-column="${col}"]`);
            if (el) el.value = val.toFixed(2);
        };

        gridConfig.columns.forEach(col => {
            const formulaExpr = typeof col.formula === 'string'
                ? col.formula
                : col.formula?.expression;
            if (formulaExpr) {
                const compiled = col.formula?.compiled || sukiCompileExpression(formulaExpr);
                if (compiled) {
                    col.formula = col.formula || {};
                    col.formula.compiled = compiled;
                    const values = {};
                    gridConfig.columns.forEach(c => values[c.name] = getVal(c.name));
                    const result = sukiEvaluateAst(compiled, values, {});
                    setVal(col.name, sukiToNumber(result));
                }
            }
        });

        this.updateGridTotals(gridName);
    }

    updateGridTotals(gridName) {
        const gridConfig = this.rules.find(g => g.name === gridName);
        if (!gridConfig) return;

        gridConfig.columns.forEach(col => {
            if (col.total) {
                let sum = 0;
                document.querySelectorAll(
                    `[data-grid="${gridName}"] [data-column="${col.name}"]`
                ).forEach(i => sum += parseFloat(i.value || 0));

                const el = document.querySelector(`[data-total="${gridName}.${col.name}"]`);
                if (el) el.textContent = sum.toFixed(2);
            }
        });
    }

    updateFormSummary() {
        if (!this.summaryState || this.summaryState.config.length === 0) return;

        const values = {};
        this.summaryState.config.forEach(item => values[item.name] = 0);

        this.summaryState.order.forEach(name => {
            const item = this.summaryState.byName.get(name);
            if (!item) return;

            let res = 0;
            if (item.type === 'sum') {
                res = this.getGridTotal(item.source?.grid, item.source?.field);
            } else if (item.type === 'formula') {
                const compiled = item.compiled || sukiCompileExpression(item.expression);
                if (compiled) {
                    item.compiled = compiled;
                    res = sukiToNumber(sukiEvaluateAst(compiled, values, {}));
                }
            }

            values[name] = res;
            const el = document.querySelector(`[data-summary="${name}"]`);
            if (el) el.textContent = res.toFixed(2);
        });
    }

    getGridTotal(gridName, col) {
        let sum = 0;
        document.querySelectorAll(
            `[data-grid="${gridName}"] [data-column="${col}"]`
        ).forEach(i => sum += parseFloat(i.value || 0));
        return sum;
    }
}

window.gridCalc = new GridCalculator();
