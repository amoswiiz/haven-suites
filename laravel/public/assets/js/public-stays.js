(() => {
    'use strict';
    const publicHeader = document.querySelector('[data-public-header]');
    const menuToggle = publicHeader?.querySelector('[data-menu-toggle]');
    const publicMenu = publicHeader?.querySelector('[data-public-menu]');
    if (publicHeader && menuToggle && publicMenu && typeof window.matchMedia === 'function') {
        const compactHeader = window.matchMedia('(max-width: 900px)');
        const setMenu = (expanded, returnFocus = false) => {
            const open = compactHeader.matches && expanded;
            publicMenu.hidden = compactHeader.matches && !open;
            menuToggle.setAttribute('aria-expanded', String(open));
            publicHeader.classList.toggle('is-menu-open', open);
            if (returnFocus && compactHeader.matches) menuToggle.focus();
        };
        publicHeader.classList.add('is-menu-enhanced');
        menuToggle.hidden = false;
        setMenu(false);
        menuToggle.addEventListener('click', () => setMenu(menuToggle.getAttribute('aria-expanded') !== 'true'));
        compactHeader.addEventListener?.('change', () => setMenu(false, publicMenu.contains(document.activeElement)));
        publicMenu.addEventListener('click', event => {
            const link = event.target.closest?.('a');
            if (link && compactHeader.matches) setMenu(false, link.target === '_blank');
        });
        document.addEventListener('click', event => {
            if (!publicHeader.contains(event.target) && menuToggle.getAttribute('aria-expanded') === 'true') setMenu(false);
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') {
                event.preventDefault();
                setMenu(false, true);
            }
        });
    }
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
        const visibleMedia = new Set();
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) visibleMedia.add(entry.target);
                else visibleMedia.delete(entry.target);
            });
            mobileEnquire.hidden = visibleMedia.size > 0;
        }, { threshold: 0 });
        mobileEnquire.hidden = false;
        observer.observe(enquiry);
        document.querySelectorAll('.stay-listing-actions, .stay-media-actions, [data-video-player]').forEach(element => observer.observe(element));
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
    const videoPosters = new Map();
    document.querySelectorAll('[data-video-card]').forEach(card => {
        const poster = card.querySelector('[data-play-video]');
        if (poster) videoPosters.set(card, poster);
    });
    function stopVideos(except) {
        videoPosters.forEach((poster, card) => {
            if (card !== except && card.querySelector('iframe')) {
                card.querySelector('[data-video-player]').replaceChildren(poster);
                card.querySelector('[data-video-status]').textContent = 'Hosted on YouTube.';
            }
        });
    }
    document.querySelectorAll('[data-video-poster-fallback]').forEach(poster => {
        const fallback = () => {
            if (!poster.dataset.videoPosterFallback) return;
            const url = poster.dataset.videoPosterFallback;
            delete poster.dataset.videoPosterFallback;
            poster.src = url;
        };
        poster.addEventListener('error', fallback, { once: true });
        if (poster.complete && !poster.naturalWidth) fallback();
    });
    document.addEventListener('click', event => {
        const preview = event.target.closest?.('[data-play-video]');
        if (!preview || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        let url;
        try {
            url = new URL(preview.dataset.videoUrl);
            if (url.protocol !== 'https:' || url.hostname !== 'www.youtube-nocookie.com' || url.port || url.username || url.password || !/^\/embed\/[a-zA-Z0-9_-]{11}$/.test(url.pathname)) return;
        } catch (_) { return; }
        event.preventDefault();
        const card = preview.closest('[data-video-card]');
        const player = card?.querySelector('[data-video-player]');
        if (!player) return;
        stopVideos(card);
        url.searchParams.set('autoplay', '1');
        const frame = document.createElement('iframe');
        frame.src = url.href;
        frame.title = (preview.dataset.videoTitle || 'Apartment video tour') + ' — YouTube video';
        frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
        frame.allowFullscreen = true;
        frame.referrerPolicy = 'strict-origin-when-cross-origin';
        player.replaceChildren(frame);
        card.querySelector('[data-video-status]').textContent = 'Video player opened. If it cannot play, use Watch on YouTube.';
        frame.focus();
    });

    const dialog = document.querySelector('[data-photo-dialog]');
    const photoLinks = Array.from(document.querySelectorAll('[data-gallery-photo]'));
    const photoEntries = new Map();
    photoLinks.forEach(link => {
        const index = Number(link.dataset.photoIndex);
        if (Number.isInteger(index) && index >= 0 && !photoEntries.has(index)) {
            photoEntries.set(index, { url: link.href, caption: link.dataset.photoCaption || link.querySelector('img')?.alt || 'Apartment photo' });
        }
    });
    const photos = Array.from(photoEntries.entries()).sort((left, right) => left[0] - right[0]).map(entry => entry[1]);
    const collection = document.getElementById('all-photos');
    if (dialog && photos.length && typeof dialog.showModal === 'function') {
        let opener;
        let active = 0;
        let touchStart;
        const image = dialog.querySelector('[data-current-photo]');
        const caption = dialog.querySelector('[data-photo-caption]');
        const counter = dialog.querySelector('[data-photo-count]');
        const previous = dialog.querySelector('[data-photo-prev]');
        const next = dialog.querySelector('[data-photo-next]');
        const thumbnails = Array.from(dialog.querySelectorAll('[data-photo-go]'));
        const show = index => {
            active = (index + photos.length) % photos.length;
            image.src = photos[active].url;
            image.alt = photos[active].caption;
            caption.textContent = photos[active].caption;
            counter.textContent = 'Photo ' + (active + 1) + ' of ' + photos.length;
            thumbnails.forEach((button, position) => {
                if (position === active) button.setAttribute('aria-current', 'true');
                else button.removeAttribute('aria-current');
            });
            thumbnails[active]?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            previous.disabled = next.disabled = photos.length === 1;
        };
        const open = (index, trigger) => {
            opener = trigger;
            stopVideos();
            dialog.showModal();
            document.body.classList.add('stay-media-open');
            show(index);
            dialog.querySelector('[data-close-photo]').focus();
        };
        photoLinks.forEach(link => link.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault();
            open(Number(link.dataset.photoIndex), link);
        }));
        document.querySelector('[data-open-gallery]')?.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault();
            open(0, event.currentTarget);
        });
        dialog.querySelector('[data-close-photo]').addEventListener('click', () => dialog.close());
        previous.addEventListener('click', () => show(active - 1));
        next.addEventListener('click', () => show(active + 1));
        thumbnails.forEach(button => button.addEventListener('click', () => show(Number(button.dataset.photoGo))));
        dialog.addEventListener('keydown', event => {
            const movement = { ArrowLeft: active - 1, ArrowRight: active + 1, Home: 0, End: photos.length - 1 };
            if (Object.prototype.hasOwnProperty.call(movement, event.key)) {
                event.preventDefault();
                show(movement[event.key]);
            }
        });
        dialog.addEventListener('click', event => {
            if (event.target !== dialog) return;
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
        });
        image.addEventListener('touchstart', event => {
            touchStart = event.touches.length === 1 ? { x: event.touches[0].clientX, y: event.touches[0].clientY } : null;
        }, { passive: true });
        image.addEventListener('touchend', event => {
            if (!touchStart || event.changedTouches.length !== 1) return;
            const x = event.changedTouches[0].clientX - touchStart.x;
            const y = event.changedTouches[0].clientY - touchStart.y;
            if (Math.abs(x) > 50 && Math.abs(x) > Math.abs(y) * 1.3) show(active + (x < 0 ? 1 : -1));
            touchStart = null;
        }, { passive: true });
        dialog.addEventListener('close', () => {
            document.body.classList.remove('stay-media-open');
            opener?.focus({ preventScroll: true });
        });
    } else {
        document.querySelector('[data-open-gallery]')?.addEventListener('click', () => { if (collection) collection.open = true; });
    }
})();

