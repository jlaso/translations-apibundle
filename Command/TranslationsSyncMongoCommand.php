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


/**
 * Sync translations files - translations server.
 *
 * @author Joseluis Laso <jlaso@joseluislaso.es>
 */
class TranslationsSyncMongoCommand extends ContainerAwareCommand
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

    const THROWS_EXCEPTION = true;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('jlaso:translations:sync-mongo');
        $this->setDescription('Sync all translations from translations server.');
        $this->addOption('port', null, InputArgument::OPTIONAL, 'port');
    }

    protected function init($port = 10000)
    {
        /** @var EntityManager */
        $this->em         = $this->getContainer()->get('doctrine.orm.default_entity_manager');
        /** @var ClientSocketService $clientApiService */
        $clientApiService = $this->getContainer()->get('jlaso_translations.client.socket');
        $this->clientApiService = $clientApiService;
        $this->translationsRepository = $this->em->getRepository('TranslationsApiBundle:Translation');
        $this->clientApiService->init($port);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input    = $input;
        $this->output   = $output;

        $this->init($input->getOption('port') ?: 10000);

        $config         = $this->getContainer()->getParameter('translations_api');
        $managedLocales = $config['managed_locales'];

        $this->output->writeln('<info>*** Syncing translations ***</info>');

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

            $this->output->writeln('uploadKeys("' . $catalog . '", $data)');

            $result = $this->clientApiService->uploadKeys($catalog, $data);
        }

        $this->em->flush();

        $this->output->writeln(PHP_EOL . '<info>Flushing translations cache ...</info>');
        $this->getContainer()->get('translator')->removeLocalesCacheFiles($managedLocales);

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
