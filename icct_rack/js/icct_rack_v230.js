(function () {
    'use strict';

    function initRackTopology() {
        const configEl = document.getElementById('icct-rack-config');
        if (!configEl || configEl.dataset.initialized === '1') return;
        configEl.dataset.initialized = '1';

        const cfg = {
            baseUrl: configEl.dataset.baseUrl || '',
            refreshSeconds: Number(configEl.dataset.refreshSeconds || 0),
            canManage: configEl.dataset.canManage === '1',
            rackCount: Number(configEl.dataset.rackCount || 0)
        };

        const root = document.getElementById('icct-rack-page') || document.documentElement;
        const qs = (s, r) => (r || document).querySelector(s);
        const qsa = (s, r) => Array.from((r || document).querySelectorAll(s));

        function csrfBody(data) {
            const body = new URLSearchParams();
            Object.keys(data).forEach(key => body.set(key, String(data[key])));
            if (window.csrfMagicToken) body.set('__csrf_magic', window.csrfMagicToken);
            return body;
        }

        function post(data) {
            return fetch(cfg.baseUrl + '/ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: csrfBody(data).toString()
            }).then(async response => {
                let payload = {};
                try { payload = await response.json(); } catch (e) {}
                if (!response.ok && !payload.error && !payload.errors) payload.error = 'Request failed.';
                return payload;
            });
        }

        function saveViewSettings(width, uHeight) {
            try {
                localStorage.setItem('icctRackWidth', String(width));
                localStorage.setItem('icctRackUHeight', String(uHeight));
            } catch (e) {}
        }

        function loadViewSettings() {
            let width = 210;
            let uHeight = 22;
            try {
                width = Number(localStorage.getItem('icctRackWidth') || 210);
                uHeight = Number(localStorage.getItem('icctRackUHeight') || 22);
            } catch (e) {}
            width = Math.min(320, Math.max(140, width));
            uHeight = Math.min(32, Math.max(14, uHeight));
            return {width, uHeight};
        }

        function applyViewSettings(width, uHeight, persist) {
            root.style.setProperty('--rack-width', width + 'px');
            root.style.setProperty('--u-height', uHeight + 'px');
            const widthInput = qs('#icct-rack-width');
            const heightInput = qs('#icct-rack-uheight');
            if (widthInput) widthInput.value = width;
            if (heightInput) heightInput.value = uHeight;
            if (persist !== false) saveViewSettings(width, uHeight);
        }

        function fitAllRacks() {
            const stage = qs('#icct-rack-stage');
            const cards = qsa('[data-rack-card]');
            if (!stage || !cards.length) return;

            const availableWidth = Math.max(500, stage.clientWidth - 24);
            const gap = 18;
            const count = cards.length;
            const width = Math.max(140, Math.min(320, Math.floor((availableWidth - gap * (count - 1)) / count) - 12));

            const maxUnits = Math.max.apply(null, cards.map(card => Number(card.dataset.rackUnits || 42)));
            const viewportHeight = Math.max(450, window.innerHeight - 290);
            const uHeight = Math.max(14, Math.min(32, Math.floor((viewportHeight - 100) / Math.max(1, maxUnits))));
            applyViewSettings(width, uHeight, true);
        }

        function bindViewControls() {
            const saved = loadViewSettings();
            applyViewSettings(saved.width, saved.uHeight, false);

            const widthInput = qs('#icct-rack-width');
            const heightInput = qs('#icct-rack-uheight');
            if (widthInput) widthInput.addEventListener('input', () => applyViewSettings(Number(widthInput.value), Number(heightInput ? heightInput.value : saved.uHeight), true));
            if (heightInput) heightInput.addEventListener('input', () => applyViewSettings(Number(widthInput ? widthInput.value : saved.width), Number(heightInput.value), true));

            const fit = qs('#icct-fit-racks');
            if (fit) fit.addEventListener('click', fitAllRacks);

            const reset = qs('#icct-reset-view');
            if (reset) reset.addEventListener('click', () => applyViewSettings(210, 22, true));
        }

        function bindLibraryTabs() {
            qsa('[data-library-tab]').forEach(tab => {
                tab.addEventListener('click', () => {
                    qsa('[data-library-tab]').forEach(t => t.classList.remove('active'));
                    qsa('[data-library-pane]').forEach(p => p.classList.remove('active'));
                    tab.classList.add('active');
                    const pane = qs('[data-library-pane="' + tab.dataset.libraryTab + '"]');
                    if (pane) pane.classList.add('active');
                });
            });
        }

        function bindSearch() {
            const search = qs('#icct-device-search');
            if (!search) return;
            search.addEventListener('input', () => {
                const term = search.value.trim().toLowerCase();
                qsa('#icct-device-library .icct-library-card').forEach(card => {
                    const hay = card.dataset.search || '';
                    card.style.display = !term || hay.indexOf(term) !== -1 ? '' : 'none';
                });
            });
        }

        let dragData = null;

        function bindDragSources() {
            if (!cfg.canManage) return;

            qsa('.icct-library-card[draggable="true"]').forEach(card => {
                card.addEventListener('dragstart', event => {
                    const heightSelect = qs('.icct-height-select', card);
                    dragData = {
                        source: 'library',
                        itemType: card.dataset.itemType,
                        hostId: card.dataset.hostId || '',
                        category: card.dataset.category || 'generic',
                        peripheralKey: card.dataset.peripheralKey || '',
                        stencil: card.dataset.stencil || '',
                        heightU: Number(heightSelect ? heightSelect.value : 1)
                    };
                    card.classList.add('dragging');
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'copy';
                        event.dataTransfer.setData('text/plain', JSON.stringify(dragData));
                    }
                });
                card.addEventListener('dragend', () => {
                    card.classList.remove('dragging');
                    qsa('.icct-drop-active').forEach(el => el.classList.remove('icct-drop-active'));
                    dragData = null;
                });
            });

            qsa('.icct-rack-item[draggable="true"]').forEach(item => {
                item.addEventListener('dragstart', event => {
                    dragData = {
                        source: 'rack',
                        itemType: item.dataset.itemType,
                        placementId: item.dataset.placementId,
                        heightU: Number(item.dataset.heightU || 1)
                    };
                    item.classList.add('dragging');
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', JSON.stringify(dragData));
                    }
                });
                item.addEventListener('dragend', () => {
                    item.classList.remove('dragging');
                    qsa('.icct-drop-active').forEach(el => el.classList.remove('icct-drop-active'));
                    dragData = null;
                });
            });
        }

        function handleDrop(row) {
            if (!dragData) return;
            const rackId = Number(row.dataset.rackId || 0);
            const startU = Number(row.dataset.u || 0);
            if (!rackId || !startU) return;

            let request;
            if (dragData.source === 'library' && dragData.itemType === 'device') {
                request = post({
                    action: 'place_device',
                    host_id: dragData.hostId,
                    rack_id: rackId,
                    start_u: startU,
                    height_u: dragData.heightU,
                    category: dragData.category
                });
            } else if (dragData.source === 'library' && dragData.itemType === 'peripheral') {
                request = post({
                    action: 'place_peripheral',
                    peripheral_key: dragData.peripheralKey,
                    rack_id: rackId,
                    start_u: startU,
                    height_u: dragData.heightU
                });
            } else if (dragData.source === 'rack' && dragData.itemType === 'device') {
                request = post({action: 'move_device', placement_id: dragData.placementId, rack_id: rackId, start_u: startU});
            } else if (dragData.source === 'rack' && dragData.itemType === 'peripheral') {
                request = post({action: 'move_peripheral', placement_id: dragData.placementId, rack_id: rackId, start_u: startU});
            }

            if (!request) return;
            request.then(data => {
                if (!data.ok) {
                    alert((data.errors || [data.error || 'Unable to place rack item.']).join('\n'));
                    return;
                }
                window.location.reload();
            }).catch(() => alert('Unable to contact the rack topology endpoint.'));
        }

        function bindDropTargets() {
            if (!cfg.canManage) return;
            qsa('.icct-rack-urow').forEach(row => {
                row.addEventListener('dragover', event => {
                    if (!dragData) return;
                    event.preventDefault();
                    row.classList.add('icct-drop-active');
                });
                row.addEventListener('dragleave', () => row.classList.remove('icct-drop-active'));
                row.addEventListener('drop', event => {
                    event.preventDefault();
                    row.classList.remove('icct-drop-active');
                    handleDrop(row);
                });
            });
        }

        let inspectorItem = null;

        function openInspector(item) {
            if (!cfg.canManage) return;
            inspectorItem = item;
            const panel = qs('#icct-placement-inspector');
            if (!panel) return;

            const type = item.dataset.itemType || '';
            const label = item.dataset.label || 'Rack Item';
            const height = Number(item.dataset.heightU || 1);
            const title = qs('#icct-inspector-title');
            const subtitle = qs('#icct-inspector-subtitle');
            const heightInput = qs('#icct-inspector-height');
            const stencilInput = qs('#icct-inspector-stencil');
            const stencilWrap = qs('#icct-inspector-stencil-wrap');
            const image = qs('#icct-inspector-image');

            if (title) title.textContent = label;
            if (subtitle) subtitle.textContent = type === 'device' ? 'Monitored Cacti device' : 'Rack peripheral';
            if (heightInput) heightInput.value = String(height);

            if (type === 'device') {
                if (stencilWrap) stencilWrap.style.display = '';
                if (stencilInput) stencilInput.value = item.dataset.category || 'generic';
            } else {
                if (stencilWrap) stencilWrap.style.display = 'none';
            }

            const existingImage = qs('.icct-rack-item-image', item);
            if (image && existingImage) image.src = existingImage.src;

            panel.classList.add('open');
            panel.setAttribute('aria-hidden', 'false');
        }

        function closeInspector() {
            const panel = qs('#icct-placement-inspector');
            if (panel) {
                panel.classList.remove('open');
                panel.setAttribute('aria-hidden', 'true');
            }
            inspectorItem = null;
        }

        function bindInspector() {
            if (!cfg.canManage) return;

            qsa('.icct-rack-item').forEach(item => {
                item.addEventListener('click', event => {
                    if (event.defaultPrevented) return;
                    openInspector(item);
                });
            });

            const close = qs('#icct-inspector-close');
            if (close) close.addEventListener('click', closeInspector);

            const save = qs('#icct-inspector-save');
            if (save) save.addEventListener('click', () => {
                if (!inspectorItem) return;
                const type = inspectorItem.dataset.itemType;
                const heightU = Number(qs('#icct-inspector-height').value || 1);
                let data;
                if (type === 'device') {
                    data = {
                        action: 'update_device',
                        placement_id: inspectorItem.dataset.placementId,
                        height_u: heightU,
                        category: qs('#icct-inspector-stencil').value || 'generic'
                    };
                } else {
                    data = {
                        action: 'update_peripheral',
                        placement_id: inspectorItem.dataset.placementId,
                        height_u: heightU
                    };
                }
                post(data).then(result => {
                    if (!result.ok) {
                        alert((result.errors || [result.error || 'Unable to update rack item.']).join('\n'));
                        return;
                    }
                    window.location.reload();
                });
            });

            const remove = qs('#icct-inspector-remove');
            if (remove) remove.addEventListener('click', () => {
                if (!inspectorItem || !window.confirm('Remove this item from the rack?')) return;
                const action = inspectorItem.dataset.itemType === 'device' ? 'delete_device' : 'delete_peripheral';
                post({action, placement_id: inspectorItem.dataset.placementId}).then(result => {
                    if (!result.ok) {
                        alert((result.errors || [result.error || 'Unable to remove rack item.']).join('\n'));
                        return;
                    }
                    window.location.reload();
                });
            });
        }

        function bindDeviceDetailsForReadonly() {
            if (cfg.canManage) return;
            qsa('.icct-rack-item.device').forEach(item => {
                item.addEventListener('click', () => {
                    const hostId = item.dataset.hostId;
                    if (!hostId) return;
                    window.location.href = '../../host.php?action=edit&id=' + encodeURIComponent(hostId);
                });
            });
        }

        function updateStatus() {
            fetch(cfg.baseUrl + '/ajax.php?action=status_all', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            }).then(r => r.json()).then(data => {
                if (!data.ok) return;
                (data.placements || []).forEach(status => {
                    const item = qs('.icct-rack-item.device[data-placement-id="' + status.placement_id + '"]');
                    if (!item) return;
                    ['status-up','status-down','status-recovering','status-error','status-unknown','status-disabled','status-missing'].forEach(c => item.classList.remove(c));
                    item.classList.add('status-' + (status.status_key || 'unknown'));
                    const label = qs('.item-status', item);
                    if (label) label.textContent = status.status_label || '';
                });
                Object.keys(data.summary || {}).forEach(key => {
                    const target = qs('[data-summary-key="' + key + '"]');
                    if (target) target.textContent = data.summary[key];
                });
                const refreshed = qs('#icct-rack-refreshed');
                if (refreshed) refreshed.textContent = data.refreshed_at || '';
            }).catch(() => {});
        }

        bindViewControls();
        bindLibraryTabs();
        bindSearch();
        bindDragSources();
        bindDropTargets();
        bindInspector();
        bindDeviceDetailsForReadonly();

        if (window.ICCTRackStatusTimer) {
            window.clearInterval(window.ICCTRackStatusTimer);
            window.ICCTRackStatusTimer = null;
        }
        if (cfg.refreshSeconds > 0) {
            window.ICCTRackStatusTimer = window.setInterval(updateStatus, cfg.refreshSeconds * 1000);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRackTopology, {once: true});
    } else {
        initRackTopology();
    }
})();
