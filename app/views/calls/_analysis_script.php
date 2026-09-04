<script>
(function () {
    const forms = Array.from(document.querySelectorAll('.js-call-analysis-form'));

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            analyze(form, true).catch(function () {});
        });
    });

    document.querySelectorAll('.js-analyze-page').forEach(function (batchButton) {
        batchButton.addEventListener('click', async function () {
            if (!forms.length) {
                batchButton.textContent = 'Nenhuma análise pendente nesta página';
                return;
            }
            const original = batchButton.innerHTML;
            batchButton.disabled = true;
            let completed = 0;
            try {
                for (const form of forms) {
                    batchButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Analisando ' + (completed + 1) + ' de ' + forms.length;
                    await analyze(form, false);
                    completed++;
                }
                batchButton.innerHTML = '<i class="fa-solid fa-check me-1"></i>' + completed + ' análise(s) concluída(s)';
                window.setTimeout(function () { window.location.reload(); }, 900);
            } catch (_) {
                batchButton.disabled = false;
                batchButton.innerHTML = original;
            }
        });
    });

    async function analyze(form, navigate) {
        const externalId = form.getAttribute('data-external-id') || '';
        const button = form.querySelector('button[type="submit"], button:not([type])');
        const original = button ? button.innerHTML : '';
        form.parentElement?.querySelectorAll('.js-analysis-message').forEach(function (item) { item.remove(); });
        if (!externalId) {
            const error = new Error('Esta ligação precisa ser sincronizada novamente para recuperar seu identificador.');
            showError(form, error.message);
            throw error;
        }
        if (button) {
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Analisando áudio, aguarde...';
        }
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
            });
            const payload = await readJson(response);
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Não foi possível analisar esta gravação.');
            }
            if (navigate) window.location.href = payload.redirect;
            return true;
        } catch (error) {
            showError(form, error.message || 'A conexão com a análise falhou. A gravação continua disponível.');
            if (button) {
                button.disabled = false;
                button.innerHTML = original;
            }
            throw error;
        }
    }

    async function readJson(response) {
        const text = (await response.text()).trim();
        try { return JSON.parse(text); }
        catch (_) {
            const status = response.status ? ' (HTTP ' + response.status + ')' : '';
            throw new Error('A hospedagem interrompeu a resposta da análise' + status + '. Confira o log PHP.');
        }
    }

    function showError(form, text) {
        const message = document.createElement('div');
        message.className = 'alert alert-danger mt-3 js-analysis-message';
        message.textContent = text;
        (form.closest('.card, td') || form.parentElement || form).appendChild(message);
    }
}());
</script>
