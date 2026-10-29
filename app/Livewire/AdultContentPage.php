<?php

namespace App\Livewire;

use App\Models\Source;
use App\Support\AdultContent;
use Flux\Flux;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The base for the pages that filter by the catalogue's content rating.
 *
 * ## Why a base class and not a service
 *
 * Two pages filter by that rating, the Sources page and the Search page, and
 * they have to agree about what a reader may be shown. A service cannot hold
 * that agreement on its own, because the answer has to be true at the moment a
 * page renders and again after a confirmation in the same request, and a
 * Livewire component's own render is what carries that.
 *
 * A trait would do the same job, but the pages are anonymous classes inside
 * Blade files, and PHPStan does not read Blade, so a trait used only there is
 * reported as used zero times and never analysed. A base class is a file the
 * analyser reads, so the rules in it are the rules that are checked.
 *
 * ## Why the rating lives here and not on the pages
 *
 * Because it is the same property on both of them: the ratings the reader is
 * looking at, carried in the address so a link can describe a search. The
 * attribute rides with it, because an attribute on an inherited property is
 * still found: Livewire's own attribute collection reads the class's
 * properties, inherited ones included.
 *
 * ## Why the read is a method and not a property
 *
 * Because a property is a value that can be stale. The confirmation writes the
 * setting and the render that follows has to see it, and a Livewire computed
 * is remembered for the life of the component. A plain method is read again
 * every time it is called, which is the only way the answer can be the truth
 * on the render after the write.
 */
abstract class AdultContentPage extends Component
{
    /**
     * The ratings the reader is looking at.
     *
     * Safe, Mixed and NSFW, which are the three the catalogue carries. The
     * default excludes NSFW, and the exclusion is not a filter the reader has
     * to remember: it is what the gate allows until the reader says otherwise.
     *
     * @var array<int, string>
     */
    #[Url(as: 'content', except: [Source::SAFE, Source::MIXED])]
    public array $content = [Source::SAFE, Source::MIXED];

    /**
     * Whether this reader has turned adult content on.
     */
    public function adultContentOn(): bool
    {
        return app(AdultContent::class)->enabled(auth()->user());
    }

    /**
     * The ratings this reader may be shown, whatever the address bar says.
     *
     * The gate removes NSFW from what was asked for and never adds it, so a
     * reader who has turned it on may still be looking at Safe and Mixed only.
     * The list is never empty, because an empty list and an absent filter are
     * the same array and only one of them can mean "nothing": a catalogue
     * filtered to nothing is an empty catalogue, not an unfiltered one.
     *
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    public function visibleRatings(array $requested): array
    {
        if (! $this->adultContentOn()) {
            $kept = array_values(array_diff($requested, [Source::NSFW]));

            return $kept === [] ? [Source::SAFE, Source::MIXED] : $kept;
        }

        return $requested === [] ? [Source::SAFE, Source::MIXED] : $requested;
    }

    /**
     * Turn adult content on, after the reader confirmed they are 18 or older.
     *
     * The rating joins the filter as well, because the reader pressed a
     * button that says they want adult content and the filter is where that
     * choice is visible. The filter stays a scope, so unticking the chip
     * narrows it again, and turning the gate off takes the rating back out.
     */
    public function confirmAdultContent(): void
    {
        app(AdultContent::class)->set(auth()->user(), true);

        if (! in_array(Source::NSFW, $this->content, true)) {
            $this->content[] = Source::NSFW;
        }

        Flux::toast(
            text: __('The sources and extensions the catalogue marks NSFW are listed and searched from now on.'),
            heading: __('Adult content on'),
            variant: 'success',
        );
    }

    /**
     * Turn adult content off.
     *
     * The rating leaves the filter as well as the gate, because the address
     * bar has to keep describing what the page is doing. A filter that still
     * carried NSFW would be a promise the gate no longer keeps, and a link
     * carrying it would ask a question the page cannot answer.
     */
    public function turnAdultContentOff(): void
    {
        app(AdultContent::class)->set(auth()->user(), false);

        $this->content = array_values(array_diff($this->content, [Source::NSFW]));

        Flux::toast(
            text: __('Nothing marked NSFW is listed or searched from now on.'),
            heading: __('Adult content off'),
        );
    }
}
