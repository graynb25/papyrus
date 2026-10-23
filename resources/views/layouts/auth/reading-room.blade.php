<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-svh bg-zinc-950 text-zinc-50 antialiased">
        {{--
            The Reading Room. The login screen is a room, and the room is built
            out of the collection itself: four rows of covers drifting at
            different depths behind a glass panel. It is always dark here,
            whatever the app's theme says, because covers carry the colour and
            a dark room is how they are meant to be seen.

            The wall fills in from /showcase, which serves only in single-reader
            deployments (see ShowcaseController for the reasoning). A fresh
            install, a shared server, or the switch turned off all arrive here
            with nothing, and the room is then painted instead: a procedural
            ink garden, seeded so it is the same room every visit.
        --}}
        <div class="rr-scene" data-rr-scene aria-hidden="true">
            <div class="rr-ambient" data-rr-ambient></div>
            <canvas data-rr-ink class="absolute inset-0 size-full"></canvas>
            <div class="rr-wall" data-rr-wall></div>
            <div class="rr-vignette"></div>
        </div>

        <div class="relative z-10 flex min-h-svh flex-col p-6 sm:p-10 lg:p-14">
            {{-- Brand, over the wall --}}
            <a href="{{ route('home') }}" class="flex w-fit items-center gap-3" tabindex="-1" aria-hidden="true">
                <span class="seal size-11">
                    <x-app-logo-icon class="size-5 fill-current" />
                </span>
                <span class="font-display text-lg font-semibold tracking-tight text-zinc-50">{{ config('app.name', 'Papyrus') }}</span>
            </a>

            {{--
                The promise comes first in the reading order, so a reader (and a
                screen reader) meets it before the card. The card then sits in the
                upper third of the viewport rather than dead centre, which keeps
                the footer anchored at the bottom of the room.
            --}}
            <div class="flex flex-1 items-start justify-center pt-8 sm:pt-12 lg:pt-16 lg:justify-end">
                <div class="w-full max-w-sm animate-rise-in">
                    <p class="serif-italic mb-6 text-center text-sm leading-relaxed text-zinc-200 [text-shadow:0_1px_12px_rgb(0_0_0/0.9)] lg:text-end">
                        {{ __('Your whole library, every source, one quiet room.') }}
                    </p>

                    <div class="glass glass-strong flex flex-col gap-6 rounded-3xl p-7 sm:p-8">
                        {{ $slot }}
                    </div>
                </div>
            </div>

            {{-- The shell's footer. A claim on a sign-in screen that cannot be verified is worth
                 exactly as much as the reader's suspicion of it, which is usually less; the shared
                 footer keeps that promise standing next to the links that serve it. --}}
            <x-auth-footer />
        </div>

        <script>
            (function () {
                'use strict';

                var scene = document.querySelector('[data-rr-scene]');
                if (!scene) {
                    return;
                }

                var wall = scene.querySelector('[data-rr-wall]');
                var ink = scene.querySelector('[data-rr-ink]');
                var ambient = scene.querySelector('[data-rr-ambient]');
                var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                var showcaseUrl = @json(route('showcase.index'));

                // ------------------------------------------------------------ ink garden
                // The room before the collection exists. Deterministic: the same
                // seed paints the same garden on every visit, so the fallback is
                // a place rather than a screensaver.
                function paintInkGarden() {
                    if (!ink) {
                        return;
                    }

                    var ctx = ink.getContext('2d');
                    var dpr = Math.min(window.devicePixelRatio || 1, 2);

                    function size() {
                        ink.width = Math.floor(window.innerWidth * dpr);
                        ink.height = Math.floor(window.innerHeight * dpr);
                    }

                    size();
                    window.addEventListener('resize', function () {
                        size();
                        paint();
                    });

                    var seed = 987654321;

                    function rnd() {
                        seed = (seed * 1664525 + 1013904223) % 4294967296;
                        return seed / 4294967296;
                    }

                    var strokes = [];
                    var total = 110;

                    for (var i = 0; i < total; i++) {
                        strokes.push({
                            x: rnd() * ink.width,
                            y: rnd() * ink.height,
                            angle: rnd() * Math.PI * 2,
                            length: (0.04 + rnd() * 0.12) * ink.width,
                            width: (0.5 + rnd() * 2.6) * dpr,
                            bend: (rnd() - 0.5) * 0.9,
                            alpha: 0.025 + rnd() * 0.075,
                            accent: rnd() < 0.07,
                        });
                    }

                    strokes.sort(function (a, b) { return a.width - b.width; });

                    var cursor = 0;

                    function stroke(s) {
                        ctx.beginPath();
                        ctx.moveTo(s.x, s.y);

                        var midX = s.x + Math.cos(s.angle + s.bend * 0.5) * s.length * 0.5;
                        var midY = s.y + Math.sin(s.angle + s.bend * 0.5) * s.length * 0.5;
                        var endX = s.x + Math.cos(s.angle + s.bend) * s.length;
                        var endY = s.y + Math.sin(s.angle + s.bend) * s.length;

                        ctx.quadraticCurveTo(midX, midY, endX, endY);
                        ctx.lineCap = 'round';
                        ctx.lineWidth = s.width;
                        ctx.strokeStyle = s.accent
                            ? 'rgba(255, 106, 69, ' + (s.alpha + 0.05) + ')'
                            : 'rgba(214, 219, 235, ' + s.alpha + ')';
                        ctx.stroke();
                    }

                    function paint() {
                        while (cursor < strokes.length) {
                            stroke(strokes[cursor]);
                            cursor++;
                        }
                    }

                    if (reduce) {
                        paint();

                        return;
                    }

                    // The garden grows in over about two seconds, then the
                    // loop stops and the page is static again.
                    (function grow() {
                        for (var step = 0; step < 4 && cursor < strokes.length; step++) {
                            stroke(strokes[cursor]);
                            cursor++;
                        }

                        if (cursor < strokes.length) {
                            requestAnimationFrame(grow);
                        }
                    })();

                    ambient.style.setProperty('--rr-glow-1', 'rgba(124, 45, 18, 0.32)');
                    ambient.style.setProperty('--rr-glow-2', 'rgba(30, 32, 44, 0.5)');
                }

                // ------------------------------------------------------------ the wall
                var DEPTHS = [0.7, 0.15, 0.35, 0.9];
                var SPEEDS = [150, 95, 125, 175];

                function buildWall(covers) {
                    if (!covers || !covers.length) {
                        paintInkGarden();

                        return;
                    }

                    if (ink && ink.parentNode) {
                        ink.parentNode.removeChild(ink);
                    }

                    var itemWidth = 176; // the widest clamp step, plus rounding
                    var perRow = Math.min(14, Math.max(6, Math.ceil((window.innerWidth * 1.5) / itemWidth)));

                    DEPTHS.forEach(function (depth, rowIndex) {
                        var row = document.createElement('div');
                        row.className = 'rr-row';
                        row.style.setProperty('--rr-depth', depth);
                        row.style.animationDelay = (rowIndex * 90) + 'ms';

                        if (depth >= 0.6) {
                            row.setAttribute('data-rr-far', '');
                        }

                        var track = document.createElement('div');
                        track.className = 'rr-track';
                        track.style.setProperty('--rr-speed', SPEEDS[rowIndex] + 's');

                        // One pass through the row's picks, then the same pass
                        // again: the drift keyframe shifts exactly half the
                        // track, so the loop has no seam.
                        for (var pass = 0; pass < 2; pass++) {
                            for (var k = 0; k < perRow; k++) {
                                var cover = covers[(rowIndex * 3 + k) % covers.length];
                                var item = document.createElement('div');
                                item.className = 'rr-item';

                                var img = document.createElement('img');
                                img.src = cover.url;
                                img.alt = '';
                                img.decoding = 'async';
                                img.draggable = false;
                                img.className = 'img-blur-up';
                                img.setAttribute('data-blur-up', '');
                                img.addEventListener('error', function (event) {
                                    var holder = event.target.parentNode;
                                    if (holder && holder.parentNode) {
                                        holder.parentNode.removeChild(holder);
                                    }
                                });

                                item.appendChild(img);
                                track.appendChild(item);
                            }
                        }

                        row.appendChild(track);
                        wall.appendChild(row);
                    });

                    collectPalette();
                }

                // ------------------------------------------------------------ palette
                // The room takes its light from the art. Average a handful of
                // covers into two ambient glows behind the wall.
                function collectPalette() {
                    var imgs = wall.querySelectorAll('img');
                    var colors = [];

                    function sample() {
                        if (colors.length >= 6) {
                            return;
                        }

                        var canvas = document.createElement('canvas');
                        canvas.width = 8;
                        canvas.height = 12;

                        var ctx = canvas.getContext('2d', { willReadFrequently: true });

                        for (var i = 0; i < imgs.length && colors.length < 6; i++) {
                            if (!imgs[i].complete || imgs[i].naturalWidth === 0 || imgs[i].__sampled) {
                                continue;
                            }

                            imgs[i].__sampled = true;

                            try {
                                ctx.drawImage(imgs[i], 0, 0, 8, 12);

                                var data = ctx.getImageData(0, 0, 8, 12).data;
                                var r = 0;
                                var g = 0;
                                var b = 0;
                                var n = data.length / 4;

                                for (var p = 0; p < data.length; p += 4) {
                                    r += data[p];
                                    g += data[p + 1];
                                    b += data[p + 2];
                                }

                                colors.push('rgb(' + Math.round(r / n) + ', ' + Math.round(g / n) + ', ' + Math.round(b / n) + ')');
                            } catch (error) {
                                // A cover that cannot be read stays unread; the
                                // wall does not need it.
                            }
                        }

                        if (colors.length > 0 && ambient) {
                            ambient.style.setProperty('--rr-glow-1', colors[0]);
                            ambient.style.setProperty('--rr-glow-2', colors[colors.length - 1]);
                        }
                    }

                    scene.addEventListener('load', sample, true);
                    window.setTimeout(sample, 4000);
                }

                // ------------------------------------------------------------ parallax
                function parallax() {
                    if (reduce || !window.matchMedia('(pointer: fine)').matches) {
                        return;
                    }

                    var targetX = 0;
                    var targetY = 0;
                    var x = 0;
                    var y = 0;
                    var frame = null;

                    function step() {
                        x += (targetX - x) * 0.06;
                        y += (targetY - y) * 0.06;

                        scene.style.setProperty('--rr-mx', x.toFixed(4));
                        scene.style.setProperty('--rr-my', y.toFixed(4));

                        if (Math.abs(targetX - x) > 0.001 || Math.abs(targetY - y) > 0.001) {
                            frame = requestAnimationFrame(step);
                        } else {
                            frame = null;
                        }
                    }

                    document.addEventListener('pointermove', function (event) {
                        targetX = (event.clientX / window.innerWidth) * 2 - 1;
                        targetY = (event.clientY / window.innerHeight) * 2 - 1;

                        if (frame === null) {
                            frame = requestAnimationFrame(step);
                        }
                    }, { passive: true });
                }

                parallax();

                fetch(showcaseUrl, { headers: { Accept: 'application/json' } })
                    .then(function (response) {
                        return response.ok ? response.json() : { covers: [] };
                    })
                    .then(function (payload) {
                        buildWall(payload.covers || []);
                    })
                    .catch(function () {
                        paintInkGarden();
                    });
            })();
        </script>

        @include('partials.behaviors')

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
