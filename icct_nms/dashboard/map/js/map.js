/** Offline geographic topology over native Cacti Sites with live status and rich site details. */
(function () {
    'use strict';
    const dataElement = document.getElementById('icctMapData');
    const mapElement = document.getElementById('icctSiteMap');
    if (!dataElement || !mapElement || !window.L) return;

    let data;
    try { data = JSON.parse(dataElement.textContent || '{}'); }
    catch (error) { return; }

    const endpoint = dataElement.dataset.summaryUrl || 'dashboard/controllers/topology.php';
    const indiaBounds = L.latLngBounds([6, 68], [37.5, 97.5]);
    const page = document.querySelector('.icct-map-page');
    const mapPanel = document.getElementById('icct-panel-map');
    const statusMessage = document.getElementById('icctMapStatus');
    const siteDialog = document.getElementById('mapSiteDialog');
    const alarmColors = {Critical:'#ff4148',Major:'#ff7226',Minor:'#ff9b17',Warning:'#43aa91',Information:'#277f9b'};
    const markerColors = {Online:'#21864a',Offline:'#c83232',Disabled:'#777777',Other:'#aa740a'};
    const siteById = new Map();
    const markers = new Map();
    const detailCache = new Map();
    let countryView = true;
    let stateError = '';
    let refreshPending = false;
    let liveStatus = statusMessage.textContent || '';

    function sizePage() {
        if (!page) return;
        const top = page.getBoundingClientRect().top + window.scrollY;
        page.style.setProperty('--icct-map-page-height', Math.max(240, window.innerHeight - top) + 'px');
    }
    sizePage();

    const map = L.map(mapElement, {
        zoomControl:false, attributionControl:false, maxBoundsViscosity:1,
        minZoom:2, maxZoom:18, zoomSnap:1, zoomDelta:1,
        zoomAnimation:true, fadeAnimation:true, markerZoomAnimation:true,
        wheelDebounceTime:30, wheelPxPerZoomLevel:60, preferCanvas:true
    });
    map.setMaxBounds(indiaBounds);

    // Optional detailed offline basemap. The server exposes only an authenticated
    // relay; the browser never needs direct access to GeoServer or the tile tree.
    const basemap = data.basemap && typeof data.basemap === 'object' ? data.basemap : null;
    let basemapLayer = null;
    if (basemap && basemap.url) {
        try {
            if (basemap.type === 'wms') {
                basemapLayer = L.tileLayer.wms(basemap.url, {
                    layers:'icct', format:'image/png', transparent:false,
                    minZoom:Number(basemap.min_zoom || 2), maxZoom:Number(basemap.max_zoom || 18),
                    tileSize:256, updateWhenIdle:true, keepBuffer:2
                });
            } else if (basemap.type === 'xyz') {
                basemapLayer = L.tileLayer(basemap.url, {
                    minZoom:Number(basemap.min_zoom || 2), maxZoom:Number(basemap.max_zoom || 18),
                    tileSize:256, updateWhenIdle:true, keepBuffer:2, noWrap:true,
                    errorTileUrl:'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs='
                });
            }
            if (basemapLayer) basemapLayer.addTo(map);
        } catch (error) { basemapLayer = null; }
    }

    function fitIndia() {
        map.setMinZoom(2);
        map.fitBounds(indiaBounds, {padding:[12,12], animate:false});
        map.setMinZoom(map.getZoom());
        countryView = true;
    }
    fitIndia();
    map.on('dragstart zoomstart', function () { countryView = false; });

    function setStatus(message) {
        liveStatus = message || '';
        const parts = [liveStatus, stateError].filter(Boolean);
        statusMessage.textContent = parts.join(' ');
        const mapVisible = !mapPanel.hidden;
        statusMessage.hidden = !mapVisible || parts.length === 0;
    }

    window.addEventListener('resize', sizePage);
    const resizeObserver = new ResizeObserver(function () {
        if (mapPanel.hidden) return;
        const refit = countryView;
        map.invalidateSize({pan:false});
        if (refit) fitIndia();
    });
    resizeObserver.observe(mapElement);

    // India-only offline state layer. No CDN or Internet tile request is used.
    map.createPane('statePolygons');
    map.getPane('statePolygons').style.zIndex = 350;
    map.createPane('stateLabels');
    map.getPane('stateLabels').style.zIndex = 450;
    map.getPane('stateLabels').style.pointerEvents = 'none';
    map.createPane('cityLabels');
    map.getPane('cityLabels').style.zIndex = 455;
    map.getPane('cityLabels').style.pointerEvents = 'none';
    fetch(data.states, {credentials:'same-origin', cache:'force-cache'}).then(function (response) {
        if (!response.ok) throw new Error('State data unavailable');
        return response.json();
    }).then(function (states) {
        const labels = [];
        L.geoJSON(states, {
            pane:'statePolygons',
            style:function (feature) { return {color:basemapLayer?'#6c777b':'#7d898d', weight:basemapLayer?0.8:1, fillColor:feature.properties.color, fillOpacity:basemapLayer?0.08:0.65}; },
            onEachFeature:function (feature, layer) {
                const name = document.createElement('span'); name.textContent = feature.properties.name;
                layer.bindTooltip(name, {sticky:true});
                const point = feature.properties.label_coordinates;
                if (!point) return;
                const label = document.createElement('span'); label.textContent = feature.properties.name;
                labels.push(L.marker([point[1], point[0]], {
                    pane:'stateLabels', interactive:false, keyboard:false,
                    icon:L.divIcon({className:'icct-state-label', html:label, iconSize:null})
                }).addTo(map));
            }
        }).addTo(map);
        function placeLabels() {
            const occupied = [];
            const showStateLabels = map.getZoom() <= 6;
            labels.forEach(function (label) {
                const el = label.getElement();
                if (!el || !el.firstElementChild) return;
                if (!showStateLabels) { el.style.visibility='hidden'; return; }
                el.style.visibility = 'visible';
                const rect = el.firstElementChild.getBoundingClientRect();
                if (occupied.some(function (r) {
                    return rect.left < r.right + 5 && rect.right + 5 > r.left && rect.top < r.bottom + 3 && rect.bottom + 3 > r.top;
                })) el.style.visibility = 'hidden';
                else occupied.push(rect);
            });
        }
        map.on('moveend zoomend resize', placeLabels);
        placeLabels();
    }).catch(function () {
        stateError = 'Local India state boundaries are unavailable.';
        setStatus(liveStatus);
    });
    if (!basemapLayer) {
        stateError = 'Offline city/town labels are available while zooming. Detailed roads/buildings require the approved local XYZ tiles or local GeoServer basemap.';
        setStatus(liveStatus);
    } else {
        basemapLayer.on('tileerror', function () {
            stateError = 'Some detailed basemap tiles are unavailable at this zoom level.';
            setStatus(liveStatus);
        });
    }

    // Bundled offline India city/town labels. They are intentionally rendered
    // progressively by zoom level so the country view stays readable and the
    // browser does not create hundreds of DOM nodes until the user zooms in.
    const cityRows = Array.isArray(data.cities) ? data.cities.filter(function (city) {
        return city && Number.isFinite(Number(city.lat)) && Number.isFinite(Number(city.lon)) && city.name;
    }) : [];
    const cityLayer = L.layerGroup().addTo(map);
    let cityRenderTimer = null;

    function cityLimitForZoom(zoom) {
        if (zoom <= 5) return 18;
        if (zoom === 6) return 45;
        if (zoom === 7) return 90;
        if (zoom === 8) return 150;
        return 260;
    }
    function cityClassForZoom(city, zoom) {
        const minZoom = Number(city.min_zoom || 8);
        if (minZoom <= 5) return 'metro';
        if (minZoom <= 6) return 'major';
        return zoom >= 9 ? 'town' : 'city';
    }
    function cityIcon(city, zoom) {
        const wrap = document.createElement('span');
        wrap.className = 'icct-city-label-inner ' + cityClassForZoom(city, zoom);
        const dot = document.createElement('i');
        dot.className = 'icct-city-dot';
        dot.setAttribute('aria-hidden', 'true');
        const label = document.createElement('span');
        label.className = 'icct-city-name';
        label.textContent = city.name;
        wrap.append(dot, label);
        return L.divIcon({className:'icct-city-label', html:wrap, iconSize:null, iconAnchor:[4,4]});
    }
    function renderCityLabels() {
        cityLayer.clearLayers();
        const zoom = map.getZoom();
        if (zoom < 5 || cityRows.length === 0) return;
        const bounds = map.getBounds().pad(0.20);
        const eligible = cityRows.filter(function (city) {
            const minZoom = Number(city.min_zoom || 8);
            return zoom >= minZoom && bounds.contains([Number(city.lat), Number(city.lon)]);
        }).sort(function (a,b) {
            const rank = Number(a.min_zoom || 8) - Number(b.min_zoom || 8);
            return rank || String(a.name).localeCompare(String(b.name));
        }).slice(0, cityLimitForZoom(zoom));

        const rendered = [];
        eligible.forEach(function (city) {
            const marker = L.marker([Number(city.lat), Number(city.lon)], {
                pane:'cityLabels', interactive:false, keyboard:false,
                icon:cityIcon(city, zoom), zIndexOffset:0
            }).addTo(cityLayer);
            rendered.push(marker);
        });

        // Declutter labels in priority order. Larger/major cities were inserted
        // first, so smaller labels yield when their rectangles overlap.
        window.requestAnimationFrame(function () {
            const occupied = [];
            rendered.forEach(function (marker) {
                const el = marker.getElement();
                if (!el) return;
                el.style.visibility = 'visible';
                const rect = el.getBoundingClientRect();
                const padded = {left:rect.left - 3, right:rect.right + 3, top:rect.top - 2, bottom:rect.bottom + 2};
                const overlaps = occupied.some(function (other) {
                    return padded.left < other.right && padded.right > other.left && padded.top < other.bottom && padded.bottom > other.top;
                });
                if (overlaps) el.style.visibility = 'hidden';
                else occupied.push(padded);
            });
        });
    }
    function scheduleCityLabels() {
        if (cityRenderTimer) window.clearTimeout(cityRenderTimer);
        cityRenderTimer = window.setTimeout(renderCityLabels, 80);
    }
    map.on('zoomend moveend resize', scheduleCityLabels);
    scheduleCityLabels();

    // A distance scale makes the offline map behave more like an operational
    // navigation map without introducing any Internet dependency.
    if (L.control && L.control.scale) {
        L.control.scale({imperial:false, metric:true, maxWidth:120, position:'bottomleft'}).addTo(map);
    }

    function node(tag, text, className) {
        const el = document.createElement(tag);
        if (text !== undefined) el.textContent = text;
        if (className) el.className = className;
        return el;
    }
    function coordinateText(coords) {
        if (!Array.isArray(coords) || coords.length !== 2) return 'Coordinates unavailable';
        return coords.map(function (value, index) {
            return Math.abs(Number(value)).toFixed(4) + '° ' + (index === 0 ? (value < 0 ? 'S' : 'N') : (value < 0 ? 'W' : 'E'));
        }).join(', ');
    }
    function aggregateFaults(site) {
        const totals = {};
        Object.keys(alarmColors).forEach(function (severity) { totals[severity] = 0; });
        (site.devices || []).forEach(function (device) {
            Object.keys(alarmColors).forEach(function (severity) { totals[severity] += Number((device.fault_counts || {})[severity] || 0); });
        });
        return totals;
    }
    function aggregateCounts(site) {
        const counts = {total:0,online:0,offline:0,disabled:0,other:0};
        (site.devices || []).forEach(function (device) {
            counts.total++;
            counts[device.status === 'Up' ? 'online' : device.status === 'Down' ? 'offline' : device.status === 'Disabled' ? 'disabled' : 'other']++;
        });
        return counts;
    }
    function stateFromCounts(counts) {
        if (Number(counts.offline || 0) > 0) return 'Offline';
        if (Number(counts.total || 0) > 0 && Number(counts.online || 0) === Number(counts.total || 0)) return 'Online';
        if (Number(counts.total || 0) > 0 && Number(counts.disabled || 0) === Number(counts.total || 0)) return 'Disabled';
        return 'Other';
    }
    function siteSummary(site) {
        const counts = site.counts || aggregateCounts(site);
        const faultCounts = site.fault_counts || aggregateFaults(site);
        return Object.assign({}, site, {counts:counts, fault_counts:faultCounts, status:site.status || stateFromCounts(counts)});
    }
    function markerColor(site) { return markerColors[siteSummary(site).status] || markerColors.Other; }

    function buildSiteHover(site) {
        const summary = siteSummary(site);
        const box = node('div', undefined, 'icct-map-site-hover');
        const head = node('div', undefined, 'icct-map-site-hover-head');
        head.append(node('strong', summary.name || 'Unnamed site'));
        const state = node('span', summary.status, 'icct-map-site-hover-status ' + String(summary.status || 'Other').toLowerCase());
        head.append(state); box.append(head);
        box.append(node('div', summary.location || 'Location not entered in Cacti Sites', 'icct-map-site-hover-location'));
        box.append(node('div', coordinateText(summary.coordinates), 'icct-map-site-hover-coordinates'));
        const grid = node('dl', undefined, 'icct-map-site-hover-counts');
        [['Devices','total'],['Up','online'],['Down','offline'],['Disabled','disabled']].forEach(function (entry) {
            const cell = node('div'); cell.append(node('dt', entry[0]), node('dd', String(Number(summary.counts[entry[1]] || 0)))); grid.append(cell);
        });
        box.append(grid);
        const alarms = node('div', undefined, 'icct-map-site-hover-alarms');
        Object.keys(alarmColors).forEach(function (severity) {
            const badge = node('span'); const dot = node('i'); dot.style.background = alarmColors[severity];
            badge.append(dot, document.createTextNode((severity === 'Information' ? 'Info' : severity) + ' ' + Number(summary.fault_counts[severity] || 0)));
            alarms.append(badge);
        });
        box.append(alarms, node('small', 'Click the site for the operational summary and topology drill-down.', 'icct-map-site-hover-hint'));
        return box;
    }

    function renderSiteDialog(site) {
        const summary = siteSummary(site);
        document.getElementById('mapSiteTitle').textContent = summary.name || 'Site';
        document.getElementById('mapSiteLocation').textContent = summary.location || 'Location not entered in Cacti Sites';
        document.getElementById('mapSiteCoordinates').textContent = coordinateText(summary.coordinates);
        const state = document.getElementById('mapSiteStatus');
        state.textContent = summary.status;
        state.className = 'device-status ' + (summary.status === 'Online' ? 'online' : summary.status === 'Offline' ? 'offline' : summary.status === 'Disabled' ? 'disabled' : 'other');
        const counts = document.getElementById('mapSiteCounts'); counts.replaceChildren();
        [['Total Devices','total'],['Online','online'],['Offline','offline'],['Disabled','disabled'],['Other / Unknown','other']].forEach(function (entry) {
            const row = node('div'); row.append(node('dt', entry[0]), node('dd', String(Number(summary.counts[entry[1]] || 0)))); counts.append(row);
        });
        const alarms = document.getElementById('mapSiteAlarms'); alarms.replaceChildren();
        Object.keys(alarmColors).forEach(function (severity) {
            const badge = node('span'); const dot = node('i'); dot.style.background = alarmColors[severity];
            badge.append(dot, document.createTextNode((severity === 'Warning' ? 'Warn' : severity === 'Information' ? 'Info' : severity) + ': ' + String(Number(summary.fault_counts[severity] || 0)).padStart(2,'0')));
            alarms.append(badge);
        });
        document.getElementById('mapSiteTopology').href = 'dashboard/controllers/topology.php?site_id=' + encodeURIComponent(summary.id) + '&view=topology';
    }

    async function refreshSiteDetail(site, marker, force) {
        const id = Number(site.id); const cached = detailCache.get(id); const now = Date.now();
        if (!force && cached && now - cached.at < 15000) return cached.site;
        try {
            const response = await fetch(endpoint + '?site_summary=' + encodeURIComponent(id), {credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error('Site summary unavailable');
            const fresh = await response.json();
            Object.assign(site, fresh);
            detailCache.set(id, {at:now, site:site});
            if (marker) { marker.setStyle({fillColor:markerColor(site)}); marker.setTooltipContent(buildSiteHover(site)); }
            if (siteDialog && siteDialog.open && Number(siteDialog.dataset.siteId) === id) renderSiteDialog(site);
            return site;
        } catch (error) { return site; }
    }

    function showSite(site, marker) {
        if (!siteDialog) return;
        siteDialog.dataset.siteId = String(site.id);
        renderSiteDialog(site);
        if (!siteDialog.open) siteDialog.showModal();
        refreshSiteDetail(site, marker, true);
    }

    function accessibleMarker(marker, site) {
        const el = marker.getElement();
        if (!el) return;
        el.setAttribute('role','button'); el.setAttribute('tabindex','0'); el.setAttribute('aria-label','Site ' + site.name + '. ' + siteSummary(site).status);
        if (el.dataset.icctKeyboardBound === '1') return;
        el.dataset.icctKeyboardBound = '1';
        el.addEventListener('focus', function () { marker.openTooltip(); refreshSiteDetail(site, marker, false); });
        el.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); marker.fire('click'); }
        });
    }

    function createOrUpdateMarker(site) {
        const id = Number(site.id); let marker = markers.get(id);
        if (!marker) {
            marker = L.circleMarker(site.coordinates, {radius:10,color:'#fff',weight:2,fillColor:markerColor(site),fillOpacity:1}).addTo(map);
            marker.bindTooltip(buildSiteHover(site), {direction:'top', permanent:false, sticky:true, opacity:1, className:'icct-map-site-detail-tooltip', offset:[0,-10]});
            marker.on('mouseover', function () { refreshSiteDetail(site, marker, false); });
            marker.on('click', function () { showSite(site, marker); });
            marker.on('add', function () { accessibleMarker(marker, site); });
            markers.set(id, marker);
        } else {
            marker.setLatLng(site.coordinates); marker.setStyle({fillColor:markerColor(site)}); marker.setTooltipContent(buildSiteHover(site));
        }
        accessibleMarker(marker, site);
        return marker;
    }

    (data.sites || []).forEach(function (site) { siteById.set(Number(site.id), site); createOrUpdateMarker(site); });
    document.addEventListener('icct:show-site', function (event) {
        const site = siteById.get(Number(event.detail)); if (site) showSite(site, markers.get(Number(site.id)));
    });

    function updateCountBar(counts) {
        ['total','online','offline','disabled'].forEach(function (key) {
            const el = document.querySelector('[data-map-count="' + key + '"]');
            if (el) el.textContent = (key.charAt(0).toUpperCase() + key.slice(1)) + ': ' + Number((counts || {})[key] || 0);
        });
    }

    async function refreshMapStatus() {
        if (refreshPending || document.hidden || mapPanel.hidden) return;
        refreshPending = true;
        try {
            const response = await fetch(endpoint + '?map_status=1', {credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error('Map status refresh failed');
            const snapshot = await response.json();
            const seen = new Set();
            (snapshot.sites || []).forEach(function (fresh) {
                const id = Number(fresh.id); seen.add(id);
                let site = siteById.get(id);
                if (!site) { site = Object.assign({devices:[],fault_counts:{}}, fresh); siteById.set(id, site); }
                else Object.assign(site, fresh);
                createOrUpdateMarker(site);
            });
            markers.forEach(function (marker, id) { if (!seen.has(id)) { map.removeLayer(marker); markers.delete(id); siteById.delete(id); detailCache.delete(id); } });
            updateCountBar(snapshot.counts || {});
            setStatus('Map status refreshed ' + (snapshot.refreshed_at || '') + '.');
        } catch (error) {
            setStatus('Live map status refresh failed; showing the last received status.');
        } finally { refreshPending = false; }
    }

    document.getElementById('icctMapZoomIn').addEventListener('click', function () { map.zoomIn(); });
    document.getElementById('icctMapZoomOut').addEventListener('click', function () { map.zoomOut(); });
    document.getElementById('icctMapFit').addEventListener('click', function () { fitIndia(); });
    document.getElementById('icctMapFullscreen').addEventListener('click', function () {
        if (document.fullscreenElement) document.exitFullscreen();
        else document.querySelector('.icct-map-panel').requestFullscreen().catch(function () { setStatus('Fullscreen is unavailable in this browser.'); });
    });

    const tabs = Array.from(document.querySelectorAll('.icct-view-tabs [role="tab"]'));
    function selectView(tab, updateUrl) {
        if (updateUrl === undefined) updateUrl = true;
        if (!document.dispatchEvent(new CustomEvent('icct:before-view-change', {cancelable:true, detail:{tab:tab}}))) return;
        const destination = new URL(window.location.href);
        destination.searchParams.set('view', tab.dataset.view);
        if (destination.searchParams.has('site_id') && tab.dataset.view === 'map') { destination.searchParams.delete('site_id'); window.location.assign(destination.href); return; }
        if (updateUrl && destination.href !== window.location.href) history.pushState(null,'',destination.href);
        tabs.forEach(function (item) {
            const selected = item === tab; item.setAttribute('aria-selected', String(selected)); item.tabIndex = selected ? 0 : -1;
            document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
        });
        document.querySelector('.icct-map-credits').hidden = tab.dataset.view !== 'map';
        if (tab.dataset.view === 'map') { map.invalidateSize({pan:false}); if (countryView) fitIndia(); setStatus(liveStatus); refreshMapStatus(); }
        else statusMessage.hidden = true;
    }
    window.addEventListener('popstate', function () {
        const view = new URL(window.location.href).searchParams.get('view') || 'topology';
        selectView(tabs.find(function (tab) { return tab.dataset.view === view; }) || tabs[0], false);
    });
    const initialView = new URL(window.location.href).searchParams.get('view') || 'topology';
    selectView(tabs.find(function (tab) { return tab.dataset.view === initialView; }) || tabs[0], false);
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { selectView(tab); });
        tab.addEventListener('keydown', function (event) {
            let next = null;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            if (next !== null) { event.preventDefault(); tabs[next].focus(); selectView(tabs[next]); }
        });
    });

    const refreshSeconds = Math.max(15, Math.min(300, Number(data.refresh_seconds || 30)));
    window.setInterval(refreshMapStatus, refreshSeconds * 1000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && !mapPanel.hidden) refreshMapStatus(); });
})();
