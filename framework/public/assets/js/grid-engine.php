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

class GridCalculator {

    constructor() {
        this.rules = <?php echo json_encode($formConfig['grids'] ?? []); ?>;
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
            if (col.formula) {
                let expr = col.formula.expression;
                col.formula.watch.forEach(v => {
                    expr = expr.replaceAll(v, `getVal("${v}")`);
                });
                try {
                    setVal(col.name, eval(expr));
                } catch {}
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
        <?php if (!empty($formConfig['summary'])): ?>
        <?php foreach ($formConfig['summary'] as $item): ?>
            const el_<?php echo $item['name']; ?> =
                document.querySelector('[data-summary="<?php echo $item['name']; ?>"]');
            if (el_<?php echo $item['name']; ?>) {
                el_<?php echo $item['name']; ?>.textContent =
                    this.calculateSummaryValue('<?php echo $item['name']; ?>').toFixed(2);
            }
        <?php endforeach; ?>
        <?php endif; ?>
    }

    calculateSummaryValue(name) {
        switch (name) {
            <?php foreach ($formConfig['summary'] as $item): ?>
            case '<?php echo $item['name']; ?>':
                <?php if ($item['type'] === 'sum'): ?>
                    return this.getGridTotal('<?php echo $item['source']['grid']; ?>','<?php echo $item['source']['field']; ?>');
                <?php elseif ($item['type'] === 'formula'): ?>
                    return <?php echo $item['expression']; ?>;
                <?php endif; ?>
            <?php endforeach; ?>
            default: return 0;
        }
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
