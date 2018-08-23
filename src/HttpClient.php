<?php
namespace http;

class HttpClient
{
    const DEFAULT_SLOW_TIME = 1;
    const SLOW_TIME_PERCENT = 0.6;

    public  $proxy;
    public  $logger;
    public  $domain;
    public  $timeout;
    public  $timeout_ms;
    public  $gzip;
    public  $port;
    public  $caller;
    public  $server;
    public  $traceid;
    private $ch;
    protected $last_resp = null;
    private $origin_result = false;

    public function  __construct($domain,$logger,$proxy=null,$port=80,$timeout=1,$caller="unknow",$server=null,$origin_result=false,$timeout_ms=null,$gzip=false)
    {
        $this->domain           = $domain;
        $this->logger           = $logger;
        $this->proxy            = $proxy;
        $this->timeout          = $timeout;
        $this->timeout_ms       = $timeout_ms;
        $this->gzip             = $gzip;
        $this->port             = $port;
        $this->caller           = $caller;
        $this->server           = $server ;
        $this->origin_result    = $origin_result;

        $this->ch      = curl_init();
    }
    public function setTraceid($value)
    {
        $this->traceid = $value;
    }
    public function getTraceid()
    {
        if ($this->traceid) {
            return $this->traceid;
        }
        if (!empty($_SERVER["HTTP_TRACEID"])) {
            return $_SERVER["HTTP_TRACEID"];
        }
        return null;
    }
    public function __destruct()
    {
        curl_close($this->ch);
    }
    private function makeURL($url)
    {
        $server = $this->domain ;
        if(! empty($this->server) )$server = $this->server ;
        if($this->port && $this->port != 80){
            $url = "http://$server:{$this->port}{$url}";
        }else{
            $url = "http://" . $server. $url;
        }
        return $url ;
    }
    /**
     * @brief GET 调用
     *
     * @param $url
     * @param $timeout
     *
     * @return
     */
    public function get($url,$timeout=0)
    {
        $url = $this->makeURL($url);
        curl_setopt($this->ch,CURLOPT_CUSTOMREQUEST,"GET");
        $r = $this->callRemote('GET',$url,$timeout);
        return $r;
    }
    /**
     * @brief PUT 调用
     *
     * @param $url
     * @param $data
     * @param $timeout
     *
     * @return
     */
    public function put($url,$data,$timeout=0)
    {
        if(is_array($data)){
            $data = http_build_query($data);
        }
        $url = $this->makeURL($url);
        curl_setopt($this->ch,CURLOPT_CUSTOMREQUEST,"PUT");
        curl_setopt($this->ch,CURLOPT_HTTPHEADER,array('Content-Length: '.strlen($data)));
        curl_setopt($this->ch,CURLOPT_POSTFIELDS,$data);
        $this->logger->info("[put] $data");
        return $this->callRemote('PUT',$url,$timeout);
    }
    /**
     * @brief POST　调用　
     *
     * @param $url
     * @param $data
     * @param $timeout
     *
     * @return
     */
    public function post($url,$data,$timeout=0)
    {
        if(is_array($data)){
            $data = http_build_query($data);
        }
        $url = $this->makeURL($url);
        curl_setopt($this->ch,CURLOPT_CUSTOMREQUEST,"POST");
        curl_setopt($this->ch,CURLOPT_HTTPHEADER,array('Content-Length: '.strlen($data)));
        curl_setopt($this->ch,CURLOPT_POSTFIELDS,$data);
        $this->logger->info("[put] $data");
        return $this->callRemote('POST',$url,$timeout);
    }

    /**
     * @brief del 调用
     *
     * @param $url
     * @param $timeout
     *
     * @return
     */
    public function delete($url,$timeout=0)
    {
        $url = $this->makeURL($url);
        curl_setopt($this->ch,CURLOPT_CUSTOMREQUEST,"DELETE");
        return $this->callRemote('DELETE',$url,$timeout);
    }

