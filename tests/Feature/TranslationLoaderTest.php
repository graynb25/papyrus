<?php

namespace Tests\Feature;

use App\Support\Translation\GuardedTranslationLoader;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefusingFilesystem;
use Tests\TestCase;

/**
 * The translator must never turn copy into a filename.
 *
 * ## The measured failure
 *
 * Laravel loads a key as a translation group when the key is not in a JSON file, so an English
 * sentence becomes `lang/en/<sentence>.php`. With `open_basedir` enabled in the 1.5.3 candidate,
 * `file_exists` warned on the `?` in `Forgot your password?`, Laravel promoted the warning to a
 * `ViewException`, and the login page answered 500. The two measured keys carrying both `?` and a
 * period are the passkey removal confirmation and the series unread confirmation.
 *
 * ## What the seam has to prove
 *
 * The guard returns an empty group before the filesystem is asked, and still forwards every lookup
 * that was already safe: framework groups, project groups, nested groups and JSON keys. The spy
 * throws on the first lookup so a regression names the exact path rather than disappearing into a
 * false return on a machine without `open_basedir`.
 */
class TranslationLoaderTest extends TestCase
{
    /**
     * The sentence keys measured in the 1.5.3 candidate.
     *
     * @return array<string, array{string}>
     */
    public static function sentenceKeys(): array
    {
        return [
            'the login prompt' => ['Forgot your password?'],
            'the passkey removal confirmation' => [
                'Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.',
            ],
            'the series unread confirmation' => [
                'Mark every chapter unread? The read flags on this series clear.',
            ],
        ];
    }

    /**
     * Characters Windows rejects in a filename, and the allowlist therefore rejects.
     *
     * @return array<string, array{string}>
     */
    public static function invalidFilenameCharacters(): array
    {
        return [
            'a colon' => ['validation:required'],
            'a quote' => ['validation"required'],
            'an angle bracket' => ['validation<required'],
            'a pipe' => ['validation|required'],
        ];
    }

    #[DataProvider('sentenceKeys')]
    public function test_a_sentence_key_never_becomes_a_filesystem_path(string $key): void
    {
        $files = new RefusingFilesystem;
        $loader = new GuardedTranslationLoader(new FileLoader($files, $this->scratchPath('translation-paths')));

        $this->assertSame([], $loader->load('en', $key));
        $this->assertSame(
            [],
            $loader->load('en', $key, '*'),
            'the translator names an unnamespaced key with a * namespace',
        );
        $this->assertSame(0, $files->existsCalls);
    }

    #[DataProvider('invalidFilenameCharacters')]
    public function test_a_key_with_an_invalid_filename_character_is_refused(string $key): void
    {
        $files = new RefusingFilesystem;
        $loader = new GuardedTranslationLoader(new FileLoader($files, $this->scratchPath('translation-paths')));

        $this->assertSame([], $loader->load('en', $key));
        $this->assertSame(0, $files->existsCalls);
    }

    public function test_a_safe_group_still_loads(): void
    {
        $path = $this->scratchPath('translation-group');

        mkdir($path.'/en/admin', 0o777, true);
        file_put_contents($path.'/en/messages.php', "<?php\n\nreturn ['hello' => 'Hello'];\n");
        file_put_contents($path.'/en/admin/users.php', "<?php\n\nreturn ['title' => 'Users'];\n");

        $loader = new GuardedTranslationLoader(new FileLoader(new Filesystem, $path));

        $this->assertSame(['hello' => 'Hello'], $loader->load('en', 'messages'));
        $this->assertSame(['title' => 'Users'], $loader->load('en', 'admin/users'));
    }

    public function test_a_json_translation_key_still_resolves(): void
    {
        $paths = $this->scratchPath('translation-json-paths');
        $json = $this->scratchPath('translation-json');

        file_put_contents(
            $json.'/en.json',
            (string) json_encode(['Welcome back' => 'Welcome back, reader']),
        );

        $loader = new GuardedTranslationLoader(new FileLoader(new Filesystem, $paths));
        $loader->addJsonPath($json);

        $translator = new Translator($loader, 'en');

        $this->assertSame('Welcome back, reader', $translator->get('Welcome back'));
    }

    public function test_the_application_translator_uses_the_guard_and_vendor_groups_still_load(): void
    {
        $loader = app('translation.loader');

        $this->assertInstanceOf(GuardedTranslationLoader::class, $loader);
        $this->assertSame(
            $loader,
            app('translator')->getLoader(),
            'the translator must be built on the guard, not a loader resolved before it',
        );
        $this->assertSame('The :attribute field is required.', __('validation.required'));
    }

    public function test_a_sentence_key_returns_itself_without_a_warning(): void
    {
        $warnings = [];

        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $first = __('Forgot your password?');
            $second = __('Forgot your password?');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'the lookup raised a warning: '.implode('; ', $warnings));
        $this->assertSame('Forgot your password?', $first);
        $this->assertSame('Forgot your password?', $second, 'the second call reads the cached group');
    }

    public function test_the_guard_forwards_namespace_registration(): void
    {
        $hint = $this->scratchPath('translation-hint');
        $loader = new GuardedTranslationLoader(
            new FileLoader(new Filesystem, $this->scratchPath('translation-namespaces')),
        );

        $loader->addNamespace('papyrus', $hint);

        $this->assertSame(['papyrus' => $hint], $loader->namespaces());
    }
}
