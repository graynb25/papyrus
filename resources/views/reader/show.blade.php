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
         * All three share one rule on purpose. They were three separate rules once
         * and the settings button ended up unpositioned and unlayered, sitting in
         * the flow under the tap zones, which no amount of reading the CSS would
         * have made obvious. One rule, three offsets.
         *
         * Above the tap zones at z-index 20 so a corner control is always
         * clickable, and along the bottom edge because that is where a thumb is
         * and the top of a reader belongs to the artwork.
         */
        .reader-back,
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

        .reader-back {
            inset-inline-end: 1rem;
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            width: auto;
            min-width: 2.5rem;
            padding: .5rem .75rem;
            border-radius: 9999px;
            text-decoration: none;
            white-space: nowrap;
            font-size: .75rem;
            font-weight: 500;
            color: var(--ink-200);
        }

        .reader-back svg { flex-shrink: 0; }
        .reader-toggle { inset-inline-end: 4rem; }
        .reader-settings-toggle { inset-inline-end: 7rem; }

        /*
         * A fourth control, so a fourth offset at the same 3rem spacing.
         *
         * The note toggle is last because it is the least used: reading a chapter needs
         * settings far more often than it needs to write about one, and the corner
         * nearest the back link is the easiest to reach by accident.
         */
        .reader-note-toggle { inset-inline-end: 10rem; }

        .reader-back:hover,
        .reader-toggle:hover,
        .reader-settings-toggle:hover,
        .reader-note-toggle:hover {
            background: color-mix(in oklab, var(--ink-800) 92%, transparent);
            border-color: color-mix(in oklab, white 16%, transparent);
        }

        .reader-back:active,
        .reader-toggle:active,
        .reader-settings-toggle:active,
        .reader-note-toggle:active { transform: scale(.94); }

        /* Visible to a keyboard, which is how a control like this is found. */
        .reader-back:focus-visible,
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

    <div class="page-marker" aria-hidden="true">
        <span data-reader-current>{{ $resumeAt + 1 }}</span> / <span data-reader-total>{{ $pageCount }}</span>
    </div>

    {{--
        Resume after the first image rather than jumping the scroll position: on a
        cold cache a jump fights lazy loading, so the reader sees the top of the
        page and then scrolls to their place instead of a blank frame.
    --}}
    <div id="pages" @class(['zoomed' => $settings['zoom'] > 1.0])>
        @for ($index = 0; $index < count($pages); $index++)
            <img
                src="{{ $pages[$index] }}"
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
                <img
                    src="{{ $page }}"
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
            <fieldset>
                <legend>{{ __('Reading mode') }}</legend>
                <div class="reader-segments" role="group">
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

            {{--
                Colour filters.

                Session-scoped by default, which is the point: a reader dimming the
                page is adjusting for the light they are in now, and a filter that
                followed them to a desk tomorrow would be a filter they have to undo.
                "Remember for this series" is the explicit way to make it durable, and
                it is a checkbox rather than a second button because a reader who never
                sees it should not have to think about it.
            --}}
            <fieldset>
                <legend>{{ __('Colour filters') }}</legend>

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

                <div class="reader-segments" role="group">
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
                            style="--fill: {{ number_format($one['progress'] * 100, 1) }}%"
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

    <a href="{{ route('series', ['manga' => $mangaId]) }}"
       class="reader-back"
       aria-label="{{ __('Back to the series') }}"
       title="{{ __('Back to the series') }}">
        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
        <span>{{ __('Back to the series') }}</span>
    </a>


    <script>
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
        (function () {
            'use strict';

            var settings = @js($settings);

            var body = document.body;

        /*
         * The remember checkbox, resolved once. Held as a variable because the
         * filter code reads it from three places and each one re-querying would
         * be three chances to get the selector subtly wrong.
         */
        var rememberInput = document.querySelector('[data-filter-remember]');
            var config = JSON.parse(body.dataset.readerConfig);
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

            var PAGE_PREVIOUS = @js(__('Previous page'));
            var PAGE_NEXT = @js(__('Next page'));

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
                    brightness: {{ (float) $settings['brightness'] }},
                    contrast: {{ (float) $settings['contrast'] }},
                    hue: {{ (int) $settings['hue'] }},
                    invert: {{ $settings['invert'] ? 'true' : 'false' }},
                    grayscale: {{ $settings['grayscale'] ? 'true' : 'false' }}
                },

                {{--
                    Single-quoted, not json_encode.

                    `{{ json_encode(...) }}` is HTML-escaped by Blade, so the quotes come
                    out as `&quot;` and the script dies with "Unexpected token '&'" the
                    moment it parses. That took the whole reader down: a syntax error in
                    the IIFE means every statement after the throw never runs, so the
                    counter froze, the mode would not switch and taps did nothing, with
                    no error pointing at the reader at all.
                --}}
                mode: '{{ $settings['mode'] }}',
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
                    note.dataset.temporary = '{{ __('Temporary, for this visit only.') }}';
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
                    say('{{ __('Saving...') }}', 'busy');

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

                                    throw new Error(first && first[0] ? first[0] : '{{ __('Could not save the note.') }}');
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

                            say(noted ? '{{ __('Saved.') }}' : '{{ __('Note cleared.') }}', noted ? 'saved' : 'cleared');
                        })
                        .catch(function (error) {
                            say(error.message || '{{ __('Could not save the note.') }}', 'error');
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

        })();
    </script>
</body>
</html>
