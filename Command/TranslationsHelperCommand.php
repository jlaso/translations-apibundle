<?php

namespace JLaso\TranslationsApiBundle\Command;

use Doctrine\ORM\EntityManager;
use JLaso\TranslationsApiBundle\Entity\Repository\SCMRepository;
use JLaso\TranslationsApiBundle\Entity\SCM;
use JLaso\TranslationsApiBundle\Service\ClientApiService;
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

/**
 * Sync translations files - translations server.
 *
 * @author Joseluis Laso <jlaso@joseluislaso.es>
 */
class TranslationsHelperCommand extends ContainerAwareCommand
{
    const COMMENTS = 'comments';
    const CHARLIST = 'áéíóúñÑÁÉÍÓÚÄäËëÏïÜüçÇ';

    /** @var InputInterface */
    private $input;
    /** @var OutputInterface */
    private $output;
    private $srcDir;

    const THROWS_EXCEPTION = true;
    /** fake key to process app/Resources/translations */
    const APP_BUNDLE_KEY = '*app';

    protected $outputFile;
    protected $originLang;
    protected $handler;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('jlaso:translations:helper');
        $this->setDescription('Generate a single translation txt file from all project translations.');

        $this->addArgument('origin', InputArgument::REQUIRED, 'Origin language.', null);
        $this->addArgument('output', InputArgument::REQUIRED, 'Output file name.', null);
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
        $this->srcDir     = realpath($this->getApplication()->getKernel()->getRootDir() . '/../src/') . '/';
    }

    /**
     * @param $bundleName
     *
     * @return BundleInterface
     */
    protected function getBundleByName($bundleName)
    {
        return $this->getApplication()->getKernel()->getBundle($bundleName);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input    = $input;
        $this->output   = $output;
        $this->init();

        $config         = $this->getContainer()->getParameter('jlaso_translations');
        $managedLocales = $config['managed_locales'];
        $managedLocales[] = self::COMMENTS;
        $apiConfig      = $this->getContainer()->getParameter('jlaso_translations_api_access');

        $this->outputFile = $input->getArgument('output');
        $this->originLang = $input->getArgument('origin');

        $this->handler = fopen($this->outputFile, "w+");

        $this->output->writeln('<info>*** generating ...  ***</info>');

        $allLocalBundles = $this->getApplication()->getKernel()->getBundles();
        $allBundles      = $this->bundles2array($allLocalBundles);

        /** @var BundleInterface[] $allLocalBundles  */
        foreach($allLocalBundles as $bundle){
            // just added bundles that are within / src as the other are not responsible for their translations
            if(strpos($bundle->getPath(), $this->srcDir) === 0 ){
                $allBundles[] = $bundle->getName();
            }
        };

        // adding a fake bundle to process translations from /app/Resources/translations
        $allBundles[] = self::APP_BUNDLE_KEY;
        $count = 0;
        $words = 0;

        // doing a array with all the keys of all remote bundles
        // proccess local keys
        foreach($allBundles as $bundleName){
            $this->output->writeln('<info>Bundle ' . $bundleName . ' . . .</info>');
            $locale = $this->originLang;

                if(self::APP_BUNDLE_KEY == $bundleName){
                    $bundle = null;
                    $filePattern = $this->srcDir . '../app/Resources/translations/messages.%s.yml';
                }else{
                    $bundle      = $this->getBundleByName($bundleName);
                    $filePattern = $bundle->getPath() . '/Resources/translations/messages.%s.yml';
                }
                $fileName   = sprintf($filePattern, $locale);

                if(!file_exists($fileName)){
                    $this->output->writeln(sprintf('<comment>File "%s" not found</comment>', $fileName));
                }else{
                    $localKeys  = $this->getYamlAsArray($fileName);
                    $count +=  count($localKeys);
                    $this->output->writeln(sprintf('<info>Processing "%s", found %d translations</info>', $fileName, count($localKeys)));
                    fwrite($this->handler, implode(PHP_EOL, $localKeys) . PHP_EOL);
                    $words += str_word_count(implode(" ", $localKeys), 0, self::CHARLIST);
                }
        }

        fclose($this->handler);

        $this->output->writeln(sprintf('found %d keys in total', $count));
        $this->output->writeln(sprintf('found %d words in total', $words));
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

}
