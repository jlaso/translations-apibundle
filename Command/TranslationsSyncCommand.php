<?php

namespace JLaso\TranslationsApiBundle\Command;

use Doctrine\ORM\EntityManager;
use JLaso\TranslationsApiBundle\Entity\Repository\TranslationRepository;
use JLaso\TranslationsApiBundle\Entity\Translation;
use JLaso\TranslationsApiBundle\Service\ClientApiService;
use JLaso\TranslationsApiBundle\Service\ClientSocketService;
use JLaso\TranslationsApiBundle\Tools\ArrayTools;
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
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Bundle\FrameworkBundle\Translation\Translator;
use Symfony\Component\Yaml\Yaml;


/**
 * Sync translations files - translations server.
 *
 * @author Joseluis Laso <jlaso@joseluislaso.es>
 */
class TranslationsSyncCommand extends ContainerAwareCommand
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
        $this->setName('jlaso:translations:sync');
        $this->setDescription('Sync all translations from translations server.');
        $this->addOption('port', null, InputArgument::OPTIONAL, 'port');
        $this->addOption('address', null, InputArgument::OPTIONAL, 'address');
        $this->addOption('upload-first', null, InputArgument::OPTIONAL, '--upload-first=yes to upload our local DB to remote first of all');
        $this->addOption('yml', null, InputOption::VALUE_REQUIRED, '--yml=[regenerate,blank,backup] to regenerate local .yml files from remote DB', null);
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

        $ymlOptions = array(
            'regenerate' => false,
            'backup'     => false,
            'blank'      => false,
        );
        $aux = explode(",", $this->input->getOption('yml'));
        if(count($aux)){
            foreach($aux as $option){
                $ymlOptions[$option] = true;
            }
        }
        if(count($ymlOptions) != 3){
            die('Sorry, but you can use only regenerate,blank and backup with --yml option');
        }

        $this->init($input->getOption('address'), $input->getOption('port'));

        $config         = $this->getContainer()->getParameter('translations_api');
        $managedLocales = $config['managed_locales'];

        $this->output->writeln(PHP_EOL . '<info>*** Syncing translations ***</info>');

        /**
         * uploading local catalog keys (from local table) to remote server
         */
        if($input->getOption('upload-first') == 'yes'){

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
                        'fileName'  => $message->getFile(),
                        'bundle'    => $message->getBundle(),
                    );

                }

                //print_r($data); die;
                $this->output->writeln('uploadKeys("' . $catalog . '", $data)');

                $result = $this->clientApiService->uploadKeys($catalog, $data);
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

        /**
         * download the remote catalogs and integrate into local table (previously truncate local table)
         */

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
            $bundles = $result['bundles'];

            foreach($result['data'] as $key=>$data){
                foreach($data as $locale=>$messageData){
                    //$this->output->writeln(sprintf("\t|-- key %s:%s/%s ... ", $catalog, $key, $locale));
                    echo '.';
                    $fileName = isset($messageData['fileName']) ? $messageData['fileName'] : '';
                    $trans = Translation::newFromArray($catalog, $key, $locale, $messageData, $bundles[$key], $fileName);
                    $this->em->persist($trans);
                }
            }

            // meter las traducciones en local

        }

        $this->output->writeln(PHP_EOL . '<info>Flushing to DB ...</info>');

        $this->em->flush();

        /**
         * regeneration of local .yml files if user wants
         */

        /** @var DialogHelper $dialog */
        $dialog = $this->getHelper('dialog');

        if(($ymlOptions['regenerate']) ||
            ($dialog->askConfirmation($output,'<question>Do want to regenerate local .yml files ?</question>',false)))
        {

            $bundles = $this->translationsRepository->getBundles();

            foreach($bundles as $bundle){

                if(!$bundle){
                    continue;
                }
                $keys = array();
                $filenames = array();
                $scheme = ""; // in order to deduce filename from other keys

                $translations = $this->translationsRepository->getKeysByBundle($bundle);
                foreach($translations as $translation){

                    $locale = $translation->getLocale();
                    $file = $translation->getFile();
                    if($file && $locale && !$scheme){
                        $scheme = str_replace(".{$locale}.", ".%s.", $file);
                        break;
                    }
                }

                foreach($translations as $translation){

                    $locale = $translation->getLocale();
                    $file = $translation->getFile();
                    if($locale && !$file && $scheme){
                        $file = sprintf($scheme, $locale);
                    }
                    if($file && $locale){
                        if(!isset($filenames[$locale])){
                            $filenames[$locale] = $file;
                        }
                        $keys[$locale][$translation->getKey()] = $translation->getMessage();
                    }
                }

                foreach($filenames as $locale=>$file){

                    $this->output->writeln(sprintf('Generating <info>"%s"</info> ...', $file));
                    $subKeys = $keys[$locale];
                    $file = dirname($this->rootDir) . '/src/' . $file;
                    if($ymlOptions['blank']){
                        foreach($subKeys as $key=>$value){
                            if(!$value){
                                $subKeys[$key] = $key;
                            }
                        }
                    }
                    if($ymlOptions['backup'] && file_exists($file)){
                        copy($file, $file . '.' . date('U'));
                    }
                    file_put_contents($file, ArrayTools::prettyYamlDump($subKeys));
                }

            }

        }

        /**
         * erasing cached translations files
         */
        $this->output->writeln(PHP_EOL . '<info>Clearing SF cache ...</info>');
        /** @var Translator $translator */
        //$translator = $this->getContainer()->get('translator');
        //$translator->removeLocalesCacheFiles($managedLocales);
        //exec("rm -rf ".$this->rootDir."/app/cache/*");
        $finder = new Finder();
        $finder->files()->in($this->rootDir . "/cache")->name('/catalogue\./i');

        foreach($finder as $file){
            $fileFull = $file->getRealpath();
            //$relativePath = $file->getRelativePath();
            $fileName = $file->getRelativePathname();
            $this->output->writeln('removing ' . $fileName);

            unlink($file);
        }

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
