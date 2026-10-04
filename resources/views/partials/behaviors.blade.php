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
</script>
