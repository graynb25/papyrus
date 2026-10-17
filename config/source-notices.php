<?php

use App\Support\SourceNotices;

/*
|--------------------------------------------------------------------------
| Per-site behaviour the Sources page states before an install
|--------------------------------------------------------------------------
|
| The facts behind the notices rendered on extension cards. The four ban
| entries are what the owner named on 2026-10-16. Comix is the different
| class, corroborated upstream in the Keiyoushi extensions-source tracker
| (issues 12130 and 16684). Only sites named here get a notice; the words
| shown for each kind live with the page that renders them.
|
*/

return [
    'sites' => [
        'Kayn Scans' => SourceNotices::BAN,
        'Asmotoon' => SourceNotices::BAN,
        'Magustoon' => SourceNotices::BAN,
        'Genztoons' => SourceNotices::BAN,
        'Comix' => SourceNotices::SCRAMBLE,
    ],
];
