<?php

namespace App\Enums;

/**
 * What kind of comparison a LeadScoringField supports — drives both which
 * LeadScoringOperator cases are valid for it (LeadScoringField::allowedOperators())
 * and which input widget the rule-builder UI shows for its value.
 */
enum LeadScoringFieldKind: string
{
    case Numeric = 'numeric';
    case Enum = 'enum';
    case Boolean = 'boolean';
}