(() => {
    'use strict';
    const source = document.querySelector('[data-copy-source]');
    const button = document.querySelector('[data-copy-button]');
    const status = document.querySelector('[data-copy-status]');
    if (!source || !button || !navigator.clipboard || !window.isSecureContext) {
        return;
    }
    button.hidden = false;
    button.addEventListener('click', () => {
        navigator.clipboard.writeText(source.textContent.trim()).then(() => {
            if (status) {
                status.textContent = 'Reference copied.';
            }
        }, () => {
            if (status) {
                status.textContent = 'Copy did not work. Please write the reference down.';
            }
        });
    });
})();
(() => {
    'use strict';
    const menu = document.querySelector('[data-destinations-menu]');
    if (!menu) {
        return;
    }
    document.addEventListener('click', (event) => {
        if (menu.open && !menu.contains(event.target)) {
            menu.open = false;
        }
    });
    menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.open) {
            menu.open = false;
            menu.querySelector('summary')?.focus();
        }
    });
})();
(() => {
    'use strict';
    const buttons = document.querySelectorAll('[data-whatsapp-enquiry]');
    const checkIn = document.getElementById('enquiry-check-in');
    const checkOut = document.getElementById('enquiry-check-out');
    const guests = document.getElementById('enquiry-guests');
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
        buttons.forEach((button) => {
            const number = button.dataset.waNumber;
            const base = button.dataset.waBase;
            if (!number || !base) {
                return;
            }
            const lines = [base];
            if (button.dataset.waCode) {
                lines.push(`Code: ${button.dataset.waCode}`);
            }
            if (start && end && end > start) {
                const nights = Math.round((end - start) / 86400000);
                lines.push(`Dates: ${label(start)} – ${label(end)} (${nights} night${nights === 1 ? '' : 's'})`);
            } else if (start) {
                lines.push(`Check-in: ${label(start)}`);
            }
            if (people > 0) {
                lines.push(`Guests: ${people}`);
            }
            button.href = `https://wa.me/${number}?text=${encodeURIComponent(lines.join('\n'))}`;
        });
    };
    [checkIn, checkOut, guests].forEach((field) => {
        field?.addEventListener('input', update);
        field?.addEventListener('change', update);
    });
    update();
})();
// Live price estimate from the chosen dates, using the listing's nightly price, cleaning fee and long-stay discounts.
(() => {
    'use strict';
    const form = document.querySelector('[data-enquiry-form][data-nightly]');
    const box = document.querySelector('[data-price-estimate]');
    if (!form || !box || !form.dataset.nightly) {
        return;
    }
    const nightly = Number(form.dataset.nightly);
    const cleaning = Number(form.dataset.cleaning || 0);
    const weekly = Number(form.dataset.weekly || 0);
    const monthly = Number(form.dataset.monthly || 0);
    const weekend = Number(form.dataset.weekend || 0);
    let seasons = [];
    try { seasons = JSON.parse(form.dataset.seasons || '[]'); } catch { seasons = []; }
    const currency = form.dataset.currency || '';
    const minNights = Number(form.dataset.minNights || 1);
    const checkIn = form.querySelector('[name="check_in"]');
    const checkOut = form.querySelector('[name="check_out"]');
    const money = (value) => `${currency} ${Math.round(value).toLocaleString('en-KE')}`;
    const add = (list, label, value, className = '') => {
        const term = document.createElement('dt');
        const detail = document.createElement('dd');
        term.textContent = label;
        detail.textContent = value;
        if (className) {
            term.className = className;
            detail.className = className;
        }
        list.append(term, detail);
    };
    const update = () => {
        const start = checkIn?.value ? new Date(`${checkIn.value}T00:00:00`) : null;
        const end = checkOut?.value ? new Date(`${checkOut.value}T00:00:00`) : null;
        const nights = start && end ? Math.round((end - start) / 86400000) : 0;
        if (!Number.isFinite(nightly) || nightly <= 0 || nights < 1) {
            box.hidden = true;
            box.replaceChildren();
            return;
        }
        // Price each night: a seasonal price first, then the weekend price on Friday and Saturday nights.
        const nightPrices = [];
        for (let night = 0; night < nights; night++) {
            const date = new Date(start.getTime() + night * 86400000);
            const day = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
            const season = seasons.find(([from, to]) => day >= from && day <= to);
            nightPrices.push(season ? Number(season[2]) : (weekend > 0 && (date.getDay() === 5 || date.getDay() === 6) ? weekend : nightly));
        }
        const base = nightPrices.reduce((total, price) => total + price, 0);
        const percent = nights >= 28 && monthly > 0 ? monthly : (nights >= 7 && weekly > 0 ? weekly : 0);
        const saving = base * percent / 100;
        const list = document.createElement('dl');
        if (nightPrices.every((price) => price === nightly)) {
            add(list, `${money(nightly)} × ${nights} night${nights === 1 ? '' : 's'}`, money(base));
        } else {
            add(list, `${nights} night${nights === 1 ? '' : 's'} (average ${money(base / nights)} a night)`, money(base));
        }
        if (saving > 0) {
            add(list, `${nights >= 28 && monthly > 0 ? 'Monthly' : 'Weekly'} discount (${percent}%)`, `− ${money(saving)}`, 'is-saving');
        }
        if (cleaning > 0) {
            add(list, 'Cleaning fee', money(cleaning));
        }
        add(list, 'Estimated total', money(base - saving + cleaning), 'is-total');
        const note = document.createElement('small');
        note.textContent = nights < minNights
            ? `This apartment has a ${minNights}-night minimum stay.`
            : 'Estimate only. Our team confirms availability and the final price on your quote.';
        box.replaceChildren(list, note);
        box.hidden = false;
    };
    [checkIn, checkOut].forEach((field) => {
        field?.addEventListener('input', update);
        field?.addEventListener('change', update);
    });
    update();
})();

