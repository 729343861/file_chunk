<?php

namespace Yangwenqu\FileChunk;

use Predis\Client;

class Upload{

    // 临时文件分隔符
    const FILE_SPLIT = '@Split@';

    /**
     * 上传目录
     *
     * @var mixed|string
     * 2021年1月25日 下午11:43
     */
    private static $filePath = '.';

    /**
     * 文件临时目录
     *
     * @var mixed
     * 2021年1月25日 下午11:43
     */
    private static $tmpPath;

    /**
     * 第几个文件包
     *
     * @var mixed
     * 2021年1月25日 下午11:43
     */
    private static $nowPackageNum;

    /**
     * 文件包总数
     *
     * @var mixed
     * 2021年1月25日 下午11:44
     */
    private static $totalPackageNum;

    /**
     * 文件名
     *
     * @var mixed
     * 2021年1月25日 下午11:44
     */
    private static $fileName;

    /**
     * 文件完全地址
     *
     * @var
     * 2021年1月25日 下午11:53
     */
    private static $pathFileName;

    /**
     * 临时分片目录
     * @var
     */
    private static $tmpChunkPath = "/tmp/file_chunk/";

    /**
     * 每次上传的临时文件
     *
     * @var
     * CreateTime: 2019/8/6 下午9:40
     */
    private static $tmpPathFile;

    /**
     * 续传超时时间(超过这个时间将清理零碎分片)：分钟
     *
     * @var int
     * CreateTime: 2019/8/11 上午12:24
     */
    private static $clearIntervalTime= 5;

    /**
     * 是否断点续传
     *
     * @var bool
     * CreateTime: 2019/8/11 上午12:36
     */
    private static $isContinuingly=true;


    /**
     * 服务器预留最小空间:GB
     * @var int
     */
    private static $diskMinSize = 20;

    /**
     * 默认每个分片大小
     * @var float
     */
    private static $defaultChunkSize = 0.5;

    /**
     * redis对象
     * @var object
     */
    private static $redis;


    /**
     * 初始化参数
     *
     * 2021年1月25日 下午11:55
     */
    public function init(array $config=[]) {

        $this->checkDisk();

        if (isset($config['file_path'])) {
            self::$filePath = $config['file_path'];
        }
        if (isset($config['tmp_name'])) {
            self::$tmpPath = $config['tmp_name'];
        }
        if (isset($config['now_package_num'])) {
            self::$nowPackageNum = $config['now_package_num'];
        }
        if (isset($config['total_package_num'])) {
            self::$totalPackageNum = $config['total_package_num'];
        }
        if (isset($config['file_name'])) {
            self::$fileName = $config['file_name'];
        }
        if (isset($config['clear_interval_time'])) {
            self::$clearIntervalTime = $config['clear_interval_time'];
        }
        if (isset($config['is_continuingly'])) {
            self::$isContinuingly = $config['is_continuingly'];
        }
        if(isset($config['tmp_file_chunk'])){
            self::$tmpChunkPath = $config['tmp_file_chunk'];
        }
        if(isset($config['redis']) && is_array($config['redis'])){
            self::$redis = new Client($config['redis']);
        }else{
            throw new \Exception("缺少redis配置项");
        }
        self::$pathFileName = self::$filePath.'/'. self::$fileName;
        self::$tmpPathFile  = self::$tmpChunkPath.'/'.self::$fileName.self::FILE_SPLIT.self::$nowPackageNum;
        $this->mkdir();
        return true;
    }


    /**
     * 获取磁盘剩余空间
     * @return array     array.avail 可用空间(GB), array.usage 空间使用率百分比
     */
    private function getDisk(){

        $fp = popen('df -lh | grep -E "^(/)"',"r");
        $rs = fread($fp,1024);
        pclose($fp);
        $rs = preg_replace("/\s{2,}/",' ',$rs);
        $hd = explode(" ",$rs);
        $hd_avail = trim($hd[3],'G');
        $hd_usage = trim($hd[4],'%');

        return ['avail'=> $hd_avail ,'usage'=> $hd_usage ];
    }


    public function checkDisk(){

        $disk     = $this->getDisk();
        if (($disk['avail'] <= self::$diskMinSize) ) {
            throw new \Exception("服务器空间不足,请联系客服扩容");
        }
        return true;
    }


    /**
     * 获取唯一文件名和分片大小 计算
     * @param $file_size  文件总大小
     * @param $file_name  原文件名
     * @return bool|float[]
     */
    public function getChunk($file_size,$file_name){

        $m = 1024 * 1024 ;
        $g = 1024 * 1024 * 10214;
        $data = [
            'chunk_size' => self::$defaultChunkSize,
        ];
        $disk     = $this->getDisk();
        if ($file_size > ($disk['avail'] - self::$diskMinSize) * $g) {
            return false;
        }
        if ($file_size > 0.1 * $g) {
            $data['chunk_size'] = 1;
        }
        if ($file_size > 1 * $g) {
            $data['chunk_size'] = 2;
        }
        $file_name = hash("md5", $file_name.$file_size) . "-" . $file_name;
        $key       = "file:" . $file_name;
        $has       = self::$redis->get($key);

        if (!$has) {
            $data['file_name'] = $file_name;
        } else {
            $ext                       = substr(strrchr($file_name, '.'), 1);
            $data['file_name'] = basename($file_name, "." . $ext) . "($has)." . $ext;
        }
        $lock       = "file_clear_lock:" . $data['file_name'];
        self::$redis->setex($lock,(int)self::$clearIntervalTime * 60,time());
        self::$redis->incr($key);
        $data['chunk_size'] *= $m;

        return $data;
    }


