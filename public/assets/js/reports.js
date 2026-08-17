/* Central de relatórios: gráficos Chart.js, atalhos de navegação e análise IA opcional. */
(function () {
    'use strict';

    function money(value) {
        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));
    }

    function initCharts(data) {
        if (!window.Chart) return;

        var dark = document.body.classList.contains('dark-mode') || document.documentElement.dataset.theme === 'dark';
        Chart.defaults.color = dark ? '#c8d3de' : '#4b5563';
        Chart.defaults.borderColor = dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.07)';
        var palette = ['#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#6366f1', '#14b8a6', '#f97316', '#64748b'];

        function chart(id, type, dataset, options) {
            var el = document.getElementById(id);
            if (!el || !dataset || !dataset.labels || !dataset.labels.length) return;
            new Chart(el, {
                type: type,
                data: { labels: dataset.labels, datasets: [dataset.config] },
                options: Object.assign({ responsive: true, maintainAspectRatio: false }, options || {})
            });
        }

        chart('chartReportLeads', 'line', {
            labels: data.leads.labels,
            config: { label: 'Leads cadastrados', data: data.leads.data, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.14)', fill: true, tension: .35, pointRadius: 2 }
        }, { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } });

        chart('chartReportRevenue', 'line', {
            labels: data.revenue.labels,
            config: { label: 'Receita realizada', data: data.revenue.data, borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.13)', fill: true, tension: .35, pointRadius: 2 }
        }, { plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return money(ctx.parsed.y); } } } }, scales: { y: { beginAtZero: true, ticks: { callback: function (value) { return money(value); } } } } });

        chart('chartReportStatus', 'doughnut', {
            labels: data.status.labels,
            config: { data: data.status.data, backgroundColor: palette, borderWidth: 1 }
        }, { plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 10 } } } } });

        chart('chartReportSource', 'bar', {
            labels: data.source.labels,
            config: { data: data.source.data, backgroundColor: '#3b82f6', borderRadius: 6 }
        }, { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } });

        chart('chartReportLoss', 'doughnut', {
            labels: data.loss.labels,
            config: { data: data.loss.data, backgroundColor: palette, borderWidth: 1 }
        }, { plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 10 } } } } });

        chart('chartReportState', 'bar', {
            labels: data.state.labels,
            config: { data: data.state.data, backgroundColor: '#8b5cf6', borderRadius: 6 }
        }, { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } });

        chart('chartReportTeam', 'bar', {
            labels: data.team.labels,
            config: { label: 'Conversão', data: data.team.data, backgroundColor: '#22c55e', borderRadius: 6 }
        }, { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return Number(ctx.parsed.x || 0).toLocaleString('pt-BR') + '%'; } } } }, scales: { x: { beginAtZero: true, ticks: { callback: function (value) { return value + '%'; } } } } });

        chart('chartReportTasks', 'doughnut', {
            labels: data.tasks.labels,
            config: { data: data.tasks.data, backgroundColor: palette, borderWidth: 1 }
        }, { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } } });
    }

    function initNavigation() {
        document.querySelectorAll('.tc-report-nav a').forEach(function (link) {
            link.addEventListener('click', function () {
                document.querySelectorAll('.tc-report-nav a').forEach(function (item) { item.classList.remove('active'); });
                link.classList.add('active');
            });
        });
    }

    function initPeriodControl() {
        var period = document.getElementById('tcReportPeriod');
        if (!period) return;
        period.addEventListener('change', function () {
            if (period.value !== 'custom') {
                var inputs = document.querySelectorAll('#tcReportFilters input[type="date"]');
                inputs.forEach(function (input) { input.value = ''; });
            }
        });
    }

    function initAi(root) {
        var open = document.getElementById('tcReportAiOpen');
        var panel = document.getElementById('tcReportAiPanel');
        var close = document.getElementById('tcReportAiClose');
        var form = document.getElementById('tcReportAiForm');
        var result = document.getElementById('tcReportAiResult');
        var submit = document.getElementById('tcReportAiSubmit');
        if (!open || !panel || !form || !result || !submit) return;

        open.addEventListener('click', function () {
            panel.hidden = false;
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
        if (close) close.addEventListener('click', function () { panel.hidden = true; });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var reportFilters = document.getElementById('tcReportFilters');
            var payload = new URLSearchParams(reportFilters ? new FormData(reportFilters) : undefined);
            payload.set('question', String(new FormData(form).get('question') || ''));
            payload.set('csrf_token', root.dataset.csrf || '');
            submit.disabled = true;
            result.hidden = false;
            result.textContent = 'Analisando os indicadores agregados…';

            try {
                var response = await fetch(root.dataset.aiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: payload.toString(),
                    credentials: 'same-origin'
                });
                var data = await response.json().catch(function () { return { success: false, message: 'Resposta inválida da análise.' }; });
                result.textContent = data.success ? (data.text || 'Análise sem conteúdo.') : (data.message || 'Não foi possível gerar a análise agora.');
            } catch (error) {
                result.textContent = 'Não foi possível conectar à análise agora. Tente novamente em instantes.';
            } finally {
                submit.disabled = false;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('tcReportsApp');
        if (!root) return;
        try { initCharts(JSON.parse(root.dataset.charts || '{}')); } catch (error) { /* gráficos vazios nunca bloqueiam a tela */ }
        initNavigation();
        initPeriodControl();
        initAi(root);
    });
}());
