<script>
    {{-- Blur-up: an image marked data-blur-up resolves from its shimmer when it
         decodes. Load events do not bubble, so the listener is capture-phase.
         A settle pass catches images that were already complete when we looked
         (back-forward cache, bfcache restores) or that failed, so a broken cover
         shows rather than staying invisible. --}}
    (function () {
        'use strict';

        function resolve(img) {
            img.classList.add('is-loaded');
        }

        document.addEventListener('load', function (event) {
            var img = event.target;
            if (img && img.tagName === 'IMG' && img.hasAttribute('data-blur-up')) {
                resolve(img);
            }
        }, true);

        document.addEventListener('error', function (event) {
            var img = event.target;
            if (img && img.tagName === 'IMG' && img.hasAttribute('data-blur-up')) {
                resolve(img);
            }
        }, true);

        function settle(root) {
            var scope = root || document;
            var pending = scope.querySelectorAll('img[data-blur-up]');
            for (var i = 0; i < pending.length; i++) {
                if (pending[i].complete) {
                    resolve(pending[i]);
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            settle();
        });

        document.addEventListener('livewire:navigated', function () {
            settle();
        });

        {{-- A morph is the case the load listener cannot see, and it is the common one.

             Every cover on a grid that appends is inserted by a morph: the browse page's
             infinite scroll, the search page streaming one source at a time. Morphdom
             builds the new nodes in a detached container and only then swaps them in, and
             a cover whose bytes are already cached fires its load **while detached**, where
             a listener on document cannot hear it. It then arrives already `complete` and
             nothing ever resolves it, so `.img-blur-up` holds it at `opacity: 0` behind
             the shimmer for ever.

             Measured on /browse/4: the first 20 covers all resolved, and after one append
             there were 40 covers of which 20 were `complete` with real bytes and no
             reveal, and a settle pass revealed all 40. That is the whole reason covers
             "did not load", and it arrived with the redesign because the old markup had a
             plain `<img>` with no opacity to hold it back.

             So the settle pass has to run when the morph lands, not only when the document
             does. `Livewire` is not defined yet here, since `@fluxScripts` comes after this
             include, and `livewire:init` is dispatched at the top of Livewire's own start
             with the hook registry already in place. --}}
        document.addEventListener('livewire:init', function () {
            if (typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
                return;
            }

            Livewire.hook('morphed', function (payload) {
                settle(payload && payload.el);
            });
        });
    })();

    {{-- Shared-element morph: the cover a reader presses becomes the series
         hero. The name goes on the way out and comes off after navigation, so
         it never sits on two live elements at once. --}}
    (function () {
        'use strict';

        document.addEventListener('click', function (event) {
            var target = event.target;
            var art = target && target.closest ? target.closest('[data-vt-cover]') : null;
            if (art) {
                art.style.viewTransitionName = 'cover-hero';
            }
        }, true);

        document.addEventListener('livewire:navigated', function () {
            var named = document.querySelectorAll('[data-vt-cover]');
            for (var i = 0; i < named.length; i++) {
                named[i].style.viewTransitionName = '';
            }
        });
    })();

    {{-- Rating: a radiogroup that moves with the arrow keys.

         The stars are `role="radio"`, which means a group of them should be one tab stop
         with the arrow keys moving within it. Without that a keyboard reader tabs five times
         to reach a five-star control, and five tab stops inside a radiogroup is the one
         arrangement a screen reader will call wrong.

         `sync()` is the same shape as the blur-up settle pass above, for the same reason: the
         server renders `tabindex` once, and a morph replaces it with whatever the new markup
         said. Rating three stars and tabbing away has to leave the third star as the tab
         stop, and only a pass after the morph can arrange that.

         It prefers the star that currently holds focus, because arrowing to the fourth star
         and then tabbing away should come back to the fourth, not jump back to the rated
         one. Focus is checked before the checked star for exactly that reason. --}}
    (function () {
        'use strict';

        function starsOf(group) {
            return group.querySelectorAll('[data-rating-star]');
        }

        function sync(root) {
            var scope = root || document;
            var groups = [];

            /*
             * `root` may be the rating group itself rather than something containing one.
             * The arrow-key handler has the group in hand and passes it straight here, and a
             * descendant-only search finds nothing inside it, so the sync silently did
             * nothing and the tab stop snapped back to the rated star the moment a reader
             * arrowed away from it. Measured: arrowing from star four to star five left star
             * four at tabindex 0.
             */
            if (scope.matches && scope.matches('[data-rating]')) {
                groups.push(scope);
            }

            var found = scope.querySelectorAll('[data-rating]');

            for (var f = 0; f < found.length; f++) {
                groups.push(found[f]);
            }

            for (var g = 0; g < groups.length; g++) {
                var group = groups[g];
                var stars = starsOf(group);

                if (!stars.length) {
                    continue;
                }

                var target = null;
                var active = document.activeElement;

                // Whichever of the three is the star to land on: where the reader is now,
                // else the rated one, else the first.
                if (active && active.hasAttribute && active.hasAttribute('data-rating-star')
                    && group.contains(active)) {
                    target = active;
                } else {
                    for (var i = 0; i < stars.length; i++) {
                        if (stars[i].getAttribute('aria-checked') === 'true') {
                            target = stars[i];
                            break;
                        }
                    }
                }

                if (!target) {
                    target = stars[0];
                }

                for (var s = 0; s < stars.length; s++) {
                    stars[s].tabIndex = stars[s] === target ? 0 : -1;
                }
            }
        }

        document.addEventListener('keydown', function (event) {
            var star = event.target;

            if (!star || !star.hasAttribute || !star.hasAttribute('data-rating-star')) {
                return;
            }

            var group = star.parentNode;

            if (!group || !group.hasAttribute || !group.hasAttribute('data-rating')) {
                return;
            }

            var stars = Array.prototype.slice.call(starsOf(group));
            var index = stars.indexOf(star);

            if (index === -1) {
                return;
            }

            var target = null;

            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                target = stars[(index + 1) % stars.length];
            } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                target = stars[(index - 1 + stars.length) % stars.length];
            } else if (event.key === 'Home') {
                target = stars[0];
            } else if (event.key === 'End') {
                target = stars[stars.length - 1];
            }

            if (!target) {
                return;
            }

            // Moving the focus is the whole interaction. Selecting on arrow would mean a
            // reader cannot inspect what they are about to set without setting it, and a
            // radiogroup that commits on arrow is not what the role promises.
            event.preventDefault();
            target.focus();
            sync(group);
        });

        document.addEventListener('DOMContentLoaded', function () {
            sync();
        });

        document.addEventListener('livewire:navigated', function () {
            sync();
        });

        document.addEventListener('livewire:init', function () {
            if (typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
                return;
            }

            Livewire.hook('morphed', function (payload) {
                sync(payload && payload.el);
            });
        });
    })();

</script>
