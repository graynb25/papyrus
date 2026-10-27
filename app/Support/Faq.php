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
                    __('No. Papyrus has no telemetry or analytics, no crash reporting or error reporting, and sends no page views, device IDs or automatic checks to anyone. There is no company or service waiting for them.'),
                    __('What Papyrus knows about you stays in files on this computer. You can open them, copy them, back them up or delete them yourself.'),
                    __('The only internet connections come from things you choose to set up, such as sources, extension stores and AniList. If you use none of them, nothing leaves this computer.'),
                ],
                exceptions: array_column($this->outbound(), 'who'),
                isPrivacy: true,
            ),

            new FaqEntry(
                question: __('Do I need an account?'),
                answer: [
                    __('You need a login, but it lives only on this computer. There is no Papyrus account online, so the email can be anything, including test@example.com.'),
                    __('The login keeps someone else who finds this install from opening your library. If you forget the password, it cannot be recovered.'),
                ],
            ),

            new FaqEntry(
                question: __('Where is my library kept?'),
                answer: [
                    __('Papyrus keeps its data in its own folder inside your Windows user profile. The folder contains your manga server\'s sources and downloads, plus a database with your library, read progress, accounts and settings.'),
                    __('Uninstalling Papyrus removes the program but leaves that folder alone. Delete the folder separately if you also want to remove your library and settings.'),
                ],
            ),

            new FaqEntry(
                question: __('Can anyone else on my network get in?'),
                answer: [
                    __('No. Papyrus accepts connections only from this computer. Another device on your network or on the internet cannot reach it, and there is no port forwarding to set up.'),
                    __('This is checked at the listener, not just in the configuration. A past bug made a listener look local while accepting connections from every network interface; that is why this is measured rather than assumed.'),
                ],
            ),

            new FaqEntry(
                question: __('Will this work offline?'),
                answer: [
                    __('Yes for chapters you have already downloaded. The reader, your library and your progress are local, so reading them does not need the internet.'),
                    __('No for finding something new. Search asks the sources directly, so it needs the internet unless a source is already reachable another way.'),
                ],
            ),

            new FaqEntry(
                question: __('What is a source, and should I trust it?'),
                answer: [
                    __('A source is a small add-on that knows how to find manga on one website. Papyrus runs it inside its manga server. Without at least one source, the app starts with an empty library.'),
                    __('Sources come from people outside Papyrus and run with your Windows account\'s permissions. Install only sources you trust. The Licences page names what Papyrus can and cannot vouch for.'),
                ],
            ),

            new FaqEntry(
                question: __('How do I turn on adult content?'),
                answer: [
                    __('Adult content starts off. Turning it on asks you to confirm that you are 18 or older. Until then, extensions and sources marked NSFW stay out of the catalogue and search. This choice belongs to your account on this computer.'),
                    __('You will find the switch on the Sources page and the Search page in a row of its own, away from the rating chips. The rating describes the source; the switch is your permission to include it. A link with an NSFW rating cannot turn the switch on.'),
                    __('The catalogue rating belongs to an extension, not to every series on its site. A site marked Safe can still list an adult series, so the search page shows the rating it received rather than guessing what every series contains.'),
                    __('Nothing is sent anywhere when you turn it on. It is one row in the database on this machine, beside your other settings, and turning it off again takes it away at once.'),
                ],
            ),

            new FaqEntry(
                question: __('Can I limit what a search asks?'),
                answer: [
                    __('Yes. Pin the sites you trust on the Sources or Health page, and search will stop asking the other sites. With no pins, search asks every source that can run.'),
                    __('A pin applies to a site, not one language. One site can have several language extensions, and pinning the site covers them together so search does not ask the same site repeatedly.'),
                    __('The Search page tells you what it left out. While pins are active, it shows how many installed sources were not pinned and lists them by name.'),
                    __('Pins change search only. They do not change your shelves, library or reading progress.'),
                ],
            ),

            new FaqEntry(
                question: __('What happens when a site blocks me?'),
                answer: [
                    __('Some sites block readers who search too often. Papyrus warns you before you install Kayn Scans, Asmotoon, Magustoon or Genztoons. Space your searches out or pin the site; a ban can last a long time.'),
                    __('A block is a waiting period, not an error Papyrus can fix. Asking the site less often is the only way to avoid making it worse.'),
                    __('Comix is a different problem: its extension can return unrelated titles and broken pages, and it may send you back to its own site. Papyrus warns you before installation.'),
                    __('When a site is simply failing, Papyrus gives it its own 20-second limit so it cannot hold up the whole search. Five failures in a row leave it out of later searches. Health shows what is happening, and you can still ask that source by name.'),
                ],
            ),

            new FaqEntry(
                question: __('What if a series has no chapters?'),
                answer: [
                    __('Sometimes a source lists a series but no chapters. The series page says "The source returned no chapters." The source answered, but its chapter list was empty. The series may have moved, or the source may have changed its layout.'),
                    __('Papyrus cannot tell which of those happened. It does not guess, and it does not call a failed request a missing series. When the request itself fails, the page says that the source could not be read.'),
                    __('The library check treats an empty chapter list as a fact about the source, not as a problem with your library.'),
                    __('A move is refused when the new source has no chapters, the source you are leaving has no chapters, or none of the chapters match. The row gives you the source page so you can look for yourself.'),
                ],
            ),

            new FaqEntry(
                question: __('How do I move a series to another source?'),
                answer: [
                    __('Open a series and choose "Move source". Papyrus searches the sources it can read, which takes about 22 seconds across 82 sources, then ranks the candidates. Each row shows the title match, chapter count, chapter difference and author match.'),
                    __('A candidate you cannot use says why: no chapters, source could not be read, or not checked yet. You can open that source to look for yourself or set the candidate aside.'),
                    __('Before moving, choose what to carry: read progress, shelves and downloaded pages. Progress and shelves start on; downloaded pages start off so moving a series never removes a copy you saved for offline reading. If you have connected an AniList account, you can also send the move there.'),
                    __('The series stays in your library and on its shelves. Read progress follows matching chapter numbers, anything that cannot be matched is reported, and downloaded pages stay where they are.'),
                    __('To move a whole shelf, open Sources and choose Migrate. Pick the source to leave and up to five destinations. Each destination searches the shelf, records its progress and can be stopped; finished rows stay moved and the rest stay untouched. A comparison takes 0.7 to 3.2 seconds, so 200 series at five destinations takes about 11 to 54 minutes. The finished run reports what moved, what was refused and which series it did not reach.'),
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
                    __('The install owner can open Settings, choose Backups, and press "Back up now". Papyrus saves your library and manga data while it keeps running, checks the copy, and puts it in the backups folder. Restore puts that copy back, and Export for another reader app creates a file Mihon and its forks can open.'),
                    __('There is no cloud copy. There is no sync service, so the backups folder is your copy. If you want protection from a lost or damaged computer, copy that folder to another drive.'),
                    __('If you copy it by hand, close Papyrus first and copy the whole folder. The library keeps recent changes in a small file beside the main database, so copying only one file can lose the last few chapters you read.'),
                ],
            ),

            new FaqEntry(
                question: __('What does a backup cover, and what does it not?'),
                answer: [
                    __('A backup saves two things: your library and the manga server\'s copy of your manga data. Your library includes read progress, shelves, settings, tracking links and import history. Papyrus checks both parts before it calls the backup ready to restore.'),
                    __('The backup stays on this computer unless you copy it somewhere else. It does not include anything the manga server does not have, and downloaded pages are included only when you choose the downloads option.'),
                    __('Papyrus also makes an automatic copy of your library each night and keeps it for two weeks. That automatic copy is only your library, not a complete backup, so it cannot restore the whole app by itself.'),
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
