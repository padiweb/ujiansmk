<table class="kop">
    <tr>
        <td class="kop-logo"><img src="{{ asset('images/logo_smk.png') }}" alt="Logo"></td>
        <td class="kop-teks">
            @if ($sekolah->nama_induk)<div class="kop-induk">{{ \Illuminate\Support\Str::upper($sekolah->nama_induk) }}</div>@endif
            <div class="kop-nama">{{ \Illuminate\Support\Str::upper($sekolah->nama) }}</div>
            @if ($sekolah->alamat)
                <div class="kop-alamat">Alamat : {{ $sekolah->alamat }}@if ($sekolah->telepon) Telp. {{ $sekolah->telepon }}@endif</div>
            @elseif ($sekolah->telepon)
                <div class="kop-alamat">Telp. {{ $sekolah->telepon }}</div>
            @endif
            @if ($sekolah->website || $sekolah->email)
                <div class="kop-alamat">@if ($sekolah->website)Website : {{ $sekolah->website }}@endif @if ($sekolah->website && $sekolah->email)&nbsp;&nbsp;@endif @if ($sekolah->email)Email : {{ $sekolah->email }}@endif</div>
            @endif
        </td>
    </tr>
</table>
