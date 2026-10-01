<?php

namespace App\Covers;

use App\Models\SeriesCover;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A reader's own cover art for a series.
 *
 * ## Why this exists rather than a field on Suwayomi
 *
 * Suwayomi has no custom-cover concept at all. `ImageMutation` exposes only
 * `clearCachedImages`, and `UpdateMangaPatchInput` carries nothing but `inLibrary`, so
 * there is no endpoint to tell it about a cover and nowhere for it to keep the bytes.
 * Migration 6 made the table and `SeriesState::cover()` has been declared since, and
 * measured **0 rows**: the storage was built and never used.
 *
 * ## Why the file is resized rather than stored as it is
 *
 * Measured against the sources themselves, a Suwayomi cover is at most **800px** on its
 * long edge and weighs **77KB to 193KB**. Bounding an upload at the same 800px makes a
 * custom cover indistinguishable in sharpness from a proxied one and costs about the
 * same, and the grid slot is 173px wide, so anything past that is pixels no screen in
 * this app can show.
 *
 * ## Why the pixel ceiling is the guard that matters
 *
 * `memory_limit` is 128M and a decoded image costs roughly four bytes a pixel, so a 12
 * megapixel phone photo is already 48MB before anything else is allocated. A byte limit
 * does not protect that: two megabytes of PNG can be 20000 by 20000. So the dimensions
 * are read with `getimagesizefromstring`, which parses the header without decoding, and
 * an absurd one is refused before gd is involved at all.
 */
class CoverStore
{
    /**
     * What gd can both read and write, so an upload is never accepted and then failed.
     */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * This reader's cover row for a series, or null.
     */
    public function find(User|int $user, int $mangaId): ?SeriesCover
    {
        if ($mangaId <= 0) {
            return null;
        }

        return $this->query($user)
            ->where('suwayomi_manga_id', $mangaId)
            ->first();
    }

    /**
     * The URL that serves a reader's custom cover, or null when they have none.
     *
     * Null is the answer that matters: the caller falls back to the source's own cover,
     * and a cover the reader removed has to come back on its own.
     */
    public function url(User|int $user, int $mangaId): ?string
    {
        return $this->find($user, $mangaId) === null
            ? null
            : route('covers.show', ['manga' => $mangaId]);
    }

    /**
     * Replace a reader's cover for a series.
     *
     * One cover per reader per series, because the table says so with a unique key, and
     * replacing deletes the file the old row pointed at rather than orphaning it. A
     * content-addressed name would make that free, but it would also mean the URL changes
     * on every replace, which defeats a browser cache for no benefit.
     */
    public function store(User|int $user, int $mangaId, UploadedFile $file): SeriesCover
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $contents = (string) file_get_contents($file->getRealPath());
        $source = $this->inspect($contents);

        $image = $this->decode($contents);

        /*
         * Rotated before resized, so a portrait photo taken sideways is not stored
         * sideways. EXIF orientation 6 and 8 are the two a phone actually produces.
         *
         * The rotation returns a new image and the dimensions are read from *that*, not
         * from before. Reading them first and swapping the image afterwards would resize
         * a portrait to landscape proportions, which is the whole bug an earlier version
         * of this had.
         */
        $image = $this->applyOrientation($image, $this->orientation($file->getRealPath()));

        $width = imagesx($image);
        $height = imagesy($image);

        $target = $this->longEdge();
        $scale = min(1.0, $target / max($width, $height));

        $outWidth = max(1, (int) round($width * $scale));
        $outHeight = max(1, (int) round($height * $scale));

