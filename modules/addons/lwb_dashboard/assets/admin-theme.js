/* Lwb-admin : réorganise l'interface Blend (rail, barre du haut, menus). */
(function () {
    var html = document.documentElement;
    if (!html.classList.contains('lwba')) { return; }
    var cfg = window.LWBA || {};
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function clean(a) {
        // « - Hébergement mutualisé » -> « Hébergement mutualisé »
        var n = a.childNodes;
        for (var i = 0; i < n.length; i++) {
            if (n[i].nodeType === 3 && /^\s*-\s+/.test(n[i].nodeValue)) { n[i].nodeValue = n[i].nodeValue.replace(/^\s*-\s+/, ''); break; }
            if (n[i].nodeType === 3 && n[i].nodeValue.trim()) { break; }
        }
    }

    var nav = $('.navigation');
    if (!nav) { html.classList.remove('lwba'); return; }

    // Logo blanc de la marque (réglages) ; sans logo, celui de WHMCS reste.
    var logo = $('a.logo img', nav);
    if (logo && cfg.logo) { logo.src = cfg.logo; logo.alt = cfg.name || ''; }

    // Bouton « Nouveau ».
    var add = $('.navbar-collapse > ul > li.bt:first-child > a', nav);
    if (add && !$('.lwba-label', add)) {
        var s = document.createElement('span'); s.className = 'lwba-label'; s.textContent = cfg.addLabel || 'Nouveau'; add.appendChild(s);
    }

    // Barre du haut : bouton menu, titre, recherche, icônes.
    var top = document.createElement('div');
    top.className = 'lwba-topbar';
    top.innerHTML = '<button type="button" class="lwba-burger" aria-label="Menu"><i class="fas fa-bars"></i></button>'
        + '<button type="button" class="lwba-sidetoggle" title="Afficher ou masquer le menu de la section" aria-label="Menu de la section"><i class="fas fa-columns"></i></button>'
        + '<div class="lwba-pagetitle"></div>';
    document.body.appendChild(top);
    var h1 = $('#contentarea h1');
    $('.lwba-pagetitle', top).textContent = h1 ? h1.textContent.trim() : document.title.replace(/^WHMCS\s*-\s*/, '');
    var search = $('#intelliSearchForm');
    if (search) { top.appendChild(search); var si = $('input[type=text], input:not([type])', search); if (si && !si.placeholder) { si.placeholder = 'Rechercher un client, une facture, un domaine…'; } }
    // Panneau des résultats aligné sous le champ de recherche.
    if (search) {
        var placeResults = function () { html.style.setProperty('--lwba-search-left', Math.round(search.getBoundingClientRect().left) + 'px'); };
        placeResults();
        window.addEventListener('resize', placeResults);
        search.addEventListener('focusin', placeResults);
    }
    var right = $('ul.right-nav', nav);
    if (right) {
        top.appendChild(right);
        $$(':scope > li > a', right).forEach(function (a) {
            var t = $('.visible-sidebar', a);
            if (t && !a.title) { a.title = t.textContent.trim(); a.setAttribute('aria-label', a.title); }
        });
    }
    var back = document.createElement('div'); back.className = 'lwba-backdrop'; document.body.appendChild(back);
    $('.lwba-burger', top).addEventListener('click', function () { html.classList.toggle('lwba-nav-open'); });
    // Menu de la section masquable, mémorisé d'une page à l'autre (pour les grands tableaux).
    // Par défaut le panneau est masqué : ses liens font doublon avec le menu de gauche (v1.5).
    var store = { get: function () { try { return localStorage.getItem('lwbaSide2'); } catch (e) { return null; } },
        set: function (v) { try { localStorage.setItem('lwbaSide2', v); } catch (e) {} } };
    if (store.get() !== 'shown') { html.classList.add('lwba-sidehidden'); }
    $('.lwba-sidetoggle', top).addEventListener('click', function () {
        html.classList.toggle('lwba-sidehidden');
        store.set(html.classList.contains('lwba-sidehidden') ? 'hidden' : 'shown');
    });
    back.addEventListener('click', function () { html.classList.remove('lwba-nav-open'); });

    // Entrée « Configuration » dans le rail : menu dépliant avec tous les réglages WHMCS.
    var mainUl = $('.navbar-collapse > ul:not(.right-nav)', nav);
    if (mainUl && !$('.lwba-rail-setup', mainUl)) {
        var li = document.createElement('li');
        li.className = 'has-dropdown lwba-rail-setup';
        var head = document.createElement('a');
        head.href = '#';
        head.innerHTML = '<i class="fas fa-cog"></i>Configuration';
        li.appendChild(head);
        var sub = document.createElement('ul');
        (cfg.setup || []).forEach(function (group) {
            var g = document.createElement('li');
            g.className = 'lwba-railgroup';
            g.textContent = group[0];
            sub.appendChild(g);
            group[1].forEach(function (item) {
                var it = document.createElement('li');
                var a = document.createElement('a');
                a.href = item[1];
                a.textContent = item[0];
                it.appendChild(a);
                sub.appendChild(it);
            });
        });
        if (!sub.children.length) {
            sub.innerHTML = '<li><a href="configgeneral.php">Paramètres généraux</a></li><li><a href="configapplinks.php">Liens d\'application (cPanel)</a></li>';
        }
        li.appendChild(sub);
        mainUl.appendChild(li);
        var page = location.pathname.split('/').pop();
        if (/^config|^systemadmin|^systemhealth|^marketconnect/.test(page) || /rp=\/[^\/]+\/(setup|apps)/.test(location.search)) {
            li.classList.add('lwba-current', 'lwba-open');
        }
    }

    // Sous-menu de la clé à molette (barre du haut) : il était masqué par le thème.
    if (right) {
        $$('ul.drop-icons', right).forEach(function (u) { u.classList.add('lwba-drop'); u.classList.remove('drop-icons'); });
    }

    // Pied du rail.
    var foot = document.createElement('div');
    foot.className = 'lwba-railfoot';
    foot.innerHTML = '<span></span><br><a href="' + (cfg.dashboard || 'addonmodules.php?module=lwb_dashboard') + '">Tableau de bord</a>';
    foot.firstChild.textContent = (cfg.name ? cfg.name + ' · ' : '') + 'Administration';
    nav.appendChild(foot);

    // Menus du rail en accordéon.
    var mains = $$('.navbar-collapse > ul:not(.right-nav) > li.has-dropdown', nav);
    mains.forEach(function (li) {
        var a = li.querySelector(':scope > a');
        if (!a || li.classList.contains('bt')) { return; }
        a.addEventListener('click', function (e) {
            e.preventDefault(); e.stopImmediatePropagation();
            var open = li.classList.contains('lwba-open');
            mains.forEach(function (o) { o.classList.remove('lwba-open'); });
            if (!open) { li.classList.add('lwba-open'); }
        }, true);
    });
    var addLi = add ? add.parentNode : null;
    if (addLi) {
        add.addEventListener('click', function (e) { e.preventDefault(); e.stopImmediatePropagation(); addLi.classList.toggle('lwba-open'); }, true);
    }
    $$('.navbar-collapse a', nav).forEach(clean);
    $$('#sidebar ul.menu a').forEach(clean);

    // Page courante : rail et menu contextuel.
    var here = location.pathname.split('/').pop() + location.search;
    var herePath = location.pathname.split('/').pop();
    function matches(a) {
        var h = a.getAttribute('href') || '';
        if (!h || h === '#') { return 0; }
        var u = h.split('/').pop();
        if (u === here) { return 2; }
        return u === herePath ? 1 : 0;
    }
    function best(links) {
        var found = null, score = 0;
        links.forEach(function (a) { var m = matches(a); if (m > score) { score = m; found = a; } });
        return found;
    }
    var hit = best($$('.navbar-collapse > ul:not(.right-nav) li ul a', nav));
    if (hit) {
        hit.classList.add('lwba-here');
        var topLi = hit.closest('.navbar-collapse > ul > li');
        if (topLi) { topLi.classList.add('lwba-current', 'lwba-open'); }
    }
    var side = best($$('#sidebar ul.menu a'));
    if (side) { side.classList.add('lwba-here'); }

    // Icônes de droite : ouverture au clic.
    if (right) {
        $$(':scope > li.has-dropdown > a', right).forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault(); e.stopImmediatePropagation();
                var li = a.parentNode, open = li.classList.contains('lwba-open');
                $$(':scope > li', right).forEach(function (o) { o.classList.remove('lwba-open'); });
                if (!open) { li.classList.add('lwba-open'); }
            }, true);
        });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.lwba-topbar ul.right-nav')) { $$(':scope > li', right).forEach(function (o) { o.classList.remove('lwba-open'); }); }
        });
    }

    // Avertissement global vide, colonne contextuelle absente ou inutile.
    var warn = $('.global-admin-warning');
    if (warn && !warn.textContent.trim()) { warn.classList.add('lwba-empty'); }
    var sb = $('#sidebar');
    if (!sb || !sb.textContent.trim() || /module=lwb_dashboard/.test(location.search)) { html.classList.add('lwba-nosidebar'); }
    // Page du tableau de bord : le titre WHMCS du module fait doublon.
    if (document.getElementById('lwbd')) {
        $$('#contentarea h1').forEach(function (t) { if (!t.closest('#lwbd')) { t.style.display = 'none'; } });
    }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { html.classList.remove('lwba-nav-open'); } });
})();
