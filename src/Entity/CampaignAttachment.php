<?php

namespace App\Entity;

use App\Repository\CampaignAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Oi\FileBundle\Entity\Upload;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'campaign_attachment')]
#[ORM\Index(columns: ['campaign_id'], name: 'idx_campaign_attachment_campaign_id')]
#[ORM\Entity(repositoryClass: CampaignAttachmentRepository::class)]
class CampaignAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Campaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: Upload::class)]
    #[ORM\JoinColumn(name: 'upload_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Upload $upload = null;

    #[ORM\Column(length: 255)]
    private string $filename = '';

    #[ORM\Column(type: 'integer')]
    private int $size = 0;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimetype = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    #[Ignore]
    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    public function setCampaign(?Campaign $campaign): static
    {
        $this->campaign = $campaign;
        return $this;
    }

    #[Ignore]
    public function getUpload(): ?Upload
    {
        return $this->upload;
    }

    public function setUpload(?Upload $upload): static
    {
        $this->upload = $upload;
        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;
        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;
        return $this;
    }

    public function getMimetype(): ?string
    {
        return $this->mimetype;
    }

    public function setMimetype(?string $mimetype): static
    {
        $this->mimetype = $mimetype;
        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }
}
