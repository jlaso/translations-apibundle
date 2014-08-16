<?php

namespace JLaso\TranslationsApiBundle\Model;


class CandidateKey
{

    protected $bundle;
    protected $key;
    protected $file;

    function __construct($bundle, $file, $key)
    {
        $this->bundle = $bundle;
        $this->file   = $file;
        $this->key    = $key;
    }


    /**
     * @param mixed $bundle
     */
    public function setBundle($bundle)
    {
        $this->bundle = $bundle;
    }

    /**
     * @return mixed
     */
    public function getBundle()
    {
        return $this->bundle;
    }

    /**
     * @param mixed $file
     */
    public function setFile($file)
    {
        $this->file = $file;
    }

    /**
     * @return mixed
     */
    public function getFile()
    {
        return $this->file;
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



}