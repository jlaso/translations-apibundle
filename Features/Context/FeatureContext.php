<?php

namespace JLaso\TranslationsApiBundle\Features\Context;

use JLaso\TranslationsBundle\Entity\Repository\KeyRepository;
use JLaso\TranslationsBundle\Entity\Repository\MessageRepository;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Behat\Behat\Context\ClosuredContextInterface,
    Behat\Behat\Context\TranslatedContextInterface,
    Behat\Behat\Context\BehatContext,
    Behat\Behat\Exception\PendingException;
use Behat\Behat\Hook\Annotation\BeforeScenario;
use Behat\Gherkin\Node\PyStringNode,
    Behat\Gherkin\Node\TableNode;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Symfony2Extension\Context\KernelAwareInterface;
use JLaso\TranslationsBundle\Entity\Project;
use JLaso\TranslationsBundle\Entity\Key;
use JLaso\TranslationsBundle\Entity\Message;
use Doctrine\ORM\EntityManager;
use JLaso\TranslationsApiBundle\Service\ClientApiService;

require_once 'PHPUnit/Autoload.php';
require_once 'PHPUnit/Framework/Assert/Functions.php';

/**
 * Features context.
 */
class FeatureContext extends MinkContext
                  implements KernelAwareInterface
{
    /** @var KernelInterface */
    private $kernel = null;
    private $parameters;
    private $translationsApiConfig = null;
    /** @var  EntityManager */
    private $em = null;
    /** @var \Exception  */
    private $lastException = null;
    private $exceptionExpected = false;
    /** @var  Project */
    private $project;
    /** @var ClientApiService */
    private $translationsApi;
    private $data;
    /** @var Array */
    private $bundles;
    /** @var Array */
    private $keys;
    private $project_id;

    /**
     * Initializes context.
     * Every scenario gets it's own context object.
     *
     * @param array $parameters context parameters (set them up through behat.yml)
     */
    public function __construct(array $parameters)
    {
        $this->parameters = $parameters;
        $this->bundles  = array();
        $this->keys     = array();
    }

    /**
     * @BeforeScenario
     */
    public function init($event)
    {
        $this->translationsApi = $this->kernel->getContainer()->get('jlaso_translations.client.api');
        $apiConfig             = $this->kernel->getContainer()->getParameter('jlaso_translations_api_access');
        $this->project_id      = 1; // cuando se crea de cero siempre es el uno - $apiConfig['project_id'];
        $this->em              = $this->kernel->getContainer()->get('doctrine.orm.default_entity_manager');
    }

    /**
     * Sets HttpKernel instance.
     * This method will be automatically called by Symfony2Extension ContextInitializer.
     *
     * @param KernelInterface $kernel
     */
    public function setKernel(KernelInterface $kernel)
    {
        //ld('set kernel');
        $this->kernel = $kernel;
    }

    protected function initializeDatabase()
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $options = array('command' => 'doctrine:schema:drop', '--force' => 'yes');
        $input = new ArrayInput($options);
        $input->setInteractive(false);
        $application->run($input, new NullOutput());

        $options = array('command' => 'doctrine:schema:create');
        $input = new ArrayInput($options);
        $input->setInteractive(false);
        $application->run($input, new NullOutput());
    }

    /**
     * @Given /^Database is clear$/
     */
    public function databaseIsClear()
    {
        $this->initializeDatabase();

        $project = new Project();
        $project->setProject('project ' . $this->project_id);
        $project->setName('project ' . $this->project_id);
        $this->em->persist($project);
        $this->project = $project;
    }

