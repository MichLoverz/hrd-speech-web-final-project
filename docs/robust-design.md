# Robust Design — Identifikasi Faktor

**Produk/Layanan:** HireNote — sistem penilaian wawancara kerja terstruktur (web + pengenalan suara lokal + penyusunan kartu skor otomatis)

## Apa arti "robust" untuk sistem ini

Wawancara kerja tidak pernah terjadi di ruang laboratorium. Ruangannya berisik, mikrofonnya seadanya, kandidat gugup dan mencampur bahasa Indonesia dengan istilah Inggris, pewawancara lupa menekan tombol, dan laptop kadang sibuk mengerjakan hal lain. Sasaran rancangan yang tangguh di sini bukan mengejar akurasi tertinggi di kondisi terbaik, melainkan **menjaga keluaran tetap stabil dan bisa dipercaya ketika kondisinya jelek** — karena keputusan perekrutan orang bergantung padanya.

---

## 1. Ringkasan (untuk satu slide)

| Factor Type | Example | Description / Impact |
|---|---|---|
| Control Factor | Ukuran model pengenalan suara untuk teks final | Menentukan mutu transkrip sekaligus apakah sistem sanggup mengikuti pembicaraan secara langsung. |
| Noise Factor | Kebisingan ruangan dan mutu mikrofon | Menaikkan salah transkrip dan mengaburkan ciri suara, sehingga label pembicara bisa tertukar. |
| Signal Factor | Pewawancara membacakan pertanyaan dari layar | Dari ucapan itulah sistem mengetahui pertanyaan sudah berpindah, sehingga jawaban kandidat tersimpan di bawah pertanyaan yang tepat. |
| Output Response | Ketepatan transkrip dan kestabilan waktu tanggap | Hasil yang harus tetap konsisten walau ruangan, mikrofon, dan beban CPU berubah-ubah. |

---

## 2. Tabel rinci

### Control Factors (C)
*Variabel yang bisa kami tetapkan sendiri saat merancang dan menjalankan sistem.*

| Example | Description / Impact |
|---|---|
| Model final `small` (bukan `base` atau `medium`) | Menentukan mutu teks yang akan dinilai. `small` WER 0,176 dengan RTF 0,50; `medium` lebih akurat (0,128) tetapi RTF 1,20 sehingga tertinggal makin jauh sepanjang wawancara. |
| Model teks sementara `base` | Menghasilkan teks abu-abu yang muncul saat orang masih bicara. Sengaja dipilih yang paling ringan (RTF 0,08) karena teksnya pasti akan ditimpa. |
| Jumlah thread CPU (12, semua inti) | Mempercepat inferensi; setelan bawaan 4 thread membuatnya sekitar 35% lebih lambat. |
| Tipe komputasi `int8` | Memperkecil model dan mempercepat inferensi di CPU, karena laptop tidak punya GPU. |
| Ambang deteksi suara 0,5 dan diam penutup 500 ms | Menentukan kapan satu segmen dianggap selesai. Terlalu pendek memotong kalimat di tengah; terlalu panjang membuat teks final terlambat muncul. |
| Selang teks sementara 1.200 ms | Mengatur seberapa "hidup" teks terasa saat orang bicara, ditukar dengan beban CPU. |
| Teks pertanyaan diberikan sebagai konteks ke model | Menaikkan peluang istilah dari pertanyaan muncul benar. Terukur: WER `small` turun 0,225 → 0,186. |
| Hanya segmen ≥ 2 detik yang boleh membentuk pusat suara | Mencegah potongan pendek yang sudah terdistorsi menjadi acuan suara seseorang. Terukur: ketepatan label 7/21 → 20/20. |
| Pengelompokan ulang suara di akhir wawancara | Memperbaiki kesalahan awal yang tidak bisa dibatalkan oleh metode berjalan, lalu koreksinya dikirim ke browser. |
| Ambang pembedaan suara 0,55 | Terlalu tinggi membuat satu orang terbaca sebagai dua; terlalu rendah membuat dua orang terbaca sebagai satu. |
| Ambang pencocokan pertanyaan 0,45 dan frasa pemicu 0,80 | Terlalu longgar membuat pertanyaan melompat sendiri; terlalu ketat membuat pertanyaan tidak pernah berpindah otomatis. |
| Penyimpanan bertahap: transkrip tiap 2 detik, audio tiap 10 detik | Menentukan batas atas data yang hilang kalau terjadi gangguan — beberapa detik, bukan seluruh wawancara. |
| Verifikasi setiap kutipan ke transkrip asli | Kutipan yang tidak ditemukan dibuang, sehingga kartu skor tidak memuat ucapan yang tidak pernah terjadi. |
| Jumlah kalimat yang diambil per kriteria penilaian | Terlalu sedikit membuat bukti kurang; terlalu banyak membuat ringkasan kabur dan biaya naik. |
| LLM daring dengan cadangan model lokal | Menentukan apakah kartu skor tetap bisa terbit saat internet bermasalah. |

