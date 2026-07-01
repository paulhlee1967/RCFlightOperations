/**
 * js/badge_fabric.js — Shared Fabric.js 7 compatibility helpers for badge design & print.
 * Requires fabric@7.4.0 loaded before this script (see badge_design.php / badge_print.php).
 */
(function (global) {
    'use strict';

    if (typeof global.fabric === 'undefined') {
        return;
    }

    var FabricObjectClass = global.fabric.Object || global.fabric.FabricObject;
    var FabricTextClass   = global.fabric.Text  || global.fabric.FabricText;
    var FabricImageClass  = global.fabric.Image || global.fabric.FabricImage;
    var FabricGroupClass  = global.fabric.Group;

    function isBadgeTextObject(obj) {
        if (!obj) return false;
        if (FabricTextClass && obj instanceof FabricTextClass) return true;
        var t = (obj.type || '').toLowerCase();
        return t === 'itext' || t === 'i-text' || t === 'text' || t === 'textbox';
    }

    function normalizeBadgeTextOrigin(obj) {
        if (!isBadgeTextObject(obj)) return;
        if (obj.originX === 'left' && obj.originY === 'top') {
            obj.setCoords();
            return;
        }
        try {
            if (typeof obj.getPositionByOrigin === 'function') {
                var pos = obj.getPositionByOrigin('left', 'top');
                obj.set({ originX: 'left', originY: 'top', left: pos.x, top: pos.y });
            } else {
                obj.set({ originX: 'left', originY: 'top' });
            }
        } catch (e) {
            obj.set({ originX: 'left', originY: 'top' });
        }
        obj.setCoords();
    }

    function applyBadgeTextFixedWidth(obj, w) {
        if (!isBadgeTextObject(obj) || !w || w <= 0) return;
        obj._fixedWidth = w;
        obj.set({ width: w, lockScalingX: true });
        obj._calcTextWidth = function () { return this._fixedWidth; };
        obj.initDimensions();
        obj.setCoords();
    }

    function restoreBadgeTextObject(obj, saved) {
        if (!obj || !saved || !isBadgeTextObject(obj)) return;
        normalizeBadgeTextOrigin(obj);
        if (saved.textAlign) {
            obj.set('textAlign', saved.textAlign);
        }
        if (saved._fixedWidth) {
            applyBadgeTextFixedWidth(obj, saved._fixedWidth);
        }
    }

    function restoreBadgeTextObjects(fabricCanvas, savedCanvas) {
        var objects = fabricCanvas.getObjects();
        var savedObjs = (savedCanvas && savedCanvas.objects) || [];
        savedObjs.forEach(function (saved, i) {
            restoreBadgeTextObject(objects[i], saved);
        });
    }

    function loadFabricImage(url, opts, cb) {
        try {
            FabricImageClass.fromURL(url, opts || {})
                .then(function (img) { cb(img || null); })
                .catch(function () { cb(null); });
        } catch (e) { cb(null); }
    }

    function setCanvasBackgroundImage(c, img, cb) {
        c.backgroundImage = img || null;
        c.requestRenderAll();
        if (typeof cb === 'function') cb();
    }

    function extendDataFieldSerialization(dataFieldProp) {
        FabricObjectClass.prototype.toObject = (function (toObject) {
            return function (properties) {
                return Object.assign(toObject.call(this, properties || []), {
                    dataField:   this[dataFieldProp],
                    _fixedWidth: this._fixedWidth || 0
                });
            };
        })(FabricObjectClass.prototype.toObject);
    }

    global.BadgeFabric = {
        FabricObjectClass: FabricObjectClass,
        FabricTextClass:   FabricTextClass,
        FabricImageClass:  FabricImageClass,
        FabricGroupClass:  FabricGroupClass,
        isBadgeTextObject: isBadgeTextObject,
        normalizeBadgeTextOrigin: normalizeBadgeTextOrigin,
        applyBadgeTextFixedWidth: applyBadgeTextFixedWidth,
        restoreBadgeTextObject: restoreBadgeTextObject,
        restoreBadgeTextObjects: restoreBadgeTextObjects,
        loadFabricImage: loadFabricImage,
        setCanvasBackgroundImage: setCanvasBackgroundImage,
        extendDataFieldSerialization: extendDataFieldSerialization
    };

    global.FabricObjectClass = FabricObjectClass;
    global.FabricTextClass   = FabricTextClass;
    global.FabricImageClass  = FabricImageClass;
    global.FabricGroupClass  = FabricGroupClass;
    global.isBadgeTextObject = isBadgeTextObject;
    global.normalizeBadgeTextOrigin = normalizeBadgeTextOrigin;
    global.applyBadgeTextFixedWidth = applyBadgeTextFixedWidth;
    global.restoreBadgeTextObject = restoreBadgeTextObject;
    global.restoreBadgeTextObjects = restoreBadgeTextObjects;
    global.loadFabricImage = loadFabricImage;
    global.setCanvasBackgroundImage = setCanvasBackgroundImage;
    global.badgeFabricExtendDataFieldSerialization = extendDataFieldSerialization;
})(typeof window !== 'undefined' ? window : globalThis);
