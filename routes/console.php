<?php

use App\Jobs\CheckLibrary;
use App\Jobs\SyncDownloads;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Reconciles downloads against Suwayomi's queue.
 *
 * Every ten seconds, and the interval is measured rather than chosen. Suwayomi's
 * own download bar steps once per page: on a 28 page chapter `progress` moves by
 * 1/28, and the whole chapter took 39 seconds. Ten seconds therefore samples a
 * long chapter five or six times, which is enough for a bar to read as moving, and
 * a five second tick would mostly re-read the same value.
 *
 * withoutOverlapping because two of these polling the same queue produce two sets
 * of writes for the same rows, and the loser is whichever finished first.
 *
 * onOneServer because with one shared Suwayomi there is no reason for every app
 * instance to poll it.
 */
Schedule::job(new SyncDownloads)
    ->everyTenSeconds()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Checks every reader's library for what is due.
 *
 * Hourly, and the interval is not arbitrary: a check costs a real upstream request,
 * measured at 361ms to 1617ms with an 876ms median, and the floor already decides
 * what is worth asking about. Hourly is just often enough that a reader who visits
 * the page in the morning sees this morning's chapters, and rare enough that the
 * schedule is not the thing driving traffic to 82 sites.
 *
 * Dispatched per reader rather than as one job for everybody, because the job takes a
 * user id and a pass has to be attributable. `CheckLibrary::everyReaderWithALibrary()`
 * is the fan-out, and it is the same list a nightly command would use.
 *
 * This is the safety net, not the mechanism. Nothing here depends on
 * `schedule:work` being up: a reader pressing "Check" on the library page queues a
 * pass directly, and that pass re-books itself until the work is done. If the
 * schedule is not running the feature still works on demand, which is the same
 * arrangement {@see SyncDownloads} uses.
 */
Schedule::call(function (): void {
    foreach (CheckLibrary::everyReaderWithALibrary() as $job) {
        dispatch($job);
    }
})->hourly()->name('library-check')->withoutOverlapping()->onOneServer();
