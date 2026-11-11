(() => {
    'use strict';

    document.querySelectorAll('[data-share-link]').forEach((container) => {
        const button = container.querySelector('[data-copy-link]');
        const input = container.querySelector('input');
        const status = container.querySelector('[role="status"]');
        if (!button || !input || !status || button.dataset.copyBound) return;
        button.dataset.copyBound = 'true';
        button.addEventListener('click', async () => {
            status.textContent = '';
            try {
                if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
                await navigator.clipboard.writeText(input.value);
                status.textContent = 'Link copied.';
            } catch {
                input.focus();
                input.select();
                input.setSelectionRange(0, input.value.length);
                status.textContent = 'Link selected. Use your device’s Copy action or Ctrl+C.';
            }
        });
    });

    if (!window.XMLHttpRequest || !window.FormData) return;
    let formNumber = 0;

    document.querySelectorAll('form[data-listing-save-form]').forEach((form) => {
        const status = form.querySelector('[data-listing-save-status]');
        const label = form.querySelector('[data-save-label]');
        const progress = form.querySelector('[data-save-progress]');
        const help = form.querySelector('[data-save-help]');
        const errors = form.querySelector('[data-listing-save-errors]');
        const summary = form.querySelector('[data-listing-file-summary]');
        if (!status || !label || !progress || !help || !errors || form.dataset.saveBound) return;
        form.dataset.saveBound = 'true';
        errors.id ||= `listing-save-errors-${++formNumber}`;
        const submitButtons = Array.from(form.querySelectorAll('button:not([type]),button[type="submit"],input[type="submit"]'));
        let pending = false;
        let previouslyDisabled = [];

        const files = () => Array.from(form.querySelectorAll('input[type="file"]')).flatMap((input) => Array.from(input.files || []));
        const updateSummary = () => {
            if (!summary) return;
            const selected = files();
            const bytes = selected.reduce((total, file) => total + file.size, 0);
            summary.textContent = selected.length ? `${selected.length} ${selected.length === 1 ? 'file' : 'files'} selected · ${(bytes / 1048576).toFixed(1)} MB total` : '';
        };
        form.querySelectorAll('input[type="file"]').forEach((input) => input.addEventListener('change', updateSummary));
        updateSummary();

        const clearErrors = () => {
            errors.replaceChildren();
            errors.hidden = true;
            form.querySelectorAll('[data-listing-error-field]').forEach((input) => {
                input.removeAttribute('aria-invalid');
                input.removeAttribute('data-listing-error-field');
            });
        };
        const restore = () => {
            pending = false;
            form.removeAttribute('aria-busy');
            submitButtons.forEach((button, index) => { button.disabled = previouslyDisabled[index] || false; });
        };
        const showErrors = (message, fieldErrors = null) => {
            restore();
            status.hidden = true;
            errors.replaceChildren();
            const heading = document.createElement('p');
            heading.textContent = message;
            errors.append(heading);
            if (fieldErrors && typeof fieldErrors === 'object' && !Array.isArray(fieldErrors)) {
                const list = document.createElement('ul');
                Object.entries(fieldErrors).forEach(([name, messages]) => {
                    const entries = Array.isArray(messages) ? messages : [messages];
                    entries.forEach((text) => {
                        if (typeof text !== 'string') return;
                        const item = document.createElement('li');
                        item.textContent = text;
                        list.append(item);
                    });
                    const bracketName = name.replace(/\.([^.]*)/g, '[$1]');
                    Array.from(form.elements).forEach((input) => {
                        if (input.name === name || input.name === bracketName || input.name === `${name}[]`) {
                            input.setAttribute('aria-invalid', 'true');
                            input.setAttribute('data-listing-error-field', 'true');
                        }
                    });
                });
                if (list.childElementCount) errors.append(list);
            }
            const retained = document.createElement('p');
            retained.textContent = 'Your entries and selected files are still here.';
            errors.append(retained);
            errors.hidden = false;
            errors.focus();
        };
        const processing = () => {
            progress.removeAttribute('value');
            progress.setAttribute('aria-label', 'Save in progress');
            label.textContent = files().length ? 'Upload complete. Processing and saving…' : 'Saving your changes…';
            help.textContent = 'Please keep this page open until the save is confirmed.';
        };

        form.addEventListener('submit', (event) => {
            if (pending) { event.preventDefault(); return; }
            const action = new URL(form.action, window.location.href);
            if (action.origin !== window.location.origin || form.method.toLowerCase() !== 'post') return;
            event.preventDefault();
            if (!form.reportValidity()) return;
            clearErrors();
            const body = new FormData(form);
            if (event.submitter?.name) body.append(event.submitter.name, event.submitter.value);
            const uploadingFiles = files().length > 0;
            previouslyDisabled = submitButtons.map((button) => button.disabled);
            submitButtons.forEach((button) => { button.disabled = true; });
            pending = true;
            form.setAttribute('aria-busy', 'true');
            status.hidden = false;
            if (uploadingFiles) {
                progress.value = 0;
                progress.setAttribute('aria-label', 'Upload progress');
                label.textContent = 'Uploading files… 0%';
                help.textContent = 'The upload will be followed by image processing and saving.';
            } else {
                processing();
            }

            const request = new XMLHttpRequest();
            request.open('POST', action.href);
            request.timeout = 300000;
            request.setRequestHeader('Accept', 'application/json');
            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            request.upload.addEventListener('progress', (upload) => {
                if (!uploadingFiles || !pending) return;
                if (upload.lengthComputable && upload.total > 0) {
                    const percentage = Math.min(100, Math.floor(upload.loaded * 100 / upload.total));
                    progress.value = percentage;
                    label.textContent = `Uploading files… ${percentage}%`;
                } else {
                    progress.removeAttribute('value');
                    label.textContent = 'Uploading files…';
                }
            });
            request.upload.addEventListener('load', () => { if (pending) processing(); });
            request.addEventListener('load', () => {
                let result = null;
                try { result = JSON.parse(request.responseText); } catch { /* Status handling also covers non-JSON server errors. */ }
                if (request.status >= 200 && request.status < 300 && typeof result?.redirect === 'string') {
                    let destination;
                    try { destination = new URL(result.redirect, window.location.href); } catch { destination = null; }
                    if (!destination || destination.origin !== window.location.origin || !['http:', 'https:'].includes(destination.protocol)) {
                        showErrors('The save response could not be verified. Check your listings before trying again.');
                        return;
                    }
                    progress.value = 100;
                    label.textContent = 'Saved. Opening your updated page…';
                    help.textContent = '';
                    window.location.assign(destination.href);
                    return;
                }
                if (request.status === 422) {
                    showErrors('Please correct the following details and save again.', result?.errors);
                } else if (request.status === 413) {
                    showErrors('The upload is larger than the server allows. Select fewer or smaller photos, then save again.');
                } else if (request.status === 419 || request.status === 401 || request.responseURL.includes('/login')) {
                    showErrors('Your session has expired. Sign in again in another tab, then reload this page before saving.');
                } else if (request.status === 403) {
                    showErrors('Your account cannot save these changes. Check your access with an administrator.');
                } else {
                    showErrors('The save could not be confirmed. Check your listings before trying again.');
                }
            });
            request.addEventListener('error', () => showErrors('The connection was interrupted before the save could be confirmed. Check your listings before trying again.'));
            request.addEventListener('timeout', () => showErrors('The request timed out before the save could be confirmed. Check your listings before trying again.'));
            request.addEventListener('abort', () => showErrors('The request ended before the save could be confirmed. Check your listings before trying again.'));
            try { request.send(body); } catch {
                showErrors('The request could not be sent. Check your connection and save again.');
            }
        });

        window.addEventListener('pageshow', (event) => {
            if (event.persisted) { restore(); status.hidden = true; clearErrors(); updateSummary(); }
        });
    });
})();

