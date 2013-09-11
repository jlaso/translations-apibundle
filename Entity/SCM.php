<?php
namespace JLaso\TranslationsApiBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass="JLaso\TranslationsApiBundle\Entity\Repository\SCMRepository")
 * @ORM\Table(name="translations_scm")
 * @ORM\HasLifecycleCallbacks
 */
class SCM
{
    /**
     * @var integer $id
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var string $bundle
     *
     * @ORM\Column(name="bundle", type="string", length=100)
     */
    protected $bundle;

    /**
     * @var string $file
     *
     * @ORM\Column(name="file", type="string", length=100)
     */
    protected $file;

    /**
     * @var string $locale
     *
     * @ORM\Column(name="locale", type="string", length=8)
     */
    protected $locale;

    /**
     * @var string $fullpath
     *
     * @ORM\Column(name="fullpath", type="string", length=255)
     */
    protected $fullpath;

    /**
     * @var string $key
     *
     * @ORM\Column(name="`key`", type="string", length=255)
     */
    protected $key;

    /**
     * @var string $content
     *
     * @ORM\Column(name="content", type="text", nullable=true)
     */
    protected $content;

    /**
     * @var \DateTime $createdAt
     *
     * @ORM\Column(name="last_modification", type="datetime")
     * @Assert\NotNull()
     * @Assert\DateTime()
     */
    protected $lastModification;

    public function __construct()
    {
        $this->lastModification = new \DateTime();
    }

    public function __toString()
    {
        return 'SCM  #' . $this->id;
    }

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
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

    /**
     * @param string $content
     */
    public function setContent($content)
    {
        $this->content = $content;
    }

    /**
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * @param string $key
     */
    public function setKey($key)
    {
        $this->key = $key;
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param \DateTime $lastModification
     */
    public function setLastModification($lastModification = null)
    {
        $this->lastModification = $lastModification ? : new \DateTime();
    }

    /**
     * @return \DateTime
     */
    public function getLastModification()
    {
        return $this->lastModification;
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
     * @param string $fullpath
     */
    public function setFullpath($fullpath)
    {
        $this->fullpath = $fullpath;
    }

    /**
     * @return string
     */
    public function getFullpath()
    {
        return $this->fullpath;
    }

    /**
     * @param string $locale
     */
    public function setLocale($locale)
    {
        $this->locale = $locale;
    }

    /**
     * @return string
     */
    public function getLocale()
    {
        return $this->locale;
    }



}
