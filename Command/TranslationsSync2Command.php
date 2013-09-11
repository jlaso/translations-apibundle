<?php

namespace JLaso\TranslationsApiBundle\Command;

use Doctrine\ORM\EntityManager;
use JLaso\TranslationsApiBundle\Entity\Repository\SCMRepository;
use JLaso\TranslationsApiBundle\Entity\SCM;
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
use Symfony\Component\Yaml\Yaml;

/**
 * Pull translations files from translations server and merges it into files.
 *
 * @author Joseluis Laso <jlaso@joseluislaso.es>
 */
class TranslationsSync2Command extends ContainerAwareCommand
{
    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /** @var  EntityManager */
    private $em;

    private $srcDir;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('jlaso:translations:sync2');
        $this->setDescription('Sync all translations from translations server and merges it into the translations files.');

        $this->addOption('cache-clear', 'c', InputOption::VALUE_NONE, 'Remove translations cache files for managed locales.', null);
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force import, replace database content.', null);
        $this->addOption('globals', 'g', InputOption::VALUE_NONE, 'Import only globals (app/Resources/translations.', null);

        $this->addArgument('bundle', InputArgument::OPTIONAL,'Import translations for this specific bundle.', null);
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

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->srcDir = realpath(__DIR__ . '/../../../..') . '/';
        $this->em = $this->getContainer()->get('doctrine.orm.default_entity_manager');
        $config = $this->getContainer()->getParameter('jlaso_translations');
        $managedLocales = $config['managed_locales'];

        $bundleName = $this->input->getArgument('bundle');
        if($bundleName) {
            $bundle = $this->getApplication()->getKernel()->getBundle($bundleName);
            $this->importBundleTranslationFiles($bundle, $managedLocales);
        } else {
            $this->output->writeln('<info>*** Importing application translation files ***</info>');
            $this->importAppTranslationFiles($managedLocales);

            if (!$this->input->getOption('globals')) {
                $this->output->writeln('<info>*** Importing bundles translation files ***</info>');
                $this->importBundlesTranslationFiles($managedLocales);
            }
        }

        if ($this->input->getOption('cache-clear')) {
            $this->output->writeln('<info>Removing translations cache files ...</info>');
            $this->removeTranslationCache();
        }
    }

    /**
     * Imports application translation files.
     *
     * @param array $locales
     */
    protected function importAppTranslationFiles(array $locales)
    {
        $finder = $this->findTranslationsFiles($this->getApplication()->getKernel()->getRootDir(), $locales);
        $this->importTranslationFiles($finder);
    }

    /**
     * Imports translation files form all bundles.
     *
     * @param array $locales
     */
    protected function importBundlesTranslationFiles(array $locales)
    {
        $bundles = $this->getApplication()->getKernel()->getBundles();

        foreach ($bundles as $bundle) {
            $this->importBundleTranslationFiles($bundle, $locales);
        }
    }

    /**
     * Imports translation files form a bundle.
     *
     * @param BundleInterface $bundle Bundle
     * @param array $locales
     */
    protected function importBundleTranslationFiles(BundleInterface $bundle, array $locales)
    {
        $this->output->writeln(sprintf('<info># %s:</info>', $bundle->getName()));
        $finder = $this->findTranslationsFiles($bundle->getPath(), $locales);
        $this->importTranslationFiles($finder, $bundle);
    }

    /**
     * Imports some translations files.
     *
     * @param Finder          $finder
     * @param BundleInterface $bundle
     */
    protected function importTranslationFiles($finder, BundleInterface $bundle = null)
    {
        if ($finder instanceof Finder) {
            $importer = null; //$this->getContainer()->get('lexik_translation.importer.file');
            foreach ($finder as $file)  {
                /** @var SplFileInfo $file */
                //ldd($file);
                $fileName = $file->getPathname();
                $this->output->write(sprintf('<comment>Importing "%s" ... </comment>', $file->getPathname()));
                $number = 0; //$importer->import($file, $this->input->getOption('force'));
                $this->output->writeln(sprintf('<comment>%d translations</comment>', $number));
                $content = Yaml::parse(file_get_contents($fileName));
                //ld($content);
                $this->processContent($bundle, $file, $content);
            }
        } else {
            $this->output->writeln('<comment>No file to import for managed locales.</comment>');
        }
    }

    protected function a2a(&$dest, $orig, $currentKey)
    {
        foreach($orig as $key=>$value){
            if(is_array($value)){
                $this->a2a($dest, $value, ($currentKey ? $currentKey . '.' : '') . $key);
            }else{
                $dest[($currentKey ? $currentKey . '.' : '') . $key] = $value;
                //$tmp = explode('.', $currentKey);
                //$currentKey = implode('.', array_pop($tmp));
            }
        }
    }

    protected function processContent(BundleInterface $bundle, $file, $contents)
    {
        $a = array();
        $this->a2a($a, $contents, '');
        ld($a);

        foreach($a as $key=>$value){
            $updated = $this->updateOrInsertEntry($bundle, $file, $key, $value);
            if($updated){
                // Sync ?
            }
        }
        $this->em->flush();

    }

    /**
     * Return a Finder object if $path has a Resources/translations folder.
     *
     * @param string $path
     * @param array $locales
     * @return Symfony\Component\Finder\Finder
     */
    protected function findTranslationsFiles($path, array $locales)
    {
        $finder = null;

        if (preg_match('#^win#i', PHP_OS)) {
            $path = preg_replace('#'. preg_quote(DIRECTORY_SEPARATOR, '#') .'#', '/', $path);
        }

        $dir = $path.'/Resources/translations';

        if (is_dir($dir)) {
            $formats = $this->getContainer()->get('lexik_translation.translator')->getFormats();

            $finder = new Finder();
            $finder->files()
                ->name(sprintf('/(.*(%s)\.(%s))/', implode('|', $locales), implode('|', $formats)))
                ->in($dir);
        }

        return $finder;
    }

    /**
     * @param BundleInterface $bundle
     * @param SplFileInfo     $file
     * @param string          $key
     * @param string          $content
     * @return bool
     */
    protected function updateOrInsertEntry(BundleInterface $bundle, $file, $key, $content)
    {
        $fullpath   = str_replace($this->srcDir, '', $file->getPathname());
        $fullpath   = str_replace('\/', '/', $fullpath);
        $bundleName = $bundle->getName();
        $filename   = $file->getFilename();
        $mod        = new \DateTime(date('c', $file->getMTime()));
        /** @var SCM $entry */
        $entry = $this->getSCMRepository()->findOneBy(array(
                'bundle' => $bundleName,
                'file'   => $filename,
                'key'    => $key,
            )
        );
        if($entry instanceof SCM){
            if($entry->getContent() != $content){
                $entry->setLastModification($mod);
                $entry->setContent($content);
                $this->em->persist($entry);

                return true;
            }else{
                return false;
            }
        }else{
            $entry = new SCM();
            $entry->setFile($filename);
            $entry->setBundle($bundleName);
            $entry->setFullpath($fullpath);
            $entry->setKey($key);
            $entry->setContent($content);
            $entry->setLastModification($mod);
            $this->em->persist($entry);

            return true;
        }
    }

    /**
     * @return SCMRepository
     */
    protected function getSCMRepository()
    {
        return $this->em->getRepository('TranslationsApiBundle:SCM');
    }

    /**
     * Remove translation cache files managed locales.
     *
     */
    public function removeTranslationCache()
    {
        $locales = $this->getContainer()->getParameter('lexik_translation.managed_locales');
        $this->getContainer()->get('translator')->removeLocalesCacheFiles($locales);
    }
}
