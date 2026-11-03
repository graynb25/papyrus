/*
 * The reader script. Plain JavaScript.
 *
 * Note that Alpine *is* loaded here: partials.head pulls in Flux, which
 * pulls in Livewire, which bundles Alpine. Nothing in this file uses it.
 * That is a choice, not an accident of the build, and
 * ReadPathTest::test_the_reader_uses_no_framework_directives holds the
 * line.
 *
 * Two reasons, in order of weight.
 *
 * The first is that the previous version of this file used Alpine
 * directives and they did not take effect. Measured: the pages rendered,
 * the counter came out empty, and nothing errored. Rather than debug a
 * framework that has no other job here, the script was rewritten, and the
 * behaviour was then verified end to end in a real browser.
 *
 * The second is that the work is not reactive. It is "which image is
 * nearest the middle of the viewport", "post progress once", "translate a
 * track", and the state is five numbers. A component tree is the wrong
 * shape for that.
 *
 * What it owns: which page is being read, the debounced progress post, the
 * vertical to paged transition, and the settings write. It does not own the
 * scroll, the images, or the zoom.
 */
export function bootReader(config) {
    'use strict';

    var settings = config.settings;

    var body = document.body;

    /*
     * The remember checkbox, resolved once. Held as a variable because the
     * filter code reads it from three places and each one re-querying would
     * be three chances to get the selector subtly wrong.
     */
    var rememberInput = document.querySelector('[data-filter-remember]');
    var pages = document.getElementById('pages');
    var pagedTrack = document.getElementById('paged-track');
    var counterCurrent = document.querySelector('[data-reader-current]');
    var counterTotal = document.querySelector('[data-reader-total]');
    var toggle = document.querySelector('.reader-toggle');
    var settingsToggle = document.querySelector('.reader-settings-toggle');
    var panel = document.querySelector('[data-reader-panel]');
    var zonePrevious = document.querySelector('.tap-zone-prev');
    var zoneNext = document.querySelector('.tap-zone-next');

    /*
     * The tap zones mean different things per mode, so their labels are
     * captured before either mode rewrites them. A screen reader and
     * anyone hovering has to be able to tell a page turn from a chapter
     * jump, and the markup only knows the chapter case.
     */
    var chapterPreviousLabel = zonePrevious === null ? '' : zonePrevious.getAttribute('aria-label');
    var chapterNextLabel = zoneNext === null ? '' : zoneNext.getAttribute('aria-label');

    var PAGE_PREVIOUS = config.strings.previousPage;
    var PAGE_NEXT = config.strings.nextPage;

    var total = config.total;
    var resumeAt = Math.min(config.resumeAt, Math.max(0, total - 1));

    /*
     * The vertical images, held once.
     *
     * Measured per frame rather than re-queried, because a querySelectorAll
     * inside a scroll handler allocates a fresh NodeList sixty times a
     * second for no reason.
     */
    var pageImages = pages === null
        ? []
        : Array.prototype.slice.call(pages.querySelectorAll('img[data-page]'));

    /*
     * `highest` is the furthest page reached and is what progress records.
     * `current` is what is on screen and moves both ways. Keeping them apart
     * is what stops a reader scrolling back to re-read a page from losing
     * their place.
     */
    var state = {
        /*
         * The resolved filter values, so the script and the first paint agree
         * without a round trip. Clamped by the server already, which is why
         * brightness is never outside 0.2 to 2 here.
         */
        filters: {
            brightness: settings.brightness,
            contrast: settings.contrast,
            hue: settings.hue,
            invert: settings.invert,
            grayscale: settings.grayscale
        },

        mode: settings.mode,
        current: resumeAt,
        highest: resumeAt,
        posted: resumeAt,
        pagedIndex: resumeAt,
        timer: null,
        ready: false
    };

    if (counterTotal !== null) {
        counterTotal.textContent = String(total);
    }

    // ---------------------------------------------------------- settings

    /*
     * The settings panel.
     *
     * Every control writes through the same path: change the local value,
     * repaint, then save. The panel holds the reader's own preferences for
     * this series and nowhere else, which is why it saves on change rather
     * than on a form submit. There is no submit button because a reader
     * closing the panel expects the change to have stuck.
     */
    function openPanel() {
        panel.hidden = false;
        settingsToggle.setAttribute('aria-expanded', 'true');
    }

    function closePanel() {
        panel.hidden = true;
        settingsToggle.setAttribute('aria-expanded', 'false');
    }

    function togglePanel() {
        if (panel.hidden) {
            // Re-read the settings on every open, not once at startup.
            // The mode buttons and the swatches render their selected
            // state from `settings`, so a panel opened after a mode change
            // would otherwise show the state it had when the page loaded.
            syncPanel();
            openPanel();
        } else {
            closePanel();
        }
    }

    /*
     * Repaint the controls to match the settings.
     *
     * Written as a loop over `data-setting` rather than a handler per
     * control. The alternative is nine listeners that each have to know
     * which output element to update and how to format its value, and a
     * tenth control added later would silently do nothing.
     */
    function syncPanel() {
        var controls = panel.querySelectorAll('[data-setting]');

        for (var i = 0; i < controls.length; i++) {
            var control = controls[i];
            var key = control.dataset.setting;
            var value = key === 'mode' ? state.mode : settings[key];

            if (control.type === 'checkbox') {
                control.checked = Boolean(value);
                continue;
            }

            if (control.tagName === 'BUTTON') {
                control.setAttribute('aria-pressed', control.dataset.value === String(value) ? 'true' : 'false');
                continue;
            }

            control.value = String(value);

            var output = panel.querySelector('[data-output="' + key + '"]');

            if (output !== null) {
                output.textContent = format(key, value);
            }
        }
    }

    function format(key, value) {
        if (key === 'width') {
            return String(value) + '%';
        }

        if (key === 'gap') {
            return String(value) + 'px';
        }

        if (key === 'zoom') {
            return (Math.round(Number(value) * 100) / 100) + '×';
        }

        return String(value);
    }

    /*
     * One handler for every control in the panel.
     *
     * Ranges report continuously, so the value is applied and repainted
     * immediately but the write is debounced inside save(). Buttons and
     * checkboxes report once and go through the same path, which is why
     * there is no separate "commit" step anywhere.
     *
     * The caller picks the event, not this function. Buttons do not fire
     * `change`, so the wiring below routes clicks and changes here
     * separately rather than making this guess from the event type.
     */
    function onPanelChange(event) {
        var control = event.target.closest('[data-setting]');

        if (control === null) {
            return;
        }

        var key = control.dataset.setting;
        var value;

        if (control.tagName === 'BUTTON') {
            // Already selected, so nothing to do.
            if (control.getAttribute('aria-pressed') === 'true') {
                return;
            }

            control.setAttribute('aria-pressed', 'true');
            value = control.dataset.value;
        } else if (control.type === 'checkbox') {
            value = control.checked;
        } else {
            value = control.type === 'range' ? Number(control.value) : control.value;
        }

        if (key === 'mode') {
            /*
             * The mode buttons go through setMode so entering or leaving
             * paged mode moves the reading position exactly once, in one
             * place. Setting state.mode from here would leave the reader
             * on a stale page.
             */
            if (value !== state.mode) {
                setMode(value);
            }

            return;
        }

        settings[key] = value;

        applySettings();
        syncPanel();
        save();
    }

    /*
     * Change mode, keeping the reader on the page they were looking at.
     *
     * Split out of cycleMode so the panel and the corner button are the
     * same operation rather than two that can disagree.
     */
    function setMode(mode) {
        var wasPaged = state.mode === 'paged';

        state.mode = mode;

        if (mode === 'paged') {
            state.pagedIndex = state.current;
            scrollPaged();
        } else if (wasPaged) {
            // Leaving paged back at the page, not at the top of the chapter.
            var target = pages === null
                ? null
                : pages.querySelector('img[data-page="' + state.current + '"]');

            if (target !== null) {
                target.scrollIntoView({ block: 'start' });
            }
        }

        body.classList.toggle('reader-visible', mode === 'paged');
        describeZones();

        applySettings();
        syncPanel();
        onScroll();
        save();
    }

    /*
     * Push the resolved settings into the document.
     *
     * The server has already written them as custom properties and data
     * attributes, so the first paint is correct before this runs. This
     * exists so a change made in this session survives a mode switch
     * without waiting for the write to come back from the server.
     */
    function applySettings() {
        var root = document.documentElement;

        root.style.setProperty('--reader-bg', settings.background);
        root.style.setProperty('--reader-width', settings.width + '%');
        root.style.setProperty('--reader-gap', settings.gap + 'px');
        root.style.setProperty('--reader-zoom', String(settings.zoom));

        body.dataset.mode = state.mode;
        body.dataset.longStrip = String(Boolean(settings.long_strip));
        body.dataset.fitHeight = String(Boolean(settings.fit_height));
        body.dataset.hideTapZones = String(Boolean(settings.hide_tap_zones));

        if (pages !== null) {
            pages.classList.toggle('zoomed', settings.zoom > 1);
        }
    }

    var saveTimer = null;

    /*
     * Persist the settings for this series.
     *
     * Debounced, because the only frequent caller is the zoom control and
     * a reader holding a key would otherwise write on every increment.
     */
    function save() {
        if (saveTimer !== null) {
            clearTimeout(saveTimer);
        }

        saveTimer = setTimeout(function () {
            saveTimer = null;

            fetch(config.settingsUrl, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    Accept: 'application/json'
                },
                body: JSON.stringify({
                    settings: {
                        mode: state.mode,
                        width: settings.width,
                        gap: settings.gap,
                        zoom: settings.zoom,
                        background: settings.background,
                        long_strip: settings.long_strip,
                        fit_height: settings.fit_height,
                        hide_tap_zones: settings.hide_tap_zones
                    }
                })
            }).catch(function (error) {
                // A failed save means the next visit reverts to the stored
                // setting, which beats interrupting a read over it.
                console.warn('Could not save reader settings', error);
            });
        }, 400);
    }

    // ------------------------------------------------------ colour filters

    /*
     * The filter chain, composed here exactly as the server composes it.
     *
     * Written out again rather than read off the stylesheet, because the script
     * has to recompose on every slider tick and reading a computed style back
     * would be a layout read per increment. The order matches the server's, and
     * the two are held to the same sequence for a reason: invert has to come
     * last or it undoes the brightness, and grayscale before hue rotation
     * would throw the hue away.
     */
    function applyFilters() {
        var filters = state.filters;
        var parts = [
            'brightness(' + filters.brightness + ')',
            'contrast(' + filters.contrast + ')'
        ];

        if (filters.grayscale) {
            parts.push('grayscale(1)');
        }

        // Wrapped rather than clamped, so dragging hue all the way round lands
        // back at the colour it started from instead of sticking at 360.
        parts.push('hue-rotate(' + (((filters.hue % 360) + 360) % 360) + 'deg)');

        if (filters.invert) {
            parts.push('invert(1)');
        }

        document.documentElement.style.setProperty('--reader-filter', parts.join(' '));
    }

    /*
     * Reflect the current values in the controls.
     *
     * Needed because the server's clamped answer is authoritative. A reader who
     * sends brightness 5 gets back 2, and the slider has to move to where the
     * page actually is rather than staying where they let go of it.
     */
    function renderFilterControls() {
        document.querySelectorAll('[data-filter]').forEach(function (input) {
            var key = input.getAttribute('data-filter');

            if (!(key in state.filters)) {
                return;
            }

            input.value = String(state.filters[key]);

            var output = document.querySelector('[data-filter-output="' + key + '"]');

            if (output === null) {
                return;
            }

            output.innerHTML = key === 'hue'
                ? Math.round(state.filters[key]) + '&deg;'
                : Math.round(state.filters[key] * 100) + '%';
        });

        document.querySelectorAll('[data-filter-toggle]').forEach(function (button) {
            var key = button.getAttribute('data-filter-toggle');
            var on = Boolean(state.filters[key]);

            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            button.classList.toggle('is-on', on);
        });
    }

    /*
     * Post the filters, and apply the answer the server sends.
     *
     * Session-scoped unless the reader ticked remember, which is the default
     * because a filter is nearly always an adjustment to the light in front of
     * them. Debounced for the same reason the settings are: a slider held down
     * would otherwise write on every increment.
     */
    var filterTimer = null;

    function saveFilters(reset) {
        if (filterTimer !== null) {
            clearTimeout(filterTimer);
        }

        filterTimer = setTimeout(function () {
            filterTimer = null;

            if (reset) {
                Object.assign(state.filters, config.filterNeutral);
            }

            // Painted first, so the page responds on the same tick as the input
            // rather than after a round trip.
            applyFilters();
            renderFilterControls();

            var request = reset
                ? fetch(config.filtersResetUrl, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' }
                })
                : fetch(config.filtersUrl, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        Accept: 'application/json'
                    },
                    body: JSON.stringify({
                        settings: state.filters,
                        remember: rememberInput !== null && rememberInput.checked
                    })
                });

            request
                .then(function (response) {
                    return response.json();
                })
                .then(function (body) {
                    if (!body || body.settings === undefined) {
                        return;
                    }

                    // The server's clamped values, so the controls show where
                    // the page actually is rather than what was asked for.
                    Object.assign(state.filters, {
                        brightness: body.settings.brightness,
                        contrast: body.settings.contrast,
                        hue: body.settings.hue,
                        invert: body.settings.invert,
                        grayscale: body.settings.grayscale
                    });

                    applyFilters();
                    renderFilterControls();
                    setFilterState(body.remembered);
                })
                .catch(function (error) {
                    // A failed write means the next visit reverts, which beats
                    // interrupting a read over it.
                    console.warn('Could not save reader filters', error);
                });
        }, reset ? 0 : 250);
    }

    function setFilterState(remembered) {
        var note = document.querySelector('[data-filter-state]');

        if (note === null) {
            return;
        }

        note.textContent = remembered
            ? note.dataset.saved
            : note.dataset.temporary;

        if (rememberInput !== null) {
            rememberInput.checked = remembered;
        }
    }

    function wireFilters() {
        var note = document.querySelector('[data-filter-state]');

        if (note !== null) {
            note.dataset.saved = note.textContent.trim();
            note.dataset.temporary = config.strings.filterTemporary;
        }

        document.querySelectorAll('[data-filter]').forEach(function (input) {
            input.addEventListener('input', function () {
                var key = input.getAttribute('data-filter');

                state.filters[key] = parseFloat(input.value);

                applyFilters();
                renderFilterControls();

                // Not on every input event: the timer coalesces a drag.
                saveFilters(false);
            });
        });

        document.querySelectorAll('[data-filter-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var key = button.getAttribute('data-filter-toggle');

                state.filters[key] = !state.filters[key];

                saveFilters(false);
            });
        });

        var reset = document.querySelector('[data-filter-reset]');

        if (reset !== null) {
            reset.addEventListener('click', function () {
                saveFilters(true);
            });
        }

        if (rememberInput !== null) {
            rememberInput.addEventListener('change', function () {
                // Re-post with the same values, now durable, so the checkbox is
                // the only thing that changes rather than also re-painting.
                saveFilters(false);
            });
        }
    }

    // ------------------------------------------------------------- notes

    /*
     * The note panel.
     *
     * Three decisions worth stating, because each could reasonably go the
     * other way.
     *
     * Saved on a click rather than as the reader types. A note is the one thing
     * in this reader a person composes rather than adjusts, and autosave would
     * write a half-typed sentence to disk every time the tab closed by accident.
     *
     * Saved on Cmd+Enter as well, because a reader who has written a paragraph
     * does not want to reach for the mouse to keep it.
     *
     * And the button disables while the request is in flight. The panel is
     * opened and closed by hand, so pressing save twice is easy, and the write
     * is an upsert against a unique key, so a double save is harmless but a
     * double *clear* is not what anyone meant.
     */
    function wireNotes() {
        var panel = document.querySelector('[data-reader-note-panel]');
        var toggle = document.querySelector('.reader-note-toggle');
        var input = document.querySelector('[data-reader-note]');
        var save = document.querySelector('[data-reader-note-save]');
        var state_ = document.querySelector('[data-reader-note-state]');
        var counter = document.querySelector('[data-reader-note-count]');
        var counterValue = document.querySelector('[data-reader-note-count-value]');

        if (panel === null || toggle === null || input === null || save === null) {
            return;
        }

        function say(message, tone) {
            if (state_ === null) {
                return;
            }

            state_.textContent = message;
            state_.dataset.tone = tone;
        }

        function refreshCounter() {
            if (counter === null || counterValue === null) {
                return;
            }

            var length = input.value.length;

            counterValue.textContent = String(length);

            // Only shown when there is something to count, so an empty box does
            // not carry a zero.
            counter.hidden = length === 0;
        }

        function setNoted(noted) {
            toggle.classList.toggle('has-note', noted);

            var dot = toggle.querySelector('.reader-note-dot');

            if (noted && dot === null) {
                dot = document.createElement('span');
                dot.className = 'reader-note-dot';
                dot.setAttribute('aria-hidden', 'true');
                toggle.appendChild(dot);
            } else if (!noted && dot !== null) {
                dot.remove();
            }
        }

        function open() {
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            input.focus();
            // The caret at the end rather than wherever the browser decided,
            // because this is almost always a reader appending a thought.
            input.setSelectionRange(input.value.length, input.value.length);
        }

        function close() {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }

        function persist() {
            save.disabled = true;
            say(config.strings.noteSaving, 'busy');

            return fetch(config.noteUrl, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    Accept: 'application/json'
                },
                body: JSON.stringify({ body: input.value })
            })
                .then(function (response) {
                    // A 422 comes back as HTML, not JSON, so the body is not
                    // read before the status is checked. Asking Laravel to fail
                    // as JSON is what makes the validation message reachable.
                    if (!response.ok) {
                        return response.json().then(function (body) {
                            var first = body.errors
                                ? Object.values(body.errors)[0]
                                : null;

                            throw new Error(first && first[0] ? first[0] : config.strings.noteFailed);
                        });
                    }

                    return response.json();
                })
                .then(function (body) {
                    var noted = typeof body.body === 'string' && body.body !== '';

                    // The server's trimmed copy, so what the reader sees is what
                    // is stored rather than what they typed.
                    input.value = typeof body.body === 'string' ? body.body : input.value;
                    refreshCounter();
                    setNoted(noted);

                    say(noted ? config.strings.noteSaved : config.strings.noteCleared, noted ? 'saved' : 'cleared');
                })
                .catch(function (error) {
                    say(error.message || config.strings.noteFailed, 'error');
                })
                .then(function () {
                    save.disabled = false;
                });
        }

        toggle.addEventListener('click', function () {
            if (panel.hidden) {
                open();
            } else {
                close();
            }
        });

        save.addEventListener('click', persist);

        input.addEventListener('input', refreshCounter);

        input.addEventListener('keydown', function (event) {
            if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                event.preventDefault();
                persist();
            }
        });

        /*
         * Escape closes, and it is the one that must not lose the note. The
         * panel is a dialog in everything but name, and a reader who types a
         * note and presses Escape expects to see it again, which it will,
         * because nothing was written yet. That is the whole argument for the
         * explicit save.
         */
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || panel.hidden) {
                return;
            }

            close();
        });

        // Unsaved text is left alone on purpose, which is what a browser does
        // with a dialog too. A confirm() on unload would fire on every chapter
        // seek and every filter drag.
        refreshCounter();
    }

    // ---------------------------------------------------------- progress

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta === null ? '' : meta.content;
    }

    function showCounter() {
        if (counterCurrent !== null) {
            counterCurrent.textContent = String(state.current + 1);
        }
    }

    function schedule() {
        if (state.timer !== null) {
            clearTimeout(state.timer);
        }

        /*
         * Debounced at 1200ms. A fast scroll across forty pages fires the
         * measurement passes dozens of times and should produce one request.
         */
        state.timer = setTimeout(post, 1200);
    }

    function post() {
        state.timer = null;

        if (state.highest === state.posted) {
            return;
        }

        var page = state.highest;

        fetch(config.progressUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                Accept: 'application/json'
            },
            body: JSON.stringify({ page: page, total: total })
        }).then(function (response) {
            if (response.ok) {
                state.posted = page;
            }
        }).catch(function (error) {
            // Losing one progress ping costs nothing. The next page turn
            // tries again.
            console.warn('Could not record progress', error);
        });
    }

    /*
     * Flush before leaving.
     *
     * sendBeacon is the only transport allowed to outlive the document, and
     * that is why this is not a fetch: a chapter switch navigates away
     * immediately and a normal request would be cancelled mid flight.
     */
    function flush() {
        if (state.timer !== null) {
            clearTimeout(state.timer);
            state.timer = null;
        }

        if (state.highest === state.posted || ! navigator.sendBeacon) {
            return;
        }

        var body = new FormData();

        body.append('page', String(state.highest));
        body.append('total', String(total));
        body.append('_token', csrfToken());

        if (navigator.sendBeacon(config.progressUrl, body)) {
            state.posted = state.highest;
        }
    }

    // ------------------------------------------------------- vertical mode

    function onPage(index) {
        if (! state.ready) {
            return;
        }

        state.current = index;
        showCounter();

        if (index > state.highest) {
            state.highest = index;
        }

        schedule();
    }

    /*
     * Which page is being read.
     *
     * Nearest the middle rather than first visible, because a reader is
     * looking at the middle of the page they are on, not the one whose top
     * edge happened to cross the top of the screen.
     *
     * Runs synchronously on every scroll event. onScroll explains why, and
     * this is the part that has to stay cheap as a result: it breaks out
     * of the loop at the first image past the viewport, and it returns
     * early when the page has not changed.
     */
    function trackPage() {
        if (pageImages.length === 0) {
            return;
        }

        var bottom = window.innerHeight;
        var middle = bottom / 2;
        var best = null;
        var bestDistance = Infinity;

        for (var i = 0; i < pageImages.length; i++) {
            var box = pageImages[i].getBoundingClientRect();

            /*
             * Images are in document order, so the first one past the
             * bottom of the viewport ends the search. Reading a 200 page
             * chapter near its end costs one rect per page rather than
             * measuring all of them to find the same answer.
             */
            if (box.top >= bottom) {
                break;
            }

            // Above the viewport, so not the page being read.
            if (box.bottom <= 0) {
                continue;
            }

            var distance = Math.abs((box.top + box.height / 2) - middle);

            if (distance < bestDistance) {
                bestDistance = distance;
                best = pageImages[i];
            }
        }

        if (best === null) {
            return;
        }

        var index = Number(best.dataset.page);

        // Most scroll events land between pages and change nothing.
        if (index === state.current) {
            return;
        }

        onPage(index);
    }

    /*
     * Scroll handler.
     *
     /*
     * Synchronous on purpose.
     *
     * Both obvious ways to throttle this were measured and both depend on
     * the page producing frames. IntersectionObserver callbacks and
     * requestAnimationFrame callbacks are delivered by the rendering
     * lifecycle, and a document that is not being rendered gets neither.
     * A backgrounded tab is the everyday case, and the in-app browser
     * panel is the extreme one: there, scrollY moved and the pages
     * rendered perfectly while the counter stayed frozen and no progress
     * was ever written.
     *
     * A scroll event arrives either way. The per event cost is one rect
     * read per page up to the viewport, with no style write anywhere in
     * the path, so layout is not invalidated and the reads come from the
     * existing one. The guard in trackPage means most events do nothing.
     */
    function onScroll() {
        if (state.mode === 'paged') {
            body.classList.add('reader-visible');

            return;
        }

        body.classList.toggle('reader-visible', window.scrollY < 240);
        trackPage();
    }

    /*
     * Resume where the reader stopped.
     *
     * Scrolling to the page is right in vertical mode, and ready only goes
     * true afterwards so the jump does not record a page the reader has
     * already read past.
     */
    function restore() {
        state.ready = true;

        if (state.mode === 'paged') {
            state.pagedIndex = resumeAt;
            scrollPaged();

            return;
        }

        var target = pages === null
            ? null
            : pages.querySelector('img[data-page="' + resumeAt + '"]');

        if (target !== null) {
            target.scrollIntoView({ block: 'start' });
            state.current = resumeAt;
            showCounter();
        }
    }

    // --------------------------------------------------------- paged mode

    /*
     * Move the track rather than scroll it.
     *
     * A transform is a compositor operation, so a page turn costs no layout
     * and the position is exact. A scroll of a 40000px track on a long
     * chapter is neither, and sub-pixel rounding makes page boundaries drift.
     */
    function scrollPaged() {
        if (pagedTrack !== null) {
            pagedTrack.style.transform = 'translateX(-' + (state.pagedIndex * 100) + 'vw)';
        }
    }

    function goTo(index) {
        var target = Math.max(0, Math.min(index, total - 1));

        if (target === state.pagedIndex) {
            return;
        }

        state.pagedIndex = target;
        state.current = target;
        showCounter();
        describeZones();
        scrollPaged();

        /*
         * Load the neighbours. A reader who turns faster than the network
         * should not be waiting on a round trip for the page they just
         * turned to.
         */
        preload(target + 1);
        preload(target - 1);

        if (target > state.highest) {
            state.highest = target;
        }

        schedule();
    }

    /*
     * Make a page's image eager.
     *
     * The lazy attribute is what keeps a forty page chapter from arriving
     * all at once, and flipping it is how the neighbours get fetched. The
     * paged copy is asked for, not the vertical one, because in paged mode
     * the vertical container is display:none and its images never load.
     */
    function preload(index) {
        if (index < 0 || index >= total || pagedTrack === null) {
            return;
        }

        var image = pagedTrack.querySelector('img[data-page="' + index + '"]');

        if (image !== null && ! image.complete) {
            image.loading = 'eager';
        }
    }

    /*
     * The corner button is a shortcut for the mode switch in the panel, so
     * it calls setMode rather than having its own version of the switch.
     */
    function cycleMode() {
        setMode(state.mode === 'paged' ? 'vertical' : 'paged');
    }

    /*
     * Keys are on the document, not on the tap zones, because a reader
     * using the keyboard should never have to tab to a control to turn a
     * page.
     */
    function onKey(event) {
        if (event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        var direction = 0;
        var absolute = null;

        switch (event.key) {
            case 'Escape':
                // Escape closes the panel, which is the one thing the
                // reader is likely to have opened and wants out of.
                closePanel();
                return;
            case 'ArrowRight':
            case 'PageDown':
            case ' ':
                direction = 1;
                break;
            case 'ArrowLeft':
            case 'PageUp':
                direction = -1;
                break;
            case 'Home':
                absolute = 0;
                break;
            case 'End':
                absolute = total - 1;
                break;
            default:
                return;
        }

        /*
         * While the panel is open the arrow keys and space belong to it:
         * a range slider eats all of them, and letting a key both turn a
         * page and move a slider is the kind of thing that makes a reader
         * close the tab.
         */
        if (panel !== null && ! panel.hidden) {
            return;
        }

        event.preventDefault();

        if (state.mode !== 'paged') {
            /*
             * In vertical mode a step is a scroll, which is what the
             * scrollbar and the space bar already do. Forcing paged
             * behaviour on a scroll would fight the reader.
             */
            window.scrollBy({ top: window.innerHeight * direction, behavior: 'smooth' });

            if (absolute !== null) {
                var edge = pages === null
                    ? null
                    : pages.querySelector('img[data-page="' + absolute + '"]');

                if (edge !== null) {
                    edge.scrollIntoView({ block: 'start' });
                }
            }

            return;
        }

        goTo(absolute === null ? state.pagedIndex + direction : absolute);
    }

    /*
     * The zones do one thing per mode, and the href only ever holds the
     * chapter.
     *
     * In vertical mode a tap moves a chapter, because a vertical scroll is
     * already the way a page is turned. In paged mode there is no scroll,
     * so a tap has to turn the page, and the zone falls through to its
     * href only at the first and last page. That is the order Mihon uses
     * too: paging to the end and tapping again opens the next chapter.
     */
    function bindZone(zone, direction) {
        if (zone === null) {
            return;
        }

        zone.addEventListener('click', function (event) {
            if (state.mode !== 'paged') {
                return;
            }

            var atEdge = direction === 1
                ? state.pagedIndex >= total - 1
                : state.pagedIndex <= 0;

            // At the edge the anchor does what it was written for.
            if (atEdge) {
                return;
            }

            event.preventDefault();
            goTo(state.pagedIndex + direction);
        });
    }

    /*
     * Keep the accessible name honest about what a tap will do. A zone
     * labelled "Next chapter" that turns a page is worse than no label.
     */
    function describeZones() {
        if (state.mode !== 'paged') {
            if (zonePrevious !== null) {
                zonePrevious.setAttribute('aria-label', chapterPreviousLabel);
            }

            if (zoneNext !== null) {
                zoneNext.setAttribute('aria-label', chapterNextLabel);
            }

            return;
        }

        if (zonePrevious !== null) {
            zonePrevious.setAttribute(
                'aria-label',
                state.pagedIndex <= 0 ? chapterPreviousLabel : PAGE_PREVIOUS
            );
        }

        if (zoneNext !== null) {
            zoneNext.setAttribute(
                'aria-label',
                state.pagedIndex >= total - 1 ? chapterNextLabel : PAGE_NEXT
            );
        }
    }


    // ---------------------------------------------------------- wiring up

    if (toggle !== null) {
        toggle.addEventListener('click', cycleMode);
    }

    if (settingsToggle !== null && panel !== null) {
        settingsToggle.addEventListener('click', togglePanel);

        /*
         * change rather than input, so the handler runs once when a range
         * is released rather than on every pixel of the drag. The repaint
         * during the drag comes from the browser's own thumb movement;
         * what matters here is not writing to the server sixty times.
         *
         * keydown on the toggle is what makes it a button to a keyboard:
         * Enter and Space already fire click, and Escape closes.
         */
        /*
         * Two events, because the controls do not agree on one.
         *
         * Ranges and checkboxes fire `change` when the reader lets go.
         * Buttons fire `click` and no `change` at all, so listening only to
         * `change` silently disables every button in the panel: the mode
         * switch and the background swatches do nothing and nothing errors.
         */
        panel.addEventListener('change', function (event) {
            if (event.target.tagName !== 'BUTTON') {
                onPanelChange(event);
            }
        });

        panel.addEventListener('click', function (event) {
            if (event.target.closest('button[data-setting]') !== null) {
                onPanelChange(event);
            }
        });

        panel.addEventListener('input', function (event) {
            // Live repaint while dragging, without saving.
            if (event.target.type === 'range') {
                settings[event.target.dataset.setting] = Number(event.target.value);
                applySettings();
                syncPanel();
            }
        });

        // Clicking outside closes, which is what a sheet is expected to do.
        document.addEventListener('click', function (event) {
            if (panel.hidden || panel.contains(event.target)) {
                return;
            }

            if (settingsToggle.contains(event.target)) {
                return;
            }

            closePanel();
        });

        syncPanel();
    }

    bindZone(zoneNext, 1);
    bindZone(zonePrevious, -1);

    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', onScroll, { passive: true });

    // A link tap, a back gesture and a tab close all need the flush.
    window.addEventListener('pagehide', flush);

    // Beforeunload would be the usual choice, but it fires on bfcache
    // eviction too and cannot be relied on. pagehide is the event that
    // actually means the document is going away.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flush();
        }
    });

    /*
     * Wiring.
     *
     * Started here rather than on window load. The load event waits for
     * every eager image, and a source that stalls on one page stalls the
     * reader with it: no counter, no progress, while the pages that did arrive
     * sit there looking fine. The DOM is already parsed at this point and the
     * images carry width and height, so measurement is correct before a single
     * byte of artwork arrives.
     */
    applySettings();
    applyFilters();
    wireFilters();
    wireNotes();
    showCounter();
    onScroll();
    describeZones();
    trackPage();
    restore();
}

/*
 * The reader page keeps its configuration in the body attribute and loads this
 * module once. It boots itself so there is one source for how the page starts,
 * which is the job the removed resources/js/app.js entry used to imply.
 */
if (document.body.hasAttribute('data-reader-config')) {
    bootReader(JSON.parse(document.body.dataset.readerConfig));
}
