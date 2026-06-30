function toggle(i) {
    var body  = document.getElementById('body-' + i);
    var arrow = document.getElementById('arrow-' + i);
    if (body.style.display === 'block') {
        body.style.display = 'none';
        arrow.innerHTML = '&#9660;';
    } else {
        body.style.display = 'block';
        arrow.innerHTML = '&#9650;';
    }
}
if (document.getElementById('body-0')) toggle(0);
