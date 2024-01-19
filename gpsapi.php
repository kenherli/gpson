		<?php
	header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: true');
    header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");


	// Параметры подключения к БД - убрать отсюда

	$config = [
		'db_host' => 'localhost',
		'db_name' => 'gps',
		'db_user' => 'mysqladmin',
		'db_password' => 'inohf7Foo3ge',
		'gpsadmin_bot_token' => '6765491066:AAEKYPZDxnYZqt6pybMQhfth54UOlBlmM78',
		'telegram_admin_chat_id' => '-1002046951624'
	];

	$basic_url = 'https://gpson.ru/';

	function telegram_message($chat_id,$text)	// отправить сообщение от бота в чат
	{	
		GLOBAL $config;
		
		$curl = curl_init();
			curl_setopt_array($curl, array(
				CURLOPT_URL => "https://api.telegram.org/bot".$config['gpsadmin_bot_token']."/sendMessage?chat_id=".$chat_id."&text=".$text,
				CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => "", CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 4,CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_CUSTOMREQUEST => "GET",
				CURLOPT_HTTPHEADER => array( "Content-Type: application/JSON"	),
			));
			
			$response = curl_exec($curl);
			return $response;
		
	}
	
	function die_with_log($text)	// die с информированием в тревожный телеграм-канал
	{	
		GLOBAL $config;
		GLOBAL $method;
		
		telegram_message($config['telegram_admin_chat_id'],"$method ❌❌❌ ".$text."💡".$_SERVER ['PHP_AUTH_USER']."💡 ↘️".json_encode($_GET));	// тревожное сообщение в телеграм-чат-админку
		die($text);
		
	}
	
	function exit_ok_with_log($text)	// exit ok с информированием в тревожный телеграм-канал
	{	
		GLOBAL $config;
		GLOBAL $method;
		
		telegram_message($config['telegram_admin_chat_id'],"$method ✅ ".$text." ↘️ ".json_encode($_GET).'');	// хорошее сообщение в телеграм-чат-админку
		exit($text);
		
	} 


	function ExecSQL($link, $query)
	{
		$dataset = $link->query(($query));
		$answer = [];

		if (is_object($dataset)) {
			while (($row = $dataset->fetch_assoc()) != false) {
				$answer[] = $row;
			}
		} else {
			$answer = $link->insert_id;
		}

		if (!is_bool($dataset)) {
			$dataset->free();
		}

		return $answer;
	}

	function logg($method, $text): void
	{
		file_put_contents('logg.txt', date('[Y-m-d H:i:s] ')  .$method.' # '.$text. PHP_EOL, FILE_APPEND | LOCK_EX);
	}

	function verify ()
	{
			GLOBAL $link;
			
			
			$username = 	$_SERVER ['PHP_AUTH_USER'];
			$password = 	$_SERVER ['PHP_AUTH_PW'];			
			if (isset($_GET ['company_id'])) $company_id = 	$_GET ['company_id']; else $company_id = 1;			/////////////////          КОСТЫЛЬ!!!!!!!!!!!!!!!!!!!!!!
			
			$res = ExecSQL ($link,"
			SELECT `users_roles`.`company_id` as company_id, `users_roles`.`user_id` as user_id, `users_roles`.`user_role` as user_role
				FROM `users` 
				JOIN `users_roles` ON `users_roles`.`user_id`=users.id
				WHERE `user_email`='$username' AND `password`='$password' AND `users_roles`.`company_id`='$company_id' 
				LIMIT 1
			");
			
			
			
			if (count($res)==0) die_with_log(json_encode(['status'=>'error', 'message'=>'Autorization fault.'])); 
			return ($res[0]); 
	}



	function calcDistance($departureLatitude, $departureLongitude, $arrivalLatitude, $arrivalLongitude)
	{
		$a1 = deg2rad($arrivalLatitude);
		$b1 = deg2rad($arrivalLongitude);
		$a2 = deg2rad($departureLatitude);
		$b2 = deg2rad($departureLongitude);
		$res = 2 * asin(sqrt(pow(sin(($a2 - $a1) / 2), 2) + cos($a2) * cos($a1) * pow(sin(($b2 - $b1) / 2), 2)));
		return 6371008 * $res;
	}

	$link = new mysqli($config['db_host'], $config['db_user'], $config['db_password'], $config['db_name']);
	$link->set_charset('utf8mb4');
	if ($link->connect_error) {   die_with_log(json_encode(['status'=>'error', 'message'=>'Ошибка при подключении к БД: ' . $link->connect_error])); }

	$method = explode('/', $_SERVER ['PATH_INFO'])[1];
	//logg($method,' вызван метод');

	if ($method=='get_settings')
	{
		GLOBAL $basic_url;
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		$res['company'] = 	ExecSQL($link,"SELECT id as company_id,name,CONCAT('$basic_url~',short_link) as short_link,`balance`,`currency` FROM `companies` WHERE `id`='$company_id' LIMIT 1")[0];
		
		$res['users'] = ExecSQL($link,"SELECT `users`.`id` as user_id,`user_email`,`user_role` FROM `users` JOIN `users_roles` ON `users`.`id`=`users_roles`.`user_id` WHERE `company_id`='$company_id';");

		ExecSQL($link,"SELECT id as user_id,user_email,user_role FROM `users` WHERE `company_id`='$company_id'");
		$res['cars'] = 		ExecSQL($link,"SELECT id as car_id,name,CONCAT('$basic_url',pic) as pic,imei,alter_imei FROM `cars` WHERE `company_id`='$company_id';");
		$res['points'] = 	ExecSQL($link,"SELECT id as point_id,name,lat,lng,address,radius FROM `points` WHERE `company_id`='$company_id';");
		$res['events'] = 	ExecSQL($link,"SELECT events.id as event_id,company_id,car_id,point_id,event,time_response_sec FROM `events` JOIN cars on cars.id=car_id WHERE `company_id`='$company_id';");
		$res['type_of_events'] = array('IN','OUT');
		$res['icons'] = 	ExecSQL($link,"SELECT id as icon_id,CONCAT('$basic_url','pics/',url) as url FROM `icons`");
		$res['roles'] = 	array (['role'=>'admin', 'ru'=>'Администратор'],['role'=>'user', 'ru'=>'Пользователь']); 
		exit_ok_with_log (json_encode($res));
	}

	if ($method=='save_company_name')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		if ($this_user_role!='admin') die_with_log(json_encode(['status'=>'error', 'message'=>'The user has no right to do this.'])); 
		$new_company_name = $_GET['company_name'];
		if (strlen($new_company_name)<2) die_with_log(json_encode(['status'=>'error', 'message'=>'Company name is too short.'])); 
		// проверить на отсутствие инъекций
		ExecSQL($link,"UPDATE `companies` SET `name`='$new_company_name' WHERE `id`='$company_id'");
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Ok'])); 
	}

	if ($method=='create_company')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		if ($this_user_role!='admin') die_with_log(json_encode(['status'=>'error', 'message'=>'The user has no right to do this.'])); 
		$new_company_name = $_GET['company_name'];
		if (strlen($new_company_name)<2) die_with_log(json_encode(['status'=>'error', 'message'=>'Company name is too short.'])); 
		// проверить на отсутствие инъекций
		$company_id = ExecSQL($link,"INSERT INTO `companies` (`name`,`balance`,`currency`) VALUES ('$new_company_name',0,'RUB') ");
		
		
		if (count(ExecSQL($link,"SELECT id FROM `users_roles` WHERE `company_id`='$company_id' AND `user_id`='$this_user_id';"))==0)
			$link->query("INSERT INTO `users_roles` (`user_id`, `user_role`, `company_id`) VALUES ('$this_user_id','admin','$company_id');"); 		
		else
			$link->query("UPDATE `users_roles` SET `user_role`='admin' WHERE `company_id`='$company_id' AND `user_id`='$this_user_id';"); 		
		
		//die("SELECT `id`,`name`,`balance`,`currency` FROM `companies` WHERE `id`='$company_id'");
		$res = ExecSQL($link,"SELECT `id`,`name`,`balance`,`currency` FROM `companies` WHERE `id`='$company_id'");
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Added', 'companies'=>$res])); 
	}
	if ($method=='delete_company')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		if ($this_user_role!='admin') die_with_log(json_encode(['status'=>'error', 'message'=>'The user has no right to do this.'])); 
		
		if (count(ExecSQL($link,"SELECT id FROM `companies` WHERE `company_id`='$company_id';"))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'Incorrect company id.'])); 
		if (count(ExecSQL($link,"SELECT id FROM `cars` WHERE `company_id`='$company_id';"))>0) die_with_log(json_encode(['status'=>'error', 'message'=>'The company has cars. Removal is not possible.'])); 
		ExecSQL($link,"DELETE FROM`companies` WHERE `id`='$company_id'");
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Ok'])); 
	}
	if ($method=='refresh_balance')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		$balance = ExecSQL($link,"SELECT `balance`,`currency` FROM `companies` WHERE `id`='$company_id'")[0];
		exit_ok_with_log (json_encode(['status'=>'Ok', 'balance'=>$balance['balance'],'currency'=>$balance['currency']])); 	
	}
	

	if ($method=='delete_short_link')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		if ($this_user_role!='admin') die_with_log(json_encode(['status'=>'error', 'message'=>'The user has no right to do this.'])); 
		ExecSQL($link,"UPDATE `companies` SET `short_link`=NULL WHERE `id`='$company_id'");
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Ok'])); 
	}

	if ($method=='refresh_short_link' OR $method=='create_short_link')
	{
		GLOBAL $basic_url;
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		if ($this_user_role!='admin') die_with_log(json_encode(['status'=>'error', 'message'=>'The user has no right to do this.'])); 
		$permitted_chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		do 
			{
				$new_short_link = substr(str_shuffle($permitted_chars), 0, 10);
				$nol = ExecSQL($link,"SELECT * FROM `companies` WHERE `short_link`='$new_short_link'");
				
			} 
		while (count($nol) > 0);
		ExecSQL($link,"UPDATE `companies` SET `short_link`='$new_short_link' WHERE `id`='$company_id'");
		exit_ok_with_log (json_encode(['status'=>'Ok', 'short_link'=>$basic_url.'~'.$new_short_link])); 	
	}

	if ($method=='delete_car')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		$car_id = $_GET['car_id'];
		if (count(ExecSQL($link,"SELECT id as car_id FROM `cars` WHERE `id`='$car_id' AND `company_id`='$company_id';"))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'The car is not exists.']));
		if (count(ExecSQL($link,"SELECT id FROM `events` WHERE `car_id`='$car_id';"))>0) die_with_log(json_encode(['status'=>'error', 'message'=>'An event has been scheduled for the vehicle. Removal is not possible. First you need to delete the event.']));
		
		ExecSQL($link,"DELETE FROM `cars` WHERE `company_id`='$company_id' AND `id`='$car_id';");
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Ok'])); 	
	}

	if ($method=='create_car')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		$car_name 	= $_GET['car_name'];
		$icon 		= $_GET['icon'];
		$icon = str_replace($basic_url,'',$icon);

		$imei 		= $_GET['imei'];
		$alter_imei = $_GET['alter_imei'];
		
		
		if (strlen($car_name)<2) die_with_log(json_encode(['status'=>'error', 'message'=>'Car name is too short.'])); 
		if (strlen($imei)!=15) die_with_log(json_encode(['status'=>'error', 'message'=>'Imei hasnt 15 characters.'])); 
		if (count(ExecSQL($link,"SELECT id as car_id FROM `cars` WHERE `imei`='$imei' OR `alter_imei`='$imei';"))>0) die_with_log(json_encode(['status'=>'error', 'message'=>'Imei is already in use.']));
		if (isset($alter_imei)) if (strlen($alter_imei)!=0) if (strlen($alter_imei)!=15) die_with_log(json_encode(['status'=>'error', 'message'=>'Imei-2 hasnt 15 characters.'])); 
		if (isset($alter_imei)) if (strlen($alter_imei)!=0) 
					if (count(ExecSQL($link,"SELECT id as car_id FROM `cars` WHERE `imei`='$alter_imei' OR `alter_imei`='$alter_imei';"))>0) die_with_log(json_encode(['status'=>'error', 'message'=>'alter_imei is already in use.']));
		
		$link->query("INSERT INTO `cars` (`company_id`, `name`, `pic`, `imei`, `alter_imei`) VALUES ('$company_id', '$car_name', '$icon', '$imei', '$alter_imei');"); 
		$car_id = $link->insert_id;
		$res = ExecSQL($link,"SELECT id as car_id,name,pic,imei,alter_imei as alter_imei FROM `cars` WHERE `id`='$car_id';")[0];
	
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Added', 'car'=>$res])); 
	}
	
	if ($method=='save_car')
	{
		
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		$car_id = $_GET['car_id'];
		$car_name 	= $_GET['car_name'];
		$icon 		= $_GET['icon'];
		
		$icon = str_replace($basic_url,'',$icon);
		
		$imei 		= $_GET['imei'];
		$alter_imei = $_GET['alter_imei'];
		
		
		if (count(ExecSQL($link,"SELECT id as car_id FROM `cars` WHERE `id`='$car_id' AND `company_id`='$company_id';"))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'The car is not exists.']));
		if (strlen($car_name)<2) die_with_log(json_encode(['status'=>'error', 'message'=>'Car name is too short.'])); 
		if (strlen($imei)!=15) die_with_log(json_encode(['status'=>'error', 'message'=>'Imei hasnt 15 characters.'])); 
		if (count(ExecSQL($link,"SELECT id as car_id FROM `cars` WHERE (`imei`='$imei' OR `alter_imei`='$imei') AND `id`<>$car_id;"))>0) die_with_log(json_encode(['status'=>'error', 'message'=>'Imei is already in use.']));
		if (isset($alter_imei)) if (strlen($alter_imei)!=0) if (strlen($alter_imei)!=15) die_with_log(json_encode(['status'=>'error', 'message'=>'alter_imei hasnt 15 characters.'])); 
		if (isset($alter_imei)) if (strlen($alter_imei)!=0) 
					if (count(ExecSQL($link,"SELECT id as car_id FROM `cars` WHERE (`imei`='$alter_imei' OR `alter_imei`='$alter_imei' ) AND `id`<>$car_id;"))>0) die_with_log(json_encode(['status'=>'error', 'message'=>'alter_imei is already in use.']));
		
		$link->query("UPDATE `cars` SET `name`='$car_name', `pic`='$icon', `imei`='$imei', `alter_imei`='$alter_imei' WHERE `id`='$car_id';");
		$res = ExecSQL($link,"SELECT id as car_id,name,pic,imei,alter_imei as alter_imei FROM `cars` WHERE `id`='$car_id';");
		
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Updated', 'car'=>$res])); 
	}

	if ($method=='delete_point')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		$point_id = $_GET['point_id'];
		if (count(ExecSQL($link,"SELECT id as point_id FROM `points` WHERE `id`='$point_id' AND `company_id`='$company_id';"))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'The point is not exists.']));
		if (count(ExecSQL($link,"SELECT id FROM `events` WHERE `point_id`='$point_id';"))>0) die_with_log(json_encode(['status'=>'error', 'message'=>'An event has been scheduled for the point. Removal is not possible. First you need to delete the event.'])); 

		
		ExecSQL($link,"DELETE FROM `points` WHERE `company_id`='$company_id' AND `id`='$point_id';");
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Ok'])); 
	}

	if ($method=='create_point')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		$point_name = $_GET['point_name'];
		$address	= $_GET['address'];
		$lat 		= $_GET['lat'];
		$lng 		= $_GET['lng'];
		$radius 	= $_GET['radius'];
		
		
		if (strlen($point_name)<2) die_with_log(json_encode(['status'=>'error', 'message'=>'point name is too short.'])); 
		if (strlen($address)<2) die_with_log(json_encode(['status'=>'error', 'message'=>'address is too short.'])); 
		if (!($radius>0)) die_with_log(json_encode(['status'=>'error', 'message'=>'Radius is not >0.'])); 
		
		$link->query("INSERT INTO `points` (`company_id`, `name`, `lat`, `lng`, `address`, `radius`) VALUES ('$company_id', '$point_name', '$lat', '$lng', '$address', '$radius');"); 
		$point_id = $link->insert_id;
		$res = ExecSQL($link,"SELECT id as point_id,name,lat,lng,address,radius FROM `points` WHERE `id`='$point_id';")[0];
	
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Added', 'point'=>$res])); 
	}
	
	if ($method=='save_point')
	{
		
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		$point_id = $_GET['point_id'];
		$point_name = $_GET['point_name'];
		$address	= $_GET['address'];
		$lat 		= $_GET['lat'];
		$lng 		= $_GET['lng'];
		$radius 	= $_GET['radius'];

		
		
		if (count(ExecSQL($link,"SELECT id as point_id FROM `points` WHERE `id`='$point_id' AND `company_id`='$company_id';"))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'The point is not exists.']));
		if (strlen($point_name)<2) die_with_log(json_encode(['status'=>'error', 'message'=>'point name is too short.'])); 
		if (strlen($address)<2) die_with_log(json_encode(['status'=>'error', 'message'=>'address is too short.'])); 
		if (!($radius>0)) die_with_log(json_encode(['status'=>'error', 'message'=>'Radius is not >0.'])); 

		
		$link->query("UPDATE `points` SET `name`='$point_name', `lat`='$lat', `lng`='$lng', `address`='$address', `radius`='$radius' WHERE `id`='$point_id';");
		$res = ExecSQL($link,"SELECT id as point_id,name,lat,lng,address,radius FROM `points` WHERE `id`='$point_id';")[0];
		
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Updated', 'point'=>$res])); 
	}
	

	if ($method=='delete_user')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		if ($this_user_role!='admin') die_with_log(json_encode(['status'=>'error', 'message'=>'The user has no right to do this.'])); 
		
		$current_user=1; //$current_user присвоить!
		
		$user_id = $_GET['user_id'];
		if (count(ExecSQL($link,"SELECT id as user_id FROM `users` WHERE `id`='$user_id' "))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'The user is not exists.']));
		if ($user_id==$current_user) die_with_log(json_encode(['status'=>'error', 'message'=>'You cant delete yourself.'])); 
		
		ExecSQL($link,"DELETE FROM `users_roles` WHERE `user_id`='$user_id';");
		ExecSQL($link,"DELETE FROM `users` WHERE `id`='$user_id';");
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Ok'])); 
	}

	if ($method=='create_user')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		if ($this_user_role!='admin') die_with_log(json_encode(['status'=>'error', 'message'=>'The user has no right to do this.'])); 
		$user_email	= $_GET['user_email'];
		$user_role 	= $_GET['user_role'];
		
		if (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) die_with_log(json_encode(['status'=>'error', 'message'=>'Incorrect email.'])); 
		if (strlen($user_email)<2) die_with_log(json_encode(['status'=>'error', 'message'=>'address is too short.'])); 
		if ($user_role!='admin' AND $user_role!='user') die_with_log(json_encode(['status'=>'error', 'message'=>'Incorrect user role.'])); 
		if (count(ExecSQL($link,"SELECT id as user_id FROM `users` WHERE `user_email`='$user_email'"))>0) die_with_log(json_encode(['status'=>'error', 'message'=>'The user is already exists.']));

		$link->query("INSERT INTO `users` (`user_email`) VALUES ('$user_email');"); 
		$user_id = $link->insert_id;
		$link->query("INSERT INTO `users_roles` (`user_id`, `user_role`, `company_id`) VALUES ('$user_id','$user_role','$company_id');"); 
		
		$res = ExecSQL($link,"SELECT `users`.`id` as user_id,`user_email`,`user_role` FROM `users` JOIN `users_roles` ON `users`.`id`=`users_roles`.`user_id` WHERE `users`.`id`='$user_id';");
	
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Added', 'user'=>$res])); 
	}
	
	if ($method=='save_user')
	{
		
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		if ($this_user_role!='admin') die_with_log(json_encode(['status'=>'error', 'message'=>'The user has no right to do this.'])); 
		$user_id = $_GET['user_id'];
		$user_email	= $_GET['user_email'];
		$user_role 	= $_GET['user_role'];
		
		//$user_role 	= str_replace('qqq','',$_GET['user_role']);
		//if ($user_role==$_GET['user_role'])
				//die_with_log(json_encode(['status'=>'error', 'message'=>'The method is temporarily unavailable']));
		
		
		
		
		if (strlen($user_email)<2) die_with_log(json_encode(['status'=>'error', 'message'=>'email is too short.'])); 
		if (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) die_with_log(json_encode(['status'=>'error', 'message'=>'Incorrect email.'])); 
		
		if ($user_role!='admin' AND $user_role!='user') die_with_log(json_encode(['status'=>'error', 'message'=>'Incorrect user role.'])); 
		if (count(ExecSQL($link,"SELECT id as user_id FROM `users` WHERE `id`='$user_id'"))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'The user is not exists.']));
		
	
		$link->query("UPDATE `users` SET `user_email`='$user_email' WHERE `id`='$user_id'");
		if (count(ExecSQL($link,"SELECT id as user_id FROM `users_roles` WHERE `user_id`='$user_id' AND `company_id`='$company_id'"))!=0)
				$link->query("UPDATE `users_roles` SET `user_role`='$user_role', `company_id`='$company_id' WHERE `user_id`='$user_id'");
			else
				$link->query("INSERT INTO `users_roles` (`user_id`,`company_id`,`user_role`) VALUES ('$user_id', '$company_id','$user_role') ");
		
		
		$res = ExecSQL($link,"SELECT `users`.`id` as user_id,`user_email`,`user_role` FROM `users` JOIN `users_roles` ON `users`.`id`=`users_roles`.`user_id` WHERE `users`.`id`='$user_id';");
	
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Updated', 'user'=>$res])); 
	}

	if ($method=='reset_password')
	{
		die_with_log(json_encode(['status'=>'error', 'message'=>'Метод в разработке.']));
		
	}
	
	if ($method=='new_password')
	{
		die_with_log(json_encode(['status'=>'error', 'message'=>'Метод в разработке.']));
		
	}
	
	
	if ($method=='delete_event')
	{
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		$event_id = $_GET['event_id'];
		if (count(ExecSQL($link,"SELECT id as event_id FROM `events` WHERE `id`='$event_id';"))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'The event is not exists.']));
		
		ExecSQL($link,"DELETE FROM `events` WHERE `id`='$event_id';");
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Ok'])); 
	}
	

	if ($method=='create_event')
	{
		
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		$car_id				= $_GET['car_id'];
		$point_id			= $_GET['point_id'];
		$event 				= $_GET['event'];
		$time_response_sec 	= $_GET['time_response_sec'];
		
		if (!isset($time_response_sec) OR $time_response_sec==NULL) $time_response_sec=0;
		
		if (count(ExecSQL($link,"SELECT id FROM `cars` WHERE `id`='$car_id' AND `company_id`='$company_id';"))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'The car is not exists.'])); 
		if (count(ExecSQL($link,"SELECT id FROM `points` WHERE `id`='$point_id' AND `company_id`='$company_id';"))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'The point is not exists.'])); 
		if ($event!='IN' AND $event!='OUT') die_with_log(json_encode(['status'=>'error', 'message'=>'Incorrect event type.']));
		if ($time_response_sec<0) die_with_log(json_encode(['status'=>'error', 'message'=>'Response time is not >=0.'])); 
		if (count(ExecSQL($link,"SELECT id FROM `events` WHERE `car_id`='$car_id' AND `point_id`='$point_id';"))>0) die_with_log(json_encode(['status'=>'error', 'message'=>'The event already exists.'])); 
		
		$link->query("INSERT INTO `events` (`car_id`, `point_id`, `event`, `time_response_sec`) VALUES ('$car_id', '$point_id', '$event', '$time_response_sec');"); 
		
		$event_id = $link->insert_id;
		$res = ExecSQL($link,"SELECT id as event_id,car_id,point_id,event,time_response_sec FROM `events` WHERE `id`='$event_id';")[0];
	
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Added', 'event'=>$res])); 
	}
	
	if ($method=='save_event')
	{
		
		$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];	// проверить авторизацию и определить там компанию
		$event_id 			= $_GET['event_id'];
		$car_id				= $_GET['car_id'];
		$point_id			= $_GET['point_id'];
		$event 				= $_GET['event'];
		$time_response_sec 	= $_GET['time_response_sec'];
		if (!isset($time_response_sec) OR $time_response_sec==NULL) $time_response_sec=0;
		
		if (count(ExecSQL($link,"SELECT id FROM `cars` WHERE `id`='$car_id' AND `company_id`='$company_id';"))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'The car is not exists.'])); 
		if (count(ExecSQL($link,"SELECT id FROM `points` WHERE `id`='$point_id' AND `company_id`='$company_id';"))==0) die_with_log(json_encode(['status'=>'error', 'message'=>'The point is not exists.'])); 
		if ($event!='IN' AND $event!='OUT') die_with_log(json_encode(['status'=>'error', 'message'=>'Incorrect event type.']));
		if ($time_response_sec<0) die_with_log(json_encode(['status'=>'error', 'message'=>'Response time is not >=0.'])); 
		//if (count(ExecSQL($link,"SELECT id FROM `events` WHERE `car_id`='$car_id' AND `point_id`='$point_id';"))>0) die_with_log(json_encode(['status'=>'error', 'message'=>'The event already exists.'])); 
		
		$link->query("UPDATE `events` SET `car_id`='$car_id', `point_id`='$point_id', `event`='$event', `time_response_sec`='$time_response_sec' WHERE `id`='$event_id';");
		$res = ExecSQL($link,"SELECT id as event_id,car_id,point_id,event,time_response_sec FROM `events` WHERE `id`='$event_id';")[0];
		
		exit_ok_with_log (json_encode(['status'=>'Ok', 'message'=>'Updated', 'event'=>$res])); 
	}

	if ($method=='all_cars')
	{
		if (isset($_GET['code']))
		{
			$code=$_GET['code'];
			$park_id=ExecSQL($link,"SELECT id FROM `companies` WHERE `short_link`='$code'")[0]['id'];
			if ($park_id==NULL) die_with_log('incorrect code');
		}
		else 
		if (isset($_SERVER ["PHP_AUTH_USER"]))
			{
				$park_id=$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];
			}
		else $park_id=$_GET['park_id']; // удалить это!!!!
		
		$company = ExecSQL($link,"SELECT * FROM `companies` WHERE `id`='$park_id' LIMIT 1")[0];
		

		if ($company['id']==NULL OR $company['id']!=$park_id) die_with_log('incorrect code');
		$res['company_name'] = $company['name'];
		$res['company_id'] = $company['id'];
		
		$cars = ExecSQL($link,"SELECT * FROM `cars` WHERE `company_id`='$park_id';");
		
		foreach ($cars as $car_1)
		{
				
				$last_track_record = ExecSQL($link,"SELECT * FROM `tracking` WHERE `imei`='".$car_1['imei']."' OR `imei`='" .$car_1['alter_imei']."' ORDER BY `timestamp` DESC LIMIT 1")[0];
				
				$car1['car_id'] 	= $car_1['id'];
				$car1['car_name'] 	= $car_1['name'];
				$car1['pic'] 		= $basic_url.$car_1['pic'];
				
				$car1['lat'] 		= $last_track_record['lat'];
				$car1['lng'] 		= $last_track_record['lng'];
				
				if ($car_1['id']==2) 
				{
					$car1['lat'] 		= rand(548000000,549500000)/10000000;
					$car1['lng'] 		= rand(275100000,275900000)/10000000;

				}
				
				$car1['angle'] 		= $last_track_record['angle'];
				$car1['altitude'] 	= $last_track_record['altitude'];
				$car1['speed'] 		= $last_track_record['speed'];
				$car1['last_track'] = $last_track_record['timestamp'];
				
				$res['cars'][]=$car1;

			
		}
		exit (json_encode($res));
	}
	
	if ($method=='about_user')
	{
		$username = 	$_SERVER ['PHP_AUTH_USER'];
		$password = 	$_SERVER ['PHP_AUTH_PW'];			
			
			$companies = ExecSQL ($link,"
			SELECT `users_roles`.`company_id` as company_id
				FROM `users` 
				JOIN `users_roles` ON `users_roles`.`user_id`=users.id
				WHERE `user_email`='$username' AND `password`='$password'  
			");
			if (count($companies)==0) die_with_log(json_encode(['status'=>'error', 'message'=>'Autorization fault.'])); 
		$res ['companies']= $companies;
		
		



		exit_ok_with_log  (json_encode($res));
	}
	


	if ($method=='history')
	{
		if (isset($_GET['code']))
		{
			$code=$_GET['code'];
			$park_id=ExecSQL($link,"SELECT id FROM `companies` WHERE `short_link`='$code'")[0]['id'];
			if ($park_id==NULL) die_with_log('incorrect code');
		}
		else 
		if (isset($_SERVER ["PHP_AUTH_USER"]))
		{
			$park_id=$ver = verify (); $company_id = $ver['company_id'];  $this_user_role = $ver['user_role']; $this_user_id = $ver['user_id'];
		}
		else $park_id=$_GET['park_id']; // удалить это!!!!


		$company = ExecSQL($link,"SELECT * FROM `companies` WHERE `id`='$park_id' LIMIT 1")[0];
		if ($company['id']==NULL OR $company['id']!=$park_id) die_with_log('incorrect code');

		$from = $_GET['from'];
		$to = 	$_GET['to'];
		$car_id = 	$_GET['car_id'];
		
		$car1 = ExecSQL($link,"SELECT * FROM `cars` WHERE `company_id`='$park_id' AND id='$car_id' LIMIT 1")[0];
		
		if ($car1['id']!=$car_id) die_with_log('incorrect car_id');

		$days = floor(abs(strtotime($to)-strtotime($from))/60/60/24);
		if ($days>7) die_with_log(json_encode(['status'=>'error', 'message'=>'The number of days should not exceed 7.'])); 
		
		$hist_all = ExecSQL($link,"SELECT timestamp,lat,lng,altitude,angle,speed FROM `tracking` WHERE (`imei`='".$car1['imei']."' OR `imei`='".$car1['alter_imei']."') AND timestamp>'$from' AND timestamp<'$to' ORDER BY `timestamp`");
		
		$res['from']=$from;
		$res['to']=$to;
		$res['car_id']=$car_id;
		
		$res['history']=array();
		$last_lat=0;
		$last_lng=0;
		foreach ($hist_all as $hist1)
		{
			if ($last_lat==0 OR calcDistance($last_lat,$last_lng,$hist1['lat'],$hist1['lng'])>max(15,$hist1['speed']/2))
			{
				$last_lat = $hist1['lat'];
				$last_lng = $hist1['lng'];
				$res['history'][]=$hist1;
			}
			//else hist_prev= надо повторить предыдущую точку, если следующая была нескоро (после остановки)
		}
		
		$res['points']=ExecSQL($link,"SELECT name,lat,lng,radius FROM `points` WHERE company_id='$park_id'");
		
		
			
		//logg($method,json_encode($res));
		exit_ok_with_log  (json_encode($res));

	}
	telegram_message($config['telegram_admin_chat_id'],"❌❌❌ Неверный метод $method");	// тревожное сообщение в телеграм-чат-админку
					

	die_with_log("incorrect methodd $method");

