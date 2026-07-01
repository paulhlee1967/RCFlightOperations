/**
 * js/badge_design.js — CR80 badge designer UI (Fabric.js).
 * Requires: fabric, js/badge_fabric.js, and window.FLIGHTOPS_BADGE_DESIGN from badge_design.php.
 */
(function () {
'use strict';

/** Single source for badge field metadata (same keys as PHP $dataFields). */
var cfg = window.FLIGHTOPS_BADGE_DESIGN || {};
var BADGE_DATA_FIELDS = cfg.dataFields || {};
var DEFAULT_BADGE_FONT = 'Arial';

/* ── Constants ──────────────────────────────────────────────────────────────── */
var CARD_W_L = cfg.cardWidthLandscape || 400;
var CARD_H_L = cfg.cardHeightLandscape || 252;
var CARD_W_P = cfg.cardWidthPortrait || 252;
var CARD_H_P = cfg.cardHeightPortrait || 400;

var dataFieldProp = 'dataField';   // custom Fabric property key
badgeFabricExtendDataFieldSerialization(dataFieldProp);
var previewMode   = false;         // true when showing a real member's data

/* ── Undo / redo stack (design mode; preview mode bypasses history) ─────── */
var undoStack = [];
var redoStack = [];
var UNDO_MAX = 35;
var historyPaused = false;
var historyTimer = null;

function resetHistoryFromCanvas() {
    undoStack.length = 0;
    redoStack.length = 0;
    if (previewMode) return;
    try {
        undoStack.push(JSON.stringify(canvas.toJSON([dataFieldProp])));
    } catch (err) {}
}

function scheduleHistorySnapshot() {
    if (previewMode || historyPaused) return;
    clearTimeout(historyTimer);
    historyTimer = setTimeout(function () {
        try {
            undoStack.push(JSON.stringify(canvas.toJSON([dataFieldProp])));
            if (undoStack.length > UNDO_MAX) undoStack.shift();
            redoStack = [];
        } catch (e) {}
    }, 220);
}

function applyHistoryJSON(jsonStr) {
    historyPaused = true;
    var parsed = JSON.parse(jsonStr);
    canvas.loadFromJSON(parsed).then(function () {
        restoreDataFields(canvas, parsed);
        historyPaused = false;
        canvas.requestRenderAll();
        scheduleDesignerViewSync();
        propsPanel.style.display = 'none';
    });
}

/* ── Canvas setup ────────────────────────────────────────────────────────── */
var canvas = new fabric.Canvas('badge-canvas', {
    selection: true,
    enableRetinaScaling: false
});

var currentCardW = CARD_W_L;
var currentCardH = CARD_H_L;

/** sessionStorage key for view zoom preference (fit | 1 | 1.5 | 2) */
var CANVAS_ZOOM_STORAGE = 'badge_designer_canvas_zoom_v1';

/**
 * 'fit' = scale to column width (clamped); number = explicit CSS multiplier (1, 1.5, 2 …).
 * Only affects on-screen size — saved JSON stays CR80 logical pixels.
 */
var canvasPixelZoom = '1';
try {
    var _savedZ = sessionStorage.getItem(CANVAS_ZOOM_STORAGE);
    if (_savedZ === 'fit' || _savedZ === '1' || _savedZ === '1.5' || _savedZ === '2') {
        canvasPixelZoom = _savedZ === 'fit' ? 'fit' : parseFloat(_savedZ, 10);
    }
} catch (eSaveZ) {}

function syncCanvasZoomRadios() {
    var val = canvasPixelZoom === 'fit' ? 'fit' : String(canvasPixelZoom);
    document.querySelectorAll('input.canvas-zoom-radio').forEach(function (r) {
        r.checked = (r.value === val);
    });
}

/**
 * Scale only the on-screen canvas (CSS) so the card fills the column on small
 * / mid screens without changing saved coordinates (backing store stays CR80 px).
 */
var designerViewTimer = null;

function scheduleDesignerViewSync() {
    clearTimeout(designerViewTimer);
    designerViewTimer = setTimeout(function () {
        syncCanvasCssScale();
        syncBackPreviewZoom();
    }, 60);
}

function syncCanvasCssScale() {
    var col = document.getElementById('designer-canvas-col');
    if (!col || !canvas) return;
    var avail = col.getBoundingClientRect().width - 20;
    if (avail < 72) return;
    var scale;
    if (canvasPixelZoom === 'fit') {
        scale = avail / currentCardW;
        scale = Math.max(0.52, Math.min(2.45, scale));
    } else {
        scale = typeof canvasPixelZoom === 'number' ? canvasPixelZoom : parseFloat(canvasPixelZoom, 10);
        if (isNaN(scale) || scale <= 0) scale = 1;
        scale = Math.max(0.5, Math.min(3, scale));
    }
    try {
        // Fabric 5 cssOnly path does not append 'px'; numeric values are invalid
        // for style.width/height and are ignored by browsers — must use strings.
        var cssW = (currentCardW * scale) + 'px';
        var cssH = (currentCardH * scale) + 'px';
        canvas.setDimensions({ width: cssW, height: cssH }, { cssOnly: true });
        canvas.requestRenderAll();
    } catch (err) {}
}

/**
 * Resize the canvas to match the chosen orientation.
 * @param {string} ori  'landscape' | 'portrait'
 */
function setCardSize(ori) {
    if (ori === 'portrait') {
        currentCardW = CARD_W_P;
        currentCardH = CARD_H_P;
    } else {
        currentCardW = CARD_W_L;
        currentCardH = CARD_H_L;
    }
    canvas.setDimensions({ width: currentCardW, height: currentCardH });
    canvas.requestRenderAll();
    scheduleDesignerViewSync();
}

window.addEventListener('resize', scheduleDesignerViewSync);
window.addEventListener('orientationchange', scheduleDesignerViewSync);
if (typeof ResizeObserver !== 'undefined') {
    (function () {
        var col = document.getElementById('designer-canvas-col');
        if (col) {
            var ro = new ResizeObserver(scheduleDesignerViewSync);
            ro.observe(col);
        }
    })();
}
syncCanvasZoomRadios();
scheduleDesignerViewSync();
if (typeof requestAnimationFrame !== 'undefined') {
    requestAnimationFrame(function () { scheduleDesignerViewSync(); });
}

document.querySelectorAll('input.canvas-zoom-radio').forEach(function (radio) {
    radio.addEventListener('change', function () {
        if (!this.checked) return;
        var v = this.value;
        canvasPixelZoom = (v === 'fit') ? 'fit' : parseFloat(v, 10);
        try { sessionStorage.setItem(CANVAS_ZOOM_STORAGE, v); } catch (eZ) {}
        syncCanvasZoomRadios();
        syncCanvasCssScale();
        syncBackPreviewZoom();
    });
});

/* ── Background image helpers ───────────────────────────────────────────── */
function setBackgroundToCover(img) {
    var w = img.get('width'), h = img.get('height');
    if (!w || !h) return;
    var scale  = Math.max(currentCardW / w, currentCardH / h);
    var left   = (currentCardW  - w * scale) / 2;
    var top    = (currentCardH - h * scale) / 2;
    img.set({ scaleX: scale, scaleY: scale, left: left, top: top,
              originX: 'left', originY: 'top' });
    setCanvasBackgroundImage(canvas, img);
    canvas.requestRenderAll();
}

/**
 * Resolve a relative path (e.g. 'uploads/…') to an absolute URL based on
 * the current page's location. Needed when loading saved templates.
 */
function resolveUrl(path) {
    if (!path || path.indexOf('data:') === 0 || path.indexOf('http') === 0) return path;
    var base = window.location.href.replace(/[#?].*$/, '').replace(/\/[^/]*$/, '/');
    return new URL(path, base).href;
}

/* ── Orientation control ────────────────────────────────────────────────── */
document.getElementById('orientation').addEventListener('change', function () {
    setCardSize(this.value);
});

/* ── Front / Back side toggle ───────────────────────────────────────────── */
var frontPanel     = document.getElementById('front-panel');
var backPanel      = document.getElementById('back-panel');
var fieldsPanel    = document.getElementById('fields-panel');
var orientWrap     = document.getElementById('orientation-wrap');
var backOrientWrap = document.getElementById('back-orientation-wrap');
var propsPanel     = document.getElementById('props-panel');

document.querySelectorAll('input[name="sideRadio"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
        var isFront = this.value === 'front';
        frontPanel.style.display     = isFront ? '' : 'none';
        backPanel.style.display      = isFront ? 'none' : '';
        fieldsPanel.style.display    = isFront ? '' : 'none';
        orientWrap.style.display     = isFront ? '' : 'none';
        backOrientWrap.style.display = isFront ? 'none' : '';
        if (isFront) {
            // Re-render canvas when switching back to front
            canvas.requestRenderAll();
            scheduleDesignerViewSync();
        } else {
            propsPanel.style.display = 'none';
        }
    });
});

/* ── Background upload / remove ─────────────────────────────────────────── */
document.getElementById('bg-upload').addEventListener('change', function () {
    var file = this.files[0];
    if (!file) return;
    var bgStatus = document.getElementById('bg-status');
    bgStatus.textContent = 'Uploading…';
    var fd = new FormData();
    fd.append('background', file);
    fd.append('template_id', String(currentDesignId || 0));
    var csrfEl = document.getElementById('csrf_token_value');
    if (csrfEl) fd.append('csrf_token', csrfEl.value);
    fetch('badge_design.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok && data.url) {
                bgStatus.textContent = 'Loaded.';
                var bgUrl = resolveUrl(data.url) + (data.url.indexOf('?') !== -1 ? '&' : '?') + 't=' + Date.now();
                loadFabricImage(bgUrl, { crossOrigin: 'anonymous' }, function (img) {
                    if (img) setBackgroundToCover(img);
                });
            } else {
                bgStatus.textContent = data.error || 'Upload failed.';
            }
        })
        .catch(function () { bgStatus.textContent = 'Network error.'; });
    this.value = '';
});

