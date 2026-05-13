(() => {
    // Decides which normalized suggestions can be auto-applied and which need review.
    window.AutofillInputRuntime = window.AutofillInputRuntime || {};
    const runtime = window.AutofillInputRuntime;

    runtime.collectReviewRequiredSuggestions = async (suggestions, applySuggestion) => {
        const reviewRequired = [];

        for (const item of suggestions) {
            if (item.matchedHandle && item.overrideCurrentValue === false && runtime.hasMeaningfulExistingValue(item.matchedHandle)) {
                continue;
            }

            const normalizedValue = String(item.value ?? '').trim();
            const missingNullOrEmptyValue = !item.hasRawValue || item.valueIsNull || normalizedValue === '';

            if (item.matchedHandle && item.requiresApproval === false && missingNullOrEmptyValue && runtime.hasMeaningfulExistingValue(item.matchedHandle)) {
                continue;
            }

            if (Array.isArray(item.validationErrors) && item.validationErrors.length) {
                reviewRequired.push(item);
                continue;
            }

            if (item.matchedHandle && item.requiresApproval === false) {
                try {
                    await applySuggestion(item);
                } catch (error) {
                    item.validationErrors = Array.isArray(item.validationErrors) ? item.validationErrors : [];
                    item.validationErrors.push(error instanceof Error ? error.message : 'Suggestion could not be applied to the matched field.');
                    reviewRequired.push(item);
                }
                continue;
            }

            reviewRequired.push(item);
        }

        return reviewRequired;
    };
})();
