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

    const CHUNK_NUM = "file:chunk_num:%s";
    const FILE_TOTAL_NUM = "file:total_num:%s";
    const FILE_CLEAR_LOCK = "file:clear_lock:%s";
    const FILE_UPLOAD_LOCK = "file:upload_lock:%s";

    private function getChunkNumKey($file_name)
    {
        return sprintf(self::CHUNK_NUM, $file_name);
    }

    private function getTotalNumKey($file_name)
    {
        return sprintf(self::FILE_TOTAL_NUM, $file_name);
    }

    private function getClearLockKey($file_name)
    {
        return sprintf(self::FILE_CLEAR_LOCK, $file_name);
    }
    private function getUploadLockKey($file_name)
    {
        return sprintf(self::FILE_UPLOAD_LOCK, $file_name);
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


    /**
     * 检查服务器空间
     * @return bool
     * @throws \Exception
     */
    public function checkDisk(){

        $disk     = $this->getDisk();
        if (($disk['avail'] <= self::$diskMinSize) ) {
            throw new \Exception("服务器空间不足,请联系客服扩容");
        }
        return true;
    }

    /**
     * 记录文件总片数
     */
    private function setFileTotalPack(){
        $key = $this->getTotalNumKey(self::$fileName);
        $is_has = self::$redis->get($key);
        if(!$is_has){
            self::$redis->setnx($key,self::$totalPackageNum);
        }
    }


    /**
     * 获取锁
     * @return bool
     */
    private function getLock(){

        $islock = self::$redis->setnx($this->getUploadLockKey(self::$fileName),1);
        if($islock){
            self::$redis->expire($this->getUploadLockKey(self::$fileName),5);
            return true;
        }else{
            $s = self::$redis->ttl($this->getUploadLockKey(self::$fileName));
            if($s == -1){
                $this->delLock();
            }
        }
        return false;
    }

    /**
     * 删除锁
     */
    private function delLock(){

        self::$redis->del($this->getUploadLockKey(self::$fileName));
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
        $lock       = $this->getClearLockKey($data['file_name']);
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
        $key      = $this->getClearLockKey($file_name);
        $file     = self::$redis->get($key);
        if ($file) {
            $is_clear = 0;
            self::$redis->setex($key,(int)self::$clearIntervalTime * 60, time());

        }
        return $is_clear;

    }


    /**
     * 记录成功的片
     */
    private function setSuccessChunk(){

        $key = $this->getChunkNumKey(self::$fileName);
        self::$redis->zAdd($key,[self::$nowPackageNum,self::$nowPackageNum]);
    }

    /**
     * 获取文件上传成功的片数
     * @return mixed
     */
    private function getSuccessCount(){

        return self::$redis->zcard($this->getChunkNumKey(self::$fileName));

    }


    /**
     * 获取失败的片
     * @param $file_name  唯一文件名
     */
    public function getFailChunk($file_name){

        $chunk_num_key   = $this->getChunkNumKey($file_name);
        $total_num_key   = $this->getTotalNumKey($file_name);
        //成功片列表
        $success_chunks = self::$redis->zrange($chunk_num_key,0,-1);
        //文件最大片
        $total_num      = self::$redis->get($total_num_key);
        //返回缺少哪些分片
        return ['lack_chunks'=> $this->getDiffNumbers($success_chunks,$total_num)];
    }


    /**
     * 从成功记录中获取差集，也就是未成功的数
     * @param $arr
     * @param $total_num
     * @return array
     *
     */
    public function getDiffNumbers($arr,$total_num){

        $num = [];
        for ($i=1;$i<= $total_num ;$i++){
            if(!in_array($i,$arr))
            {
                $num[] = $i;
            }
        }
        return $num;
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
     * 合并包
     *
     * 2021年1月25日 下午11:58
     */
    private function mergePackage(){

        //成功片数等于总包数时候鉴定为全部上传完成，进行合并
        if($this->getSuccessCount() === self::$totalPackageNum){
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
        if($this->getSuccessCount() === self::$totalPackageNum){
            return self::$pathFileName;
        }
        return self::$nowPackageNum;
    }


    /**
     * 主处理方法
     *
     * 2021年1月25日 下午11:48
     */
    public function upload(array $config=[]) {

        try {

            // 初始化必要参数
            $this->init($config);
            //获取锁,抵抗并发请求
            $islock = $this->getLock();
            if(!$islock){
                throw new \Exception('上传过于频繁');
            }
            //创建目录
            $this->mkdir();
            //记录上传总片数
            $this->setFileTotalPack();
            // 移动包
            $this->movePackage();
            //记录成功片
            $this->setSuccessChunk();
            // 合并包
            $this->mergePackage();
            //释放锁
            $this->delLock();
            // 返回结果
            return $this->result();

        }catch (\Exception $e){
            var_dump($e->getMessage());
            $this->delLock();
            return false;
        }

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
                $key       = $this->getClearLockKey($file_name);
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