document.getElementById('bg-remove').addEventListener('click', function () {
    canvas.backgroundImage = null;
    canvas.requestRenderAll();
    document.getElementById('bg-status').textContent = 'Background removed.';
});

/* ── Add field to canvas ────────────────────────────────────────────────── */
function addTextField(field, placeholder) {
    var text = new fabric.IText(placeholder, {
        left: 20,
        top: 20,
        originX: 'left',
        originY: 'top',
        textAlign: 'left',
        fontFamily: DEFAULT_BADGE_FONT,
        fontSize: 14,
        fill: '#000000'
    });
    text.set(dataFieldProp, field);
    canvas.add(text);
    canvas.setActiveObject(text);
    canvas.requestRenderAll();
}

function normalizePhotoPlaceholder(obj) {
    if (!obj || !FabricGroupClass || !(obj instanceof FabricGroupClass)) return;
    if (obj[dataFieldProp] !== 'photo') return;

    var canvasPos = null;
    try {
        if (typeof obj.getPositionByOrigin === 'function') {
            canvasPos = obj.getPositionByOrigin('left', 'top');
        }
    } catch (e) {}

    var rect = null;
    var label = null;
    obj.getObjects().forEach(function (o) {
        var t = (o.type || '').toLowerCase();
        if (t === 'rect') rect = o;
        else if (t === 'text' || t === 'itext' || t === 'i-text') label = o;
    });
    if (!rect) return;

    var w = rect.width || 80;
    var h = rect.height || 100;
    rect.set({ left: 0, top: 0, originX: 'left', originY: 'top' });
    if (label) {
        label.set({
            left: w / 2,
            top: h / 2,
            originX: 'center',
            originY: 'center',
            textAlign: 'center'
        });
    }
    if (typeof obj.triggerLayout === 'function') {
        obj.triggerLayout();
    }
    if (canvasPos) {
        obj.set({ originX: 'left', originY: 'top', left: canvasPos.x, top: canvasPos.y });
    } else {
        obj.set({ originX: 'left', originY: 'top' });
    }
    obj.setCoords();
}

