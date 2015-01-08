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

    protected function init()
    {
        parent::init();
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
     * @Route("/catalogs/list", name="jlaso_translationsapi_admin_catalogs")
     * @Secure(roles="ROLE_ADMIN")
     * @Template()
     */
    public function catalogsIndexAction(Request $request)
    {
        $entities = $this->getCategoriesRepository()->findAll();

        $paginator = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $entities,
            $request->query->get('page', 1)/*page number*/,
            $this->container->getParameter('jlaso_blog.max_per_page')/*limit per page*/
        );

        return array(
            'section' => 'admin.categories',
            'pagination' => $pagination,
        );
    }

    /**
     * @Route("/category/edit/{categoryId}", name="jlaso_blog_admin_category_edit")
     * @Secure(roles="ROLE_ADMIN")
     * @Template("JLasoBlogBundle:Admin/Category:edit.html.twig")
     * @ParamConverter("category", class="JLasoBlogBundle:Category", options={"id" = "categoryId"})
     */
    public function categoryEditAction(Request $request, Category $category)
    {
        $form = $this->createForm(new CategoryType(), $category);

        if($request->isMethod('POST')){
            $form->handleRequest($request);

            if ($form->isValid()) {

                $this->om->persist($category);
                $this->om->flush();
                $this->addSuccessFlash('category.saved');

                return $this->redirect($this->generateUrl('jlaso_blog_admin_categories', array('id' => $category->getId())));
            }
            $this->addNoticeFlash('form_submit_error');
        }

        return array(
            'section'  => 'admin.categories',
            'form'     => $form->createView(),
            'category' => $category,
        );
    }

    /**
     * @Route("/category/new", name="jlaso_blog_admin_categories_new")
     * @Secure(roles="ROLE_ADMIN")
     * @Template("JLasoBlogBundle:Admin/Category:create.html.twig")
     */
    public function createAction(Request $request)
    {
        $category = new Category();
        $form = $this->createForm(new CategoryType(), $category);
        if($request->isMethod('POST')){
            $form->handleRequest($request);

            if ($form->isValid()) {

                $this->om->persist($category);
                $this->om->flush();
                $this->addSuccessFlash('category.created');

                return $this->redirect($this->generateUrl('jlaso_blog_admin_categories', array('id' => $category->getId())));
            }
            $this->addNoticeFlash('form_submit_error');
        }

        //ldd($category, $form->createView());
        return array(
            'section'  => 'admin.categories',
            'category' => $category,
            'form'     => $form->createView(),
        );
    }



}
