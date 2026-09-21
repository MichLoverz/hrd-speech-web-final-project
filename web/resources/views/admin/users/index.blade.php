<x-page title="Pengguna" subtitle="Admin melihat semua data; HRD mengelola lowongan miliknya; Pewawancara hanya menilai sesi yang diikutinya.">
    <div class="sheet-section">
        <form method="GET" class="flex flex-wrap items-end gap-3 mb-6">
            <div class="flex-1 min-w-[200px]">
                <label for="q" class="field-label">Cari</label>
                <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" class="field-input" placeholder="Nama atau email">
            </div>
            <div>
                <label for="role" class="field-label">Peran</label>
                <select id="role" name="role" class="field-select">
                    <option value="">Semua</option>
                    @foreach (App\Models\User::ROLES as $r)
                        <option value="{{ $r }}" @selected(($filters['role'] ?? '') === $r)>{{ status_label($r) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-quiet">Terapkan</button>
            @if (array_filter($filters))
                <a href="{{ route('admin.users.index') }}" class="text-sm">Hapus filter</a>
            @endif
        </form>

        <div class="overflow-x-auto">
            <table class="ledger">
                <thead>
                    <tr>
                        <th>Pengguna</th>
                        <th class="text-right">Lowongan</th>
                        <th class="text-right">Sesi dipimpin</th>
                        <th class="text-right">Penilaian</th>
                        <th>Peran</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td>
                                <span class="font-semibold">{{ $user->name }}</span>
                                @if ($user->is(auth()->user()))
                                    <span class="text-sm text-pencil">(Anda)</span>
                                @endif
                                <div class="text-sm text-pencil">{{ $user->email }}</div>
                            </td>
                            <td class="text-right tabular-nums">{{ $user->job_openings_count }}</td>
                            <td class="text-right tabular-nums">{{ $user->led_sessions_count }}</td>
                            <td class="text-right tabular-nums">{{ $user->manual_assessments_count }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.users.role', $user) }}" class="flex items-center gap-2">
                                    @csrf @method('PATCH')
                                    <select name="role" class="field-select w-auto py-1 text-sm" aria-label="Peran {{ $user->name }}">
                                        @foreach (App\Models\User::ROLES as $r)
                                            <option value="{{ $r }}" @selected($user->role === $r)>{{ status_label($r) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn-quiet py-1">Simpan</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6">{{ $users->links() }}</div>
    </div>
</x-page>
