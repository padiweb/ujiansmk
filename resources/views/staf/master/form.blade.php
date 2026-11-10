@extends('layouts.staf')
@section('judul', ($m ? 'Ubah ' : 'Tambah ').$def->judul())

@section('konten')
<div class="pita-info pita-form">
    <div class="pita-atas">
        <a class="tombol-pita" href="{{ route('staf.master.index', $def->slug()) }}"><x-ikon nama="panah-kiri" :ukuran="16" />Kembali</a>
    </div>
    <h1>{{ $m ? 'Ubah' : 'Tambah' }} {{ $def->judul() }}</h1>
</div>

<form class="kartu formulir" method="POST"
      action="{{ $m ? route('staf.master.update', [$def->slug(), $m->id]) : route('staf.master.store', $def->slug()) }}">
    @csrf
    @if ($m) @method('PUT') @endif

    @foreach ($bidang as $b)
        @php
            $nama = $b['name'];
            $bawaan = array_key_exists('nilai', $b) ? $b['nilai'] : $m?->getAttribute($nama);
            $nilai = old($nama, $bawaan);
            if ($nilai instanceof \DateTimeInterface) { $nilai = $nilai->format('Y-m-d'); }
            $wajib = in_array('required', array_filter($b['rules'] ?? [], 'is_string'), true);
        @endphp
        <div class="bidang @error($nama) salah @enderror">
            <label for="f-{{ $nama }}">{{ $b['label'] }}@if ($wajib) <span class="wajib">*</span>@endif</label>

            @if ($b['type'] === 'select')
                <select id="f-{{ $nama }}" name="{{ $nama }}" @required($wajib)>
                    @if (! $wajib || isset($b['kosong']))
                        <option value="">{{ $b['kosong'] ?? '— Pilih —' }}</option>
                    @endif
                    @foreach ($b['options'] as $v => $label)
                        <option value="{{ $v }}" @selected((string) $nilai === (string) $v)>{{ $label }}</option>
                    @endforeach
                </select>
            @else
                <input id="f-{{ $nama }}" name="{{ $nama }}" type="{{ $b['type'] }}"
                       value="{{ $b['type'] === 'password' ? '' : $nilai }}"
                       @if ($b['type'] === 'password') autocomplete="new-password" @endif
                       @required($wajib)>
            @endif

            @isset($b['bantuan']) <small class="bantuan">{{ $b['bantuan'] }}</small> @endisset
            @error($nama) <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
    @endforeach

    <div class="aksi-form">
        <button class="tombol kecil" type="submit">Simpan</button>
        <a class="tombol kecil garis" href="{{ route('staf.master.index', $def->slug()) }}">Batal</a>
    </div>
</form>
@endsection
