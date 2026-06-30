function filtrar() {
    var q = document.getElementById('buscar').value.toLowerCase();
    document.querySelectorAll('[data-grupo]').forEach(function(g) {
        var filas = g.querySelectorAll('tbody tr');
        var visible = 0;
        filas.forEach(function(tr) {
            var mostrar = tr.textContent.toLowerCase().includes(q);
            tr.style.display = mostrar ? '' : 'none';
            if (mostrar) visible++;
        });
        g.style.display = visible > 0 ? '' : 'none';
    });
}
