
整合大文件分片上传,下载功能(如果需要在window环境运行,需要修改getChunk方法里的获取硬盘空间方法) 

###### * nginx配置client_max_body_size;
###### * php配置post_max_size、upload_max_filesize、memory_limit、max_input_time

分片上传
===============


 + 自定义文件存储目录
 + 自定义临时分片存储目录
 + 根据文件计算分片大小级文件名
 + 服务器空间检测及预留
 + 超时分片检测
 + 自定义文件名
 + 断点续传配置
 + 定时清理临时文件
 + 非断点上传

断点下载
===============


 + 自定义下载文件路径
 + 自定义下载保存文件名
 + 可配置非断点下载
 + 可配置断点下载
 + 可配置下载限速


## 上传示例代码


~~~

//1.客户端上传文件前进行获取分片大小和唯一文件名
try {
    $file  = new Upload();
    $file->init([
        'redis'               =>[
            'scheme' => 'tcp',
            'host'   => '127.0.0.1',
            'port'   => 6379,
        ],
        'clear_interval_time' => 60,                                // 清理临时文件间隔，默认五分钟
        'disk_min_size'       => 20    // 服务器空间最低剩余空间，超过此空间将不接收分片
    ]);
    $res = $file->getChunk(10 * 1024 * 1024 ,'a.b.c.exe');
    if(!$res){
        echo "服务器空间不足,请联系客服扩容";
    }
    echo $res;  // { ["chunk_size"]=> float(524288) ["file_name"]=> string(42) "27ad9ac14aa35137552ab038e038a6b8-a.b.c.exe" }

}catch (Exception $exception){

    var_dump($exception->getMessage());
    var_dump($exception->getTraceAsString());
}


//2.客户端上传每个分片前请求接口来获取当前文件是否超时,之前的分片是否被清理,如果被请求则拒绝处理。返回客户端错误码，让客户端不再续传剩余分片
$file  = new Upload();
$file->init([
    'redis'               =>
        'scheme' => 'tcp',
        'host'   => '127.0.0.1',
        'port'   => 6379,
    ],
    'clear_interval_time' => 60,                                // 清理临时文件间隔，默认五分钟
]);
$is_clear = $file->check('27ad9ac14aa35137552ab038e038a6b8-a.b.c.exe');
echo $is_clear;


//3.进行接收分片处理
$param = $_REQUEST;
$res   = $file->upload([
    'redis'               =>[
        'scheme' => 'tcp',
        'host'   => '127.0.0.1',
        'port'   => 6379,
    ],                                                          // predis的配置
    'tmp_name'            => $_FILES['file']['tmp_name'],       // 文件内容
    'now_package_num'     => $param['blob_num'],                // 当前文件包数量
    'total_package_num'   => $param['total_blob_num'],          // 文件包总量
    'file_name'           => $param['file_name'],               // 唯一文件名称
    'file_path'           => './upload',                        // 文件存放路径
    'clear_interval_time' => 60,                                // 清理临时文件间隔，默认五分钟
    'is_continuingly'     => true,                              // 是否断点续传，默认为true
    'tmp_file_chunk'      => '/tmp/file_chunk'                  // 临时分片存放目录
]);
if (is_string($res)){
    return "文件合并成功";
}else{
    return "当前分片上传成功,当前分片编号是:{$res}";
}


//4.定时器执行清理碎片
$file  = new Upload();
$file->init([
    'redis'               =>[
        'scheme' => 'tcp',
        'host'   => '127.0.0.1',
        'port'   => 6379,
    ],
    'clear_interval_time' => 60,                                // 清理临时文件间隔，默认五分钟
    'tmp_file_chunk'      => '/tmp/file_chunk'                  // 临时分片存放目录
]);
$file->claerChunk();

~~~

## 下载示例代码

注意：在下载http请求头中加入Range字段 
Range: bytes=start-end  [表示从start读取，一直读取到end位置,第一次请求这里可以1-2000,第二次根据响应中的文件总字节自行计算每次分片下载的字节范围]

~~~

$path     = './static/CentOS-7-x86_64-Everything-2009.iso';  //需要下载的文件目录+文件名
$filename = 'CentOS2009.iso';                                //下载保存的文件名
$file     = new Download();
$file->download($path, $filename,true); 

~~~

## 响应示例

~~~

HTTP/1.1 206 Partial Content     //断点续传http响应码为206
content-length=106786028         //剩余字节
content-range=bytes 2000070-106786027/106786028   //正在下载的字节范围 / 文件总字节
content-type=application/octet-stream    //mime类型:octet-stream 

~~~
