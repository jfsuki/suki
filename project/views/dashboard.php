<?php
// project/views/dashboard.php
?>
<div class="max-w-6xl mx-auto p-6 space-y-6">
    <div class="bg-white p-4 rounded-lg shadow-sm border">
        <h1 class="text-xl font-bold text-gray-800">Dashboard runtime</h1>
        <p class="text-xs text-gray-500">Carga un dashboard real desde datos.</p>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-4">
            <input id="dash-form" class="border rounded px-3 py-2 text-sm" placeholder="form (ej: fact.form)" value="<?php echo htmlspecialchars($_GET['form'] ?? '', ENT_QUOTES); ?>">
            <input id="dash-id" class="border rounded px-3 py-2 text-sm" placeholder="dashboard id (opcional)" value="<?php echo htmlspecialchars($_GET['dashboard'] ?? '', ENT_QUOTES); ?>">
            <input id="dash-entity" class="border rounded px-3 py-2 text-sm" placeholder="entity (opcional)" value="<?php echo htmlspecialchars($_GET['entity'] ?? '', ENT_QUOTES); ?>">
            <button id="dash-load" class="bg-indigo-600 text-white text-sm font-bold rounded px-3 py-2">Cargar</button>
        </div>
    </div>

    <div id="dash-message" class="text-sm text-gray-500"></div>

    <div id="dash-kpis" class="grid grid-cols-1 md:grid-cols-3 gap-3"></div>

    <div id="dash-charts" class="grid grid-cols-1 lg:grid-cols-2 gap-4"></div>
</div>

<script>
(function(){
    const formInput = document.getElementById('dash-form');
    const dashInput = document.getElementById('dash-id');
    const entityInput = document.getElementById('dash-entity');
    const message = document.getElementById('dash-message');
    const kpiContainer = document.getElementById('dash-kpis');
    const chartContainer = document.getElementById('dash-charts');

    function clear() {
        kpiContainer.innerHTML = '';
        chartContainer.innerHTML = '';
    }

    function setMessage(text) {
        message.textContent = text || '';
    }

    function renderKpi(widget) {
        const card = document.createElement('div');
        card.className = 'bg-white border rounded-lg p-4 shadow-sm';
        const title = document.createElement('div');
        title.className = 'text-xs text-gray-500 uppercase font-bold';
        title.textContent = widget.label || 'KPI';
        const value = document.createElement('div');
        value.className = 'text-2xl font-bold text-indigo-700 mt-1';
        value.textContent = Number(widget.value || 0).toFixed(2);
        card.appendChild(title);
        card.appendChild(value);
        kpiContainer.appendChild(card);
    }

    function renderChart(widget) {
        const card = document.createElement('div');
        card.className = 'bg-white border rounded-lg p-4 shadow-sm';
        const title = document.createElement('div');
        title.className = 'text-xs text-gray-500 uppercase font-bold';
        title.textContent = widget.label || 'Grafica';
        card.appendChild(title);

        const list = document.createElement('div');
        list.className = 'mt-3 space-y-2';
        const series = Array.isArray(widget.series) ? widget.series : [];
        const max = Math.max(1, ...series.map(s => Number(s.value || 0)));
        series.forEach(item => {
            const row = document.createElement('div');
            row.className = 'flex items-center gap-2';
            const label = document.createElement('div');
            label.className = 'text-xs text-gray-600 w-24 truncate';
            label.textContent = item.label;
            const barWrap = document.createElement('div');
            barWrap.className = 'flex-1 h-3 bg-gray-100 rounded';
            const bar = document.createElement('div');
            bar.className = 'h-3 bg-indigo-500 rounded';
            bar.style.width = ((Number(item.value || 0) / max) * 100) + '%';
            barWrap.appendChild(bar);
            const val = document.createElement('div');
            val.className = 'text-xs text-gray-500 w-10 text-right';
            val.textContent = item.value;
            row.appendChild(label);
            row.appendChild(barWrap);
            row.appendChild(val);
            list.appendChild(row);
        });
        if (series.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'text-xs text-gray-400';
            empty.textContent = 'Sin datos para graficar.';
            list.appendChild(empty);
        }
        card.appendChild(list);
        chartContainer.appendChild(card);
    }

    async function loadDashboard() {
        const form = (formInput.value || '').trim();
        if (!form) {
            setMessage('Define el nombre del formulario.');
            return;
        }
        const dash = (dashInput.value || '').trim();
        const entity = (entityInput.value || '').trim();
        setMessage('Cargando dashboard...');
        clear();
        try {
            const url = `/api/dashboards?form=${encodeURIComponent(form)}&dashboard=${encodeURIComponent(dash)}&entity=${encodeURIComponent(entity)}`;
            const res = await fetch(url);
            const json = await res.json();
            if (json.status !== 'success') {
                setMessage(json.message || 'Error');
                return;
            }
            const widgets = json.data.widgets || [];
            setMessage('Dashboard cargado.');
            widgets.forEach(widget => {
                if (widget.type === 'kpi') renderKpi(widget);
                if (widget.type === 'chart') renderChart(widget);
            });
        } catch (e) {
            setMessage('Error: ' + (e.message || e));
        }
    }

    document.getElementById('dash-load').addEventListener('click', loadDashboard);

    if (formInput.value) {
        loadDashboard();
    }
})();
</script>
