import { Controller } from '@hotwired/stimulus';

/*
 * Reazioni al widget ALTCHA. Il widget sta fuori dai form (altrimenti ne aggiungerebbe uno
 * suo campo): a verifica riuscita questo controller copia la soluzione nel campo del form,
 * sblocca i pulsanti e, a seconda dei valori, invia il form o cambia pagina.
 */
export default class extends Controller {
    static targets = ['field', 'form', 'submit'];

    static values = { redirect: String, submit: Boolean };

    verified(event) {
        this.fieldTargets.forEach((field) => {
            field.value = event.detail.payload;
        });
        this.submitTargets.forEach((button) => {
            button.disabled = false;
        });

        if (this.submitValue && this.hasFormTarget) {
            this.formTarget.requestSubmit();
        } else if (this.redirectValue !== '') {
            window.location.assign(this.redirectValue);
        }
    }
}
