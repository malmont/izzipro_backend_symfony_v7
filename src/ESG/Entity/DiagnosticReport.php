<?php

namespace App\ESG\Entity;

use App\ESG\Enum\ReportStatusEnum;
use App\ESG\Repository\DiagnosticReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiagnosticReportRepository::class)]
#[ORM\Table(name: 'esg_diag_report')]
#[ORM\Index(columns: ['status'], name: 'idx_report_status')]
class DiagnosticReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50, enumType: ReportStatusEnum::class)]
    private ?ReportStatusEnum $status = ReportStatusEnum::PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $generatedAt = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $requestedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'integer')]
    private int $downloadCount = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastDownloadAt = null;

    #[ORM\OneToOne(inversedBy: 'report', targetEntity: DiagnosticSession::class)]
    #[ORM\JoinColumn(name: 'session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?DiagnosticSession $session = null;

    public function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable();
        $this->status = ReportStatusEnum::PENDING;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): ?ReportStatusEnum
    {
        return $this->status;
    }

    public function setStatus(ReportStatusEnum $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): self
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getGeneratedAt(): ?\DateTimeInterface
    {
        return $this->generatedAt;
    }

    public function setGeneratedAt(?\DateTimeInterface $generatedAt): self
    {
        $this->generatedAt = $generatedAt;
        return $this;
    }

    public function getRequestedAt(): ?\DateTimeInterface
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(\DateTimeInterface $requestedAt): self
    {
        $this->requestedAt = $requestedAt;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getDownloadCount(): int
    {
        return $this->downloadCount;
    }

    public function setDownloadCount(int $downloadCount): self
    {
        $this->downloadCount = $downloadCount;
        return $this;
    }

    public function incrementDownloadCount(): self
    {
        $this->downloadCount++;
        $this->lastDownloadAt = new \DateTimeImmutable();
        return $this;
    }

    public function getLastDownloadAt(): ?\DateTimeInterface
    {
        return $this->lastDownloadAt;
    }

    public function setLastDownloadAt(?\DateTimeInterface $lastDownloadAt): self
    {
        $this->lastDownloadAt = $lastDownloadAt;
        return $this;
    }

    public function getSession(): ?DiagnosticSession
    {
        return $this->session;
    }

    public function setSession(?DiagnosticSession $session): self
    {
        $this->session = $session;
        return $this;
    }
}
