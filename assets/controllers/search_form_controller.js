import { Controller } from '@hotwired/stimulus';

/*
 * Porting di changeDBList / changeParameters / changeGapcostsOptions di viroblast.js.
 * Regole, default e preset arrivano da PHP (SearchFormClientConfig): qui non si duplicano.
 * I campi non applicabili al programma vengono disabilitati, così non vengono inviati;
 * il server comunque li ignora (SearchFormData::toSearchParameters).
 */
export default class extends Controller {
    static targets = ['program', 'databases', 'field', 'wordSize', 'nucleotideScoring', 'matrix', 'gapCosts', 'lowComplexityFilter', 'softMasking'];

    static values = { programs: Object, gapCosts: Object };

    connect() {
        // Al primo caricamento (o dopo un errore di validazione) si rispettano i valori presenti.
        this.applyProgram(false);
    }

    programChanged() {
        this.applyProgram(true);
    }

    scoringChanged() {
        this.refreshGapCosts(true);
    }

    applyProgram(resetDefaults) {
        const program = this.programTarget.value;
        const config = this.programsValue[program];

        if (!config) {
            return;
        }

        this.filterDatabases(program);
        this.fieldTargets.forEach((field) => this.toggleField(field, this.isApplicable(field.dataset.field, config)));
        this.filterWordSizes(config.wordSizes, resetDefaults ? config.defaults.wordSize : null);

        if (resetDefaults) {
            this.setValue(this.nucleotideScoringTarget, config.defaults.nucleotideScoring);
            this.setValue(this.matrixTarget, config.defaults.matrix);
            this.lowComplexityFilterTarget.checked = config.defaults.lowComplexityFilter;
            this.softMaskingTarget.checked = config.defaults.softMasking;
        }

        this.refreshGapCosts(resetDefaults);
    }

    isApplicable(field, config) {
        const conditional = ['nucleotideScoring', 'matrix', 'gapCosts', 'ungapped', 'queryGeneticCode', 'databaseGeneticCode'];

        return !conditional.includes(field) || config.fields.includes(field);
    }

    toggleField(field, enabled) {
        field.hidden = !enabled;
        field.querySelectorAll('input, select, textarea').forEach((input) => { input.disabled = !enabled; });
    }

    filterDatabases(program) {
        Array.from(this.databasesTarget.options).forEach((option) => {
            const supported = (option.dataset.programs || '').split(' ').includes(program);
            option.hidden = !supported;
            option.disabled = !supported;

            if (!supported) {
                option.selected = false;
            }
        });
    }

    filterWordSizes(allowed, defaultSize) {
        const select = this.wordSizeTarget;

        Array.from(select.options).forEach((option) => {
            const isAllowed = allowed.includes(Number(option.value));
            option.hidden = !isAllowed;
            option.disabled = !isAllowed;
        });

        if (defaultSize !== null || !allowed.includes(Number(select.value))) {
            select.value = String(defaultSize ?? allowed[0]);
        }
    }

    refreshGapCosts(resetToDefault) {
        const select = this.gapCostsTarget;
        const scoring = this.nucleotideScoringTarget.disabled ? this.matrixTarget.value : this.nucleotideScoringTarget.value;
        const presets = this.gapCostsValue[scoring] || [];
        const current = select.value;

        select.replaceChildren(...presets.map((value) => {
            const [existence, extension] = value.split(',');

            return new Option(`Existence: ${existence}, Extension: ${extension}`, value);
        }));

        // Il primo preset è il default di BLAST+ per lo schema di punteggio scelto.
        select.value = !resetToDefault && presets.includes(current) ? current : (presets[0] || '');
    }

    setValue(select, value) {
        if (value !== null && value !== undefined) {
            select.value = value;
        }
    }
}
