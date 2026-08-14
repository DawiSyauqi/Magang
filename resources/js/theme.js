// =============================================================================
// theme.js — light/dark toggle
// - Simpan pilihan di localStorage ('theme' = 'light' | 'dark')
// - First load: pakai preferensi tersimpan, kalau kosong → ikut prefers-color-scheme
// - Sync <html data-theme> DAN <html data-bs-theme> (Bootstrap 5.3)
// - Tombol toggle: elemen mana pun dengan atribut [data-theme-toggle]
// =============================================================================
(function () {
    var STORAGE_KEY = 'theme';
    var root = document.documentElement;

    function getPreferred() {
        var saved = null;
        try { saved = localStorage.getItem(STORAGE_KEY); } catch (_) { }
        if (saved === 'light' || saved === 'dark') return saved;
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark';
        return 'light';
    }

    function apply(theme) {
        root.setAttribute('data-theme', theme);
        root.setAttribute('data-bs-theme', theme); // sync Bootstrap 5.3
        try { localStorage.setItem(STORAGE_KEY, theme); } catch (_) { }
        // Kabari komponen lain kalau perlu
        document.dispatchEvent(new CustomEvent('themechange', { detail: { theme: theme } }));
    }

    // Init secepat mungkin (idealnya script ini di-load defer, atau inline di <head>)
    apply(getPreferred());

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                apply(next);
            });
        });
    });

    // Ikuti perubahan sistem HANYA kalau user belum pernah override manual
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
            var saved = null;
            try { saved = localStorage.getItem(STORAGE_KEY); } catch (_) { }
            if (!saved) apply(e.matches ? 'dark' : 'light');
        });
    }
})();
