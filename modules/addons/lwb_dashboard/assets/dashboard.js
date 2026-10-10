(function () {
    var root = document.getElementById('lwbd');
    if (!root) { return; }
    var tip = root.querySelector('.lwbd-tip');
    // Sur petit écran, le graphique défile : on montre d'abord les mois les plus récents.
    Array.prototype.forEach.call(root.querySelectorAll('.lwbd-chart-wrap'), function (w) { w.scrollLeft = w.scrollWidth; });
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