// Share a listing with the phone's share sheet when available, falling back to WhatsApp.
(() => {
    'use strict';
    document.querySelectorAll('[data-share-url]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (typeof navigator.share !== 'function') {
                return;
            }
            event.preventDefault();
            navigator.share({ title: link.dataset.shareTitle, url: link.dataset.shareUrl }).catch(() => {});
        });
    });
})();

/* Sticky enquiry bar: shown on phones once the main contact buttons scroll away, hidden while the form is visible. */
(() => {
    const bar = document.querySelector('[data-mobile-bar]');
    const actions = document.querySelector('[data-quick-contact]');
    if (!bar || !actions || !('IntersectionObserver' in window)) return;
    const form = document.getElementById('enquire');
    let actionsVisible = true;
    let formVisible = false;
    bar.hidden = false;
    document.body.classList.add('has-mobile-bar');
    const update = () => bar.classList.toggle('is-visible', !actionsVisible && !formVisible);
    new IntersectionObserver(([entry]) => { actionsVisible = entry.isIntersecting; update(); }).observe(actions);
    if (form) new IntersectionObserver(([entry]) => { formVisible = entry.isIntersecting; update(); }, { rootMargin: '0px 0px -20% 0px' }).observe(form);
})();

/* Listing photo slider: swipe on phones, arrows, keyboard and thumbnails on larger screens. Tapping a photo still opens the full-screen viewer. */
(() => {
    const slider = document.querySelector('[data-photo-slider]');
    if (!slider) return;
    const track = slider.querySelector('[data-slider-track]');
    const slides = Array.from(track.querySelectorAll('[data-slide]'));
    const previous = slider.querySelector('[data-slider-prev]');
    const next = slider.querySelector('[data-slider-next]');
    const count = slider.querySelector('[data-slider-count]');
    const caption = slider.querySelector('[data-slider-caption]');
    const thumbs = Array.from(document.querySelectorAll('[data-slider-thumbs] [data-slide-go]'));
    let current = 0;
    const goTo = (index) => {
        const target = Math.max(0, Math.min(slides.length - 1, index));
        track.scrollTo({ left: slides[target].offsetLeft - track.offsetLeft, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    };
    const update = (index) => {
        if (index === current && count?.dataset.ready) return;
        current = index;
        if (count) { count.textContent = (index + 1) + ' / ' + slides.length; count.dataset.ready = '1'; }
        if (caption) caption.textContent = slides[index].dataset.photoCaption || '';
        if (previous) previous.disabled = index === 0;
        if (next) next.disabled = index === slides.length - 1;
        thumbs.forEach((thumb, position) => {
            if (position === index) { thumb.setAttribute('aria-current', 'true'); thumb.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
            else thumb.removeAttribute('aria-current');
        });
    };
    let frame;
    track.addEventListener('scroll', () => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => update(Math.round(track.scrollLeft / Math.max(1, track.clientWidth))));
    }, { passive: true });
    previous?.addEventListener('click', () => goTo(current - 1));
    next?.addEventListener('click', () => goTo(current + 1));
    thumbs.forEach((thumb) => thumb.addEventListener('click', () => goTo(Number(thumb.dataset.slideGo))));
    track.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowRight') { event.preventDefault(); goTo(current + 1); }
        if (event.key === 'ArrowLeft') { event.preventDefault(); goTo(current - 1); }
    });
    if (previous) previous.hidden = false;
    if (next) next.hidden = false;
    update(0);
})();

