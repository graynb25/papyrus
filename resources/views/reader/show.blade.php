<!DOCTYPE html>
{{--
    The reader. Deliberately not a Livewire page.

    Measured: fetchChapterPages takes 2.0s for a 22 page chapter, and it is the
    only call that yields a real page count. It runs once, here. After that the
    browser has every URL it needs, so turning a page is a scroll and costs
    nothing. Progress is posted back on page entry, debounced, so scrolling does
    not generate a request per pixel.

    Images are plain <img> tags pointing at the same-origin proxy. No canvas, no
    zoom transform on the scroll path, no framework between a finger and a page
    turn. That is AD-7: vertical reading is the mode that works everywhere.

    Two modes share this document. Vertical is the default and the one AD-7 is
    built around. Paged is a container that shows one image and advances on a tap
    or a key, which is what some readers want for a fixed aspect page. They are
    not separate readers, so there is no state to synchronise between them.

    Settings arrive resolved and clamped from the server. The browser renders
    them and never validates them, which is why the colour is checked there
    rather than here.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    @include('partials.head')

    <title>{{ __('Reading') }} &middot; {{ config('app.name') }}</title>

    <style>
        /*
         * Server-rendered and static, so the first paint is correct before any
         * script runs. Resolved settings go in as CSS custom properties rather
         * than as inline styles on the container, so one block governs everything.
         */
        :root {
            --reader-bg: {{ $settings['background'] }};
            --reader-width: {{ $settings['width'] }}%;
            --reader-gap: {{ $settings['gap'] }}px;
            --reader-zoom: {{ $settings['zoom'] }};

            /*
             * Ink & Vermilion, mirrored from resources/css/app.css. This document
             * does not import the app stylesheet, on purpose: the reader is a
             * plain document with its own surface, and pulling the whole app
             * bundle for a dozen values is the wrong trade. The values are
             * copies, and this comment is the promise that they move together.
             */
            --ink-50: oklch(0.945 0.004 260);
            --ink-100: oklch(0.902 0.005 260);
            --ink-200: oklch(0.822 0.006 260);
            --ink-300: oklch(0.722 0.007 260);
            --ink-400: oklch(0.612 0.008 260);
            --ink-500: oklch(0.502 0.009 260);
            --ink-700: oklch(0.322 0.008 260);
            --ink-800: oklch(0.235 0.007 260);
            --ink-900: oklch(0.185 0.006 260);
            --ink-950: oklch(0.138 0.005 260);
            --vermilion-400: oklch(0.778 0.132 34);
            --vermilion-500: oklch(0.712 0.168 33);
            --vermilion-600: oklch(0.643 0.188 32);
            --moss-400: oklch(0.78 0.13 150);
            --moss-500: oklch(0.70 0.15 150);
            --red-400: oklch(0.737 0.168 25);
            --glass-surface: color-mix(in oklab, var(--ink-900) 78%, transparent);
            --glass-hairline: color-mix(in oklab, white 10%, transparent);
            --glass-blur: blur(18px) saturate(160%);
            --ease-swift: cubic-bezier(0.22, 1, 0.36, 1);

            /*
             * The colour filter chain, composed here rather than in the script.
             *
             * Server-rendered so the first paint is already filtered, and composed
             * because the order matters: invert has to come last or it undoes the
             * brightness, and grayscale before hue rotation would throw the hue away.
             *
             * Every number is clamped by ReaderSettings, so this is not an
             * interpolation sink. Hue is wrapped to 0-360 rather than clamped,
             * because 360 degrees and 0 degrees are the same colour and a reader who
             * drags the handle round should land back where they started.
             */
            --reader-filter: brightness({{ $settings['brightness'] }})
                contrast({{ $settings['contrast'] }})
                {{ $settings['grayscale'] ? 'grayscale(1)' : '' }}
                hue-rotate({{ (int) round(((float) $settings['hue'] % 360) + 360) % 360 }}deg)
                {{ $settings['invert'] ? 'invert(1)' : '' }};
        }

        [x-cloak] { display: none !important; }

        html {
            scrollbar-gutter: stable;
            background: var(--reader-bg);
        }

        body {
            margin: 0;
            background: var(--reader-bg);
            color: var(--ink-200);
            overscroll-behavior-y: contain;
        }

        /*
         * The progress hairline. Pure CSS scroll-driven animation: the bar grows
         * with the document scroll on the compositor, so it costs nothing per
         * frame and has no script to disagree with the page counter. Hidden in
         * paged mode, where the document never scrolls, and removed entirely for
         * readers who ask for reduced motion.
         */
        .reader-progress {
            position: fixed;
            inset-block-start: 0;
            inset-inline: 0;
            z-index: 21;
            block-size: 2px;
            transform-origin: 0 50%;
            transform: scaleX(0);
            background: linear-gradient(to right, var(--vermilion-500), var(--vermilion-400));
            box-shadow: 0 0 10px color-mix(in oklab, var(--vermilion-500) 55%, transparent);
            animation: reader-progress-grow linear both;
            animation-timeline: scroll(root block);
            pointer-events: none;
        }

        body[data-mode="paged"] .reader-progress { display: none; }

        @keyframes reader-progress-grow {
            to { transform: scaleX(1); }
        }

        @media (prefers-reduced-motion: reduce) {
            .reader-progress { display: none; }
        }

        /*
         * The reading column. Width is a percentage of the viewport so it works on
         * a phone and a monitor without a breakpoint, and the reader can narrow it.
         */
        #pages {
            width: var(--reader-width);
            max-width: 100%;
            margin-inline: auto;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: var(--reader-gap);

            /*
             * One filter on the container, not one per image. A 28 page chapter then
             * composites as a single layer rather than 28, which matters because the
             * browser re-composites on every scroll tick and a reader scrolling a long
             * strip would pay that 28 times per frame.
             */
            filter: var(--reader-filter);
        }

        /*
         * Zoom is a transform, not a width, so it costs no reflow and no refetch.
         * transform-origin top so the page grows downwards rather than being
         * scaled about its centre, which would move the reading position.
         */
        #pages.zoomed {
            width: calc(var(--reader-width) / var(--reader-zoom));
            margin-inline: auto;
        }

        /*
         * Page images.
         *
         * No content-visibility here, deliberately. It looks like a free win on a
         * forty page chapter, and it is a second optimisation on top of
         * loading="lazy", which already stops the chapter arriving all at once.
         * Measured: it stops IntersectionObserver reporting the elements at all,
         * so the page counter and the progress posts die silently while the pages
         * keep rendering. Dropping it costs paint time and buys back the reader's
         * place in the book.
         */
        #pages img {
            display: block;
            width: 100%;
            height: auto;
        }

        /*
         * Long strips and fit-height. A webtoon page is one enormous image, so
         * fitting its height would shrink it to nothing. Fit-height applies to
         * ordinary pages where the reader wants the whole spread visible.
         */
        #pages[data-long-strip="true"] img { width: 100%; }

        #pages[data-fit-height="true"]:not([data-long-strip="true"]) {
            height: 100dvh;
            justify-content: center;
        }

        #pages[data-fit-height="true"]:not([data-long-strip="true"]) img {
            max-height: 100dvh;
            width: auto;
            max-width: 100%;
            margin-inline: auto;
        }

        /*
         * Top chrome. One bar, always on screen, holding the way back and the
         * place in the chapter. The hairline above it carries progress, so this
         * bar carries position in plain numbers. Fixed, so a reader deep in a
         * long strip never loses the way back to the series.
         */
        .reader-topbar {
            position: fixed;
            inset-block-start: 2px;
            inset-inline: 0;
            z-index: 40;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            padding: .5rem .75rem;
            background: color-mix(in oklab, var(--ink-950) 72%, transparent);
            border-block-end: 1px solid var(--glass-hairline);
            backdrop-filter: blur(16px) saturate(150%);
            -webkit-backdrop-filter: blur(16px) saturate(150%);
        }

        .reader-back {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .5rem .75rem;
            border-radius: 9999px;
            text-decoration: none;
            white-space: nowrap;
            font-size: .75rem;
            font-weight: 500;
            color: var(--ink-200);
            background: var(--glass-surface);
            border: 1px solid var(--glass-hairline);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
        }

        .reader-back:hover {
            background: color-mix(in oklab, var(--ink-800) 92%, transparent);
            border-color: color-mix(in oklab, white 16%, transparent);
        }

        .reader-back:focus-visible { outline: 2px solid var(--vermilion-500); outline-offset: 2px; }

        .reader-back svg { flex-shrink: 0; }

        /*
         * Page counter in the top bar. Always visible, tabular so the digits
         * never shuffle the bar as the reader turns pages.
         */
        .reader-counter {
            margin: 0;
            font-size: .75rem;
            font-variant-numeric: tabular-nums;
            color: var(--ink-400);
            white-space: nowrap;
        }

        /*
         * Page counter. Fixed to the top edge, hidden until the reader is near the
         * top of the chapter so it never sits over the artwork.
         */
        .page-marker {
            position: fixed;
            inset-block-start: 0;
            inset-inline: 0;
            z-index: 20;
            padding: .5rem 1rem;
            font-size: .75rem;
            font-variant-numeric: tabular-nums;
            color: var(--ink-400);
            background: linear-gradient(to bottom, var(--reader-bg), transparent);
            pointer-events: none;
            opacity: 0;
            transition: opacity .2s;
        }

        body.reader-visible .page-marker { opacity: 1; }

        /*
         * Room for the always-visible top bar, so the first page never sits
         * under the back link and the counter. Paged mode keeps its full
         * height images and lets the translucent bar sit over them, because a
         * paged image is contained and the bar is narrow.
         */
        body { padding-block-start: 3.25rem; }
        body[data-mode="paged"] { padding-block-start: 0; }
        body[data-mode="paged"] #paged { padding-block-start: 3.25rem; }

        /*
         * Paged mode. One image in the viewport, no scroll. The container scrolls
         * horizontally by transform, so a page turn is a compositor operation
         * rather than a scroll, and the reader's position is exact.
         */
        #paged {
            display: none;
            height: 100dvh;
            overflow: hidden;
        }

        body[data-mode="paged"] #pages { display: none; }
        body[data-mode="paged"] #paged { display: block; }

        #paged-track {
            display: flex;
            height: 100dvh;
            will-change: transform;
            transition: transform .18s ease-out;
        }

        @media (prefers-reduced-motion: reduce) {
            #paged-track { transition: none; }
        }

        #paged-track img {
            height: 100dvh;
            width: auto;
            max-width: 100vw;
            flex: 0 0 100vw;
            object-fit: contain;
            display: block;
        }

        /* Tap zones. Over the outer margins so they never eat a tap meant for the art. */
        .tap-zone {
            position: fixed;
            inset-block: 0;
            width: 18vw;
            max-width: 8rem;
            z-index: 15;
            border: 0;
            background: transparent;
            cursor: pointer;
        }

        .tap-zone-prev { inset-inline-start: 0; }
        .tap-zone-next { inset-inline-end: 0; }

        body[data-hide-tap-zones="true"] .tap-zone { display: none; }

        /*
         * Corner controls.
         *
         * All three share one rule on purpose. They were four separate rules once
         * and the settings button ended up unpositioned and unlayered, sitting in
         * the flow under the tap zones, which no amount of reading the CSS would
         * have made obvious. One rule, three offsets.
         *
         * Above the tap zones at z-index 20 so a corner control is always
         * clickable, and along the bottom edge because that is where a thumb is
         * and the top of a reader belongs to the artwork and the top bar.
         */
        .reader-toggle,
        .reader-settings-toggle,
        .reader-note-toggle {
            position: fixed;

            /*
             * Lifted clear of the chapter navigator.
             *
             * The navigator is a full-width bar along the bottom edge, which is where
             * these three already sit, so without this they are behind it and a
             * reader cannot reach the mode switch. Measured by the reader gate failing
             * to click it, which reads as "the button is broken" rather than "something
             * is on top of it".
             *
             * The height is a custom property rather than a guess so the offset follows
             * the bar: it is 0 when there is no navigator, because most series in a
             * fresh library have no chapter list at all.
             */
            inset-block-end: calc(1rem + var(--reader-nav-height, 0px));

            /*
             * Above the panels, not between them and the navigator.
             *
             * The note panel is a side panel on the right on a desktop and the controls
             * are bottom right, so they overlap. With the panel above them, a reader
             * opened the note panel and could not close it: the note toggle, which is how
             * it closes, was underneath it. Escape works, and so does saving, but a
             * control the reader cannot see is not a control.
             */
            z-index: 60;
            display: grid;
            place-items: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 9999px;
            background: var(--glass-surface);
            color: var(--ink-300);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--glass-hairline);
            cursor: pointer;
            box-shadow:
                inset 0 1px 0 rgb(255 255 255 / .08),
                0 6px 20px -6px rgb(0 0 0 / .5);
            transition: background .2s var(--ease-swift), transform .2s var(--ease-swift), border-color .2s;
        }

        .reader-toggle { inset-inline-end: 1rem; }
        .reader-settings-toggle { inset-inline-end: 4rem; }

        /*
         * A third control, so a third offset at the same 3rem spacing.
         *
         * The note toggle is last because it is the least used: reading a chapter needs
         * settings far more often than it needs to write about one, and the corner
         * nearest the settings button is the easiest to reach by accident.
         */
        .reader-note-toggle { inset-inline-end: 7rem; }

        .reader-toggle:hover,
        .reader-settings-toggle:hover,
        .reader-note-toggle:hover {
            background: color-mix(in oklab, var(--ink-800) 92%, transparent);
            border-color: color-mix(in oklab, white 16%, transparent);
        }

        .reader-toggle:active,
        .reader-settings-toggle:active,
        .reader-note-toggle:active { transform: scale(.94); }

        /* Visible to a keyboard, which is how a control like this is found. */
        .reader-toggle:focus-visible,
        .reader-settings-toggle:focus-visible,
        .reader-note-toggle:focus-visible { outline: 2px solid var(--vermilion-500); outline-offset: 2px; }

        /*
         * The settings drawer.
         *
         * Bottom sheet on a phone, side panel from `sm` up, because on a phone the
         * thumb is at the bottom and on a monitor a sheet over the artwork is
         * worse than a panel beside it. `dvh` rather than `vh`, so a mobile
         * browser's collapsing toolbar does not leave the last control unreachable.
         */
        .reader-panel {
            position: fixed;
            inset-block-end: 0;
            inset-inline: 0;

            /*
             * Above the chapter navigator and above the corner controls.
             *
             * The navigator is also 30 and comes later in the document, so with equal
             * z-index it painted over the panel: the panel opened, reported itself
             * visible to every assertion, and was invisible to the reader. Found by
             * looking at a screenshot, which no assertion had done.
             *
             * The three layers are deliberate: 30 for chrome that is always there
             * (navigator), 40 for controls the reader reaches for (corners), 50 for a
             * surface that was opened on purpose and should not compete with either.
             */
            z-index: 50;
            max-block-size: 80dvh;
            overflow-y: auto;
            overscroll-behavior: contain;
            background: color-mix(in oklab, var(--ink-900) 88%, transparent);
            backdrop-filter: blur(24px) saturate(160%);
            -webkit-backdrop-filter: blur(24px) saturate(160%);
            border-block-start: 1px solid var(--glass-hairline);
            padding: 1.25rem 1.25rem 1.5rem;

            /*
             * Room at the bottom for the corner controls.
             *
             * The controls sit above the panel so they stay reachable, which means they
             * sit across the panel's action row unless the panel leaves room for them.
             * Measured: the Save button was under three of the four buttons, so the one
             * control the whole panel exists for could not be clicked.
             *
             * The height comes from the same body-level variable the controls use, so
             * this tracks the navigator rather than guessing at it twice.
             */
            padding-block-end: calc(1.5rem + var(--reader-nav-height, 0px) + 3.5rem);
        }

        .reader-panel[hidden] { display: none; }

        .reader-panel-body {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-inline-size: 24rem;
            margin-inline: auto;
            font-size: .875rem;
        }

        .reader-panel fieldset { display: flex; flex-direction: column; gap: .5rem; }

        .reader-panel legend,
        .reader-panel label > span {
            color: var(--ink-400);
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .reader-panel output { color: var(--ink-200); font-variant-numeric: tabular-nums; }
        .reader-panel label { display: flex; flex-direction: column; gap: .375rem; }

        /*
         * A filter slider.
         *
         * The label, the range and the readout sit on one row rather than stacked,
         * because a brightness control the reader drags to compare against a page is
         * useless if they cannot see the number while they drag it. The readout is
         * tabular so the digits do not shuffle the row as the value changes.
         */
        .reader-panel .reader-slider {
            display: grid;
            grid-template-columns: 5.5rem 1fr 3.25rem;
            align-items: center;
            gap: .625rem;
        }

        .reader-panel .reader-slider > span {
            text-transform: none;
            letter-spacing: normal;
            font-size: .8125rem;
            color: var(--ink-300);
        }

        .reader-panel .reader-slider > output { text-align: end; }

        .reader-panel input[type="range"] {
            inline-size: 100%;
            accent-color: var(--vermilion-500);
        }

        .reader-segments {
            display: flex;
            gap: .5rem;
        }

        .reader-segments button {
            flex: 1;
            padding: .375rem .75rem;
            border-radius: .375rem;
            border: 1px solid rgb(63 63 70);
            background: transparent;
            color: var(--ink-400);
            font-size: .8125rem;
            cursor: pointer;
        }

        .reader-segments button[aria-pressed="true"],
        .reader-segments button.is-on {
            background: rgb(63 63 70);
            border-color: rgb(82 82 91);
            color: var(--ink-50);
        }

        /*
         * Reset is a different kind of control from the toggles beside it: it acts
         * rather than holds a state, so it is dimmed by default and lit only on hover
         * or focus. Styling it as another toggle would claim a state it does not have.
         */
        .reader-segments button[data-filter-reset] { color: var(--ink-500); }

        .reader-segments button[data-filter-reset]:hover,
        .reader-segments button[data-filter-reset]:focus-visible {
            color: var(--ink-50);
            border-color: var(--vermilion-500);
        }

        /* Swatches are sized by their own colour so the control is the swatch. */
        .reader-segments .reader-swatch {
            block-size: 1.75rem;
            background: var(--swatch);
            border-color: rgb(63 63 70);
        }

        .reader-segments .reader-swatch[aria-pressed="true"] {
            outline: 2px solid var(--vermilion-500);
            outline-offset: 2px;
        }

        .reader-check {
            flex-direction: row !important;
            align-items: center;
            gap: .625rem !important;
        }

        .reader-check span { text-transform: none; letter-spacing: normal; font-size: .875rem; color: var(--ink-300); }
        .reader-check input { accent-color: var(--vermilion-500); inline-size: 1rem; block-size: 1rem; }

        .reader-panel-note { color: var(--ink-500); font-size: .75rem; }


        /*
         * The note toggle's dot.
         *
         * Additive rather than a change of icon: the button looks the same shape either
         * way, so a reader scanning the corner finds the control in the same place
         * whether or not this chapter happens to have a note.
         */
        .reader-note-toggle { position: fixed; }

        .reader-note-dot {
            position: absolute;
            inset-block-start: .3125rem;
            inset-inline-end: .3125rem;
            inline-size: .4375rem;
            block-size: .4375rem;
            border-radius: 9999px;
            background: var(--vermilion-500);
            box-shadow: 0 0 0 2px rgb(9 9 11 / .8);
        }

        /*
         * The note panel.
         *
         * Shares the settings panel's positioning so the two are the same object at
         * different times, and differs only in width: a note wants room for a sentence
         * and a settings drawer wants to be narrow.
         */
        .reader-note-panel { inline-size: min(30rem, 100%); }

        .reader-note-label {
            display: block;
            margin-block-end: .375rem;
            color: var(--ink-300);
            font-size: .8125rem;
            font-weight: 500;
        }

        .reader-note-input {
            inline-size: 100%;
            padding: .625rem .75rem;
            border-radius: .5rem;
            border: 1px solid rgb(63 63 70);
            background: rgb(9 9 11 / .6);
            color: var(--ink-200);
            font-size: .875rem;
            line-height: 1.5;
            resize: vertical;
        }

        .reader-note-input:focus {
            outline: 2px solid var(--vermilion-500);
            outline-offset: 1px;
            border-color: transparent;
        }

        /*
         * The action row.
         *
         * The state message is a status region rather than a visual label, so a screen
         * reader announces "Saved" when the save lands without the focus moving. It is
         * the one place in the reader that reports on an action that changed nothing
         * visible, which is exactly what a status region is for.
         */
        .reader-note-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .625rem;
            margin-block-start: .625rem;
        }

        .reader-note-state { flex: 1 1 auto; min-inline-size: 0; font-size: .75rem; }
        .reader-note-state[data-tone="saved"] { color: var(--moss-400); }
        .reader-note-state[data-tone="error"] { color: var(--red-400); }

        .reader-note-count {
            display: inline-flex;
            align-items: baseline;
            gap: .25rem;
            color: var(--ink-500);
            font-size: .75rem;
            font-variant-numeric: tabular-nums;
        }

        /*
         * The counter is shown and hidden with the `hidden` attribute, and a `display`
         * rule defeats it: `[hidden]` works through the user-agent stylesheet's
         * `display: none`, which any author `display` wins over. Without this rule the
         * counter stayed on screen showing "0" in an empty box.
         */
        .reader-note-count[hidden] { display: none; }

        .reader-note-save {
            padding: .375rem .75rem;
            border-radius: .375rem;
            background: var(--vermilion-500);
            color: var(--ink-950);
            font-size: .8125rem;
            font-weight: 500;
            border: 0;
            cursor: pointer;
        }

        .reader-note-save:hover { background: var(--vermilion-400); }
        .reader-note-save:focus-visible { outline: 2px solid var(--ink-50); outline-offset: 2px; }

        /*
         * Busy, so a second click cannot queue two saves. The panel is opened and
         * closed by hand, so the reader can very easily press save twice.
         */
        .reader-note-save[disabled] { opacity: .6; cursor: progress; }

        .reader-note-hint { margin-block-start: .5rem; }

        /*
         * The chapter navigator.
         *
         * A bottom bar rather than a side panel, because the reader's own controls are
         * at the bottom and a reader who has just finished a chapter looks there. It is
         * a landmark so a screen reader can jump to it, and the strip is a list of
         * links rather than a canvas or a div soup, so its segments are countable.
         *
         * Its height is declared as a variable and the three corner controls are offset
         * by it, because both occupy the bottom edge and one of them has to yield. The
         * legend is held to one line so the bar's height is a fact rather than
         * something that depends on how the text wraps.
         */
        .reader-nav {
            position: fixed;
            inset-inline: 0;
            inset-block-end: 0;
            z-index: 30;
            padding: .5rem .75rem calc(.5rem + env(safe-area-inset-bottom));
            background: color-mix(in oklab, var(--ink-950) 88%, transparent);
            border-block-start: 1px solid var(--glass-hairline);
            backdrop-filter: blur(16px) saturate(150%);
            -webkit-backdrop-filter: blur(16px) saturate(150%);
        }

        .reader-nav-head {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: .5rem;
            margin-block-end: .375rem;
        }

        .reader-nav-where {
            color: var(--ink-400);
            font-size: .75rem;
            font-variant-numeric: tabular-nums;
        }

        .reader-nav-legend {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            color: var(--ink-500);
            font-size: .6875rem;
        }

        /*
         * Below about 640px there is no room for a legend beside a position readout, and
         * a wrapped legend would make the bar's height depend on the text. So the
         * legend goes rather than the bar growing, and each segment's own title and
         * screen-reader text still say what it is.
         */
        @media (max-width: 639px) {
            .reader-nav-legend { display: none; }
        }

        .reader-nav-key { display: inline-flex; align-items: center; gap: .3125rem; }

        /*
         * The three swatches differ in outline as well as fill, not only in shade.
         *
         * Measured, this matters more than it sounds: a "not started" segment and a
         * "part read" segment are both mostly empty, and two greys that differ by a few
         * percent of lightness are the same swatch to anyone whose eyes are not a
         * calibrated display.
         */
        .reader-nav-swatch {
            inline-size: .625rem;
            block-size: .625rem;
            border-radius: 2px;
            border: 1px solid rgb(63 63 70);
        }

        .reader-nav-swatch[data-state="read"] { background: var(--moss-400); border-color: var(--moss-400); }
        .reader-nav-swatch[data-state="partial"] { background: var(--vermilion-500); border-color: var(--vermilion-500); }
        .reader-nav-swatch[data-state="unknown"] { background: transparent; border-style: dashed; }

        .reader-nav-strip {
            display: flex;
            gap: 2px;
            list-style: none;
            margin: 0;
            padding: 0;
            /*
             * A strip of 107 segments in a 1000px bar is 9px each, so the flex basis is
             * a floor rather than a size and the row is allowed to scroll sideways on a
             * narrow screen. Scroll, not squeeze: a segment narrower than about 6px
             * stops being a target and starts being a decoration.
             */
            overflow-x: auto;
            scrollbar-width: thin;
        }

        .reader-nav-strip > li { flex: 1 0 6px; min-inline-size: 6px; }

        .reader-nav-seg {
            position: relative;
            display: block;
            block-size: 1.5rem;
            border-radius: 2px;
            background: rgb(39 39 42);
            border: 1px solid rgb(63 63 70);
            overflow: hidden;
        }

        /*
         * The fill is a child rather than a background, so the three states are told
         * apart by outline and not only by colour: dashed for unknown, solid for
         * finished, a partial bar for in progress.
         */
        .reader-nav-seg[data-state="unknown"] { border-style: dashed; background: transparent; }

        .reader-nav-fill {
            position: absolute;
            inset-block: 0;
            inset-inline-start: 0;
            inline-size: var(--fill, 0%);
            background: var(--vermilion-500);
        }

        .reader-nav-seg[data-state="read"] { border-color: var(--moss-400); background: rgb(74 222 128 / .2); }
        .reader-nav-seg[data-state="read"] .reader-nav-fill { background: var(--moss-400); inline-size: 100%; }
        .reader-nav-seg[data-state="partial"] { border-color: var(--vermilion-500); }

        /*
         * The current chapter, ringed rather than tinted, so it is distinguishable
         * from a finished one at a glance and from a colour-blind reader.
         */
        .reader-nav-seg[data-current="true"] {
            outline: 2px solid var(--ink-50);
            outline-offset: 1px;
        }

        .reader-nav-seg:hover { border-color: var(--ink-200); }
        .reader-nav-seg:focus-visible { outline: 2px solid var(--vermilion-500); outline-offset: 2px; }

        /*
         * Room for the bar at the bottom of the page, so the last chapter's final
         * panel is not permanently under it.
         */
        body.has-nav #pages { padding-block-end: 4.5rem; }

        /*
         * The navigator's height, declared on the body rather than on the navigator.
         *
         * A custom property inherits downwards, and the three corner controls are
         * siblings of the navigator, not descendants. Declared on `.reader-nav` it was
         * invisible to them, they fell back to 0px, and they sat exactly where the
         * navigator was: measured, the legend at x 1323 to 1413 with the mode switch and
         * the back link on top of it.
         *
         * On the body it reaches everything on the page, which is what "the bottom edge
         * is occupied" means. Zero when there is no navigator, which is the case for most
         * series in a fresh library.
         */
        body { --reader-nav-height: 0px; }

        body.has-nav { --reader-nav-height: 3.5rem; }

        @media (min-width: 640px) {
            .reader-panel {
                inset-block: 0 0 auto;
                inset-inline-start: auto;
                border-block-start: 0;
                border-inline-start: 1px solid rgb(255 255 255 / .07);
                max-inline-size: 22rem;
            }
        }
    </style>
