<?php

namespace JLaso\TranslationsApiBundle\Command;

use Doctrine\ORM\EntityManager;
use JLaso\TranslationsApiBundle\Entity\Repository\SCMRepository;
use JLaso\TranslationsApiBundle\Entity\Repository\TranslationRepository;
use JLaso\TranslationsApiBundle\Entity\SCM;
use JLaso\TranslationsApiBundle\Entity\Translation;
use JLaso\TranslationsApiBundle\Service\ClientApiService;
use JLaso\TranslationsApiBundle\Service\ClientSocketService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Bundle\FrameworkBundle\Translation\Translator;


/**
 * Sync translations files - translations server.
 *
 * @author Joseluis Laso <jlaso@joseluislaso.es>
 */
class TranslationsSyncMongoCommand extends ContainerAwareCommand
{
    /** @var InputInterface */
    private $input;
    /** @var OutputInterface */
    private $output;
    /** @var  EntityManager */
    private $em;
    /** @var ClientSocketService */
    private $clientApiService;
    /** @var  TranslationRepository */
    private $translationsRepository;

    private $rootDir;

    const THROWS_EXCEPTION = true;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('jlaso:translations:sync-mongo');
        $this->setDescription('Sync all translations from translations server.');
        $this->addOption('port', null, InputArgument::OPTIONAL, 'port');
        $this->addOption('address', null, InputArgument::OPTIONAL, 'address');
        $this->addOption('force', null, InputArgument::OPTIONAL, 'force=yes to upload our local DB to remote');
    }

    protected function init($server = null, $port = null)
    {
        /** @var EntityManager */
        $this->em         = $this->getContainer()->get('doctrine.orm.default_entity_manager');
        /** @var ClientSocketService $clientApiService */
        $clientApiService = $this->getContainer()->get('jlaso_translations.client.socket');
        $this->clientApiService = $clientApiService;
        $this->translationsRepository = $this->em->getRepository('TranslationsApiBundle:Translation');
        $this->clientApiService->init($server, $port);
        $this->rootDir = $this->getContainer()->get('kernel')->getRootDir();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input    = $input;
        $this->output   = $output;

        $this->init($input->getOption('address'), $input->getOption('port'));

        $config         = $this->getContainer()->getParameter('translations_api');
        $managedLocales = $config['managed_locales'];

        $this->output->writeln(PHP_EOL . '<info>*** Syncing translations ***</info>');

        if($input->getOption('force') == 'yes'){

            $catalogs = $this->translationsRepository->getCatalogs();

            foreach($catalogs as $catalog){

                // data para enviar al servidor
                $data = array();

                $this->output->writeln(PHP_EOL . sprintf('<info>Processing catalog %s ...</info>', $catalog));

                /** @var Translation[] $messages */
                $messages = $this->translationsRepository->findBy(array('domain' => $catalog));

                foreach($messages as $message){

                    $key      = $message->getKey();
                    $locale   = $message->getLocale();
                    $bundle   = $message->getBundle();
                    $fileName = $message->getFile();

                    $data[$key][$locale] = array(
                        'message'   => $message->getMessage(),
                        'updatedAt' => $message->getUpdatedAt()->format('c'),
                    );

                }

                //print_r($data); die;
                $this->output->writeln('uploadKeys("' . $catalog . '", $data)');

                $result = $this->clientApiService->uploadKeys($catalog, $data, $bundle, $fileName);
            }
        }else{

            /** @var DialogHelper $dialog */
            $dialog = $this->getHelper('dialog');
            if (!$dialog->askConfirmation(
                $output,
                '<question>The local DB will be erased, it is ok ?</question>',
                false
            )) {
                die('Please, repeat the command with --force==yes in order to update remote DB with local changes');
            }

        }
        // truncate local translations table
        $this->translationsRepository->truncateTranslations();

        $result = $this->clientApiService->getCatalogIndex();

        if($result['result']){
            $catalogs = $result['catalogs'];
        }else{
            die('error getting catalogs');
        }

        foreach($catalogs as $catalog){

            $this->output->writeln(PHP_EOL . sprintf('<info>Processing catalog %s ...</info>', $catalog));

            $result = $this->clientApiService->downloadKeys($catalog);
            //var_dump($result); die;

            foreach($result['data'] as $key=>$data){
                foreach($data as $locale=>$messageData){
                    //$this->output->writeln(sprintf("\t|-- key %s:%s/%s ... ", $catalog, $key, $locale));
                    echo '.';
                    $trans = Translation::newFromArray($catalog, $key, $locale, $messageData);
                    $this->em->persist($trans);
                }
            }

            // meter las traducciones en local

        }

        $this->output->writeln(PHP_EOL . sprintf('<info>Flushing to DB ...</info>', $catalog));

        $this->em->flush();

        $this->output->writeln(PHP_EOL . '<info>Clearing SF cache ...</info>');
        /** @var Translator $translator */
        //$translator = $this->getContainer()->get('translator');
        //$translator->removeLocalesCacheFiles($managedLocales);
        exec("rm -rf ".$this->rootDir."/app/cache/*");

        $this->output->writeln('');
    }

    protected function center($text, $width = 120)
    {
        $len = strlen($text);
        if($len<$width){
            $w = (intval($width - $len)/2);
            $left = str_repeat('·', $w);
            $right = str_repeat('·', $width - $len - $w);
            return  $left . $text . $right;
        }else{
            return $text;
        }
    }

}
