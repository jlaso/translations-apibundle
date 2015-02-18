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

    public function getBundles()
    {
        $em = $this->getEntityManager();

        $queryBuilder = $em->createQueryBuilder();
        $queryBuilder->select('DISTINCT t.bundle')
            ->from('TranslationsApiBundle:Translation', 't')
        ;

        /** @var Translation[] $result */
        $result = $queryBuilder->getQuery()->getResult();

        $bundles = array();
        foreach($result as $item){
            $bundles[$item['bundle']] = null;
        }

        return array_keys($bundles);
    }

    /**
     * @param $bundle
     *
     * @return Translation[]
     */
    public function getKeysByBundle($bundle)
    {
        $em = $this->getEntityManager();

        $queryBuilder = $em->createQueryBuilder();
        $queryBuilder->select('t')
            ->from('TranslationsApiBundle:Translation', 't')
            ->where('t.bundle = :bundle')
            ->setParameter('bundle', $bundle)
        ;

        return $queryBuilder->getQuery()->getResult();
    }

    public function truncateTranslations()
    {
        $em = $this->getEntityManager();
        $cmd = $em->getClassMetadata('TranslationsApiBundle:Translation');
        $connection = $em->getConnection();
        $dbPlatform = $connection->getDatabasePlatform();
        $connection->beginTransaction();

        try {
            $connection->query('SET FOREIGN_KEY_CHECKS=0');
            $connection->query('TRUNCATE '.$cmd->getTableName());
            //$connection->query('DELETE FROM '.$cmd->getTableName());
            // Beware of ALTER TABLE here--it's another DDL statement and will cause
            // an implicit commit.
            $connection->query('SET FOREIGN_KEY_CHECKS=1');
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollback();
        }
    }

}
