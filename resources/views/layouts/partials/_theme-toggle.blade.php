{{-- Reusable theme toggle button.
     Cara pakai: @include('layouts.partials._theme-toggle')
     JavaScript-nya di-handle theme.js lewat atribut data-theme-toggle --}}
<button type="button"
        class="theme-toggle"
        data-theme-toggle
        aria-label="Ganti tema light/dark"
        title="Ganti tema">
    {{-- Moon (light mode → show moon = "switch to dark") --}}
    <svg class="icon-moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
    </svg>
    {{-- Sun (dark mode → show sun = "switch to light") --}}
    <svg class="icon-sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4"></circle>
        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"></path>
    </svg>
</button>