function addPhotoField() {
    var rect = new fabric.Rect({
        width: 80,
        height: 100,
        left: 0,
        top: 0,
        originX: 'left',
        originY: 'top',
        fill: '#e0e0e0',
        stroke: '#aaa',
        strokeWidth: 1
    });
    var label = new fabric.Text('Photo', {
        fontSize: 11,
        fill: '#555',
        left: 40,
        top: 50,
        originX: 'center',
        originY: 'center',
        textAlign: 'center'
    });
    var grp = new fabric.Group([rect, label], {
        left: 20,
        top: 20,
        originX: 'left',
        originY: 'top'
    });
    grp.set(dataFieldProp, 'photo');
    canvas.add(grp);
    canvas.setActiveObject(grp);
    canvas.requestRenderAll();
}

document.getElementById('add-field-select').addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    var value = this.value;
    if (value === '__photo__') {
        addPhotoField();
    } else if (value) {
        addTextField(value, opt.dataset.placeholder);
    }
    this.selectedIndex = 0; // reset back to the "Add a field…" prompt
});

document.getElementById('deleteObj').addEventListener('click', function () {
    var active = canvas.getActiveObjects();
    if (active.length) {
        canvas.discardActiveObject();
        active.forEach(function (obj) { canvas.remove(obj); });
        canvas.requestRenderAll();
    }
    propsPanel.style.display = 'none';
});

canvas.on('object:modified', scheduleHistorySnapshot);
canvas.on('object:added', scheduleHistorySnapshot);
canvas.on('object:removed', scheduleHistorySnapshot);

document.getElementById('undo-canvas').addEventListener('click', function () {
    if (previewMode || historyPaused || undoStack.length < 2) return;
    var cur = undoStack.pop();
    redoStack.push(cur);
    applyHistoryJSON(undoStack[undoStack.length - 1]);
});

document.getElementById('redo-canvas').addEventListener('click', function () {
    if (previewMode || historyPaused || !redoStack.length) return;
    var next = redoStack.pop();
    undoStack.push(next);
    applyHistoryJSON(next);
});

document.addEventListener('keydown', function (e) {
    if (previewMode || historyPaused) return;
    var el = document.activeElement;
    if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT')) return;
    var mod = e.ctrlKey || e.metaKey;
    if (mod && e.key === 'z' && !e.shiftKey) {
        e.preventDefault();
        document.getElementById('undo-canvas').click();
    } else if (mod && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
        e.preventDefault();
        document.getElementById('redo-canvas').click();
    }
}, true);