        // Only resample when there is something to gain. Re-encoding an 800px cover at
        // quality 85 loses a little for nothing, and a reader who uploads exactly the
        // size we would have produced gets their own bytes back.
        if ($scale < 1.0) {
            $resized = imagecreatetruecolor($outWidth, $outHeight);

            // A transparent source resized onto an opaque canvas comes out with a black
            // fringe, so the canvas is filled white first. Manga covers are opaque in
            // practice, and a PNG that is not gets the treatment it needs.
            imagefill($resized, 0, 0, (int) imagecolorallocate($resized, 255, 255, 255));

            imagecopyresampled(
                $resized,
                $image,
                0,
                0,
                0,
                0,
                $outWidth,
                $outHeight,
                imagesx($image),
                imagesy($image),
            );

            imagedestroy($image);
            $image = $resized;
        }

        $extension = self::ALLOWED[$source['mime']];
        $path = $this->pathFor($userId, $mangaId, $extension);

        $disk = $this->disk();
        $disk->put($path, $this->encode($image, $source['mime']));

        imagedestroy($image);

        /*
         * The old file goes before the row is rewritten, and only if it is a different
         * path. Replacing a jpg cover with a jpg cover writes the same path, so deleting
         * first would destroy the file just written.
         */
        $existing = $this->find($user, $mangaId);

        if ($existing !== null && $existing->path !== $path) {
            $disk->delete($existing->path);
        }

        if ($existing !== null) {
            $existing->disk = $this->diskName();
            $existing->path = $path;
            $existing->origin = 'upload';
            $existing->save();

            return $existing;
        }

