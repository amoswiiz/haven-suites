/*
 * Visual editor for blog posts (Toast UI Editor). Posts are still saved as Markdown, so the public site keeps
 * rendering them with raw HTML removed. If the editor script cannot load, the plain Markdown box stays in place.
 */
(() => {
    const holder = document.querySelector('[data-post-editor]');
    const body = document.querySelector('[data-post-body]');
    if (!holder || !body || !window.toastui || !window.toastui.Editor) return;

    const form = body.form;
    const status = document.querySelector('[data-editor-status]');
    const token = form.querySelector('input[name="_token"]')?.value || '';
    const setStatus = (text) => { if (status) status.textContent = text; };

    holder.hidden = false;
    body.hidden = true;
    body.removeAttribute('required');
    form.classList.add('has-rich-editor');

    const editor = new window.toastui.Editor({
        el: holder,
        height: '560px',
        initialEditType: 'wysiwyg',
        previewStyle: 'tab',
        initialValue: body.value,
        usageStatistics: false,
        placeholder: 'Write your post. Use the toolbar for headings, lists, links and photos.',
        toolbarItems: [
            ['heading', 'bold', 'italic', 'strike'],
            ['hr', 'quote'],
            ['ul', 'ol', 'task'],
            ['table', 'image', 'link'],
        ],
        hooks: {
            addImageBlobHook: async (blob, callback) => {
                if (blob.size > 5 * 1024 * 1024) {
                    setStatus('That photo is larger than 5 MB. Choose a smaller one.');
                    return;
                }
                const data = new FormData();
                data.append('image', blob, blob.name || 'photo.jpg');
                setStatus('Uploading photo…');
                try {
                    const response = await fetch(holder.dataset.uploadUrl, {
                        method: 'POST', body: data, credentials: 'same-origin',
                        headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
                    });
                    const result = await response.json().catch(() => ({}));
                    if (!response.ok || !result.url) {
                        setStatus(result.errors?.image?.[0] || result.message || 'The photo could not be uploaded. Try another image.');
                        return;
                    }
                    const altText = window.prompt('Describe the photo for guests who cannot see it (optional):', '') || '';
                    callback(result.url, altText.replace(/[\[\]]/g, ''));
                    setStatus('Photo added.');
                } catch {
                    setStatus('The photo could not be uploaded. Check your connection and try again.');
                }
            },
        },
    });

    let saving = false;
    form.addEventListener('submit', (event) => {
        const markdown = editor.getMarkdown().trim();
        body.value = markdown;
        if (markdown.length < 50) {
            event.preventDefault();
            setStatus('Write at least a short paragraph (50 characters) before saving.');
            editor.focus();
            return;
        }
        saving = true;
    });

    let changed = false;
    editor.on('change', () => { changed = true; });
    window.addEventListener('beforeunload', (event) => {
        if (changed && !saving) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();
