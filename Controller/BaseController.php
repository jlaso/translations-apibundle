<?php

namespace JLaso\TranslationsApiBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Bundle\FrameworkBundle\Translation\Translator;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BaseController extends Controller
{
    const NOPARAMS = null;

    /** @var  Translator */
    protected $translator;

    public function setContainer(ContainerInterface $container = null)
    {
        parent::setContainer($container);

        $this->translator = $container->get('translator');

        if(method_exists($this, 'init')){
            $this->init();
        }
    }

    public function throwError($message, $params = null, $toAction = null, $paramsToAction = array())
    {
        if(null == $params){
            $params = array();
        }
        $message = $this->translator->trans($message, $params);
        $this->get('session')->getFlashBag()->add('error', $message);

        if($toAction){
            return $this->redirect($this->generateUrl($toAction, $paramsToAction));
        }else{
            $currentUrl = $this->getRequest()->getUri();
            return $this->redirect($currentUrl);
        }

    }

    public function addNoticeFlash($message, $params = array())
    {
        $message = $this->translator->trans($message, $params);
        $this->get('session')->getFlashBag()->add('notice', $message);
    }

    public function addSuccessFlash($message, $params = array())
    {
        $message = $this->translator->trans($message, $params);
        $this->get('session')->getFlashBag()->add('success', $message);
    }

    public function trans($msg)
    {
        return $this->translator->trans($msg);
    }

}