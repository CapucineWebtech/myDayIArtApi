<?php

namespace App\Entity;

use App\Repository\DayRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DayRepository::class)]
class Day
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $dayDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column]
    private ?int $nbView = null;

    #[ORM\Column]
    private ?int $nbFinish = null;

    #[ORM\Column]
    private ?int $nbPostInstagram = null;

    #[ORM\OneToMany(targetEntity: Theme::class, mappedBy: 'day', orphanRemoval: true)]
    private Collection $themes;

    public function __construct()
    {
        $this->themes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDayDate(): ?\DateTimeInterface
    {
        return $this->dayDate;
    }

    public function setDayDate(\DateTimeInterface $dayDate): static
    {
        $this->dayDate = $dayDate;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    public function getNbView(): ?int
    {
        return $this->nbView;
    }

    public function setNbView(int $nbView): static
    {
        $this->nbView = $nbView;

        return $this;
    }

    public function getNbFinish(): ?int
    {
        return $this->nbFinish;
    }

    public function setNbFinish(int $nbFinish): static
    {
        $this->nbFinish = $nbFinish;

        return $this;
    }

    public function getNbPostInstagram(): ?int
    {
        return $this->nbPostInstagram;
    }

    public function setNbPostInstagram(int $nbPostInstagram): static
    {
        $this->nbPostInstagram = $nbPostInstagram;

        return $this;
    }

    /**
     * @return Collection<int, Theme>
     */
    public function getThemes(): Collection
    {
        return $this->themes;
    }

    public function addTheme(Theme $theme): static
    {
        if (!$this->themes->contains($theme)) {
            $this->themes->add($theme);
            $theme->setDay($this);
        }

        return $this;
    }

    public function removeTheme(Theme $theme): static
    {
        if ($this->themes->removeElement($theme)) {
            // set the owning side to null (unless already changed)
            if ($theme->getDay() === $this) {
                $theme->setDay(null);
            }
        }

        return $this;
    }
}
