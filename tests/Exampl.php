<?php
require_once '../vendor/autoload.php';

use Yangwenqu\FileChunk\Download;
use Yangwenqu\FileChunk\Upload;


//分片上传
//服务需要注意nginx配置client_max_body_size
//php设置post_max_size、upload_max_filesize、memory_limit、max_input_time

$file  = new Upload();
$param = $_REQUEST;

$res   = $file->upload([
    'tmp_name'            => $_FILES['file']['tmp_name'], // 文件内容
    'now_package_num'     => $param['blob_num'], // 当前文件包数量
    'total_package_num'   => $param['total_blob_num'], // 文件包总量
    'file_name'           => $param['file_name'], // 文件名称(唯一)
    'file_path'           => './upload', // 文件存放路径
    'clear_interval_time' => 60, // 清理临时文件间隔，默认五分钟
    'is_continuingly'     => true, // 是否断点续传，默认为true
    'tmp_file_chunk'      => '/tmp/file_chunk' // 临时分片存放目录
]);
var_dump($res);die;

//分片下载
$path     = './static/CentOS-7-x86_64-Everything-2009.iso';  //需要下载的文件目录+文件名
$filename = 'CentOS2009.iso';                                //下载保存的文件名
$file     = new Download();
$file->download($path, $filename,true);


