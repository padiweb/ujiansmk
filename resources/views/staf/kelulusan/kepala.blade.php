<table class="kop">
    <tr>
        <td>
            <div class="kop-nama">{{ \Illuminate\Support\Str::upper($sekolah->nama) }}</div>
            @if ($sekolah->alamat)<div class="kop-alamat">{{ $sekolah->alamat }}@if ($sekolah->kota), {{ $sekolah->kota }}@endif @if ($sekolah->provinsi) {{ $sekolah->provinsi }}@endif</div>@endif
            @if ($sekolah->npsn)<div class="kop-alamat">NPSN : {{ $sekolah->npsn }}</div>@endif
        </td>
    </tr>
</table>
