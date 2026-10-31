/* Ergonomie des formulaires EasyAdmin (31/10/2026)
 * - un panneau replié qui contient une erreur de validation est déplié au chargement ;
 * - l'état replié/déplié de chaque panneau est mémorisé dans le navigateur, par formulaire. */
(function () {
    'use strict';

    function storageKey() {
        var params = new URLSearchParams(window.location.search);
        return 'ea-fieldsets:' + (params.get('crudControllerFqcn') || '') + ':' + (params.get('crudAction') || '');
    }

    function readState(key) {
        try { return JSON.parse(window.localStorage.getItem(key) || '{}') || {}; } catch (e) { return {}; }
    }

    function writeState(key, state) {
        try { window.localStorage.setItem(key, JSON.stringify(state)); } catch (e) { /* stockage indisponible */ }
    }

    function setOpen(body, open) {
        var toggle = document.querySelector('a[href="#' + body.id + '"]');
        body.classList.toggle('show', open);
        if (toggle) {
            toggle.classList.toggle('collapsed', !open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var bodies = document.querySelectorAll('.form-fieldset-body.collapse');
        if (!bodies.length) { return; }
        var key = storageKey();
        var state = readState(key);

        bodies.forEach(function (body) {
            if (!body.id) { return; }
            if (Object.prototype.hasOwnProperty.call(state, body.id)) {
                setOpen(body, !!state[body.id]);
            }
            if (body.querySelector('.is-invalid, .invalid-feedback, .has-error')) {
                setOpen(body, true);
            }
            body.addEventListener('shown.bs.collapse', function () { state[body.id] = true; writeState(key, state); });
            body.addEventListener('hidden.bs.collapse', function () { state[body.id] = false; writeState(key, state); });
        });
    });
})();

/* Menu de gauche : les groupes ouverts à la main restent ouverts d'une page à l'autre (par navigateur) ;
 * le groupe de la page courante est ouvert par EasyAdmin lui-même. */
(function () {
    'use strict';
    var KEY = 'ea-menu-groups';
    function read() { try { return JSON.parse(window.localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
    function write(state) { try { window.localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* stockage indisponible */ } }
    function labelOf(group) { var el = group.querySelector(':scope > a.submenu-toggle .menu-item-label'); return el ? el.textContent.trim() : ''; }

    document.addEventListener('DOMContentLoaded', function () {
        var groups = document.querySelectorAll('#main-menu li.menu-item.has-submenu');
        if (!groups.length) { return; }
        var state = read();
        groups.forEach(function (group) {
            var label = labelOf(group);
            if (label && state[label] && !group.classList.contains('expanded')) {
                group.classList.add('expanded');
            }
            var toggle = group.querySelector(':scope > a.submenu-toggle');
            if (!toggle) { return; }
            toggle.addEventListener('click', function () {
                // EasyAdmin bascule la classe « expanded » dans son propre gestionnaire : on lit l'état juste après
                window.setTimeout(function () { state[label] = group.classList.contains('expanded'); write(state); }, 0);
            });
        });
    });
})();
