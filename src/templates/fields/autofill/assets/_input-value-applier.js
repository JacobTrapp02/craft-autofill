(() => {
    // Applies normalized suggestions to Craft form controls.
    window.AutofillInputRuntime = window.AutofillInputRuntime || {};
    const runtime = window.AutofillInputRuntime;

    const parseDateTimeParts = (value) => {
        const raw = String(value ?? '').trim();
        if (!raw) {
            return { date: '', time: '' };
        }

        const isoLike = raw.match(/^(\d{4}-\d{2}-\d{2})(?:[T\s](\d{2}:\d{2})(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/);
        if (isoLike) {
            return {
                date: isoLike[1] || '',
                time: isoLike[2] || '',
            };
        }

        const parsed = new Date(raw);
        if (!Number.isNaN(parsed.getTime())) {
            const pad = (n) => String(n).padStart(2, '0');
            return {
                date: `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())}`,
                time: `${pad(parsed.getHours())}:${pad(parsed.getMinutes())}`,
            };
        }

        return { date: raw, time: '' };
    };

    const resolveSelectOptionValue = (select, candidate, resolveBy = 'both') => {
        const raw = String(candidate ?? '').trim();
        if (raw === '') {
            return '';
        }

        const decodeOptionValue = (optionValue) => {
            const serialized = String(optionValue ?? '');
            if (!serialized.startsWith('base64:')) {
                return serialized;
            }

            const encoded = serialized.slice('base64:'.length);
            try {
                return window.atob(encoded);
            } catch (error) {
                return serialized;
            }
        };

        if (resolveBy === 'value' || resolveBy === 'both') {
            const direct = Array.from(select.options).find((opt) => String(opt.value) === raw);
            if (direct) {
                return direct.value;
            }

            // Craft dropdowns may serialize option values as base64:<encoded>.
            const decodedMatch = Array.from(select.options).find((opt) => decodeOptionValue(opt.value) === raw);
            if (decodedMatch) {
                return decodedMatch.value;
            }
        }

        if (resolveBy === 'label' || resolveBy === 'both') {
            const byLabel = Array.from(select.options).find((opt) => String(opt.textContent || '').trim().toLowerCase() === raw.toLowerCase());
            if (byLabel) {
                return byLabel.value;
            }
        }

        const numeric = Number(raw);
        if (!Number.isNaN(numeric)) {
            const asNumericString = String(numeric);
            const byNumeric = Array.from(select.options).find((opt) => String(opt.value) === asNumericString);
            if (byNumeric) {
                return byNumeric.value;
            }
        }

        return raw;
    };

    const resolveSelectizeOptionValue = (selectize, candidate) => {
        const raw = String(candidate ?? '').trim();
        if (!selectize || typeof selectize !== 'object' || raw === '') {
            return raw;
        }

        const decodeOptionValue = (optionValue) => {
            const serialized = String(optionValue ?? '');
            if (!serialized.startsWith('base64:')) {
                return serialized;
            }
            try {
                return window.atob(serialized.slice('base64:'.length));
            } catch (error) {
                return serialized;
            }
        };

        const options = selectize.options && typeof selectize.options === 'object'
            ? Object.entries(selectize.options)
            : [];

        for (const [key] of options) {
            if (String(key) === raw || decodeOptionValue(key) === raw) {
                return String(key);
            }
        }

        for (const [key, option] of options) {
            const label = option && typeof option === 'object' && option.text != null
                ? String(option.text).trim().toLowerCase()
                : '';
            if (label && label === raw.toLowerCase()) {
                return String(key);
            }
        }

        return raw;
    };

    const normalizeNumberInputValue = (value) => {
        if (typeof value === 'number' && Number.isFinite(value)) {
            return String(value);
        }
        const raw = String(value ?? '').trim();
        if (!raw) {
            return '';
        }

        const cleaned = raw
            .replace(/,/g, '')
            .replace(/\s+/g, '')
            .replace(/[^\d.+-]/g, '');

        if (!cleaned || cleaned === '.' || cleaned === '-' || cleaned === '+') {
            return '';
        }

        const parsed = Number(cleaned);
        return Number.isFinite(parsed) ? String(parsed) : '';
    };

    const isTruthyLike = (value) => ['1', 'true', 'yes', 'on'].includes(String(value).trim().toLowerCase());
    const isBlankLikeSelectValue = (value) => {
        const normalized = String(value ?? '').trim().toLowerCase();
        return normalized === '' || normalized === '__blank__';
    };

    const getEffectiveRuntimeSpec = (handle, suggestion = null) => {
        const fromSuggestion = suggestion && typeof suggestion === 'object' && suggestion.fillRuntimeSpec && typeof suggestion.fillRuntimeSpec === 'object'
            ? suggestion.fillRuntimeSpec
            : null;
        if (fromSuggestion) {
            return fromSuggestion;
        }

        const byHandle = runtime.fillRuntimeSpecsByHandle && typeof runtime.fillRuntimeSpecsByHandle === 'object'
            ? runtime.fillRuntimeSpecsByHandle
            : {};

        return byHandle[handle] || {};
    };

    runtime.applySuggestionValue = (handle, value, suggestion = null) => {
        const spec = getEffectiveRuntimeSpec(handle, suggestion);
        const acceptanceCheck = String(spec.acceptanceCheck || 'valueRoundTrip');

        const splitDateTime = runtime.findSplitDateTimeInputsByHandle(handle);
        if (splitDateTime.date || splitDateTime.time) {
            const anchorInput = splitDateTime.date || splitDateTime.time;
            if (anchorInput) {
                runtime.prepareInputForInteraction(anchorInput);
            }

            const parts = parseDateTimeParts(value);
            if (splitDateTime.date instanceof HTMLInputElement) {
                splitDateTime.date.value = parts.date;
                splitDateTime.date.dispatchEvent(new Event('input', { bubbles: true }));
                splitDateTime.date.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (splitDateTime.time instanceof HTMLInputElement) {
                splitDateTime.time.value = parts.time;
                splitDateTime.time.dispatchEvent(new Event('input', { bubbles: true }));
                splitDateTime.time.dispatchEvent(new Event('change', { bubbles: true }));
            }

            return true;
        }

        const input = runtime.findFieldInputByHandle(handle);
        if (!(input instanceof HTMLElement)) {
            return false;
        }

        runtime.prepareInputForInteraction(input);

        if (input instanceof HTMLInputElement && input.type === 'checkbox') {
            const boolValue = isTruthyLike(value);
            input.checked = boolValue;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            if (acceptanceCheck === 'checkedState') {
                return input.checked === boolValue;
            }
            return true;
        }

        if (input instanceof HTMLSelectElement) {
            const resolveBy = String(spec.resolveBy || 'both');
            let resolvedOptionValue = resolveSelectOptionValue(input, value, resolveBy);
            input.value = resolvedOptionValue;
            for (const option of Array.from(input.options)) {
                option.selected = String(option.value) === String(resolvedOptionValue);
            }

            const selectize =
                input.selectize ||
                (window.jQuery ? window.jQuery(input).data('selectize') : null);
            if (selectize && typeof selectize.setValue === 'function') {
                const selectizeResolvedValue = resolveSelectizeOptionValue(selectize, resolvedOptionValue);
                resolvedOptionValue = selectizeResolvedValue;
                input.value = resolvedOptionValue;
                for (const option of Array.from(input.options)) {
                    option.selected = String(option.value) === String(resolvedOptionValue);
                }
                selectize.setValue(String(resolvedOptionValue), true);
            }

            const selectizeValue = selectize && typeof selectize.getValue === 'function'
                ? String(selectize.getValue())
                : null;

            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new Event('blur', { bubbles: true }));

            if (acceptanceCheck === 'selectedOptionExists') {
                const inputValue = String(input.value ?? '');
                const resolvedAsString = String(resolvedOptionValue ?? '');
                const exists = Array.from(input.options).some((opt) => String(opt.value) === inputValue);
                const intendedValueIsBlank = isBlankLikeSelectValue(resolvedAsString);
                const selectedValueIsBlank = isBlankLikeSelectValue(inputValue);
                const matchesResolved = inputValue === resolvedAsString;
                const selectizeMismatch = selectizeValue !== null && String(selectizeValue) !== inputValue;

                if (selectizeMismatch) {
                    return false;
                }

                const accepted = exists && !intendedValueIsBlank && !selectedValueIsBlank && matchesResolved;
                return accepted;
            }

            return true;
        }

        if (input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement) {
            const nextValue = input instanceof HTMLInputElement && input.type === 'number'
                ? normalizeNumberInputValue(value)
                : String(value ?? '');
            input.value = nextValue;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));

            if (acceptanceCheck === 'valueRoundTrip') {
                return String(input.value) === String(nextValue);
            }

            return true;
        }

        return false;
    };
})();
