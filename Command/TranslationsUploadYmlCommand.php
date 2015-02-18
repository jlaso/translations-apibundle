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
class TranslationsUploadYmlCommand extends TranslationsBaseCommand
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
        $this->setName('jlaso:translations:upload-yml');
        $this->setDescription('Sync all translations from translations server.');
        $this->addOption('port', null, InputArgument::OPTIONAL, 'port');
        $this->addOption('address', null, InputArgument::OPTIONAL, 'address');
        //$this->addOption('bundle', null, InputArgument::OPTIONAL, 'bundle');
        $this->addOption('yml', null, InputOption::VALUE_REQUIRED, 'yml file to upload', null);
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

        $yml = $this->input->getOption('yml');
        if(!$yml){
            die('yml to upload is mandatory');
        }

        $this->init($input->getOption('address'), $input->getOption('port'));

        $config         = $this->getContainer()->getParameter('translations_api');
        $managedLocales = $config['managed_locales'];

        $this->output->writeln(PHP_EOL . '<info>*** Uploading yml file ***</info>');

        $parts = explode('/', $yml);
        $lastPart = $parts[count($parts)-1];
        preg_match("/^(?<catalog>[^\.]*?)\.(?<locale>[^\.]*?)\.yml$/i", $lastPart, $match);
        $locale = isset($match['locale']) ? $match['locale'] : 'en';
        $catalog  = isset($match['catalog']) ? $match['catalog'] : 'messages';
        preg_match("/(?<bundle>\w*?Bundle)\//i", $yml, $match);
        //$bundle  = $input->getOption('bundle');
        $bundle  = (isset($match['bundle']) ? $match['bundle'] : '');
        //var_dump($match, $locale, $catalog, $bundle); die;
        if(!$bundle){
            die('Incorrect file, it must be in a Bundle folder to deduce its name and the filename');
        }

        $messages = $this->getYamlAsArray($yml);
        //print_r($localKeys); die;

        //$bundles = $this->clientApiService->getBundleIndex();
        //var_dump($bundles); die;

        // data para enviar al servidor
        $data = array();
        $date = date('c');

        $this->output->writeln(PHP_EOL . sprintf('<info>Processing catalog %s ...</info>', $catalog));

        $fileName = preg_replace("/.*\/?src\//", "", $yml);

        foreach($messages as $key => $message){

            $data[$key][$locale] = array(
                'message'   => $message,
                'updatedAt' => $date,
                'fileName'  => $fileName,
                'bundle'    => $bundle,
            );

        }

        //print_r($data); die;
        $this->output->writeln('uploadKeys("' . $catalog . '", $data)');

        $result = $this->clientApiService->uploadKeys($catalog, $data);

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
