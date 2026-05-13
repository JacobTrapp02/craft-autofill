(() => {
    // Manages the Twig-rendered suggestion review modal and its navigation controls.
    window.AutofillInputRuntime = window.AutofillInputRuntime || {};
    const runtime = window.AutofillInputRuntime;

    runtime.createReviewModal = ({ applySuggestionValue, focusMatchedField }) => {
        let suggestions = [];
        let currentIndex = 0;

        const modalState = {
            backdrop: document.querySelector('[data-autofill-review-backdrop]'),
            modal: document.querySelector('[data-autofill-review-modal]'),
            status: document.querySelector('[data-autofill-review-status]'),
            field: document.querySelector('[data-autofill-review-field]'),
            value: document.querySelector('[data-autofill-review-value]'),
        };

        const hoistModalToBody = () => {
            if (modalState.backdrop instanceof HTMLElement && modalState.backdrop.parentElement !== document.body) {
                document.body.appendChild(modalState.backdrop);
            }
            if (modalState.modal instanceof HTMLElement && modalState.modal.parentElement !== document.body) {
                document.body.appendChild(modalState.modal);
            }
        };

        hoistModalToBody();

        const teardown = () => {
            if (modalState.backdrop instanceof HTMLElement) {
                modalState.backdrop.hidden = true;
            }
            if (modalState.modal instanceof HTMLElement) {
                modalState.modal.hidden = true;
            }
            if (modalState.status instanceof HTMLElement) {
                modalState.status.textContent = '';
            }
            if (modalState.field instanceof HTMLInputElement) {
                modalState.field.value = '';
            }
            if (modalState.value instanceof HTMLTextAreaElement) {
                modalState.value.value = '';
            }
        };

        const show = () => {
            if (modalState.backdrop instanceof HTMLElement) {
                modalState.backdrop.hidden = false;
            }
            if (modalState.modal instanceof HTMLElement) {
                modalState.modal.hidden = false;
            }
        };

        const positionBelowField = (input) => {
            if (!(modalState.modal instanceof HTMLElement)) {
                return;
            }

            const anchor = runtime.getInteractionAnchor(input);
            const gap = 12;
            const viewportPadding = 12;
            const rect = anchor.getBoundingClientRect();
            const modalHeight = modalState.modal.offsetHeight || 320;
            const idealTop = rect.bottom + gap;
            const maxTop = window.innerHeight - modalHeight - viewportPadding;
            const computedTop = Math.max(viewportPadding, Math.min(idealTop, maxTop));
            modalState.modal.style.top = `${computedTop}px`;
        };

        const renderCurrent = () => {
            if (!suggestions.length) {
                teardown();
                return;
            }

            if (
                !(modalState.modal instanceof HTMLElement) ||
                !(modalState.status instanceof HTMLElement) ||
                !(modalState.field instanceof HTMLInputElement) ||
                !(modalState.value instanceof HTMLTextAreaElement)
            ) {
                return;
            }

            show();
            const current = suggestions[currentIndex];
            const validationErrors = Array.isArray(current.validationErrors) ? current.validationErrors : [];
            modalState.status.textContent = validationErrors.length
                ? `Suggestion ${currentIndex + 1} of ${suggestions.length} - ${validationErrors.join(' ')}`
                : `Suggestion ${currentIndex + 1} of ${suggestions.length}`;
            modalState.field.value = `${current.fieldName}${current.matchedHandle ? ` -> ${current.matchedHandle}` : ' (no matching field found)'}`;
            modalState.value.value = String(current.value ?? '');
            focusMatchedField(current.matchedHandle);
        };

        const clear = () => {
            suggestions = [];
            currentIndex = 0;
            teardown();
        };

        const setSuggestions = (nextSuggestions) => {
            suggestions = Array.isArray(nextSuggestions) ? nextSuggestions : [];
            currentIndex = 0;
            if (suggestions.length) {
                renderCurrent();
                return;
            }
            teardown();
        };

        const handleDocumentClick = (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement) || !(modalState.modal instanceof HTMLElement)) {
                return;
            }

            if (target === modalState.backdrop || target.matches('[data-autofill-close]')) {
                teardown();
                return;
            }

            if (target.matches('[data-autofill-accept]')) {
                if (!suggestions.length || !(modalState.value instanceof HTMLTextAreaElement)) {
                    return;
                }
                const current = suggestions[currentIndex];
                current.value = modalState.value.value;
                if (current.matchedHandle) {
                    const applied = applySuggestionValue(current.matchedHandle, current.value, current);
                    if (!applied) {
                        const existing = Array.isArray(current.validationErrors) ? current.validationErrors : [];
                        current.validationErrors = [...existing, 'Suggestion could not be applied to the matched field.'];
                        renderCurrent();
                        return;
                    }
                }
                if (currentIndex < suggestions.length - 1) {
                    currentIndex += 1;
                    renderCurrent();
                } else {
                    teardown();
                }
                return;
            }

            if (target.matches('[data-autofill-reject]')) {
                if (!suggestions.length) {
                    return;
                }
                if (currentIndex < suggestions.length - 1) {
                    currentIndex += 1;
                    renderCurrent();
                } else {
                    teardown();
                }
                return;
            }

            if (target.matches('[data-autofill-prev]')) {
                if (!suggestions.length) {
                    return;
                }
                currentIndex = Math.max(0, currentIndex - 1);
                renderCurrent();
                return;
            }

            if (target.matches('[data-autofill-next]')) {
                if (!suggestions.length) {
                    return;
                }
                currentIndex = Math.min(suggestions.length - 1, currentIndex + 1);
                renderCurrent();
            }
        };

        return {
            clear,
            handleDocumentClick,
            positionBelowField,
            setSuggestions,
        };
    };
})();