resetHistoryFromCanvas();

/* ── Selection → properties panel ──────────────────────────────────────── */
var propFontFamily = document.getElementById('prop-fontfamily');
var propFontSize  = document.getElementById('prop-fontsize');
var propBold      = document.getElementById('prop-bold');
var propItalic    = document.getElementById('prop-italic');
var propColor     = document.getElementById('prop-color');
var propsLabel    = document.getElementById('props-field-name');
var alignBtns     = document.querySelectorAll('#prop-align button');

/** Populate the properties panel from the currently selected object. */
function syncPropsPanel(obj) {
    if (!obj || (FabricGroupClass && obj instanceof FabricGroupClass)) {
        propsPanel.style.display = 'none';
        return;
    }
    propsPanel.style.display = '';
    var field = obj[dataFieldProp] || '';

    var fi = BADGE_DATA_FIELDS[field];
    propsLabel.textContent = (fi && fi.label) ? fi.label : (field || '');

    propFontFamily.value     = obj.fontFamily || DEFAULT_BADGE_FONT;
    propFontSize.value       = obj.fontSize || 14;
    propBold.checked         = obj.fontWeight === 'bold';
    propItalic.checked       = obj.fontStyle === 'italic';
    propColor.value          = obj.fill || '#000000';

    var align = obj.textAlign || 'left';
    alignBtns.forEach(function (b) {
        b.classList.toggle('active', b.dataset.align === align);
    });

    // Populate fixed-width field (0 means auto-sizing)
    document.getElementById('prop-width').value = obj._fixedWidth || 0;
}

canvas.on('selection:created',  function (e) { syncPropsPanel(e.selected && e.selected[0]); });
canvas.on('selection:updated',  function (e) { syncPropsPanel(e.selected && e.selected[0]); });
canvas.on('selection:cleared',  function ()  { propsPanel.style.display = 'none'; });

/* Apply property changes back to the selected object */
function applyPropChange(fn) {
    var obj = canvas.getActiveObject();
    if (!obj) return;
    fn(obj);
    canvas.requestRenderAll();
}

propFontFamily.addEventListener('change', function () {
    applyPropChange(function (o) { o.set('fontFamily', propFontFamily.value); });
});
propFontSize.addEventListener('input', function () {
    applyPropChange(function (o) { o.set('fontSize', parseInt(propFontSize.value, 10) || 14); });
});
propBold.addEventListener('change', function () {
    applyPropChange(function (o) { o.set('fontWeight', propBold.checked ? 'bold' : 'normal'); });
});
propItalic.addEventListener('change', function () {
    applyPropChange(function (o) { o.set('fontStyle', propItalic.checked ? 'italic' : 'normal'); });
});
propColor.addEventListener('input', function () {
    applyPropChange(function (o) { o.set('fill', propColor.value); });
});
alignBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
        applyPropChange(function (o) {
            normalizeBadgeTextOrigin(o);
            o.set('textAlign', btn.dataset.align);
            o.initDimensions();
            o.setCoords();
        });
        alignBtns.forEach(function (b) { b.classList.toggle('active', b === btn); });
    });
});

document.getElementById('prop-width').addEventListener('input', function () {
    var w = parseInt(this.value, 10) || 0;
    applyPropChange(function (o) {
        normalizeBadgeTextOrigin(o);
        if (w > 0) {
            applyBadgeTextFixedWidth(o, w);
        } else {
            o._fixedWidth = 0;
            o.lockScalingX = false;
            delete o._calcTextWidth;
            o.initDimensions();
            o.setCoords();
        }
        canvas.requestRenderAll();
    });
});
/* ── Back side HTML editor ──────────────────────────────────────────────── */
var backHtmlEl  = document.getElementById('back-html');
var backPreview = document.getElementById('back-preview');

/**
 * CR80-sized HTML preview + same Fit / 1× / 1.5× / 2× scaling as the front canvas.
 */
function syncBackPreviewZoom() {
    var outer = document.getElementById('back-preview-outer');
    var scaler = document.getElementById('back-preview-scaler');
    var prev = document.getElementById('back-preview');
    if (!outer || !scaler || !prev) return;

    var sel = document.getElementById('back-orientation');
    var portrait = sel && sel.value === 'portrait';
    var lw = portrait ? CARD_W_P : CARD_W_L;
    var lh = portrait ? CARD_H_P : CARD_H_L;

    prev.style.width = lw + 'px';
    prev.style.minHeight = lh + 'px';

    var col = document.getElementById('designer-canvas-col');
    if (!col) return;
    var avail = col.getBoundingClientRect().width - 20;
    if (avail < 72) return;

    var scale;
    if (canvasPixelZoom === 'fit') {
        scale = avail / lw;
        scale = Math.max(0.52, Math.min(2.45, scale));
    } else {
        scale = typeof canvasPixelZoom === 'number' ? canvasPixelZoom : parseFloat(canvasPixelZoom, 10);
        if (isNaN(scale) || scale <= 0) scale = 1;
        scale = Math.max(0.5, Math.min(3, scale));
    }

    var contentH = Math.max(lh, prev.scrollHeight);
    scaler.style.width = lw + 'px';
    scaler.style.height = contentH + 'px';
    scaler.style.transform = 'scale(' + scale + ')';
    scaler.style.transformOrigin = '0 0';

    outer.style.width = (lw * scale) + 'px';
    outer.style.height = (contentH * scale) + 'px';
}

