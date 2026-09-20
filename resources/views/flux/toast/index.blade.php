@blaze(fold: true, safe: ['position'])

@props([
    'position' => 'bottom end',
])

<ui-toast x-data x-on:toast-show.document="! $el.closest('ui-toast-group') && $el.showToast($event.detail)" popover="manual" position="{{ $position }}" wire:ignore>
    <template>
        <div {{ $attributes->only(['class'])->class('max-w-[calc(100vw-2rem)] w-[calc(100vw-2rem)] in-[ui-toast-group]:max-w-[calc(100vw-2rem)] in-[ui-toast-group]:w-[calc(100vw-2rem)] sm:in-[ui-toast-group]:w-sm sm:in-[ui-toast-group]:max-w-none') }} data-variant="" data-flux-toast-dialog>
            {{--
                Frosted glass card. All variant styling (tinted wash, icon
                badge color, progress bar) lives in app.css keyed off the
                [data-variant] attribute that Flux's toast-show JS sets on
                the outer [data-flux-toast-dialog] element before insertion
                (empty string = info styling). Keep that contract intact
                when editing.
            --}}
            <div class="flux-toast" data-flux-toast-card>
                <div class="flux-toast-icon">
                    {{-- Success --}}
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="hidden [[data-flux-toast-dialog][data-variant=success]_&]:block size-[18px]">
                        <path fill-rule="evenodd" d="M8 15A7 7 0 1 0 8 1a7 7 0 0 0 0 14Zm3.844-8.791a.75.75 0 0 0-1.188-.918l-3.7 4.79-1.649-1.833a.75.75 0 1 0-1.114 1.004l2.25 2.5a.75.75 0 0 0 1.15-.043l4.25-5.5Z" clip-rule="evenodd" />
                    </svg>
                    {{-- Warning --}}
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="hidden [[data-flux-toast-dialog][data-variant=warning]_&]:block size-[18px]">
                        <path fill-rule="evenodd" d="M6.701 2.25c.577-1 2.02-1 2.598 0l5.196 9a1.5 1.5 0 0 1-1.299 2.25H2.804a1.5 1.5 0 0 1-1.3-2.25l5.197-9ZM8 4a.75.75 0 0 1 .75.75v3a.75.75 0 1 1-1.5 0v-3A.75.75 0 0 1 8 4Zm0 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                    </svg>
                    {{-- Info --}}
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="hidden [[data-flux-toast-dialog][data-variant=info]_&]:block size-[18px]">
                        <path fill-rule="evenodd" d="M15 8A7 7 0 1 1 1 8a7 7 0 0 1 14 0ZM9 5a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM6.75 8a.75.75 0 0 0 0 1.5h.75v1.75a.75.75 0 0 0 1.5 0v-2.5A.75.75 0 0 0 8.25 8h-1.5Z" clip-rule="evenodd" />
                    </svg>
                    {{-- Danger --}}
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="hidden [[data-flux-toast-dialog][data-variant=danger]_&]:block size-[18px]">
                        <path fill-rule="evenodd" d="M8 15A7 7 0 1 0 8 1a7 7 0 0 0 0 14ZM8 4a.75.75 0 0 1 .75.75v3a.75.75 0 0 1-1.5 0v-3A.75.75 0 0 1 8 4Zm0 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                    </svg>
                </div>

                <div class="flux-toast-body">
                    {{-- Heading --}}
                    <div class="flux-toast-heading"><slot name="heading"></slot></div>

                    {{-- Text --}}
                    <div class="flux-toast-text"><slot name="text"></slot></div>

                    {{-- Link --}}
                    <template name="link">
                        <a class="flux-toast-link"><slot name="text"></slot></a>
                    </template>
                </div>

                {{-- Close button --}}
                <ui-close class="flex items-center shrink-0 self-center">
                    <button type="button" class="flux-toast-close" as="button" aria-label="{{ __('Close') }}">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"></path>
                        </svg>
                    </button>
                </ui-close>

                {{--
                    Auto-dismiss progress bar. Lives inside the card so its
                    absolute positioning anchors to the card in both group
                    and solo mode; the drain runs on a CSS animation (see
                    app.css) timed to Flux's 5s default duration.
                --}}
                <div class="flux-toast-progress" data-flux-toast-progress></div>
            </div>
        </div>
    </template>
</ui-toast>
