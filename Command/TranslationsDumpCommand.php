<?php

namespace JLaso\TranslationsApiBundle\Command;

use Doctrine\ORM\EntityManager;
use JLaso\TranslationsApiBundle\Entity\Repository\TranslationRepository;
use JLaso\TranslationsApiBundle\Entity\Translation;
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
        //$this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force import, replace database content.', null);
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

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input    = $input;
        $this->output   = $output;
        $this->init();

        $config         = $this->getContainer()->getParameter('translations_api');
        $managedLocales = $config['managed_locales'];
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

        // proccess local keys
        foreach ($allBundles as $bundleName)  {

            $this->output->writeln(PHP_EOL . sprintf("<error>%s</error>", $this->center($bundleName)));

            foreach($managedLocales as $locale){

                $this->output->writeln(PHP_EOL . sprintf('· %s/%s', $bundleName, $locale));

                if(self::APP_BUNDLE_KEY == $bundleName){
                    $bundle = null;
                    $filePattern = $this->srcDir . '../app/Resources/translations/messages.%s.yml';
                }else{
                    $bundle      = $this->getBundleByName($bundleName);
                    $filePattern = $bundle->getPath() . '/Resources/translations/messages.%s.yml';
                }

                $fileName = sprintf($filePattern, $locale);

                if(!file_exists($fileName)){
                    $this->output->writeln(sprintf("· · <comment>File '%s' not found</comment>", $fileName));
                }else{
//                    $maxDate = new \DateTime(date('c',filemtime($fileName)));
                    $hasChanged = false;
                    $localKeys  = $this->getYamlAsArray($fileName);
                    $this->output->writeln(sprintf("· · <info>Processing</info> '%s', found <info>%d</info> translations", $this->fileTrim($fileName), count($localKeys)));
                    //$this->output->writeln(sprintf("\t|-- <info>getKeys</info> informs that there are %d keys ", count($remoteKeys)));

                    foreach($localKeys as $localKey=>$message){

                        $this->output->writeln(sprintf("\t|-- key %s:%s/%s ... ", $bundleName, $localKey, $locale));
                        $this->updateOrInsertEntry($bundleName, $fileName, $localKey, $locale, $message);
                    }

                }

                //unlink($fileName);
                $this->output->writeln('');
            }
        }
        $this->em->flush();

        if ($this->input->getOption('cache-clear')) {
            $this->output->writeln(PHP_EOL . '<info>Removing translations cache files ...</info>');
            $this->getContainer()->get('translator')->removeLocalesCacheFiles($managedLocales);
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

    protected function fileTrim($fileName)
    {
        return str_replace(dirname($this->srcDir), '', $fileName);
    }

    /**
     * Dumps a message translations array to yaml file
     *
     * @param string $file
     * @param array $keys
     */
    protected function dumpYaml($file, $keys)
    {
        if($this->input->getOption('cache-clear') && file_exists($file)){
            // backups the file
            copy($file, $file . '.' . date('d-m-H-i'). '.bak');
        }
        if(!is_dir(dirname($file))){
            // the dir not exists
            mkdir(dirname($file), 0777, true);
        }
        $this->output->writeln(sprintf("\t|-- <info>saving file</info> '%s'", $this->fileTrim($file)));
        file_put_contents($file, Yaml::dump($this->k2a($keys), 100));
        //touch($fileName, $maxDate->format('U'));
    }

    /**
     * @param BundleInterface[] $bundles
     * @return array
     */
    protected function bundles2array($bundles)
    {
        $result = array();
        foreach($bundles as $bundle){
            $result[$bundle->getName()] = $bundle->getName();
        }

        //array_combine($bundles, $bundles);

        return $result;
    }


    /**
     * associative array indexed to dimensional associative array of keys
     *
     * @param $dest
     * @param $orig
     * @param $currentKey
     */
    protected function a2k(&$dest, $orig, $currentKey)
    {
        if(is_array($orig) && (count($orig)>0)){
            foreach($orig as $key=>$value){
                if(is_array($value)){
                    $this->a2k($dest, $value, ($currentKey ? $currentKey . '.' : '') . $key);
                }else{
                    $dest[($currentKey ? $currentKey . '.' : '') . $key] = $value;
                    //$tmp = explode('.', $currentKey);
                    //$currentKey = implode('.', array_pop($tmp));
                }
            }
        }
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
            $content = Yaml::parse(file_get_contents($file));
            $result  = array();
            $this->a2k($result, $content, '');

            return $result;
        }else{
            return array();
        }
    }

    /**
     * dimensional associative array of keys to associative array indexed
     *
     * @param $orig
     *
     * @return array
     */
    protected function k2a($orig)
    {
        $result = array();
        foreach($orig as $key=>$value){
            if($value===null){

            }else{
                $keys = explode('.',$key);
                $node = $value;
                for($i = count($keys); $i>0; $i--){
                    $k = $keys[$i-1];
                    $node = array($k => $node);
                }
                $result = array_merge_recursive($result, $node);
            }
        }

        return $result;
    }

    /**
     * @param string    $bundleName
     * @param string    $file
     * @param string    $key
     * @param string    $locale
     * @param string    $content
     * @param \DateTime $updatedAt
     *
     */
    protected function updateOrInsertEntry($bundleName, $file, $key, $locale, $content, \DateTime $updatedAt = null)
    {
        $shortFile  = str_replace($this->srcDir, '', $file);
        $shortFile  = str_replace('\/', '/', $shortFile);
        $mod        = $updatedAt ?: new \DateTime(date('c',filemtime($file)));
        /** @var Translation $entry */
        $entry = $this->translationRepository->findOneBy(array(
                'domain' => $bundleName,
                //'file'   => $filename,
                'key'    => $key,
                'locale' => $locale,
            )
        );

        if(!$entry instanceof Translation){
            $entry = new Translation();
        }

        $parts = explode(DIRECTORY_SEPARATOR, $shortFile);
        $filename = $parts[count($parts)-1];
        $parts = explode(".", $filename);
        $domain = $parts[0];

        $entry->setDomain($domain);
        $entry->setBundle($bundleName);
        $entry->setFile($shortFile);
        $entry->setKey($key);
        $entry->setMessage($content);
        $entry->setUpdatedAt($mod);
        $entry->setLocale($locale);
        $this->em->persist($entry);
        //$this->em->flush();

    }

}
