<?php

namespace App\Enums;

enum MatchType: string {

    case Exact = 'exact';
    case Normalized = 'normalized';
    case Manual = 'manual';
    case Unmatched = 'unmatched';
    case CanonicalSameName = 'canonical_same_name'; // same normalized_name, different employee record

}
