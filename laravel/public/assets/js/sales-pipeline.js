(() => {
    'use strict';
    const board = document.getElementById('sales-board');
    const dialog = document.getElementById('sales-move-dialog');
    if (!board || !dialog) return;
    const form = document.getElementById('sales-move-form');
    const stage = document.getElementById('move-stage');
    const status = document.getElementById('sales-move-status');
    const columns = Array.from(board.querySelectorAll('section[data-stage]'));
    let dragged = null;
    let trigger = null;
    let scrolling = 0;
    let scrollFrame = null;
    let touch = null;
    let suppressClick = false;

    function highlight(column) {
        columns.forEach(item => item.classList.toggle('sales-drop-target', item === column));
    }
    function clearDrag() {
        if (dragged) dragged.classList.remove('sales-dragging');
        dragged = null;
        highlight(null);
        scrolling = 0;
        if (scrollFrame !== null) cancelAnimationFrame(scrollFrame);
        scrollFrame = null;
    }
    function autoScroll(x) {
        const bounds = board.getBoundingClientRect();
        scrolling = x < bounds.left + 48 ? -12 : x > bounds.right - 48 ? 12 : 0;
        if (scrollFrame !== null) return;
        const tick = () => {
            board.scrollLeft += scrolling;
            scrollFrame = scrolling ? requestAnimationFrame(tick) : null;
        };
        scrollFrame = requestAnimationFrame(tick);
    }
    function configureStage() {
        const won = stage.value === 'won';
        const lost = stage.value === 'lost';
        document.getElementById('sales-move-fields').hidden = won;
        document.getElementById('sales-won-message').hidden = !won;
        document.getElementById('sales-save-move').hidden = won;
        document.getElementById('sales-confirm-booking').hidden = !won;
        document.getElementById('sales-lost-fields').hidden = !lost;
        form.elements.next_action.required = !won;
        form.elements.follow_up_at.required = !won && !lost;
        form.elements.lost_reason.required = lost;
    }
    function openMove(card, target, opener) {
        clearDrag();
        trigger = opener || card.querySelector('.sales-move');
        form.action = card.dataset.updateUrl;
        form.elements.version.value = card.dataset.version;
        form.elements.assigned_to.value = card.dataset.assigned;
        form.elements.next_action.value = card.dataset.nextAction;
        form.elements.follow_up_at.value = card.dataset.followUp;
        form.elements.lost_reason.value = card.dataset.lostReason;
        stage.value = target || card.dataset.stage;
        document.getElementById('sales-move-guest').textContent = card.dataset.name;
        document.getElementById('sales-confirm-booking').href = card.dataset.detailUrl;
        document.getElementById('sales-save-move').disabled = false;
        configureStage();
        dialog.showModal();
        stage.focus();
    }

    stage.addEventListener('change', configureStage);
    document.getElementById('sales-cancel-move').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => trigger?.focus());
    form.addEventListener('submit', event => {
        if (stage.value === 'won') {
            event.preventDefault();
            return;
        }
        document.getElementById('sales-save-move').disabled = true;
        status.textContent = 'Saving stage change…';
    });
    board.querySelectorAll('[data-movable]').forEach(card => {
        const button = card.querySelector('.sales-move');
        button.addEventListener('click', () => {
            if (suppressClick) { suppressClick = false; return; }
            openMove(card, null, button);
        });
        card.addEventListener('dragstart', event => {
            dragged = card;
            card.classList.add('sales-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.dataset.name);
            status.textContent = `Moving ${card.dataset.name}. Drop into a stage to review the change.`;
        });
        card.addEventListener('dragend', clearDrag);
        // A dedicated handle lets touch users drag while the rest of the board still scrolls.
        button.addEventListener('pointerdown', event => {
            if (event.pointerType === 'mouse') return;
            touch = { card, id: event.pointerId, x: event.clientX, y: event.clientY, active: false };
            button.setPointerCapture(event.pointerId);
        });
        button.addEventListener('pointermove', event => {
            if (!touch || touch.id !== event.pointerId) return;
            if (!touch.active && Math.hypot(event.clientX - touch.x, event.clientY - touch.y) < 10) return;
            touch.active = true;
            dragged = card;
            card.classList.add('sales-dragging');
            const column = document.elementFromPoint(event.clientX, event.clientY)?.closest('section[data-stage]');
            highlight(columns.includes(column) ? column : null);
            autoScroll(event.clientX);
            event.preventDefault();
        });
        button.addEventListener('pointerup', event => {
            if (!touch || touch.id !== event.pointerId) return;
            const active = touch.active;
            touch = null;
            if (!active) return;
            suppressClick = true;
            const column = document.elementFromPoint(event.clientX, event.clientY)?.closest('section[data-stage]');
            if (columns.includes(column) && column.dataset.stage !== card.dataset.stage) {
                openMove(card, column.dataset.stage, button);
            } else { clearDrag(); }
            setTimeout(() => { suppressClick = false; }, 300);
        });
        button.addEventListener('pointercancel', () => { touch = null; clearDrag(); });
    });
    columns.forEach(column => {
        column.addEventListener('dragover', event => {
            if (!dragged) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            highlight(column);
            autoScroll(event.clientX);
        });
        column.addEventListener('drop', event => {
            if (!dragged) return;
            event.preventDefault();
            const card = dragged;
            if (column.dataset.stage !== card.dataset.stage) openMove(card, column.dataset.stage);
            else clearDrag();
        });
    });
})();
