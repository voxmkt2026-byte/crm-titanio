/* Indicadores: interações de gráficos, período e mapa Leaflet por volume/conversão. */
(function () {
    'use strict';

    function leadUrl(base, extra) {
        try {
            var url = new URL(base, window.location.origin);
            Object.keys(extra || {}).forEach(function (key) { url.searchParams.set(key, extra[key]); });
            return url.toString();
        } catch (error) {
            var query = new URLSearchParams(extra || {}).toString();
            return query ? base + (base.indexOf('?') >= 0 ? '&' : '?') + query : base;
        }
    }

    function initPeriodControl() {
        var period = document.getElementById('tcIndicatorPeriod');
        if (!period) return;
        period.addEventListener('change', function () {
            if (period.value !== 'custom') {
                document.querySelectorAll('#tcIndicatorFilters input[type="date"]').forEach(function (input) { input.value = ''; });
            }
        });
    }

    function initCharts(data) {
        if (!window.Chart) return;
        var dark = document.body.classList.contains('dark-mode') || document.documentElement.dataset.theme === 'dark';
        Chart.defaults.color = dark ? '#c8d3de' : '#4b5563';
        Chart.defaults.borderColor = dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.07)';

        var trendEl = document.getElementById('chartIndicatorTrend');
        if (trendEl && data.trend.labels.length) {
            new Chart(trendEl, {
                type: 'line',
                data: {
                    labels: data.trend.labels,
                    datasets: [{ label: 'Leads captados', data: data.trend.data, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.14)', fill: true, tension: .35, pointRadius: 3 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    onClick: function (event, elements) {
                        if (!elements.length) return;
                        var day = data.trend.days[elements[0].index];
                        if (day) window.location.href = leadUrl(data.leadMapBaseUrl, { date_from: day, date_to: day });
                    }
                }
            });
        }

        var sourceEl = document.getElementById('chartIndicatorSource');
        if (sourceEl && data.sources.labels.length) {
            new Chart(sourceEl, {
                type: 'bar',
                data: {
                    labels: data.sources.labels,
                    datasets: [{ label: 'Conversão', data: data.sources.data, backgroundColor: '#22c55e', borderRadius: 6 }]
                },
                options: {
                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return Number(ctx.parsed.x || 0).toLocaleString('pt-BR') + '%'; } } } },
                    scales: { x: { beginAtZero: true, ticks: { callback: function (value) { return value + '%'; } } } },
                    onClick: function (event, elements) {
                        if (!elements.length) return;
                        var source = data.sources.keys[elements[0].index];
                        if (source) window.location.href = leadUrl(data.leadMapBaseUrl, { source: source });
                    }
                }
            });
        }
    }

    function initMap(data) {
        var mapEl = document.getElementById('tcLeafletMap');
        var unavailableEl = document.getElementById('tcMapUnavailable');
        if (!mapEl || typeof L === 'undefined') {
            if (mapEl) mapEl.classList.add('d-none');
            if (unavailableEl) unavailableEl.classList.remove('d-none');
            return;
        }

        var mode = 'volume';
        var maxConversion = Math.max.apply(null, Object.keys(data.stateData).map(function (uf) { return Number(data.stateData[uf].conversion || 0); }).concat([0]));
        function colorFor(value, maximum) {
            if (value <= 0) return '#e5e9ef';
            var ratio = maximum > 0 ? value / maximum : 0;
            if (ratio > .66) return '#1e3a5f';
            if (ratio > .33) return '#3b82f6';
            return '#93c5fd';
        }
        function featureStyle(feature) {
            var uf = feature.properties && feature.properties.sigla;
            var item = data.stateData[uf] || { total: 0, conversion: 0 };
            var value = mode === 'conversion' ? Number(item.conversion || 0) : Number(item.total || 0);
            return { fillColor: colorFor(value, mode === 'conversion' ? maxConversion : data.maxTotal), fillOpacity: .75, color: '#fff', weight: 1.5 };
        }

        var map = L.map(mapEl, { scrollWheelZoom: false, zoomControl: true }).setView([-14.2, -51.9], 4);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors', maxZoom: 10, minZoom: 3 }).addTo(map);
        window.setTimeout(function () { map.invalidateSize(); }, 120);
        if (typeof ResizeObserver !== 'undefined') new ResizeObserver(function () { map.invalidateSize({ pan: false }); }).observe(mapEl);

        var heatLayer = null;
        if (typeof L.heatLayer === 'function') {
            var heatPoints = [];
            Object.keys(data.stateData).forEach(function (uf) {
                var centroid = data.centroids[uf];
                if (centroid && Number(data.stateData[uf].total || 0) > 0) heatPoints.push([centroid[0], centroid[1], data.stateData[uf].total]);
            });
            if (heatPoints.length) heatLayer = L.heatLayer(heatPoints, { radius: 45, blur: 35, maxZoom: 6, max: data.maxTotal || 1 }).addTo(map);
        }

        fetch(data.geoJsonUrl)
            .then(function (response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.json(); })
            .then(function (geojson) {
                var statesLayer = L.geoJSON(geojson, {
                    style: featureStyle,
                    onEachFeature: function (feature, layer) {
                        var uf = feature.properties && feature.properties.sigla;
                        var name = (feature.properties && feature.properties.name) || uf;
                        var item = data.stateData[uf] || { total: 0, fechados: 0, conversion: 0 };
                        layer.bindTooltip('<strong>' + name + ' (' + uf + ')</strong><br>' + item.total + ' lead(s)<br>' + item.fechados + ' fechado(s)<br>Conversão: ' + String(item.conversion).replace('.', ',') + '%' + (item.total > 0 ? '<br><em>Clique para abrir os leads</em>' : ''), { sticky: true });
                        layer.on('mouseover', function () { layer.setStyle({ fillOpacity: .92, weight: 2.5 }); if (item.total > 0) mapEl.style.cursor = 'pointer'; });
                        layer.on('mouseout', function () { layer.setStyle(featureStyle(feature)); mapEl.style.cursor = ''; });
                        layer.on('click', function () { if (item.total > 0 && uf) window.location.href = leadUrl(data.leadMapBaseUrl, { state: uf }); });
                    }
                }).addTo(map);
                if (statesLayer.getBounds().isValid()) map.fitBounds(statesLayer.getBounds(), { padding: [12, 12] });
                document.querySelectorAll('[data-map-mode]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        mode = button.dataset.mapMode === 'conversion' ? 'conversion' : 'volume';
                        document.querySelectorAll('[data-map-mode]').forEach(function (item) { item.classList.toggle('active', item === button); });
                        if (heatLayer) {
                            if (mode === 'volume' && !map.hasLayer(heatLayer)) map.addLayer(heatLayer);
                            if (mode === 'conversion' && map.hasLayer(heatLayer)) map.removeLayer(heatLayer);
                        }
                        statesLayer.setStyle(featureStyle);
                    });
                });
            })
            .catch(function (error) {
                console.error('Titanium CRM - falha ao carregar brazil-states.geojson:', error);
                mapEl.classList.add('d-none');
                if (unavailableEl) unavailableEl.classList.remove('d-none');
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('tcIndicatorsApp');
        if (!root) return;
        var data;
        try { data = JSON.parse(root.dataset.indicators || '{}'); } catch (error) { return; }
        initPeriodControl();
        initCharts(data);
        initMap(data);
    });
}());
