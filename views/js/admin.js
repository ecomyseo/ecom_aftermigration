/**
 * Ecom Aftermigration - Plegado del panel
 *
 * Por delegacion sobre document: los bloques se pintan con el resto de la pagina
 * y engancharse directamente al elemento daria null en la mitad de los casos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
document.addEventListener('click', function (evento) {
    var disparador = evento.target;

    while (disparador && disparador !== document) {
        if (disparador.classList && disparador.classList.contains('ecomam-toggle')) {
            break;
        }
        disparador = disparador.parentNode;
    }

    if (!disparador || disparador === document || !disparador.classList) {
        return;
    }

    evento.preventDefault();

    var id = disparador.getAttribute('data-ecomam-destino');
    if (!id) {
        return;
    }

    var destino = document.getElementById(id);
    if (!destino) {
        return;
    }

    destino.classList.toggle('ecomam-cerrado');
});
