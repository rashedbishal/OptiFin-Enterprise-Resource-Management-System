---
paths:
  - 'app/{Models/Deal.php,Models/DealStatusLog.php,Enums/DealStatus.php,Services/LeadMergeService.php}'
---

# App Models

## Deal is a CHILD_MODEL; DealStatusLog must never be
Deal is a plain lead_id-keyed child of Lead, added to LeadMergeService::CHILD_MODELS like LeadNote/LeadAttachment. DealStatusLog is keyed by deal_id only (no lead_id/tenant_id column) — never add it to CHILD_MODELS, it would fail (no such column) or be a no-op. A Deal's own id never changes during merge (only its lead_id does), so DealStatusLog rows stay correctly attached automatically with zero reparenting code. DealStatus (Open/Won/Lost) is a plain enum, not a catalog — deliberately independent of LeadStatus, which already models the Lead's own proposal_sent/negotiation/won/lost pipeline. canTransitionTo() blocks direct Won<->Lost; must pass back through Open first.
