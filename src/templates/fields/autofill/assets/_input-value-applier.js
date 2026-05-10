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

    const resolveSelectOptionValue = (select, candidate) => {
        const raw = String(candidate ?? '').trim();
        if (raw === '') {
            return '';
        }

        const direct = Array.from(select.options).find((opt) => String(opt.value) === raw);
        if (direct) {
            return direct.value;
        }

        const byLabel = Array.from(select.options).find((opt) => String(opt.textContent || '').trim().toLowerCase() === raw.toLowerCase());
        if (byLabel) {
            return byLabel.value;
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

    const normalizeNumberInputValue = (value) => {
        if (typeof value === 'number' && Number.isFinite(value)) {
            return String(value);
        }
        const raw = String(value ?? '').trim();
        if (!raw) {
            return '';
        }

        // Remove common formatting noise from model output.
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

    runtime.applySuggestionValue = (handle, value) => {
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
            const boolValue = ['1', 'true', 'yes', 'on'].includes(String(value).trim().toLowerCase());
            input.checked = boolValue;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }

        if (input instanceof HTMLSelectElement) {
            const resolvedOptionValue = resolveSelectOptionValue(input, value);
            input.value = resolvedOptionValue;
            for (const option of Array.from(input.options)) {
                option.selected = String(option.value) === String(resolvedOptionValue);
            }

            // If Craft enhanced this select with Selectize, use its API to ensure
            // UI + hidden input state stay in sync for submission.
            const selectize =
                input.selectize ||
                (window.jQuery ? window.jQuery(input).data('selectize') : null);
            if (selectize && typeof selectize.setValue === 'function') {
                selectize.setValue(String(resolvedOptionValue), true);
            }

            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            // Craft may decorate selects; prompt a UI refresh path too.
            input.dispatchEvent(new Event('blur', { bubbles: true }));
            return true;
        }

        if (input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement) {
            if (input instanceof HTMLInputElement && input.type === 'number') {
                input.value = normalizeNumberInputValue(value);
            } else {
                input.value = String(value ?? '');
            }
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }

        return false;
    };
})();