/* Listing cards: swipe through up to five photos; arrows on devices with a mouse. */
(() => {
    document.querySelectorAll('[data-card-slider]').forEach((slider) => {
        const track = slider.querySelector('[data-card-track]');
        const slides = track ? Array.from(track.children) : [];
        if (slides.length < 2) return;
        const previous = slider.querySelector('[data-card-prev]');
        const next = slider.querySelector('[data-card-next]');
        const dots = Array.from(slider.querySelectorAll('.stay-card-dots i'));
        let current = 0;
        const sync = () => {
            current = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
            dots.forEach((dot, index) => dot.classList.toggle('is-active', index === current));
            if (previous) previous.disabled = current === 0;
            if (next) next.disabled = current === slides.length - 1;
        };
        const go = (step) => track.scrollTo({ left: (current + step) * track.clientWidth, behavior: 'smooth' });
        previous?.addEventListener('click', (event) => { event.preventDefault(); go(-1); });
        next?.addEventListener('click', (event) => { event.preventDefault(); go(1); });
        track.addEventListener('scroll', () => requestAnimationFrame(sync), { passive: true });
        if (previous) previous.hidden = false;
        if (next) next.hidden = false;
        sync();
    });
})();

/* 360° tour: the outside service loads only when the guest asks for it. */
(() => {
    document.querySelectorAll('[data-virtual-tour]').forEach((holder) => {
        holder.querySelector('[data-open-tour]')?.addEventListener('click', () => {
            const frame = document.createElement('iframe');
            frame.src = holder.dataset.tourUrl;
            frame.title = '360° virtual tour';
            frame.allow = 'fullscreen; xr-spatial-tracking; gyroscope; accelerometer';
            frame.allowFullscreen = true;
            frame.referrerPolicy = 'strict-origin-when-cross-origin';
            holder.replaceChildren(frame);
            frame.focus();
        });
    });
})();

