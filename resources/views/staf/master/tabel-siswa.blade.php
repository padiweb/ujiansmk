<table class="tabel tabel-siswa">
    <thead>
        <tr>
            <th class="no">#</th>
            <th>Nama Siswa</th>
            <th>Kelas</th>
            <th>No. Peserta US</th>
            <th>Ruang Ujian</th>
            <th>Username</th>
            <th class="tengah">Status</th>
            @unless ($terkunci)
                <th class="kanan">Aksi</th>
            @endunless
        </tr>
    </thead>
    <tbody>
        @forelse ($baris as $s)
            @php $ruang = $def->ruangDari($s); @endphp
            <tr>
                <td class="no">{{ $baris->firstItem() + $loop->index }}</td>
                <td>
                    <strong>{{ $s->nama }}</strong>
                    <small class="sub-baris">NIS: {{ $s->nis ?: '–' }} · NISN: {{ $s->nisn }}</small>
                </td>
                <td>{{ $def->kelasDari($s) ?? '–' }}</td>
                <td>@if ($s->no_peserta_us)<code class="kode">{{ $s->no_peserta_us }}</code>@else<span class="redup">–</span>@endif</td>
                <td>
                    @if ($ruang)
                        <span class="lencana pucat">{{ $ruang[0] }} · meja {{ $ruang[1] }}</span>
                    @elseif ($s->status === 'aktif')
                        <span class="redup">Belum diset</span>
                    @else
                        <span class="redup">–</span>
                    @endif
                </td>
                <td><code class="kode">{{ $s->nisn }}</code></td>
                <td class="tengah">
                    <span class="lencana {{ $s->status === 'aktif' ? '' : 'pucat' }}">{{ ucfirst($s->status) }}</span>
                </td>
                @unless ($terkunci)
                    <td class="kanan aksi-baris">
                        <a href="{{ route('staf.master.edit', [$def->slug(), $s->id]) }}">Ubah</a>
                        <form method="POST" action="{{ route('staf.master.destroy', [$def->slug(), $s->id]) }}" onsubmit="return confirm('Hapus siswa ini?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="tautan">Hapus</button>
                        </form>
                    </td>
                @endunless
            </tr>
        @empty
            <tr><td colspan="{{ $terkunci ? 7 : 8 }}" class="kosong">{{ request()->hasAny(['q', 'kelas', 'ruang', 'status']) ? 'Tidak ada siswa yang cocok dengan pilihan ini.' : 'Belum ada data siswa.' }}</td></tr>
        @endforelse
    </tbody>
</table>
