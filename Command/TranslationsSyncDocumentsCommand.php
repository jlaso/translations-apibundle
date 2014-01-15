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
 * Sync translations documents - translations server.
 *
 * @author Joseluis Laso <jlaso@joseluislaso.es>
 */
class TranslationsSyncDocumentsCommand extends ContainerAwareCommand
{
    /** @var InputInterface */
    private $input;
    /** @var OutputInterface */
    private $output;
    /** @var  EntityManager */
    private $em;
    /** @var ClientSocketService */
    private $clientApiService;

    private $rootDir;

    const THROWS_EXCEPTION = true;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('jlaso:translations:sync-docs');
        $this->setDescription('Sync all documents to and from translations server.');
        $this->addOption('port', null, InputArgument::OPTIONAL, 'port');
        $this->addOption('address', null, InputArgument::OPTIONAL, 'address');
    }

    protected function init($server = null, $port = null)
    {
        /** @var ClientSocketService $clientApiService */
        $clientApiService = $this->getContainer()->get('jlaso_translations.client.socket');
        $this->clientApiService = $clientApiService;
        $this->clientApiService->init($server, $port);
        $this->rootDir = dirname($this->getContainer()->get('kernel')->getRootDir());
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

        $this->output->writeln(PHP_EOL . '<info>*** Syncing documents ***</info>');

        $config         = $this->getContainer()->getParameter('jlaso_translations');
        $managedLocales = $config['managed_locales'];

        $finder = new Finder();

        $finder->files()->in($this->rootDir)->name('jlaso_translations.yml');

        $this->output->writeln($this->rootDir);

        $transDocs = array();

        foreach ($finder as $file) {
            $yml = $file->getRealpath();
            $relativePath = $file->getRelativePath();
            $fileName = $file->getRelativePathname();
            $rules = Yaml::parse($yml);
            //var_dump($rules);
            if(preg_match('/\/(\w*)Bundle\//', $relativePath, $matches)){

                $bundle = $matches[1] . 'Bundle';

            }else{
                $bundle = "app*";
            };
            $this->output->writeln(PHP_EOL . $this->center($bundle));

            if(isset($rules['files'])){

                foreach($rules['files'] as $key=>$fileRule){

                    foreach($managedLocales as $locale){

                        $transFile = $relativePath . '/' . str_replace('%locale%', $locale, $fileRule);

                        if(file_exists($transFile)){
                            $this->output->writeln(sprintf('<info>Processing file "%s"</info>', $transFile));
                            $result = $this->syncDoc($bundle, $key, $locale, $transFile);
                        }else{
                            $this->output->writeln(sprintf('<comment>File "%s" not found</comment>', $transFile));
                            $result = $this->getDoc($bundle, $key, $locale, $transFile);
                        }

                    }

                }

            }
        }


        die('ok');



        $catalogs = $this->translationsRepository->getCatalogs();

        foreach($catalogs as $catalog){

            // data para enviar al servidor
            $data = array();

            $this->output->writeln(PHP_EOL . sprintf('<info>Processing catalog %s ...</info>', $catalog));

            /** @var Translation[] $messages */
            $messages = $this->translationsRepository->findBy(array('domain' => $catalog));

            foreach($messages as $message){

                $key = $message->getKey();
                $locale = $message->getLocale();

                $data[$key][$locale] = array(
                    'message'   => $message->getMessage(),
                    'updatedAt' => $message->getUpdatedAt()->format('c'),
                );

            }

            //print_r($data); die;
            $this->output->writeln('uploadKeys("' . $catalog . '", $data)');

            $result = $this->clientApiService->uploadKeys($catalog, $data);
        }


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

    protected function syncDoc($bundle, $key, $locale, $transFile)
    {
        $fullFilePath = $this->rootDir . '/' . $transFile;
        $document = file_get_contents($fullFilePath);
        $updatedAt = \DateTime::createFromFormat('U',filemtime($fullFilePath))->format('c');

        $result = $this->clientApiService->transDocSync($bundle, $key, $locale, $transFile, $document, $updatedAt);

        if($result['result']){

            if($result['updated']){

            }else{

                $updatedAt = new \DateTime($result['updatedAt']);
                copy($fullFilePath, $fullFilePath . '.' . $updatedAt->format('hms'));
                file_put_contents($fullFilePath, $result['message']);
                touch($fullFilePath, $updatedAt->getTimestamp());

            }

        }else{
            print_r($result); die;
        }
    }

    protected function getDoc($bundle, $key, $locale, $transFile)
    {
        $fullFilePath = $this->rootDir . '/' . $transFile;
        $document = ''; //file_get_contents($fullFilePath);
        $updatedAt = null; //filemtime($fullFilePath);

        //$this->
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
