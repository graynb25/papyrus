<?php

namespace App\Support;

/**
 * The privacy page and the terms of service page, in one place.
 *
 * ## Why these are in the application and not in a document
 *
 * Because a privacy statement is the easiest thing in a program to let rot.
 * It stays true right up until somebody adds a script, a tracker or a mail
 * transport, and nobody re-reads the paragraph. The content here is therefore
 * written from the code that does the thing, and a test holds it, the same
 * way the FAQ's answers are held.
 *
 * ## What was measured, and where each claim comes from
 *
 * Every outbound destination in `app/` and `config/` was listed and read. The
 * hosts that appear are `graphql.anilist.co` and `anilist.co`, both behind an
 * OAuth flow the reader starts themselves; `lhtranslation.net` and
 * `tachimanga.app`, both inside import fixtures for bringing a library in from
 * another app; and `sqs.us-east-1.amazonaws.com`, an unused default in the
 * queue config. **There is no analytics, no telemetry, no crash reporting and
 * no error reporting service anywhere in the application.** The mailers are all
 * driven by the environment and the default one writes to a local log file.
 *
 * The three exceptions below are therefore the whole of the network side, and
 * the mail is the fourth thing that can leave, and it is named separately
 * because it leaves under different conditions.
 *
 * ## What these pages do not claim
 *
 * They do not claim to be a controller or a processor for anybody, because
 * there is nobody to be one for. They do not name a GDPR article or a CCPA
 * section, because a page that cannot determine where its reader lives cannot
 * honestly map itself onto a legal instrument written for somebody who can.
 * They say what the application does in relation to those laws, which is the
 * part that is true everywhere: nothing is collected by the application and
 * nothing is transmitted by it, so there is nothing to opt out of, to sell or
 * to share.
 *
 * ## Who the reader meets
 *
 * The third parties the reader reaches are their own responsibility and not
 * this project's. A manga site sees the reader's address and the titles they
 * ask for. An extension repository sees the address. AniList sees the titles
 * that are searched. No page here can speak for any of them, and a page that
 * tried would be the one dishonest sentence on it.
 */
final class Legal
{
    /**
     * The privacy page.
     */
    public function privacy(): LegalDocument
    {
        return new LegalDocument(
            title: __('Privacy'),
            promise: __('This application collects nothing and reports nothing. What is here stays on this computer.'),
            sections: [
                new LegalSection(
                    heading: __('What the application collects'),
                    paragraphs: [
                        __('Nothing. There is no telemetry, no analytics, no crash reporting and no error reporting service in it, because there is no company behind it to receive any of it. No usage data, no page views, no device identifiers, no phone-home check.'),
                        __('The account is a display name, an email address and a password, and the password is stored as a hash in the same local database as everything else. The address is never sent anywhere and is never checked against a list of addresses; it exists so the sign in form can ask for it and so a password reset has somewhere to go.'),
                        __('The rest is what you read and what you do: your library and your read progress, your shelves, your notes, your ratings and tags, your search history, your reading sessions, the pages you have downloaded, the backups you have imported and the licences you have acknowledged. All of it is a row in a database file on this machine.'),
                    ],
                ),

                new LegalSection(
                    heading: __('What reaches the internet'),
                    paragraphs: [
                        __('Three things, and all three are things you set up yourself. If you use none of them, nothing is sent anywhere.'),
                    ],
                    exceptions: true,
                ),

                new LegalSection(
                    heading: __('Mail to you, and only to you'),
                    paragraphs: [
                        __('The application can send you three messages: a verification link when you sign up, a password reset link when you ask for one, and a welcome note if your account was made from the command line. On a default install none of them leave the machine, because the mailer ships writing to a local log file: there is nowhere for an application on your own computer to send mail from.'),
                        __('Point it at a real mail server and that is you deciding to, and the address goes to whoever runs that server. In a shared or public install the sign up verification is not skipped, because those installs are reachable by people you have never met, so that message does go out.'),
                    ],
                ),

                new LegalSection(
                    heading: __('Where your data is kept'),
                    paragraphs: [
                        __('In a folder called Papyrus inside your user profile, in the same place Windows keeps other application data. It holds two halves: the manga server\'s own store of sources and downloads, and a single SQLite file holding your library, your read progress, your accounts and your settings.'),
                        __('Nothing is written outside that folder except the downloads you ask for and the pages you have already downloaded. Uninstalling removes the program and deliberately leaves that folder alone, so removing a reader\'s library is a separate and deliberate act.'),
                        __('It is yours to read, copy, back up and delete. The database is a file you can open with any SQLite reader, and the Backups page writes a copy of it while the application keeps running.'),
                    ],
                ),

                new LegalSection(
                    heading: __('Your rights, and what they mean here'),
                    paragraphs: [
                        __('The GDPR in the European Union and the United Kingdom, and the CCPA in California, give people rights over personal data. This application collects no personal data and transmits none, so there is nothing for it to sell, share or disclose, and no cookie, no advertising identifier and no profile of you exists for anyone to act on.'),
                        __('The rights still make sense, and they are answered locally. To see what is held, open the database file or the pages that show it. To take a copy, use the Backups page, which writes it while everything keeps running. To have it erased, delete the file, or the rows in it; the application does not keep a copy anywhere else, because there is nowhere else for it to keep one.'),
                        __('The one thing this page cannot do is speak for the third parties you meet. A manga site, an extension repository and AniList each handle your address and the titles you ask them for under their own terms, and a reader who wants to know what they do with it has to read their terms, not this one.'),
                    ],
                ),
            ],
        );
    }

    /**
     * The terms of service page.
     */
    public function terms(): LegalDocument
    {
        return new LegalDocument(
            title: __('Terms of service'),
            promise: __('You run this on your own machine. Here is what that means.'),
            sections: [
                new LegalSection(
                    heading: __('What this is'),
                    paragraphs: [
                        __('Papyrus is a self-hosted manga reader. It runs on your computer, it is MIT licensed, and there is no company behind it: no accounts on our servers, no subscription, no support desk and no telemetry. The software is the whole of the product, and the licence is on the Licences page with every other package in the install.'),
                        __('The one thing it does not do is stop you reading what you like. What you install, where you get it from and whether you use it are yours to decide, and nothing in this document asks you to ask us first.'),
                    ],
                ),

                new LegalSection(
                    heading: __('What you are responsible for'),
                    paragraphs: [
                        __('Sources are small programs written by people who are not us. They run inside the bundled manga server with your user\'s permissions, and they are the least part of this that we control. Treat them the way you would treat any other program you did not write.'),
                        __('What you read, where you get it from and whether that is allowed where you are is between you and whoever publishes it. We do not host the manga, we do not mirror it, and we cannot tell you what the law says in your country.'),
                    ],
                ),

                new LegalSection(
                    heading: __('What the software does not promise'),
                    paragraphs: [
                        __('It is provided as it is, with no warranty of any kind, and that is the same promise its licence makes. It is free software written by people who do not work on it for a living, and a source can be wrong, slow, gone or full of files that are not what their name says.'),
                        __('The application is careful about what it tells you: a source that has stopped answering says so, a listing that could not be read says so, and a backup that does not match what it claims is refused rather than restored. That is the standard it is held to, not a promise that it never fails.'),
                    ],
                ),

                new LegalSection(
                    heading: __('Your data and your library'),
                    paragraphs: [
                        __('The library is yours. It is a file on your machine, you can back it up, you can move it to another machine and you can delete it. Nothing is taken back, nothing is locked, and nothing in the application asks this project for permission to keep it.'),
                    ],
                ),
            ],
        );
    }
}
