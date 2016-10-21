<?php
header('content-type:text/html;charset=utf-8');
class PdoMySQL{
	
	public static $config = array(); //连接参数|配置信息
	public static $link = null; // 保存连接标识符
	public static $pconnect = false;//是否长链接
	public static $dbVersion = null;//数据库版本
	public static $connected = false;//是否连接成功
	public static $PDOStatement = null;//保存PDOStatement对象
	public static $queryStr = null; //保存最后执行的操作
	public static $error = null; //保存错误信息
	public static $lastInsertId=null; //保存上一步插入操作的id
	public static $numRows=0;//上一步操作影响的记录条数

	public function __construct($dbConfig=''){
		if(!class_exists("PDO")){
			self::throw_exception('不支持PDO，请先开启');
		}
		if(!is_array($dbConfig)){
			$dbConfig = array(
				'hostname' => DB_HOST,
				'username' => DB_USER,
				'password' => DB_PWD,
				'database' => DB_NAME,
				'hostport' => DB_PORT,
				'dbms' =>DB_TYPE,
				'dsn' => DB_TYPE.':host='.DB_HOST.';dbname='.DB_NAME
				);
		}
		if(empty($dbConfig['hostname'])) self::throw_exception('未指定hostname');
		self::$config = $dbConfig;

		if(empty(self::$config['params'])) self::$config['params'] = array();
		if(!isset(self::$link)){
			$configs = self::$config;
			if(self::$pconnect){
				//开启长链接,添加到配置数组中
				$configs['params'][constant("PDO::ATTR_PRESISTENT")] = true;
			}
			try{
				self::$link = new PDO($configs['dsn'],$configs['username'],$configs['password'],$configs['params']);
			}catch(PDOException $e){
				self::throw_exception($e->getMessage());
			}
			if(!self::$link){
				self::throw_exception('PDO连接错误');
				return false;
			}
			self::$link->exec('SET NAMES '.DB_CHARSET);
			self::$dbVersion = self::$link->getAttribute(constant("PDO::ATTR_SERVER_VERSION"));
			self::$connected = true;
			unset($configs);
		}
	}

	
	public static function getALL($sql = null){
		if($sql != null){
			self::query($sql);
		}
		$result = self::$PDOStatement->fetchALL(constant("PDO::FETCH_ASSOC"));
		return $result;
	}

	public static function getRow($sql=null){
		if($sql!=null){
			self::query($sql);
		}
		$result=self::$PDOStatement->fetch(constant("PDO::FETCH_ASSOC"));
		return $result;
	}

	public static function findById($tabName,$priId,$fields='*'){
		$sql='SELECT %s FROM %s WHERE uid=%d';
		// echo sprintf($sql,self::parseFields($fields),$tabName,$priId);
		return self::getRow(sprintf($sql,self::parseFields($fields),$tabName,$priId)); //###sprintf
	}

	public static function find($tables,$where=null,$fields='*',$group=null,$having=null,$order=null,$limit=null){
		$sql='SELECT '.self::parseFields($fields).' FROM '.$tables
		.self::parseWhere($where)
		.self::parseGroup($group)
		.self::parseHaving($having)
		.self::parseOrder($order)
		.self::parseLimit($limit);
		$dataAll=self::getALL($sql);
		return (count($dataAll)==1)?$data[0]:$dataAll;
	}
	//-------------------------------------------------无法防止SQL注入
	public static function parseWhere($where){
		$whereStr='';
		if(is_string($where)&&!empty($where)){
			$whereStr=$where;
		}
		return empty($whereStr)?'':' WHERE '.$whereStr;
	}

	public static function parseGroup($group){
		$groupStr = '';
		if (is_array($group)) {
			$groupStr.=' GROUP BY '.implode(',',$group);
		}elseif(is_string($group)&&!empty($group)){
			$groupStr .=' GROUP BY '.$group;
		}
		return empty($groupStr)?'':$groupStr;
	}

	public static function parseHaving($having){
		$havingStr='';
		if(is_string($having)&&!empty($having)){
			$havingStr .=' HAVING '.$having;
		}
		return $havingStr;
	}