/* Listing photos: choose the cover and move photos earlier or later instead of typing order numbers. */
(() => {
    const sorter = document.querySelector('[data-photo-sorter]');
    if (!sorter) return;
    const items = () => Array.from(sorter.querySelectorAll('[data-photo-item]'));
    const renumber = () => {
        const list = items();
        list.forEach((item, position) => {
            item.querySelector('[data-order-input]').value = position;
            const [earlier, later] = item.querySelectorAll('[data-move]');
            earlier.disabled = position === 0;
            later.disabled = position === list.length - 1;
        });
        const cover = list[0];
        list.forEach((item) => item.classList.toggle('is-cover', item === cover));
        const radio = cover?.querySelector('[data-cover-choice]');
        if (radio) radio.checked = true;
    };
    items().forEach((item) => {
        item.querySelector('[data-photo-move]').hidden = false;
        item.querySelector('[data-order-field]').hidden = true;
        item.querySelectorAll('[data-move]').forEach((button) => button.addEventListener('click', () => {
            const sibling = Number(button.dataset.move) < 0 ? item.previousElementSibling : item.nextElementSibling;
            if (!sibling) return;
            Number(button.dataset.move) < 0 ? sorter.insertBefore(item, sibling) : sorter.insertBefore(sibling, item);
            renumber();
            button.focus();
        }));
        item.querySelector('[data-cover-choice]').addEventListener('change', () => {
            sorter.prepend(item);
            renumber();
            item.scrollIntoView({ block: 'nearest' });
        });
    });
    renumber();
})();
