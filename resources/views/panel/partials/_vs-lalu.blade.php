{{-- Keterangan kolom "vs periode lalu". Param: $periode (array dengan 'dari' & 'sampai', Y-m-d). --}}
@php
    $vsDari = \Carbon\Carbon::parse($periode['dari']);
    $vsSampai = \Carbon\Carbon::parse($periode['sampai']);
    $vsHari = $vsDari->diffInDays($vsSampai) + 1;
    $vsLaluSampai = $vsDari->copy()->subDay();
    $vsLaluDari = $vsLaluSampai->copy()->subDays($vsHari - 1);
@endphp
<div class="dx-kpi-note mt-1">
    <strong>vs periode lalu</strong> = perubahan menit downtime dibanding
    {{ $vsLaluDari->translatedFormat('d M') }}{{ $vsHari > 1 ? ' – '.$vsLaluSampai->translatedFormat('d M Y') : ' '.$vsLaluDari->format('Y') }}
    ({{ $vsHari }} hari tepat sebelum periode ini).
    <span class="dx-delta up"><i class="bi bi-arrow-up-short"></i>merah</span> = naik (memburuk) ·
    <span class="dx-delta down"><i class="bi bi-arrow-down-short"></i>hijau</span> = turun (membaik) · – = periode lalu tidak ada data.
</div>
