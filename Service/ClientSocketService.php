<?php

namespace JLaso\TranslationsApiBundle\Service;

class ClientSocketService
{
    protected $socket;
    protected $api_key;
    protected $api_secret;
    protected $url_plan;
    protected $base_url;
    protected $project_id;
    protected $init = false;

    const ACK    = 'ACK';
    const NO_ACK = 'NO-ACK';
    const BLOCK_SIZE = 1024;

    const DEBUG = false;

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
        $this->base_url   = $baseUrl;
        $this->url_plan   = array_merge(array(
                'get_bundle_index'         => 'bundle-index',
                'get_catalog_index'        => 'catalog-index',
                'get_key_index'            => 'key-index',
                'get_messages'             => 'translations',
                'get_message'              => 'translation-details',
                'get_comment'              => 'get-comment',
                'put_message'              => 'put-message',
                'update_message_if_newest' => 'update-message-if-newest',
                'update_comment_if_newest' => 'update-comment-if-newest',
                'shutdown'                 => 'shutdown',
                'upload_keys'              => 'upload-keys',
                'download_keys'            => 'download-keys',
                'transdoc_index'           => 'transdoc-index',
                'transdoc_sync'            => 'transdoc-sync',
                'transdoc_get'             => 'transdoc-get',
            ),
            isset($apiData['url_plan']) ? $apiData['url_plan'] : array()
        );
    }

    public function init($address = null, $port = null)
    {
        if(!$address && !$port){
            $info = $this->createSocket();
            //sleep(2);

            if(!$info['result']){
                var_dump($info); die;
            }
            $address = $info['host'];
            $port = $info['port'];
        }

        if($this->init){
            socket_close($this->socket);
        }

        if (($this->socket  = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
            echo "socket_create() error: " . socket_strerror(socket_last_error()) . "\n";
        }

        sleep(2);

        echo sprintf("connecting %s port %d", trim($address), intval($port)), PHP_EOL;

        if (socket_connect($this->socket , trim($address), intval($port)) === false) {
            echo "socket_connect() error: " . socket_strerror(socket_last_error($this->socket )) . "\n";
        }

//        if (socket_listen($this->socket , 5) === false) {
//            echo "socket_listen() falló: razón: " . socket_strerror(socket_last_error($this->socket )) . "\n";
//        }
        $out = trim(socket_read($this->socket, 2048, PHP_NORMAL_READ));
        print $out;

        $this->init = true;
    }

    function __destruct()
    {
        if($this->init){
            $this->shutdown();
            //socket_close($this->socket);
        }
    }

    protected function sendMessage($msg, $compress = true)
    {
        if($compress){
            $msg = lzf_compress($msg);
        }else{
            $msg .= PHP_EOL;
        }

        $len = strlen($msg);
        if(self::DEBUG){
            print "sending {$len} chars" . PHP_EOL;
        }

        $blocks = ceil($len / self::BLOCK_SIZE);
        for($i=0; $i<$blocks; $i++){

            $block = substr($msg, $i * self::BLOCK_SIZE,
                ($i == $blocks-1) ? $len - ($i-1) * self::BLOCK_SIZE : self::BLOCK_SIZE);
            $prefix = sprintf("%06d:%03d:%03d:", strlen($block), $i+1, $blocks);
            $aux =  $prefix . $block;
            if(self::DEBUG){
                print sprintf("sending block %d from %d, prefix = %s\n", $i+1, $blocks, $prefix);
            }

            if(false === socket_write($this->socket, $aux, strlen($aux))){
                die('error');
            };

            do{
                $read = socket_read($this->socket, 10, PHP_NORMAL_READ);
                //print $read;
            }while(strpos($read, self::ACK) !== 0);
        }

        return true;
    }

    /**
     * Atomic send of a string trough the socket
     *
     * @param $msg
     *
     * @return int
     */
    protected function send($msg)
    {
        $msg .= PHP_EOL;

        return socket_write($this->socket, $msg, strlen($msg));
    }

    protected function readSocket()
    {
        $buffer = '';
        $overload = strlen('000000:000:000:');
        do{
            $buf = socket_read($this->socket, $overload + self::BLOCK_SIZE, PHP_BINARY_READ);
            if($buf === false){
                echo "socket_read() error: " . socket_strerror(socket_last_error($this->socket)) . "\n";
                return -2;
            }

            if(!trim($buf)){
                return '';
            }

            if(substr_count($buf, ":") < 3){
                var_dump($buf);
                die('error in format');
            }
            list($size, $block, $blocks)  = explode(":", $buf);
            $aux = substr($buf, $overload);

            if(self::DEBUG){
                echo sprintf("%d/%d blocks (start of block %s)\n", $block, $blocks, substr($aux, 0, 10));
            }else{
                echo 'R';
            }

            if($size == strlen($aux)){
                $this->send(self::ACK);
            }else{
                $this->send(self::NO_ACK);
                die(sprintf('error in size (block %d of %d): informed %d vs %d read', $block, $blocks, $size, strlen($aux)));
            }

            $buffer .= $aux;

        }while($block < $blocks);

        $result = lzf_decompress($buffer);

        if(self::DEBUG){
            $aux = json_decode($result, true);
            if(isset($aux['data'])){
                //var_dump($aux);
                echo sprintf("received %d keys\n", count($aux['data']));
            }
        }

        return $result;
    }

    protected function callService($url, $data = array())
    {
        $data = array_merge(
            array(
                'auth.key'    => $this->api_key,
                'auth.secret' => $this->api_secret,
                'command'     => $url,
                'project_id'  => $this->project_id,
            ),
            $data
        );

        $msg = json_encode($data) . PHP_EOL;

        $this->sendMessage($msg);

        $buffer = $this->readSocket();
        //$buffer = trim(socket_read($this->socket, 1024 * 1024, PHP_NORMAL_READ));

        if(self::DEBUG){
            print $buffer;
        }

        $result = json_decode($buffer, true);
        //var_dump($result);
        if(!count($result)){
            var_dump($buffer);
            die;
        }

        return $result;
    }

    protected function shutdown()
    {
        return $this->callService($this->url_plan['shutdown']);
    }

    public function createSocket()
    {
        if(function_exists("lzf_compress")){
            $base = "create-socket/";
        }else{
            $base = "create-socket-no-lzf/";
        }
        $url = $this->base_url . 'create-socket/' . $this->project_id;
        $data = array(
            'key'    => $this->api_key,
            'secret' => $this->api_secret,
        );
        $postFields = json_encode($data);
        $hdl = curl_init($url);
        curl_setopt($hdl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($hdl, CURLOPT_HTTPHEADER, array('Accept: json'));
        curl_setopt($hdl, CURLOPT_TIMEOUT, 10);
        curl_setopt($hdl, CURLOPT_POST, true);
        curl_setopt($hdl, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($hdl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($hdl, CURLINFO_CONTENT_TYPE, 'application_json');
        curl_setopt($hdl, CURLOPT_SSL_VERIFYPEER, false);

        $body = curl_exec($hdl);
        $info = curl_getInfo($hdl);
        curl_close($hdl);
        $result = json_decode($body, true);

        if(!count($result)){
            file_put_contents(dirname(__FILE__) . '/../../../../../../web/last-error.html',$body);
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
        $projectId = $projectId ?: $this->project_id;

        return $this->callService($this->url_plan['get_bundle_index'], array(
                'project_id' => $projectId,
            )
        );
    }

    /**
     * Get catalog index
     *
     * @param $projectId
     *
     * @return array
     */
    public function getCatalogIndex($projectId = null)
    {
        return $this->callService($this->url_plan['get_catalog_index'], array(
                'project_id' => $projectId ?: $this->project_id,
            )
        );
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
        $projectId = $projectId ?: $this->project_id;

        return $this->callService($this->url_plan['get_key_index'], array(
                'project_id' => $projectId,
                'bundle'     => $bundle,
            )
        );
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
        $projectId = $projectId ?: $this->project_id;

        return $this->callService($this->url_plan['get_messages'], array(
                'project_id' => $projectId,
                'bundle'     => $bundle,
                'key'        => $key,
            )
        );
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
        $projectId = $projectId ?: $this->project_id;

        return $this->callService($this->url_plan['get_message'], array(
                'project_id' => $projectId,
                'bundle'     => $bundle,
                'key'        => $key,
                'language'   => $locale,
            )
        );
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
        $projectId = $projectId ?: $this->project_id;

        return $this->callService($this->url_plan['get_comment'], array(
                'project_id' => $projectId,
                'bundle'     => $bundle,
                'key'        => $key,
            )
        );
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
        $projectId = $projectId ?: $this->project_id;

        return $this->callService($this->url_plan['put_message'], array(
                'project_id' => $projectId,
                'bundle'     => $bundle,
                'key'        => $key,
                'language'   => $language,
                'message'    => $message,
            )
        );
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
        $projectId = $projectId ?: $this->project_id;

        return $this->callService($this->url_plan['update_message_if_newest'], array(
                'project_id'        => $projectId,
                'bundle'            => $bundle,
                'key'               => $key,
                'language'          => $language,
                'message'           => $message,
                'last_modification' => $lastModification->format('c'),

            )
        );
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
        $projectId = $projectId ?: $this->project_id;

        return $this->callService($this->url_plan['update_comment_if_newest'], array(
                'project_id'        => $projectId,
                'bundle'            => $bundle,
                'key'               => $key,
                'comment'           => $comment,
                'last_modification' => $lastModification->format('c'),

            )
        );
    }

    public function uploadKeys($catalog, $data, $projectId = null)
    {
        $projectId = $projectId ?: $this->project_id;

        print sprintf("sending %d keys in catalog %s on project %d\n", count($data), $catalog, $projectId);

        return $this->callService($this->url_plan['upload_keys'], array(
                'project_id' => $projectId,
                'catalog'    => $catalog,
                'data'       => $data,
            )
        );
    }

    public function downloadKeys($catalog, $projectId = null)
    {
        return $this->callService($this->url_plan['download_keys'], array(
                'project_id' => $projectId ?: $this->project_id,
                'catalog'    => $catalog,
            )
        );
    }

    public function transDocIndex($projectId = null)
    {
        return $this->callService($this->url_plan['transdoc_index'], array(
                'project_id' => $projectId ?: $this->project_id,
            )
        );
    }

    public function transDocSync($bundle, $key, $locale, $transFile, $document, $updatedAt, $projectId = null)
    {
        return $this->callService($this->url_plan['transdoc_sync'], array(
                'project_id'        => $projectId ? : $this->project_id,
                'bundle'            => $bundle,
                'key'               => $key,
                'locale'            => $locale,
                'file_name'         => $transFile,
                'message'           => $document,
                'last_modification' => $updatedAt,
            )
        );

    }

}