### Noise Factors (N)
*Variabel yang tidak bisa kami kendalikan tetapi membuat hasil berubah-ubah.*

| Example | Description / Impact |
|---|---|
| Kebisingan dan gema ruangan | Menaikkan salah transkrip dan mengotori ciri suara yang dipakai membedakan pembicara. |
| Mutu dan jarak mikrofon (bawaan laptop vs headset) | Mengubah rasio sinyal terhadap derau, sehingga WER bisa berbeda jauh untuk kalimat yang sama. |
| Peredam bising dan AGC bawaan browser | Mengubah ciri suara sebelum sampai ke sistem. Ini penyebab nyata kegagalan label 7/21 pada uji browser pertama. |
| Campur kode Indonesia–Inggris | Istilah Inggris paling sering salah: *with* → width, *composite* → komposit, *where* → ware. |
| Nama diri dan istilah jarang | Penyumbang kesalahan terbesar pada tolok ukur FLEURS — *Brzezinski*, *Sachin Tendulkar*, *John F. Kennedy*. |
| Bicara spontan: kata pengisi, ralat, jeda mikir | Ciri wawancara sungguhan yang tidak ada pada naskah dibacakan. Diduga penyumbang kesalahan terbesar, dan **belum terukur**. |
| Dua suara mirip atau bicara bertumpuk | Label pembicara bisa tertukar; satu segmen hanya bisa diberi satu label. |
| CPU laptop terpakai proses lain | Waktu tanggap melonjak. Pernah teramati satu kalimat 6,4 detik dengan RTF 1,6. |
| Pewawancara lupa menandai pergantian pertanyaan | Jawaban kandidat masuk ke pertanyaan yang salah, sehingga penilaian per kriteria ikut melenceng. |
| Browser tertutup, laptop tertidur, listrik mati | Berpotensi menghapus seluruh wawancara yang sudah berjalan. |
| Internet putus saat menyusun kartu skor | Kartu skor gagal terbit padahal wawancaranya sudah selesai. |
| LLM mengarang kalimat yang tidak diucapkan | Paling berbahaya, karena hasil karangan terlihat meyakinkan dan bisa dipakai menilai orang. |
| Angka salah dikenali | Contoh nyata: *delapan juta* terbaca *80 juta* — kesalahan sepuluh kali lipat pada data yang sensitif. |

### Signal Factors (S)
*Masukan dari pengguna yang menentukan keluaran seperti apa yang diinginkan.*

| Example | Description / Impact |
|---|---|
| Daftar pertanyaan yang disusun HRD | Seluruh jalannya wawancara mengikuti daftar ini, dan teks tiap pertanyaan dipakai lagi sebagai pembanding untuk mengenali ucapan pewawancara. |
| Pewawancara membacakan pertanyaan dari layar | Ucapan itulah yang dicocokkan sistem dengan teks pertanyaan di daftar; makin mirip bunyinya, makin besar peluang perpindahan terbaca dengan benar. |
| Frasa pemicu yang diucapkan ("lanjut ke pertanyaan berikutnya") | Dipakai kalau lapis pencocokan teks meleset, misalnya karena pertanyaannya sendiri salah ditranskripsi, supaya wawancara tetap bisa maju. |
| Tombol pertanyaan berikutnya / klik pertanyaan | Kalau kedua cara otomatis di atas gagal, pewawancara masih bisa memindahkan sendiri — jadi tidak pernah ada kondisi buntu. |
| Kriteria penilaian dan bobotnya | Menetapkan aspek apa saja yang muncul di kartu skor dan seberapa besar masing-masing menarik nilai akhir. |
| Bahasa wawancara dikunci ke Indonesia | Karena bahasanya sudah ditetapkan, model tidak lagi menebak sendiri lalu menerjemahkan — persoalan yang sempat muncul ketika setelannya dibiarkan otomatis. |
| Lama wawancara dan jumlah pertanyaan | Makin panjang sesinya, makin banyak audio yang harus diproses dan disimpan tanpa terputus dari awal sampai akhir. |
| Koreksi manusia: label pembicara, pemindahan segmen, ulangi rekaman | Memberi jalan memperbaiki hasil yang keliru tanpa harus mengorbankan rekaman yang sudah berjalan. |
| Skor manual HRD (dinilai sebelum melihat hasil sistem) | Berperan sebagai acuan manusia — tanpa ini tidak ada cara mengukur apakah penilaian sistem mendekati penilaian sesungguhnya. |

