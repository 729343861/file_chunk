
整合大文件分片上传,下载功能 

###### * nginx配置client_max_body_size;
###### * php配置post_max_size、upload_max_filesize、memory_limit、max_input_time

分片上传
===============


 + 自定义目录
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
var_dump($res);
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
