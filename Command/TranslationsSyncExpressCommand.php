<?php

namespace JLaso\TranslationsApiBundle\Command;

use Doctrine\ORM\EntityManager;
use JLaso\TranslationsApiBundle\Entity\Repository\SCMRepository;
use JLaso\TranslationsApiBundle\Entity\SCM;
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
class TranslationsSyncExpressCommand extends ContainerAwareCommand
{
    const COMMENTS = 'comments';

    /** @var InputInterface */
    private $input;
    /** @var OutputInterface */
    private $output;
    /** @var  EntityManager */
    private $em;
    private $srcDir;
    /** @var ClientSocketService */
    private $clientApiService;

    const THROWS_EXCEPTION = true;
    /** fake key to process app/Resources/translations */
    const APP_BUNDLE_KEY = '*app';

    protected $socket;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('jlaso:translations:sync2');
        $this->setDescription('Sync all translations from translations server and merges it into the translations files.');

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
        /** @var ClientApiService $clientApiService */
        $clientApiService = $this->getContainer()->get('jlaso_translations.client.socket');
        $this->clientApiService = $clientApiService;
    }

    /**
     * get remote bundles
     *
     * @return array
     * @throws \Exception
     */
    protected function getRemoteBundles()
    {
        $result = $this->clientApiService->getBundleIndex();
        if($result['result']){
            $remoteBundles = $result['bundles'];
        }else{
            throw new \Exception('error retrieving remote bundles, reason ' . $result['reason']);
        }

        return $remoteBundles;
    }

    /**
     * get keys for a bundle in remote repo
     *
     * @param string $bundle
     * @param bool   $throwsException
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function getKeys($bundle, $throwsException = false)
    {
        $result = $this->clientApiService->getKeyIndex($bundle);
        if($result['result']){
            return $result['keys'];
        }else{
            if($throwsException){
                throw new \Exception('error retrieving keys for bundle '. $bundle. ', reason ' .$result['reason']);
            }
            $this->output->writeln(sprintf('<error>Error retrieving remote keys for bundle %s, reason %s</error>', $bundle, $result['reason']));

            return array();
        }
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
        //$localBundles    = $this->bundles2array($allLocalBundles);
        $remoteBundles   = $this->getRemoteBundles();
        $allBundles      = $remoteBundles;

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

        /**
        foreach ($allBundles as $bundleName)  {
            $this->output->writeln(PHP_EOL . sprintf("<error>%s</error>", $this->center($bundleName)));
            $locale = "en";
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
                $hasChanged = false;
                $localKeys  = $this->getYamlAsArray($fileName);
                $this->output->writeln(sprintf("· · <info>Processing</info> '%s'</info>", $this->fileTrim($fileName)));
            }
        }
        die;
        */

        $allBundles = array('ClientExtranetBundle');

        $this->output->writeln(PHP_EOL . "· · There are these bundles and keys:");

        // doing a array with all the keys of all remote bundles
        $keys = array();
        foreach($allBundles as $bundle){
            $this->output->write(sprintf("\t<info>Bundle %s . . .</info>  ",$bundle));
            $keys[$bundle] = $this->getKeys($bundle, self::THROWS_EXCEPTION);
            $this->output->writeln(sprintf('<error>%d keys in remote</error>', count($keys[$bundle])));
        }
        // adding a fake bundle to process translations from /app/Resources/translations
        $allBundles[self::APP_BUNDLE_KEY] = self::APP_BUNDLE_KEY;

        $this->output->writeln('');

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

                // en cada iteracion el numero de remoteKeys puede crecer porque los diferentes idiomas pueden aportar
                // nuevas claves dentro del mismo bundle
                $remoteKeys = $this->getKeys($bundleName);

                if(!file_exists($fileName)){
                    $this->output->writeln(sprintf("· · <comment>File '%s' not found</comment>", $fileName));
                }else{
//                    $maxDate = new \DateTime(date('c',filemtime($fileName)));
                    $hasChanged = false;
                    $localKeys  = $this->getYamlAsArray($fileName);
                    $this->output->writeln(sprintf("· · <info>Processing</info> '%s', found <info>%d</info> translations", $this->fileTrim($fileName), count($localKeys)));
                    //$this->output->writeln(sprintf("\t|-- <info>getKeys</info> informs that there are %d keys ", count($remoteKeys)));
                    foreach($localKeys as $localKey=>$message){
                        if(isset($remoteKeys[$localKey])){
                            // remove the key to not process it on the second pass
                            unset($remoteKeys[$localKey]);
                        }
                        if($message){
                            $this->output->write(sprintf("\t|-- key %s:%s/%s ... ", $bundleName, $localKey, $locale));
                            $SCM    = $this->updateOrInsertEntry($bundleName, $fileName, $localKey, $locale, $message);
                            $date   = $SCM->getLastModification();
                            if(self::COMMENTS === $locale){
                                $result = $this->clientApiService->updateCommentIfNewest($bundleName, $localKey, $message, $date);
                            }else{
                                $result = $this->clientApiService->updateMessageIfNewest($bundleName, $localKey, $locale, $message, $date);
                            }
                            if(!$result['result']){
                                $this->output->writeln("\t" . sprintf('Error updating key %s, reason %s', $localKey, $result['reason']));
                            }else{
                                $updated = $result['updated'];
                                $this->output->writeln($updated ? "<comment>-> updated</comment>" : '<info>= match</info>');
                                if(!$updated){
                                    // the remote key is newest than local
                                    $newMessage           = $result['message'];
                                    $localKeys[$localKey] = $newMessage;
                                    $hasChanged           = true;
                                    $updatedAt            = new \DateTime($result['updatedAt']);
//                                    if($updatedAt > $maxDate){
//                                        $maxDate = $updatedAt;
//                                    }
                                    $SCM->setContent($newMessage);
                                    $SCM->setLastModification($updatedAt);
                                    $this->em->persist($SCM);
                                }
                            }
                        }
                    }
                }

                if(count($remoteKeys)){
                    $this->output->writeln(sprintf("\t|-- there are %d <comment>remote keys</comment>", count($remoteKeys)));
                    // process the rest of keys that are in remote but not in local
                    foreach($remoteKeys as $remoteKey){
                        $this->output->writeln(sprintf("\t|-- key %s:%s ... <question><- remote</question>", $bundleName, $remoteKey));
                        if(self::COMMENTS === $locale){
                            $result = $this->clientApiService->getComment($bundleName, $remoteKey);
                        }else{
                            $result = $this->clientApiService->getMessage($bundleName, $remoteKey, $locale);
                        }
                        try{
                            if(!$result['result']){
                                $this->output->writeln("\t" . sprintf('<error>Error getting key %s:%s/%s, reason %s</error>', $bundleName, $remoteKey, $locale, $result['reason']));
                            }else{
                                $message    = $result[ (self::COMMENTS === $locale) ? 'comment' : 'message' ];
                                $updatedAt  = new \DateTime($result['updatedAt']);
                                $SCM        = $this->updateOrInsertEntry($bundleName, $fileName, $remoteKey, $locale, $message, $updatedAt);
                                if($SCM->getLastModification()<$updatedAt){
                                    $hasChanged = true;
        //                            if($updatedAt > $maxDate){
        //                                $maxDate = $updatedAt;
        //                            }
                                    $localKeys[$remoteKey] = $message;
                                }
                            }
                        }catch(\Exception $e){
                            die($e->getMessage() . ' on line ' . $e->getLine());
                        }
                    }
                }
                if($hasChanged){
                    $this->dumpYaml($fileName, $localKeys);
                }

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
     * @internal param string $bundle
     * @return SCM
     */
    protected function updateOrInsertEntry($bundleName, $file, $key, $locale, $content, \DateTime $updatedAt = null)
    {
        $shortFile  = str_replace($this->srcDir, '', $file);
        $shortFile  = str_replace('\/', '/', $shortFile);
        $mod        = $updatedAt ?: new \DateTime(date('c',filemtime($file)));
        /** @var SCM $entry */
        $entry = $this->getSCMRepository()->findOneBy(array(
                'bundle' => $bundleName,
                //'file'   => $filename,
                'key'    => $key,
                'locale' => $locale,
            )
        );
        if(!$entry instanceof SCM){
            $entry = new SCM();
            $entry->setFile($shortFile);
            $entry->setBundle($bundleName);
            $entry->setFullpath($file);
            $entry->setKey($key);
            $entry->setContent($content);
            $entry->setLastModification($mod);
            $entry->setLocale($locale);
            $this->em->persist($entry);
            $this->em->flush();
        }else{
            if(($entry->getLastModification()<$mod) || ($entry->getContent() != $content)){
//                if($entry->getLastModification()<$mod){
//                    ld('-->',date('c',filemtime($file)),$entry->getLastModification()->format('c'), $mod->format('c'));
//                }
                $entry->setLastModification($mod);
                $entry->setContent($content);
                $this->em->persist($entry);
                $this->em->flush();
            }
        }

        return $entry;
    }

    /**
     * @return SCMRepository
     */
    protected function getSCMRepository()
    {
        return $this->em->getRepository('TranslationsApiBundle:SCM');
    }

}
