<?php

namespace JLaso\TranslationsApiBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use JLaso\TranslationsApiBundle\Entity\Repository\TranslationRepository;

/**
 * @ORM\Entity(repositoryClass="JLaso\TranslationsApiBundle\Entity\Repository\TranslationRepository")
 * @ORM\Table(name="jlaso_translations")
 * @UniqueEntity(fields="domain,locale,key")
 */
class Translation
{

    const DEFAULT_DOMAIN = "messages";

    /**
     * @ORM\Id
     * @ORM\Column(name="id", type="integer")
     * @ORM\GeneratedValue
     */
    private $id;

    /**
     * @ORM\column(name="domain", type="string", length=50)
     */
    private $domain;

    /**
     * @ORM\column(name="locale", type="string", length=10)
     */
    private $locale;

    /**
     * @ORM\column(name="`key`", type="string", length=255)
     */
    private $key;

    /**
     * @ORM\column(name="message", type="text", nullable=true)
     */
    private $message;

    /**
     * @var string $bundle
     *
     * @ORM\Column(name="bundle", type="string", length=100)
     */
    protected $bundle;

    /**
     * @var string $file
     *
     * @ORM\Column(name="file", type="string", length=255)
     */
    protected $file;

    /**
     * @var \DateTime $createdAt
     *
     * @ORM\Column(name="created_at", type="datetime")
     * @Assert\NotNull()
     * @Assert\DateTime()
     */
    private $createdAt;

    /**
     * @var \DateTime $updatedAt
     *
     * @ORM\Column(name="updated_at", type="datetime")
     * @Assert\NotNull()
     * @Assert\DateTime()
     */
    private $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
        $this->createdAt = new \DateTime();
        $this->domain    = self::DEFAULT_DOMAIN;
    }

    public function getId() {
        return $this->id;
    }

    public function setId($id) {
        $this->id = $id;
    }

    public function getLocale() {
        return $this->locale;
    }

    public function setLocale($locale) {
        $this->locale = $locale;
    }

    /**
     * @param \DateTime $createdAt
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;
    }

    /**
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * @param mixed $domain
     */
    public function setDomain($domain)
    {
        $this->domain = $domain;
    }

    /**
     * @return mixed
     */
    public function getDomain()
    {
        return $this->domain;
    }

    /**
     * @param mixed $key
     */
    public function setKey($key)
    {
        $this->key = $key;
    }

    /**
     * @return mixed
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param mixed $message
     */
    public function setMessage($message)
    {
        $this->message = $message;
    }

    /**
     * @return mixed
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @param \DateTime $updatedAt
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * @return \DateTime
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * @param string $bundle
     */
    public function setBundle($bundle)
    {
        $this->bundle = $bundle;
    }

    /**
     * @return string
     */
    public function getBundle()
    {
        return $this->bundle;
    }

    /**
     * @param string $file
     */
    public function setFile($file)
    {
        $this->file = $file;
    }

    /**
     * @return string
     */
    public function getFile()
    {
        return $this->file;
    }


}