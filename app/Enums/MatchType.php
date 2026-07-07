<?php

namespace App\Enums;

enum MatchType: string {

    case Exact = 'exact';
    case Normalized = 'normalized';
    case Manual = 'manual';
    case Unmatched = 'unmatched';
    case CanonicalSameName = 'canonical_same_name'; // same normalized_name, different employee record
    case Historical = 'historical'; // inherited from a confirmed assignment in a prior period
    case Majority = 'majority'; // dominant branch among several candidates, no tie
    case Fuzzy = 'fuzzy'; // reduced-candidate-pool fuzzy name match, last resort

}
