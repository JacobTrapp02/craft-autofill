(() => {
    // Server communication for prompt previews and response normalization.
    window.AutofillInputRuntime = window.AutofillInputRuntime || {};
    const runtime = window.AutofillInputRuntime;

    const jsonHeaders = () => {
        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        };

        if (window.Craft?.csrfTokenValue) {
            headers['X-CSRF-Token'] = window.Craft.csrfTokenValue;
        }

        return headers;
    };

    const postJson = async (endpoint, payload, fallbackErrorMessage) => {
        const response = await window.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: jsonHeaders(),
            body: JSON.stringify(payload),
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.error || fallbackErrorMessage);
        }

        return data;
    };

    runtime.buildPromptPreview = async ({ endpoint, fieldId, userPrompt }) => {
        const data = await postJson(
            endpoint,
            { userPrompt, fieldId },
            'Could not build prompt preview.',
        );

        return String(data.prompt || '');
    };

    runtime.normalizeResponse = async ({ endpoint, fieldId, rawResponse }) => {
        const data = await postJson(
            endpoint,
            { rawResponse, fieldId },
            'Could not normalize response.',
        );

        return Array.isArray(data.suggestions) ? data.suggestions : [];
    };
})();
