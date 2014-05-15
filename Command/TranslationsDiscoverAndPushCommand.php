<?php

namespace JLaso\TranslationsApiBundle\Command;

use Doctrine\ORM\EntityManager;
use JLaso\TranslationsApiBundle\Entity\Repository\TranslationRepository;
use JLaso\TranslationsApiBundle\Entity\Translation;
use JLaso\TranslationsApiBundle\Service\ClientSocketService;
use Sensio\Bundle\GeneratorBundle\Command\Helper\DialogHelper;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use JLaso\TranslationsApiBundle\Model\CandidateKey;


/**
 * @author Joseluis Laso <jlaso@joseluislaso.es>
 *
 *         Discover new keys used on twigs, php and js and push to server
 */
class TranslationsDiscoverAndPushCommand extends ContainerAwareCommand
{
    /** @var InputInterface */
    private $input;
    /** @var OutputInterface */
    private $output;
    private $srcDir;
    /** @var  TranslationRepository */
    private $translationRepository;
    /** @var  EntityManager */
    private $em;
    /** @var ClientSocketService */
    private $clientApiService;

    const THROWS_EXCEPTION = true;

    const ESCAPE_CHARS = '"';
    /** @var array */
    protected $inputFiles = array();

    /** @var array */
    protected $data = array();
    /** @var array */
    protected $filterStore = array();

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('jlaso:translations:discover-and-push');
        $this->setDescription('Discover new keys used on twigs, php and js and push to server.');
        $this->addOption('bundle', null, InputArgument::OPTIONAL, '--bundle=bundleName to only extract keys for this bundle');
    }

    protected function init()
    {
        $this->srcDir = realpath($this->getApplication()->getKernel()->getRootDir() . '/../');
        /** @var EntityManager $em */
        $this->em                    = $this->getContainer()->get('doctrine.orm.default_entity_manager');
        $this->translationRepository = $this->em->getRepository('TranslationsApiBundle:Translation');
        /** @var ClientSocketService $clientApiService */
        $clientApiService = $this->getContainer()->get('jlaso_translations.client.socket');
        $this->clientApiService = $clientApiService;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input    = $input;
        $this->output   = $output;
        $this->init();

        $verbose = (boolean)$input->getOption('verbose');
        $bundleToExtract = $input->getOption('bundle');

        $this->output->writeln('<info>*** Discovering new keys in php, twig and js files ***</info>');

        $fileNames = array();
        $keys = array();
        $idx = 0;
        $numKeys = 0;

        $patterns = array(
            '*.twig' => '/(["\'])(?<trans>(?:\\\1|(?!\1).)+?)\1\s*\|\s*trans/i',
            '*.php'  => '/trans\s*\(\s*(["\'])(?<trans>(?:\\\1|(?!\1).)+?)\1\s*\)/i',
            '*Type.php' => '/([\'"])label\1\s*=>\s*([\'"])(?<trans>(?:\\\2|(?!\2).)+?)\2/',
            '*.js'   => '/trans.getTranslation\s*\(\s*(["\'])(?<trans>(?:\\\1|(?!\1).)+?)\1\s*\)/i',
        );
        $folders  = array(
            $this->srcDir . '/app',
            $this->srcDir . '/src'
        );

        $keyInfo = array();

        foreach($patterns as $filePattern=>$exrPattern){

            foreach($folders as $folder){

                if($verbose){
                    $output->writeln($folder);
                }
                $finder = new Finder();
                $files = $finder->in($folder)->name($filePattern)->files();

                /** @var SplFileInfo[] $files */
                foreach($files as $file){
                    $fileName = $folder . '/' . $file->getRelativePathname();
                    if(strpos($fileName, $folder . "/cache") === 0){
                        //$output->writeln(sprintf("ignored <comment>%s</comment>", $file));
                        continue;
                    }
                    if(preg_match("/\/(?P<bundle>.*Bundle)\//U", $file->getRelativePathname(), $match)){
                        $bundleName = $match['bundle'];
                    }else{
                        $bundleName = "*app";
                    }

                    if(!$bundleToExtract || ($bundleToExtract == $bundleName)){
                        $fileContents = file_get_contents($fileName);
                        if(preg_match_all($exrPattern, $fileContents, $matches)){
                            if($verbose){
                                $output->writeln(sprintf("<info>%s</info>", $file));
                            }
                            $fileNames[$bundleName] = $file->getRelativePathname();
                            if(!isset($keys[$bundleName])){
                                if($verbose){
                                    $output->writeln($bundleName);
                                }
                                $keys[$bundleName] = array();
                            }
                            $keys[$bundleName] = array_merge_recursive($keys[$bundleName], $matches["trans"]);
                            foreach($matches['trans'] as $currentKey){
                                $keyInfo[$bundleName][$currentKey] = $file->getRelativePathname();
                            }
                            $numKeys += count($matches["trans"]);
                        }
                        $idx++;
                    }
                }
            }
        }

        $output->writeln(sprintf("Total %d files examined, and found key translations in %d files", $idx, count($fileNames)));
        $output->writeln(sprintf("Total %d keys extracted (%d)", $numKeys, count($keys, COUNT_RECURSIVE)));


        $sortedKeys = array();
        /** @var Translation[] $localKeys */
        $localKeys = $this->translationRepository->findAll();
        foreach($localKeys as $localKey){
            $bundle = $localKey->getBundle();
            $keyName = $localKey->getKey();
            $sortedKeys[$bundle][$keyName] = true;
        }

        $localSortedKeys = array();
        /** @var CandidateKey[] $candidates */
        $candidates = array();

        // find keys thar are in files but not in db
        foreach($keys as $bundle=>$keyArray){

            foreach($keyArray as $key){

                if(!isset($sortedKeys[$bundle][$key])){

                    $file = $keyInfo[$bundle][$key];
                    $candidates[] = new CandidateKey($bundle, $file, $key);
                    //$output->writeln(sprintf("file local key <info>%s</info>[<comment>%s</comment>] not found in DB, file %s", $bundle, $key, $file));

                }
                $localSortedKeys[$bundle][$key] = true;
            }
        }

//        // find keys that are in db but not used in files
//        foreach($sortedKeys as $bundle=>$keyArray){
//
//            //var_dump($keyArray);
//            foreach($keyArray as $key=>$void){
//
//                if(!isset($localSortedKeys[$bundle][$key])){
//
//                    $output->writeln(sprintf("db key <info>%s</info>[<comment>%s</comment>] not used in local files", $bundle, $key));
//                    //var_dump($localSortedKeys[$bundle]);
//                    //die;
//                }
//
//            }
//
//        }

        $output->writeln("Check the possible candidate keys to upload to server");

        foreach($candidates as $candidate){

            $output->writeln(sprintf("file local key <info>%s</info>[<comment>%s</comment>] not found in DB, file %s", $candidate->getBundle(), $candidate->getKey(), $candidate->getFile()));

        }

        $output->writeln('<info>Have you been synchronized your translations with the sync command?</info>');


        /** @var DialogHelper $dialog */
        $dialog = $this->getHelper('dialog');
        if ($dialog->askConfirmation(
            $output,
            '<question>Confirm that you want to upload the previous keys to server ?</question>',
            false
        )) {

            $catalog = $dialog->ask($output, 'Name of catalog in which to integrate new keys [messages]:', 'messages');

            $data           = array();
            $date           = new \DateTime();
            $config         = $this->getContainer()->getParameter('translations_api');
            $managedLocales = $config['managed_locales'];

            foreach($candidates as $candidate){

                $key      = $candidate->getKey();
                $bundle   = $candidate->getBundle();
                $fileName = $candidate->getFile();
                preg_match('|^(?<prefix>\w+)/'.$bundle.'/|', $fileName, $matches);
                if(isset($matches['prefix'])){
                    $prefix = $matches['prefix'];

                    foreach($managedLocales as $locale){

                        $data[$key][$locale] = array(
                            'message'   => '',
                            'updatedAt' => $date->format('c'),
                            'fileName'  => $prefix . '/' . $this->genFileFromBundleAndLocale($bundle, $catalog, $locale),
                            'bundle'    => $bundle,

                        );
                    }

                }

            }

            $this->output->writeln('uploadKeys("' . $catalog . '", $data)');

            //var_dump($data); //die;

            $this->clientApiService->init('localhost', 10000);
            $result = $this->clientApiService->uploadKeys($catalog, $data);


        }


        $output->writeln('You must now to synchronize your translations with the sync command!');


    }

    protected function genFileFromBundleAndLocale($bundleName, $catalog, $locale)
    {
        return sprintf("%s/Resources/translations/%s.%s.yml", $bundleName, $catalog, $locale);
    }

    /**
     * @param string $message
     * @throws \Exception
     */
    protected function throwException($message)
    {
        $message = $message ?: 'Unexpected exception';
        //print $message;
        throw new \Exception($message);
    }


}
