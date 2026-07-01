/**
 * js/badge_print.js — CR80 badge print (Fabric static canvas + two-step print).
 */
(function () {
    var sel = document.getElementById('design_id');
    var form = document.getElementById('design-picker-form');
    if (sel && form) {
        sel.addEventListener('change', function () { form.submit(); });
    }
})();

(function() {
    var cfg = window.FLIGHTOPS_BADGE_PRINT || {};
    var memberData = cfg.memberData || {};
    var templateData = cfg.templateData || null;

    var printArea = document.getElementById('card-print-area');
    var printFront = printArea && printArea.getAttribute('data-print-front') === '1';
    var printBack = printArea && printArea.getAttribute('data-print-back') === '1';

    // Remember the original parent so we can restore after printing
    var printAreaOriginalParent   = printArea ? printArea.parentNode : null;
    var printAreaOriginalNextSibling = printArea ? printArea.nextSibling : null;

    /**
     * Move #card-print-area to be a direct child of <body>.
     * This bypasses Bootstrap's .container margin/padding entirely —
     * the card starts at the exact page origin with no inherited offsets.
     */
    function moveCardToBody() {
        if (printArea && printArea.parentNode !== document.body) {
            document.body.appendChild(printArea);
        }
        document.body.classList.add('printing');
    }

    /**
     * Restore #card-print-area to its original DOM position.
     */
    function restoreCard() {
        if (printArea && printAreaOriginalParent) {
            if (printAreaOriginalNextSibling) {
                printAreaOriginalParent.insertBefore(printArea, printAreaOriginalNextSibling);
            } else {
                printAreaOriginalParent.appendChild(printArea);
            }
        }
        document.body.classList.remove(
            'printing', 'print-step-front', 'print-step-back',
            'print-portrait-front', 'print-portrait-back'
        );
    }

    /**
     * Inject (or replace) a <style id="dynamic-page-size"> tag to set @page
     * to the correct physical CR80 dimensions for the given orientation.
     * Landscape = 3.375" × 2.125"  |  Portrait = 2.125" × 3.375"
     *
     * @param {string} ori   'landscape' | 'portrait'
     * @param {string} side  'front' | 'back'
     */
    function setPageSize(ori, side) {
        var w = ori === 'portrait' ? '2.125in' : '3.375in';
        var h = ori === 'portrait' ? '3.375in' : '2.125in';
        var el = document.getElementById('dynamic-page-size');
        if (!el) {
            el = document.createElement('style');
            el.id = 'dynamic-page-size';
            document.head.appendChild(el);
        }
        el.textContent = '@page { size: ' + w + ' ' + h + '; margin: 0; }';

        document.body.classList.remove('print-portrait-front', 'print-portrait-back');
        if (ori === 'portrait') {
            document.body.classList.add('print-portrait-' + side);
        }
    }

    document.getElementById('do-print').addEventListener('click', function() {
        var frontOri = (typeof orientation     !== 'undefined') ? orientation     : 'landscape';
        var backOri  = (typeof backOrientation !== 'undefined') ? backOrientation : 'landscape';

        function doPrintFront() {
            setPageSize(frontOri, 'front');
            moveCardToBody();
            document.body.classList.add('print-step-front');
            document.body.classList.remove('print-step-back');
            window.print();
        }
        function doPrintBack() {
            setPageSize(backOri, 'back');
            moveCardToBody();
            document.body.classList.remove('print-step-front');
            document.body.classList.add('print-step-back');
            window.print();
        }

        if (printFront && printBack) {
            doPrintFront();
            window.addEventListener('afterprint', function step2() {
                window.removeEventListener('afterprint', step2);
                doPrintBack();
                window.addEventListener('afterprint', function step3() {
                    window.removeEventListener('afterprint', step3);
                    restoreCard();
                }, { once: true });
            }, { once: true });
        } else if (printFront) {
            doPrintFront();
            window.addEventListener('afterprint', restoreCard, { once: true });
        } else if (printBack) {
            doPrintBack();
            window.addEventListener('afterprint', restoreCard, { once: true });
        }
    });

    if (!templateData || !templateData.canvas) {
        document.getElementById('badge-front').after(document.createTextNode('No badge template saved. Design one under Administration → Badge design.'));
        document.getElementById('badge-back').innerHTML = '<p class="text-muted">No back design.</p>';
        return;
    }

    var orientation = (templateData.orientation === 'portrait') ? 'portrait' : 'landscape';
    var backOrientation = (templateData.backOrientation === 'portrait') ? 'portrait' : 'landscape';
    var cardW = orientation === 'portrait' ? (cfg.cardWidthPortrait || 252) : (cfg.cardWidthLandscape || 400);
    var cardH = orientation === 'portrait' ? (cfg.cardHeightPortrait || 400) : (cfg.cardHeightLandscape || 252);
    var backW = backOrientation === 'portrait' ? (cfg.cardWidthPortrait || 252) : (cfg.cardWidthLandscape || 400);
    var backH = backOrientation === 'portrait' ? (cfg.cardHeightPortrait || 400) : (cfg.cardHeightLandscape || 252);

    document.getElementById('badge-front').width = cardW;
    document.getElementById('badge-front').height = cardH;
    document.querySelector('.badge-back-content').style.width = backW + 'px';
    document.querySelector('.badge-back-content').style.minHeight = backH + 'px';

    if (templateData.backHtml) {
        var backHtml = templateData.backHtml.replace(/\{\{(\w+)\}\}/g, function (_, field) {
            return getMemberValue(field);
        });
        document.getElementById('badge-back').innerHTML = backHtml;
        scaleBackToFit();
    } else {
        document.getElementById('badge-back').innerHTML = '';
    }

    /**
     * Measure back content height and scale font/padding so it fits in the card
     * without cutting off the last line(s). Uses inline styles with !important
     * so print CSS doesn't override.
     */
    function scaleBackToFit() {
        var el = document.getElementById('badge-back');
        if (!el || !el.innerHTML.trim()) return;
        var wrap = document.getElementById('card-back-wrap');
        if (!wrap) return;
        // Temporarily let content grow so we can measure full height
        el.style.height = 'auto';
        el.style.minHeight = '0';
        el.style.overflow = 'visible';
        el.style.position = 'absolute';
        el.style.left = '-9999px';
        el.style.top = '0';
        el.style.width = backW + 'px';
        var contentHeight = el.offsetHeight;
        // Restore for layout
        el.style.position = '';
        el.style.left = '';
        el.style.top = '';
        el.style.height = '';
        el.style.minHeight = '';
        el.style.overflow = '';
        // Use print content area height (card minus padding) in px at 96dpi
        var cardHeightIn = backOrientation === 'portrait' ? 3.375 : 2.125;
        var paddingIn = 0.06 * 2;
        var containerPx = Math.round((cardHeightIn - paddingIn) * 96);
        if (contentHeight <= 0) return;
        var scale = Math.min(1, containerPx / contentHeight);
        if (scale >= 1) return;
        // Apply scaled typography so content reflows and fits (override print CSS)
        el.style.setProperty('font-size', (7.5 * scale).toFixed(2) + 'pt', 'important');
        el.style.setProperty('padding', (0.06 * scale).toFixed(3) + 'in', 'important');
        el.style.setProperty('line-height', (1.25 * scale).toFixed(2), 'important');
    }

    function getMemberValue(field) {
        return memberData[field] !== undefined && memberData[field] !== null ? String(memberData[field]) : '';
    }

    var canvas = new fabric.StaticCanvas('badge-front', { enableRetinaScaling: false });
    canvas.setDimensions({ width: cardW, height: cardH });

// Always prefer the path on disk over the saved data-URL snapshot.
// This ensures a re-uploaded background (same filename) shows up correctly
// without needing to re-save the template after every background swap.
// The cache-busting ?t= prevents any server/proxy from serving stale bytes.
var bgUrl = null;
if (templateData.backgroundPath) {
    var base = window.location.href.replace(/[#?].*$/, '').replace(/\/[^/]*$/, '/');
    bgUrl = new URL(templateData.backgroundPath, base).href
            + '?t=' + Date.now();
} else if (templateData.backgroundDataUrl) {
    bgUrl = templateData.backgroundDataUrl;
}

    function resolveUrl(path) {
        if (!path || path.indexOf('data:') === 0 || path.indexOf('http') === 0) return path;
        var base = window.location.href.replace(/[#?].*$/, '').replace(/\/[^/]*$/, '/');
        return new URL(path, base).href;
    }

    // Backgrounds loaded from external URLs need CORS; same-origin URLs (e.g.
    // badge_photo.php or relative uploads/) avoid taint. Prefer those over hotlinking.
    function setBackground(done) {
        if (!bgUrl) { done(); return; }
        var opts = bgUrl.indexOf('data:') === 0 ? {} : { crossOrigin: 'anonymous' };
        loadFabricImage(bgUrl, opts, function(img) {
            if (img) {
                var w = img.get('width'), h = img.get('height');
                if (w && h) {
                    var scale = Math.max(cardW / w, cardH / h);
                    img.set('scaleX', scale);
                    img.set('scaleY', scale);
                    img.set('left', (cardW - w * scale) / 2);
                    img.set('top', (cardH - h * scale) / 2);
                    img.set('originX', 'left');
                    img.set('originY', 'top');
                }
                setCanvasBackgroundImage(canvas, img);
            }
            done();
        });
    }

    function applyMemberData(done) {
        var objects = canvas.getObjects();
        var savedObjs = (templateData.canvas.objects || []);
        // Prefer data URL so the canvas is never tainted — toDataURL('image/png') then works and the photo appears in print
        var photoDataUrl = getMemberValue('photo_data_url');
        var photoEndpoint = getMemberValue('photo_url');
        var photoPath = getMemberValue('photo_path');
        var photoUrl = photoDataUrl || photoEndpoint || (photoPath ? resolveUrl(photoPath) : '');
        var pending = 0;
        function checkDone() {
            pending--;
            if (pending === 0) done();
        }
        savedObjs.forEach(function(saved, i) {
            var obj = objects[i];
            if (!obj) return;
            var field = saved.dataField;
            if (!field) return;
            if (field === 'photo') {
                if (!photoUrl) {
                    canvas.requestRenderAll();
                    return;
                }
                pending++;
                (function(orig) {
                    var rect = orig.getBoundingRect();
                    var left = rect.left;
                    var top = rect.top;
                    var w = Math.max(rect.width || 80, 1);
                    var h = Math.max(rect.height || 100, 1);
                    var opts = (photoUrl.indexOf('data:') === 0 || photoUrl.indexOf('http') !== 0) ? {} : { crossOrigin: 'anonymous' };
                    loadFabricImage(photoUrl, opts, function(img) {
                        if (img && canvas.getObjects().indexOf(orig) !== -1) {
                            img.set('left', left);
                            img.set('top', top);
                            img.set('originX', 'left');
                            img.set('originY', 'top');
                            if (w > 0 && h > 0) {
                                img.scaleToWidth(w);
                                if (img.getScaledHeight() > h) img.scaleToHeight(h);
                            }
                            canvas.add(img);
                            orig.set('visible', false);
                        }
                        canvas.requestRenderAll();
                        checkDone();
                    });
                })(obj);
            } else if (field === 'freeform') {
                // Freeform text: keep the text from the template (same on every badge)
                return;
            } else {
                var val = getMemberValue(field);
                var saved = savedObjs[i] || {};
                var fixedW = saved._fixedWidth || obj._fixedWidth || 0;
                if (typeof obj.setText === 'function') obj.setText(val);
                else obj.set('text', val);
                if (field === 'full_name' || field === 'full_name_first_last') {
                    obj.set('fontSize', 24);
                }
                normalizeBadgeTextOrigin(obj);
                if (saved.textAlign) obj.set('textAlign', saved.textAlign);
                if (fixedW) {
                    applyBadgeTextFixedWidth(obj, fixedW);
                } else {
                    obj.initDimensions();
                    obj.setCoords();
                }
            }
        });
        canvas.requestRenderAll();
        if (pending === 0) done();
    }


    function finalizeForPrint() {
        try {
            var dataUrl = canvas.toDataURL({ format: 'png', multiplier: 1 });
            var imgEl = document.getElementById('badge-front-img');
            imgEl.src = dataUrl;
            // Show at canvas pixel size for the on-screen preview only.
            // Print CSS overrides these with exact inch dimensions via position:fixed.
            imgEl.style.display = 'block';
            imgEl.style.width   = cardW + 'px';
            imgEl.style.height  = cardH + 'px';
            // Hide the raw Fabric canvas — the img is used for printing
            document.getElementById('badge-front').style.display = 'none';
        } catch (e) {
            // Cross-origin canvas taint: canvas stays visible as screen fallback;
            // fall back to printing the canvas itself.
            console.warn('badge_print: toDataURL() failed; canvas export blocked (possible cross-origin taint).', e);
            document.body.classList.add('badge-tainted');
            var warnEl = document.getElementById('badge-print-warning');
            if (warnEl) {
                warnEl.style.display = 'block';
                warnEl.innerHTML = '<strong>Warning:</strong> The badge image could not be exported for printing (cross-origin). Falling back to the on-screen canvas.';
            }
        }
        document.getElementById('card-loading').style.display = 'none';
    }

    // Order: load canvas (objects only), then set background (so it is not overwritten), then apply member data, then export to img for reliable printing.
    // Delay allows async photo load + canvas paint to complete before toDataURL().
    canvas.loadFromJSON(templateData.canvas).then(function() {
        restoreBadgeTextObjects(canvas, templateData.canvas);
        setBackground(function() {
            applyMemberData(function() {
                canvas.requestRenderAll();
                var raf = (typeof fabric !== 'undefined' && fabric.util && fabric.util.requestAnimFrame)
                    ? fabric.util.requestAnimFrame.bind(fabric.util)
                    : (window.requestAnimationFrame || function(cb) { setTimeout(cb, 16); });
                raf(function() {
                    raf(function() {
                        raf(function() {
                            finalizeForPrint();
                        });
                    });
                });
            });
        });
    });
})();
