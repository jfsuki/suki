document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('tbody[data-endpoint]').forEach(tbody => {
        const endpoint = tbody.dataset.endpoint;

        fetch(`/api.php?route=${endpoint}`)
            .then(res => res.json())
            .then(json => {
                if (json.status !== 'success') return;

                tbody.innerHTML = '';

                json.data.forEach(row => {
                    const tr = document.createElement('tr');

                    Object.values(row).forEach(val => {
                        const td = document.createElement('td');
                        td.className = 'border p-2';
                        td.textContent = val;
                        tr.appendChild(td);
                    });

                    tbody.appendChild(tr);
                });
            })
            .catch(err => {
                console.error('Error cargando tabla:', err);
            });
    });
});
