<div>
    @if ($visible && $current !== null)
        <div
            wire:key="first-run-tour-{{ $step }}"
            x-data="{
                selector: @js($current['selector']),
                mode: @js($current['mode']),
                rect: null,
                card: { top: 0, left: 0 },
                ready: false,
                minimized: false,
                previousFocus: null,
                observer: null,
                cardObserver: null,
                init() {
                    this.previousFocus = document.activeElement;
                    this.$nextTick(() => {
                        this.measure();

                        /*
                         * The card is positioned while x-show is still applying
                         * its display change, so offsetHeight can be zero on the
                         * first pass and the card lands below a short viewport.
                         * Re-position once the card has a real size so the
                         * buttons stay inside the window.
                         */
                        if (this.$refs.card && typeof ResizeObserver !== 'undefined') {
                            this.cardObserver = new ResizeObserver(() => this.positionCard());
                            this.cardObserver.observe(this.$refs.card);
                        }

                        if (this.mode === 'modal') {
                            this.$nextTick(() => {
                                const primary = this.$refs.card?.querySelector('[data-tour-primary]');
                                primary?.focus();
                            });
                        }
                    });
                    this.observer = new MutationObserver(() => {
                        if (!this.rect || !document.querySelector(this.selector)) {
                            window.requestAnimationFrame(() => this.measure());
                        }
                    });
                    this.observer.observe(document.body, { childList: true, subtree: true });
                },
                destroy() {
                    this.observer?.disconnect();
                    this.cardObserver?.disconnect();
                },
                measure() {
                    const target = document.querySelector(this.selector);
                    if (!target) {
                        this.rect = null;
                        this.ready = true;
                        this.$nextTick(() => this.positionCard());
                        return;
                    }
                    target.scrollIntoView({
                        block: window.innerWidth < 640 ? 'start' : 'center',
                        inline: 'nearest',
                        behavior: 'auto'
                    });
                    this.reposition();
                },
                reposition() {
                    const target = document.querySelector(this.selector);
                    if (!target) {
                        this.rect = null;
                        this.ready = true;
                        this.$nextTick(() => this.positionCard());
                        return;
                    }
                    const r = target.getBoundingClientRect();
                    const pad = 8;
                    const top = Math.max(0, r.top - pad);
                    const left = Math.max(0, r.left - pad);
                    const width = Math.max(0, Math.min(window.innerWidth - left, r.width + pad * 2));
                    const height = Math.max(0, Math.min(window.innerHeight - top, r.height + pad * 2));
                    this.rect = { top, left, width, height };
                    this.ready = true;
                    this.$nextTick(() => this.positionCard());
                },
                positionCard() {
                    const el = this.$refs.card;
                    if (!el) {
                        return;
                    }
                    const margin = 16;
                    const width = el.offsetWidth;
                    const height = el.offsetHeight;
                    if (window.innerWidth < 640) {
                        el.style.width = 'calc(100vw - 2rem)';
                        this.card = {
                            top: Math.max(margin, window.innerHeight - height - margin),
                            left: margin
                        };
                        return;
                    }
                    el.style.width = '';
                    let top;
                    let left;
                    if (this.rect) {
                        top = this.rect.top + this.rect.height + margin;
                        if (top + height > window.innerHeight - margin) {
                            top = this.rect.top - height - margin;
                        }
                        left = this.rect.left + ((this.rect.width - width) / 2);
                    } else {
                        top = (window.innerHeight - height) / 2;
                        left = (window.innerWidth - width) / 2;
                    }
                    top = Math.max(margin, Math.min(window.innerHeight - height - margin, top));
                    left = Math.max(margin, Math.min(window.innerWidth - width - margin, left));
                    this.card = { top, left };
                },
                spotlightStyle() {
                    if (!this.rect) {
                        return 'display: none';
                    }
                    return `top:${this.rect.top}px;left:${this.rect.left}px;width:${this.rect.width}px;height:${this.rect.height}px;box-shadow:0 0 0 9999px rgba(9,9,11,.72);`;
                },
                cardStyle() {
                    return `top:${this.card.top}px;left:${this.card.left}px;`;
                },
                next() {
                    this.$wire.next();
                },
                back() {
                    this.$wire.back();
                },
                done() {
                    this.restoreFocus();
                    this.$wire.finish();
                },
                close() {
                    this.restoreFocus();
                    this.$wire.skip();
                },
                restoreFocus() {
                    if (this.previousFocus instanceof HTMLElement) {
                        this.previousFocus.focus();
                    }
                },
                onKeydown(event) {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        this.close();
                        return;
                    }
                    if (this.mode !== 'modal' || event.key !== 'Tab') {
                        return;
                    }
                    const focusable = this.$refs.card?.querySelectorAll(
                        'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\'-1\'])'
                    );
                    if (!focusable || focusable.length === 0) {
                        return;
                    }
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            }"
            x-on:keydown.window="onKeydown($event)"
            x-on:resize.window.debounce.100ms="reposition()"
            x-on:scroll.window.throttle.100ms="reposition()"
            x-on:livewire:navigated.window="$nextTick(() => measure())"
            class="pointer-events-none fixed inset-0 z-[100] overflow-hidden"
            role="dialog"
            aria-modal="{{ $current['mode'] === 'modal' ? 'true' : 'false' }}"
            aria-labelledby="first-run-tour-title"
            aria-describedby="first-run-tour-body"
        >
            <div
                class="pointer-events-auto absolute inset-0"
                x-show="mode === 'modal'"
                @click.prevent
                aria-hidden="true"
            ></div>

            <div
                class="pointer-events-none absolute rounded-xl border-2 border-vermilion-400 motion-safe:transition-all motion-safe:duration-200"
                x-show="ready && rect"
                :style="spotlightStyle()"
                aria-hidden="true"
            ></div>

            <div
                x-ref="card"
                x-show="ready && !minimized"
                class="pointer-events-auto absolute max-h-[calc(100vh-2rem)] w-[min(22rem,calc(100vw-2rem))] overflow-y-auto rounded-2xl border border-zinc-200/80 bg-white/95 p-5 text-zinc-900 shadow-2xl backdrop-blur dark:border-white/10 dark:bg-zinc-900/95 dark:text-zinc-100 relative"
                :style="cardStyle()"
            >
                @if ($current['mode'] === 'interactive')
                    <flux:button
                        size="xs"
                        variant="ghost"
                        icon="chevron-down"
                        class="absolute right-3 top-3"
                        x-on:click="minimized = true; $nextTick(() => $refs.show?.focus())"
                        aria-label="{{ __('Minimize the tour') }}"
                    >
                        {{ __('Minimize') }}
                    </flux:button>
                @endif

                <p class="text-[0.7rem] font-semibold uppercase tracking-[0.18em] text-vermilion-600 dark:text-vermilion-400">
                    {{ __('Getting started') }}
                </p>

                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Step :step of :total', ['step' => $step, 'total' => $total]) }}
                </p>

                <h2 id="first-run-tour-title" class="mt-2 font-serif text-xl font-semibold">
                    {{ $current['title'] }}
                </h2>

                <p id="first-run-tour-body" class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                    {{ $current['body'] }}
                </p>

                @if ($step === 1)
                    <div class="mt-4">
                        <x-locale-switcher compact :return-to="route('library', absolute: false)" />
                    </div>
                @endif

                <p class="sr-only" aria-live="polite">
                    {{ $current['title'] }}. {{ __('Step :step of :total', ['step' => $step, 'total' => $total]) }}
                </p>

                <div class="mt-5 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        @if ($step > 1 && $step < $total)
                            <flux:button size="sm" variant="ghost" x-on:click="back()">
                                {{ __('Back') }}
                            </flux:button>
                        @endif

                        @if ($current['next'] && $step < $total)
                            <flux:button size="sm" variant="primary" data-tour-primary x-on:click="next()">
                                {{ __('Next') }}
                            </flux:button>
                        @elseif ($step === $total)
                            <flux:button size="sm" variant="primary" data-tour-primary x-on:click="done()">
                                {{ __('Done') }}
                            </flux:button>
                        @endif
                    </div>

                    <flux:button size="sm" variant="ghost" x-on:click="close()">
                        {{ __('Skip') }}
                    </flux:button>
                </div>
            </div>

            <div
                x-show="minimized"
                x-cloak
                class="pointer-events-auto absolute bottom-4 right-4 flex items-center gap-2 rounded-full border border-zinc-200/80 bg-white/95 px-3 py-2 text-zinc-900 shadow-2xl backdrop-blur dark:border-white/10 dark:bg-zinc-900/95 dark:text-zinc-100"
            >
                <span class="text-xs font-medium">{{ __('Tour, step :step of :total', ['step' => $step, 'total' => $total]) }}</span>
                <flux:button x-ref="show" size="xs" variant="outline" x-on:click="minimized = false; $nextTick(() => $refs.card?.querySelector('button')?.focus())">
                    {{ __('Show') }}
                </flux:button>
            </div>
        </div>
    @endif
</div>
