<?php

namespace App\Support;

/**
 * The questions a reader actually asks, answered honestly.
 *
 * ## Why this page leads with what leaves the machine
 *
 * Because that is the question, and because the answer is not the one most people expect. A
 * self-hosted app that reaches out to nobody is rare enough to be worth stating plainly, but the
 * statement is only worth anything if it survives contact with the code, and three things do contact
 * the internet. So the page says what does not, then names what does, and a reader who disagrees with
 * the trade can see exactly which line to disagree with.
 *
 * ## Where each claim was checked
 *
 * Every outbound destination in `app/` and `config/` was listed and read: `anilist.co` and
 * `graphql.anilist.co`, both behind an OAuth flow the reader starts themselves; `lhtranslation.net`
 * and `tachimanga.app`, both inside import fixtures for bringing a library in from another app;
 * `sqs.us-east-1.amazonaws.com`, an unused default in the queue config. There is no analytics, no
 * telemetry, no crash reporting and no error reporting service anywhere in the application.
 *
 * The store side needed the server's own account of itself. The extension repositories live here
 * as rows a reader adds rather than in `config`, and the bundled manga server re-reads the index
 * of every registered store when it starts up on its own, which was measured from the server's own
 * log on 2026-10-19. The third exception names that re-read rather than claiming the repository
 * only moves when an install does.
 *
 * That check is why the exceptions list has three entries rather than one. The first draft of this
 * file said AniList and moved on, and the test below failed, because a claim with an unlisted
 * exception is the exact failure this page is for. The list itself now lives in
 * {@see Outbound}, because the privacy page carries the same three facts.
 *
 * ## Where the mechanism answers were checked
 *
 * The later entries answer what the app does rather than what leaves the machine, and the rule is
 * the same for them: every sentence was read out of the code that does the thing. The search scope
 * and the pin come from `App\Search\SearchFanout` and `App\Search\HealthDashboard`, the ban and the
 * wait from `config/source-notices.php`, the sources page's install press and
 * `App\Search\HealthRecorder`, the empty listing from the series page's own `emptyReason()` and
 * `App\Migration\SeriesMigrator`, the migration from the wizard and the bulk page's own copy, the
 * backup from `App\Backup\LiveBackup` and the Backups page, the licences from
 * `App\Support\Licences` and the Licences page, and the adult content gate from
 * `App\Support\AdultContent`, the fanout's own universe and the Sources page.
 *
 * Four of these answers end in "it depends on the source", and they say so rather than rounding it
 * off. A page that hid the dependence would be wrong in the exact way this one exists to prevent.
 */
final class Faq
{
    /**
     * The three things that genuinely contact the internet.
     *
     * The list itself is in {@see Outbound}, because the privacy page
     * carries the same three facts and a second copy of the list is a second
     * copy that can drift from the first. The measurement that produced it is
     * recorded there.
     *
     * @return list<array{what: string, who: string}>
     */
    public function outbound(): array
    {
        return app(Outbound::class)->routes();
    }

