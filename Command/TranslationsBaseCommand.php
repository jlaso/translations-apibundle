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
abstract class TranslationsBaseCommand extends ContainerAwareCommand
{
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
