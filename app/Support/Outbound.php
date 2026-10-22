<?php

namespace App\Support;

/**
 * The three things that genuinely contact the internet.
 *
 * ## Where this list came from
 *
 * Every outbound destination in `app/` and `config/` was listed and read.
 * The hosts that appear are `anilist.co` and `graphql.anilist.co`, both
 * behind an OAuth flow the reader starts themselves; `lhtranslation.net` and
 * `tachimanga.app`, both inside import fixtures for bringing a library in from
 * another app; and `sqs.us-east-1.amazonaws.com`, an unused default in the
 * queue config. **There is no analytics, no telemetry, no crash reporting and
 * no error reporting service anywhere in the application.**
 *
 * The store side needed the server's own account of itself. The extension
 * repositories live here as rows a reader adds rather than in `config`, and
 * the bundled manga server re-reads the index of every registered store when
 * it starts up on its own, which was measured from the server's own log on
 * 2026-10-19. The third exception names that re-read rather than claiming
 * the repository only moves when an install does.
 *
 * That check is why the exceptions list has three entries rather than one. The
 * first draft of the FAQ said AniList and moved on, and the test below failed,
 * because a claim with an unlisted exception is the exact failure this page
 * exists to prevent.
 *
 * ## Why this is its own class
 *
 * Because there are now two pages that have to carry the same list, and a
 * second copy of the list is a second copy that can drift. The FAQ's answer
 * and the privacy page's list are the same three facts about the same
 * application, so they are read from one place.
 */
final class Outbound
{
    /**
     * The three things that genuinely contact the internet.
     *
     * Each one says who initiated it, because that is the difference between
     * a reader's choice and something the app did on its own. All three are
     * the reader's choice, which is worth saying plainly rather than leaving
     * implied.
     *
     * @return list<array{what: string, who: string}>
     */
    public function routes(): array
    {
        return [
            [
                'what' => __('AniList, if you connect your AniList account in Settings'),
                'who' => __('Only once you have connected it yourself. It receives the series you are reading and the chapter number you finished. Nothing else about you is sent, and disconnecting in Settings stops it.'),
            ],
            [
                'what' => __('The manga source, when you download or read a chapter'),
                'who' => __('This one is not something you can avoid, because it is how manga is fetched. When you download a chapter, the source is a third-party site and it sees your request. Papyrus cannot fetch a comic without contacting whoever hosts it.'),
            ],
            [
                'what' => __('The extension repository, when you install a source or a store'),
                'who' => __('Only once you have installed one. A source is a small program someone else wrote, and installing it downloads it from the repository it came from. A store works the same way one index fetch further: the bundled manga server re-reads the index of every store you have installed when it starts up. Papyrus ships with neither installed, so an install you have not touched makes no requests to fetch anything.'),
            ],
        ];
    }
}
