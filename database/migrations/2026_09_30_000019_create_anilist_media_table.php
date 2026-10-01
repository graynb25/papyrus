<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What AniList says about a title, cached.
         *
         * The lookup key is the normalised title, because that is what a search
         * result carries and what a reader would recognise. One row per title rather
         * than one per media, because the question asked is "what does AniList call
         * this title" and asking it twice for the same answer is the whole cost
         * problem.
         *
         * Measured constraints, all of which shaped this table:
         *
         *  - The rate limit is **30 requests a minute**, not the 90 AniList documents
         *    for an authenticated client. A fan-out producing 91 distinct titles
         *    therefore cannot be resolved inside a request, which is why the cache is
         *    filled by a job and the render path only ever reads.
         *  - A title's display names are not the names a source uses. Measured:
         *    searching "The Berserker's Second Playthrough" returns media 205085 whose
         *    romaji is "Game Sok 2-hoecha Gwangjeonsa" and whose native is
         *    "게임 속 2회차 광전사". The English title appears only in `synonyms`, so
         *    all three title forms and the synonym list are stored, because a later
         *    check may want to answer "which titles does this media claim" without
         *    asking again.
         *  - `isAdult` is per series while Suwayomi's `content_warning` is a rating
         *    for the whole source, so the two are stored side by side and neither
         *    overwrites the other.
         */
        Schema::create('anilist_media', function (Blueprint $table) {
            $table->id();

            /*
             * AniList's own id. Not a foreign key to anything, and the only exact
             * cross-language identity available: measured, "チェンソーマン" and
             * "Chainsaw Man" both resolve to 105778, and "ワンピース" and "One Piece"
             * both to 30013. Suwayomi's own tracker is not usable, because
             * `MangaType.trackRecords` is `totalCount: 0` until a reader logs into a
             * tracker, and a self-hosted install has nobody who has.
             */
            $table->unsignedBigInteger('anilist_id');

            /*
             * The key this row answers for. Unique, so a repeat search of the same
             * title is a cache hit rather than a third request.
             */
            $table->string('normalized_title');

            $table->string('title_romaji')->nullable();
            $table->string('title_english')->nullable();
            $table->string('title_native')->nullable();
            $table->json('synonyms')->nullable();

            /*
             * Recorded, never acted on. A reader can see that AniList marks a series
             * adult even when the source's extension is rated SAFE, which is a fact
             * worth surfacing, and overriding the source's rating on the strength of
             * one field would hide when a source is deliberately more permissive.
             */
            $table->boolean('is_adult')->default(false);

            $table->string('format', 16)->nullable();
            $table->string('country_of_origin', 8)->nullable();
            $table->string('status', 16)->nullable();
            $table->unsignedSmallInteger('chapters')->nullable();

            /*
             * The original author and artist, which is not what a source reports.
             * Measured: AniList gives "Original Story: Insaengmanghaesseum, Story &
             * Art: Isang" where sources variously report "Life Ruined", "Isang",
             * "Isang, Life Ruined" and an empty string. Useful, and stored because it
             * is the reason to make the call beyond the id.
             */
            $table->json('staff')->nullable();

            /*
             * When this was asked of AniList. Not decoration: a title that changes
             * meaning, a series that gets renamed, and a lookup that was wrong all
             * look identical otherwise, and a reader who suspects a bad match needs
             * to know how stale the answer is.
             */
            $table->timestamp('looked_up_at');

            $table->timestamps();

            $table->unique('normalized_title', 'anilist_media_normalized_title_unique');
            $table->index('anilist_id', 'anilist_media_anilist_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anilist_media');
    }
};
