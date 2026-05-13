<?php

namespace Ephp\MailflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class BaseSendStat
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    protected \DateTimeInterface $openedAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    protected ?\DateTimeInterface $firstOpenedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    protected int $count = 0;

    public function __construct(\DateTimeInterface $openedAt)
    {
        $this->openedAt = $openedAt;
        $this->firstOpenedAt = $openedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOpenedAt(): \DateTimeInterface
    {
        return $this->openedAt;
    }

    public function setOpenedAt(\DateTimeInterface $openedAt): static
    {
        $this->openedAt = $openedAt;
        return $this;
    }

    public function getFirstOpenedAt(): ?\DateTimeInterface
    {
        return $this->firstOpenedAt;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function incrementCount(): static
    {
        ++$this->count;
        $this->openedAt = new \DateTime();
        return $this;
    }
}
