@php
    $sourceUrl = config('relaticle.source_url');
@endphp

@if(filled($sourceUrl))
    {{-- AGPL-3.0 section 13: this panel is network-reachable, so remote users
         are entitled to an offer of the corresponding source. Always visible
         to every signed-in user, not gated behind the workspace-admin check
         that filament.app.sidebar-footer uses. --}}
    <div class="border-t border-gray-200 px-4 py-2 dark:border-white/10">
        <a
            href="{{ $sourceUrl }}"
            target="_blank"
            rel="noopener noreferrer"
            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs text-gray-400 transition hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
        >
            <x-heroicon-o-code-bracket class="h-4 w-4 flex-shrink-0" />
            <span class="truncate">{{ __('filament/app.source_link.label') }}</span>
        </a>
    </div>
@endif
