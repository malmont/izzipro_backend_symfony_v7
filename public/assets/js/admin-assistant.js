/* Console IA flottante du back-office (07/11/2026) : sur toutes les pages EasyAdmin, pour un administrateur du site.
 * Même assistant et mêmes routes que le panneau de « Mon site : pilotage » (/admin/mon-site/assistant, confirmation d'une action) ;
 * l'historique de l'échange suit l'administrateur de page en page (sessionStorage) ; la page courante est signalée à l'assistant. */
(function () {
    'use strict';

    function boot() {
        var root = document.getElementById('arkanoa-assistant');
        if (!root || document.getElementById('assistant-form')) { return; } // la page Mon site a déjà son panneau
        var token = root.getAttribute('data-token'), chatUrl = root.getAttribute('data-chat'), confirmBase = root.getAttribute('data-confirm'), tier = root.getAttribute('data-tier') || 'full';
        var storeKey = 'arkanoa-assistant:' + (root.getAttribute('data-site') || '');
        var history = [], shown = [];
        try { var saved = JSON.parse(window.sessionStorage.getItem(storeKey) || '{}'); history = saved.history || []; shown = saved.shown || []; } catch (e) { history = []; shown = []; }

        root.innerHTML =
            '<button type="button" class="ark-assistant-toggle" id="ark-assistant-toggle" title="Assistant IA">✦ Assistant IA</button>' +
            '<div class="ark-assistant-panel" id="ark-assistant-panel" hidden>' +
            '  <div class="ark-assistant-head"><strong>Assistant IA du site</strong><span class="ark-assistant-tier">' + (tier === 'help' ? 'Aide IA' : 'IA complète') + '</span>' +
            '    <button type="button" class="ark-assistant-clear" id="ark-assistant-clear" title="Nouvel échange">↺</button><button type="button" class="ark-assistant-close" id="ark-assistant-close" title="Fermer">×</button></div>' +
            '  <div class="ark-assistant-hint">Posez une question ou demandez une action (« que me manque-t-il pour vendre ? », « rembourse la commande REF… », « explique les taxes »). Il propose, vous confirmez.</div>' +
            '  <div class="ark-assistant-log" id="ark-assistant-log"></div>' +
            '  <form class="ark-assistant-form" id="ark-assistant-form"><input class="form-control" id="ark-assistant-input" placeholder="Votre demande…" maxlength="4000" autocomplete="off"><button type="submit" class="btn btn-primary" id="ark-assistant-send">Envoyer</button></form>' +
            '</div>';

        var panel = document.getElementById('ark-assistant-panel'), log = document.getElementById('ark-assistant-log'), input = document.getElementById('ark-assistant-input'),
            form = document.getElementById('ark-assistant-form'), send = document.getElementById('ark-assistant-send'), toggle = document.getElementById('ark-assistant-toggle');

        function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); }
        function save() { try { window.sessionStorage.setItem(storeKey, JSON.stringify({history: history.slice(-20), shown: shown.slice(-40)})); } catch (e) { /* stockage indisponible */ } }
        function line(role, html, keep) {
            var d = document.createElement('div'); d.className = 'ark-assistant-line ark-assistant-' + role;
            d.innerHTML = (role === 'user' ? '<strong>Vous :</strong> ' : '<strong>Assistant :</strong> ') + html; log.appendChild(d); log.scrollTop = log.scrollHeight;
            if (keep !== false) { shown.push({role: role, html: html}); save(); }
            return d;
        }
        function post(url, body) { return fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': token}, body: JSON.stringify(body || {})}).then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); }); }
        function pageContext() { var t = (document.title || '').replace(/\s+/g, ' ').trim(); return t ? '\n(Je suis sur la page « ' + t.slice(0, 120) + ' » du back-office.)' : ''; }
        function render(j) {
            var html = esc(j.reply).replace(/\n/g, '<br>');
            (j.links || []).forEach(function (l) { if (l.url) { html += ' <a class="btn btn-sm btn-outline-secondary ms-1" href="' + esc(l.url) + '">' + esc(l.label) + '</a>'; } });
            (j.actions || []).forEach(function (a) {
                var money = /refund|cancel|capture/.test(a.tool || '');
                html += '<div class="ark-assistant-action' + (money ? ' ark-assistant-action-money' : '') + '" data-action="' + esc(a.id) + '"><span>' + esc(a.summary) + '</span> <button type="button" class="btn btn-sm ' + (money ? 'btn-danger' : 'btn-success') + ' ms-2" data-confirm="' + esc(a.id) + '">Confirmer</button>' + (a.tool === 'setup_batch' && a.input && a.input.steps ? '<ol class="small mb-0 mt-1 ps-3">' + a.input.steps.map(function (s) { return '<li>' + esc(s.summary || s.tool) + '</li>'; }).join('') + '</ol>' : '') + '</div>';
            });
            return html;
        }

        shown.forEach(function (s) { line(s.role, s.html, false); });
        function prefillFromHash() {
            var h = window.location.hash || '';
            if (h.indexOf('#assistant=') !== 0) { return; }
            panel.hidden = false; input.value = decodeURIComponent(h.substring(11).replace(/\+/g, ' ')); input.focus();
            try { history.replaceState(null, '', window.location.pathname + window.location.search); } catch (e) { /* ignoré */ }
        }
        prefillFromHash();
        document.addEventListener('click', function (ev) {
            var a = ev.target.closest('a[data-assistant-prefill]'); if (!a) { return; }
            ev.preventDefault(); window.location.hash = a.getAttribute('href'); prefillFromHash();
        });
        toggle.addEventListener('click', function () { panel.hidden = !panel.hidden; if (!panel.hidden) { input.focus(); log.scrollTop = log.scrollHeight; } });
        document.getElementById('ark-assistant-close').addEventListener('click', function () { panel.hidden = true; });
        document.getElementById('ark-assistant-clear').addEventListener('click', function () { history = []; shown = []; log.innerHTML = ''; save(); input.focus(); });
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var text = input.value.trim(); if (!text) { return; }
            input.value = ''; send.disabled = true;
            history.push({role: 'user', content: text + pageContext()}); line('user', esc(text));
            var waiting = line('assistant', '<span class="text-muted">…</span>', false);
            post(chatUrl, {messages: history.slice(-20)}).then(function (j) {
                send.disabled = false;
                if (j._status !== 200) { history.pop(); waiting.remove(); line('assistant', '<span class="text-danger">' + esc(j.message || 'Erreur') + '</span>'); return; }
                history.push({role: 'assistant', content: j.reply});
                waiting.remove(); line('assistant', render(j));
                if (j.credits && j.credits.monthly > 0 && j.credits.remaining * 5 <= j.credits.monthly && root.getAttribute('data-mon-site')) {
                    line('assistant', '<span class="text-warning">Il vous reste ' + j.credits.remaining + ' crédit(s) IA sur ' + j.credits.monthly + ' ce mois-ci.</span> <a class="btn btn-sm btn-outline-warning ms-1" href="' + esc(root.getAttribute('data-mon-site')) + '">Acheter des crédits ou changer de forfait</a>', false);
                }
            }).catch(function () { send.disabled = false; waiting.remove(); line('assistant', '<span class="text-danger">Assistant injoignable.</span>'); });
        });
        log.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-confirm]'); if (!btn) { return; }
            btn.disabled = true;
            post(confirmBase.replace('0000000000000000', btn.getAttribute('data-confirm'))).then(function (j) {
                var box = btn.closest('[data-action]');
                if (j._status === 200) { box.innerHTML = '<span class="text-success">✔ Appliqué.</span> <a href="" class="small">Recharger la page</a>'; shown.push({role: 'assistant', html: '<span class="text-success">✔ Action appliquée.</span>'}); save(); }
                else { btn.disabled = false; box.insertAdjacentHTML('beforeend', '<div class="text-danger small">' + esc(j.message || 'Refusé') + '</div>'); }
            });
        });
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
