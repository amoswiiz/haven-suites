/*
 * Two-month date range picker for the homepage search. The real check_in / check_out fields stay in the form
 * (as hidden inputs holding YYYY-MM-DD), so searches, shared links and the server work exactly as before.
 * Without this script the browser's own date fields are used.
 */
(() => {
    const pill = document.querySelector('[data-date-range]');
    const startInput = pill?.querySelector('[data-range="start"]');
    const endInput = pill?.querySelector('[data-range="end"]');
    if (!pill || !startInput || !endInput) return;

    const iso = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const parse = (value) => (/^\d{4}-\d{2}-\d{2}$/.test(value || '') ? new Date(`${value}T12:00:00`) : null);
    const today = parse(startInput.min) || new Date();
    today.setHours(12, 0, 0, 0);
    const label = (value) => {
        const date = parse(value);
        return date ? date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) : 'Add date';
    };
    const monthName = (date) => date.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });

    let start = startInput.value;
    let end = endInput.value;
    let picking = 'start';
    let view = new Date((parse(start) || today).getFullYear(), (parse(start) || today).getMonth(), 1, 12);

    const makeTrigger = (input, kind) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'stay-date-trigger';
        button.setAttribute('aria-haspopup', 'dialog');
        button.addEventListener('click', () => open(kind));
        input.type = 'hidden';
        input.after(button);
        return button;
    };
    const startButton = makeTrigger(startInput, 'start');
    const endButton = makeTrigger(endInput, 'end');

    const panel = document.createElement('div');
    panel.className = 'stay-range-picker';
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Choose your dates');
    pill.append(panel);

    const nights = () => {
        const from = parse(start);
        const to = parse(end);
        return from && to ? Math.round((to - from) / 86400000) : 0;
    };
    const sync = () => {
        startInput.value = start;
        endInput.value = end;
        startButton.textContent = label(start);
        endButton.textContent = label(end);
        startButton.classList.toggle('is-empty', !start);
        endButton.classList.toggle('is-empty', !end);
        startButton.setAttribute('aria-label', start ? `Check-in ${label(start)}, change` : 'Add check-in date');
        endButton.setAttribute('aria-label', end ? `Check-out ${label(end)}, change` : 'Add check-out date');
    };

    const month = (first) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'stay-range-month';
        const title = document.createElement('h4');
        title.textContent = monthName(first);
        const grid = document.createElement('div');
        grid.className = 'stay-range-grid';
        ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'].forEach((day) => {
            const head = document.createElement('abbr');
            head.textContent = day;
            grid.append(head);
        });
        const offset = (first.getDay() + 6) % 7;
        for (let blank = 0; blank < offset; blank++) grid.append(document.createElement('span'));
        const days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
        for (let day = 1; day <= days; day++) {
            const date = new Date(first.getFullYear(), first.getMonth(), day, 12);
            const value = iso(date);
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = String(day);
            button.dataset.date = value;
            button.setAttribute('aria-label', date.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
            button.disabled = date < today;
            if (value === iso(today)) button.classList.add('is-today');
            if (value === start) button.classList.add('is-start');
            if (value === end) button.classList.add('is-end');
            if (start && end && value > start && value < end) button.classList.add('is-between');
            if (value === start || value === end) button.setAttribute('aria-pressed', 'true');
            grid.append(button);
        }
        wrapper.append(title, grid);
        return wrapper;
    };

    const render = () => {
        const second = new Date(view.getFullYear(), view.getMonth() + 1, 1, 12);
        const atStart = view.getFullYear() === today.getFullYear() && view.getMonth() === today.getMonth();
        const count = nights();
        const summary = start && end ? `${label(start)} – ${label(end)} · ${count} night${count === 1 ? '' : 's'}`
            : (picking === 'start' ? 'Select your check-in date' : 'Select your check-out date');
        panel.innerHTML = '';
        const head = document.createElement('div');
        head.className = 'stay-range-head';
        const title = document.createElement('strong');
        title.textContent = summary;
        title.setAttribute('aria-live', 'polite');
        const nav = document.createElement('div');
        nav.className = 'stay-range-nav';
        const previous = document.createElement('button');
        previous.type = 'button';
        previous.innerHTML = '&#8249;';
        previous.setAttribute('aria-label', 'Previous month');
        previous.disabled = atStart;
        previous.addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() - 1, 1, 12); render(); });
        const next = document.createElement('button');
        next.type = 'button';
        next.innerHTML = '&#8250;';
        next.setAttribute('aria-label', 'Next month');
        next.addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() + 1, 1, 12); render(); });
        nav.append(previous, next);
        head.append(title, nav);
        const months = document.createElement('div');
        months.className = 'stay-range-months';
        months.append(month(view), month(second));
        const foot = document.createElement('div');
        foot.className = 'stay-range-foot';
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.textContent = 'Clear dates';
        clear.addEventListener('click', () => { start = ''; end = ''; picking = 'start'; sync(); render(); });
        const done = document.createElement('button');
        done.type = 'button';
        done.className = 'is-primary';
        done.textContent = 'Done';
        done.addEventListener('click', close);
        foot.append(clear, done);
        panel.append(head, months, foot);
    };

    panel.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-date]');
        if (!button || button.disabled) return;
        const value = button.dataset.date;
        if (picking === 'start' || !start || value <= start) {
            start = value;
            end = '';
            picking = 'end';
        } else {
            end = value;
            picking = 'start';
        }
        sync();
        render();
        if (start && end) {
            close();
            endButton.focus();
        } else {
            panel.querySelector(`button[data-date="${start}"]`)?.focus();
        }
    });

    function open(kind) {
        picking = kind === 'end' && start ? 'end' : 'start';
        const focus = parse(picking === 'end' ? (end || start) : start) || today;
        view = new Date(focus.getFullYear(), focus.getMonth(), 1, 12);
        render();
        panel.hidden = false;
        (picking === 'start' ? startButton : endButton).setAttribute('aria-expanded', 'true');
        (panel.querySelector('.is-start, .is-end') || panel.querySelector('button[data-date]:not(:disabled)'))?.focus();
    }
    function close() {
        panel.hidden = true;
        startButton.setAttribute('aria-expanded', 'false');
        endButton.setAttribute('aria-expanded', 'false');
    }

    document.addEventListener('click', (event) => {
        // composedPath is fixed when the click starts, so it still includes the panel after a re-render removes the button.
        const path = event.composedPath();
        if (!panel.hidden && !path.includes(panel) && !path.includes(startButton) && !path.includes(endButton)) close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            close();
            startButton.focus();
        }
    });
    sync();
})();
