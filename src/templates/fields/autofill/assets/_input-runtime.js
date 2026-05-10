(() => {
    // Bootstraps the input runtime and wires UI events to the helper modules.
    const runtime = window.AutofillInputRuntime || {};
    const root = document.querySelector('[data-autofill-runtime]');
    if (!root) {
        return;
    }

    const generateButton = root.querySelector('[data-autofill-generate]');
    const userPromptInput = root.querySelector('[data-autofill-user-prompt]');
    const previewOutput = root.querySelector('[data-autofill-preview]');
    const responseInput = root.querySelector('[data-autofill-response-input]');
    const parseResponseButton = root.querySelector('[data-autofill-parse-response]');
    if (!(generateButton instanceof HTMLButtonElement) || !(userPromptInput instanceof HTMLTextAreaElement) || !(previewOutput instanceof HTMLTextAreaElement)) {
        return;
    }

    const config = window.__autofillPreviewConfig || {};
    const reviewModal = runtime.createReviewModal({
        applySuggestionValue: runtime.applySuggestionValue,
        focusMatchedField: runtime.focusMatchedField,
    });
    runtime.positionActiveReviewModal = reviewModal.positionBelowField;

    generateButton.addEventListener('click', async () => {
        const promptPreviewActionUrl = config.promptPreviewActionUrl || '';
        const fieldId = Number(config.fieldId || 0);
        if (!promptPreviewActionUrl || !fieldId) {
            window.alert('Prompt preview endpoint is not configured.');
            return;
        }

        generateButton.disabled = true;
        previewOutput.value = 'Building prompt preview...';

        try {
            previewOutput.value = await runtime.buildPromptPreview({
                endpoint: promptPreviewActionUrl,
                fieldId,
                userPrompt: userPromptInput.value,
            });
        } catch (error) {
            previewOutput.value = '';
            window.alert(error instanceof Error ? error.message : 'Could not build prompt preview.');
        } finally {
            generateButton.disabled = false;
        }
    });

    if (
        parseResponseButton instanceof HTMLButtonElement &&
        responseInput instanceof HTMLTextAreaElement
    ) {
        parseResponseButton.addEventListener('click', async () => {
            const responseNormalizeActionUrl = config.responseNormalizeActionUrl || '';
            const fieldId = Number(config.fieldId || 0);
            if (!responseNormalizeActionUrl || !fieldId) {
                window.alert('Response normalization endpoint is not configured.');
                return;
            }

            parseResponseButton.disabled = true;
            try {
                const suggestions = await runtime.normalizeResponse({
                    endpoint: responseNormalizeActionUrl,
                    fieldId,
                    rawResponse: responseInput.value,
                });
                reviewModal.setSuggestions(runtime.collectReviewRequiredSuggestions(suggestions));
            } catch (error) {
                reviewModal.clear();
                window.alert(error instanceof Error ? error.message : 'Could not normalize response.');
            } finally {
                parseResponseButton.disabled = false;
            }
        });
    }

    document.addEventListener('click', reviewModal.handleDocumentClick);
})();