//    /**
//     * @Given /^The next projects are present in database:$/
//     */
//    public function theNextProjectsArePresentInDatabase(TableNode $table)
//    {
//        $rows = $table->getHash();
//        foreach($rows as $row){
//            $project = new Project();
//            $project->setProject($row['project']);
//            $project->setName($row['project']);
//            $this->em->persist($project);
//            $this->projects[$row['project']] = $project;
//        }
//        $this->em->flush();
//    }

    /**
     * @Given /^The next keys are present in database:$/
     */
    public function theNextKeysArePresentInDatabase(TableNode $table)
    {
        $rows = $table->getHash();
        foreach($rows as $row){
            $key = new Key();
            $key->setComment($row['comment']);
            $key->setKey($row['key']);
            $key->setProject($this->project);
            $key->setBundle($row['bundle']);
            $this->em->persist($key);
            $this->bundles[$row['bundle']] = false;
            $this->keys[$row['bundle'] . ':' . $row['key']] = $key;
        }
        $this->em->flush();
    }

    /**
     * @When /^get bundle index$/
     */
    public function getBundleIndex()
    {
        $result = $this->translationsApi->getBundleIndex($this->project_id);
        if($result['result']){
            $bundles = $result['bundles'];
            $this->data = serialize($bundles);
        }else{
            throw new \Exception('Error '. $result['reason']);
        }
    }

    /**
     * @Then /^there are these bundles:$/
     */
    public function thereAreTheseBundles(TableNode $table)
    {
        $bundlesReaded = unserialize($this->data);
        $bundles = array();
        foreach($table->getHash() as $row){
            $bundles[$row['bundle']] = false;
        }
        foreach($bundlesReaded as $bundle=>$n){
            if(!isset($bundles[$bundle])){
                throw new \Exception(sprintf('bundle %s readed is not present in DB', $bundle));
            }else{
                $bundles[$bundle] = true;
            }
        }
        foreach($bundles as $bundle=>$readed){
            if(!$readed){
                throw new \Exception(sprintf('bundle %s in DB but is not readed', $bundle));
            }
        }
    }

    /**
     * @When /^get key index for bundle "([^"]*)"$/
     */
    public function getKeyIndex($bundle)
    {
        $result = $this->translationsApi->getKeyIndex($bundle, $this->project_id);
        if($result['result']){
            $keys = $result['keys'];
            $this->data = serialize($keys);
        }else{
            throw new \Exception('Error '. $result['reason']);
        }
    }

    /**
     * @Then /^there are these keys:$/
     */
    public function thereAreTheseKeys(TableNode $table)
    {
        $keysReaded = unserialize($this->data);
        $keys = array();
        foreach($table->getHash() as $row){
            $keys[$row['key']] = false;
        }
        foreach($keysReaded as $key){
            if(!isset($keys[$key])){
                throw new \Exception(sprintf('key %s readed is not present in DB', $key));
            }else{
                $keys[$key] = true;
            }
        }
        foreach($keys as $key=>$readed){
            if(!$readed){
                throw new \Exception(sprintf('key %s in DB but is not readed', $key));
            }
        }
    }

    /**
     * @Given /^The next messages are present in database:$/
     */
    public function theNextMessagesArePresentInDatabase(TableNode $table)
    {
        $rows = $table->getHash();
        foreach($rows as $row){
            $message = new Message();
            $message->setLanguage($row['language']);
            $message->setKey($this->keys[$row['bundle'] . ':' . $row['key']]);
            $message->setMessage($row['message']);
            $this->em->persist($message);
        }
        $this->em->flush();
    }

    /**
     * @When /^get messages for a key "([^:]*):([^"]*)"$/
     */
    public function getMessagesForAKey($bundle, $key)
    {
        $result = $this->translationsApi->getMessages($bundle, $key, $this->project_id);
        if($result['result']){
            $this->data = serialize($result['messages']);
        }else{
            throw new \Exception('Error '. $result['reason']);
        }
    }

    /**
     * @When /^put message for a key "([^:]*):([^\/]*)\/([^"]*)" as "([^"]*)"$/
     */
    public function putMessageForAKeyAs($bundle, $key, $language, $message)
    {
        $result = $this->translationsApi->putMessage($bundle, $key, $language, $message, $this->project_id);
        if(!$result['result']){
            throw new \Exception('Error '. $result['reason']);
        }
    }

    /**
     * @When /^update message for a key "([^:]*):([^\/]*)\/([^"]*)" as "([^"]*)","(newest|oldest)"$/
     */
    public function updateMessageForAKeyAsAnotherTestNewest($bundle, $key, $language, $message, $newest)
    {
        $currentMessage   = $this->getMessage($bundle, $key, $language);
        $currentDate      = clone $currentMessage->getUpdatedAt();
        $lastModification = ($newest == 'newest') ? $currentDate->modify('+1 day') : $currentDate->modify('-1 day');
        $result = $this->translationsApi->updateMessageIfNewest($bundle, $key, $language, $message, $lastModification, $this->project_id);
        if (! $result['result']) {
            throw new \Exception('Error ' . $result['reason']);
        }
    }

    /**
     * @Then /^there are these messages:$/
     */
    public function thereAreTheseMessages(TableNode $table)
    {
        $messagesReaded = unserialize($this->data);
        $messages = array();
        foreach($table->getHash() as $row){
            $messages[$row['language']] = array(
                'message'           => $row['message'],
                'is'                => false,
            );
        }
        foreach($messagesReaded as $language=>$data){
            if(!isset($messages[$language])){
                throw new \Exception(sprintf('message %s readed is not present in DB', $language));
            }else{
                if($data['message'] != $messages[$language]['message']) {
                    ld($data, $messages[$language]);
                    throw new \Exception(sprintf('message %s readed present but diferent', $language));
                }
                $messages[$language]['is'] = true;
            }
        }
        foreach($messages as $language=>$data){
            if(!$data['is']){
                throw new \Exception(sprintf('message %s in DB but is not readed', $language));
            }
        }
    }

    /**
     * @param string  $bundle
     * @param string  $key
     * @param string  $language
     *
     * @return Message
     */
    protected function getMessage($bundle, $key, $language)
    {
        $keyRecord = $this->getKeyRepository()->findOneBy(array(
                'project'  => $this->project,
                'bundle'   => $bundle,
                'key'      => $key,
            )
        );

        if(!$keyRecord){
            return null;
        }
        /** @var Message $message */
        $message = $this->getMessageRepository()->findOneBy(array(
                'key'      => $keyRecord,
                'language' => $language,
            )
        );
        return $message;
    }

    /**
     * @return MessageRepository
     */
    private function getMessageRepository()
    {
        return $this->em->getRepository('TranslationsBundle:Message');
    }

    /**
     * @return KeyRepository
     */
    private function getKeyRepository()
    {
        return $this->em->getRepository('TranslationsBundle:Key');
    }

}
