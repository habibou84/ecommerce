{{-- Article sans photo : symbole d'image sur fond neutre (jamais d'aplat de couleur) --}}
<span class="flex h-full w-full flex-col items-center justify-center gap-1.5 text-slate-400" role="img" aria-label="Photo à venir">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="{{ $size ?? 'h-10 w-10' }}" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/></svg>
    @if ($label ?? false)<span class="text-sm font-medium">Photo à venir</span>@endif
</span>
