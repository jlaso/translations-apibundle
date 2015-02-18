<?php

namespace JLaso\TranslationsApiBundle\Command;

use Doctrine\ORM\EntityManager;
use JLaso\TranslationsApiBundle\Entity\Repository\TranslationRepository;
use JLaso\TranslationsApiBundle\Entity\Translation;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;


/**
 * @author Joseluis Laso <jlaso@joseluislaso.es>
 */
class TranslationsExtractCommand extends ContainerAwareCommand
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
        $this->setName('jlaso:translations:extract');
        $this->setDescription('Extract translations keys from php and template files.');

        //$this->addOption('cache-clear', 'c', InputOption::VALUE_NONE, 'Remove translations cache files for managed locales.', null);
        //$this->addOption('backup-files', 'b', InputOption::VALUE_NONE, 'Makes a backup of yaml files updated.', null);
        //$this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force import, replace database content.', null);
    }

    protected function init()
    {
        $this->srcDir = realpath($this->getApplication()->getKernel()->getRootDir() . '/../');
        /** @var EntityManager $em */
        $this->em                    = $this->getContainer()->get('doctrine.orm.default_entity_manager');
        $this->translationRepository = $this->em->getRepository('TranslationsApiBundle:Translation');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input    = $input;
        $this->output   = $output;
        $this->init();

        $this->output->writeln('<info>*** Extracting translating info from php and twig files ***</info>');

        $fileNames = array();
        $keys = array();
        $idx = 0;
        $numKeys = 0;

        $patterns = array(
            '*.twig' => '/(["\'])(?<trans>(?:\\\1|(?!\1).)*?)\1\s*\|\s*trans/i',
            '*.php'  => '/trans\s*\(\s*(["\'])(?<trans>(?:\\\1|(?!\1).)*?)\1\s*\)/i',
            '*.js'   => '/trans.getTranslation\s*\(\s*(["\'])(?<trans>(?:\\\1|(?!\1).)*?)\1\s*\)/i',
        );
        $folders  = array(
            $this->srcDir . '/app',
            $this->srcDir . '/src'
        );

        $keyInfo = array();

        foreach($patterns as $filePattern=>$exrPattern){

            foreach($folders as $folder){

                $output->writeln($folder);
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

                    $fileContents = file_get_contents($fileName);
                    if(preg_match_all($exrPattern, $fileContents, $matches)){
                        $output->writeln(sprintf("<info>%s</info>", $file));
                        $fileNames[$bundleName] = $file->getRelativePathname();
                        if(!isset($keys[$bundleName])){
                            $output->writeln($bundleName);
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

        $output->writeln(sprintf("Total %d files examined, and found translations in %d files", $idx, count($fileNames)));
        $output->writeln(sprintf("Total %d keys extracted (%d)", $numKeys, count($keys, COUNT_RECURSIVE)));
//        var_dump($fileNames);
//        var_dump($keys);

        $sortedKeys = array();
        /** @var Translation[] $localKeys */
        $localKeys = $this->translationRepository->findAll();
        foreach($localKeys as $localKey){
            $bundle = $localKey->getBundle();
            $keyName = $localKey->getKey();
            $sortedKeys[$bundle][$keyName] = true;
        }

        $localSortedKeys = array();

        // find keys thar are in files but not in db
        foreach($keys as $bundle=>$keyArray){

            foreach($keyArray as $key){

                if(!isset($sortedKeys[$bundle][$key])){

                    $output->writeln(sprintf("file local key <info>%s</info>[<comment>%s</comment>] not found in DB, file %s", $bundle, $key, $keyInfo[$bundle][$key]));

                }
                $localSortedKeys[$bundle][$key] = true;
            }
        }

        // find keys that are in db but not used in files
        foreach($sortedKeys as $bundle=>$keyArray){

            //var_dump($keyArray);
            foreach($keyArray as $key=>$void){

                if(!isset($localSortedKeys[$bundle][$key])){

                    $output->writeln(sprintf("db key <info>%s</info>[<comment>%s</comment>] not used in local files", $bundle, $key));
                    //var_dump($localSortedKeys[$bundle]);
                    //die;
                }

            }

        }

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
