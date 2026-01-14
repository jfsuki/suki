<?php
header('Content-Type: application/javascript');
$configName = basename($_GET['config'] ?? '');

// Busca el JSON en la carpeta del script actual, subiendo niveles
$jsonPath = realpath(__DIR__ . '/../../views/clientes/' . $configName);

if (!$jsonPath || !file_exists($jsonPath)) {
    // Si falla, intentamos una ruta relativa al DOCUMENT_ROOT de Laragon
    $jsonPath = $_SERVER['DOCUMENT_ROOT'] . '/../views/clientes/' . $configName;
}

$formConfig = json_decode(file_get_contents($jsonPath), true);
?>

class GridCalculator {
    constructor() {
        this.init();
        // Guardamos las reglas de validación del JSON en una variable JS
        this.rules = <?php echo json_encode($formConfig['grids'] ?? []); ?>;
    }

    init() {
        <?php foreach ($formConfig['grids'] as $grid): ?>
            this.initGrid('<?php echo $grid['name']; ?>');
        <?php endforeach; ?>
    }

    initGrid(gridName) {
        const table = document.querySelector(`[data-grid="${gridName}"]`);
        if (!table) return;

        // Botón agregar
        const btnAdd = document.querySelector(`[data-add-row="${gridName}"]`);
        if (btnAdd) btnAdd.onclick = () => this.addRow(gridName);

        // Listener para Cálculos y VALIDACIÓN inmediata
        table.addEventListener('input', (e) => {
            if (e.target.matches('input, select')) {
                const row = e.target.closest('tr');
                this.calculateRow(gridName, row);
                this.validateField(e.target, gridName); // <--- VALIDACIÓN AQUÍ
                this.updateFormSummary();
            }
        });

        // Validar también al salir del campo (por si quedó vacío)
        table.addEventListener('blur', (e) => {
            if (e.target.matches('input, select')) {
                this.validateField(e.target, gridName);
            }
        }, true);
    }

    validateField(input, gridName) {
    const colName = input.dataset.column;
    // Buscamos la configuración de esta columna en las reglas cargadas del JSON
    const grid = this.rules.find(g => g.name === gridName);
    const colConfig = grid?.columns.find(c => c.name === colName);
    
    if (!colConfig || !colConfig.validation) return;

    const val = input.value.trim();
    const rules = colConfig.validation;
    let isInvalid = false;

    // VALIDACIÓN LÓGICA
    if (rules.required && val === "") {
        isInvalid = true;
    } else if (rules.pattern && val !== "") {
        const regex = new RegExp(rules.pattern);
        if (!regex.test(val)) isInvalid = true;
    }

    // APLICACIÓN DE ESTILOS (Tailwind)
    if (isInvalid) {
        input.classList.add('border-red-500', 'ring-2', 'ring-red-200', 'bg-red-50');
        input.classList.remove('border-gray-300');
    } else {
        input.classList.remove('border-red-500', 'ring-2', 'ring-red-200', 'bg-red-50');
        input.classList.add('border-gray-300');
    }
}

    // ... (Mantén calculateRow, updateFormSummary y getGridTotal igual que antes) ...
    calculateRow(gridName, row) {
        const getVal = (col) => parseFloat(row.querySelector(`[data-column="${col}"]`)?.value || 0);
        const setVal = (col, val) => {
            const input = row.querySelector(`[data-column="${col}"]`);
            if (input) input.value = val.toFixed(2);
        };
        switch (gridName) {
            <?php foreach ($formConfig['grids'] as $grid): ?>
            case '<?php echo $grid['name']; ?>':
                <?php foreach ($grid['columns'] as $col): 
                    if (isset($col['formula'])): 
                        $expr = $col['formula']['expression'];
                        foreach ($col['formula']['watch'] as $v) $expr = str_replace($v, "getVal('$v')", $expr);
                ?>
                setVal('<?php echo $col['name']; ?>', <?php echo $expr; ?>);
                <?php endif; endforeach; ?>
                break;
            <?php endforeach; ?>
        }
    }

    updateFormSummary() {
        <?php if(isset($formConfig['summary'])): ?>
            <?php foreach ($formConfig['summary'] as $item): ?>
                const el = document.querySelector('[data-summary="<?php echo $item['name']; ?>"]');
                if (el) el.textContent = this.calculateSummaryValue('<?php echo $item['name']; ?>').toLocaleString('en-US', {minimumFractionDigits:2});
            <?php endforeach; ?>
        <?php endif; ?>
    }

    calculateSummaryValue(name) {
        switch (name) {
            <?php if(isset($formConfig['summary'])): ?>
            <?php foreach ($formConfig['summary'] as $item): ?>
            case '<?php echo $item['name']; ?>':
                <?php if ($item['type'] === 'sum'): ?>
                    return this.getGridTotal('<?php echo $item['source']['grid']; ?>', '<?php echo $item['source']['field']; ?>');
                <?php elseif ($item['type'] === 'formula'): 
                    $jsExpr = $item['expression'];
                    foreach ($item['watch'] as $v) $jsExpr = str_replace($v, "this.calculateSummaryValue('$v')", $jsExpr);
                ?>
                    return <?php echo $jsExpr; ?>;
                <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
            default: return 0;
        }
    }

    getGridTotal(gridName, col) {
        let sum = 0;
        document.querySelectorAll(`[data-grid="${gridName}"] [data-column="${col}"]`).forEach(i => sum += parseFloat(i.value || 0));
        return sum;
    }
}

window.gridCalc = new GridCalculator();