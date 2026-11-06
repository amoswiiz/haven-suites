(() => {
    'use strict';
    const sort = document.querySelector('[data-sort-stays]');
    sort?.addEventListener('change', () => {
        const form = document.getElementById('stay-search-form');
        if (form) form.requestSubmit();
    });
    const searchStart = document.getElementById('search-check-in');
    const searchEnd = document.getElementById('search-check-out');
    const enquiryStart = document.getElementById('enquiry-check-in');
    const enquiryEnd = document.getElementById('enquiry-check-out');
    [[searchStart, searchEnd], [enquiryStart, enquiryEnd]].forEach(([start, end]) => {
        if (!start || !end) return;
        const updateMinimum = () => {
            if (!start.value) return;
            const day = new Date(start.value + 'T12:00:00Z');
            if (!Number.isFinite(day.getTime())) return;
            const minimum = start.closest('[data-enquiry-form]')?.dataset.minNights;
            day.setUTCDate(day.getUTCDate() + Math.max(1, Number(minimum) || 1));
            end.min = day.toISOString().slice(0, 10);
        };
        start.addEventListener('change', updateMinimum);
        updateMinimum();
    });
    const enquiry = document.querySelector('[data-enquiry-form]');
    const originalStatus = enquiry?.querySelector('[data-enquiry-status]')?.textContent;
    const mobileEnquire = document.querySelector('[data-mobile-enquire]');
    if (enquiry && mobileEnquire && 'IntersectionObserver' in window) {
        const observer = new IntersectionObserver(entries => {
            mobileEnquire.hidden = entries[0].isIntersecting;
        }, { threshold: 0 });
        observer.observe(enquiry);
    }
    enquiry?.addEventListener('submit', () => {
        enquiry.querySelector('button[type="submit"]').disabled = true;
        enquiry.querySelector('[data-enquiry-status]').textContent = 'Sending your enquiry. Please wait a moment…';
    });
    window.addEventListener('pageshow', () => {
        if (!enquiry) return;
        enquiry.querySelector('button[type="submit"]').disabled = false;
        enquiry.querySelector('[data-enquiry-status]').textContent = originalStatus;
    });
    const calendar = document.querySelector('[data-stay-calendar]');
    if (calendar?.dataset.calendarUrl) {
        const label = calendar.querySelector('[data-calendar-label]');
        const grid = calendar.querySelector('[data-calendar-grid]');
        const previous = calendar.querySelector('[data-calendar-prev]');
        const next = calendar.querySelector('[data-calendar-next]');
        const status = calendar.querySelector('[data-calendar-status]');
        let month = calendar.dataset.month;
        let loading = false;
        const minimum = calendar.dataset.today.slice(0, 7);
        const maximum = calendar.dataset.maximum;
        function buttons() {
            previous.disabled = loading || month <= minimum;
            next.disabled = loading || month >= maximum;
        }
        function draw(current, ranges) {
            const first = new Date(current + '-01T12:00:00Z');
            const offset = (first.getUTCDay() + 6) % 7;
            const count = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).getUTCDate();
            const fragment = document.createDocumentFragment();
            let row;
            for (let index = 0; index < Math.ceil((offset + count) / 7) * 7; index++) {
                if (index % 7 === 0) { row = document.createElement('tr'); fragment.append(row); }
                const cell = document.createElement('td');
                const number = index - offset + 1;
                if (number < 1 || number > count) {
                    cell.className = 'empty';
                } else {
                    const date = current + '-' + String(number).padStart(2, '0');
                    const past = date < calendar.dataset.today;
                    const blocked = ranges.some(range => date >= range.start && date < range.end);
                    cell.className = past ? 'past' : blocked ? 'blocked' : 'available';
                    cell.textContent = String(number);
                    cell.setAttribute('aria-label', date + ': ' + (past ? 'Past date' : blocked ? 'Unavailable night' : 'No recorded block'));
                }
                row.append(cell);
            }
            grid.replaceChildren(fragment);
            label.textContent = first.toLocaleDateString('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' });
        }
        async function move(delta) {
            if (loading) return;
            const first = new Date(month + '-01T12:00:00Z');
            first.setUTCMonth(first.getUTCMonth() + delta);
            const selected = first.toISOString().slice(0, 7);
            if (selected < minimum || selected > maximum) return;
            const end = new Date(first);
            end.setUTCMonth(end.getUTCMonth() + 1);
            loading = true;
            buttons();
            status.textContent = 'Checking the calendar…';
            const abort = new AbortController();
            const timeout = window.setTimeout(() => abort.abort(), 12000);
            try {
                const url = new URL(calendar.dataset.calendarUrl, window.location.origin);
                url.searchParams.set('from', selected + '-01');
                url.searchParams.set('to', end.toISOString().slice(0, 10));
                const response = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', signal: abort.signal });
                if (!response.ok) throw new Error('Calendar unavailable');
                const data = await response.json();
                if (!Array.isArray(data.blocked) || data.blocked.some(range => !/^\d{4}-\d{2}-\d{2}$/.test(range.start) || !/^\d{4}-\d{2}-\d{2}$/.test(range.end))) throw new Error('Calendar unavailable');
                draw(selected, data.blocked);
                month = selected;
                status.textContent = data.fresh ? 'Availability is subject to confirmation. Check-out dates do not count as an additional night.' : (calendar.dataset.unconfirmedMessage || 'The external calendar needs confirmation. Our team will verify your dates.');
            } catch (_) {
                status.textContent = 'We could not refresh the calendar. Your current calendar is still shown; please enquire so our team can confirm your dates.';
            } finally {
                window.clearTimeout(timeout);
                loading = false;
                buttons();
            }
        }
        previous.addEventListener('click', () => move(-1));
        next.addEventListener('click', () => move(1));
        buttons();
    }
    const dialog = document.querySelector('[data-photo-dialog]');
    if (dialog && typeof dialog.showModal === 'function') {
        let opener;
        document.querySelectorAll('[data-gallery-photo]').forEach(link => link.addEventListener('click', event => {
            event.preventDefault();
            opener = link;
            const photo = link.querySelector('img');
            const target = dialog.querySelector('img');
            target.src = link.href;
            target.alt = photo?.alt || 'Apartment photo';
            dialog.showModal();
            dialog.querySelector('button').focus();
        }));
        dialog.querySelector('[data-close-photo]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
        dialog.addEventListener('close', () => opener?.focus());
    }
})();
