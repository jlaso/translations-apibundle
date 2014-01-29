<?php

namespace JLaso\TranslationsApiBundle\Command;

use Doctrine\ORM\EntityManager;
use JLaso\TranslationsApiBundle\Entity\Repository\TranslationRepository;
use JLaso\TranslationsApiBundle\Entity\Translation;
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
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpKernel\Kernel;


/**
 * Sync translations files - translations server.
 *
 * @author Joseluis Laso <jlaso@joseluislaso.es>
 */
class TranslationsDumpCommand extends ContainerAwareCommand
{
    const COMMENTS = 'comments';

    /** @var InputInterface */
    private $input;
    /** @var OutputInterface */
    private $output;
    /** @var  EntityManager */
    private $em;
    private $srcDir;
    /** @var  TranslationRepository */
    private $translationRepository;

    const THROWS_EXCEPTION = true;
    /** fake key to process app/Resources/translations */
    const APP_BUNDLE_KEY = '*app';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('jlaso:translations:dump');
        $this->setDescription('Dump yml translations files to pdo.');

        $this->addOption('cache-clear', 'c', InputOption::VALUE_NONE, 'Remove translations cache files for managed locales.', null);
        $this->addOption('backup-files', 'b', InputOption::VALUE_NONE, 'Makes a backup of yaml files updated.', null);
        $this->addOption('force', null, InputOption::VALUE_OPTIONAL, 'Force replace local database content.', null);
    }

    /**
     * Estrategia:
     * - recuperar la lista de bundles
     * - confeccionar una lista completa de bundles con los locales y remotos
     * - recorrer la lista de bundles
     *     - recuperar la lista de claves del bundle
     *     - confeccionar una lista completa de claves con los locales y remotos del bundle
     *     - enviar un if-newest de cada clave/idioma
     *
     */

    protected function init()
    {
        /** @var EntityManager $em */
        $this->em         = $this->getContainer()->get('doctrine.orm.default_entity_manager');
        $this->srcDir     = realpath($this->getApplication()->getKernel()->getRootDir() . '/../src/') . '/';
        $this->translationRepository = $this->em->getRepository('TranslationsApiBundle:Translation');
    }

    /**
     * @param $bundleName
     *
     * @return BundleInterface
     */
    protected function getBundleByName($bundleName)
    {
        /** @var Kernel $kernel */
        $kernel = $this->getApplication()->getKernel();

        $bundles = $kernel->getBundle($bundleName, false);

        return $bundles[count($bundles) - 1];
    }


    /*
     * Scans folders to find translations files and extract catalog by filename
     */
    protected function getLocalCatalogs()
    {
        $result = array();

        $folders = array(
            $this->srcDir,
            dirname($this->srcDir) . '/app',
        );

        foreach($folders as $folder){
            $finder = new Finder();
            $finder->files()->in($folder)->name('/\w+\.\w+\.yml$/i');

            foreach($finder as $file){
                //$yml = $file->getRealpath();
                //$relativePath = $file->getRelativePath();
                $fileName = $file->getRelativePathname();

                if(preg_match("/translations\/(\w+)\.(\w+)\.yml/i", $fileName, $matches)){
                    $catalog = $matches[1];
                    $result[$catalog] = null;
                }
            }
        }

        return array_keys($result);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input    = $input;
        $this->output   = $output;
        $this->init();

        if($input->getOption('force')!='yes'){
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
        $this->translationRepository->truncateTranslations();

        $config         = $this->getContainer()->getParameter('translations_api');
        $managedLocales = $config['managed_locales'];
        if(!count($managedLocales)){
            die('not found managed locales' . PHP_EOL);
        }
        $managedLocales[] = self::COMMENTS;
        //$apiConfig      = $this->getContainer()->getParameter('jlaso_translations_api_access');

        $this->output->writeln('<info>*** Syncing bundles translation files ***</info>');

        $allLocalBundles = $this->getApplication()->getKernel()->getBundles();

        /** @var BundleInterface[] $allLocalBundles  */
        foreach($allLocalBundles as $bundle){
            // just added bundles that are within / src as the other are not responsible for their translations
            if(strpos($bundle->getPath(), $this->srcDir) === 0 ){
                $name = $bundle->getName();
                if(!isset($allBundles[$name])){
                    $allBundles[$name] = $name;
                }
            }
        };

        // adding a fake bundle to process translations from /app/Resources/translations
        $allBundles[self::APP_BUNDLE_KEY] = self::APP_BUNDLE_KEY;

        $catalogs = $this->getLocalCatalogs();

        // proccess local keys
        foreach ($allBundles as $bundleName)  {

            $this->output->writeln(PHP_EOL . sprintf("<error>%s</error>", $this->center($bundleName)));

            foreach($managedLocales as $locale){

                $this->output->writeln(sprintf('· %s/%s', $bundleName, $locale));

                foreach($catalogs as $catalog){
                    if(self::APP_BUNDLE_KEY == $bundleName){
                        $bundle = null;
                        $filePattern = $this->srcDir . '../app/Resources/translations/%s.%s.yml';
                    }else{
                        $bundle      = $this->getBundleByName($bundleName);
                        $filePattern = $bundle->getPath() . '/Resources/translations/%s.%s.yml';
                    }

                    $fileName = sprintf($filePattern, $catalog, $locale);

                    if(!file_exists($fileName)){
                        //$this->output->writeln(sprintf("· · <comment>File '%s' not found</comment>", $fileName));
                    }else{
                        //                    $maxDate = new \DateTime(date('c',filemtime($fileName)));
                        $hasChanged = false;
                        $localKeys  = $this->getYamlAsArray($fileName);
                        $this->output->writeln(sprintf("· · <info>Processing</info> '%s', found <info>%d</info> translations", $this->fileTrim($fileName), count($localKeys)));
                        //$this->output->writeln(sprintf("\t|-- <info>getKeys</info> informs that there are %d keys ", count($remoteKeys)));

                        foreach($localKeys as $localKey=>$message){

                            $this->output->writeln(sprintf("\t|-- key %s:%s/%s ... ", $bundleName, $localKey, $locale));
                            $this->updateOrInsertEntry($bundleName, $fileName, $localKey, $locale, $message, $catalog);
                        }

                    }

                    //unlink($fileName);
                    //$this->output->writeln('');
                }
            }
        }

        $this->output->writeln(PHP_EOL . '<info>Flushing to DB ...</info>');
        $this->em->flush();

        if ($this->input->getOption('cache-clear')) {
            $this->output->writeln(PHP_EOL . '<info>Removing translations cache files ...</info>');
            $this->getContainer()->get('translator')->removeLocalesCacheFiles($managedLocales);
        }

        $this->output->writeln('');
    }

    /**
     * pretty center a message on the screen
     *
     * @param string $text
     * @param int    $width
     *
     * @return string
     */
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

    /**
     * removes the system path to project in order to archive only the relative path
     *
     * @param string $fileName
     *
     * @return string
     */
    protected function fileTrim($fileName)
    {
        return str_replace(dirname($this->srcDir), '', $fileName);
    }

    /**
     * Reads a Yaml file and process the keys and returns as a associative indexed array
     *
     * @param string $file
     *
     * @return array
     */
    protected function getYamlAsArray($file)
    {
        if(file_exists($file)){
            return ArrayTools::YamlToKeyedArray(file_get_contents($file));
        }

        return array();
    }

    /**
     * @param string    $bundleName
     * @param string    $file
     * @param string    $key
     * @param string    $locale
     * @param string    $content
     * @param string    $catalog
     * @param \DateTime $updatedAt
     */
    protected function updateOrInsertEntry($bundleName, $file, $key, $locale, $content, $catalog, \DateTime $updatedAt = null)
    {
        $shortFile  = str_replace($this->srcDir, '', $file);
        $shortFile  = str_replace('\/', '/', $shortFile);
        $mod        = $updatedAt ?: new \DateTime(date('c',filemtime($file)));
        /** @var Translation $entry */
        $entry = $this->translationRepository->findOneBy(array(
                'domain' => $bundleName,
                'key'    => $key,
                'locale' => $locale,
            )
        );
        if(!$entry){
            $entry = new Translation();
        }
        $entry->setDomain($catalog);
        $entry->setBundle($bundleName);
        $entry->setFile($shortFile);
        $entry->setKey($key);
        $entry->setMessage($content);
        $entry->setUpdatedAt($mod);
        $entry->setLocale($locale);
        $this->em->persist($entry);
    }

}
