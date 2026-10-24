import { Controller } from '@hotwired/stimulus';

/*
 * Aggiorna la pagina del job finché la ricerca non termina (sostituisce il META refresh a
 * 10 secondi di blastresult.php). A fine ricerca ricarica la pagina, che mostrerà i risultati.
 */
export default class extends Controller {
    static targets = ['status', 'counts'];

    static values = { url: String, finished: Boolean, interval: { type: Number, default: 2000 } };

    connect() {
        if (!this.finishedValue) {
            this.schedule();
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    schedule() {
        this.timer = setTimeout(() => this.poll(), this.intervalValue);
    }

    async poll() {
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            this.render(await response.json());
        } catch (error) {
            // Rete o server momentaneamente non disponibili: si riprova al giro successivo.
            this.schedule();
        }
    }

    render(job) {
        this.statusTarget.textContent = job.status;
        this.countsTarget.textContent = `${job.numQueries ?? '–'} / ${job.numHits}`;

        if (job.finished) {
            // La pagina renderizzata dal server mostra esito, errore e (in seguito) i risultati.
            window.location.reload();

            return;
        }

        this.schedule();
    }
}