    /**
     * 检查文件分片是否被情况
     * @param $file_name 唯一文件名
     * @return int 1-文件被清理，0未被清理
     */
    public function check($file_name){

        $is_clear = 1;
        $key      = "file_clear_lock:" . $file_name;
        $file     = self::$redis->get($key);
        if ($file) {
            $is_clear = 0;
            self::$redis->setex($key,(int)self::$clearIntervalTime * 60, time());

        }
        return $is_clear;

    }


    /**
     * 生成哈希文件名
     * @param $file_name
     * @return string
     */
    private function getHash($file_name){

        return hash('md5',$file_name).'.'.substr(strrchr($file_name, '.'), 1);

    }

    /**
     * 主处理方法
     *
     * 2021年1月25日 下午11:48
     */
    public function upload(array $config=[]) {
        // 初始化必要参数
        $this->init($config);
        // 移动包
        $this->movePackage();
        // 合并包
        $this->mergePackage();
        // 检测并删除目录中是否存在过期临时文件
        $this->overdueFile();
        // 返回结果
        return $this->result();
    }

    /**
     * 检测并删除目录中是否存在过期临时文件
     *
     * CreateTime: 2019/8/11 上午12:27
     */
    private function overdueFile() {
        $files = scandir(self::$tmpChunkPath);
        foreach ($files as $key => $val) {
            if (strpos($val,self::FILE_SPLIT) !== false) {
                $ctime = filectime(self::$tmpChunkPath.'/'.$val);
                $intervalTime = time()-$ctime+60*self::$clearIntervalTime;
                if ($intervalTime<0) {
                    @unlink(self::$tmpChunkPath.'/'.$val);
                }
            }
        }
    }

    /**
     * 合并包
     *
     * 2021年1月25日 下午11:58
     */
    private function mergePackage(){
        if(self::$nowPackageNum === self::$totalPackageNum){
            $blob = '';
            for($i=1; $i<= self::$totalPackageNum; $i++){
                $blob = file_get_contents(self::$tmpChunkPath.self::$fileName.self::FILE_SPLIT.$i);
                // 追加合并
                file_put_contents(self::$pathFileName, $blob, FILE_APPEND);
                unset($blob);
            }
            $this->deletePackage();
        }
    }

    /**
     * 删除文件包
     *
     * 2021年1月25日 下午11:59
     */
    private function deletePackage(){

        for($i=1; $i<= self::$totalPackageNum; $i++){
            @unlink(self::$tmpChunkPath.self::$fileName.self::FILE_SPLIT.$i);
        }
    }

    /**
     * 移动文件包
     *
     * 2021年1月25日 下午11:52
     */
    private function movePackage(){
        if (file_exists(self::$tmpPathFile) && self::$isContinuingly) {
            return true;
        }
        move_uploaded_file(self::$tmpPath, self::$tmpPathFile);
    }

    /**
     * 上传结果
     *
     * CreateTime: 2019/8/3 下午1:41
     */
    private function result(){
        if(self::$nowPackageNum === self::$totalPackageNum){
            return self::$pathFileName;
        }
        return self::$nowPackageNum;
    }

    /**
     * 创建目录
     *
     * @return bool
     * 2021年1月25日 下午11:56
     */
    private function mkdir(){

        if(!file_exists(self::$tmpChunkPath)){
            mkdir(self::$tmpChunkPath,0777,true);
        }else{
            chmod(self::$tmpChunkPath,0777);
        }
        if(!file_exists(self::$filePath)){
            return mkdir(self::$filePath,0777,true);
        }

        chmod(self::$filePath,0777);
    }

    /**
     * 清除超时的分片，该方法可以被定时器调用
     */
    public function claerChunk(){

        $files          = scandir(self::$tmpChunkPath);
        foreach ($files as $val){
            if(in_array($val,['.','..'])){
                continue;
            }
            if (strpos($val,self::FILE_SPLIT) !== false) {
                $file_name = explode(self::FILE_SPLIT, $val)[0];
                $key       = "file_clear_lock:" . $file_name;
                $lock      = self::$redis->get($key);
                if($lock){
                    continue ;
                }
                $ctime = filectime(self::$tmpChunkPath.'/'.$val);
                $intervalTime = time()- ($ctime + 60*self::$clearIntervalTime);
                if ($intervalTime > 0) {
                    @unlink(self::$tmpChunkPath.'/'.$val);
                }
            }
        }

    }

}