function updateBackPreview() {
    var html = backHtmlEl.value;
    // Replace {{tokens}} with placeholder spans for preview
    var display = html.replace(/\{\{(\w+)\}\}/g, function (_, field) {
        var ph = BADGE_DATA_FIELDS[field] && BADGE_DATA_FIELDS[field].placeholder;
        return '<span style="background:#ffe8a1;padding:1px 3px;border-radius:3px;">' +
               (ph || field) + '</span>';
    });
    backPreview.innerHTML = display;
    if (typeof requestAnimationFrame !== 'undefined') {
        requestAnimationFrame(function () { syncBackPreviewZoom(); });
    } else {
        syncBackPreviewZoom();
    }
}

// backHtmlEl input listener added with scheduleAutosave below (badge design block)

// Insert token at cursor position
document.querySelectorAll('.insert-back-field').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var tag   = this.dataset.tag;
        var start = backHtmlEl.selectionStart;
        var end   = backHtmlEl.selectionEnd;
        var val   = backHtmlEl.value;
        backHtmlEl.value = val.substring(0, start) + tag + val.substring(end);
        backHtmlEl.selectionStart = backHtmlEl.selectionEnd = start + tag.length;
        backHtmlEl.focus();
        updateBackPreview();
        scheduleAutosave();
        updateBackPreview();
    });
});

/* ── Live member preview ────────────────────────────────────────────────── */
var previewSelect = document.getElementById('preview-member-select');
var previewStatus = document.getElementById('preview-status');

// Load member list into the dropdown
fetch('badge_design.php?action=member_list', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        var members = Array.isArray(data) ? data : (data.members || []);
        if (data && data.ok === false && window.console) console.warn(data.error || 'member_list');
        members.forEach(function (m) {
            var opt = document.createElement('option');
            opt.value = m.id;
            opt.textContent = m.last_name + ', ' + m.first_name;
            previewSelect.appendChild(opt);
        });
    })
    .catch(function () { if (window.console) console.warn('member_list request failed'); });

/** Replace placeholder text on all canvas objects with real member values. */
function applyPreview(memberData) {
    canvas.getObjects().forEach(function (obj) {
        var field = obj[dataFieldProp];
        if (!field) return;
        if (field === 'photo') {
            // Swap the photo placeholder for the member's actual photo.
            // Use the placeholder's bounding rect (true top-left + size in canvas
            // coords) so the preview matches the printed output exactly. A Fabric
            // v6+ group's left/top is its CENTER, so positioning by obj.left/top
            // shifted the photo down/right by half the box.
            if (memberData.photo_data_url) {
                var placeholder = obj;
                loadFabricImage(memberData.photo_data_url, {}, function (img) {
                    if (!img) return;
                    if (canvas.getObjects().indexOf(placeholder) === -1) return;
                    var rect = placeholder.getBoundingRect();
                    var w = Math.max(rect.width  || 80, 1);
                    var h = Math.max(rect.height || 100, 1);
                    img.set({ originX: 'left', originY: 'top', left: rect.left, top: rect.top });
                    img.scaleToWidth(w);
                    if (img.getScaledHeight() > h) img.scaleToHeight(h);
                    img.set(dataFieldProp, 'photo_preview');
                    // Hide (don't remove) the placeholder so it can be
                    // restored on exitPreview — removing it caused the photo
                    // field to disappear from the saved template.
                    placeholder.set('visible', false);
                    canvas.add(img);
                    canvas.requestRenderAll();
                });
            }
        } else if (isBadgeTextObject(obj)) {
            var fixedW = obj._fixedWidth || 0;
            obj.set('text', memberData[field] !== undefined ? String(memberData[field]) : obj.text);
            normalizeBadgeTextOrigin(obj);
            if (fixedW) {
                applyBadgeTextFixedWidth(obj, fixedW);
            } else {
                obj.initDimensions();
                obj.setCoords();
            }
        }
    });
    canvas.requestRenderAll();
}

/**
 * Snapshot of placeholder texts so we can restore them when leaving preview.
 * Shape: { fabricObjectRef: 'original text' }
 */
var previewSnapshot = [];

