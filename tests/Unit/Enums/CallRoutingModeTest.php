<?php

use App\Enums\CallRoutingMode;

/*
 * WhatsApp Call Backup / Overflow Receiver, Phase 2 — pure enum structure.
 * Behavioral routing (who actually gets BackupFallback and when) belongs to
 * CallRouter's own tests, not here.
 */

test('BackupFallback exists alongside every pre-existing routing mode', function () {
    expect(CallRoutingMode::values())->toBe([
        'assignee',
        'backup_fallback',
        'admin_fallback',
        'none',
        'agent_initiated',
    ]);
});

test('BackupFallback has the expected string value', function () {
    expect(CallRoutingMode::BackupFallback->value)->toBe('backup_fallback');
});

test('every pre-existing case keeps its original value unchanged', function (CallRoutingMode $case, string $expected) {
    expect($case->value)->toBe($expected);
})->with([
    'Assignee' => [CallRoutingMode::Assignee, 'assignee'],
    'AdminFallback' => [CallRoutingMode::AdminFallback, 'admin_fallback'],
    'None' => [CallRoutingMode::None, 'none'],
    'AgentInitiated' => [CallRoutingMode::AgentInitiated, 'agent_initiated'],
]);