</head>

{{--
    The reader's configuration, in the DOM rather than in the script.

    @js() emits JSON.parse('...') with the slashes escaped, which is fine for the
    browser and unreadable for anything inspecting the page, including the tests.
    As a data attribute the browser hands it back verbatim through dataset, so
    there is one copy of the config and it can be read from either side.

    JSON_UNESCAPED_SLASHES is for the same reason. The other three flags stay:
    the attribute is delimited by single quotes and JSON_HEX_APOS keeps the value
    from closing it, while HEX_TAG and HEX_AMP keep a URL from closing the tag or
    starting an entity.
--}}
<body
    data-mode="{{ $settings['mode'] }}"
    data-long-strip="{{ $settings['long_strip'] ? 'true' : 'false' }}"
    data-fit-height="{{ $settings['fit_height'] ? 'true' : 'false' }}"
    data-hide-tap-zones="{{ $settings['hide_tap_zones'] ? 'true' : 'false' }}"
    data-reader-config='{{ json_encode([
        'progressUrl' => route('reader.progress', ['manga' => $mangaId, 'chapter' => $chapterId]),
        'settingsUrl' => route('reader.settings', ['manga' => $mangaId]),
    'filtersUrl' => route('reader.filters', ['manga' => $mangaId]),
        'filtersResetUrl' => route('reader.filters.clear', ['manga' => $mangaId]),

        // Scoped to the chapter as well as the series, unlike settings and filters.
        'noteUrl' => route('reader.note', ['manga' => $mangaId, 'chapter' => $chapterId]),

    /*
     * The values a reset returns to, so the script has one source of truth for
     * "off" rather than hardcoding it in two places. The neutral of a filter is 1.0
     * for brightness and contrast, 0 degrees for hue, and false for the toggles.
     */
    'filterNeutral' => [
        'brightness' => 1.0,
        'contrast' => 1.0,
        'hue' => 0,
        'invert' => false,
        'grayscale' => false,
    ],
        'total' => $pageCount,
        'resumeAt' => $resumeAt,

        /*
         * The resolved settings, so the module and the first paint agree without
         * a round trip. The server has already clamped them.
         */
        'settings' => $settings,

        /*
         * Every string the module writes. Kept here rather than in the module so
         * the reader's locale reaches the browser through the same channel as
         * its settings.
         */
        'strings' => [
            'previousPage' => __('Previous page'),
            'nextPage' => __('Next page'),
            'filterTemporary' => __('Temporary, for this visit only.'),
            'noteSaving' => __('Saving...'),
            'noteFailed' => __('Could not save the note.'),
            'noteSaved' => __('Saved.'),
            'noteCleared' => __('Note cleared.'),
        ],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) }}'
    {{--
    has-nav reserves room at the bottom of the page so the last chapter's final
    panel is not permanently under the bar. Set server-side rather than from the
    script, because the script runs after first paint and the reservation has to
    be in the first layout.
--}}
class="reader {{ $settings['mode'] === 'paged' ? 'reader-visible' : '' }} {{ $navigator['chapters'] !== [] ? 'has-nav' : '' }}"
>
    {{-- Chapter progress as a hairline, drawn by the compositor. The scroll
         timeline means no script and no scroll handler: it cannot compete with
         the page tracking, it simply cannot be wrong about how far down the
         chapter the reader is. Paged mode has no document scroll, so it hides. --}}
    <div class="reader-progress" aria-hidden="true"></div>

    {{--
        Top chrome, always on screen. The back link and the page counter share
        one bar so a reader deep in a long strip never loses either. The counter
        keeps the server-rendered spans, so the place in the chapter is correct
        before any script runs.
    --}}
    <header class="reader-topbar" data-test="reader-topbar">
        <a href="{{ route('series', ['manga' => $mangaId]) }}"
           class="reader-back"
           data-test="reader-back"
           aria-label="{{ __('Back to the series') }}"
           title="{{ __('Back to the series') }}">
            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            <span>{{ __('Back to the series') }}</span>
        </a>

        <p class="reader-counter" data-test="page-counter" aria-hidden="true">
            <span data-reader-current>{{ $resumeAt + 1 }}</span> / <span data-reader-total>{{ $pageCount }}</span>
        </p>
    </header>

    {{--
        Resume after the first image rather than jumping the scroll position: on a
        cold cache a jump fights lazy loading, so the reader sees the top of the
        page and then scrolls to their place instead of a blank frame.
    --}}
    <div id="pages" @class(['zoomed' => $settings['zoom'] > 1.0])>
        @for ($index = 0; $index < count($pages); $index++)
            @php($pageSrc = $pages[$index])
            @php($pageSrcset = \App\Support\ImageUrl::responsiveSet($pageSrc, [320, 480, 720, 960, 1200]))
            <img
                src="{{ $pageSrc }}"
                srcset="{{ $pageSrcset }}"
                sizes="(max-width: 640px) 100vw, (max-width: 1024px) 80vw, 60vw"
                alt="{{ __('Page :number', ['number' => $index + 1]) }}"
                loading="{{ $index < 3 ? 'eager' : 'lazy' }}"
                decoding="{{ $index < 2 ? 'sync' : 'async' }}"
                width="1200"
                height="1800"
                data-page="{{ $index }}"
            >
        @endfor
    </div>

    {{-- Paged mode reuses the same URLs, so switching modes costs no request. --}}
    <div id="paged" aria-hidden="true">
        <div id="paged-track">
            @foreach ($pages as $page)
                @php($srcset = \App\Support\ImageUrl::responsiveSet($page, [320, 480, 720, 960, 1200]))
                <img
                    src="{{ $page }}"
                    srcset="{{ $srcset }}"
                    sizes="(max-width: 640px) 100vw, (max-width: 1024px) 80vw, 60vw"
                    alt="{{ __('Page :number', ['number' => $loop->iteration]) }}"
                    loading="{{ $loop->index < 3 ? 'eager' : 'lazy' }}"
                    decoding="async"
                    width="1200"
                    height="1800"
                    data-page="{{ $loop->index }}"
                >
            @endforeach
        </div>
    </div>

    @if ($pages === [])
        <p style="padding: 4rem 1rem; text-align: center">
            {{ __('The source returned no pages for this chapter.') }}
        </p>
    @endif

    {{--
        Tap zones over the outer margins. Links rather than divs so they are
        reachable by keyboard, and omitted entirely when there is no chapter that
        way, since a dead zone is a control that does nothing.
    --}}
    @if ($neighbours['previous'] !== null)
        <a href="{{ route('reader.show', ['manga' => $mangaId, 'chapter' => $neighbours['previous']]) }}"
           class="tap-zone tap-zone-prev"
           aria-label="{{ __('Previous chapter') }}"></a>
    @endif

    @if ($neighbours['next'] !== null)
        <a href="{{ route('reader.show', ['manga' => $mangaId, 'chapter' => $neighbours['next']]) }}"
           class="tap-zone tap-zone-next"
           aria-label="{{ __('Next chapter') }}"></a>
    @endif

    <button type="button" class="reader-settings-toggle"
            aria-label="{{ __('Reader settings') }}"
            title="{{ __('Reader settings') }}"
            aria-expanded="false"
            aria-controls="reader-panel">
        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
        </svg>
    </button>

    {{--
        The note toggle. A button rather than part of the settings drawer because a
        reader thinks "remember this" while looking at a page, and the settings drawer
        is a page of controls they opened to change the layout.
    --}}
    <button type="button" class="reader-note-toggle"
            aria-label="{{ __('Note on this chapter') }}"
            title="{{ __('Note on this chapter') }}"
            aria-expanded="false"
            aria-controls="reader-note-panel"
            @class(['has-note' => $note !== ''])>
        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897l12.682-12.68z" />
        </svg>
        @if ($note !== '')
            <span class="reader-note-dot" aria-hidden="true"></span>
        @endif
    </button>

    <button type="button" class="reader-toggle" aria-label="{{ __('Switch reading mode') }}" title="{{ __('Switch reading mode') }}">
        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5" />
        </svg>
    </button>

    {{--
        The settings drawer.

        A panel rather than a row of fixed buttons, because the settings are a set
        and a reader expects to see all of them at once: mode, width, gap, zoom,
        background, and the two behaviours that change how pages are laid out.

        Hidden by default and toggled from one corner button, so the reader's own
        surface is only ever the pages.
    --}}
    {{--
        The note panel.

        Server rendered so the note is in the first paint and survives a script
        failure, which is a real case here: the reader is a plain document and its
        script has no framework to fall back on.

        One textarea and one save button rather than autosave. Autosave on a reader
        would write on every keystroke, and a note is the one thing here a reader types
        deliberately rather than adjusts. An explicit save also means a half-typed note
        is not on disk when the tab closes by accident.

        The character count is a ceiling, not a target, and it is only shown when there
        is something to count, so an empty box does not carry a number.
    --}}
    <div class="reader-panel reader-note-panel" id="reader-note-panel" data-reader-note-panel hidden>
        <div class="reader-panel-body">
            <label for="reader-note" class="reader-note-label">{{ __('Note on this chapter') }}</label>

            <textarea
                id="reader-note"
                data-reader-note
                rows="6"
                maxlength="10000"
                class="reader-note-input"
                placeholder="{{ __('What do you want to remember about this chapter?') }}"
            >{{ $note }}</textarea>

            <div class="reader-note-actions">
                <span class="reader-note-state" data-reader-note-state role="status" aria-live="polite"></span>

                <span class="reader-note-count" data-reader-note-count @if ($note === '') hidden @endif>
                    <span data-reader-note-count-value>{{ mb_strlen($note) }}</span>
                    <span class="reader-panel-note">/ 10000</span>
                </span>

                <button type="button" class="reader-note-save" data-reader-note-save>
                    {{ __('Save note') }}
                </button>
            </div>

            <p class="reader-panel-note reader-note-hint">
                {{ __('Saved on this chapter only. Empty clears it.') }}
            </p>
        </div>
    </div>

    <div class="reader-panel" id="reader-panel" data-reader-panel hidden>
        <div class="reader-panel-body">
            <h2 style="margin: 0; font-size: 1rem; color: var(--ink-200);">{{ __('Reader settings') }}</h2>

            <fieldset data-test="settings-group-reading">
                <legend>{{ __('Reading') }}</legend>
                <p class="reader-panel-note">{{ __('How pages turn.') }}</p>

                <div class="reader-segments" role="group" aria-label="{{ __('Reading mode') }}">
                    <button type="button" data-setting="mode" data-value="vertical"
                            aria-pressed="{{ $settings['mode'] === 'vertical' ? 'true' : 'false' }}">
                        {{ __('Scroll') }}
                    </button>
                    <button type="button" data-setting="mode" data-value="paged"
                            aria-pressed="{{ $settings['mode'] === 'paged' ? 'true' : 'false' }}">
                        {{ __('Paged') }}
                    </button>
                </div>
            </fieldset>

            <fieldset data-test="settings-group-pages">
                <legend>{{ __('Pages') }}</legend>
                <p class="reader-panel-note">{{ __('Size and surface of each page.') }}</p>

                <label>
                    <span>{{ __('Column width') }} <output data-output="width">{{ $settings['width'] }}%</output></span>
                    <input type="range" data-setting="width"
                           min="{{ $settings['min_width'] }}" max="{{ $settings['max_width'] }}" step="5"
                           value="{{ $settings['width'] }}">
                </label>

                <label>
                    <span>{{ __('Gap') }} <output data-output="gap">{{ $settings['gap'] }}px</output></span>
                    <input type="range" data-setting="gap"
                           min="0" max="32" step="2"
                           value="{{ $settings['gap'] }}">
                </label>

                <label>
                    <span>{{ __('Zoom') }} <output data-output="zoom">{{ round((float) $settings['zoom'], 2) }}&times;</output></span>
                    <input type="range" data-setting="zoom"
                           min="{{ $settings['min_zoom'] }}" max="{{ $settings['max_zoom'] }}" step="0.1"
                           value="{{ $settings['zoom'] }}">
                </label>

                <fieldset>
                    <legend>{{ __('Background') }}</legend>
                    <div class="reader-segments" role="group">
                        @foreach (['#0a0a0a' => __('Dark'), '#18181b' => __('Softer'), '#ffffff' => __('Light')] as $colour => $label)
                            <button type="button" data-setting="background" data-value="{{ $colour }}"
                                    class="reader-swatch"
                                    style="--swatch: {{ $colour }}"
                                    aria-pressed="{{ $settings['background'] === $colour ? 'true' : 'false' }}"
                                    aria-label="{{ $label }}"></button>
                        @endforeach
                    </div>
                </fieldset>

                <label class="reader-check">
                    <input type="checkbox" data-setting="fit_height"
                           @checked($settings['fit_height'])>
                    <span>{{ __('Fit the whole page on screen') }}</span>
                </label>

                <label class="reader-check">
                    <input type="checkbox" data-setting="long_strip"
                           @checked($settings['long_strip'])>
                    <span>{{ __('Long strips: never shrink to fit') }}</span>
                </label>

                <label class="reader-check">
                    <input type="checkbox" data-setting="hide_tap_zones"
                           @checked($settings['hide_tap_zones'])>
                    <span>{{ __('Hide the tap zones') }}</span>
                </label>

                <p class="reader-panel-note">{{ __('Saved for this series only.') }}</p>
            </fieldset>

            {{--
                Colour filters.

                Session-scoped by default, which is the point: a reader dimming the
                page is adjusting for the light they are in now, and a filter that
                followed them to a desk tomorrow would be a filter they have to undo.
                "Remember for this series" is the explicit way to make it durable, and
                it is a checkbox rather than a second button because a reader who never
                sees it should not have to think about it.
            --}}
            <fieldset data-test="settings-group-filters">
                <legend>{{ __('Filters') }}</legend>
                <p class="reader-panel-note">{{ __('Colour filters change how pages look. They stay for this visit unless you keep them.') }}</p>

                <label class="reader-slider">
                    <span>{{ __('Brightness') }}</span>
                    <input type="range" data-filter="brightness"
                           min="{{ $settings['min_brightness'] }}"
                           max="{{ $settings['max_brightness'] }}"
                           step="0.05"
                           value="{{ $settings['brightness'] }}">
                    <output data-filter-output="brightness">{{ (int) round((float) $settings['brightness'] * 100) }}%</output>
                </label>

                <label class="reader-slider">
                    <span>{{ __('Contrast') }}</span>
                    <input type="range" data-filter="contrast"
                           min="{{ $settings['min_contrast'] }}"
                           max="{{ $settings['max_contrast'] }}"
                           step="0.05"
                           value="{{ $settings['contrast'] }}">
                    <output data-filter-output="contrast">{{ (int) round((float) $settings['contrast'] * 100) }}%</output>
                </label>

                <label class="reader-slider">
                    <span>{{ __('Hue') }}</span>
                    <input type="range" data-filter="hue"
                           min="0" max="360" step="1"
                           value="{{ $settings['hue'] }}">
                    <output data-filter-output="hue">{{ (int) $settings['hue'] }}&deg;</output>
                </label>

                <div class="reader-segments" role="group" aria-label="{{ __('Colour filters') }}">
                    <button type="button" data-filter-toggle="invert"
                            class="reader-seg {{ $settings['invert'] ? 'is-on' : '' }}"
                            aria-pressed="{{ $settings['invert'] ? 'true' : 'false' }}">
                        {{ __('Invert') }}
                    </button>

                    <button type="button" data-filter-toggle="grayscale"
                            class="reader-seg {{ $settings['grayscale'] ? 'is-on' : '' }}"
                            aria-pressed="{{ $settings['grayscale'] ? 'true' : 'false' }}">
                        {{ __('No colour') }}
                    </button>

                    <button type="button" data-filter-reset class="reader-seg">
                        {{ __('Reset') }}
                    </button>
                </div>

                <p class="reader-panel-note">{{ __('Colour filters') }}</p>

                <label class="reader-check">
                    <input type="checkbox" data-filter-remember
                           @checked($settings['sticky_filters'] || $hasSessionOverride)>
                    <span>{{ __('Remember for this series') }}</span>
                </label>

                {{-- Says which layer the current values live in, because that is the
                     whole decision and a reader who cannot see it will not trust it. --}}
                <p class="reader-panel-note" data-filter-state aria-live="polite">
                    @if ($hasSessionOverride)
                        {{ __('Temporary, for this visit only.') }}
                    @else
                        {{ __('Saved for this series only.') }}
                    @endif
                </p>
            </fieldset>
        </div>
    </div>

    {{--
        The chapter navigator.

        Boundary ticks, a read map, and cross-chapter seek, which are the same data
        seen three ways: one segment per chapter in source order, filled by how much
        of it is read, clickable to jump.

        The three fill states are the honest part. Measured on the dev library, 12 of
        575 chapters carry a pageCount, because it is -1 until a chapter's pages have
        been fetched. So a fraction is computable for 2% of a library, and a strip with
        two states would look identical whether a segment was untouched or unread for
        reasons we cannot compute. A legend states the three rather than leaving a
        reader to infer them.
    --}}
    @if ($navigator['chapters'] !== [])
        @php($navigatorCounts = $navigator['counts'])
        @php($navigatorPosition = app(\App\Library\ChapterNavigator::class)
            ->position($navigator['chapters'], $chapterId))

        <nav class="reader-nav" aria-label="{{ __('Chapters in this series') }}">
            <div class="reader-nav-head">
                <span class="reader-nav-where">
                    {{ trans_choice(
                        'Chapter :index of :total|Chapter :index of :total',
                        $navigatorPosition['total'],
                        ['index' => $navigatorPosition['index'], 'total' => $navigatorPosition['total']],
                    ) }}
                </span>

                {{-- The legend, because the three states are not guessable. --}}
                <span class="reader-nav-legend">
                    @if ($navigatorCounts['read'] > 0)
                        <span class="reader-nav-key">
                            <span class="reader-nav-swatch" data-state="read" aria-hidden="true"></span>
                            {{ trans_choice(':count finished|:count finished', $navigatorCounts['read'], ['count' => $navigatorCounts['read']]) }}
                        </span>
                    @endif

                    @if ($navigatorCounts['partial'] > 0)
                        <span class="reader-nav-key">
                            <span class="reader-nav-swatch" data-state="partial" aria-hidden="true"></span>
                            {{ trans_choice(':count part read|:count part read', $navigatorCounts['partial'], ['count' => $navigatorCounts['partial']]) }}
                        </span>
                    @endif

                    <span class="reader-nav-key">
                        <span class="reader-nav-swatch" data-state="unknown" aria-hidden="true"></span>
                        {{ trans_choice(':count not started|:count not started', $navigatorCounts['unknown'], ['count' => $navigatorCounts['unknown']]) }}
                    </span>
                </span>
            </div>

            {{--
                One button per chapter. A button rather than a div because a segment is
                a control: it takes focus, it answers Enter, and a screen reader can
                count them. The fill is a child span rather than a background so the
                three states are distinguishable and not only a difference in shade.
            --}}
            <ol class="reader-nav-strip">
                @foreach ($navigator['chapters'] as $one)
                    <li>
                        <a
                            href="{{ route('reader.show', ['manga' => $mangaId, 'chapter' => $one['id']]) }}"
                            wire:navigate
                            class="reader-nav-seg"
                            data-state="{{ $one['state'] }}"
                            data-current="{{ $one['is_current'] ? 'true' : 'false' }}"
                            aria-current="{{ $one['is_current'] ? 'true' : 'false' }}"
                            style="--fill: {{ number_format($one['progress'] * 100, 1, '.', '') }}%"
                            title="{{ $one['name'] !== '' ? $one['name'] : __('Chapter :id', ['id' => $one['id']]) }}{{ $one['state'] === 'partial' ? ': '.__(':read of :pages', ['read' => $one['page'], 'pages' => $one['pages']]) : '' }}"
                        >
                            <span class="reader-nav-fill" aria-hidden="true"></span>
                            <span class="sr-only">
                                {{ $one['name'] !== '' ? $one['name'] : __('Chapter :id', ['id' => $one['id']]) }}
                                @if ($one['state'] === 'read')
                                    {{ __('(finished)') }}
                                @elseif ($one['state'] === 'partial')
                                    {{ __('(:read of :pages read)', ['read' => $one['page'], 'pages' => $one['pages']]) }}
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    @vite('resources/js/reader.js')
</body>
</html>
