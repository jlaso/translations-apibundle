<?php


namespace JLaso\TranslationsApiBundle\Tools;


use Symfony\Component\Yaml\Yaml;

class ArrayTools
{

    const SORT_KEYS = true;
    const DO_NOT_SORT_KEYS = false;

    /**
     * keyed associative array to hierarchical associative array
     * example[subkey1.subkey2] to example[subkey1][subkey2]
     * this is a more valuable tool to dump yaml files pretty
     *
     * @param mixed $data
     * @param bool  $sortKeys
     *
     * @return array
     */
    public static function keyedAssocToHierarchical($data, $sortKeys = self::DO_NOT_SORT_KEYS)
    {
        if(self::SORT_KEYS == $sortKeys){
            ksort($data);
        }
        $result = array();
        foreach($data as $key=>$value){
            if($value!==null){
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
     * This helper returns a pretty formatted yaml like
     *
     * key1:
     *     key2: value
     *     key3: value
     *
     * instead of
     * ==========
     * key1.key2: value
     * key1.key3: value
     *
     * @param $data
     *
     * @return string
     */
    public static function prettyYamlDump($data)
    {
        return Yaml::dump(self::keyedAssocToHierarchical($data, self::SORT_KEYS), 100);
    }


    /**
     * associative array indexed to dimensional associative array of keys
     * example[key1][key2][key3]  => example[key1.key2.key3]
     *
     * @param mixed $data
     *
     * @return array
     */
    public static function hierarchicalAssocToKeyed($data)
    {
        $h2k = function(&$dest, $orig, $currentKey) use(&$h2k){
            if(is_array($orig) && (count($orig)>0)){
                foreach($orig as $key=>$value){
                    if(is_array($value)){
                        $h2k($dest, $value, ($currentKey ? $currentKey . '.' : '') . $key);
                    }else{
                        $dest[($currentKey ? $currentKey . '.' : '') . $key] = $value;
                    }
                }
            }
        };

        $result = array();
        $h2k($result, $data, '');

        return $result;
    }

    /**
     * parses a Yaml string and process the keys and returns as a associative indexed array
     *
     * key1:
     *     key2: value1
     *     key3: value2
     *
     * turns into { "key1.key2": value1, "key1.key3": value2 }
     *
     * @param string $yaml
     *
     * @return array
     */
    public static function YamlToKeyedArray($yaml)
    {
        $content = Yaml::parse($yaml);

        return self::hierarchicalAssocToKeyed($content);
    }

}