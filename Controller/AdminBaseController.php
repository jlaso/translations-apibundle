<?php

namespace JLaso\TranslationsApiBundle\Controller;

use JLaso\TranslationsApiBundle\Entity\Repository\TranslationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Bundle\FrameworkBundle\Translation\Translator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\Common\Persistence\ObjectManager;

class AdminBaseController extends BaseController
{
    /** @var  ObjectManager */
    protected $om;

    protected function init()
    {
        $this->om = $this->getDoctrine()->getManager();
    }

    /**
     * @return TranslationRepository
     */
    protected function getTranslationRepository()
    {
        return $this->om->getRepository('TranslationsApiBundle:Translation');
    }


}