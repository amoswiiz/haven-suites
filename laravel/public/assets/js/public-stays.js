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