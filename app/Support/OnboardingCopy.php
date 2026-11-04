<?php

namespace App\Support;

/**
 * Sentences that more than one onboarding surface has to say.
 *
 * The empty library and the first tour step both have to explain why Papyrus
 * starts with nothing. Two copies of that sentence drift the moment one of them
 * is edited, and the tour is supposed to point at the page it is describing.
 */
final class OnboardingCopy
{
    public const SOURCE_REQUIREMENT = 'Papyrus reads from sources. A source is an extension repository that holds manga; Papyrus ships with none, so the first step is to add one.';
}