function enterPreview(memberData) {
    previewSnapshot = [];
    canvas.getObjects().forEach(function (obj) {
        var field = obj[dataFieldProp];
        if (!field) return;
        if (isBadgeTextObject(obj)) {
            previewSnapshot.push({ obj: obj, text: obj.text });
        }
    });
    applyPreview(memberData);
    previewMode = true;
}

function exitPreview() {
    previewSnapshot.forEach(function (item) {
        var fixedW = item.obj._fixedWidth || 0;
        item.obj.set('text', item.text);
        normalizeBadgeTextOrigin(item.obj);
        if (fixedW) {
            applyBadgeTextFixedWidth(item.obj, fixedW);
        } else {
            item.obj.initDimensions();
            item.obj.setCoords();
        }
    });
    // Remove any photo images added during preview and restore the
    // (hidden) photo placeholder so it survives a save.
    canvas.getObjects().forEach(function (obj) {
        if (obj[dataFieldProp] === 'photo_preview') canvas.remove(obj);
        if (obj[dataFieldProp] === 'photo') obj.set('visible', true);
    });
    previewSnapshot = [];
    previewMode = false;
    canvas.requestRenderAll();
}

previewSelect.addEventListener('change', function () {
    if (previewMode) exitPreview();
    var mid = this.value;
    if (!mid) {
        previewStatus.textContent = '';
        return;
    }
    previewStatus.textContent = 'Loading…';
    fetch('badge_design.php?action=member_data&member_id=' + mid, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || data.ok === false || !data.full_name) {
                previewStatus.textContent = (data && data.error) ? data.error : 'Member not found.';
                return;
            }
            previewStatus.textContent = 'Showing: ' + data.last_name + ', ' + data.first_name;
            enterPreview(data);
        })
        .catch(function () { previewStatus.textContent = 'Failed to load member.'; });
});

/* ── Build payload for save / autosave ───────────────────────────────────── */
function getPayloadForSave() {
    var bg = canvas.backgroundImage;
    var backgroundPath = null;
    var backgroundDataUrl = null;
    if (bg && bg.getSrc) {
        var src = bg.getSrc();
        if (typeof src === 'string') {
            var cleanSrc = src.split('?')[0];
            if (cleanSrc.indexOf('data:') === 0) {
                backgroundDataUrl = cleanSrc;
            } else if (cleanSrc.indexOf('uploads/') !== -1) {
                backgroundPath = cleanSrc.substring(cleanSrc.indexOf('uploads/'));
            }
        }
    }
    if (!backgroundPath && bg && !backgroundDataUrl) {
        var el = (typeof bg.getElement === 'function' && bg.getElement()) || bg._element;
        if (el && el.complete && el.naturalWidth) {
            try {
                var c = document.createElement('canvas');
                c.width = el.naturalWidth; c.height = el.naturalHeight;
                c.getContext('2d').drawImage(el, 0, 0);
                backgroundDataUrl = c.toDataURL('image/png');
            } catch (e) {}
        }
    }
    var json = canvas.toJSON([dataFieldProp]);
    delete json.background;
    delete json.backgroundImage;
    return {
        canvas: json,
        backgroundPath: backgroundPath,
        backgroundDataUrl: backgroundDataUrl,
        orientation: document.getElementById('orientation').value,
        backOrientation: document.getElementById('back-orientation').value,
        backHtml: backHtmlEl.value.trim(),
        version: 1
    };
}

// Scope autosave per user so multiple admins on a shared browser never see each other's unsaved designs.
var AUTOSAVE_KEY = 'badge_design_backup_u<?= (int) $userId ?>';
var autosaveDebounce = null;
var AUTOSAVE_MS = 30000;
function scheduleAutosave() {
    if (previewMode) return;
    clearTimeout(autosaveDebounce);
    autosaveDebounce = setTimeout(function () {
        try {
            localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(getPayloadForSave()));
        } catch (e) {}
    }, AUTOSAVE_MS);
}
canvas.on('object:modified', scheduleAutosave);
canvas.on('object:added', scheduleAutosave);
canvas.on('object:removed', scheduleAutosave);
document.getElementById('orientation').addEventListener('change', scheduleAutosave);
document.getElementById('back-orientation').addEventListener('change', function () {
    syncBackPreviewZoom();
    scheduleAutosave();
});
backHtmlEl.addEventListener('input', function () { updateBackPreview(); scheduleAutosave(); });
syncBackPreviewZoom();

/* ── Multiple designs: state + picker controls ──────────────────────────── */
var designSelect   = document.getElementById('design-select');
var newDesignBtn   = document.getElementById('newDesign');
var renameBtn      = document.getElementById('renameDesign');
var deleteBtn      = document.getElementById('deleteDesign');
var defaultCheck   = document.getElementById('design-default');

var designs         = [];   // [{id, name, is_default}]
var currentDesignId = 0;    // 0 = unsaved new design
var currentName     = 'Default';

function findDesign(id) {
    for (var i = 0; i < designs.length; i++) { if (designs[i].id === id) return designs[i]; }
    return null;
}

