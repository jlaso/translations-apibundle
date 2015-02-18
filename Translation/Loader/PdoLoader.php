<?php

namespace JLaso\TranslationsApiBundle\Translation\Loader;

use JLaso\TranslationsApiBundle\Entity\Translation;
use JLaso\TranslationsApiBundle\Tools\ArrayTools;
use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\Statement;

class PdoLoader implements LoaderInterface, ResourceInterface
{
    protected $con;
    protected $options = array(
        'table' => 'jlaso_translations',
        'columns' => array(
            'key'        => 'key',
            'message'    => 'message',
            'locale'     => 'locale',
            'domain'     => 'domain',
            'updated_at' => 'updated_at',
            'bundle'     => 'bundle',
        ),
        'blank' => 'key',
    );

    protected $freshnessStatement;
    protected $resourcesStatement;
    protected $translationsStatement;

    public function __construct(EntityManager $entityManager, array $options = array())
    {
        $this->con     = $entityManager->getConnection();
        $this->options = array_replace_recursive($this->options, $options);
        if(isset($this->options['fill_blank_messages_with_keyname'])
             && (filter_var($this->options['fill_blank_messages_with_keyname'], FILTER_VALIDATE_BOOLEAN))){
            $this->con->exec("update `jlaso_translations` set `message` = `key` where message = \"\"");
        }
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Loads a locale.
     *
     * @param mixed  $resource A resource
     * @param string $locale   A locale
     * @param string $domain   The domain
     *
     * @throws \RuntimeException
     * @return MessageCatalogue
     */
    public function load($resource, $locale, $domain = Translation::DEFAULT_DOMAIN)
    {
        //echo "domain=", $domain, ", locale=$locale<br>"; die;
        // The loader only accepts itself as a resource.
        if ($resource !== $this) {
            return new MessageCatalogue($locale);
        }

        $stmt = $this->getTranslationsStatement();
        $stmt->bindValue(':locale', $locale, \PDO::PARAM_STR);
        $stmt->bindValue(':domain', $domain, \PDO::PARAM_STR);

        if (false === $stmt->execute()) {
            throw new \RuntimeException('Could not fetch translation data from database.');
        }

        //$stmt->bindColumn('key', $key);
        //$stmt->bindColumn('message', $trans);

        $catalogue = new MessageCatalogue($locale);
        while ($row = $stmt->fetch()) {
            $catalogue->set($row['key'], $row['message'], $domain);
        }

        return $catalogue;
    }

    protected function getTranslationsStatement()
    {
        if ($this->translationsStatement instanceOf \PDOStatement) {
            return $this->translationsStatement;
        }

        $sql = vsprintf('SELECT `%s` AS `key`, `%s` AS `message` FROM `%s` WHERE `%s` = :locale AND `%s` = :domain', array(
                // SELECT ..
                $this->getColumnname('key'),
                $this->getColumnname('message'),
                // FROM ..
                $this->getTablename(),
                // WHERE ..
                $this->getColumnname('locale'),
                $this->getColumnname('domain'),
            ));

        $stmt = $this->getConnection()->prepare($sql);
        $stmt->setFetchMode(\PDO::FETCH_ASSOC);

        $this->translationsStatement = $stmt;

        return $stmt;
    }

    public function getTranslations($locale, $criteria, $hierarchicalArray = true)
    {
        $sql = vsprintf('SELECT `%s` AS `key`, `%s` AS `message` FROM `%s` WHERE `%s` = :locale AND `%s` = :domain', array(
                // SELECT ..
                $this->getColumnname('key'),
                $this->getColumnname('message'),
                // FROM ..
                $this->getTablename(),
                // WHERE ..
                $this->getColumnname('locale'),
                strpos('Bundle', $criteria) !== false ? $this->getColumnname('domain') : $this->getColumnname('bundle'),
            ));

        $stmt = $this->getConnection()->prepare($sql);
        $stmt->bindParam('locale', $locale);
        if(strpos('Bundle', $criteria) !== false){
            $stmt->bindParam('bundle', $criteria);
        }else{
            $stmt->bindParam('domain', $criteria);
        }

        if (false === $stmt->execute()) {
            throw new \RuntimeException('Could not fetch translation data from database.');
        }

        $result = array();
        while ($row = $stmt->fetch()) {
            $result[$row['key']] =  $row['message'];
        }

        if($hierarchicalArray){
            return ArrayTools::keyedAssocToHierarchical($result);
        }
        return $result;
    }

    /**
     * Retrieves all locale-domain combinations and add them as a resource to
     * the translator.
     *
     * @param Translator $translator
     *
     * @throws \RuntimeException
     */
    public function registerResources(Translator $translator)
    {
        $stmt = $this->getResourcesStatement();
        if (false === $stmt->execute()) {
            throw new \RuntimeException('Could not fetch translation data from database.');
        }

        //$stmt->bindColumn('locale', $locale);
        //$stmt->bindColumn('domain', $domain);

        while ($row = $stmt->fetch()) {
            $translator->addResource('pdo', $this, $row['locale'], $row['domain']);
        }
    }

    protected function getResourcesStatement()
    {
        if ($this->resourcesStatement instanceOf \PDOStatement) {
            return $this->resourcesStatement;
        }

        $sql = vsprintf('SELECT DISTINCT `%s` AS `locale`, `%s` AS `domain` FROM `%s`', array(
                // SELECT ..
                $this->getColumnname('locale'),
                $this->getColumnname('domain'),
                // FROM ..
                $this->getTablename(),
            ));

        $stmt = $this->getConnection()->prepare($sql);
        $stmt->setFetchMode(\PDO::FETCH_ASSOC);

        $this->resourcesStatement = $stmt;

        return $stmt;
    }

    public function __toString()
    {
        return 'PDOLoader::'.base64_encode($this->options);
    }

    public function isFresh($timestamp)
    {
        $stmt = $this->getFreshnessStatement($timestamp);

        // If we cannot fetch from database, keep the cache, even if it's not fresh.
        if (false === $stmt->execute()) {
            return true;
        }

        $stmt->bindColumn(1, $count);
        $stmt->fetch();

        return (Boolean) $count;
    }

    protected function getFreshnessStatement($timestamp)
    {
        if ($this->freshnessStatement instanceOf \PDOStatement) {
            return $this->freshnessStatement;
        }

        $sql = vsprintf('SELECT COUNT(*) FROM `%s` WHERE UNIX_TIMESTAMP(`%s`) > :timestamp', array(
                $this->getTablename(),
                $this->getColumnname('updated_at'),
            ));

        $stmt = $this->con->prepare($sql);
        $stmt->bindParam(':timestamp', $timestamp, \PDO::PARAM_INT);

        $this->freshnessStatement = $stmt;

        return $stmt;
    }

    public function getResource()
    {
        return $this;
    }

    public function getConnection()
    {
        return $this->con;
    }

    public function getTablename()
    {
        return $this->options['table'];
    }

    public function getColumnname($column)
    {
        return $this->options['columns'][$column];
    }

}