### Output Responses (Y)
*Hasil terukur yang harus tetap stabil walaupun kondisinya berubah.*

| Example | Description / Impact |
|---|---|
| WER dan CER transkrip yang disimpan | Mutu bahan baku kartu skor. Semua tahap sesudahnya hanya sebagus transkrip ini. |
| Ketepatan label pembicara | Kalau salah, ucapan pewawancara bisa ikut dinilai sebagai jawaban kandidat. |
| Ketepatan deteksi pergantian pertanyaan | Menentukan jawaban masuk ke pertanyaan yang benar, dasar penilaian per kriteria. |
| Waktu tanggap teks muncul di layar (median dan p95) | Menentukan rasa pakai: teks terasa mengikuti pembicaraan atau tersendat. |
| RTF tetap di bawah 1,0 | Syarat mati. Di atas 1,0 sistem tertinggal permanen dan tidak akan pernah mengejar. |
| Tingkat kutipan terverifikasi pada kartu skor | Pagar terhadap karangan LLM. Target: tidak ada satu pun kutipan yang tidak ada di transkrip. |
| Kesesuaian skor sistem dengan skor manusia | Ukuran akhir apakah sistem benar-benar berguna bagi HRD. |
| Tingkat penyuntingan kartu skor oleh HRD | Proksi kepercayaan: makin sedikit disunting, makin dipercaya hasilnya. |
| Data yang hilang saat gangguan | Ketahanan operasional. Target: paling lama beberapa detik terakhir. |

---

## 3. Inti robust design: setelan yang dipilih untuk menahan noise

Identifikasi faktor saja belum menjadi rancangan tangguh. Yang membuatnya tangguh adalah **memilih setelan control factor supaya keluaran tidak ikut goyang ketika noise datang.**

| Noise | Setelan penangkal | Perubahan yang terukur |
|---|---|---|
| Peredam bising browser | Pusat suara hanya dari segmen ≥ 2 detik + pengelompokan ulang di akhir | Label 7/21 → **20/20** |
| CPU terpakai proses lain | Dua jalur inferensi; teks sementara dilewati, bukan diantre | Antrean 32 potongan (6,4 detik) hilang |
| Inferensi melambat | Memilih `small`, menolak `medium` | `medium` lebih akurat tapi RTF 1,20 — tanpa margin |
| Istilah teknis salah | Teks pertanyaan sebagai konteks | WER 0,225 → **0,186** |
| Pewawancara lupa menandai | Deteksi berlapis tiga | 5/5 pertanyaan terdeteksi |
| Browser/laptop mati | Penyimpanan bertahap tiap 2 detik | Kehilangan beberapa detik, bukan seluruhnya |
| Angka salah dikenali | Gaji dibaca dari kolom formulir, bukan transkrip | Kesalahan berbahaya dijauhkan dari jalur keputusan |
| LLM mengarang | Verifikasi kutipan ke transkrip | Kutipan tak terverifikasi dibuang |
| Internet putus | LLM daring dengan cadangan lokal | Kartu skor tetap terbit |

### Contoh paling murni: `medium` ditolak

Setelan dengan **rata-rata terbaik justru ditolak** karena tidak menyisakan margin terhadap noise. `medium` paling akurat (WER 0,128), tetapi RTF 1,20 berarti tidak punya cadangan waktu sama sekali — begitu CPU terganggu sedikit, sistem tertinggal dan tidak akan mengejar. `small` dengan RTF 0,50 menyisakan margin dua kali lipat.

Artinya: **sebagian akurasi rata-rata ditukar demi kestabilan** — persis logika rancangan tangguh, yang menilai sebuah setelan dari sebaran hasilnya, bukan dari nilai terbaiknya.

---

## 4. Batas kejujuran dari data yang ada

Supaya tidak berlebihan saat dipresentasikan:

- Seluruh angka WER di atas berasal dari **naskah yang dibacakan**, termasuk tolok ukur publik FLEURS (WER 0,159). Ketahanan terhadap noise **bicara spontan** belum terukur dan masih menunggu uji dengan dua orang sungguhan.
- Uji pembedaan suara sejauh ini memakai satu suara sintetis sebagai pewawancara. Kasus tersulit — dua orang bersuara mirip di ruangan berisik — belum diuji.
- Noise "ruangan dan mikrofon" belum divariasikan secara sengaja. Percobaan lanjutan yang paling berguna: satu naskah yang sama direkam pada beberapa kombinasi ruangan × mikrofon, lalu **sebaran** WER-nya dibandingkan, bukan sekadar rata-ratanya.