    /**
     * Every question, in the order a reader meets them.
     *
     * @return list<FaqEntry>
     */
    public function entries(): array
    {
        return [
            new FaqEntry(
                question: __('Does anything leave my computer?'),
                answer: [
                    __('Nothing is reported anywhere. There is no telemetry, no analytics, no crash reporting and no error reporting service in this application, because there is no company behind it to receive any of it. No usage data, no page views, no device identifiers, no phone-home check.'),
                    __('Everything Papyrus knows about you is in a database file on this machine, and you can open it, copy it, back it up or delete it yourself.'),
                    __('Three things do contact the internet, and all three are things you set up yourself. If you use none of them, nothing is sent anywhere.'),
                ],
                exceptions: array_column($this->outbound(), 'who'),
                isPrivacy: true,
            ),

            new FaqEntry(
                question: __('Do I need an account?'),
                answer: [
                    __('No. Not with us, anyway, because there is no us. Papyrus has no server, no sign-up and no user database that leaves your machine.'),
                    __('You will be asked to create a login the first time you start it, and that login exists for one reason: so that a second person who finds your copy cannot open your library. It is stored as a password hash in the same local database as everything else, it is never sent anywhere, and nobody including the person who wrote this can recover it for you.'),
                ],
            ),

            new FaqEntry(
                question: __('Where is my library kept?'),
                answer: [
                    __('In a folder called Papyrus inside your user profile, in the same place Windows keeps other application data. It holds two halves: the manga server\'s own store of sources and downloads, and a single SQLite file holding your library, your read progress, your accounts and your settings.'),
                    __('Uninstalling Papyrus removes the program and deliberately leaves that folder alone. Removing it is a separate, deliberate act, because deleting a reader\'s library by accident is not a recoverable mistake.'),
                ],
            ),

            new FaqEntry(
                question: __('Can anyone else on my network get in?'),
                answer: [
                    __('No. Papyrus listens only on your own machine\'s loopback address, which is unreachable from another computer on your network, on your router, or from the internet. There is no port forwarding to set up and no setting to get wrong.'),
                    __('This was a real bug once. The configuration named a loopback address but did not actually restrict the listener, so the server answered on every network interface until it was measured and fixed. It is worth knowing it was checked rather than assumed.'),
                ],
            ),

            new FaqEntry(
                question: __('Will this work offline?'),
                answer: [
                    __('Reading what you have already downloaded: yes, entirely. The reader, your library and your progress are all local, and nothing on that path touches the network.'),
                    __('Finding anything new: no. Searching asks the sources directly, so a search needs the internet. An installed source that happens to be reachable another way is the exception, not the rule.'),
                ],
            ),

            new FaqEntry(
                question: __('What is a source, and should I trust it?'),
                answer: [
                    __('A source is a small program, written by someone who is not us, that knows how to find and download manga from one particular website. Papyrus installs and runs them inside the bundled manga server. Without at least one, the app starts and shows you an empty library.'),
                    __('Treat them the way you would treat any other program you did not write: install the ones you want, and notice that they run with your user\'s permissions. They are the least Papyrus-controlled part of the stack, and the Licences page does not pretend otherwise.'),
                ],
            ),

            new FaqEntry(
                question: __('How do I turn on adult content?'),
                answer: [
                    __('Adult content is off until you turn it on, and turning it on asks you to confirm that you are 18 or older. Nothing the catalogue marks NSFW is listed in the extension catalogue or asked in a search until you have done that, and the setting is per reader on this machine.'),
                    __('The switch sits on the Sources page and on the Search page, in a row of its own rather than among the rating chips, and it is one switch and not two: the rating says what may be shown and the switch says whether that rating is in scope at all. The rating in the address bar does not turn it on, because a link is not a confirmation.'),
                    __('There was a real hole here once. A link carrying the rating put 41 adult-rated extensions on the first page of the catalogue with nothing asked, and the page went on saying they were hidden. That is fixed: the rating is behind the confirmation now, and the count on the page tells you how many installed extensions the switch is holding back.'),
                    __('The rating is the catalogue\'s own, and it belongs to the extension rather than to a series inside it. A site rated Safe can still carry a series that is adult, and the app does not claim otherwise: the search page says so beside the rating it shows.'),
                    __('Nothing is sent anywhere when you turn it on. It is one row in the database on this machine, beside your other settings, and turning it off again takes it away at once.'),
                ],
            ),

            new FaqEntry(
                question: __('Can I limit what a search asks?'),
                answer: [
                    __('Yes. Pin the sites you trust on the Sources page or on the Health page, and searches stop asking anyone else. With nothing pinned, a search asks every source that can run, which is what it has always done.'),
                    __('A pin is for a site, not for one language of it. The extension repository ships one extension per language and they all point at the same site, so pinning MangaDex pins all 61 of its language variants at once. That is deliberate, and the measurement is why: on a measured run all 61 variants returned the same 51 titles in different scripts, and asking one variant per site kept 64% of the results and threw away every non-Latin one.'),
                    __('The search page says what it left out. While a pin stands, it tells you how many installed sources are not pinned and lists them by name, so no results never reads as nobody having the series.'),
                    __('Pins are about searching and about nothing else. Your shelves, your library and your read progress are not touched by them.'),
                ],
            ),

            new FaqEntry(
                question: __('What happens when a site blocks me?'),
                answer: [
                    __('Four sites are known to block readers who search hard, and the app says so before you install one: Kayn Scans, Asmotoon, Magustoon and Genztoons. Pressing install on one of them opens a warning first, and the warning says this: "This site is known to block readers that search aggressively. Space searches out or pin it; expect long bans, not errors you can retry away."'),
                    __('A block is a wait, not an error, and nothing Papyrus does brings a site back sooner. Spacing your searches out or pinning the site is the whole of the advice, because both mean asking it less.'),
                    __('One more site is a different problem rather than a ban. Comix scrambles what comes back: searches through its extension return titles unrelated to the query and pages arrive broken, and it pushes readers back to its own site. The app warns about that one at the install press as well.'),
                    __('With a site that is merely failing, the app is plain about it. Each source gets its own budget inside a search, 20 seconds by default, so one slow site cannot use up the whole run, and a source that fails five times in a row is marked dead and left out of later searches, because asking a dead one costs the full wait every single time. The Health page shows what each site is doing, and a dead one can still be asked for by name.'),
                ],
            ),

            new FaqEntry(
                question: __('What if a series has no chapters?'),
                answer: [
                    __('Sometimes a source lists a series and then lists no chapters for it. The series page says exactly that, in these words: "The source returned no chapters", and that this is an upstream condition, that the source answered and its chapter listing was empty, and that the series may have moved or the source may have changed its layout.'),
                    __('Papyrus cannot tell you which of those it is. A publisher may have taken a series down, a series may have moved somewhere else, and a source may have changed the shape of its pages. The app does not guess, and it does not present a broken fetch as a missing series: when the call itself failed, the page shows the server\'s own message instead.'),
                    __('The library check treats it as a fact about the source rather than a fault to fix, so a series with no chapters does not show up as something broken.'),
                    __('Moving such a series is refused rather than half done. A destination that lists no chapters is refused with "That source lists no chapters for it, so there is nothing to move to." A source you are leaving that lists none is refused because your read progress there could not be carried over. And when none of your chapters can be matched to the new source, the move is refused too, because a move that carried nothing is not a move. Those rows offer you the source\'s own page to look at instead.'),
                ],
            ),

            new FaqEntry(
                question: __('How do I move a series to another source?'),
                answer: [
                    __('One series at a time, from its own page. "Move source" searches every source that can run, which takes about 22 seconds across 82 sources, and then lists what it found, best first. Each candidate carries what was measured rather than a guess: how alike the titles are, how many chapters it lists and how many more or fewer than where the series is now, and whether the author matches.'),
                    __('A candidate you cannot move to says why on its own row: "This source lists no chapters for it.", "This source could not be read." or "Not looked at yet." Those rows offer to take you to that source\'s own page to look for yourself, or to step the row aside.'),
                    __('Before the move you are asked what to carry: your read progress, your shelves, and the pages you already downloaded. The first two are on by default and the last is off, because moving a series should not delete a copy you downloaded to read on a train. If you have connected an AniList account you are also asked whether to tell AniList about the move, and that row starts ticked, because a checkbox wired to nothing would be a promise the page could not keep.'),
                    __('What the move does: the series points at the new source and stays in your library and on its shelves, your read progress moves across chapter by chapter matched on chapter number, anything that cannot be matched is reported afterwards rather than dropped quietly, and the pages you downloaded stay on disk.'),
                    __('A whole shelf at once is on the Sources page, under Migrate. You pick the source to leave and up to five destinations, because each destination is another full search of the whole shelf. It compares series by series, writes its progress on its own row so a long run can be watched, and can be stopped: the rows that finished keep their places and the rest are untouched. The timing is worth knowing, because a comparison takes between 0.7 and 3.2 seconds depending on the site, so a shelf of 200 series at five destinations takes between 11 and 54 minutes. The run reports what moved, how many chapters kept their place, how many were refused and why, and it names the series it never looked at.'),
                ],
            ),

            new FaqEntry(
                question: __('Is this open source?'),
                answer: [
                    __('Yes. Papyrus itself is MIT licensed, and the Licences page in Settings lists every package in the install, where it came from, and under what terms.'),
                    __('Almost all of it is open source, and the Licences page names the one package that is not, rather than leaving you to find it in a table of several hundred rows. It is also worth knowing that the manga server and its Java runtime are separate projects with their own licences, and that no source you install is bundled: those are downloaded by you, from wherever you choose.'),
                ],
            ),

            new FaqEntry(
                question: __('Where do I acknowledge the licences?'),
                answer: [
                    __('On the Licences page in Settings. It lists every package in this install, read from the lock files the build installed from, and it adds the two large pieces no lock file mentions: the manga server and the Java runtime it runs on.'),
                    __('Each row names the package, its licence and where it came from, and opens the licence text itself, read byte for byte from the file that ships beside it. A package that names itself proprietary is marked as not open source rather than left for you to find, and a package that records no licence at all is listed as not recorded rather than guessed at.'),
                    __('Acknowledging is a button on each row, and it means you have read that licence as the page shows it now. The row then reads Acknowledged. If the package moves to another version or another licence, the acknowledgement re-arms and the row says it changed since, because a version bump is exactly the moment a read wants looking at again.'),
                    __('The record is yours and stays on this machine: one row per package, per reader, in the same local database as everything else.'),
                ],
            ),

            new FaqEntry(
                question: __('How do I make a backup?'),
                answer: [
                    __('The app takes its own. On the Backups page, "Back up now" writes a pair of files while the app and the manga server keep running, checks both halves, and puts them in the backups folder with the token naming the moment. "Restore" puts a pair back the same way, and "Export for another reader app" writes a .tachibk file another reader can open.'),
                    __('There is no cloud copy and no sync service, so that folder is the copy. If you want a second one, copy the folder to another disk yourself.'),
                    __('If you would rather copy the folder by hand, copy the whole folder rather than picking files, and do it while Papyrus is closed. The library database keeps recent changes in a small sidecar file beside it, and copying only the main file is the quiet way to lose the last few chapters you read.'),
                ],
            ),

            new FaqEntry(
                question: __('What does a backup cover, and what does it not?'),
                answer: [
                    __('A backup is a pair, and the two halves are the two things Papyrus does not keep in one place. The reader half is your library database, copied with a database operation that is safe while the app is running, and it holds your read progress per series and per chapter, your shelves, your settings, your tracking link and your import ledger. The manga half is the manga server\'s own fresh export of its library. Both are staged and checked before they are put away, and the manifest beside them carries their digests and their row counts, so a pair that does not match what it claims is refused rather than restored.'),
                    __('What it does not promise is worth as much. There is no second copy off this machine: the pair is a file in the folder the Backups page names, and if the machine is lost the backup goes with it. The manga half carries the server\'s library and nothing else, so a series the app has state for but the server does not hold is not in the file, and the export names every series it could not carry and says why. The pages you downloaded are not in the pair unless you ask for them, and the page tells you how big that folder is before you press.'),
                    __('One thing does happen on its own, and it is half a backup rather than a whole one. The reader half is snapshotted every night at 03:17 and kept for a fortnight, and the manga server backs up its own library daily. That file is named reader-half on purpose, because it is not a pair and cannot be restored as one.'),
                ],
            ),
        ];
    }

    /**
     * The privacy entry on its own, because it is the one the page is for.
     */
    public function privacy(): FaqEntry
    {
        foreach ($this->entries() as $entry) {
            if ($entry->isPrivacy) {
                return $entry;
            }
        }

        // Unreachable while the list above is written by hand in the same file, and a hard failure
        // here is better than a page that quietly lost its headline.
        throw new \LogicException('the FAQ has no privacy entry, which is the one thing it exists to publish');
    }

    public function totalCount(): int
    {
        return count($this->entries());
    }
}
