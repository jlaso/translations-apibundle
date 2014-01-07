<?php

namespace JLaso\TranslationsApiBundle\Service;

class ClientApiService
{

    protected $api_key;
    protected $api_secret;
    protected $url_plan;
    protected $base_url;
    protected $project_id;

    public function __construct($apiData, $environment)
    {
        $this->api_key    = isset($apiData['key']) ? $apiData['key'] : '';
        $this->api_secret = isset($apiData['secret']) ? $apiData['secret'] : '';
        $this->project_id = $apiData['project_id'];
        $baseUrl          = isset($apiData['url']) ? $apiData['url'] : 'http://localhost/app_dev.php/api/';
        if($environment == 'prod'){
            $baseUrl = str_replace('app_dev.php/', '', $baseUrl);
        }else{
            $baseUrl = str_replace('app_dev.php/', 'app_'.$environment.'.php/', $baseUrl);
        }
        $this->url_plan   = array_merge(array(
                'get_bundle_index'         => $baseUrl . 'bundle/index/%d',
                'get_key_index'            => $baseUrl . 'key/index/%d/%s',
                'get_messages'             => $baseUrl . 'translations/:projectId/:bundle/:key',
                'get_message'              => $baseUrl . 'translation/:projectId/:bundle/:key/:locale',
                'get_comment'              => $baseUrl . 'get/comment/:projectId/:bundle/:key',
                'put_message'              => $baseUrl . 'put/message/:projectId/:bundle/:key/:language',
                'update_message_if_newest' => $baseUrl . 'update/message/if-newest/:projectId/:bundle/:key/:language',
                'update_comment_if_newest' => $baseUrl . 'update/comment/if-newest/:projectId/:bundle/:key',
            ),
            isset($apiData['url_plan']) ? $apiData['url_plan'] : array()
        );
    }

    protected function callService($url, $data = array())
    {
        $data = array_merge(
            array(
                'key'    => $this->api_key,
                'secret' => $this->api_secret,
            ),
            $data
        );
        $postFields = json_encode($data);
        $hdl = curl_init($url);
        curl_setopt($hdl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($hdl, CURLOPT_HTTPHEADER, array('Accept: json'));
        curl_setopt($hdl, CURLOPT_TIMEOUT, 10);
        curl_setopt($hdl, CURLOPT_POST, true);
        curl_setopt($hdl, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($hdl, CURLINFO_CONTENT_TYPE, 'application_json');
        curl_setopt($hdl, CURLOPT_SSL_VERIFYPEER, false);

        $body = curl_exec($hdl);
        $info = curl_getInfo($hdl);
        curl_close($hdl);
        $result = json_decode($body, true);

        if(!count($result)){
            var_dump(substr($body, 0 , 800));
            var_dump($info);
            die;
        }
        
        return $result;
    }

    /**
     * Get bundle index
     *
     * @param $projectId
     *
     * @return array
     */
    public function getBundleIndex($projectId = null)
    {
        $url = sprintf($this->url_plan['get_bundle_index'], $projectId ?: $this->project_id);

        return $this->callService($url);
    }

    /**
     * Get bundle index
     *
     * @param string $bundle
     * @param int    $projectId
     *
     * @return array
     */
    public function getKeyIndex($bundle, $projectId = null)
    {
        $url = sprintf($this->url_plan['get_key_index'], $projectId ?: $this->project_id, $bundle);

        return $this->callService($url);
    }

    /**
     * Get messages for a key
     *
     * @param $bundle
     * @param $key
     * @param $projectId
     *
     * @return array
     */
    public function getMessages($bundle, $key, $projectId = null)
    {
        $url = str_replace(
            array(':projectId',                     ':bundle', ':key' ),
            array($projectId ?: $this->project_id,   $bundle,   $key),
            $this->url_plan['get_messages']);

        return $this->callService($url);
    }

    /**
     * Get message for a bundle, key, locale
     *
     * @param $bundle
     * @param $key
     * @param $locale
     * @param $projectId
     *
     * @return array
     */
    public function getMessage($bundle, $key, $locale, $projectId = null)
    {
        $url = str_replace(
            array(':projectId',                     ':bundle', ':key', ':locale' ),
            array($projectId ?: $this->project_id,   $bundle,   $key,  $locale),
            $this->url_plan['get_message']);

        return $this->callService($url);
    }

    /**
     * Get comment for a bundle, key
     *
     * @param $bundle
     * @param $key
     * @param $projectId
     *
     * @return array
     */
    public function getComment($bundle, $key, $projectId = null)
    {
        $url = str_replace(
            array(':projectId',                     ':bundle', ':key'),
            array($projectId ?: $this->project_id,   $bundle,   $key),
            $this->url_plan['get_comment']);

        return $this->callService($url);
    }

    /**
     * Put message
     *
     * @param $bundle
     * @param $key
     * @param $language
     * @param $message
     * @param $projectId
     *
     * @return array
     */
    public function putMessage($bundle, $key, $language, $message, $projectId = null)
    {
        $data = array(
            'message'    => $message,
        );
        $url = str_replace(
            array(':projectId',                     ':bundle', ':key', ':language'),
            array($projectId ?: $this->project_id,   $bundle,   $key,   $language),
            $this->url_plan['put_message']);

        return $this->callService($url, $data);
    }

    /**
     * Update message if newest
     *
     * @param string    $bundle
     * @param string    $key
     * @param string    $language
     * @param string    $message
     * @param \DateTime $lastModification
     * @param int       $projectId
     *
     * @return array
     */
    public function updateMessageIfNewest($bundle, $key, $language, $message, \DateTime $lastModification, $projectId = null)
    {
        $data = array(
            'message'           => $message,
            'last_modification' => $lastModification->format('c'),
        );
        $url = str_replace(
            array(':projectId',                     ':bundle', ':key', ':language'),
            array($projectId ?: $this->project_id,   $bundle,   $key,   $language),
            $this->url_plan['update_message_if_newest']);

        return $this->callService($url, $data);
    }

    /**
     * Update comment if newest
     *
     * @param string    $bundle
     * @param string    $key
     * @param string    $comment
     * @param \DateTime $lastModification
     * @param int       $projectId
     *
     * @return array
     */
    public function updateCommentIfNewest($bundle, $key, $comment, \DateTime $lastModification, $projectId = null)
    {
        $data = array(
            'comment'           => $comment,
            'last_modification' => $lastModification->format('c'),
        );
        $url = str_replace(
            array(':projectId',                     ':bundle', ':key'),
            array($projectId ?: $this->project_id,   $bundle,   $key),
            $this->url_plan['update_comment_if_newest']);

        return $this->callService($url, $data);
    }

}