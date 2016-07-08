<?php
class LgcBPME
{
	//FOR ORM LEVEL
	public static function getDefaultDSN(){
		$dsn="db_app";
		return $dsn;
	}

	const BPM_MODE_FUNC ='FUNC';//[SYNC]: Sync, Func
	const BPM_MODE_DEFAULT ='DEFAULT';//[SYNC]: Sync, Local, Simple
	//const BPM_MODE_LOCALIO ='LOCALIO';//[ASYNC] Sync + LocalFileIO
	//const BPM_MODE_SESSION ='SESSION';//[ASYNC] Session Mode & Mode Sync
	const BPM_MODE_ORM ='ORM';//[ASYNC] ORM Mode using Relative DB e.g. Mysql/Sqlite/MongoDB...
	//const BPM_MODE_REDIS ='REDIS';//[ASYNC] Redis Mode//maybe give up
	//const BPM_MODE_SWOOLE ='SWOOLE';//[ASYNC] using Swoole Server to do the object managerment

	//For BPM_MODE_ORM:
	const DEFAULT_BPME_TABLE='bpme';//引擎管理表 【WORKER THREAD】
	const DEFAULT_BP_TABLE='bp';//bp实例管理表 【实例】
	const DEFAULT_BP_FLOWOBJECT_TABLE='bpflowobject';//bp对象表 【实例】

	//Flow Object Status Constants:
	const FO_STATUS_NEW='NEW';
	const FO_STATUS_LOCK='LOCK';
	const FO_STATUS_DONE='DONE';
	const FO_STATUS_JUMP='JUMP';

	const ERRCODE_NOTFOUNDTASK=60404;
	const DefaultHandleFatalError='defaultHandleFatalError';

	const M_TYPE_PROGRAM = 'Program';
	const M_TYPE_GATEWAY = 'Gateway';
	const M_TYPE_EVENT = 'Event';

	//public function handleFuncMode($bpo, $_p){
	//	println('bpo=');
	//	println($bpo);
	//	$output = array( 'STS'=>'TODO', 'errmsg'=>'handleFuncMode()' );
	//	return $output;
	//}

