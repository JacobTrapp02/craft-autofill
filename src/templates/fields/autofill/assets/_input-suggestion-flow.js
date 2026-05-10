(() => {
    // Decides which normalized suggestions can be auto-applied and which need review.
    window.AutofillInputRuntime = window.AutofillInputRuntime || {};
    const runtime = window.AutofillInputRuntime;

    runtime.collectReviewRequiredSuggestions = (suggestions) => {
        const reviewRequired = [];

        suggestions.forEach((item) => {
            if (item.matchedHandle && item.overrideCurrentValue === false && runtime.hasMeaningfulExistingValue(item.matchedHandle)) {
                return;
            }

            const normalizedValue = String(item.value ?? '').trim();
            const missingNullOrEmptyValue = !item.hasRawValue || item.valueIsNull || normalizedValue === '';

            if (item.matchedHandle && item.requiresApproval === false && missingNullOrEmptyValue && runtime.hasMeaningfulExistingValue(item.matchedHandle)) {
                return;
            }

            if (Array.isArray(item.validationErrors) && item.validationErrors.length) {
                reviewRequired.push(item);
                return;
            }

            if (item.matchedHandle && item.requiresApproval === false) {
                runtime.applySuggestionValue(item.matchedHandle, item.value);
                return;
            }

            reviewRequired.push(item);
        });

        return reviewRequired;
    };
})();