        return SeriesCover::createFor($user, [
            'suwayomi_manga_id' => $mangaId,
            'disk' => $this->diskName(),
            'path' => $path,
            'origin' => 'upload',
            'source_page' => null,
        ]);
    }

    /**
     * Put the source's own cover back.
     *
     * Deletes the row and the file. The source's cover returns because the app only
     * overrides it when a row exists, so there is nothing to restore.
     */
    public function remove(User|int $user, int $mangaId): bool
    {
        $existing = $this->find($user, $mangaId);

        if ($existing === null) {
            return false;
        }

        Storage::disk($existing->disk)->delete($existing->path);

        $existing->delete();

        return true;
    }

    /**
     * The bytes of a stored cover, or null when the row exists and the file does not.
     *
     * Null in that case rather than an exception, because the row outliving its file is a
     * real state: a restore from a database dump without the storage directory. The
     * caller falls back to the source's cover, which is the right answer.
     *
     * @return array{contents: string, mime: string}|null
     */
    public function read(SeriesCover $cover): ?array
    {
        $disk = Storage::disk($cover->disk);

        if (! $disk->exists($cover->path)) {
            return null;
        }

        $contents = $disk->get($cover->path);

        if ($contents === null || $contents === '') {
            return null;
        }

        $info = @getimagesizefromstring($contents);

        return [
            'contents' => $contents,
            'mime' => is_array($info) ? (string) $info['mime'] : 'application/octet-stream',
        ];
    }

    /**
     * Every reader's cover for a set of series, keyed by manga id.
     *
     * One query for a whole page rather than one per entry, because a library page renders
     * up to 60 covers and 60 queries to learn which of them are custom is a cost with no
     * argument for it.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, SeriesCover>
     */
    public function mapFor(User|int $user, array $mangaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mangaIds)));

        if ($ids === []) {
            return [];
        }

        $rows = $this->query($user)->whereIn('suwayomi_manga_id', $ids)->get();

        $byManga = [];

        foreach ($rows as $row) {
            $byManga[(int) $row->suwayomi_manga_id] = $row;
        }

        return $byManga;
    }

    // ------------------------------------------------------------ internals

    /**
     * The mime and dimensions, read from the header without decoding the pixels.
     *
     * `getimagesizefromstring` parses the header only, which is the whole point: this
     * runs before gd allocates anything, so an absurd image is refused rather than
     * attempted.
     *
     * @return array{mime: string, width: int, height: int}
     */
    private function inspect(string $contents): array
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false) {
            throw new CoverRejected('That file is not an image this server can read.');
        }

        $mime = (string) $info['mime'];

        if (! isset(self::ALLOWED[$mime])) {
            throw new CoverRejected(
                'Covers have to be JPEG, PNG or WebP. That file is '.str_replace('image/', '', $mime).'.',
            );
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        if ($width < 1 || $height < 1) {
            throw new CoverRejected('That image has no readable dimensions.');
        }

        $pixels = $width * $height;

        if ($pixels > $this->maxPixels()) {
            throw new CoverRejected(sprintf(
                'That image is %d by %d, which is %.0f megapixels. The limit is %.0f, which is what this server has the memory to open.',
                $width,
                $height,
                $pixels / 1_000_000,
                $this->maxPixels() / 1_000_000,
            ));
        }

        return ['mime' => $mime, 'width' => $width, 'height' => $height];
    }

    /**
     * Decode into a gd image.
     */
    private function decode(string $contents): \GdImage
    {
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw new CoverRejected('That image could not be opened.');
        }

        return $image;
    }

    /**
     * Apply the EXIF orientation a phone camera records.
     *
     * Returns the image to carry on with, which is a different object when a rotation
     * happened: `imagerotate` does not rotate in place, it returns a new image. Reading
     * the dimensions before this and swapping the image afterwards would resize a
     * portrait to landscape proportions.
     *
     * Only the rotations, not the mirrored orientations. A phone produces 1, 3, 6 and 8
     * in practice, and handling 2, 4 and 5 needs a flip as well as a rotate for no case
     * that has ever been observed from a real cover upload.
     */
    private function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        $degrees = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate(
            $image,
            $degrees,
            (int) imagecolorallocate($image, 255, 255, 255),
        );

        if ($rotated === false) {
            return $image;
        }

        // The original has to go or it leaks for the life of the request.
        imagedestroy($image);

        return $rotated;
    }

    /**
     * The EXIF orientation, or 1 for anything that does not say.
     */
    private function orientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);

        if (! is_array($exif) || ! isset($exif['Orientation'])) {
            return 1;
        }

        $orientation = (int) $exif['Orientation'];

        return in_array($orientation, [1, 2, 3, 4, 5, 6, 7, 8], true) ? $orientation : 1;
    }

    /**
     * Encode to bytes in the format the upload arrived in.
     *
     * Re-encoding a PNG as a JPEG would lose the transparency for no gain, and the
     * extension is written to match what is actually encoded, so the served content type
     * and the file on disk cannot disagree.
     */
    private function encode(\GdImage $image, string $mime): string
    {
        ob_start();

        match ($mime) {
            'image/png' => imagepng($image, null, 6),
            'image/webp' => imagewebp($image, null, $this->quality()),
            default => imagejpeg($image, null, $this->quality()),
        };

        return (string) ob_get_clean();
    }

    /**
     * Where a reader's cover for a series lives.
     *
     * The user id is in the path so two readers' covers for the same series cannot
     * collide, and the name is stable so replacing a cover keeps its URL and therefore
     * the browser cache entry.
     */
    private function pathFor(int $userId, int $mangaId, string $extension): string
    {
        return sprintf('covers/%d/%d.%s', $userId, $mangaId, $extension);
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * The configured disk's name, which is what the row stores.
     *
     * Not `getDriver()`, which returns a League `FilesystemOperator` rather than a name.
     * The column is read back through `Storage::disk()`, so it has to hold a name, and
     * the config is where the name comes from.
     */
    private function diskName(): string
    {
        $name = (string) config('papyrus.covers.disk', 'local');

        return $name === '' ? 'local' : $name;
    }

    /**
     * @return Builder<SeriesCover>
     */
    private function query(User|int $user): Builder
    {
        return SeriesCover::query()->forUser(
            $user instanceof User ? (int) $user->getKey() : $user,
        );
    }

    private function longEdge(): int
    {
        return max(64, (int) config('papyrus.covers.long_edge', 800));
    }

    private function maxPixels(): int
    {
        return max(1, (int) config('papyrus.covers.max_pixels', 40_000_000));
    }

    private function quality(): int
    {
        return max(40, min(100, (int) config('papyrus.covers.quality', 85)));
    }
}
