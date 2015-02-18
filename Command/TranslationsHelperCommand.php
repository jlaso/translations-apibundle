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
    const COMMENTS    = 'comments';
    const CHARLIST    = 'áéíóúñÑÁÉÍÓÚÄäËëÏïÜüçÇ';
    const TXT_FORMAT  = 'txt';
    const CSV_FORMAT  = 'csv';
    const CSV2_FORMAT = 'csv2';

    /** @var InputInterface */
    private $input;
    /** @var OutputInterface */
    private $output;
    private $srcDir;
    private $format;

    const THROWS_EXCEPTION = true;
    /** fake key to process app/Resources/translations */
    const APP_BUNDLE_KEY = '*app';

    protected $outputFile;
    protected $originLang;
    protected $handler;

    protected $labels;

    protected function getFormats()
    {
        return array(
            self::TXT_FORMAT   => self::TXT_FORMAT . ': plain text',
            self::CSV_FORMAT   => self::CSV_FORMAT . ': csv format',
            self::CSV2_FORMAT  => self::CSV2_FORMAT . ': csv with html labels minified',
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('jlaso:translations:helper');
        $this->setDescription('Generate a single translation txt file from all project translations.');

        $this->addArgument('origin', InputArgument::REQUIRED, 'Origin language.', null);
        $this->addArgument('output', InputArgument::REQUIRED, 'Output file name.', null);
        $this->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format ('.implode(',',$this->getFormats()).')', null);
    }


    protected function init()
    {
        $this->srcDir = realpath($this->getApplication()->getKernel()->getRootDir() . '/../src/') . '/';
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

        $config         = $this->getContainer()->getParameter('jlaso_translations');
        $managedLocales = $config['managed_locales'];
        $managedLocales[] = self::COMMENTS;
        $apiConfig      = $this->getContainer()->getParameter('jlaso_translations_api_access');

        $this->outputFile = $input->getArgument('output');
        $this->originLang = $input->getArgument('origin');
        $this->format     = $input->getOption('format') ?: 'txt';
        $formats =  $this->getFormats();
        if(!isset($formats[$this->format])){
             throw new \Exception(sprintf('format %s not recognized', $this->format));
        };

        $this->handler = fopen($this->outputFile, "w+");

        $this->output->writeln('<info>*** generating ...  ***</info>');

        $allLocalBundles = $this->getApplication()->getKernel()->getBundles();
        $allBundles      = array(); //$this->bundles2array($allLocalBundles);

        /** @var BundleInterface[] $allLocalBundles  */
        foreach($allLocalBundles as $bundle){
            // just added bundles that are within / src as the other are not responsible for their translations
            if(strpos($bundle->getPath(), $this->srcDir) === 0 ){
                $allBundles[] = $bundle->getName();
                //$this->output->writeln('Bundle ' . $bundle->getName());
            }
        };

        // adding a fake bundle to process translations from /app/Resources/translations
        $allBundles[] = self::APP_BUNDLE_KEY;
        $localKeys = array();

        // doing a array with all the keys of all remote bundles
        // proccess local keys
        foreach($allBundles as $bundleName){

            $locale = $this->originLang;

                if(self::APP_BUNDLE_KEY == $bundleName){
                    $bundle = null;
                    $filePattern = $this->srcDir . '../app/Resources/translations/messages.%s.yml';
                }else{
                    $bundle      = $this->getBundleByName($bundleName);
                    $filePattern = $bundle->getPath() . '/Resources/translations/messages.%s.yml';
                }
                $fileName   = sprintf($filePattern, $locale);

                if(file_exists($fileName)){
                    $auxKeys = $this->getYamlAsArray($fileName);
                    $this->output->writeln('<info>Bundle ' . $bundleName . ' . . .</info>');
                    $this->output->writeln(sprintf('<info>Processing "%s", found %d translations</info>', $fileName, count($auxKeys)));
                    $localKeys = array_merge($localKeys, $auxKeys);
                }
        }

        switch($this->format)
        {
            case self::TXT_FORMAT:
                fwrite($this->handler, implode(PHP_EOL, $localKeys) . PHP_EOL);
                break;

            case self::CSV2_FORMAT:
                $raw = '';
                foreach($localKeys as $key=>$data){
                    if(preg_match_all("|(</?[^<]*>)|i", $data, $matches)){
                        foreach($matches[1] as $match){
                            if(!isset($this->labels[$match])){
                                $this->labels[$match] = sprintf("[%d]", count($this->labels) + 1);
                            }
                            $subst = $this->labels[$match];
                            $data = str_replace($match, $subst, $data);
                        }
                    }
                    if(preg_match_all("|(\%([^%]*)\%)|i", $data, $matches, PREG_SET_ORDER)){
                        foreach($matches as $match){
                            $varName = $match[2];
                            $textVar = $match[1];
                            if(!isset($this->labels[$varName])){
                                $this->labels[$textVar] = sprintf("(%d)", count($this->labels) + 1);
                            }
                            $subst = $this->labels[$textVar];
                            $subst = sprintf("%s%s%s", $subst, $varName, $subst);
                            $data = str_replace($textVar, $subst, $data);
                        }
                    }
                    $data = $this->clean($data);
                    $raw .= sprintf('"%s","%s"'.PHP_EOL, $key, $data);
                }
                fwrite($this->handler, $raw);
                break;

            case self::CSV_FORMAT:
                $raw = '';
                foreach($localKeys as $key=>$data){
                    $data = $this->clean($data);
                    $raw .= sprintf('"%s","%s"'.PHP_EOL, $key, $data);
                }
                fwrite($this->handler, $raw);
                break;

            default:
                throw new \Exception(sprintf('format %s not recognized', $this->format));

        }

        fclose($this->handler);

        $words = str_word_count(implode(" ", $localKeys), 0, self::CHARLIST);

        $this->output->writeln(sprintf('found %d keys in total', count($localKeys)));
        $this->output->writeln(sprintf('found %d words in total', $words));

        if($this->format == self::CSV2_FORMAT){
            $this->output->writeln(sprintf('minified %d html labels in total', count($this->labels)));
            file_put_contents($this->outputFile . '.json', json_encode($this->labels));
            file_put_contents($this->outputFile . '.raw', print_r($this->labels, true));
    }
    }


    /**
     * clean data replacing typographic commas and escaping doubles commas
     *
     * @param string $data
     *
     * @return string
     */
    protected function clean($data)
    {
        $data = str_replace(
            array(
                '”','‘','’','´','“','€',"\r","\n",
            ),array(
                '"',"'","'","'",'"','&euro;','','',
            ),$data);

        return str_replace('"', '""', $data);
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
