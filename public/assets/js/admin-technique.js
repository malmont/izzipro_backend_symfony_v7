/* Tableau de bord technique (T2) : courbes Chart.js (servi en local, aucun CDN) à partir du JSON posé dans #tech-data.
 * Séries : couleurs attribuées dans un ordre fixe (palette validée pour les daltonismes, jamais recyclée au-delà de 8 : les suivantes
 * sont regroupées sous « autres »), traits de 2 px, pas de point sauf au survol, infobulle commune à toutes les séries d'une heure,
 * légende seulement à partir de deux séries, une vue tableau repliée sous chaque courbe. Aucune donnée n'est chargée : tout vient du HTML. */
(function () {
    'use strict';
    var SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    var GRID = 'rgba(82, 81, 78, 0.12)';
    var INK = '#52514e';
    var node = document.getElementById('tech-data');
    if (!node || typeof Chart === 'undefined') { return; }
    var data;
    try { data = JSON.parse(node.textContent); } catch (e) { return; }

    function fold(datasets) {
        if (datasets.length <= SERIES.length) { return datasets; }
        var kept = datasets.slice(0, SERIES.length - 1);
        var rest = datasets.slice(SERIES.length - 1);
        var other = { label: 'autres', data: rest[0].data.map(function (_, i) {
            return rest.reduce(function (sum, d) { return sum + (Number(d.data[i]) || 0); }, 0);
        }) };
        kept.push(other);
        return kept;
    }

    function table(chart) {
        var holder = document.querySelector('[data-table-for="' + chart.id + '"]');
        if (!holder) { return; }
        var t = document.createElement('table');
        t.className = 'table table-sm tech-table-valeurs';
        var head = '<tr><th>Heure</th>' + chart.datasets.map(function (d) { return '<th class="text-end">' + escapeHtml(d.label) + '</th>'; }).join('') + '</tr>';
        var rows = chart.labels.map(function (label, i) {
            return '<tr><td>' + escapeHtml(label) + '</td>' + chart.datasets.map(function (d) {
                var v = d.data[i];
                return '<td class="text-end">' + (v === null || v === undefined ? '—' : escapeHtml(String(v))) + '</td>';
            }).join('') + '</tr>';
        }).join('');
        t.innerHTML = '<thead>' + head + '</thead><tbody>' + rows + '</tbody>';
        holder.appendChild(t);
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }

    function draw(chart) {
        var canvas = document.getElementById('tech-chart-' + chart.id);
        if (!canvas) { return; }
        var datasets = fold(chart.datasets).map(function (d, i) {
            return {
                label: d.label,
                data: d.data,
                borderColor: SERIES[i],
                backgroundColor: SERIES[i],
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
                pointHitRadius: 12,
                tension: 0.2,
                spanGaps: true,
                fill: false
            };
        });
        new Chart(canvas, {
            type: 'line',
            data: { labels: chart.labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: datasets.length > 1, position: 'bottom', labels: { color: INK, boxWidth: 12, boxHeight: 12, usePointStyle: true } },
                    tooltip: { mode: 'index', intersect: false, callbacks: { label: function (ctx) { return ctx.dataset.label + ' : ' + ctx.formattedValue + (chart.unit ? ' ' + chart.unit : ''); } } }
                },
                scales: {
                    x: { ticks: { color: INK, maxTicksLimit: 8, maxRotation: 0 }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: INK, maxTicksLimit: 6 }, grid: { color: GRID }, border: { display: false } }
                }
            }
        });
        table(chart);
    }

    (data.site || []).forEach(draw);
    (data.plateforme || []).forEach(draw);
})();
