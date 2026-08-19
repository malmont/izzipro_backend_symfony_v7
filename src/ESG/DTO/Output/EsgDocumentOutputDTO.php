<?php

namespace App\ESG\DTO\Output;

use App\ESG\Entity\EsgDocument;

class EsgDocumentOutputDTO
{
    public int $id;
    public string $code;
    public string $domain;
    public string $originalName;
    public int $fileSize;
    public string $mimeType;
    public string $uploadedAt;    // ISO 8601
    public string $downloadUrl;   // URL de téléchargement sécurisé

    public function __construct(EsgDocument $doc, string $downloadUrl)
    {
        $this->id = $doc->getId();
        $this->code = $doc->getCode() ?? '';
        $this->domain = $doc->getDomain() ? $doc->getDomain()->value : '';
        $this->originalName = $doc->getOriginalName() ?? '';
        $this->fileSize = $doc->getFileSize() ?? 0;
        $this->mimeType = $doc->getMimeType() ?? '';
        $this->uploadedAt = $doc->getUploadedAt() ? $doc->getUploadedAt()->format(\DateTimeInterface::ATOM) : '';
        $this->downloadUrl = $downloadUrl;
    }
}
