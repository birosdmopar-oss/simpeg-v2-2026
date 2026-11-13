<?php

declare(strict_types=1);

namespace App\Controllers\Api\HaloSimpeg\Chatbot;

use App\Libraries\HaloSimpeg\Chatbot\ImporQna;
use App\Libraries\HaloSimpeg\Chatbot\QnaService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Admin QnA chatbot (role 1, F8-32): CRUD, status, opsi form, template + impor Excel. Aturan di {@see QnaService} dan
 * {@see ImporQna}.
 */
final class QnaController extends ChatbotController
{
    public function index(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess((new QnaService())->daftar($this->query())));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess((new QnaService())->simpan(null, $this->payload()), 201);
    }

    public function show(int|string $id): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess((new QnaService())->detail($this->idValid($id))));
    }

    public function update(int|string $id): ResponseInterface
    {
        return $this->respondSuccess((new QnaService())->simpan($this->idValid($id), $this->payload()));
    }

    public function status(int|string $id): ResponseInterface
    {
        return $this->respondSuccess((new QnaService())->setStatus($this->idValid($id), $this->payload()['status'] ?? null));
    }

    public function delete(int|string $id): ResponseInterface
    {
        (new QnaService())->hapus($this->idValid($id));

        return $this->respondSuccess(null);
    }

    public function opsi(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess((new QnaService())->opsi()));
    }

    public function template(): ResponseInterface
    {
        return $this->berkasXlsx('Template_Import_QnA.xlsx', ImporQna::template());
    }

    public function impor(): ResponseInterface
    {
        $berkas = $this->request->getFile('berkas');

        return $this->respondSuccess((new ImporQna())->impor($berkas instanceof UploadedFile ? $berkas : null));
    }
}
