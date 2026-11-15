/*
 * Live search checklist for the blog editor. Sends the text as typed to the server, which scores it and returns the
 * updated panel. Nothing is saved. Without JavaScript the panel shows the score from the last save.
 */
(() => {
    const panel = document.querySelector('[data-seo-panel]');
    const body = document.querySelector('[data-post-body]');
    if (!panel || !body || !window.fetch) return;

    const form = body.form;
    const token = form.querySelector('input[name="_token"]')?.value || '';
    const value = (name) => form.querySelector(`[name="${name}"]`)?.value || '';
    let hasCover = panel.dataset.hasCover === '1';
    let timer = null;
    let latest = 0;

    const check = async () => {
        const request = ++latest;
        const data = new FormData();
        data.append('id', panel.dataset.postId || '');
        data.append('body', window.havenPostEditor ? window.havenPostEditor.getMarkdown() : body.value);
        ['title', 'slug', 'excerpt', 'seo_title', 'seo_description', 'focus_keyword'].forEach((name) => data.append(name, value(name)));
        data.append('has_cover', hasCover ? '1' : '0');
        panel.classList.add('is-checking');
        try {
            const response = await fetch(panel.dataset.checkUrl, {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
            });
            const result = await response.json().catch(() => null);
            if (request === latest && response.ok && result?.html) panel.innerHTML = result.html;
        } catch {
            /* Keep the last result; the checklist is a helper, not required for saving. */
        } finally {
            if (request === latest) panel.classList.remove('is-checking');
        }
    };
    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(check, 900);
    };

    form.querySelectorAll('[data-seo-field]').forEach((field) => {
        field.addEventListener(field.type === 'file' ? 'change' : 'input', () => {
            if (field.type === 'file') hasCover = field.files.length > 0 || panel.dataset.hasCover === '1';
            schedule();
        });
    });
    form.querySelector('[name="remove_cover"]')?.addEventListener('change', (event) => {
        hasCover = !event.target.checked && panel.dataset.hasCover === '1';
        schedule();
    });
    body.addEventListener('input', schedule);
    document.addEventListener('post-editor-change', schedule);
})();
