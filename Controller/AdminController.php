<?php

namespace JLaso\TranslationsApiBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use JLaso\TranslationsApiBundle\Entity\Translation;
use JLaso\TranslationsApiBundle\Entity\Repository\TranslationRepository;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\Common\Persistence\ObjectManager;
use JMS\SecurityExtraBundle\Annotation\Secure;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;


/**
 * @Route("/translations/admin")
 */
class AdminController extends AdminBaseController
{

    protected $maxPerPage = 10;
    protected $currentPage = 1;
    protected $locale = 'en';

    protected function init()
    {
        parent::init();

        try{
            $this->maxPerPage = $this->container->getParameter('jlaso_translationsapi.max_per_page');
        }catch(\Exception $e){
            $this->maxPerPage = 10;
        }
        $request = $this->get('request');
        $this->currentPage = $request->query->get('page', 1);
    }

    /**
     * @Route("/", name="jlaso_translationsapi_admin_home")
     * @Secure(roles="ROLE_ADMIN")
     * @Template()
     */
    public function indexAction(Request $request)
    {
        return array(
            'section' => 'translationsapi.admin.home',
        );
    }

    /**
     * CATALOGS
     */

    /**
     * @Route("/catalog/list", name="jlaso_translationsapi_admin_catalogs")
     * @Secure(roles="ROLE_ADMIN")
     * @Template()
     */
    public function catalogIndexAction(Request $request)
    {
        $entities   = $this->getTranslationRepository()->getCatalogs();
        $paginator  = $this->get('knp_paginator');
        $pagination = $paginator->paginate($entities, $this->currentPage, $this->maxPerPage);

        return array(
            'section' => 'admin.categories',
            'pagination' => $pagination,
        );
    }

    /**
     * TRANSLATIONS
     */

    /**
     * @Route("/list", name="jlaso_translationsapi_admin_translations")
     * @Secure(roles="ROLE_ADMIN")
     * @Template()
     */
    public function translationIndexAction(Request $request)
    {
        $entities = $this->getCategoriesRepository()->findAll();

        $paginator = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $entities,
            $request->query->get('page', 1)/*page number*/,
            $this->container->getParameter('jlaso_blog.max_per_page')/*limit per page*/
        );

        return array(
            'section' => 'admin.translations',
            'pagination' => $pagination,
        );
    }

    /**
     * @Route("/catalog/{catalog}/list", name="jlaso_translationsapi_admin_catalog_list")
     * @Secure(roles="ROLE_ADMIN")
     * @Template()
     */
    public function translationCatalogIndexAction(Request $request, $catalog)
    {
        $entities   = $this->getTranslationRepository()->getKeysByCatalogAndLocale($catalog, $this->locale);
        $paginator  = $this->get('knp_paginator');
        $pagination = $paginator->paginate($entities, $this->currentPage, $this->maxPerPage);

        return array(
            'section' => 'admin.translations_by_catalog',
            'pagination' => $pagination,
        );
    }

    /**
     * @Route("/translation/{id}/edit", name="jlaso_translationsapi_admin_key_edit")
     * @Secure(roles="ROLE_ADMIN")
     * @Template()
     */
    public function translationEditIndexAction(Request $request, $id)
    {
        /** @var Translation $entitiy */
        $entitiy = $this->getTranslationRepository()->find($id);

        if(!$entitiy){
            return $this->createNotFoundException();
        }
        $entities = $this->getTranslationRepository()->getKey($entitiy->getKey());

        return array(
            'section'  => 'admin.key_edit',
            'entities' => $entities,
        );
    }

}
