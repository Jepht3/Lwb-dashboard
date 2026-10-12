/*
 * Lwb-admin 1.5 : finitions sur toutes les pages de l'administration.
 * - textes restés en anglais traduits (statuts, en-têtes, tâches, pages d'erreur) ;
 * - statuts en pastilles de couleur ;
 * - montants sur une ligne, alignés à droite ;
 * - actions de ligne regroupées dans un menu « ⋯ » (suppression rangée en bas, en rouge) ;
 * - anciennes icônes de WHMCS remplacées par des icônes du thème ;
 * - listes vides : message clair et bouton d'ajout ;
 * - journal des paiements : résumé lisible, détail technique repliable.
 * Rien n'est envoyé au serveur : seule l'affichage change.
 */
(function () {
    var html = document.documentElement;
    if (!html.classList.contains('lwba')) { return; }
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function norm(s) { return (s || '').replace(/\s+/g, ' ').trim(); }
    var page = location.pathname.split('/').pop() || 'index.php';

    /* ---------- 1. Textes restés en anglais ---------- */
    var WORDS = {
        'Reports': 'Rapports', 'Client Name': 'Nom du client', 'No Records Found': 'Aucun résultat',
        'No records found': 'Aucun résultat', 'No data': 'Aucune donnée', 'No Data': 'Aucune donnée',
        'Search:': 'Rechercher :', 'Search': 'Rechercher', 'Previous': 'Précédent', 'Next': 'Suivant',
        'Show': 'Afficher', 'entries': 'lignes', 'Loading...': 'Chargement…', 'Processing...': 'Traitement…',
        'Manual Domain Renewal': 'Renouvellement manuel de domaine',
        'Manual Domain Registration': 'Enregistrement manuel de domaine',
        'Manual Domain Transfer': 'Transfert manuel de domaine',
        'Page Not Found': 'Page introuvable', "We couldn't find that page.": 'Cette page n’existe pas ou a été déplacée.',
        'Please try navigating using the options below.': 'Revenez en arrière ou repartez de l’accueil.',
        'Go Back': 'Retour', 'Homepage': 'Accueil', 'Oops!': 'Oups !',
        'Invalid Request': 'Requête invalide', 'HTTP Method Not Allowed': 'Action impossible depuis cette adresse',
        'Access Denied': 'Accès refusé', 'An error occurred': 'Une erreur est survenue',
        'Enter search term...': 'Rechercher un client, une facture, un domaine…',
        'Title': 'Titre', 'Status': 'Statut', 'Due Date': 'Échéance', 'Priority': 'Priorité', 'Amount': 'Montant',
        'Annually': 'Annuel', 'Monthly': 'Mensuel', 'Quarterly': 'Trimestriel', 'Semi-Annually': 'Semestriel',
        'Biennially': 'Tous les 2 ans', 'Triennially': 'Tous les 3 ans', 'One Time': 'Paiement unique', 'Free Account': 'Gratuit',
        'Pay Methods': 'Moyens de paiement', 'No Pay Methods': 'Aucun moyen de paiement enregistré',
        'Add Credit Card': 'Ajouter une carte', 'Add Bank Account': 'Ajouter un compte bancaire',
        'Report a Bug': 'Signaler un bug', 'Verified': 'vérifié', 'Unverified': 'non vérifié'
    };
    // Menu de gauche quand WHMCS l'affiche en anglais (certaines pages d'erreur) et statuts de tickets.
    var NAV = {
        'Clients': 'Clientèle', 'Orders': 'Commandes', 'Billing': 'Facturation', 'Support': 'Support', 'Reports': 'Rapports',
        'Utilities': 'Utilitaires', 'Addons': 'Modules complémentaires', 'Open': 'Ouverts', 'Answered': 'Répondus',
        'Customer-Reply': 'Réponse du client', 'On Hold': 'En pause', 'In Progress': 'En cours', 'Closed': 'Fermés',
        'Flagged Tickets': 'Tickets marqués', 'All Active Tickets': 'Tous les tickets actifs'
    };
    function translateNav() {
        $$('.navigation .navbar-collapse > ul:not(.right-nav) a').forEach(function (a) {
            for (var i = 0; i < a.childNodes.length; i++) {
                var n = a.childNodes[i];
                if (n.nodeType !== 3 || !n.nodeValue.trim()) { continue; }
                var fr = NAV[n.nodeValue.trim()];
                if (fr) { n.nodeValue = n.nodeValue.replace(n.nodeValue.trim(), fr); }
                break;
            }
        });
    }
    var PATTERNS = [
        [/^Client ID (\d+) has paid for the renewal of domain (\S+)$/, 'Le client n° $1 a payé le renouvellement du domaine $2'],
        [/^Client ID (\d+) has paid for the registration of domain (\S+)$/, 'Le client n° $1 a payé l’enregistrement du domaine $2'],
        [/^Client ID (\d+) has paid for the transfer of domain (\S+)$/, 'Le client n° $1 a payé le transfert du domaine $2'],
        [/^Showing (\d+) to (\d+) of (\d+) entries$/, 'Lignes $1 à $2 sur $3'],
        [/^(\d+) hours? ago$/, 'il y a $1 h'], [/^(\d+) minutes? ago$/, 'il y a $1 min'], [/^(\d+) days? ago$/, 'il y a $1 j']
    ];
    function translateNode(n) {
        var t = n.nodeValue, k = t.trim();
        if (!k || k.length > 140) { return; }
        var fr = WORDS[k];
        if (!fr) {
            for (var i = 0; i < PATTERNS.length; i++) {
                if (PATTERNS[i][0].test(k)) { fr = k.replace(PATTERNS[i][0], PATTERNS[i][1]); break; }
            }
        }
        if (fr) { n.nodeValue = t.replace(k, fr); }
    }
    function translate(root) {
        if (!root) { return; }
        var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (n) {
                var p = n.parentNode && n.parentNode.nodeName;
                return (p === 'SCRIPT' || p === 'STYLE' || p === 'TEXTAREA') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            }
        });
        var list = []; while (w.nextNode()) { list.push(w.currentNode); }
        list.forEach(translateNode);
        $$('option', root).forEach(function (o) { var fr = WORDS[norm(o.textContent)]; if (fr) { o.textContent = fr; } });
        $$('input[placeholder]', root).forEach(function (i) { var fr = WORDS[i.placeholder.trim()]; if (fr) { i.placeholder = fr; } });
    }

    /* ---------- 2. Statuts en pastilles ---------- */
    // statut affiché (anglais ou français) -> [libellé français, ton]
    var STATUS = {};
    function st(tone, fr, names) { names.concat([fr]).forEach(function (n) { STATUS[n.toLowerCase()] = [fr, tone]; }); }
    st('good', 'Actif', ['Active', 'Activé']);
    st('good', 'Payé', ['Paid', 'Payée', 'Complet']);
    st('warn', 'Non réglé', ['Incomplete', 'Incomplet']);
    st('good', 'Terminé', ['Completed', 'Complete']);
    st('good', 'Accepté', ['Accepted', 'Acceptée']);
    st('good', 'Réussi', ['Success', 'Successful']);
    st('good', 'Notification reçue', ['Webhook Success']);
    st('info', 'Notification en cours', ['Webhook Processing', 'Processing']);
    st('good', 'Répondu', ['Answered']);
    st('warn', 'En attente', ['Pending', 'Pending Registration', 'Pending Transfer', 'Payment Pending', 'Paiement en attente', 'Draft Pending']);
    st('warn', 'Non payé', ['Unpaid', 'Non payée']);
    st('warn', 'Période de grâce', ['Grace', 'Grace Period']);
    st('warn', 'Réponse client', ['Customer-Reply', 'Customer Reply']);
    st('warn', 'En cours', ['In Progress']);
    st('warn', 'En pause', ['On Hold']);
    st('info', 'Ouvert', ['Open']);
    st('info', 'Brouillon', ['Draft']);
    st('bad', 'Impayé', ['Overdue', 'En retard']);
    st('bad', 'Suspendu', ['Suspended', 'Suspendue']);
    st('bad', 'Fraude', ['Fraud']);
    st('bad', 'Échec', ['Failure', 'Failed', 'Error', 'Erreur', 'Declined', 'Refusé']);
    st('bad', 'Notification en échec', ['Webhook Failed']);
    st('bad', 'Rédemption', ['Redemption', 'Redemption Period']);
    st('bad', 'Grâce expirée', ['Période de grâce (expirée)', 'Grace (Expired)', 'Grace Period (Expired)']);
    st('bad', 'Rédemption expirée', ['Rédemption (expirée)', 'Redemption (Expired)', 'Redemption Period (Expired)']);
    st('bad', 'Recouvrement', ['Collections']);
    st('mute', 'Annulé', ['Cancelled', 'Canceled', 'Annulée']);
    st('mute', 'Résilié', ['Terminated', 'Résiliée']);
    st('mute', 'Fermé', ['Closed', 'Fermée']);
    st('mute', 'Inactif', ['Inactive', 'Inactive Client']);
    st('mute', 'Expiré', ['Expired', 'Expirée']);
    st('mute', 'Remboursé', ['Refunded', 'Remboursée']);
    st('mute', 'Transféré ailleurs', ['Transferred Away']);
    st('mute', 'Clôturé', ['Closed Account']);

    function pill(text) {
        var s = STATUS[norm(text).toLowerCase()];
        if (!s) { return null; }
        var el = document.createElement('span');
        el.className = 'lwba-pill lwba-pill-' + s[1];
        el.textContent = s[0];
        return el;
    }
    function headerIndex(table, re) {
        var head = table.tHead ? table.tHead.rows[0] : table.rows[0];
        if (!head) { return -1; }
        var cells = Array.prototype.slice.call(head.cells), found = -1;
        cells.forEach(function (c, i) { if (found < 0 && re.test(norm(c.textContent))) { found = i; } });
        return found;
    }
    function statusCells(table) {
        var i = headerIndex(table, /^(statut|status|statuts|résultat|result|état)$/i);
        if (i < 0) { return; }
        Array.prototype.slice.call(table.rows).forEach(function (r) {
            var c = r.cells[i];
            if (!c || c.tagName !== 'TD' || c.querySelector('select, input, .lwba-pill')) { return; }
            if (c.querySelectorAll('*').length > 3) { return; }
            var p = pill(c.textContent);
            if (!p) { return; }
            var tgt = c.querySelector('a') ? c.querySelector('a') : c;
            tgt.textContent = ''; tgt.appendChild(p);
            if (tgt !== c) { c.innerHTML = ''; c.appendChild(tgt); }
        });
    }
    // Statuts hors tableaux (fiche client, en-têtes…) : uniquement les anciennes étiquettes WHMCS.
    function statusLabels(root) {
        $$('span.label, span.textred, span.textgreen, span.textblack', root).forEach(function (s) {
            if (s.closest('.lwba-pill, .nav, .navigation, .lwba-topbar') || s.children.length) { return; }
            var p = pill(s.textContent);
            if (p) { s.replaceWith(p); }
        });
    }

    /* ---------- 3. Montants ---------- */
    var MONEY = /^[-−]?\s?[$€]?\s?\d[\d\s.,]*\s?(USD|CDF|EUR|FC|\$|€)?$/;
    function moneyColumns(table) {
        var head = table.tHead ? table.tHead.rows[0] : table.rows[0];
        if (!head) { return; }
        var rows = Array.prototype.slice.call(table.rows).filter(function (r) { return r !== head && r.cells.length === head.cells.length; });
        if (rows.length < 1) { return; }
        Array.prototype.slice.call(head.cells).forEach(function (th, i) {
            if (/identifiant|^id$|#|n°|commande #|facture #|date|téléphone|quantit|qté|ordre/i.test(norm(th.textContent))) { return; }
            var hits = 0, total = 0;
            rows.forEach(function (r) {
                var t = norm(r.cells[i] && r.cells[i].textContent);
                if (!t || t === '-' || t === 'N/A') { return; }
                total++;
                if (MONEY.test(t) && /[.,]\d{2}\b|USD|CDF|EUR|\$|€/.test(t)) { hits++; }
            });
            if (total && hits / total >= 0.8) {
                th.classList.add('lwba-num');
                rows.forEach(function (r) { if (r.cells[i]) { r.cells[i].classList.add('lwba-num'); } });
            }
        });
    }

    /* ---------- 4. Actions de ligne : menu « ⋯ » ---------- */
    var openMenu = null;
    function closeMenus() { if (openMenu) { openMenu.classList.remove('open'); openMenu = null; } }
    document.addEventListener('click', function (e) { if (!e.target.closest('.lwba-kebab')) { closeMenus(); } });
    window.addEventListener('scroll', closeMenus, true);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenus(); } });
    function isDanger(el) {
        return el.classList.contains('btn-danger') || /delete|supprim|annuler et supprimer/i.test((el.id || '') + ' ' + norm(el.textContent) + ' ' + (el.getAttribute('title') || '') + ' ' + ((el.querySelector('img') || {}).alt || ''));
    }
    function kebab(cell, items) {
        var box = document.createElement('div'); box.className = 'lwba-kebab';
        var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'lwba-kebab-btn';
        btn.setAttribute('aria-label', 'Actions'); btn.title = 'Actions'; btn.innerHTML = '<i class="fas fa-ellipsis-h"></i>';
        var menu = document.createElement('div'); menu.className = 'lwba-kebab-menu'; menu.setAttribute('role', 'menu');
        var safe = items.filter(function (i) { return !isDanger(i); }), danger = items.filter(isDanger);
        safe.concat(danger).forEach(function (el, n) {
            el.classList.remove('btn', 'btn-default', 'btn-xs', 'btn-sm', 'btn-danger', 'btn-primary', 'btn-success');
            el.classList.add('lwba-kebab-item');
            if (isDanger(el)) {
                el.classList.add('danger');
                if (n === safe.length && safe.length) { var hr = document.createElement('div'); hr.className = 'lwba-kebab-sep'; menu.appendChild(hr); }
            }
            menu.appendChild(el);
            // Le menu se ferme après un choix (l'action d'origine s'exécute normalement).
            el.addEventListener('click', function () { setTimeout(closeMenus, 0); });
        });
        btn.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            var was = box.classList.contains('open'); closeMenus();
            if (was) { return; }
            box.classList.add('open'); openMenu = box;
            var r = btn.getBoundingClientRect();
            menu.style.top = Math.min(r.bottom + 4, window.innerHeight - menu.offsetHeight - 8) + 'px';
            menu.style.left = Math.max(8, r.right - menu.offsetWidth) + 'px';
        });
        box.appendChild(btn); box.appendChild(menu);
        cell.appendChild(box);
        cell.classList.add('lwba-actions');
    }
    var ICONS = { 'edit.gif': ['fa-pen', 'Modifier'], 'delete.gif': ['fa-trash-alt', 'Supprimer'], 'icons/delete.png': ['fa-trash-alt', 'Supprimer'] };
    function rowActions(table) {
        Array.prototype.slice.call(table.rows).forEach(function (r) {
            // Groupes de boutons (Voir / Editer / Supprimer…) -> menu « ⋯ ».
            Array.prototype.slice.call(r.cells).forEach(function (c) {
                if (c.classList.contains('lwba-actions')) { return; }
                var group = c.querySelector(':scope > .btn-group, :scope > div > .btn-group');
                var btns = group ? $$(':scope > a.btn, :scope > button.btn', group) : [];
                if (btns.length >= 2 && norm(c.textContent).length < 80) {
                    group.remove(); kebab(c, btns);
                }
            });
            // Petites images « modifier / supprimer » : icônes du thème, discrètes.
            $$('td > a > img[src$="edit.gif"], td > a > img[src$="delete.gif"]', r).forEach(function (img) {
                var a = img.parentNode, key = img.getAttribute('src').split('/').pop(), ic = ICONS[key];
                if (!ic || a.classList.contains('lwba-iconbtn')) { return; }
                a.classList.add('lwba-iconbtn'); if (/delete/.test(key)) { a.classList.add('danger'); }
                a.title = a.title || img.alt || ic[1]; a.setAttribute('aria-label', a.title);
                img.replaceWith(Object.assign(document.createElement('i'), { className: 'fas ' + ic[0] }));
            });
        });
    }

    /* ---------- 5. Listes vides ---------- */
    function emptyStates(table) {
        if (table.rows.length > 3) { return; }
        Array.prototype.slice.call(table.rows).forEach(function (r) {
            var c = r.cells[0];
            if (!c || r.cells.length !== 1 || c.classList.contains('lwba-empty')) { return; }
            var t = norm(c.textContent);
            if (!/^(aucun résultat|aucun enregistrement|no records found|no matching records found|aucune donnée|rien à afficher)\.?$/i.test(t)) { return; }
            c.classList.add('lwba-empty');
            // Bouton d'ajout seulement sur une vraie page de liste (pas dans une fiche à plusieurs tableaux).
            var single = !$('#clientsummarycontainer') && $$('#contentarea table.datatable').length <= 1;
            var add = !single ? null : $$('#contentarea a, #contentarea button').filter(function (a) {
                if (a.closest('table, .lwba-empty, .pagination, .nav-tabs') || !a.offsetParent) { return false; }
                return /^(\+\s*)?(ajouter|créer|nouveau|nouvelle|ouvrir un nouveau)/i.test(norm(a.textContent));
            })[0];
            var filtered = /[?&](status|view|validity|filter|type)=/i.test(location.search) && !/view=all/i.test(location.search);
            c.innerHTML = '<div class="lwba-empty-box"><i class="fas fa-inbox"></i><b>' + (filtered ? 'Rien dans ce filtre' : 'Rien à afficher pour le moment') + '</b>'
                + '<span>' + (filtered ? 'Aucun élément ne correspond à ce filtre. Essayez un autre statut.' : 'Les éléments apparaîtront ici dès qu’il y en aura.') + '</span></div>';
            if (add) {
                var b = document.createElement('button'); b.type = 'button'; b.className = 'lwba-empty-add';
                b.textContent = norm(add.textContent).replace(/^\+\s*/, '');
                b.addEventListener('click', function () { add.click(); });
                c.firstChild.appendChild(b);
            }
        });
    }

    function polishTables(root) {
        $$('table.datatable, .tablebg table, table.table, table.clientssummarystats', root).forEach(function (t) {
            if (t.closest('.lwbd, .lwba-sticky-head')) { return; }
            try { statusCells(t); moneyColumns(t); rowActions(t); emptyStates(t); } catch (e) { /* une page inattendue ne doit rien casser */ }
        });
    }

    /* ---------- 6. Fiche client ---------- */
    var LEGACY = {
        'clientlogin.png': 'fa-sign-in-alt', 'clientsadd.png': 'fa-user-plus', 'add.png': 'fa-plus-circle',
        'invoicesedit.png': 'fa-file-invoice', 'addfunds.png': 'fa-wallet', 'ticketspredefined.png': 'fa-comment-dots',
        'billableitems.png': 'fa-receipt', 'income.png': 'fa-coins', 'quotes.png': 'fa-file-signature',
        'orders.png': 'fa-shopping-cart', 'ordersadd.png': 'fa-cart-plus', 'reports.png': 'fa-chart-line',
        'ticketsopen.png': 'fa-life-ring', 'ticketsother.png': 'fa-comments', 'affiliates.png': 'fa-handshake',
        'clients.png': 'fa-users', 'delete.png': 'fa-trash-alt', 'csvexports.png': 'fa-file-export',
        'email.png': 'fa-envelope', 'emails.png': 'fa-envelope', 'notes.png': 'fa-sticky-note', 'logs.png': 'fa-history',
        'products.png': 'fa-box', 'domains.png': 'fa-globe', 'transactions.png': 'fa-exchange-alt', 'invoices.png': 'fa-file-invoice-dollar'
    };
    function legacyIcons(root) {
        $$('img[src*="images/icons/"]', root).forEach(function (img) {
            var name = img.getAttribute('src').split('/').pop(), ic = LEGACY[name];
            if (!ic) { return; }
            var i = document.createElement('i'); i.className = 'fas ' + ic + ' lwba-legacy-ic' + (name === 'delete.png' ? ' danger' : '');
            img.replaceWith(i);
        });
    }
    function clientSummary() {
        var box = $('#clientsummarycontainer');
        if (!box) { return; }
        html.classList.add('lwba-client');
        legacyIcons(box);
        // Coordonnées GPS saisies dans l'adresse : lien vers la carte.
        $$('.clientssummarystats td', box).forEach(function (td) {
            var m = /^\s*(-?\d{1,2}\.\d{4,}),\s*(-?\d{1,3}\.\d{4,})\s*(.*)$/.exec(td.textContent);
            if (!m || td.children.length) { return; }
            td.textContent = '';
            if (m[3]) { td.appendChild(document.createTextNode(m[3] + ' ')); }
            var a = document.createElement('a');
            a.href = 'https://www.google.com/maps?q=' + m[1] + ',' + m[2]; a.target = '_blank'; a.rel = 'noopener';
            a.className = 'lwba-maplink'; a.innerHTML = '<i class="fas fa-map-marker-alt"></i> Voir sur la carte';
            a.title = m[1] + ', ' + m[2];
            td.appendChild(a);
        });
    }

    /* ---------- 7. Pages d'erreur ---------- */
    function errorPage() {
        var e = $('.error-page');
        if (!e) { return; }
        html.classList.add('lwba-errorpage');
        var h = $('#contentarea h1'); if (h) { translate(h); }
        var t = $('.lwba-pagetitle'); if (t && h) { t.textContent = h.textContent.trim(); }
    }

    /* ---------- 8. Journal des paiements ---------- */
    function gatewayLog() {
        if (page !== 'gatewaylog.php') { return; }
        $$('table.datatable tr').forEach(function (r) {
            var ta = r.querySelector('textarea');
            if (!ta || r.classList.contains('lwba-glog')) { return; }
            r.classList.add('lwba-glog');
            var raw = ta.value || ta.textContent, get = function (k) {
                var m = new RegExp('(?:^|\\s)' + k + ' => ([^\\n]+)').exec(raw); return m ? m[1].trim() : '';
            };
            var ref = get('reference'), amount = get('amountCustomer') || get('amount'), cur = get('currency'),
                msg = get('message'), phone = get('phone'), result = get('result') || get('status');
            var parts = [];
            if (amount) { parts.push('<b>' + amount + (cur ? ' ' + cur : '') + '</b>'); }
            if (phone) { parts.push('<i class="fas fa-mobile-alt"></i> ' + phone.replace(/(\d{3})(\d{3})(\d{3})(\d+)/, '$1 $2 $3 $4')); }
            if (ref) { parts.push('Réf. ' + ref); }
            var sum = document.createElement('div'); sum.className = 'lwba-glog-sum';
            sum.innerHTML = '<div>' + (parts.join(' · ') || 'Données techniques') + '</div>'
                + ((msg || result) ? '<div class="lwba-glog-msg"></div>' : '');
            var RES = { failed: 'paiement échoué', success: 'paiement réussi', processing: 'paiement en cours', pending: 'en attente', cancelled: 'annulé' };
            var text = msg ? msg.replace(/^Transaction with code \S+ was declined by the operator\.?$/i, 'Paiement refusé par l’opérateur')
                .replace(/^Transaction with code \S+ has been successfully processed\.?$/i, 'Paiement accepté par l’opérateur')
                : 'Notification FlexPay : ' + (RES[result.toLowerCase()] || result);
            if (msg || result) { sum.querySelector('.lwba-glog-msg').textContent = text; }
            var det = document.createElement('details'); det.className = 'lwba-glog-det';
            det.innerHTML = '<summary>Détails techniques</summary>';
            ta.parentNode.insertBefore(sum, ta);
            ta.parentNode.insertBefore(det, ta);
            det.appendChild(ta);
        });
    }

    /* ---------- Lancement ---------- */
    function run(root) {
        translate(root);
        statusLabels(root);
        polishTables(root);
    }
    var area = $('#contentarea');
    run(area);
    translate($('#sidebar'));
    translateNav();
    var si = $('.lwba-topbar input[placeholder]'); if (si) { translate(si.parentNode); }
    var h1 = $('#contentarea h1'), title = $('.lwba-pagetitle');
    if (h1 && title) { title.textContent = h1.textContent.trim(); }
    clientSummary();
    errorPage();
    gatewayLog();

    // Contenus chargés après coup (listes en AJAX, onglets, fenêtres) : même traitement.
    if (area && window.MutationObserver) {
        var pending = null;
        new MutationObserver(function (muts) {
            if (pending) { return; }
            var touched = muts.some(function (m) { return Array.prototype.some.call(m.addedNodes, function (n) { return n.nodeType === 1 && !n.closest('.lwba-kebab'); }); });
            if (!touched) { return; }
            pending = setTimeout(function () { pending = null; run(area); legacyIcons(area); }, 120);
        }).observe(area, { childList: true, subtree: true });
    }
    // Fenêtres modales (ex. « Nouveautés de la version ») : traduction et étiquettes.
    document.addEventListener('shown.bs.modal', function (e) { run(e.target); });
})();
