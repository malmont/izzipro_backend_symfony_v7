/* Aperçu en direct de l'apparence (B3, 31/10/2026), formulaire « Profils des applications » d'EasyAdmin.
 * Le panneau « Apparence de l'application » porte un <span class="apercu-data" data-apercu="…"> (JSON : gabarits,
 * adresse du cadre, repli des champs vides, profil). Le cadre charge /admin/apercu/<code>?embed=1 (même origine) et,
 * à chaque input/change d'un champ du panneau, reçoit window.ApercuApparence.update(theme, profil).
 * Rien n'est enregistré avant « Sauvegarder ». */
(function () {
    'use strict';

    var FIELDS = { primaryColor: 'primaryColor', accentColor: 'accentColor', themeMode: 'mode', themeFont: 'font', themeRadius: 'radius', buttonStyle: 'buttonStyle', productCard: 'productCard', homeHero: 'homeHero' };

    function fieldValue(form, property) {
        var input = form.querySelector('[name$="[' + property + ']"]');
        if (!input || input.disabled) { return null; }
        var value = (input.value || '').trim();
        if (value === '') { return null; }
        if (property === 'themeRadius') { var n = parseInt(value, 10); return isNaN(n) ? null : Math.max(0, Math.min(28, n)); }
        if (property === 'primaryColor' || property === 'accentColor') { return /^#[0-9a-fA-F]{6}$/.test(value) ? value.toLowerCase() : null; }
        return value;
    }

    function currentApp(form, data) {
        var select = form.querySelector('[name$="[app]"]');
        return select && select.value ? select.value : data.app;
    }

    function readTheme(form, data) {
        var app = currentApp(form, data);
        var fallback = app === 'mobile' ? (data.fallbackMobile || {}) : {};
        var theme = {};
        Object.keys(FIELDS).forEach(function (property) {
            var key = FIELDS[property];
            var own = fieldValue(form, property);
            theme[key] = own !== null ? own : (fallback[key] === undefined ? null : fallback[key]);
        });
        theme.updatedAt = null;
        return theme;
    }

    function readProfil(form, data) {
        var name = form.querySelector('[name$="[appName]"]');
        var profil = { name: (data.profil && data.profil.name) || null, logo: (data.profil && data.profil.logo) || null };
        if (name && name.value.trim() !== '') { profil.name = name.value.trim(); }
        return profil;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var holder = document.querySelector('.apercu-data');
        if (!holder) { return; }
        var data;
        try { data = JSON.parse(holder.getAttribute('data-apercu') || '{}'); } catch (e) { return; }
        var form = holder.closest('form');
        // L'aide du panneau est rendue dans son en-tête : le corps (les champs) est le frère dans le même <fieldset>
        var fieldset = holder.closest('.form-fieldset');
        var body = fieldset ? fieldset.querySelector('.form-fieldset-body') : null;
        if (!form || !body) { return; }

        // Mise en page : champs à gauche, appareil à droite (sous les champs sur un écran étroit)
        var wrap = document.createElement('div');
        wrap.className = 'apercu-wrap';
        var fields = document.createElement('div');
        fields.className = 'apercu-fields';
        while (body.firstChild) { fields.appendChild(body.firstChild); }
        var frame = document.createElement('div');
        frame.className = 'apercu-frame';
        wrap.appendChild(fields);
        wrap.appendChild(frame);
        body.appendChild(wrap);

        var iframe = null;
        var ready = false;

        function push() {
            if (!iframe || !ready) { return; }
            try {
                var api = iframe.contentWindow && iframe.contentWindow.ApercuApparence;
                if (api && typeof api.update === 'function') { api.update(readTheme(form, data), readProfil(form, data)); }
            } catch (e) { /* cadre pas encore prêt */ }
        }

        function mount(app) {
            frame.innerHTML = '';
            iframe = null;
            ready = false;
            if (!app || (data.templates || []).indexOf(app) === -1) {
                var note = document.createElement('p');
                note.className = 'apercu-unavailable';
                note.textContent = app ? (data.unavailable || 'Aperçu non disponible pour cette application') : 'Choisissez l\'application pour voir l\'aperçu.';
                frame.appendChild(note);
                return;
            }
            iframe = document.createElement('iframe');
            iframe.className = 'apercu-iframe';
            iframe.title = 'Aperçu de l\'application';
            iframe.setAttribute('loading', 'eager');
            iframe.src = '/admin/apercu/' + app + '?embed=1';
            iframe.addEventListener('load', function () {
                ready = true;
                push();
                // le script du gabarit peut charger son module après « load » : second passage
                window.setTimeout(push, 400);
                window.setTimeout(push, 1500);
            });
            frame.appendChild(iframe);
        }

        mount(currentApp(form, data));

        ['input', 'change'].forEach(function (type) {
            fields.addEventListener(type, push);
            form.addEventListener(type, function (event) {
                var target = event.target;
                if (target && /\[app\]$/.test(target.name || '')) { mount(target.value); }
                if (target && /\[appName\]$/.test(target.name || '')) { push(); }
            });
        });
    });
})();