/* Filter drawer (new homepage): closes with the × button, the backdrop or Escape, and locks page scrolling while open. */
(() => {
    const drawer = document.querySelector('[data-filter-drawer]');
    if (!drawer) return;
    const panel = drawer.querySelector('.stay-drawer-panel');
    const close = () => { drawer.open = false; };
    drawer.addEventListener('toggle', () => {
        document.body.classList.toggle('stay-drawer-open', drawer.open);
        if (drawer.open) panel.querySelector('input, select, button')?.focus();
        else drawer.querySelector('summary')?.focus();
    });
    drawer.querySelector('[data-close-drawer]')?.addEventListener('click', close);
    drawer.addEventListener('click', (event) => { if (drawer.open && !panel.contains(event.target) && event.target !== drawer.querySelector('summary') && !drawer.querySelector('summary').contains(event.target)) close(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && drawer.open) close(); });
})();

/* Saved stays (♡): kept only in this browser. The Saved page receives the list as ?stays=slug,slug. */
(() => {
    const key = 'haven.saved-stays';
    const read = () => { try { const list = JSON.parse(localStorage.getItem(key) || '[]'); return Array.isArray(list) ? list.filter((slug) => /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)).slice(0, 50) : []; } catch { return []; } };
    const write = (list) => { try { localStorage.setItem(key, JSON.stringify(list)); } catch { /* Private browsing: saving is skipped. */ } };
    const buttons = Array.from(document.querySelectorAll('[data-save-stay]'));
    const link = document.querySelector('[data-saved-link]');
    const count = document.querySelector('[data-saved-count]');
    const refresh = () => {
        const saved = read();
        buttons.forEach((button) => {
            const on = saved.includes(button.dataset.saveStay);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            const name = button.getAttribute('aria-label').replace(/^(Save|Remove) /, '').replace(/ from saved$/, '');
            button.setAttribute('aria-label', on ? `Remove ${name} from saved` : `Save ${name}`);
            if (button.dataset.saveLabel !== undefined) button.querySelector('[data-save-text]').textContent = on ? 'Saved' : 'Save';
        });
        if (link) link.href = link.href.split('?')[0] + (saved.length ? '?stays=' + saved.join(',') : '');
        if (count) { count.textContent = saved.length; count.hidden = saved.length === 0; }
    };
    buttons.forEach((button) => {
        button.hidden = false;
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const saved = read();
            const slug = button.dataset.saveStay;
            write(saved.includes(slug) ? saved.filter((item) => item !== slug) : [slug, ...saved]);
            refresh();
        });
    });
    const page = document.querySelector('[data-saved-page]');
    if (page) {
        const saved = read();
        const current = new URLSearchParams(location.search).get('stays') || '';
        if (saved.join(',') !== current && (saved.length || current)) {
            location.replace(page.dataset.savedBase + (saved.length ? '?stays=' + saved.join(',') : ''));
            return;
        }
    }
    refresh();
})();

