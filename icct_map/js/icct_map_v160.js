(function () {
    'use strict';

    const cfgEl = document.getElementById('icct-map-config');
    const canvas = document.getElementById('icct-map-canvas');
    const workspace = document.getElementById('icct-map-workspace');

    if (!cfgEl || !canvas || !workspace || typeof window.L === 'undefined') {
        return;
    }

    let cfg;
    try {
        cfg = JSON.parse(cfgEl.textContent || '{}');
    } catch (e) {
        canvas.innerHTML = '<div class="icct-map-error">Invalid map configuration.</div>';
        return;
    }

    /*
     * Cacti can load plugin pages through AJAX navigation.  Always tear down an older
     * instance before creating a new Leaflet map so state labels/layers never stack.
     */
    if (window.ICCTIndiaRefreshTimer) {
        window.clearInterval(window.ICCTIndiaRefreshTimer);
        window.ICCTIndiaRefreshTimer = null;
    }
    if (window.ICCTIndiaMap && typeof window.ICCTIndiaMap.remove === 'function') {
        try {
            window.ICCTIndiaMap.off();
            window.ICCTIndiaMap.remove();
        } catch (e) {}
    }
    window.ICCTIndiaMap = null;
    canvas.innerHTML = '';
    if (canvas._leaflet_id) {
        try { delete canvas._leaflet_id; } catch (e) { canvas._leaflet_id = undefined; }
    }

    let current = cfg.initial || {sites: [], summary: {}};
    let selectedSiteId = null;
    let map = null;
    let stateLayer = null;
    let stateLabelLayer = null;
    let siteLayer = null;
    let indiaBounds = null;
    const stateBounds = new Map();

    const HARD_INDIA_BOUNDS = L.latLngBounds([4.0, 64.0], [40.0, 101.0]);

    const overviewLabels = new Set([
        'Jammu and Kashmir', 'Jammu & Kashmir', 'Ladakh', 'Himachal Pradesh',
        'Punjab', 'Rajasthan', 'Gujarat', 'Maharashtra', 'Madhya Pradesh',
        'Uttar Pradesh', 'Bihar', 'West Bengal', 'Jharkhand', 'Chhattisgarh',
        'Odisha', 'Telangana', 'Andhra Pradesh', 'Karnataka', 'Tamil Nadu',
        'Kerala', 'Assam', 'Arunachal Pradesh'
    ]);

    const smallStateNames = new Set([
        'Chandigarh', 'Delhi', 'NCT of Delhi', 'Goa', 'Sikkim', 'Tripura',
        'Mizoram', 'Manipur', 'Nagaland', 'Meghalaya', 'Puducherry', 'Pondicherry',
        'Lakshadweep', 'Dadra and Nagar Haveli and Daman and Diu',
        'Dadra & Nagar Haveli and Daman & Diu', 'Andaman and Nicobar Islands'
    ]);

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>'"]/g, function (c) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;'}[c];
        });
    }

    function statusColor(k) {
        return {
            up: '#218739',
            down: '#c83232',
            recovering: '#d97706',
            error: '#7c3aed',
            unknown: '#667085',
            disabled: '#8a939f'
        }[k] || '#667085';
    }

    function featureName(f) {
        const p = f && f.properties ? f.properties : {};
        return p.name || p.NAME_1 || p.ST_NM || p.st_nm || p.State_Name || p.state || p.STATE || p.NAME || '';
    }

    function shortStateName(name) {
        const map = {
            'Andaman and Nicobar Islands': 'A&N Islands',
            'Dadra and Nagar Haveli and Daman and Diu': 'Dadra & Nagar Haveli / Daman & Diu',
            'Dadra & Nagar Haveli and Daman & Diu': 'Dadra & Nagar Haveli / Daman & Diu',
            'Jammu & Kashmir': 'Jammu & Kashmir',
            'Jammu and Kashmir': 'Jammu & Kashmir'
        };
        return map[name] || name;
    }

    function filters() {
        const status = document.getElementById('icct-status-filter');
        const search = document.getElementById('icct-map-search');
        return {
            status: status ? status.value : '',
            q: search ? String(search.value || '').trim().toLowerCase() : ''
        };
    }

    function visible(site) {
        const f = filters();
        const key = site.health && site.health.status_key ? site.health.status_key : 'unknown';
        if (f.status && key !== f.status) return false;
        if (!f.q) return true;
        const hay = [site.name, site.city, site.state, site.country, site.alternate_id].join(' ').toLowerCase();
        return hay.indexOf(f.q) !== -1;
    }

    function renderDrawer(site) {
        const drawer = document.getElementById('icct-site-drawer');
        if (!drawer) return;

        if (!site) {
            drawer.innerHTML = '<div class="drawer-empty"><strong>Select an ICCT site</strong><span>Click a marker to view native Cacti site/device health.</span></div>';
            return;
        }

        const health = site.health || {};
        const counts = health.counts || {};
        const link = health.link || {status_key:'not_configured', status_label:'Not configured'};
        const location = [site.city, site.state, site.country].filter(Boolean).join(', ');
        const editUrl = cfg.cactiUrl + site.site_url;
        const devicesUrl = cfg.cactiUrl + site.devices_url;

        drawer.innerHTML =
            '<div class="drawer-head">' +
                '<div><span class="drawer-kicker">CACTI SITE</span><h3>' + esc(site.name) + '</h3></div>' +
                '<span class="drawer-status status-' + esc(health.status_key || 'unknown') + '">' + esc(health.status_label || 'Unknown') + '</span>' +
            '</div>' +
            '<div class="drawer-grid">' +
                '<div><span>Location</span><strong>' + esc(location || '—') + '</strong></div>' +
                '<div><span>Coordinates</span><strong>' + Number(site.latitude).toFixed(5) + ', ' + Number(site.longitude).toFixed(5) + '</strong></div>' +
                '<div><span>Devices</span><strong>' + Number(site.host_count || 0) + '</strong></div>' +
                '<div><span>Communication link</span><strong class="link-' + esc(link.status_key || 'not_configured') + '">' + esc(link.status_label || 'Not configured') + '</strong></div>' +
            '</div>' +
            '<div class="device-health-row">' +
                '<span class="h-up">Up ' + Number(counts.up || 0) + '</span>' +
                '<span class="h-down">Down ' + Number((counts.down || 0) + (counts.error || 0)) + '</span>' +
                '<span>Recovering ' + Number(counts.recovering || 0) + '</span>' +
                '<span>Unknown ' + Number(counts.unknown || 0) + '</span>' +
            '</div>' +
            '<div class="drawer-note">Current position comes from native Cacti Sites. Device health comes from native Cacti hosts linked through <code>host.site_id</code>.</div>' +
            '<div class="drawer-actions">' +
                '<a class="button" href="' + esc(devicesUrl) + '">View Devices</a>' +
                (cfg.canManage ? '<a class="button" href="' + esc(editUrl) + '">Edit Site</a>' : '') +
            '</div>';
    }

    function siteTooltipPermanent() {
        if (!cfg.showLabels || !map) return false;
        const count = (current.sites || []).filter(visible).length;
        return map.getZoom() >= 6 || count <= 20;
    }

    function renderSites() {
        if (!map) return;

        if (siteLayer) {
            siteLayer.clearLayers();
        } else {
            siteLayer = L.layerGroup().addTo(map);
        }

        const permanent = siteTooltipPermanent();

        (current.sites || []).forEach(function (site) {
            if (!visible(site)) return;

            const health = site.health || {};
            const marker = L.circleMarker([Number(site.latitude), Number(site.longitude)], {
                radius: Number(site.id) === Number(selectedSiteId) ? 10 : 8,
                color: '#ffffff',
                weight: 2,
                fillColor: statusColor(health.status_key),
                fillOpacity: 1,
                className: 'icct-site-marker'
            });

            marker.bindTooltip(esc(site.name), {
                permanent: permanent,
                direction: 'right',
                offset: [10, 0],
                className: 'icct-site-label'
            });

            marker.on('click', function () {
                selectedSiteId = Number(site.id);
                renderSites();
                renderDrawer(site);
            });

            marker.addTo(siteLayer);
        });
    }

    function updateSummary(summary) {
        Object.keys(summary || {}).forEach(function (key) {
            const el = document.querySelector('[data-summary="' + key + '"]');
            if (el) el.textContent = summary[key];
        });
    }

    function refresh() {
        const center = map ? map.getCenter() : null;
        const zoom = map ? map.getZoom() : null;

        fetch(cfg.baseUrl + '/ajax.php?action=snapshot', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'X-Requested-With':'XMLHttpRequest'}
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (!data.ok) return;
            current = data;
            updateSummary(data.summary || {});
            const updated = document.getElementById('icct-map-refreshed');
            if (updated) updated.textContent = data.refreshed_at || '';
            renderSites();

            if (selectedSiteId) {
                const site = (current.sites || []).find(function (s) {
                    return Number(s.id) === Number(selectedSiteId);
                });
                renderDrawer(site || null);
            }

            /* Status refresh must never change the operator's viewport. */
            if (map && center && zoom != null) {
                map.setView(center, zoom, {animate:false});
            }
        }).catch(function () {});
    }

    function addStateBounds(name, layer) {
        if (!name || !layer || typeof layer.getBounds !== 'function') return;
        const bounds = layer.getBounds();
        if (!bounds || !bounds.isValid()) return;

        /*
         * Some public India GeoJSON sources split a state into multiple Features
         * (mainland + islands). Keep the largest feature as the label anchor so a
         * tiny offshore polygon cannot drag the permanent state label away.
         */
        const sw = bounds.getSouthWest();
        const ne = bounds.getNorthEast();
        const score = Math.abs((ne.lat - sw.lat) * (ne.lng - sw.lng));
        const existing = stateBounds.get(name);
        if (!existing || score > existing.score) {
            stateBounds.set(name, {bounds: L.latLngBounds(sw, ne), score: score});
        }
    }

    function shouldShowStateLabel(name, zoom) {
        if (zoom >= 6.5) return true;
        if (zoom >= 5.3) return !smallStateNames.has(name);
        return overviewLabels.has(name);
    }

    function renderStateLabels() {
        if (!map) return;
        if (!stateLabelLayer) {
            stateLabelLayer = L.layerGroup().addTo(map);
        }
        stateLabelLayer.clearLayers();

        const zoom = map.getZoom();
        stateBounds.forEach(function (entry, name) {
            if (!shouldShowStateLabel(name, zoom)) return;
            const center = entry.bounds.getCenter();
            const icon = L.divIcon({
                className: 'icct-state-label-clean',
                html: '<span>' + esc(shortStateName(name)) + '</span>',
                iconSize: null,
                iconAnchor: [0, 0]
            });
            L.marker(center, {
                icon: icon,
                interactive: false,
                keyboard: false,
                zIndexOffset: 50
            }).addTo(stateLabelLayer);
        });
    }

    function initMap(states) {
        map = L.map(canvas, {
            zoomControl: true,
            attributionControl: false,
            preferCanvas: true,
            minZoom: 3.5,
            maxZoom: 10,
            zoomSnap: 0.25,
            zoomDelta: 0.5,
            wheelPxPerZoomLevel: 90,
            maxBounds: HARD_INDIA_BOUNDS,
            maxBoundsViscosity: 0.92,
            worldCopyJump: false,
            dragging: true,
            scrollWheelZoom: true,
            touchZoom: true,
            doubleClickZoom: true,
            keyboard: true
        });
        window.ICCTIndiaMap = map;

        stateBounds.clear();
        stateLayer = L.geoJSON(states, {
            style: function (feature) {
                const name = featureName(feature);
                let hash = 0;
                for (let i = 0; i < name.length; i++) hash = (hash + name.charCodeAt(i)) % 997;
                const fills = ['#eaf1f7','#dfe9f2','#e8eef4','#d9e5ef','#edf3f8','#e2ebf3'];
                return {
                    color: '#6b8195',
                    weight: 1.05,
                    fillColor: fills[hash % fills.length],
                    fillOpacity: 0.96
                };
            },
            onEachFeature: function (feature, layer) {
                const name = featureName(feature);
                addStateBounds(name, layer);

                if (name) {
                    /* Hover-only tooltip. Permanent labels are rendered once per unique state below. */
                    layer.bindTooltip(esc(name), {
                        permanent: false,
                        sticky: true,
                        direction: 'top',
                        className: 'icct-state-tooltip'
                    });
                }

                layer.on({
                    mouseover: function () {
                        layer.setStyle({weight: 2.1, color: '#294b68', fillOpacity: 1});
                        if (layer.bringToFront) layer.bringToFront();
                    },
                    mouseout: function () {
                        if (stateLayer) stateLayer.resetStyle(layer);
                    }
                });
            }
        }).addTo(map);

        indiaBounds = stateLayer.getBounds();
        if (!indiaBounds || !indiaBounds.isValid()) {
            indiaBounds = HARD_INDIA_BOUNDS;
        }

        map.setMaxBounds(indiaBounds.pad(0.12));
        map.fitBounds(indiaBounds, {padding:[24,24], maxZoom:5});

        L.control.scale({imperial:false, position:'bottomright', maxWidth:120}).addTo(map);

        renderStateLabels();
        renderSites();

        map.on('zoomend', function () {
            renderStateLabels();
            renderSites();
        });
    }

    function invalidateMap() {
        if (!map) return;
        window.setTimeout(function () { map.invalidateSize({pan:false}); }, 60);
        window.setTimeout(function () { map.invalidateSize({pan:false}); }, 220);
    }

    function setCompact(on) {
        workspace.classList.toggle('is-compact', on);
        const btn = document.getElementById('icct-map-compact');
        if (btn) btn.textContent = on ? 'Restore Height' : 'Minimize';
        try { window.localStorage.setItem('icctMapCompact', on ? '1' : '0'); } catch (e) {}
        invalidateMap();
    }

    function setDetailsHidden(on) {
        workspace.classList.toggle('details-hidden', on);
        const btn = document.getElementById('icct-map-details-toggle');
        if (btn) btn.textContent = on ? 'Show Details' : 'Hide Details';
        try { window.localStorage.setItem('icctMapDetailsHidden', on ? '1' : '0'); } catch (e) {}
        invalidateMap();
    }

    function setMaximized(on) {
        workspace.classList.toggle('is-maximized', on);
        document.body.classList.toggle('icct-map-body-locked', on);
        const btn = document.getElementById('icct-map-maximize');
        if (btn) btn.textContent = on ? 'Restore' : 'Maximize';
        invalidateMap();
    }

    fetch(cfg.statesUrl, {
        credentials: 'same-origin',
        cache: 'no-store'
    }).then(function (response) {
        if (!response.ok) throw new Error('State/UT map data unavailable');
        return response.json();
    }).then(function (data) {
        if (!data || data.type !== 'FeatureCollection' || !Array.isArray(data.features) || !data.features.length) {
            throw new Error('Invalid state/UT GeoJSON');
        }
        initMap(data);
    }).catch(function (err) {
        canvas.innerHTML = '<div class="icct-map-error"><strong>India state/UT map data could not be loaded.</strong><br>' + esc(err.message) + '</div>';
    });

    const search = document.getElementById('icct-map-search');
    const status = document.getElementById('icct-status-filter');
    const fit = document.getElementById('icct-map-fit');
    const compact = document.getElementById('icct-map-compact');
    const details = document.getElementById('icct-map-details-toggle');
    const maximize = document.getElementById('icct-map-maximize');

    if (search) search.addEventListener('input', renderSites);
    if (status) status.addEventListener('change', renderSites);
    if (fit) fit.addEventListener('click', function () {
        if (map && indiaBounds) map.fitBounds(indiaBounds, {padding:[24,24], maxZoom:5});
    });
    if (compact) compact.addEventListener('click', function () {
        setCompact(!workspace.classList.contains('is-compact'));
    });
    if (details) details.addEventListener('click', function () {
        setDetailsHidden(!workspace.classList.contains('details-hidden'));
    });
    if (maximize) maximize.addEventListener('click', function () {
        setMaximized(!workspace.classList.contains('is-maximized'));
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && workspace.classList.contains('is-maximized')) {
            setMaximized(false);
        }
    });

    try {
        setCompact(window.localStorage.getItem('icctMapCompact') === '1');
        setDetailsHidden(window.localStorage.getItem('icctMapDetailsHidden') === '1');
    } catch (e) {}

    const seconds = Number(cfg.refreshSeconds || 30);
    if (seconds > 0) {
        window.ICCTIndiaRefreshTimer = window.setInterval(refresh, seconds * 1000);
    }
})();
