---
paths:
  - 'config/calling.php,resources/views/livewire/platform/clients/lead-sources.blade.php'
---

# Config Views Livewire Platform Clients

## Call permission template body: default must have exactly {{1}}/{{2}}, insert via buttons
Real bug caught 2026-10-14, same day the Create form shipped: the template I manually created live (using a Meta-AI-suggested body with ZERO {{n}} placeholders) got Meta-APPROVED fine — Meta's own template review doesn't care about variable count matching anything — but RequestLeadCallPermission::sendTemplate() always sends exactly 2 text parameters when actually using the approved template later, REGARDLESS of what its body says. A 0-variable approved body would have silently failed the first real send with a parameter-count mismatch, discovered only at that point, not at template-creation time. config('calling.call_permission_request_template')['body'] now correctly includes both {{1}} and {{2}} as its default — if this default is ever changed, it MUST keep exactly one of each (see config/calling.php's own comment for why) — the live, broken template was deleted and must be recreated via the form with a corrected body before Outbound Calling works for that tenant.

The Create form (Lead Sources page) no longer expects the admin to type {{1}}/{{2}} by hand: two buttons ("Insert Lead's Name" / "Insert Lead's Inquiry") insert the token at the textarea's cursor position (plain Alpine x-data + $wire.entangle + x-ref, same cursor-insertion technique as the pre-existing Messenger template's own "Insert Variable" button just above it in this same file), each disabled once its token is already present so a second copy can't be accidentally inserted. Live ✓/missing text indicators track each placeholder's presence. Built the brace-construction the same established non-adjacent-pieces way this file already uses elsewhere (openBrace/closeBrace PHP variables, concatenated inside a real `{{ }}` echo — NOT a literal `{{1}}` written directly in the source, which Blade would silently misparse as PHP expression `1`).
