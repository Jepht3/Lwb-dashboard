/* Lwb-dashboard : recherche rapide (Ctrl+K / Cmd+K) — réglages, clients, factures, domaines, services, tickets. */
(function () {
    var cfg = window.LWBD_SEARCH || {};
    if (!cfg.url || window.LWBD_SEARCH_READY) { return; }
    window.LWBD_SEARCH_READY = true;

    var css = '.lwbk-back{position:fixed;inset:0;z-index:100000;background:rgba(13,13,43,.45);display:flex;align-items:flex-start;justify-content:center;padding:12vh 16px 16px}'
        + '.lwbk-back[hidden]{display:none}'
        + '.lwbk{width:100%;max-width:640px;background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(13,13,43,.35);overflow:hidden;font-family:inherit;color:#0d0d2b}'
        + '.lwbk-in{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid #e6e9f2}'
        + '.lwbk-in svg{width:20px;height:20px;flex:none;stroke:#8a8ea8;fill:none;stroke-width:2;stroke-linecap:round}'
        + '.lwbk-in input{flex:1;min-width:0;border:0!important;outline:0!important;box-shadow:none!important;font-size:17px;padding:4px 0;background:transparent;color:inherit}'
        + '.lwbk-in kbd{font:600 11px inherit;color:#8a8ea8;border:1px solid #e6e9f2;border-radius:6px;padding:2px 6px}'
        + '.lwbk-list{list-style:none;margin:0;padding:6px;max-height:min(60vh,460px);overflow-y:auto}'
        + '.lwbk-list li a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:10px;color:#0d0d2b!important;text-decoration:none!important}'
        + '.lwbk-list li.on a,.lwbk-list li a:hover{background:#eef0fb}'
        + '.lwbk-t{flex:none;min-width:74px;font-size:10.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#5b6bd6}'
        + '.lwbk-l{flex:1;min-width:0}.lwbk-l b{display:block;font-size:14px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        + '.lwbk-l small{display:block;font-size:12px;color:#575b78;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        + '.lwbk-empty{padding:18px 16px;color:#575b78;font-size:13px}'
        + '.lwbk-foot{display:flex;gap:14px;padding:8px 14px;border-top:1px solid #e6e9f2;font-size:11.5px;color:#8a8ea8}'
        + '.lwbk-btn{display:inline-flex;align-items:center;justify-content:center;min-width:40px;height:40px;border:0;border-radius:10px;background:none;color:inherit;cursor:pointer}'
        + '.lwbk-btn svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round}'
        + '@media (prefers-color-scheme:dark){html:not(.lwba) .lwbk{background:#16182c;color:#eef0fb}}'
        + '@media (max-width:600px){.lwbk-back{padding:8px}.lwbk-t{min-width:60px}.lwbk-foot{display:none}}';
    var st = document.createElement('style');
    st.textContent = css;
    document.head.appendChild(st);

    var icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>';
    var back = document.createElement('div');
    back.className = 'lwbk-back';
    back.hidden = true;
    back.innerHTML = '<div class="lwbk" role="dialog" aria-modal="true" aria-label="Recherche rapide">'
        + '<div class="lwbk-in">' + icon + '<input type="text" autocomplete="off" spellcheck="false" placeholder="Réglage, client, facture, domaine, ticket…" aria-label="Rechercher"><kbd>Échap</kbd></div>'
        + '<ul class="lwbk-list" role="listbox"></ul>'
        + '<div class="lwbk-foot"><span>↑ ↓ pour choisir</span><span>Entrée pour ouvrir</span><span>Ctrl+Entrée : nouvel onglet</span></div></div>';
    document.body.appendChild(back);
    var input = back.querySelector('input');
    var list = back.querySelector('.lwbk-list');
    var items = [];
    var sel = 0;
    var timer = null;
    var seq = 0;
    var cache = {};

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function render(q) {
        if (!q || q.length < 2) {
            list.innerHTML = '<li class="lwbk-empty">Tapez au moins 2 caractères : « serveurs », « liens d\'application », un nom de client, « facture 4269 », un domaine…</li>';
            items = [];
            return;
        }
        if (!items.length) {
            list.innerHTML = '<li class="lwbk-empty">Aucun résultat pour « ' + esc(q) + ' ».</li>';
            return;
        }
        list.innerHTML = items.map(function (r, i) {
            return '<li role="option"' + (i === sel ? ' class="on" aria-selected="true"' : '') + '><a href="' + esc(r.href) + '"><span class="lwbk-t">' + esc(r.type) + '</span>'
                + '<span class="lwbk-l"><b>' + esc(r.label) + '</b>' + (r.meta ? '<small>' + esc(r.meta) + '</small>' : '') + '</span></a></li>';
        }).join('');
        var on = list.querySelector('li.on');
        if (on && on.scrollIntoView) { on.scrollIntoView({ block: 'nearest' }); }
    }
    function search(q) {
        q = q.trim();
        sel = 0;
        if (q.length < 2) { items = []; render(q); return; }
        if (cache[q]) { items = cache[q]; render(q); return; }
        var mine = ++seq;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', cfg.url + encodeURIComponent(q));
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function () {
            if (mine !== seq) { return; }
            try {
                var res = JSON.parse(xhr.responseText);
                items = cache[q] = res.results || [];
            } catch (e) { items = []; }
            render(q);
        };
        xhr.send();
    }
    function open() {
        back.hidden = false;
        input.value = '';
        items = [];
        render('');
        setTimeout(function () { input.focus(); }, 10);
    }
    function close() { back.hidden = true; }
    function go(newTab) {
        var r = items[sel];
        if (!r) { return; }
        if (newTab) { window.open(r.href, '_blank'); } else { window.location.href = r.href; }
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value;
        timer = setTimeout(function () { search(q); }, 180);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (items.length) { sel = (sel + 1) % items.length; render(input.value.trim()); } }
        else if (e.key === 'ArrowUp') { e.preventDefault(); if (items.length) { sel = (sel - 1 + items.length) % items.length; render(input.value.trim()); } }
        else if (e.key === 'Enter') { e.preventDefault(); go(e.ctrlKey || e.metaKey); }
        else if (e.key === 'Escape') { e.preventDefault(); close(); }
    });
    back.addEventListener('mousedown', function (e) { if (e.target === back) { close(); } });
    list.addEventListener('mousemove', function (e) {
        var li = e.target.closest('li[role="option"]');
        if (!li) { return; }
        var i = Array.prototype.indexOf.call(list.children, li);
        if (i !== sel && i >= 0) { sel = i; Array.prototype.forEach.call(list.children, function (x, j) { x.classList.toggle('on', j === sel); }); }
    });
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            if (back.hidden) { open(); } else { close(); }
        } else if (e.key === 'Escape' && !back.hidden) {
            close();
        }
    });

    // Bouton dans la barre du haut du thème (utile sur téléphone, sans clavier).
    var bar = document.querySelector('.lwba-topbar ul.right-nav') || document.querySelector('.lwba-topbar');
    if (bar) {
        var btn = document.createElement(bar.tagName === 'UL' ? 'li' : 'span');
        btn.innerHTML = '<button type="button" class="lwbk-btn" title="Recherche rapide (Ctrl+K)" aria-label="Recherche rapide">' + icon + '</button>';
        btn.firstChild.addEventListener('click', open);
        bar.insertBefore(btn, bar.firstChild);
    }
    window.LWBD_SEARCH_OPEN = open;
})();
