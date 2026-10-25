import { Controller } from '@hotwired/stimulus';

/*
 * Menu dell'utente nella navbar: un <details> che si chiude con un clic fuori dal menu o con Esc.
 */
export default class extends Controller {
    close(event) {
        if (!this.element.open) {
            return;
        }

        if (event.type === 'click' && this.element.contains(event.target)) {
            return;
        }

        this.element.open = false;
    }
}
