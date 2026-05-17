(() => {
    // Finds Craft field inputs and checks whether they already contain values.
    window.AutofillInputRuntime = window.AutofillInputRuntime || {};
    const runtime = window.AutofillInputRuntime;

    const escapeHandle = (handle) => (
        window.CSS && typeof window.CSS.escape === 'function'
            ? window.CSS.escape(handle)
            : handle.replace(/"/g, '\\"')
    );

    const pickBest = (nodes) => {
        const list = Array.from(nodes).filter((node) => node instanceof HTMLElement);
        if (!list.length) {
            return null;
        }
        const visibleEnabled = list.find((node) => {
            const style = window.getComputedStyle(node);
            const hidden = style.display === 'none' || style.visibility === 'hidden';
            const disabled = 'disabled' in node ? Boolean(node.disabled) : false;
            return !hidden && !disabled;
        });
        return visibleEnabled || list[0] || null;
    };

    runtime.findFieldInputByHandle = (handle) => {
        const escaped = escapeHandle(handle);
        const base = `[name="fields[${escaped}]"]`;
        const nestedBase = `[name^="fields[${escaped}]["]`;
        const nativeCandidates = [
            `textarea[name="${escaped}"]`,
            `select[name="${escaped}"]`,
            `input[type="text"][name="${escaped}"]`,
            `input[type="tel"][name="${escaped}"]`,
            `input[type="number"][name="${escaped}"]`,
            `input[type="checkbox"][name="${escaped}"]`,
            `input[name="${escaped}"]`,
            `textarea[name^="${escaped}["]`,
            `select[name^="${escaped}["]`,
            `input[name^="${escaped}["]`,
            `#${escaped}`,
        ];

        const primaryMatch = (
            pickBest(document.querySelectorAll(`textarea${base}`)) ||
            pickBest(document.querySelectorAll(`select${base}`)) ||
            pickBest(document.querySelectorAll(`input[type="text"]${base}`)) ||
            pickBest(document.querySelectorAll(`input[type="tel"]${base}`)) ||
            pickBest(document.querySelectorAll(`input[type="number"]${base}`)) ||
            pickBest(document.querySelectorAll(`input[type="checkbox"]${base}`)) ||
            pickBest(document.querySelectorAll(`input${base}`)) ||
            pickBest(document.querySelectorAll(nativeCandidates.join(', '))) ||
            null
        );

        if (primaryMatch) {
            return primaryMatch;
        }

        // Fallback for fields that use nested names such as fields[handle][value].
        const nestedMatch = (
            pickBest(document.querySelectorAll(`textarea${nestedBase}`)) ||
            pickBest(document.querySelectorAll(`select${nestedBase}`)) ||
            pickBest(document.querySelectorAll(`input[type="text"]${nestedBase}`)) ||
            pickBest(document.querySelectorAll(`input[type="tel"]${nestedBase}`)) ||
            pickBest(document.querySelectorAll(`input[type="number"]${nestedBase}`)) ||
            pickBest(document.querySelectorAll(`input[type="checkbox"]${nestedBase}`)) ||
            pickBest(document.querySelectorAll(`input${nestedBase}`)) ||
            null
        );

        if (nestedMatch) {
            return nestedMatch;
        }

        // Last-resort fallback by Craft field container markup.
        const fieldContainer = document.querySelector(
            `[data-attribute="${escaped}"], #fields-${escaped}-field`
        );
        if (!(fieldContainer instanceof HTMLElement)) {
            return null;
        }

        return pickBest(fieldContainer.querySelectorAll(
            'input[type="text"], input[type="tel"], input[type="number"], textarea, select, input:not([type="hidden"])'
        ));
    };

    runtime.findSplitDateTimeInputsByHandle = (handle) => {
        const escaped = escapeHandle(handle);
        const candidates = [
            {
                date: `input[name="fields[${escaped}][date]"]`,
                time: `input[name="fields[${escaped}][time]"]`,
            },
            {
                date: `input[name="${escaped}[date]"]`,
                time: `input[name="${escaped}[time]"]`,
            },
        ];

        for (const pair of candidates) {
            const dateInput = document.querySelector(pair.date);
            const timeInput = document.querySelector(pair.time);
            if (dateInput instanceof HTMLInputElement || timeInput instanceof HTMLInputElement) {
                return {
                    date: dateInput instanceof HTMLInputElement ? dateInput : null,
                    time: timeInput instanceof HTMLInputElement ? timeInput : null,
                };
            }
        }

        return { date: null, time: null };
    };

    runtime.getInteractionAnchor = (input) => {
        if (!(input instanceof HTMLElement)) {
            return input;
        }
        return (
            input.closest('.field') ||
            input.closest('.input') ||
            input.closest('.select') ||
            input
        );
    };

    runtime.hasMeaningfulExistingValue = (handle) => {
        const splitDateTime = runtime.findSplitDateTimeInputsByHandle(handle);
        if (splitDateTime.date || splitDateTime.time) {
            const dateValue = splitDateTime.date instanceof HTMLInputElement ? String(splitDateTime.date.value || '').trim() : '';
            const timeValue = splitDateTime.time instanceof HTMLInputElement ? String(splitDateTime.time.value || '').trim() : '';
            return dateValue !== '' || timeValue !== '';
        }

        const input = runtime.findFieldInputByHandle(handle);
        if (!(input instanceof HTMLElement)) {
            return false;
        }

        if (input instanceof HTMLInputElement && input.type === 'checkbox') {
            return input.checked;
        }

        if (input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement || input instanceof HTMLSelectElement) {
            return String(input.value || '').trim() !== '';
        }

        return false;
    };
})();