/* Map view: loads the map library and map images only when the guest asks. Pins show each stay's area. */
(() => {
    const toggle = document.querySelector('[data-map-toggle]');
    const view = document.querySelector('[data-map-view]');
    const cards = Array.from(document.querySelectorAll('[data-stay-card][data-map-lat]'));
    if (!toggle || !view || !cards.length) return;
    toggle.hidden = false;
    let map;
    const load = () => new Promise((resolve, reject) => {
        if (window.L) { resolve(); return; }
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = view.dataset.leafletCss;
        document.head.append(css);
        const script = document.createElement('script');
        script.src = view.dataset.leafletJs;
        script.onload = resolve;
        script.onerror = reject;
        document.head.append(script);
    });
    const draw = () => {
        const canvas = view.querySelector('[data-map-canvas]');
        map = window.L.map(canvas, { scrollWheelZoom: false });
        window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(map);
        const points = cards.map((card) => {
            const point = [Number(card.dataset.mapLat), Number(card.dataset.mapLng)];
            const pin = document.createElement('a');
            pin.className = 'stay-map-pin';
            pin.href = card.dataset.mapUrl;
            pin.textContent = card.dataset.mapPrice || card.dataset.mapTitle;
            pin.setAttribute('aria-label', card.dataset.mapTitle + (card.dataset.mapPrice ? ', ' + card.dataset.mapPrice + ' a night' : ''));
            pin.title = card.dataset.mapTitle;
            window.L.marker(point, { icon: window.L.divIcon({ className: '', html: pin.outerHTML, iconSize: null }), keyboard: false }).addTo(map);
            return point;
        });
        map.fitBounds(points, { padding: [40, 40], maxZoom: 14 });
    };
    toggle.addEventListener('click', async () => {
        const showing = view.hidden;
        view.hidden = !showing;
        toggle.setAttribute('aria-pressed', showing ? 'true' : 'false');
        toggle.querySelector('[data-map-label]').textContent = showing ? 'Hide map' : 'Map';
        if (!showing) return;
        try {
            await load();
            if (!map) draw(); else map.invalidateSize();
            view.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } catch {
            view.querySelector('[data-map-canvas]').textContent = 'The map could not load. Check your connection and try again.';
        }
    });
})();

(() => {
    'use strict';
    // Article sidebar contents: highlight the section being read.
    const nav = document.querySelector('[data-post-contents]');
    if (!nav || typeof IntersectionObserver !== 'function') return;
    const links = new Map([...nav.querySelectorAll('a[href^="#"]')].map((link) => [decodeURIComponent(link.hash.slice(1)), link]));
    const headings = [...links.keys()].map((id) => document.getElementById(id)).filter(Boolean);
    const visible = new Set();
    const mark = () => {
        const current = headings.filter((heading) => visible.has(heading.id)).at(0)
            || [...headings].reverse().find((heading) => heading.getBoundingClientRect().top < 120);
        links.forEach((link, id) => {
            const active = current?.id === id;
            link.classList.toggle('is-current', active);
            if (active) link.setAttribute('aria-current', 'location'); else link.removeAttribute('aria-current');
        });
    };
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => { if (entry.isIntersecting) visible.add(entry.target.id); else visible.delete(entry.target.id); });
        mark();
    }, { rootMargin: '0px 0px -60% 0px' });
    headings.forEach((heading) => observer.observe(heading));
})();
