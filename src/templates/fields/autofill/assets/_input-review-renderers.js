(() => {
    // Registry of suggestion editor renderers used by the review modal.
    window.AutofillInputRuntime = window.AutofillInputRuntime || {};
    const runtime = window.AutofillInputRuntime;

    const asString = (value) => (value === null || value === undefined ? '' : String(value));
    const pad2 = (value) => String(value).padStart(2, '0');
    const RICH_TEXT_BLOCKED_SELECTOR = 'script, iframe, object, embed, form, input, button, textarea, select, option, link, meta, base';

    const toDateInputValue = (date) => (
        `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`
    );

    const toTimeInputValue = (date) => (
        `${pad2(date.getHours())}:${pad2(date.getMinutes())}`
    );

    const parseSuggestionDate = (value) => {
        const raw = asString(value).trim();
        if (raw === '') {
            return null;
        }

        const parsed = new Date(raw);
        if (!Number.isNaN(parsed.getTime())) {
            return parsed;
        }

        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
            const parts = raw.split('-').map((item) => Number(item));
            return new Date(parts[0], parts[1] - 1, parts[2], 0, 0, 0, 0);
        }

        return null;
    };

    const toBool = (value) => {
        if (typeof value === 'boolean') {
            return value;
        }

        if (typeof value === 'number') {
            return value !== 0;
        }

        const normalized = asString(value).trim().toLowerCase();
        if (normalized === '') {
            return false;
        }

        return ['1', 'true', 'on', 'yes'].includes(normalized);
    };

    const getEditorConfig = (suggestion) => {
        const reviewEditor = suggestion && typeof suggestion.reviewEditor === 'object'
            ? suggestion.reviewEditor
            : null;
        const type = asString(reviewEditor?.type || '').trim() || 'textarea';

        return {
            type,
            config: reviewEditor || {},
        };
    };

    const getTemplateSelector = (type) => {
        const safeType = window.CSS && typeof window.CSS.escape === 'function'
            ? window.CSS.escape(type)
            : type.replace(/"/g, '\\"');
        return `[data-autofill-review-template="${safeType}"]`;
    };

    const mountTemplate = (type, container) => {
        if (!(container instanceof HTMLElement)) {
            return false;
        }

        const template = document.querySelector(getTemplateSelector(type));
        if (!(template instanceof HTMLTemplateElement)) {
            return false;
        }

        container.innerHTML = '';
        const fragment = template.content.cloneNode(true);
        container.appendChild(fragment);
        return true;
    };

    const sanitizeRichTextHtml = (rawValue) => {
        const input = asString(rawValue);
        if (input.trim() === '') {
            return '';
        }

        const parser = new DOMParser();
        const doc = parser.parseFromString(input, 'text/html');

        doc.querySelectorAll(RICH_TEXT_BLOCKED_SELECTOR).forEach((node) => node.remove());

        doc.querySelectorAll('*').forEach((element) => {
            Array.from(element.attributes).forEach((attribute) => {
                const name = attribute.name.toLowerCase();
                const value = attribute.value || '';

                if (name.startsWith('on')) {
                    element.removeAttribute(attribute.name);
                    return;
                }

                if ((name === 'href' || name === 'src') && /^\s*javascript:/i.test(value)) {
                    element.removeAttribute(attribute.name);
                }
            });
        });

        return (doc.body.innerHTML || '').trim();
    };

    const createTextareaRenderer = () => ({
        render({ suggestion, editorConfig, container }) {
            const isRichText = String(editorConfig?.displayMode || '').trim().toLowerCase() === 'richtext';
            if (isRichText) {
                container.innerHTML = '';
                const editor = document.createElement('div');
                editor.className = 'autofill-review-richtext-editor text fullwidth';
                editor.setAttribute('contenteditable', 'true');
                editor.setAttribute('spellcheck', 'true');
                editor.dataset.autofillReviewInput = 'richtext';
                editor.innerHTML = sanitizeRichTextHtml(suggestion.value ?? '');
                container.appendChild(editor);
                return;
            }

            const usedTemplate = mountTemplate('textarea', container);
            const textarea = usedTemplate
                ? container.querySelector('[data-autofill-review-input="textarea"]')
                : (() => {
                    const element = document.createElement('textarea');
                    element.className = 'text fullwidth';
                    element.rows = 3;
                    container.appendChild(element);
                    return element;
                })();
            if (!(textarea instanceof HTMLTextAreaElement)) {
                return;
            }
            textarea.value = asString(suggestion.value ?? '');
        },
        readValue({ container }) {
            const richText = container.querySelector('[data-autofill-review-input="richtext"]');
            if (richText instanceof HTMLElement) {
                return sanitizeRichTextHtml(richText.innerHTML);
            }

            const textarea = container.querySelector('[data-autofill-review-input="textarea"], textarea');
            return textarea instanceof HTMLTextAreaElement ? textarea.value : '';
        },
    });

    const createDropdownRenderer = () => ({
        render({ suggestion, editorConfig, container }) {
            const usedTemplate = mountTemplate('dropdown', container);
            const select = usedTemplate
                ? container.querySelector('[data-autofill-review-input="dropdown"]')
                : (() => {
                    const element = document.createElement('select');
                    element.className = 'text fullwidth';
                    container.appendChild(element);
                    return element;
                })();
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }

            select.innerHTML = '';
            const options = Array.isArray(editorConfig.options) ? editorConfig.options : [];
            const normalizedValue = asString(suggestion.value ?? '');
            let hasMatchingOption = false;

            for (const option of options) {
                if (!option || typeof option !== 'object') {
                    continue;
                }
                const optionValue = asString(option.value ?? '');
                const optionLabel = asString(option.label ?? optionValue);
                const element = document.createElement('option');
                element.value = optionValue;
                element.textContent = optionLabel === '' ? optionValue : optionLabel;
                if (optionValue === normalizedValue) {
                    hasMatchingOption = true;
                }
                select.appendChild(element);
            }

            if (!hasMatchingOption && normalizedValue !== '') {
                const fallback = document.createElement('option');
                fallback.value = normalizedValue;
                fallback.textContent = asString(editorConfig.displayValue || normalizedValue);
                fallback.selected = true;
                select.appendChild(fallback);
            }

            select.value = normalizedValue;
        },
        readValue({ container }) {
            const select = container.querySelector('[data-autofill-review-input="dropdown"], select');
            return select instanceof HTMLSelectElement ? select.value : '';
        },
    });

    const createButtonGroupRenderer = () => ({
        render({ suggestion, editorConfig, container }) {
            const usedTemplate = mountTemplate('buttonGroup', container);
            const wrapper = usedTemplate
                ? container.querySelector('[data-autofill-review-input="button-group"]')
                : null;

            const host = wrapper instanceof HTMLElement ? wrapper : container;
            host.innerHTML = '';
            host.classList.add('autofill-review-editor-button-group');

            const options = Array.isArray(editorConfig.options) ? editorConfig.options : [];
            const normalizedValue = asString(suggestion.value ?? '');

            for (const option of options) {
                if (!option || typeof option !== 'object') {
                    continue;
                }

                const optionValue = asString(option.value ?? '');
                const optionLabel = asString(option.label ?? optionValue);
                if (optionValue === '') {
                    continue;
                }

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn';
                button.dataset.autofillReviewInput = 'button-group-option';
                button.dataset.value = optionValue;
                button.textContent = optionLabel === '' ? optionValue : optionLabel;
                if (optionValue === normalizedValue) {
                    button.classList.add('active');
                    button.setAttribute('aria-pressed', 'true');
                } else {
                    button.setAttribute('aria-pressed', 'false');
                }

                button.addEventListener('click', () => {
                    host.querySelectorAll('[data-autofill-review-input="button-group-option"]').forEach((node) => {
                        if (!(node instanceof HTMLElement)) {
                            return;
                        }
                        node.classList.remove('active');
                        node.setAttribute('aria-pressed', 'false');
                    });

                    button.classList.add('active');
                    button.setAttribute('aria-pressed', 'true');
                    host.dataset.selectedValue = optionValue;
                });

                host.appendChild(button);
            }

            host.dataset.selectedValue = normalizedValue;
        },
        readValue({ suggestion, container }) {
            const wrapper = container.querySelector('[data-autofill-review-input="button-group"]');
            const host = wrapper instanceof HTMLElement ? wrapper : container;
            const selected = host.querySelector('[data-autofill-review-input="button-group-option"].active');
            if (selected instanceof HTMLElement) {
                return asString(selected.dataset.value || '');
            }

            const fallback = asString(host.dataset.selectedValue || '');
            if (fallback !== '') {
                return fallback;
            }

            return asString(suggestion?.value ?? '');
        },
    });

    const createDateRenderer = () => ({
        render({ suggestion, container }) {
            const usedTemplate = mountTemplate('date', container);
            const input = usedTemplate
                ? container.querySelector('[data-autofill-review-input="date"]')
                : (() => {
                    const element = document.createElement('input');
                    element.type = 'date';
                    element.className = 'text fullwidth';
                    container.appendChild(element);
                    return element;
                })();
            if (!(input instanceof HTMLInputElement)) {
                return;
            }
            const parsedDate = parseSuggestionDate(suggestion.value);
            input.value = parsedDate ? toDateInputValue(parsedDate) : '';
        },
        readValue({ container }) {
            const input = container.querySelector('[data-autofill-review-input="date"], input[type="date"]');
            return input instanceof HTMLInputElement ? input.value : '';
        },
    });

    const createDateTimeRenderer = () => ({
        render({ suggestion, container }) {
            const usedTemplate = mountTemplate('datetime', container);
            let dateInput = usedTemplate
                ? container.querySelector('[data-autofill-review-input="datetime-date"]')
                : null;
            let timeInput = usedTemplate
                ? container.querySelector('[data-autofill-review-input="datetime-time"]')
                : null;

            if (!(dateInput instanceof HTMLInputElement) || !(timeInput instanceof HTMLInputElement)) {
                container.innerHTML = '';
                const wrapper = document.createElement('div');
                wrapper.className = 'autofill-review-editor-datetime';
                const fallbackDateInput = document.createElement('input');
                fallbackDateInput.type = 'date';
                fallbackDateInput.className = 'text fullwidth';
                const fallbackTimeInput = document.createElement('input');
                fallbackTimeInput.type = 'time';
                fallbackTimeInput.className = 'text fullwidth';
                fallbackTimeInput.step = '60';
                wrapper.appendChild(fallbackDateInput);
                wrapper.appendChild(fallbackTimeInput);
                container.appendChild(wrapper);
                dateInput = fallbackDateInput;
                timeInput = fallbackTimeInput;
            }

            const parsedDate = parseSuggestionDate(suggestion.value);
            if (parsedDate) {
                dateInput.value = toDateInputValue(parsedDate);
                timeInput.value = toTimeInputValue(parsedDate);
            } else {
                dateInput.value = '';
                timeInput.value = '';
            }
        },
        readValue({ container }) {
            const dateInput = container.querySelector('[data-autofill-review-input="datetime-date"], input[type="date"]');
            const timeInput = container.querySelector('[data-autofill-review-input="datetime-time"], input[type="time"]');
            const dateValue = dateInput instanceof HTMLInputElement ? dateInput.value : '';
            const timeValue = timeInput instanceof HTMLInputElement ? timeInput.value : '';

            if (dateValue === '') {
                return '';
            }

            if (timeValue === '') {
                return dateValue;
            }

            return `${dateValue}T${timeValue}`;
        },
    });

    const createLightswitchRenderer = () => ({
        render({ suggestion, container }) {
            const usedTemplate = mountTemplate('lightswitch', container);
            const switchElement = usedTemplate
                ? container.querySelector('[data-autofill-review-input="lightswitch"]')
                : null;

            if (!(switchElement instanceof HTMLElement)) {
                const wrapper = document.createElement('label');
                wrapper.className = 'checkboxfield';
                wrapper.style.display = 'inline-flex';
                wrapper.style.alignItems = 'center';
                wrapper.style.gap = '8px';
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.checked = toBool(suggestion.value);
                const text = document.createElement('span');
                text.textContent = input.checked ? 'On' : 'Off';
                input.addEventListener('change', () => {
                    text.textContent = input.checked ? 'On' : 'Off';
                });
                wrapper.appendChild(input);
                wrapper.appendChild(text);
                container.innerHTML = '';
                container.appendChild(wrapper);
                return;
            }

            const setSwitchState = (isOn) => {
                switchElement.classList.toggle('on', isOn);
                switchElement.classList.toggle('indeterminate', false);
                switchElement.setAttribute('aria-checked', isOn ? 'true' : 'false');
                const hiddenInput = switchElement.querySelector('input[type="hidden"]');
                if (hiddenInput instanceof HTMLInputElement) {
                    hiddenInput.value = isOn ? '1' : '';
                }
            };

            const on = toBool(suggestion.value);
            setSwitchState(on);

            if (!switchElement.dataset.autofillBound) {
                switchElement.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    const currentOn = switchElement.classList.contains('on');
                    setSwitchState(!currentOn);
                });
                switchElement.dataset.autofillBound = '1';
            }
        },
        readValue({ container }) {
            const switchElement = container.querySelector('[data-autofill-review-input="lightswitch"]');
            if (switchElement instanceof HTMLElement) {
                return switchElement.classList.contains('on');
            }

            const input = container.querySelector('input[type="checkbox"]');
            return input instanceof HTMLInputElement ? input.checked : false;
        },
    });

    const reviewRenderers = {
        textarea: createTextareaRenderer(),
        dropdown: createDropdownRenderer(),
        buttonGroup: createButtonGroupRenderer(),
        date: createDateRenderer(),
        datetime: createDateTimeRenderer(),
        lightswitch: createLightswitchRenderer(),
    };

    runtime.renderReviewEditor = ({ suggestion, container }) => {
        if (!(container instanceof HTMLElement)) {
            return;
        }

        container.innerHTML = '';
        const { type, config } = getEditorConfig(suggestion);
        const renderer = reviewRenderers[type] || reviewRenderers.textarea;
        renderer.render({
            suggestion,
            editorConfig: config,
            container,
        });
    };

    runtime.readReviewEditorValue = ({ suggestion, container }) => {
        if (!(container instanceof HTMLElement)) {
            return asString(suggestion?.value ?? '');
        }

        const { type } = getEditorConfig(suggestion);
        const renderer = reviewRenderers[type] || reviewRenderers.textarea;
        return renderer.readValue({
            suggestion,
            container,
        });
    };
})();