    public function complex_delete($url,$data,$timeout=0)
    {
        $url = $this->makeURL($url);
        curl_setopt($this->ch,CURLOPT_CUSTOMREQUEST,"DELETE");
        curl_setopt($this->ch,CURLOPT_HTTPHEADER,array('Content-Length: '.strlen($data)));
        curl_setopt($this->ch,CURLOPT_POSTFIELDS,$data);
        return $this->callRemote('delete',$url,$timeout);
    }
    private function bindCaller($url)
    {
        if(strstr($url,'?'))
        {
            $url = "$url&_caller=" . $this->caller ;
        }
        else
        {
            $url = $url . "?_caller=" . $this->caller;
        }
        return $url;
    }
    private function callRemote($method,$url,$timeout=0)
    {/*{{{*/
        $url = $this->bindCaller($url);
        $stime=microtime(true);

        $header = array("Host:" .$this->domain);

        if ($this->getTraceid()) {
            $header[] = "Traceid:" . $this->getTraceid();
        }
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_PORT, $this->port);

        //cURL小于7.16.2版本，不支持毫秒超时
        if ($this->timeout_ms > 0 && ! defined('CURLOPT_TIMEOUT_MS'))
        {
            $this->timeout_ms = null;
            $this->logger->error("TIMEOUT_MS need cURL 7.16.2.");
        }

        if ($this->timeout_ms > 0)
        {
            curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT_MS, $this->timeout_ms);
            curl_setopt($this->ch, CURLOPT_TIMEOUT_MS, $this->timeout_ms);
            $timeout_info = $this->timeout_ms.'(ms)';
        }
        else
        {
            $timeout = $timeout > 0 ? $timeout : $this->timeout;
            curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($this->ch, CURLOPT_TIMEOUT, $timeout);
            $timeout_info = $timeout.'(s)';
        }

        if ($this->gzip)
        {
            curl_setopt($this->ch, CURLOPT_ENCODING, 'gzip,deflate');
        }

        if(!empty($proxy))
        {
            curl_setopt($this->ch, CURLOPT_PROXY, $this->proxy);
            $this->logger->info("[proxy] ".$this->proxy);
        }

        $port =  $this->port;
        $this->logger->info("[$port:$method,timeout:$timeout_info] url: $url");
        $this->logger->info("curl -X $method -H\"Host:{$this->domain}\" \"$url\" ");
        if (!empty($this->proxy))
        {
            curl_setopt($this->ch, CURLOPT_PROXY, $this->proxy);
        }
        $r          = curl_exec($this->ch);
        $this->last_resp = $r;
        $errono     = curl_errno($this->ch);
        if ($errono !=0 )
        {
            if(TIMEOUT_ERROR == $errono)
            {
                $errMsg = curl_error($this->ch);
                $this->logger->error("$url timeout: ".$errMsg);
            }
            else
            {
                $errMsg = curl_error($this->ch);
                $this->logger->error("$url curlerr: ".$errMsg);
            }
            $this->logger->error("[slow] errmsg: $errMsg, timeout: $timeout_info,  port: $port, method: $method, url: $url ");

            return false;
        }

        $http_code = curl_getinfo($this->ch,CURLINFO_HTTP_CODE);
        $etime=microtime(true);
        $usetime=sprintf("%.3f", $etime-$stime);
        $this->logger->info("[response] code: $http_code, usetime: $usetime, url:$url");
        $slowtime = self::DEFAULT_SLOW_TIME;
        if (!empty($this->timeout)) {
            $slowtime = $this->timeout * self::SLOW_TIME_PERCENT;
        }
        if($usetime > $slowtime)
        {
            $slowmsg = "[slow] usetime: $usetime(s), code: $http_code, timeout: $timeout_info, port: $port, method: $method, url: $url ";

            $this->logger->info($slowmsg);
        }
        if ($http_code == 200 || $http_code == 201)
        {
            $this->logger->info("[response] $r");
            return $r;
        }

        $this->logger->error("$url [response] $r");
        if ($this->origin_result == true)
        {
            return $r;
        }
        return false;
    }/*}}}*/

}

