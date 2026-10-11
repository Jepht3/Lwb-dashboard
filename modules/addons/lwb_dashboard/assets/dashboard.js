(function () {
    var root = document.getElementById('lwbd');
    if (!root) { return; }
    var tip = root.querySelector('.lwbd-tip');
    // Sur petit écran, le graphique défile : on montre d'abord les mois les plus récents.
    Array.prototype.forEach.call(root.querySelectorAll('.lwbd-chart-wrap'), function (w) { w.scrollLeft = w.scrollWidth; });
    // Afficher les lignes masquées d'un tableau.
    root.addEventListener('click', function (e) {
        var more = e.target.closest('.lwbd-showmore');
        if (more) {
            Array.prototype.forEach.call(document.querySelectorAll('#' + more.getAttribute('data-target') + ' .lwbd-extra'), function (tr) { tr.hidden = false; });
            more.remove();
            return;
        }
        var btn = e.target.closest('[data-export]');
        if (!btn) { return; }
        var src = document.getElementById(btn.getAttribute('data-export'));
        if (!src) { return; }
        var rows = src.tagName === 'TABLE' ? src.querySelectorAll('tr') : src.querySelectorAll('li');
        var lines = [];
        Array.prototype.forEach.call(rows, function (row) {
            var cells = row.tagName === 'TR' ? row.querySelectorAll('th, td') : row.children;
            var vals = [];
            Array.prototype.forEach.call(cells, function (c) {
                // On retire les boutons et les icônes, puis on garde chaque information (lien, détail, statut).
                var clone = c.cloneNode(true);
                Array.prototype.forEach.call(clone.querySelectorAll('form, button, [aria-hidden="true"]'), function (n) { n.remove(); });
                var parts = [];
                Array.prototype.forEach.call(clone.childNodes, function (n) {
                    var t = (n.textContent || '').replace(/\s+/g, ' ').trim();
                    if (t) { parts.push(t); }
                });
                vals.push('"' + parts.join(' - ').replace(/"/g, '""') + '"');
            });
            if (vals.length) { lines.push(vals.join(';')); }
        });
        var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'lewebmax-' + (src.getAttribute('data-name') || 'export') + '-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    });
    function show(el, x, y) {
        var parts = (el.getAttribute('data-tip') || '').split('|');
        tip.innerHTML = '';
        parts.forEach(function (txt, i) {
            var node = document.createElement(i === 0 ? 'span' : (i === 1 ? 'b' : 'span'));
            node.textContent = txt;
            tip.appendChild(node);
        });
        tip.hidden = false;
        var w = tip.offsetWidth, h = tip.offsetHeight;
        var left = Math.min(window.innerWidth - w - 8, Math.max(8, x + 14));
        var top = y - h - 12 < 8 ? y + 18 : y - h - 12;
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
    }
    function hide() { tip.hidden = true; }
    root.addEventListener('mousemove', function (e) {
        var el = e.target.closest('[data-tip]');
        if (el && root.contains(el)) { show(el, e.clientX, e.clientY); } else { hide(); }
    });
    root.addEventListener('mouseleave', hide);
    root.addEventListener('focusin', function (e) {
        var el = e.target.closest('[data-tip]');
        if (!el) { return; }
        var r = el.getBoundingClientRect();
        show(el, r.left + r.width / 2, r.top + 20);
    });
    root.addEventListener('focusout', hide);
})();