	public static function parseOrder($order){
		$orderStr='';
		if(is_array($order)){
			$orderStr.=' ORDER BY '.join(',',$order);
		}elseif(is_string($order)&&!empty($order)){
			$orderStr .=' ORDER BY '.$order;
		}
		return $orderStr;
	}

	public static function parseLimit($limit){
		$limitStr = '';
		if(is_array($limit)){
			if(count($limit)>1){
				$limitStr .=' LIMIT '.$limit[0].','.$limit[1];
			}else{
				$limitStr .=' LIMIT '.$limit[0];
			}
		}elseif(is_string($limit)&&!empty($limit)){
			$limitStr.=' LIMIT '.$limit;
		}
		return $limitStr;
	}

	// TODO::  underStand this ---------------------------------------------------array_walk
	public static function parseFields($fields){
		if (is_array($fields)){
			array_walk($fields, array('PdoMySQL','addSpecialChar')); 		//###array_walk
			$fieldsStr=implode(',',$fields);
		}elseif(is_string($fields) && !empty($fields)){
			if(strpos($fields,'`')===false){                                // 0== false   0 !==false
				$fields=explode(',',$fields);
				array_walk($fields,array('PdoMySQL','addSpecialChar'));
				$fieldsStr=implode(',',$fields);
			}else{
				$fieldsStr=$fields;
			}
		}else{
			$fieldsStr='*';
		}
		return $fieldsStr;
	}

	public static function addSpecialChar(&$value){
		if($value==='*'||strpos($value,'.')!==false||strpos($value,'`')!==false){
			//TO DO Nothing!	
		}elseif(strpos($value,'`')===false){
			$value='`'.trim($value).'`';
		}
		return $value;
	}


/**
 * 	执行增删改，返回受影响的记录的条数
 */
	public static function execute($sql=null){
		$link=self::$link;
		if(!$link) return false;
		self::$queryStr=$sql;
		if(!empty(self::$PDOStatement)) self::free();
		$result = $link->exec(self::$queryStr);
		self::haveErrorThrowException();
		if ($result){
			self::$lastInsertId=$link->lastInsertId();
			self::$numRows=$result;
			return self::$numRows;
		}else{
			return false;
		}
		
	}



	//释放结果集
	public static function free(){
		self::$PDOStatement = null;
	}

	public static function query($sql = ''){
		$link = self::$link;
		if (!$link) return false;
		//判断之前是否有结果集，若有，释放
		if(!empty(self::$PDOStatement)) self::free();
		self::$queryStr = $sql;
		self::$PDOStatement = $link->prepare(self::$queryStr);
		$res = self::$PDOStatement->execute();
		self::haveErrorThrowException();
		return $res;
	} 

	public static function haveErrorThrowException(){
		$obj = empty(self::$PDOStatement) ? self::$link : self::$PDOStatement;
		$arrError = $obj->errorInfo();
		//print_r($arrError);
		if ($arrError[0] !='00000') {
			self::$error = 'SQLSTAE: '.$arrError[0].' <br/>SQL Error: '.$arrError[2].'<br/>Error SQL: '.self::$queryStr;
			self::throw_exception(self::$error);
			return false;
		}
		if (self::$queryStr == ''){
			self::throw_exception('空的sql语句');
			return false;
 		}
	}



	public static function throw_exception($errMsg){
		echo '<pre>'.$errMsg.'</pre>';
	}
}

require_once 'config.php';
$pdoMysql = new PdoMySQL();


$sql1 = "INSERT INTO `test`.`user` (`uid`, `account`, `pw`, `name`, `age`, `score`) VALUES (NULL, '呵2', '呵3', '呵4', '23', '2332');";
$sql2 = "SELECT * FROM `user` ";
$sql3 = "DELETE FROM `user` WHERE `uid`= 62";
// $tables = 'user';
echo '<pre>';

//var_dump($pdoMysql->execute($sql3));
$tabName = "`user`";
$priId = '3';
$fields = array("uid","pw","na8me","age");
print_r($pdoMysql->findById($tabName,$priId,$fields));
// print_r($pdoMysql->find($tables,null,'*',null,null,null,'3'));
echo '</pre>';
echo '完毕';












