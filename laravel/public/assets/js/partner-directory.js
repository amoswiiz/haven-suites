(() => {
    'use strict';
    const buttons = document.querySelectorAll('[data-owner-request]');
    const forms = document.querySelectorAll('[data-owner-request-form]');
    const checkIn = document.getElementById('partner-check-in');
    const checkOut = document.getElementById('partner-check-out');
    const guests = document.getElementById('partner-guests');
    const reminder = document.querySelector('[data-dates-reminder]');
    if ((!buttons.length && !forms.length) || !checkIn || !checkOut) {
        return;
    }
    // Partner requests are created on the server, so keep each form's dates and guests in step with the search.
    const syncForms = () => {
        const start = parse(checkIn.value);
        const end = parse(checkOut.value);
        forms.forEach((form) => {
            form.elements.check_in.value = start ? checkIn.value : '';
            form.elements.check_out.value = start && end && end > start ? checkOut.value : '';
            form.elements.guests.value = Number.parseInt(guests?.value || '', 10) > 0 ? guests.value : '';
        });
    };
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const parse = (value) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) {
            return null;
        }
        const date = new Date(value + 'T12:00:00Z');
        return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value ? date : null;
    };
    const label = (date) => `${days[date.getUTCDay()]} ${date.getUTCDate()} ${months[date.getUTCMonth()]} ${date.getUTCFullYear()}`;
    const update = () => {
        const start = parse(checkIn.value);
        const end = parse(checkOut.value);
        const people = Number.parseInt(guests?.value || '', 10);
        const validEnd = start && end && end > start ? end : (!start && end ? end : null);
        const nights = start && validEnd ? Math.round((validEnd - start) / 86400000) : null;
        const lines = [
            `Check-in: ${start ? label(start) : 'to be confirmed'}`,
            `Check-out: ${validEnd ? label(validEnd) : 'to be confirmed'}`,
            `Nights: ${nights ?? 'to be confirmed'}`,
        ];
        if (people > 0) {
            lines.push(`Guests: ${people}`);
        }
        if (reminder) {
            reminder.hidden = Boolean(start && nights);
        }
        syncForms();
        buttons.forEach((button) => {
            const { waNumber, waGreeting, waLink } = button.dataset;
            if (!waNumber || !waGreeting) {
                return;
            }
            const message = [waGreeting, ...lines, ...(waLink ? [`Listing: ${waLink}`] : [])].join('\n');
            button.href = `https://wa.me/${waNumber}?text=${encodeURIComponent(message)}`;
        });
    };
    [checkIn, checkOut, guests].forEach((field) => {
        field?.addEventListener('input', update);
        field?.addEventListener('change', update);
    });
    update();
})();
