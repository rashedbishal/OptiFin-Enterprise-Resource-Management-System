---
paths:
  - app/Services/LeadMergeService.php
---

# App Services

## Tag union in merge reads the pivot table directly, not $loser->tags
LeadMergeService::unionTags() queries DB::table('lead_lead_tag') directly instead of iterating $loser->tags and reading ->pivot->tagged_by — Larastan doesn't resolve the pivot magic property to a typed object here, so ->pivot access reports as an undefined-property error. The raw query builder read is fully statically typed and avoids that gap. Tags aren't in MERGEABLE_FIELDS or CHILD_MODELS — the union is its own explicit step inside the same merge transaction (no nested transaction), preserving the loser's original tagged_by attribution for any tag it uniquely contributes.
