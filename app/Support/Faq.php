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
 * That check is why the exceptions list has three entries rather than one. The first draft of this
 * file said AniList and moved on, and the test below failed, because a claim with an unlisted
 * exception is the exact failure this page is for.
 */
final class Faq
{
    /**
     * The three things that genuinely contact the internet.
     *
     * Each one says who initiated it, because that is the difference between a reader's choice and
     * something the app did on its own. All three are the reader's choice, which is worth saying
     * plainly rather than leaving implied.
     *
     * @return list<array{what: string, who: string}>
     */
    public function outbound(): array
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
                'what' => __('The extension repository, when you install a source'),
                'who' => __('Only when you install one. A source is a small program someone else wrote, and installing it downloads it from the repository it came from. Papyrus ships with none installed, so an install you have not touched makes no requests to fetch anything.'),
            ],
        ];
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
                    __('Three things do contact the internet, and all three are things you asked for. If you use none of them, nothing is sent anywhere.'),
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
                question: __('Is this open source?'),
                answer: [
                    __('Yes. Papyrus itself is MIT licensed, and the Licences page in Settings lists every package in the install, where it came from, and under what terms.'),
                    __('Almost all of it is open source, and the Licences page names the one package that is not, rather than leaving you to find it in a table of several hundred rows. It is also worth knowing that the manga server and its Java runtime are separate projects with their own licences, and that no source you install is bundled: those are downloaded by you, from wherever you choose.'),
                ],
            ),

            new FaqEntry(
                question: __('How do I make a backup?'),
                answer: [
                    __('Copy the folder. There is no cloud copy, no sync service and nothing to export, because there is nowhere else for it to be.'),
                    __('Copy it while Papyrus is closed, and copy the whole folder rather than picking files. The library database keeps recent changes in a small sidecar file beside it, and copying only the main file is the quiet way to lose the last few chapters you read.'),
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
