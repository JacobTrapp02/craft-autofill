(() => {
    // Manages the Twig-rendered suggestion review modal and its navigation controls.
    window.AutofillInputRuntime = window.AutofillInputRuntime || {};
    const runtime = window.AutofillInputRuntime;

    runtime.createReviewModal = ({ applySuggestion, focusMatchedField, fieldId }) => {
        let suggestions = [];
        let currentIndex = 0;
        let isLoading = false;
        const normalizedFieldId = Number(fieldId || 0);
        const fieldIdAttr = Number.isFinite(normalizedFieldId) && normalizedFieldId > 0
            ? String(normalizedFieldId)
            : '';

        const byField = (selector) => {
            if (fieldIdAttr === '') {
                return document.querySelector(selector);
            }
            return document.querySelector(`${selector}[data-autofill-field-id="${fieldIdAttr}"]`);
        };

        const modalState = {
            backdrop: byField('[data-autofill-review-backdrop]'),
            modal: byField('[data-autofill-review-modal]'),
            status: null,
            loading: null,
            loadingText: null,
            body: null,
            buttons: null,
            errors: null,
            field: null,
            editor: null,
        };

        const setModalDisplayMode = (mode) => {
            if (!(modalState.modal instanceof HTMLElement)) {
                return;
            }

            const normalized = String(mode || '').trim().toLowerCase();
            if (normalized === 'richtext') {
                modalState.modal.classList.add('autofill-review-modal--richtext');
                return;
            }

            modalState.modal.classList.remove('autofill-review-modal--richtext');
        };

        if (modalState.modal instanceof HTMLElement) {
            modalState.status = modalState.modal.querySelector('[data-autofill-review-status]');
            modalState.loading = modalState.modal.querySelector('[data-autofill-review-loading]');
            modalState.loadingText = modalState.modal.querySelector('[data-autofill-review-loading-text]');
            modalState.body = modalState.modal.querySelector('.autofill-review-modal__body');
            modalState.buttons = modalState.modal.querySelector('.autofill-review-modal__buttons');
            modalState.errors = modalState.modal.querySelector('[data-autofill-review-errors]');
            modalState.field = modalState.modal.querySelector('[data-autofill-review-field]');
            modalState.editor = modalState.modal.querySelector('[data-autofill-review-editor]');
        }

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
            setModalDisplayMode('');
            setLoadingState(false);
            if (modalState.status instanceof HTMLElement) {
                modalState.status.textContent = '';
            }
            if (modalState.loadingText instanceof HTMLElement) {
                modalState.loadingText.textContent = 'Waiting for response from AI...';
            }
            if (modalState.errors instanceof HTMLElement) {
                modalState.errors.textContent = '';
                modalState.errors.hidden = true;
            }
            if (modalState.field instanceof HTMLElement) {
                modalState.field.textContent = '';
            }
            if (modalState.editor instanceof HTMLElement) {
                modalState.editor.innerHTML = '';
            }
            isLoading = false;
        };

        const finishReview = ({ reloadPage = false } = {}) => {
            teardown();

            if (reloadPage) {
                window.location.reload();
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

        const setLoadingState = (loading, message = '') => {
            isLoading = Boolean(loading);
            if (modalState.modal instanceof HTMLElement) {
                if (isLoading) {
                    modalState.modal.classList.add('is-loading');
                } else {
                    modalState.modal.classList.remove('is-loading');
                }
            }
            if (modalState.loading instanceof HTMLElement) {
                modalState.loading.hidden = !isLoading;
            }
            if (modalState.loadingText instanceof HTMLElement) {
                modalState.loadingText.textContent = String(message || 'Waiting for response from AI...');
            }
            if (modalState.body instanceof HTMLElement) {
                modalState.body.hidden = isLoading;
            }
            if (modalState.buttons instanceof HTMLElement) {
                modalState.buttons.hidden = isLoading;
            }
        };

        const showLoading = (message = 'Waiting for response from AI...') => {
            show();
            setLoadingState(true, message);
            if (modalState.status instanceof HTMLElement) {
                modalState.status.textContent = '';
            }
            if (modalState.errors instanceof HTMLElement) {
                modalState.errors.textContent = '';
                modalState.errors.hidden = true;
            }
            if (modalState.field instanceof HTMLElement) {
                modalState.field.textContent = '';
            }
            if (modalState.editor instanceof HTMLElement) {
                modalState.editor.innerHTML = '';
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
                !(modalState.field instanceof HTMLElement) ||
                !(modalState.editor instanceof HTMLElement)
            ) {
                return;
            }

            setLoadingState(false);
            show();
            const current = suggestions[currentIndex];
            const validationErrors = Array.isArray(current.validationErrors) ? current.validationErrors : [];
            const displayMode = current?.reviewEditor?.displayMode || '';
            setModalDisplayMode(displayMode);
            modalState.status.textContent = `${currentIndex + 1} of ${suggestions.length}`;
            modalState.field.textContent = current.fieldName || '(Unmatched field)';
            if (modalState.errors instanceof HTMLElement) {
                if (validationErrors.length) {
                    modalState.errors.textContent = validationErrors.join(' ');
                    modalState.errors.hidden = false;
                } else {
                    modalState.errors.textContent = '';
                    modalState.errors.hidden = true;
                }
            }
            runtime.renderReviewEditor({
                suggestion: current,
                container: modalState.editor,
            });
            focusMatchedField(current.matchedHandle);
        };

        const clear = () => {
            suggestions = [];
            currentIndex = 0;
            teardown();
        };

        const setSuggestions = (nextSuggestions) => {
            setLoadingState(false);
            suggestions = Array.isArray(nextSuggestions) ? nextSuggestions : [];
            currentIndex = 0;
            if (suggestions.length) {
                renderCurrent();
                return;
            }
            teardown();
        };

        const handleDocumentClick = async (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement) || !(modalState.modal instanceof HTMLElement)) {
                return;
            }

            if (isLoading) {
                return;
            }

            if (target === modalState.backdrop || target.matches('[data-autofill-close]')) {
                teardown();
                return;
            }

            if (target.matches('[data-autofill-accept]')) {
                if (!suggestions.length || !(modalState.editor instanceof HTMLElement)) {
                    return;
                }
                const current = suggestions[currentIndex];
                current.value = runtime.readReviewEditorValue({
                    suggestion: current,
                    container: modalState.editor,
                });
                if (current.matchedHandle) {
                    try {
                        await applySuggestion({
                            ...current,
                            value: current.value,
                        });
                    } catch (error) {
                        const existing = Array.isArray(current.validationErrors) ? current.validationErrors : [];
                        const message = error instanceof Error ? error.message : 'Suggestion could not be applied to the matched field.';
                        current.validationErrors = [...existing, message];
                        renderCurrent();
                        return;
                    }
                }
                if (currentIndex < suggestions.length - 1) {
                    currentIndex += 1;
                    renderCurrent();
                } else {
                    finishReview({ reloadPage: true });
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
                    finishReview({ reloadPage: true });
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
            showLoading,
        };
    };
})();