function populateDesignSelect() {
    designSelect.innerHTML = '';
    if (currentDesignId === 0) {
        var optNew = document.createElement('option');
        optNew.value = '0';
        optNew.textContent = (currentName || 'New design') + ' (unsaved)';
        designSelect.appendChild(optNew);
    }
    designs.forEach(function (d) {
        var opt = document.createElement('option');
        opt.value = String(d.id);
        var tags = [];
        if (d.is_default) tags.push('default');
        opt.textContent = d.name + (tags.length ? '  [' + tags.join(', ') + ']' : '');
        designSelect.appendChild(opt);
    });
    designSelect.value = String(currentDesignId);
}

function syncFlagChecks() {
    var d = findDesign(currentDesignId);
    defaultCheck.checked = d ? !!d.is_default : false;
}

function setSaveStatus(text, cls) {
    var saveStatus = document.getElementById('save-status');
    saveStatus.textContent = text;
    saveStatus.className    = 'small ' + (cls || 'text-muted');
}

/* ── Save design ────────────────────────────────────────────────────────── */
function doSave(overrideName) {
    if (previewMode) {
        exitPreview();
        previewSelect.value = '';
        previewStatus.textContent = '';
    }
    var payload = getPayloadForSave();
    setSaveStatus('Saving…', 'text-muted');

    if (typeof overrideName === 'string' && overrideName !== '') {
        currentName = overrideName;
    }

    var fd = new FormData();
    fd.append('action', 'save');
    fd.append('template', JSON.stringify(payload));
    fd.append('template_id', String(currentDesignId || 0));
    fd.append('name', currentName || 'Untitled design');
    fd.append('is_default', defaultCheck.checked ? '1' : '0');
    var csrfEl = document.getElementById('csrf_token_value');
    if (csrfEl) fd.append('csrf_token', csrfEl.value);

    return fetch('badge_design.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
                currentDesignId = data.id;
                currentName     = data.name;
                designs         = data.designs || [];
                populateDesignSelect();
                syncFlagChecks();
                setSaveStatus('✓ Saved', 'text-success');
                window.setTimeout(function () { setSaveStatus('', 'text-muted'); }, 3000);
            } else {
                setSaveStatus('Error: ' + (data.error || 'Save failed'), 'text-danger');
            }
        })
        .catch(function () {
            setSaveStatus('Network error — design not saved.', 'text-danger');
        });
}
document.getElementById('saveDesign').addEventListener('click', function () { doSave(); });

/* ── Switch / create / rename / delete designs ──────────────────────────── */
designSelect.addEventListener('change', function () {
    var id = parseInt(this.value, 10) || 0;
    if (id === currentDesignId) return;
    try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
    currentDesignId = id;
    var d = findDesign(id);
    currentName = d ? d.name : currentName;
    // Drop any lingering "unsaved" entry now that we've moved to a saved design.
    populateDesignSelect();
    syncFlagChecks();
    loadDesignById(id);
});

newDesignBtn.addEventListener('click', function () {
    var name = prompt('Name for the new design:', 'New design');
    if (name === null) return;
    name = (name || '').trim() || 'Untitled design';
    try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
    currentDesignId = 0;
    currentName     = name;
    defaultCheck.checked = false;
    populateDesignSelect();
    applyDesignData(null);
    setSaveStatus('New design — click “Save Design” to store it.', 'text-muted');
});

renameBtn.addEventListener('click', function () {
    var current = currentDesignId ? (findDesign(currentDesignId) || {}).name : currentName;
    var nn = prompt('Design name:', current || '');
    if (nn === null) return;
    nn = (nn || '').trim();
    if (!nn) return;
    currentName = nn;
    if (currentDesignId === 0) {
        populateDesignSelect();      // just relabel the unsaved entry
    } else {
        doSave(nn);                  // persist the rename (saves current canvas too)
    }
});

deleteBtn.addEventListener('click', function () {
    if (currentDesignId === 0) {
        // Discard the unsaved new design and return to the default/first.
        try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
        selectInitialDesign();
        return;
    }
    if (designs.length <= 1) {
        alert('You cannot delete the only design. Create another design first.');
        return;
    }
    var d = findDesign(currentDesignId);
    if (!confirm('Delete design “' + (d ? d.name : '') + '”? This cannot be undone.')) return;

    var fd = new FormData();
    fd.append('action', 'delete_design');
    fd.append('template_id', String(currentDesignId));
    var csrfEl = document.getElementById('csrf_token_value');
    if (csrfEl) fd.append('csrf_token', csrfEl.value);

    setSaveStatus('Deleting…', 'text-muted');
    fetch('badge_design.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                designs = data.designs || [];
                try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
                setSaveStatus('Design deleted', 'text-success');
                window.setTimeout(function () { setSaveStatus('', 'text-muted'); }, 3000);
                selectInitialDesign();
            } else {
                setSaveStatus('Error: ' + (data.error || 'Delete failed'), 'text-danger');
            }
        })
        .catch(function () { setSaveStatus('Network error — not deleted.', 'text-danger'); });
});

