<?php

namespace JLaso\TranslationsApiBundle\Entity\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use JLaso\TranslationsApiBundle\Entity\Translation;

class TranslationRepository extends EntityRepository
{

    public function getCatalogs()
    {
        $em = $this->getEntityManager();

        $queryBuilder = $em->createQueryBuilder();
        $queryBuilder->select('DISTINCT t.domain AS catalog')
            ->from('TranslationsApiBundle:Translation', 't')
        ;

        /** @var Translation[] $result */
        $result = $queryBuilder->getQuery()->getResult();

        $catalogs = array();
        foreach($result as $item){
            $catalogs[$item['catalog']] = null;
        }

        return array_keys($catalogs);
    }

}
