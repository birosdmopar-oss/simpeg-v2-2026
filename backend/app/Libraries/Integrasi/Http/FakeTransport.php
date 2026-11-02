<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Http;

use RuntimeException;

/**
 * Transport tiruan untuk test (BP-11): mengembalikan antrean {@see Respons}/{@see GalatTransport} sesuai urutan dan
 * mencatat setiap permintaan + opsi. Tidak pernah membuka koneksi. Fixture JSON sintetis di `tests/_support/Integrasi/`.
 */
final class FakeTransport implements TransportHttpInterface
{
    /** @var list<GalatTransport|Respons> */
    private array $antrean = [];

    /** @var list<array{permintaan: Permintaan, opsi: OpsiTransport}> */
    private array $tercatat = [];

    public function antre(GalatTransport|Respons ...$hasil): self
    {
        foreach ($hasil as $h) {
            $this->antrean[] = $h;
        }

        return $this;
    }

    public function antreJson(int $status, mixed $data): self
    {
        return $this->antre(new Respons($status, (string) json_encode($data), ['content-type' => 'application/json']));
    }

    public function antreBerkas(int $status, string $pathFixture): self
    {
        $isi = file_get_contents($pathFixture);

        if ($isi === false) {
            throw new RuntimeException('FakeTransport: fixture tidak terbaca: ' . basename($pathFixture));
        }

        return $this->antre(new Respons($status, $isi, ['content-type' => str_ends_with($pathFixture, '.json') ? 'application/json' : 'application/octet-stream']));
    }

    public function kirim(Permintaan $permintaan, OpsiTransport $opsi): Respons
    {
        $this->tercatat[] = ['permintaan' => $permintaan, 'opsi' => $opsi];
        $hasil            = array_shift($this->antrean);

        if ($hasil === null) {
            throw new RuntimeException("FakeTransport: antrean habis untuk {$permintaan->layanan}/{$permintaan->operasi}.");
        }

        if ($hasil instanceof GalatTransport) {
            throw $hasil;
        }

        return $hasil;
    }

    /**
     * @return list<array{permintaan: Permintaan, opsi: OpsiTransport}>
     */
    public function tercatat(): array
    {
        return $this->tercatat;
    }

    public function sisaAntrean(): int
    {
        return count($this->antrean);
    }
}
