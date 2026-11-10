(() => {
    'use strict';
    const buttons = document.querySelectorAll('[data-owner-request]');
    const checkIn = document.getElementById('partner-check-in');
    const checkOut = document.getElementById('partner-check-out');
    const guests = document.getElementById('partner-guests');
    const reminder = document.querySelector('[data-dates-reminder]');
    if (!buttons.length || !checkIn || !checkOut) {
        return;
    }
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
        const lines = [];
        if (start && end && end > start) {
            const nights = Math.round((end - start) / 86400000);
            lines.push(`Dates: ${label(start)} – ${label(end)} (${nights} night${nights === 1 ? '' : 's'})`);
        } else if (start) {
            lines.push(`Check-in: ${label(start)}`);
        } else {
            lines.push('Dates: to be confirmed');
        }
        if (people > 0) {
            lines.push(`Guests: ${people}`);
        }
        if (reminder) {
            reminder.hidden = Boolean(start);
        }
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
