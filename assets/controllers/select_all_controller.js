import { Controller } from '@hotwired/stimulus';

/*
 * Casella "seleziona tutti" della tabella risultati (ex "Download All" di blastresult.php,
 * che scaricava le righe mostrate): seleziona gli hit della pagina corrente.
 */
export default class extends Controller {
    static targets = ['all', 'item'];

    toggleAll() {
        this.itemTargets.forEach((item) => { item.checked = this.allTarget.checked; });
    }

    refresh() {
        const checked = this.itemTargets.filter((item) => item.checked).length;
        this.allTarget.checked = checked > 0 && checked === this.itemTargets.length;
        this.allTarget.indeterminate = checked > 0 && checked < this.itemTargets.length;
    }
}
