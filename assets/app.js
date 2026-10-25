import './stimulus_bootstrap.js';

// I fogli di stile sono collegati con <link> in base.html.twig, non importati da qui: AssetMapper
// trasformerebbe gli import CSS in moduli "data:", vietati dalla Content-Security-Policy.

// Turbo ricrea gli script del <body> a ogni visita. In Firefox la copia degli attributi sovrascrive il
// nonce con quello (nascosto, quindi vuoto) dell'originale e la CSP blocca lo script. Quello vuoto che la
// web debug toolbar mette in sviluppo non fa nulla: Turbo lo lascia stare.
document.addEventListener('turbo:before-render', (event) => {
    event.detail.newBody.querySelectorAll('script:not([src])').forEach((script) => {
        if (script.textContent.trim() === '') {
            script.setAttribute('data-turbo-eval', 'false');
        }
    });
});
