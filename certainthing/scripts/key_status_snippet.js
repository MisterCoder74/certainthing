/**
 * key_status_snippet.js
 *
 * Aggiunge key preview + avviso trial-shared alla funzione che popola
 * il modal della API key. Integra nel tuo app.js / modal handler esistente.
 *
 * STRUTTURA HTML DA AGGIUNGERE DENTRO IL MODAL (una volta sola):
 *
 *   <div id="key-status-info" style="display:none;" class="key-status-box"></div>
 *   <div id="key-shared-warning" style="display:none;" class="key-warning-box">
 *     ⚠️ Stai usando la <strong>chiave condivisa</strong>.
 *     Questa chiave è disponibile solo durante il periodo di prova.
 *     Al termine del trial dovrai inserire la tua chiave personale.
 *   </div>
 */


// ── SOSTITUISCI / INTEGRA nella tua funzione che carica lo stato del modal ──

async function loadApiKeyStatus() {
    try {
        const res  = await fetch('api/save_api_key.php');
        const data = await res.json();

        const statusBox  = document.getElementById('key-status-info');
        const warningBox = document.getElementById('key-shared-warning');

        // --- Key status info ---
        if (statusBox) {
            if (data.configured && data.key_preview) {
                const sourceLabels = {
                    user:   '🔑 Chiave personale',
                    shared: '🔑 Chiave condivisa (trial)',
                    env:    '🔑 Chiave server',
                };
                const label = sourceLabels[data.source] || '🔑 Chiave configurata';
                statusBox.innerHTML = `${label} &nbsp;·&nbsp; <code>${data.key_preview}</code>`;
                statusBox.style.display = 'block';
            } else {
                statusBox.style.display = 'none';
            }
        }

        // --- Shared-key trial warning ---
        if (warningBox) {
            warningBox.style.display = data.show_shared_warning ? 'block' : 'none';
        }

        // --- Existing masked key field (compatibilità con codice esistente) ---
        const maskedField = document.getElementById('current-api-key');
        if (maskedField) {
            maskedField.value = data.masked_key || '';
        }

        return data;

    } catch (err) {
        console.error('loadApiKeyStatus error:', err);
    }
}


// ── CSS CONSIGLIATO (aggiungi nel tuo style.css) ─────────────────────────────
/*
.key-status-box {
    font-size: 0.82rem;
    color: #888;
    margin: 6px 0 2px;
}
.key-status-box code {
    font-family: monospace;
    background: rgba(255,255,255,0.06);
    padding: 1px 5px;
    border-radius: 3px;
}
.key-warning-box {
    margin-top: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    background: rgba(255, 180, 0, 0.12);
    border: 1px solid rgba(255, 180, 0, 0.35);
    color: #f5c842;
    font-size: 0.85rem;
    line-height: 1.5;
}
*/
