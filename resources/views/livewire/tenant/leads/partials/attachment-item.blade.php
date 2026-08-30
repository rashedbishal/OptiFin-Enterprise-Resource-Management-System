@if ($attachment->status === \App\Enums\LeadAttachmentStatus::Downloaded)
    <a
        href="{{ route('tenant-leads.attachment.download', [$lead, $attachment]) }}"
        target="_blank"
        rel="noopener"
        class="inline-flex w-fit items-center gap-1.5 text-blue-600 hover:underline dark:text-blue-400"
    >
        <flux:icon icon="paper-clip" class="size-4" />
        {{ $attachment->summary() }}
    </a>
@elseif ($attachment->status === \App\Enums\LeadAttachmentStatus::Failed)
    <flux:text class="inline-flex w-fit items-center gap-1.5 text-red-600 dark:text-red-400">
        <flux:icon icon="exclamation-triangle" class="size-4" />
        {{ __('Failed to download :type', ['type' => $attachment->type]) }}
    </flux:text>
@else
    <flux:text class="inline-flex w-fit items-center gap-1.5 text-zinc-500">
        <flux:icon icon="arrow-path" class="size-4 animate-spin" />
        {{ __('Downloading :type…', ['type' => $attachment->type]) }}
    </flux:text>
@endif
