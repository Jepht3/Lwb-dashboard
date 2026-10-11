/*
 * Lwb-admin — outils : file « À faire », notifications, listes intelligentes,
 * actions mobiles et WhatsApp. Chargé après admin-theme.js.
 */
(function () {
    var html = document.documentElement;
    if (!html.classList.contains('lwba')) { return; }
    var cfg = window.LWBA || {};
    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
    function el(tag, cls, htm) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (htm != null) { e.innerHTML = htm; } return e; }
    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    var store = {
        get: function (k, d) { try { var v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
        set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
    };
    var page = location.pathname.split('/').pop() || 'index.php';
    var params = new URLSearchParams(location.search);
    var isMobile = function () { return window.matchMedia('(max-width: 767px)').matches; };
    function toast(msg) {
        var t = el('div', 'lwba-toast', esc(msg)); document.body.appendChild(t);
        setTimeout(function () { t.classList.add('on'); }, 10);
        setTimeout(function () { t.classList.remove('on'); setTimeout(function () { t.remove(); }, 300); }, 2200);
    }
    function copy(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(function () { toast('Lien copié'); }, function () { fallback(); }); } else { fallback(); }
        function fallback() { var t = el('textarea'); t.value = text; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); toast('Lien copié'); } catch (e) {} t.remove(); }
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-copy]');
        if (b) { e.preventDefault(); copy(b.getAttribute('data-copy')); }
    });

    /* ---------- 1. Entrée « À faire » dans le rail ---------- */
    var mainUl = $('.navigation .navbar-collapse > ul:not(.right-nav)');
    var todoBadge = null;
    if (cfg.tools && mainUl) {
        var li = el('li', 'lwba-rail-todo');
        li.innerHTML = '<a href="' + esc(cfg.todoUrl) + '"><i class="fas fa-tasks"></i>À faire<b class="lwba-badge"' + (cfg.todo ? '' : ' hidden') + '>' + (cfg.todo || 0) + '</b></a>';
        var first = mainUl.children[0];
        mainUl.insertBefore(li, first && first.classList.contains('bt') ? first.nextSibling : first);
        todoBadge = $('.lwba-badge', li);
        if (params.get('module') === 'lwb_dashboard' && params.get('view') === 'todo') { li.classList.add('lwba-current'); }
    }
    function setTodo(n) { if (todoBadge) { todoBadge.textContent = n; todoBadge.hidden = !n; } }

    /* ---------- 6. Centre de notifications ---------- */
    var top = $('.lwba-topbar');
    if (cfg.tools && top && cfg.ajax) {
        var right = $('ul.right-nav', top);
        var wrap = el('div', 'lwba-bell');
        wrap.innerHTML = '<button type="button" class="lwba-bell-btn" aria-label="Notifications" title="Notifications"><i class="far fa-bell"></i><b class="lwba-badge" hidden>0</b></button>'
            + '<div class="lwba-bell-panel" hidden><div class="lwba-bell-head"><b>Notifications</b><button type="button" class="lwba-bell-read">Tout marquer comme lu</button></div><div class="lwba-bell-list"><p class="lwba-bell-empty">Chargement…</p></div>'
            + '<a class="lwba-bell-foot" href="' + esc(cfg.todoUrl) + '"><i class="fas fa-tasks"></i> Ouvrir la file « À faire »</a></div>';
        top.insertBefore(wrap, right || null);
        var btn = $('.lwba-bell-btn', wrap), badge = $('.lwba-badge', wrap), panel = $('.lwba-bell-panel', wrap), list = $('.lwba-bell-list', wrap);
        var data = null;
        var ago = function (t, now) {
            var s = Math.max(0, now - t);
            if (s < 3600) { return 'il y a ' + Math.max(1, Math.round(s / 60)) + ' min'; }
            if (s < 86400) { return 'il y a ' + Math.round(s / 3600) + ' h'; }
            return 'il y a ' + Math.round(s / 86400) + ' j';
        };
        var render = function () {
            if (!data) { return; }
            var seen = store.get('lwbaSeen', 0);
            var unread = data.events.filter(function (e) { return e.t > seen; }).length + data.sticky.length;
            badge.textContent = unread > 99 ? '99+' : unread; badge.hidden = !unread;
            setTodo(data.todo);
            var items = data.sticky.concat(data.events);
            if (!items.length) { list.innerHTML = '<p class="lwba-bell-empty">Rien de nouveau sur les 7 derniers jours.</p>'; return; }
            list.innerHTML = items.map(function (e) {
                var ext = /^https?:/.test(e.href) ? ' target="_blank" rel="noopener"' : '';
                return '<a class="lwba-notif lvl-' + esc(e.level) + (e.sticky || e.t > seen ? ' unread' : '') + '" href="' + esc(e.href) + '"' + ext + '><i class="fas ' + esc(e.icon) + '"></i><span><b>' + esc(e.title) + '</b><small>' + esc(e.text) + '</small><em>' + (e.sticky ? 'En cours' : ago(e.t, data.now)) + '</em></span></a>';
            }).join('');
        };
        var load = function () {
            if (document.hidden) { return; }
            fetch(cfg.ajax + 'notif', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) { if (j && j.events) { data = j; render(); } })
                .catch(function () {});
        };
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.hidden = !panel.hidden;
            wrap.classList.toggle('open', !panel.hidden);
        });
        $('.lwba-bell-read', wrap).addEventListener('click', function () { if (data) { store.set('lwbaSeen', data.now); render(); } });
        document.addEventListener('click', function (e) { if (!e.target.closest('.lwba-bell')) { panel.hidden = true; wrap.classList.remove('open'); } });
        load();
        setInterval(load, 60000);
        document.addEventListener('visibilitychange', load);
    }

    /* ---------- 4. Listes intelligentes ---------- */
    var rp = params.get('rp') || '';
    var listKey = page + (rp ? '|' + rp.replace(/^\/[^/]+/, '') : '');
    var presets = {
        'invoices.php': [['Toutes', 'invoices.php'], ['Impayées', 'invoices.php?status=Unpaid'], ['En retard', 'invoices.php?status=Overdue'], ['Payées', 'invoices.php?status=Paid'], ['Brouillons', 'invoices.php?status=Draft'], ['Annulées', 'invoices.php?status=Cancelled']],
        'orders.php': [['À valider', 'orders.php?status=Pending'], ['Actives', 'orders.php?status=Active'], ['Annulées', 'orders.php?status=Cancelled'], ['Frauduleuses', 'orders.php?status=Fraud'], ['Toutes', 'orders.php']],
        'supporttickets.php': [['Actifs', 'supporttickets.php?view=active'], ['Ouverts', 'supporttickets.php?view=Open'], ['Réponse client', 'supporttickets.php?view=Customer-Reply'], ['Répondus', 'supporttickets.php?view=Answered'], ['Marqués', 'supporttickets.php?view=flagged'], ['Fermés', 'supporttickets.php?view=Closed'], ['Tous', 'supporttickets.php?view=any']]
    };
    if (page === 'index.php' && /\/services/.test(rp)) {
        var base = 'index.php?rp=' + rp.replace(/\/services.*/, '/services');
        presets.svc = [['Tous', base], ['Hébergement mutualisé', base + '/shared'], ['Revendeurs', base + '/reseller'], ['VPS/Serveurs', base + '/server'], ['Autres', base + '/other'], ['Options', base.replace('/services', '/addons')]];
    }
    var table = $('#contentarea .tablebg > table.datatable');
    // Uniquement sur les vraies listes (WHMCS y affiche « N résultats » et la pagination).
    if (table && !document.getElementById('lwbd') && document.getElementById('frmRecordsFound')) {
        var tablebg = table.parentElement;
        var form = table.closest('form');
        var searchForm = $$('#contentarea form').filter(function (f) {
            return f !== form && f.id !== 'frmRecordsFound' && !$('[name$="[]"]', f) && $$('input[type=text], select', f).some(function (i) { return i.name && i.name !== 'token'; });
        })[0];
        var bar = el('div', 'lwba-listbar');
        var chips = el('div', 'lwba-chips');
        var mine = presets[page] || (presets.svc || []);
        var here = location.pathname.split('/').pop() + location.search;
        mine.forEach(function (p) {
            var a = el('a', 'lwba-chip' + (here === p[1] || (here === page && p[1] === page) ? ' on' : ''), esc(p[0]));
            a.href = p[1]; chips.appendChild(a);
        });
        var views = store.get('lwbaViews', []).filter(function (v) { return v.key === listKey; });
        views.forEach(function (v) {
            var a = el('a', 'lwba-chip mine', '<i class="fas fa-bookmark"></i>' + esc(v.name) + '<span class="x" title="Supprimer cette vue">×</span>');
            a.href = '#'; a.dataset.view = v.id; chips.appendChild(a);
        });
        bar.appendChild(chips);
        var tools = el('div', 'lwba-listtools');
        if (searchForm) { tools.appendChild(el('button', 'lwba-tool save', '<i class="far fa-bookmark"></i> Enregistrer la recherche')); }
        tools.appendChild(el('button', 'lwba-tool cols', '<i class="fas fa-columns"></i> Colonnes'));
        bar.appendChild(tools);
        tablebg.parentNode.insertBefore(bar, tablebg);
        $$('button', tools).forEach(function (b) { b.type = 'button'; });

        // Vues enregistrées : on mémorise les champs du formulaire de recherche, puis on le soumet.
        var applyView = function (v) {
            if (!searchForm) { return; }
            $$('input, select', searchForm).forEach(function (i) {
                if (!i.name || i.name === 'token' || i.type === 'hidden') { return; }
                if (i.type === 'checkbox' || i.type === 'radio') { i.checked = !!(v.fields[i.name] && v.fields[i.name].indexOf(i.value) !== -1); } else { i.value = v.fields[i.name] != null ? v.fields[i.name] : ''; }
            });
            searchForm.submit();
        };
        chips.addEventListener('click', function (e) {
            var a = e.target.closest('[data-view]');
            if (!a) { return; }
            e.preventDefault();
            var all = store.get('lwbaViews', []);
            var v = all.filter(function (x) { return x.id === a.dataset.view; })[0];
            if (e.target.classList.contains('x')) {
                if (confirm('Supprimer la vue « ' + v.name + ' » ?')) { store.set('lwbaViews', all.filter(function (x) { return x.id !== v.id; })); a.remove(); }
                return;
            }
            if (v) { applyView(v); }
        });
        var save = $('.save', tools);
        if (save) {
            save.addEventListener('click', function () {
                var fields = {}, any = false;
                $$('input, select', searchForm).forEach(function (i) {
                    if (!i.name || i.name === 'token' || i.type === 'hidden') { return; }
                    if ((i.type === 'checkbox' || i.type === 'radio')) { if (i.checked) { (fields[i.name] = fields[i.name] || []).push(i.value); any = true; } return; }
                    if (i.value !== '' && i.value !== 'any' && i.value !== 'Any') { fields[i.name] = i.value; any = true; }
                });
                if (!any) { alert('Remplissez d\u2019abord les critères dans « Chercher/Filtrer », puis enregistrez la recherche.'); var t = $('a[href="#tab1"], .nav-tabs a', $('#contentarea')); if (t) { t.click(); } return; }
                var name = prompt('Nom de cette recherche (ex. : Impayés de plus de 30 jours) :');
                if (!name) { return; }
                var all = store.get('lwbaViews', []);
                all.push({ id: 'v' + Date.now(), key: listKey, name: name.slice(0, 40), fields: fields });
                store.set('lwbaViews', all);
                toast('Recherche enregistrée');
                setTimeout(function () { location.reload(); }, 600);
            });
        }

        // Choix des colonnes, mémorisé par liste.
        var heads = $$('tr:first-child > th', table);
        var colKey = 'lwbaCols:' + listKey;
        var styleTag = el('style'); document.head.appendChild(styleTag);
        if (!table.id) { table.id = 'lwbaTbl' + Date.now(); }
        var applyCols = function () {
            var hidden = store.get(colKey, []);
            styleTag.textContent = hidden.map(function (n) { return '#' + table.id + ' tr > :nth-child(' + n + '),.lwba-sticky-head tr > :nth-child(' + n + '){display:none}'; }).join('');
        };
        applyCols();
        var menu = el('div', 'lwba-colmenu'); menu.hidden = true;
        heads.forEach(function (th, i) {
            var label = th.textContent.trim();
            if (!label || $('input', th)) { return; }
            var hidden = store.get(colKey, []);
            var row = el('label', '', '<input type="checkbox"' + (hidden.indexOf(i + 1) === -1 ? ' checked' : '') + '> ' + esc(label));
            $('input', row).addEventListener('change', function (e) {
                var h = store.get(colKey, []).filter(function (n) { return n !== i + 1; });
                if (!e.target.checked) { h.push(i + 1); }
                store.set(colKey, h); applyCols();
            });
            menu.appendChild(row);
        });
        tools.appendChild(menu);
        $('.cols', tools).addEventListener('click', function (e) { e.stopPropagation(); menu.hidden = !menu.hidden; });
        document.addEventListener('click', function (e) { if (!e.target.closest('.lwba-colmenu')) { menu.hidden = true; } });

        // En-tête qui reste visible pendant le défilement.
        var headRow = $('tr:first-child', table);
        var sticky = el('div', 'lwba-sticky-head'); sticky.hidden = true;
        var st = el('table', table.className); sticky.appendChild(st); document.body.appendChild(sticky);
        var buildHead = function () {
            st.innerHTML = ''; var clone = headRow.cloneNode(true);
            $$('input', clone).forEach(function (i) { i.remove(); });
            st.appendChild(clone);
            var src = $$(':scope > *', headRow), dst = $$(':scope > *', clone);
            src.forEach(function (c, i) { if (dst[i]) { dst[i].style.width = c.getBoundingClientRect().width + 'px'; dst[i].style.minWidth = dst[i].style.width; } });
        };
        var place = function () {
            var r = table.getBoundingClientRect(), topH = (document.querySelector('.lwba-topbar') || { offsetHeight: 0 }).offsetHeight;
            var show = r.top < topH && r.bottom > topH + 60;
            if (show && sticky.hidden) { buildHead(); }
            sticky.hidden = !show;
            if (show) { var b = tablebg.getBoundingClientRect(); sticky.style.top = topH + 'px'; sticky.style.left = b.left + 'px'; sticky.style.width = b.width + 'px'; st.style.width = r.width + 'px'; st.style.transform = 'translateX(' + (r.left - b.left) + 'px)'; }
        };
        window.addEventListener('scroll', place, { passive: true });
        window.addEventListener('resize', function () { sticky.hidden = true; place(); });
        tablebg.addEventListener('scroll', place, { passive: true });

        // Barre de sélection en haut : reprend les actions groupées de WHMCS.
        var bulk = form ? $$('input[type=submit], button[type=submit]', form).filter(function (b) { return !table.contains(b) && (b.value || b.textContent).trim(); }) : [];
        var seenLabels = {};
        bulk = bulk.filter(function (b) { var l = (b.value || b.textContent).trim(); if (seenLabels[l]) { return false; } seenLabels[l] = 1; return true; });
        if (bulk.length) {
            var sel = el('div', 'lwba-selbar'); sel.hidden = true;
            sel.innerHTML = '<b class="n"></b><div class="acts"></div><button type="button" class="lwba-sel-clear">Désélectionner</button>';
            bulk.forEach(function (b) {
                var c = el('button', 'lwba-sel-btn' + (/danger/.test(b.className) ? ' danger' : (/success|primary/.test(b.className) ? ' primary' : '')), esc((b.value || b.textContent).trim()));
                c.type = 'button';
                c.addEventListener('click', function () { b.click(); });
                $('.acts', sel).appendChild(c);
            });
            document.body.appendChild(sel);
            var boxes = function () { return $$('tbody input[type=checkbox], tr input[type=checkbox][name$="[]"]', table).filter(function (x, i, a) { return a.indexOf(x) === i && x.name; }); };
            var update = function () {
                var n = boxes().filter(function (x) { return x.checked; }).length;
                sel.hidden = !n; $('.n', sel).textContent = n + ' sélectionné' + (n > 1 ? 's' : '');
            };
            table.addEventListener('change', function () { setTimeout(update, 0); });
            table.addEventListener('click', function (e) { if (e.target.type === 'checkbox') { setTimeout(update, 0); } });
            $('.lwba-sel-clear', sel).addEventListener('click', function () { $$('input[type=checkbox]', table).forEach(function (x) { x.checked = false; }); update(); });
        }
    }

    /* ---------- 9. Actions mobiles et WhatsApp ---------- */
    var mbar = el('div', 'lwba-mbar');
    var addM = function (label, icon, fn, cls) {
        var b = el('button', 'lwba-mbtn ' + (cls || ''), '<i class="' + icon + '"></i><span>' + esc(label) + '</span>'); b.type = 'button';
        b.addEventListener('click', fn); mbar.appendChild(b); return b;
    };
    var action = params.get('action');
    if (page === 'orders.php' && action === 'view') {
        var acc = $('#btnAcceptOrder');
        if (acc && !acc.disabled) { addM('Accepter', 'fas fa-check-circle', function () { acc.click(); }, 'primary'); }
        var cl = $('#contentarea a[href*="clientssummary.php"]');
        if (cl) { addM('Client', 'fas fa-user', function () { location.href = cl.href; }); }
    }
    if (page === 'supporttickets.php' && action === 'view') {
        var ta = $('#replymessage'), post = $('#btnPostReply');
        if (ta) { addM('Répondre', 'fas fa-reply', function () { ta.scrollIntoView({ behavior: 'smooth', block: 'center' }); setTimeout(function () { ta.focus(); }, 300); }, 'primary'); }
        if (post) { addM('Envoyer', 'fas fa-paper-plane', function () { post.click(); }); }
        if (location.hash === '#replymessage' && ta) { setTimeout(function () { ta.scrollIntoView({ block: 'center' }); ta.focus(); }, 400); }
    }
    if (page === 'invoices.php' && action === 'edit' && cfg.tools && cfg.ajax) {
        var invId = params.get('id');
        var unpaid = /non pay|unpaid/i.test(($('#contentarea .textred') || {}).textContent || '');
        if (unpaid) {
            fetch(cfg.ajax + 'wa&invoice=' + encodeURIComponent(invId), { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
                if (!j || j.error) { return; }
                var go = function () { if (j.phone) { window.open(j.url, '_blank', 'noopener'); } else { alert('Aucun numéro de téléphone valide sur la fiche du client.'); } };
                addM('WhatsApp', 'fab fa-whatsapp', go, 'wa');
                addM('Copier le lien', 'fas fa-link', function () { copy(j.link); });
                var pay = $$('#contentarea .nav-tabs a').filter(function (a) { return /paiement/i.test(a.textContent); })[0];
                if (pay) { addM('Paiement reçu', 'fas fa-money-bill-wave', function () { pay.click(); pay.scrollIntoView({ block: 'start' }); }); }
                // Sur ordinateur : bouton WhatsApp à côté du lien FlexPay.
                var flex = $('#flexpayPayLink');
                if (flex && j.phone) {
                    var w = el('a', 'btn btn-default lwba-wa-inline', '<i class="fab fa-whatsapp"></i> WhatsApp'); w.href = j.url; w.target = '_blank'; w.rel = 'noopener';
                    var grp = flex.parentElement; (grp.querySelector('.input-group-btn') || grp).appendChild(w);
                }
                showBar();
            }).catch(function () {});
        }
    }
    function showBar() { if (mbar.children.length && !mbar.parentNode) { document.body.appendChild(mbar); html.classList.add('lwba-has-mbar'); } }
    showBar();
})();
