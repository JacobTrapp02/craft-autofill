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
    if (!(generateButton instanceof HTMLButtonElement)) {
        return;
    }

    const config = window.__autofillPreviewConfig || {};
    const isTestMode = config.testMode !== false;
    const applySuggestionServer = async (suggestion) => {
        const applySuggestionActionUrl = config.applySuggestionActionUrl || '';
        const fieldId = Number(config.fieldId || 0);
        const entryId = Number(config.entryId || 0);
        const siteId = Number(config.siteId || 0);

        if (!applySuggestionActionUrl || !fieldId || !entryId) {
            throw new Error('Server-side suggestion apply is not configured for this entry. Save the entry first, then retry.');
        }

        await runtime.applySuggestionViaServer({
            endpoint: applySuggestionActionUrl,
            fieldId,
            entryId,
            siteId: siteId || null,
            suggestion,
        });

        return true;
    };
    const reviewModal = runtime.createReviewModal({
        applySuggestion: applySuggestionServer,
        focusMatchedField: runtime.focusMatchedField,
        fieldId: Number(config.fieldId || 0),
    });
    runtime.positionActiveReviewModal = reviewModal.positionBelowField;
    const currentUserPrompt = () => (
        userPromptInput instanceof HTMLTextAreaElement ? userPromptInput.value : ''
    );

    const startReviewFlow = async (suggestions) => {
        const reviewSuggestions = await runtime.collectReviewRequiredSuggestions(suggestions, applySuggestionServer);
        reviewModal.setSuggestions(reviewSuggestions);
    };

    generateButton.addEventListener('click', async () => {
        const fieldId = Number(config.fieldId || 0);
        if (!fieldId) {
            window.alert('Autofill field is not configured.');
            return;
        }

        generateButton.disabled = true;
        try {
            if (isTestMode) {
                const promptPreviewActionUrl = config.promptPreviewActionUrl || '';
                if (!(previewOutput instanceof HTMLTextAreaElement) || !promptPreviewActionUrl) {
                    window.alert('Prompt preview endpoint is not configured.');
                    return;
                }

                previewOutput.value = 'Building prompt preview...';
                previewOutput.value = await runtime.buildPromptPreview({
                    endpoint: promptPreviewActionUrl,
                    fieldId,
                    userPrompt: currentUserPrompt(),
                    entryId: Number(config.entryId || 0),
                    siteId: Number(config.siteId || 0) || null,
                });
                return;
            }

            const generateSuggestionsActionUrl = config.generateSuggestionsActionUrl || '';
            if (!generateSuggestionsActionUrl) {
                window.alert('AI generate endpoint is not configured.');
                return;
            }

            reviewModal.showLoading('Waiting for response from AI...');
            reviewModal.positionBelowField(generateButton);
            const suggestions = await runtime.generateSuggestions({
                endpoint: generateSuggestionsActionUrl,
                fieldId,
                userPrompt: currentUserPrompt(),
                entryId: Number(config.entryId || 0),
                siteId: Number(config.siteId || 0) || null,
            });
            await startReviewFlow(suggestions);
        } catch (error) {
            if (previewOutput instanceof HTMLTextAreaElement && isTestMode) {
                previewOutput.value = '';
            }
            reviewModal.clear();
            window.alert(error instanceof Error ? error.message : 'Could not complete Autofill request.');
        } finally {
            generateButton.disabled = false;
        }
    });

    if (
        isTestMode &&
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
                await startReviewFlow(suggestions);
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
