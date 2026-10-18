<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk migration learns several destinations, a picked subset, and per row numbers.
 *
 * ## Why the targets are a table and not a JSON column
 *
 * The names are stamped at run creation the way the run's own `from_source_name` is, for the
 * same reason that column has one: the run outlives the mirror row, and the history card is
 * the thing that names a source which has since vanished. A JSON column would bury the stamp
 * inside a blob the page cannot query or render column by column.
 *
 * ## Why the picked list is not item rows
 *
 * An item row is a decision the comparison wrote. A picked series is a choice the reader made
 * on the compose screen, before any comparison ran, and pre-match item rows would read as
 * decisions nobody made: the page's offer cards, the missing cards and the counts all key on
 * statuses the comparison alone sets. So the picks ride the run as ids stamped at pick time,
 * and the matcher intersects them with the lens when it starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_migration_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bulk_migration_id')->constrained('bulk_migrations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /** Suwayomi source ids are strings here for the same reason they are on the run. */
            $table->string('source_id', 64);

            /** Stamped at creation, so a vanished mirror row still has something to say. */
            $table->string('source_name')->nullable();
            $table->string('lang', 16)->nullable()->default('');

            /** The order the reader picked them in, which is the order the card names them in. */
            $table->unsignedSmallInteger('seq');

            $table->timestamps();
        });

        Schema::table('bulk_migrations', function (Blueprint $table): void {
            $table->text('picked_manga_ids')->nullable();

            /**
             * The single destination of every run before the target list existed. A run
             * that carries a list leaves these null, so the column answers "this is every
             * destination the run had" rather than "the first of several".
             */
            $table->string('to_source_id', 64)->nullable()->change();
        });

        Schema::table('bulk_migration_items', function (Blueprint $table): void {
            /**
             * Which target produced the offer. Batch 1 rows have no value here and stay
             * exactly as they are, because their run had one destination that the run row
             * itself names.
             */
            $table->string('to_source_id', 64)->nullable();
            $table->string('to_source_name')->nullable();

            /** How far behind the leaving series was when the compare ran, lens counted. */
            $table->unsignedInteger('unread_here')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bulk_migration_items', function (Blueprint $table): void {
            $table->dropColumn(['to_source_id', 'to_source_name', 'unread_here']);
        });

        Schema::table('bulk_migrations', function (Blueprint $table): void {
            $table->string('to_source_id', 64)->nullable(false)->change();
            $table->dropColumn('picked_manga_ids');
        });

        Schema::dropIfExists('bulk_migration_targets');
    }
};