	//Design:
	//https://www.processon.com/embed/570759a0e4b0dcddf98190f1
	//for CMP, the param could have been put the _c/_m/lang/_s/etc/...
	public function handle($_p, $timeout){

		$rt=array('STS'=>'FATAL');

		$_c=$_p['_c'];
		BpmeTool::checkCond( !$_c, array("_c") );
		BpmeTool::checkCond( strtolower(trim($_c))=='base', null, 'Not Allow Bpmn '.$_c );

		$bpm_mode = $_p['bpm_mode'];
		//if(!$bpm_mode){
		//	throw new Exception("bpm_mode is not config");
		//}

		if($bpm_mode==self::BPM_MODE_FUNC){
			//for func mode
			$bpo = new BpBase('Bp'.$_c);
			//return $this->handleFuncMode($bpo, $_p);
		}else{
			$bpmn_c = 'Bp'.$_c;//safer, don't use it directly
			if( class_exists( $bpmn_c ) ){
				$bpo = new $bpmn_c( $bpmn_c );
			}else{
				throw new Exception("BPMN_NOT_FOUND $bpmn_c");
			}
		}

		$bpm_a=$bpo->bpm_a;

		$properties=$bpm_a['properties'];

		if(!$bpm_mode){
			$bpm_mode = $properties['bpm_mode'];
			if(!$bpm_mode) $bpm_mode= $properties['BpmMode'];
			$bpm_mode = strtoupper($bpm_mode);
		}
		if(!$bpm_mode){
			throw new Exception("bpm_mode is not config");
		}

		$bpm_flow_objects=$bpm_a['all'];

		$_m=$_p['_m'];
		if(!$_m){
			$_m='start';
			//$_m=$properties['start'];
			//if(!$_m) {
			//	println($properties);
			//	throw new Exception('BPMN_DESIGN_ERROR default start not define');
			//}
		}
		//BpmeTool::checkCond( strtolower(trim($_m))=='defaultHandleFatalError', null, 'Not Allow defaultHandleFatalError()');

		//get definition of the _m
		$t=$bpm_flow_objects[$_m];
		//BpmeTool::checkCond(!$t, null, "Unknown _m $_c.$_m");
		if(!$t){
			throw new Exception("$_c.$_m undefined");
		}

		$bpm_entry_channel=$_p['bpm_entry_channel'];
		if($bpm_entry_channel=='WEB'){
			$t_type=$t['type'];
			$t_properties=$t['properties'];
			$AllowWeb=$t_properties['AllowWeb'];
			//$AllowWeb=false;//TEST UserTask Only
			if ($AllowWeb===true || in_array(strtoupper($AllowWeb), array('YES','Y','TRUE'))){
				//PASS if the _m designed as AllowWeb 
			}else{
				if("UserTask"==$t_type){
					//PASS IF UserTask for Web...
				}else{
					//$rt['debug_AllowWeb']=$AllowWeb;
					$rt['errmsg']="$_c.$_m Not Allow Access From $bpm_entry_channel";
					return $rt;
				}
			}
		}

		$bpm_timeout=$_p['bpm_timeout'];
		//if(!$bpm_timeout) $bpm_timeout=getConf("bpm_timeout_default");//Seems no need
		if($bpm_timeout>0){
			set_time_limit($bpm_timeout);
			#NOTES: In PHP-Safe-Mode, Fail to use ini_set() or set_time_limit() to change it.  For that case, edit max_execution_time in the php.ini or not use safe-mode is the only solution.
		}

		///////////////////////  Basic check done.

		$_p['bpm_mode'] = $bpm_mode;

		$flagAutoNotify=true;
		$fo = array(
			'_c'=>$_c,
			'_m'=>$_m,
			'_p'=>$_p,
		);

		$system_code=$param['system_code'];
		if(!$system_code){
			$saas_conf=getConf("saas_conf");
			if($saas_conf)
				$system_code=$saas_conf['tenant_code'];
		}
		if($system_code){
			$fo['system_code']=$system_code;
		}
		if($bpm_timeout){
			$fo['bpm_timeout']=$bpm_timeout;
		}
		$env=array();
		if($_SERVER) $env['SERVER']=$_SERVER;
		if($_SESSION) $env['SESSION']=$_SESSION;
		if($_REQUEST) $env['REQUEST']=$_REQUEST;
		if($_GET) $env['GET']=$_GET;
		if($_POST) $env['POST']=$_POST;
		if($GLOBALS['HTTP_RAW_POST_DATA']) $env['HTTP_RAW_POST_DATA']=$GLOBALS['HTTP_RAW_POST_DATA'];
		if ($env) $fo['env']=$env;

		$BpmeInstance = self::getEngineInstance( $bpm_mode );
		$fo_id = $BpmeInstance->enqueueFlowObject(array(
			"fo"=>$fo,
			"flagAutoNotify"=>$flagAutoNotify,//true will notify() automatically
		));
		if(!$flagAutoNotify) $BpmeInstance->notify("taskEnqueueed", array("fo_id"=>$fo_id));

		//get the result when it stop:
		$query_rt_o = $BpmeInstance->queryLatestResultUntilTimeout(array(
			'fo_id'=>$fo_id,
			'bpm_timeout'=>$bpm_timeout,
		));
		$result = $query_rt_o['result'];

		if (is_string($result)) {print $result;return;}//return $result;

		$rt = array_merge( $rt, (array) $result);

		//TODO ??
		//$next_fo_id = $query_rt_o['next_fo_id'];
		//if($next_fo_id){
		//	$rt['next_fo_id']=$next_fo_id;
		//}

		//if(!$rt['STS']) $rt['STS']='KO';

		//TMP FOR DEBUG:
		$latest_fo = $query_rt_o['fo'];
		$rt['latest_fo']=$latest_fo;

		return $rt;
	}

	protected static $cachedBpmeInstanceArray;
	public static function getEngineInstance( $bpm_mode ){
		if(!self::$cachedBpmeInstanceArray){
			self::$cachedBpmeInstanceArray=array();
		}
		$cachedBpmeInstance = self::$cachedBpmeInstanceArray[ $bpm_mode ] ;
		if( !$cachedBpmeInstance ){

			switch( $bpm_mode ){
			case self::BPM_MODE_DEFAULT:
				$newBpmeIntance = new AppBpmeDefaultMode;
				break;
			case self::BPM_MODE_ORM:
				$newBpmeIntance = new AppBpmeOrmMode;
				break;
			case self::BPM_MODE_FUNC:
				$newBpmeIntance = new AppBpmeFuncMode;
				break;
			default:
				throw new Exception("Unsupport bpm_mode $bpm_mode yet");
			}

			self::$cachedBpmeInstanceArray[ $bpm_mode ] = $newBpmeIntance;
			return $newBpmeIntance;
		}else{
			return $cachedBpmeInstance;
		}
	}
}

