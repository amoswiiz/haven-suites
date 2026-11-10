(() => {
    'use strict';
    const board = document.querySelector('[data-task-board]');
    const dialog = document.querySelector('[data-note-dialog]');
    const noteInput = document.querySelector('[data-note-dialog-input]');
    let pending = null;

    const askForNote = (form, text) => {
        if (!dialog || typeof dialog.showModal !== 'function') {
            form.querySelector('details, textarea')?.focus();
            return;
        }
        pending = form;
        noteInput.value = form.elements.note.value;
        dialog.querySelector('[data-note-dialog-text]').textContent = text;
        dialog.showModal();
        noteInput.focus();
    };

    dialog?.addEventListener('close', () => {
        if (dialog.returnValue === 'confirm' && pending && noteInput.value.trim() !== '') {
            pending.elements.note.value = noteInput.value.trim();
            pending.submit();
        }
        pending = null;
    });

    // Moves that need a note (complete, return) ask for it before submitting, from the menu or a drag.
    document.querySelectorAll('[data-move-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const option = form.elements.action.selectedOptions[0];
            if (option?.dataset.note === '1' && form.elements.note.value.trim() === '') {
                event.preventDefault();
                askForNote(form, `${option.textContent.trim()}: add a short note for the team.`);
            }
        });
    });

    if (board && board.dataset.group === 'status') {
        let dragged = null;
        const columns = Array.from(board.querySelectorAll('[data-drop-status]'));
        const movesFor = (card) => {
            try {
                return JSON.parse(card.dataset.moves || '{}');
            } catch (error) {
                return {};
            }
        };
        const clear = () => columns.forEach((column) => column.classList.remove('is-drop-target', 'is-drop-blocked'));

        board.addEventListener('dragstart', (event) => {
            const card = event.target.closest('.task-card[draggable="true"]');
            if (!card) {
                return;
            }
            dragged = card;
            card.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.dataset.taskId);
            const moves = movesFor(card);
            columns.forEach((column) => {
                const own = column.contains(card);
                column.classList.toggle('is-drop-target', !own && column.dataset.dropStatus in moves);
                column.classList.toggle('is-drop-blocked', !own && !(column.dataset.dropStatus in moves));
            });
        });
        board.addEventListener('dragend', () => {
            dragged?.classList.remove('is-dragging');
            dragged = null;
            clear();
        });
        columns.forEach((column) => {
            column.addEventListener('dragover', (event) => {
                if (dragged && column.dataset.dropStatus in movesFor(dragged)) {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                }
            });
            column.addEventListener('drop', (event) => {
                event.preventDefault();
                const card = dragged;
                clear();
                const move = card ? movesFor(card)[column.dataset.dropStatus] : null;
                const form = card?.querySelector('[data-move-form]');
                if (!move || !form) {
                    return;
                }
                const option = Array.from(form.elements.action.options).find((item) => item.dataset.target === column.dataset.dropStatus);
                if (option) {
                    form.elements.action.value = option.value;
                }
                if (move.note && form.elements.note.value.trim() === '') {
                    askForNote(form, `${move.label}: add a short note for the team.`);
                    return;
                }
                form.submit();
            });
        });
    }

    document.addEventListener('keydown', (event) => {
        const typing = event.target.closest('input, textarea, select, [contenteditable="true"]');
        if (typing || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        if (event.key === '/') {
            event.preventDefault();
            document.getElementById('task-search')?.focus();
        } else if (event.key.toLowerCase() === 'n') {
            const title = document.querySelector('[data-quick-title]');
            if (title) {
                event.preventDefault();
                title.closest('details').open = true;
                title.focus();
            }
        }
    });
})();
