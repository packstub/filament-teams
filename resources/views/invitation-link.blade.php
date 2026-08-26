<div class="space-y-2">
    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('packstub-teams::teams.invitations.link_help') }}</p>
    <x-filament::input.wrapper>
        <x-filament::input type="text" value="{{ $url }}" readonly onclick="this.select()" />
    </x-filament::input.wrapper>
</div>