/* ── Load existing template on page load ────────────────────────────────── */
function restoreDataFields(fabricCanvas, savedCanvas) {
    var objects   = fabricCanvas.getObjects();
    var savedObjs = (savedCanvas && savedCanvas.objects) || [];
    savedObjs.forEach(function (saved, i) {
        if (!objects[i]) return;
        if (saved.dataField) objects[i].set(dataFieldProp, saved.dataField);
        if (saved.dataField === 'photo') {
            normalizePhotoPlaceholder(objects[i]);
        }
        restoreBadgeTextObject(objects[i], saved);
    });
}
// Render one design's data onto the canvas. Pass null/empty for a blank card.
function applyDesignData(data) {
    historyPaused = true;

    if (!data || !data.canvas) {
        canvas.clear();
        canvas.backgroundImage = null;
        document.getElementById('orientation').value = 'landscape';
        setCardSize('landscape');
        document.getElementById('back-orientation').value = 'landscape';
        syncBackPreviewZoom();
        backHtmlEl.value = '';
        updateBackPreview();
        canvas.requestRenderAll();
        scheduleDesignerViewSync();
        historyPaused = false;
        resetHistoryFromCanvas();
        return;
    }

    var ori = (data.orientation === 'portrait') ? 'portrait' : 'landscape';
    document.getElementById('orientation').value = ori;
    setCardSize(ori);

    var backOri = (data.backOrientation === 'portrait') ? 'portrait' : 'landscape';
    document.getElementById('back-orientation').value = backOri;
    syncBackPreviewZoom();

    backHtmlEl.value = data.backHtml || '';
    updateBackPreview();

    var bgUrl = (data.backgroundPath ? resolveUrl(data.backgroundPath) : null)
        || data.backgroundDataUrl
        || null;
    if (bgUrl && bgUrl.indexOf('data:') !== 0) {
        bgUrl = bgUrl + (bgUrl.indexOf('?') !== -1 ? '&' : '?') + 't=' + Date.now();
    }

    canvas.loadFromJSON(data.canvas).then(function () {
        restoreDataFields(canvas, data.canvas);
        if (bgUrl) {
            var opts = bgUrl.indexOf('data:') !== 0 ? { crossOrigin: 'anonymous' } : {};
            loadFabricImage(bgUrl, opts, function (img) {
                if (img) setBackgroundToCover(img);
                canvas.requestRenderAll();
                scheduleDesignerViewSync();
                historyPaused = false;
                resetHistoryFromCanvas();
            });
        } else {
            canvas.backgroundImage = null;
            canvas.requestRenderAll();
            scheduleDesignerViewSync();
            historyPaused = false;
            resetHistoryFromCanvas();
        }
    });
}

// Fetch a design by id (0/empty → server default) and render it.
function loadDesignById(id) {
    var url = 'badge_design.php?action=load' + (id ? '&id=' + encodeURIComponent(id) : '');
    return fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) { applyDesignData(data); })
        .catch(function () { resetHistoryFromCanvas(); });
}

// Pick the default (or first) design from the loaded list and render it.
function selectInitialDesign() {
    var sel = null;
    for (var i = 0; i < designs.length; i++) { if (designs[i].is_default) { sel = designs[i]; break; } }
    if (!sel && designs.length) sel = designs[0];
    currentDesignId = sel ? sel.id : 0;
    currentName     = sel ? sel.name : 'Default';
    populateDesignSelect();
    syncFlagChecks();
    loadDesignById(currentDesignId);
}

/* ── Initial page load: list designs, then load the selected one ─────────── */
fetch('badge_design.php?action=list', { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (resp) {
        designs = (resp && resp.designs) || [];
        var sel = null;
        for (var i = 0; i < designs.length; i++) { if (designs[i].is_default) { sel = designs[i]; break; } }
        if (!sel && designs.length) sel = designs[0];
        currentDesignId = sel ? sel.id : 0;
        currentName     = sel ? sel.name : 'Default';
        populateDesignSelect();
        syncFlagChecks();

        var loadUrl = 'badge_design.php?action=load' + (currentDesignId ? '&id=' + currentDesignId : '');
        return fetch(loadUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; });
    })
    .then(function (data) {
        // Offer to restore an autosave backup from a previous session (first load only).
        var backup = null;
        try { backup = localStorage.getItem(AUTOSAVE_KEY); } catch (e) {}
        if (backup) {
            if (confirm('You have unsaved changes from a previous session. Restore them?')) {
                try { data = JSON.parse(backup); } catch (e) { /* fall back to server data */ }
            }
            try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
        }
        applyDesignData(data);
    })
    .catch(function () { resetHistoryFromCanvas(); });

})(); // end IIFE
