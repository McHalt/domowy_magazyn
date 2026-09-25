(function () {
    var toggle   = document.getElementById('groups-toggle');
    if (!toggle) return;
    var panel    = document.getElementById('groups-panel');
    var search   = document.getElementById('groups-search');
    var countEl  = document.getElementById('groups-count');
    var arrow    = document.getElementById('groups-arrow');
    var items    = document.querySelectorAll('.groups-item');
    var checks   = document.querySelectorAll('.group-checkbox');

    function updateCount() {
        var n = document.querySelectorAll('.group-checkbox:checked').length;
        countEl.textContent = n === 0
            ? 'żadna'
            : n + ' zaznaczon' + (n === 1 ? 'a' : 'ych');
        countEl.className = 'badge ms-1 ' + (n > 0 ? 'bg-primary' : 'bg-secondary');
    }

    function togglePanel() {
        var open = panel.style.display !== 'none';
        panel.style.display = open ? 'none' : 'block';
        arrow.textContent   = open ? '▾' : '▴';
        if (!open) search.focus();
    }

    toggle.addEventListener('click', togglePanel);

    search.addEventListener('input', function () {
        var q = this.value.toLowerCase();
        items.forEach(function (item) {
            var label = item.querySelector('label').textContent.toLowerCase();
            item.style.display = label.includes(q) ? '' : 'none';
        });
    });

    checks.forEach(function (cb) {
        cb.addEventListener('change', updateCount);
    });

    updateCount();
})();
