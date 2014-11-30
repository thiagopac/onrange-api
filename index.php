<?php
require 'Slim/Slim.php';
\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim();
$app->response()->header('Content-Type', 'application/json;charset=utf-8');

// GET
// http://roomnants.com/onrange/api/local/listatodoslocais
// http://roomnants.com/onrange/api/local/listaLocaisRange
// http://roomnants.com/onrange/api/checkin/listaUsuariosCheckin
// http://roomnants.com/onrange/api/checkin/verificaCheckinUsuario
// http://roomnants.com/onrange/api/match/listaMatches
// 
// POST
// http://roomnants.com/onrange/api/local/adicionalocal {"nome_local":"Clube do Chalezinho","latitude_local":"-19.968165","longitude_local":"-43.957688","id_usuario":"1","tipo_local":"1"}
// http://roomnants.com/onrange/api/usuario/adicionausuario {"nome_usuario":"João","sexo_usuario":"M","facebook_usuario":"11111111111","email_usuario":"joao@joao.com"}
// http://roomnants.com/onrange/api/checkin/adicionacheckin {"id_usuario":"1","id_local":"1"}
// http://roomnants.com/onrange/api/like/adicionalike {"id_usuario1":"1","id_usuario2":"2","id_local":"1"}
// http://roomnants.com/onrange/api/usuario/login {"facebook_usuario":"100000627704444"}
//
// PUT
// http://roomnants.com/onrange/api/checkin/fazcheckout
// http://roomnants.com/onrange/api/checkin/unMatch

//GET METHODS
$app->get('/', function () { echo "{\"Erro\":\"diretório raiz\"}"; }); //erro no raiz
$app->get('/local/listatodoslocais','listaTodosLocais'); //traz todos locais
$app->get('/local/listaLocaisRange/:latitude_atual/:longitude_atual/:range/:order_by','listaLocaisRange'); //traz os locais dentro do range definido pelo usuário, baseando-se no local atual
$app->get('/checkin/listaUsuariosCheckin/:id_local/:sexo/:id_usuario','listaUsuariosCheckin'); //traz os usuários com checkin corrente no local informado
$app->get('/checkin/verificaCheckinUsuario/:id_usuario','verificaCheckinUsuario'); //traz os usuários com checkin corrente no local informado
$app->get('/match/listaMatches/:id_usuario','listaMatches'); //traz uma lista com todos os matches válidos do usuário informado

//POST METHODS
$app->post('/local/adicionalocal','adicionaLocal'); //cria novo local
$app->post('/usuario/adicionausuario','adicionaUsuario'); //cria novo usuario
$app->post('/checkin/adicionacheckin','adicionaCheckin'); //faz checkin
$app->post('/like/adicionalike','adicionaLike'); //dá like em algu�m, em algum local
$app->post('/usuario/login','loginUsuario'); //faz login de usuário

//PUT METHODS
$app->put('/checkin/fazcheckout','fazCheckout'); //cancela o checkin vigente do usuário
$app->put('/match/unmatch','unMatch'); //cancela o Match com o usuário informado
$app->put('/usuario/exclui','apagaUsuario'); //apaga usuário

//INTERFACES com QUICKBLOX
$app->post('/quickblox/todosusuarios','listaTodosUsuariosQuickblox'); //cria novo usuario

$app->run();

function getConn()
{
	return new PDO('mysql:host=mysql.hostinger.com.br;dbname=u138894269_onrng','u138894269_onrng','onrange8375',
	array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8", PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
}

function listaTodosLocais()
{
	$sql = "SELECT id_local, nome, latitude, longitude FROM LOCAL";
	try{
		$conn = getConn();
		$stmt = $conn->query($sql);
		$locais = $stmt->fetchAll(PDO::FETCH_OBJ);
		
		echo "{\"Locais\":" . json_encode($locais) . "}";
		
		$conn = null;
		
	} catch(PDOException $e){
        echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
		die();
    }
}

function listaLocaisRange($latitude_atual,$longitude_atual,$range,$order_by)
{
	// first-cut bounding box (in degrees)
	$maxLat = $latitude_atual + rad2deg($range/6371);
	$minLat = $latitude_atual - rad2deg($range/6371);
	
	// compensate for degrees longitude getting smaller with increasing latitude
	$maxLong = $longitude_atual + rad2deg($range/6371/cos(deg2rad($latitude_atual)));
	$minLong = $longitude_atual - rad2deg($range/6371/cos(deg2rad($latitude_atual)));
	
	//Verifica qual seleção deve ser aplicada, se por checkins ou por distância
	if($order_by=="checkin"){
		$sql = "SELECT id_local, nome, latitude, longitude, 
					acos(sin(:latitude_atual)*sin(radians(latitude)) + cos(:latitude_atual)*cos(radians(latitude))*cos(radians(longitude)-:longitude_atual)) * 6371 As distancia,
					qt_checkin, id_tipo_local AS tipo_local, destaque
				FROM (
						SELECT LOCAL.id_local, LOCAL.nome, LOCAL.latitude, LOCAL.longitude, CHECKINS_CORRENTES.qt_checkin, LOCAL.id_tipo_local, LOCAL.destaque
						FROM LOCAL JOIN CHECKINS_CORRENTES ON LOCAL.id_local = CHECKINS_CORRENTES.id_local
						WHERE
						CHECKINS_CORRENTES.qt_checkin > 0
						AND LOCAL.dt_exclusao IS NULL
						AND LOCAL.latitude BETWEEN :minLat AND :maxLat
						AND LOCAL.longitude Between :minLong AND :maxLong
						GROUP BY LOCAL.id_local
					) AS FirstCut 
				WHERE acos(sin(:latitude_atual)*sin(radians(latitude)) + cos(:latitude_atual)*cos(radians(latitude))*cos(radians(longitude)-:longitude_atual)) * 6371 <= :range
				ORDER BY qt_checkin DESC";
	}else{
		$sql = "SELECT id_local, nome, latitude, longitude, 
					acos(sin(:latitude_atual)*sin(radians(latitude)) + cos(:latitude_atual)*cos(radians(latitude))*cos(radians(longitude)-:longitude_atual)) * 6371 As distancia,
					qt_checkin, id_tipo_local AS tipo_local, destaque
				FROM (
						SELECT LOCAL.id_local, LOCAL.nome, LOCAL.latitude, LOCAL.longitude, CHECKINS_CORRENTES.qt_checkin, LOCAL.id_tipo_local, LOCAL.destaque
						FROM LOCAL JOIN CHECKINS_CORRENTES ON LOCAL.id_local = CHECKINS_CORRENTES.id_local
						WHERE
						LOCAL.dt_exclusao IS NULL
						AND LOCAL.latitude BETWEEN :minLat AND :maxLat
						AND LOCAL.longitude Between :minLong AND :maxLong
						GROUP BY LOCAL.id_local
					) AS FirstCut 
				WHERE acos(sin(:latitude_atual)*sin(radians(latitude)) + cos(:latitude_atual)*cos(radians(latitude))*cos(radians(longitude)-:longitude_atual)) * 6371 <= :range
				ORDER BY distancia ASC";
	}

	try{
		$conn = getConn();
		$stmt = $conn->prepare($sql);
		$stmt->bindParam("latitude_atual",deg2rad($latitude_atual));
		$stmt->bindParam("longitude_atual",deg2rad($longitude_atual));
		$stmt->bindParam("range",$range);
		$stmt->bindParam("minLat",$minLat);
		$stmt->bindParam("maxLat",$maxLat);
		$stmt->bindParam("minLong",$minLong);
		$stmt->bindParam("maxLong",$maxLong);
		$stmt->execute();
		$locais = $stmt->fetchAll(PDO::FETCH_OBJ);
		echo "{\"Locais\":" . json_encode($locais) . "}";
		
		$conn = null;
		
	} catch(PDOException $e){
        
            //ERRO 502
            //MENSAGEM: Erro na listagem de locais
            
            header('Ed-Return-Message: Erro na listagem de locais', true, 502);
            echo '[]';
                                
            die();
            
            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';

    }
}

function adicionaLocal()
{
	$request = \Slim\Slim::getInstance()->request();
	$local = json_decode($request->getBody());
	$sql = "INSERT INTO LOCAL (nome, latitude, longitude, dt_local, id_usuario, id_tipo_local) VALUES (:nome_local, :latitude_local, :longitude_local, NOW(), :id_usuario, :id_tipo_local)";
	try{
		$conn = getConn();
		$stmt = $conn->prepare($sql);
		$stmt->bindParam("nome_local",$local->nome);
		$stmt->bindParam("latitude_local",$local->latitude);
		$stmt->bindParam("longitude_local",$local->longitude);
		$stmt->bindParam("id_usuario",$local->id_usuario);
		$stmt->bindParam("id_tipo_local",$local->tipo_local);
		$stmt->execute();
		$local->id_local = $conn->lastInsertId();
		
		$local->id_output = "1";
		$local->desc_output = "Local adicionado com sucesso.";
		
	} catch(PDOException $e){
		
            //ERRO 503
            //MENSAGEM: Erro ao adicionar novo local
            
            header('Ed-Return-Message: Erro ao adicionar novo local', true, 503);
            echo '[]';
                                
            die();
            
            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	}
	
	//Cria o local na tabela de controle de checkins correntes
	
	$sql = "INSERT INTO CHECKINS_CORRENTES (id_local) VALUES (:id_local)";
	try{
		$stmt = $conn->prepare($sql);
		$stmt->bindParam("id_local",$local->id_local);
		$stmt->execute();

	} catch(PDOException $e){
		
            //ERRO 504
            //MENSAGEM: Erro ao adicionar novo local em checkins correntes
            
            header('Ed-Return-Message: Erro ao adicionar novo local em checkins correntes', true, 504);
            echo '[]';
                                
            die();
            
            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
                
	}
	
	//Checkout no local anterior
	
	//Verifica se há checkin corrente
			
	$sql = "SELECT id_checkin, id_local FROM CHECKIN WHERE id_usuario = :id_usuario AND dt_checkout IS NULL";
		
	try{
		$conn = getConn();
		$stmt = $conn->prepare($sql);
		$stmt->bindParam("id_usuario",$local->id_usuario);
		$stmt->execute();
		
	} catch(PDOException $e){
		
            //ERRO 505
            //MENSAGEM: Erro ao verificar checkin corrente do usuario
            
            header('Ed-Return-Message: Erro ao verificar checkin corrente do usuario', true, 505);
            echo '[]';
                                
            die();
            
            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
                
	}	
	
	$checkin = $stmt->fetch(PDO::FETCH_OBJ);
	
	if($checkin){ //Se existe checkin prévio, faz o checkout
	
		$sql = "UPDATE CHECKIN SET dt_checkout = NOW() WHERE id_checkin = :id_checkin";
		
		try{
			$stmt = $conn->prepare($sql);
			$stmt->bindParam("id_checkin",$checkin->id_checkin);
			$stmt->execute();
			
		} catch(PDOException $e){
                    //ERRO 519
                    //MENSAGEM: Erro ao realizar checkout no local anterior

                    header('Ed-Return-Message: Erro ao realizar checkout no local anterior', true, 519);
                    echo '[]';

                    die();

                    //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
		}
		
		//Atualiza a tabela de checkins correntes, decrementando 1 do local anterior
				
		$sql = "UPDATE CHECKINS_CORRENTES SET qt_checkin = qt_checkin - 1 WHERE id_local = :id_local";
		
		try{
	
			$stmt = $conn->prepare($sql);
			$stmt->bindParam("id_local",$checkin->id_local);
			$stmt->execute();
		
		} catch(PDOException $e){
			
                    //ERRO 506
                    //MENSAGEM: Erro ao decrementar tabela de checkins correntes

                    header('Ed-Return-Message: Erro ao decrementar tabela de checkins correntes', true, 506);
                    echo '[]';

                    die();

                    //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
		}
	
	}
	
	// Faz o checkin no local criado
	$sql = "INSERT INTO CHECKIN (id_usuario, id_local, dt_checkin) VALUES (:id_usuario, :id_local, NOW())";
	
	try{
		$stmt = $conn->prepare($sql);
		$stmt->bindParam("id_usuario",$local->id_usuario);
		$stmt->bindParam("id_local",$local->id_local);
		$stmt->execute();

	} catch(PDOException $e){
	
            //ERRO 507
            //MENSAGEM: Erro ao fazer checkin no local criado

            header('Ed-Return-Message: Erro ao fazer checkin no local criado', true, 507);
            echo '[]';

            die();

            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	}
	
	// Atualiza tabela de checkins correntes, incrementando 1 ao local novo
	
	$sql = "UPDATE CHECKINS_CORRENTES SET qt_checkin = qt_checkin + 1 WHERE id_local = :id_local";
	try{
	$stmt = $conn->prepare($sql);
	$stmt->bindParam("id_local",$local->id_local);
	$stmt->execute();
	} catch(PDOException $e){
            
            //ERRO 520
            //MENSAGEM: Erro ao incrementar tabela de checkins correntes

            header('Ed-Return-Message: Erro ao incrementar tabela de checkins correntes', true, 520);
            echo '[]';

            die();

            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	}
	
	// Retorna o objeto do Local criado
	
	echo "{\"Local\":" . json_encode($local) . "}";
	
	$conn = null;
	
}

function adicionaUsuario()
{
	$request = \Slim\Slim::getInstance()->request();
	$usuario = json_decode($request->getBody());
	
	//Adequa tags
		
	$usuario->localizacao_usuario = str_replace(", ", "-", $usuario->localizacao_usuario);
	$usuario->localizacao_usuario = str_replace(" ", "-", $usuario->localizacao_usuario);
		
	$usuario->aniversario_usuario = str_replace("/", "-", $usuario->aniversario_usuario);
	
	//Verifica se o usuário já está cadastrado
	
	$sql = "SELECT id_usuario, nome, sexo, email, localizacao, aniversario FROM USUARIO WHERE id_facebook = :id_facebook";
	try{
		$conn = getConn();
		$stmt = $conn->prepare($sql);
		$stmt->bindParam("id_facebook",$usuario->facebook_usuario);
		$stmt->execute();
	} catch(PDOException $e){
		
            //ERRO 508
            //MENSAGEM: Erro ao buscar usuario

            header('Ed-Return-Message: Erro ao buscar usuario', true, 508);
            echo '[]';

            die();

            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	}
		
	$registro_usuario = $stmt->fetch(PDO::FETCH_OBJ);
	
	$usuario->id_usuario = $registro_usuario->id_usuario;
	
	if(!$registro_usuario){		//--------------####### NOVO USUÁRIO #######--------------//
	//Insere na base e informa novo_usuario = 1
	
		$sql = "INSERT INTO USUARIO (nome, sexo, id_facebook, email, dt_usuario, localizacao, aniversario) VALUES (:nome_usuario, :sexo_usuario, :facebook_usuario, :email_usuario, NOW(), :localizacao_usuario, :aniversario_usuario)";
		try{
			$stmt = $conn->prepare($sql);
			$stmt->bindParam("nome_usuario",$usuario->nome_usuario);
			$stmt->bindParam("sexo_usuario",$usuario->sexo_usuario);
			$stmt->bindParam("facebook_usuario",$usuario->facebook_usuario);
			$stmt->bindParam("email_usuario",$usuario->email_usuario);
			$stmt->bindParam("localizacao_usuario",$usuario->localizacao_usuario);
			$stmt->bindParam("aniversario_usuario",$usuario->aniversario_usuario);
			$stmt->execute();
		} catch(PDOException $e){
			
                    //ERRO 509
                    //MENSAGEM: Erro ao adicionar novo usuario

                    header('Ed-Return-Message: Erro ao adicionar novo usuario', true, 509);
                    echo '[]';

                    die();

                    //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
		}
		
		// Cria usuário no QuickBlox
		
		$tags = "sexo-" . $usuario->sexo_usuario . ",localizacao-" . $usuario->localizacao_usuario . ",aniversario-" . $usuario->aniversario_usuario;
		
		$dados_usuario = array("login" => $usuario->facebook_usuario, "password" => $usuario->facebook_usuario, "email" => $usuario->email_usuario, "facebook_id" => $usuario->facebook_usuario, "tag_list" => $tags);
		
		try{
			$usuario->QB = CallAPIQB("POST","http://api.quickblox.com/users.json",$dados_usuario,"QB-Token: " . $usuario->qbtoken);
		} catch(PDOException $e){
		
                    //ERRO 510
                    //MENSAGEM: Erro ao criar usuario no QB

                    header('Ed-Return-Message: Erro ao criar usuario no QB', true, 510);
                    echo '[]';

                    die();

                    //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
		}
		
		$usuario->id_usuario = $conn->lastInsertId();
		$usuario->novo_usuario = "1";
		
		$usuario->id_output = "1";
		$usuario->desc_output = "Usuario criado com sucesso. Login realizado com sucesso.";
		
	}
	else{	   //--------------####### USUÁRIO EXISTENTE #######--------------//
	
		//Verifica se houve alteração das informações pessoais
		
		if($registro_usuario->nome != $usuario->nome_usuario || $registro_usuario->sexo != $usuario->sexo_usuario || $registro_usuario->email != $usuario->email_usuario || $registro_usuario->localizacao != $usuario->localizacao_usuario || $registro_usuario->aniversario != $usuario->aniversario_usuario){
			//Se houve alteração em algum dos dados, atualiza o registro do usuário na base do Onrange
			
			$sql = "UPDATE USUARIO SET nome = :nome_usuario, sexo = :sexo_usuario, email = :email_usuario, localizacao = :localizacao_usuario, aniversario = :aniversario_usuario WHERE id_usuario = :id_usuario";
			try{
				$stmt = $conn->prepare($sql);
				$stmt->bindParam("id_usuario",$usuario->id_usuario);
				$stmt->bindParam("nome_usuario",$usuario->nome_usuario);
				$stmt->bindParam("sexo_usuario",$usuario->sexo_usuario);
				$stmt->bindParam("email_usuario",$usuario->email_usuario);
				$stmt->bindParam("localizacao_usuario",$usuario->localizacao_usuario);
				$stmt->bindParam("aniversario_usuario",$usuario->aniversario_usuario);
				$stmt->execute();
			} catch(PDOException $e){
                            
                            //ERRO 511
                            //MENSAGEM: Erro ao autalizar usuario

                            header('Ed-Return-Message: Erro ao autalizar usuario', true, 511);
                            echo '[]';

                            die();

                            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
                    
			}
		
			// Atualiza usuário no QuickBlox
		
			$tags = "sexo-" . $usuario->sexo_usuario . ",localizacao-" . $usuario->localizacao_usuario . ",aniversario-" . $usuario->aniversario_usuario;
			
			$dados_usuario = array("login" => $usuario->facebook_usuario, "password" => $usuario->facebook_usuario, "email" => $usuario->email_usuario, "facebook_id" => $usuario->facebook_usuario, "tag_list" => $tags);
			
			try{
				$usuario->QB = CallAPIQB("PUT","http://api.quickblox.com/users/1328.json",$dados_usuario,"QB-Token: " . $usuario->qbtoken);
			} catch(PDOException $e){
			
                            //ERRO 512
                            //MENSAGEM: Erro ao autalizar usuario no QB

                            header('Ed-Return-Message: Erro ao autalizar usuario no QB', true, 512);
                            echo '[]';

                            die();

                            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
			}

		}
	
		$usuario->novo_usuario = "0";
		
		$usuario->id_output = "1";
		$usuario->desc_output = "Login realizado com sucesso.";
	
	}
	
	echo "{\"Usuario\":" . json_encode($usuario) . "}";
	
	$conn = null;
}

function adicionaCheckin()
{
	$request = \Slim\Slim::getInstance()->request();
	$checkin = json_decode($request->getBody());

	//Verifica se o usuário já tem algum checkin corrente
	$sql = "SELECT id_checkin, id_local, TIME_TO_SEC(TIMEDIFF(NOW(),dt_checkin))/60 as minutos_ultimo_checkin FROM CHECKIN WHERE id_usuario = :id_usuario AND dt_checkout IS NULL";
	try{
		$conn = getConn();
		$stmt = $conn->prepare($sql);
		$stmt->bindParam("id_usuario",$checkin->id_usuario);
		$stmt->execute();
	} catch(PDOException $e){
		
            //ERRO 513
            //MENSAGEM: Erro ao buscar checkins

            header('Ed-Return-Message: Erro ao buscar checkins', true, 513);
            echo '[]';

            die();

            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	}
	
	$checkin_vigente = $stmt->fetch(PDO::FETCH_OBJ);
	
	if($checkin_vigente){		//Se há checkin vigente para o usuário
	
		//retorna checkin_vigente = 1, o id e o local do checkin vigente
			
		$checkin->checkin_vigente = "1";
		$checkin->id_checkin_anterior = $checkin_vigente->id_checkin;
		$checkin->id_local_anterior = $checkin_vigente->id_local;
	
		// Verifica se o último checkin foi realizado há menos de 5 minutos.
		
		if($checkin_vigente->minutos_ultimo_checkin > 0){		
		
			// Faz o checkout no local anterior
			
			$sql = "UPDATE CHECKIN SET dt_checkout = NOW() WHERE id_checkin = :id_checkin";
			try{
			$stmt = $conn->prepare($sql);
			$stmt->bindParam("id_checkin",$checkin_vigente->id_checkin);
			$stmt->execute();
			} catch(PDOException $e){
                            //ERRO 514
                            //MENSAGEM: Erro ao fazer checkout pre-checkin

                            header('Ed-Return-Message: Erro ao fazer checkout pre-checkin', true, 514);
                            echo '[]';

                            die();

                            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
			}
			
			// Atualiza tabela de checkins correntes, decrementando 1 do local anterior
			
			$sql = "UPDATE CHECKINS_CORRENTES SET qt_checkin = qt_checkin - 1 WHERE id_local = :id_local";
			try{
			$stmt = $conn->prepare($sql);
			$stmt->bindParam("id_local",$checkin_vigente->id_local);
			$stmt->execute();
			} catch(PDOException $e){
                            //ERRO 515
                            //MENSAGEM: Erro ao decrementar tabela de checkins correntes

                            header('Ed-Return-Message: Erro ao decrementar tabela de checkins correntes', true, 515);
                            echo '[]';

                            die();

                            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
			}
		}
		// Se o ultimo checkin foi realizado há menos de 5 minutos, retorna mensagem de erro.
		else{
                        //ERRO 516
                        //MENSAGEM: Checkin anterior em menos de 5 minutos.

                        header('Ed-Return-Message: Checkin anterior em menos de 5 minutos', true, 516);
                        echo '[]';

                        die();

                        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
                                        
                        //$checkin->id_output = "3";
			//$checkin->desc_output = "Checkin anterior em menos de 5 minutos.";
		}
	}
	else{
		$checkin->checkin_vigente = "0";
	}
	
	// Faz o checkin
		
	$sql = "INSERT INTO CHECKIN (id_usuario, id_local, dt_checkin) VALUES (:id_usuario, :id_local, NOW())";
	
	try{
		$stmt = $conn->prepare($sql);
		$stmt->bindParam("id_usuario",$checkin->id_usuario);
		$stmt->bindParam("id_local",$checkin->id_local);
		$stmt->execute();
		$checkin->id_checkin = $conn->lastInsertId();
		
		$checkin->id_output = "1";
		$checkin->desc_output = "Checkin realizado com sucesso.";

	} catch(PDOException $e){
	
            //ERRO 517
            //MENSAGEM: Erro ao fazer checkin

            header('Ed-Return-Message: Erro ao fazer checkin', true, 517);
            echo '[]';

            die();

            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	}
	
	// Atualiza tabela de checkins correntes, incrementando 1 ao local novo
	
	$sql = "UPDATE CHECKINS_CORRENTES SET qt_checkin = qt_checkin + 1 WHERE id_local = :id_local";
	try{
	$stmt = $conn->prepare($sql);
	$stmt->bindParam("id_local",$checkin->id_local);
	$stmt->execute();
	} catch(PDOException $e){
            //ERRO 518
            //MENSAGEM: Erro ao incrementar tabela de checkins correntes

            header('Ed-Return-Message: Erro ao incrementar tabela de checkins correntes', true, 518);
            echo '[]';

            die();

            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	}

	$checkin->id_output = "1";
	$checkin->desc_output = "Checkin realizado com sucesso.";
	
	echo "{\"Checkin\":" . json_encode($checkin) . "}";
	
	$conn = null;
	
}

function adicionaLike()
{
    $request = \Slim\Slim::getInstance()->request();
    $like = json_decode($request->getBody());

    //Verifica se o usuário destino do like ainda tem um checkin válido

    $sql = "SELECT 1 FROM CHECKIN WHERE id_usuario = :id_usuario2 AND id_local = :id_local AND DT_CHECKOUT IS NULL";
    try{
            $conn = getConn();
            $stmt = $conn->prepare($sql);
            $stmt->bindParam("id_usuario2",$like->id_usuario2);
            $stmt->bindParam("id_local",$like->id_local);
            $stmt->execute();

    } catch(PDOException $e){

        //ERRO 521
        //MENSAGEM: Erro ao buscar checkin do usuario de destino

        header('Ed-Return-Message: Erro ao buscar checkin do usuario de destino', true, 521);
        echo '[]';

        die();

        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
    }

    //Se o usuário de destino fez o checkout
    if(!$stmt->fetchObject()){ 

        //ERRO 522
        //MENSAGEM: Usuario de destino realizou checkout

        header('Ed-Return-Message: Usuario de destino realizou checkout', true, 522);
        echo '[]';

        die();

        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';

    }
    else{ //Se o checkin do usuário destino ainda é válido

        //Verifica se o usuário já foi curtido ou não

        $sql = "SELECT id_like FROM LIKES WHERE id_usuario1 = :id_usuario1 AND id_usuario2 = :id_usuario2 AND dt_expiracao IS NULL";
        try{
                $stmt = $conn->prepare($sql);
                $stmt->bindParam("id_usuario1",$like->id_usuario1);
                $stmt->bindParam("id_usuario2",$like->id_usuario2);
                $stmt->execute();

                $like_existente = $stmt->fetch(PDO::FETCH_OBJ);

        } catch(PDOException $e){

            //ERRO 523
            //MENSAGEM: Erro ao verificar se ja existe like

            header('Ed-Return-Message: Erro ao verificar se ja existe like', true, 523);
            echo '[]';

            die();

            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';

        }

        //Se já não existe like válido
        if(!$like_existente){

            //Dá o like

            $sql = "INSERT INTO LIKES (id_usuario1, id_usuario2, id_local, dt_like) VALUES (:id_usuario1, :id_usuario2, :id_local, NOW())";
            try{
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam("id_usuario1",$like->id_usuario1);
                    $stmt->bindParam("id_usuario2",$like->id_usuario2);
                    $stmt->bindParam("id_local",$like->id_local);
                    $stmt->execute();
                    $like->id_like = $conn->lastInsertId();

            } catch(PDOException $e){

                //ERRO 524
                //MENSAGEM: Erro ao curtir

                header('Ed-Return-Message: Erro ao curtir', true, 524);
                echo '[]';

                die();

                //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';

            }

            //Verifica se houve o match

            //Verifica se o outro usuário já deu o like também, e se o mesmo ainda é válido
            try{
                    $sql = "SELECT 1 FROM LIKES WHERE id_usuario1 = :id_usuario2 AND id_usuario2 = :id_usuario1 AND DT_EXPIRACAO IS NULL"; 
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam("id_usuario1",$like->id_usuario1);
                    $stmt->bindParam("id_usuario2",$like->id_usuario2);
                    $stmt->execute();

            } catch(PDOException $e){

                //ERRO 525
                //MENSAGEM: Erro ao verificar se houve match

                header('Ed-Return-Message: Erro ao verificar se houve match', true, 525);
                echo '[]';

                die();

                //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
            }

            //Retorna match = 0 se não houver retorno do select

            if(!$stmt->fetchObject())
                    $like->match = "0";
            else{	//--------------------------######## MATCH ########--------------------------//

                    // Busca os IDs do QB dos usuários

                    try{
                    $sql = "SELECT id_qb FROM USUARIO WHERE id_usuario = :id_usuario1";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam("id_usuario1",$like->id_usuario1);
                    $stmt->execute();
                    $usuario1 = $stmt->fetch(PDO::FETCH_OBJ);

                    } catch(PDOException $e){

                        //ERRO 526
                        //MENSAGEM: Erro ao buscar ID do QB do usuario 1

                        header('Ed-Return-Message: Erro ao buscar ID do QB do usuario 1', true, 526);
                        echo '[]';

                        die();

                        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
                    }

                    try{
                    $sql = "SELECT id_qb FROM USUARIO WHERE id_usuario = :id_usuario2";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam("id_usuario2",$like->id_usuario2);
                    $stmt->execute();
                    $usuario2 = $stmt->fetch(PDO::FETCH_OBJ);

                    } catch(PDOException $e){

                        //ERRO 527
                        //MENSAGEM: Erro ao buscar ID do QB do usuario 2

                        header('Ed-Return-Message: Erro ao buscar ID do QB do usuario 2', true, 527);
                        echo '[]';

                        die();

                        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
                    }

                    //######## CHAT ########//

                    // Por algum motivo obscuro o QB se perde caso mandemos uma requisição de um chat já existente, mas com os IDs na ordem inversa. Desta forma, mandamos sempre na mesma ordem.

                    if($usuario1->id_qb > $usuario2->id_qb)
                            $dados_chat = array( "type" => 3, "name" => "", "occupants_ids" => $usuario1->id_qb . "," . $usuario2->id_qb);
                    else
                            $dados_chat = array( "type" => 3, "name" => "", "occupants_ids" => $usuario2->id_qb . "," . $usuario1->id_qb);

                    try{
                            $like->chat = CallAPIQB("POST","https://api.quickblox.com/chat/Dialog.json",$dados_chat,"QB-Token: " . $like->qbtoken);
                    } catch(PDOException $e){

                        //ERRO 543
                        //MENSAGEM: Erro ao criar chat no QB

                        header('Ed-Return-Message: Erro ao criar chat no QB', true, 543);	
                        echo '[]';

                        die();
                    }

                    //Insere na tabela e retorna match = 1

                    $sql = "INSERT INTO MATCHES (id_usuario1, id_usuario2, id_local, dt_match) VALUES (:id_usuario1, :id_usuario2, :id_local, NOW())";

                    try{
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam("id_usuario1",$like->id_usuario1);
                    $stmt->bindParam("id_usuario2",$like->id_usuario2);
                    $stmt->bindParam("id_local",$like->id_local);
                    $stmt->execute();

                    } catch(PDOException $e){

                        //ERRO 528
                        //MENSAGEM: Erro ao criar match

                        header('Ed-Return-Message: Erro ao criar match', true, 528);
                        echo '[]';

                        die();

                        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';

                    }
                    $like->match = "1";
            }

            $like->id_output = "1";
            $like->desc_output = "Like realizado com sucesso.";

        //Se já há o like válido, dá deslike
        }
        else{

            $sql = "UPDATE LIKES SET dt_expiracao = NOW() WHERE id_like = :id_like";

            try{
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam("id_like",$like_existente->id_like);
                    $stmt->execute();

            } catch(PDOException $e){

                //ERRO 529
                //MENSAGEM: Erro ao descurtir

                header('Ed-Return-Message: Erro ao descurtir', true, 529);
                echo '[]';

                die();

                //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
            }

            $like->id_output = "4";
            $like->desc_output = "Deslike realizado com sucesso.";

        }

        echo "{\"Like\":" . json_encode($like) . "}";
       
    }	
    
    $conn = null;
}

function loginUsuario()
{
    $request = \Slim\Slim::getInstance()->request();
    $usuario = json_decode($request->getBody());

    //Verifica e devolve seus dados

    $sql = "SELECT USUARIO.id_usuario, USUARIO.id_facebook AS facebook_usuario, USUARIO.id_qb AS quickblox_usuario, USUARIO.nome AS nome_usuario, USUARIO.sexo AS sexo_usuario, USUARIO.dt_usuario, USUARIO.dt_exclusao, USUARIO.dt_bloqueio, USUARIO.email AS email_usuario 
            FROM USUARIO WHERE USUARIO.id_facebook = :id_facebook";
    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_facebook",$usuario->facebook_usuario);
        $stmt->execute();
        
        $usuario = $stmt->fetch(PDO::FETCH_OBJ);

    } catch(PDOException $e){

        //ERRO 530
        //MENSAGEM: Erro ao buscar usuario

        header('Ed-Return-Message: Erro ao buscar usuario', true, 530);	
        echo '[]';

        die();
    }

    //Se o usuário foi encontrado
    if($usuario){

        //Verificando se usuario foi bloqueado logicamente através do preenchimento do campo DT_BLOQUEIO
        if($usuario->dt_bloqueio != null){

            //ERRO 501
            //MENSAGEM: Usuario bloqueado

            header('Ed-Return-Message: Usuario bloqueado', true, 501);	
            echo '[]';

            die();	

        } 
        else{
            if($usuario->dt_exclusao != null){ //Verificando se usuario foi excluído logicamente através do preenchimento do campo DT_EXCLUSAO

                //Seta NULL no campo DT_EXCLUSAO, pois o usuario deseja retornar ao aplicativo

                $sql = "UPDATE USUARIO SET dt_exclusao = NULL 
                WHERE id_usuario = :id_usuario";

                try{
                        $stmt = $conn->prepare($sql);
                        $stmt->bindParam("id_usuario",$usuario->id_usuario);
                        $stmt->execute();

                } catch(PDOException $e){

                    //ERRO 546
                    //MENSAGEM: Erro ao remover data de exclusao do usuario

                    header('Ed-Return-Message: Erro ao remover data de exclusao do usuario', true, 546);	
                    echo '[]';

                    die();
                }
            }
        
            //Login realizado com sucesso. Retorna o objeto com os dados do usuário
            
            echo "{\"Usuario\":" . json_encode($usuario) . "}";
            
        }

    }
    else{

        //ERRO 500
        //MENSAGEM: Usuario inexistente

        header('Ed-Return-Message: Usuario inexistente', true, 500);	
        echo '[]';

        die();

    }

    $conn = null;
}

function listaUsuariosCheckin($id_local,$sexo,$id_usuario)
{
    if($sexo=='MF'){

        $sql = "SELECT USUARIO.id_usuario, USUARIO.nome as nome_usuario, USUARIO.id_facebook as facebook_usuario, 
                CASE WHEN LIKES.id_usuario1 IS NULL THEN 0 ELSE 1 END as liked, 
                CASE WHEN MATCHES.id_usuario1 IS NULL THEN 0 ELSE 1 END as matched
                FROM USUARIO INNER JOIN CHECKIN
                        ON USUARIO.id_usuario = CHECKIN.id_usuario
                LEFT JOIN LIKES
                        ON USUARIO.id_usuario = LIKES.id_usuario2
                        AND LIKES.id_usuario1 = :id_usuario
                        AND LIKES.dt_expiracao IS NULL
                        AND LIKES.id_local = :id_local
                LEFT JOIN MATCHES
                        ON 
                        ((USUARIO.id_usuario = MATCHES.id_usuario2
                        AND MATCHES.id_usuario1 = :id_usuario) 
                        OR 
                        (USUARIO.id_usuario = MATCHES.id_usuario1
                        AND MATCHES.id_usuario2 = :id_usuario))
                        AND MATCHES.dt_block IS NULL
                WHERE CHECKIN.id_local = :id_local
                        AND CHECKIN.dt_checkout IS NULL
                ORDER BY CHECKIN.dt_checkin ASC";
    }
    else{
        $sql = "SELECT USUARIO.id_usuario, USUARIO.nome as nome_usuario, USUARIO.id_facebook as facebook_usuario, 
                CASE WHEN LIKES.id_usuario1 IS NULL THEN 0 ELSE 1 END as liked, 
                CASE WHEN MATCHES.id_usuario1 IS NULL THEN 0 ELSE 1 END as matched
                FROM USUARIO INNER JOIN CHECKIN
                        ON USUARIO.id_usuario = CHECKIN.id_usuario
                LEFT JOIN LIKES
                        ON USUARIO.id_usuario = LIKES.id_usuario2
                        AND LIKES.id_usuario1 = :id_usuario
                        AND LIKES.dt_expiracao IS NULL
                        AND LIKES.id_local = :id_local
                LEFT JOIN MATCHES
                        ON 
                        ((USUARIO.id_usuario = MATCHES.id_usuario2
                        AND MATCHES.id_usuario1 = :id_usuario) 
                        OR 
                        (USUARIO.id_usuario = MATCHES.id_usuario1
                        AND MATCHES.id_usuario2 = :id_usuario))
                        AND MATCHES.dt_block IS NULL
                WHERE CHECKIN.id_local = :id_local
                        AND CHECKIN.dt_checkout IS NULL
                        AND (USUARIO.sexo = :sexo OR USUARIO.sexo = \"N\" OR USUARIO.id_usuario = :id_usuario)
                ORDER BY CHECKIN.dt_checkin ASC";
    }
    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        if($sexo<>'MF') $stmt->bindParam("sexo",$sexo);
        $stmt->bindParam("id_local",$id_local);
        $stmt->bindParam("id_usuario",$id_usuario);
        $stmt->execute();
        $usuarios = $stmt->fetchAll(PDO::FETCH_OBJ);

        echo "{ \"Usuarios\": " . json_encode($usuarios) . " }";

        $conn = null;

    } catch(PDOException $e){
        
        //ERRO 531
        //MENSAGEM: Erro na listagem de usuarios

        header('Ed-Return-Message: Erro na listagem de usuarios', true, 531);	
        echo '[]';

        die();
    }
}

function fazCheckout()
{
    $request = \Slim\Slim::getInstance()->request();
    $checkin = json_decode($request->getBody());

    $sql = "SELECT id_checkin, id_local FROM CHECKIN WHERE id_usuario = :id_usuario AND dt_checkout IS NULL";

    try{
            $conn = getConn();
            $stmt = $conn->prepare($sql);
            $stmt->bindParam("id_usuario",$checkin->id_usuario);
            $stmt->execute();

    } catch(PDOException $e){

        //ERRO 532
        //MENSAGEM: Erro ao buscar checkin

        header('Ed-Return-Message: Erro ao buscar checkin', true, 532);	
        echo '[]';

        die();
    }	

    $existe_checkin = $stmt->fetch(PDO::FETCH_OBJ);

    //Verifica se existe checkin corrente para o usuário. Se sim, faz o checkout.

    if($existe_checkin){

        $sql = "UPDATE CHECKIN SET dt_checkout = NOW() WHERE id_checkin = :id_checkin";

        try{
                $stmt = $conn->prepare($sql);
                $stmt->bindParam("id_checkin",$existe_checkin->id_checkin);
                $stmt->execute();

        } catch(PDOException $e){

            //ERRO 533
            //MENSAGEM: Erro ao fazer checkout

            header('Ed-Return-Message: Erro ao fazer checkout', true, 533);	
            echo '[]';

            die();
        }

        //Atualiza a tabela de checkins correntes

        $sql = "UPDATE CHECKINS_CORRENTES SET qt_checkin = qt_checkin - 1 WHERE id_local = :id_local";

        try{

            $stmt = $conn->prepare($sql);
            $stmt->bindParam("id_local",$existe_checkin->id_local);
            $stmt->execute();

        } catch(PDOException $e){

            //ERRO 534
            //MENSAGEM: Erro ao decrementar tabela de checkins correntes

            header('Ed-Return-Message: Erro ao fazer checkout', true, 534);	
            echo '[]';

            die();
        }

        //Expira todos os likes dados pelo usuário

        $sql = "UPDATE LIKES SET dt_expiracao = NOW() WHERE id_usuario1 = :id_usuario AND dt_expiracao IS NULL";

        try{

                $stmt = $conn->prepare($sql);
                $stmt->bindParam("id_usuario",$checkin->id_usuario);
                $stmt->execute();

        } catch(PDOException $e){

            //ERRO 535
            //MENSAGEM: Erro ao expirar os likes do usuario

            header('Ed-Return-Message: Erro ao expirar os likes do usuario', true, 535);	
            echo '[]';

            die();
        }

        echo "{\"Checkout\":{\"id_output\":\"1\",\"desc_output\":\"Checkout realizado.\"}}";		
    }
    else{
        //ERRO 536
        //MENSAGEM: Nao existe checkin corrente para o usuario

        header('Ed-Return-Message: Nao existe checkin corrente para o usuario', true, 536);	
        echo '[]';

        die();
    }
	
    $conn = null;
}

function verificaCheckinUsuario($id_usuario)
{
    $sql = "SELECT LOCAL.id_local, LOCAL.nome, LOCAL.latitude, LOCAL.longitude
            FROM LOCAL JOIN CHECKIN ON LOCAL.ID_LOCAL = CHECKIN.ID_LOCAL
            WHERE CHECKIN.ID_USUARIO = :id_usuario
              AND CHECKIN.DT_CHECKOUT IS NULL";
    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_usuario",$id_usuario);
        $stmt->execute();

        $local = $stmt->fetch(PDO::FETCH_OBJ);
        
    } catch(PDOException $e){
        //ERRO 537
        //MENSAGEM: Erro ao buscar local

        header('Ed-Return-Message: Erro ao buscar local', true, 537);	
        echo '[]';

        die();
    }

    if(!$local){
        echo "{\"Local\":{\"id_local\":\"0\",\"nome\":\"0\"}}";
    }else{
        
        try{
            $sql = "SELECT qt_checkin
                    FROM CHECKINS_CORRENTES
                    WHERE id_local = :id_local";

            $stmt = $conn->prepare($sql);
            $stmt->bindParam("id_local",$local->id_local);
            $stmt->execute();

            $qt_checkin = $stmt->fetch(PDO::FETCH_OBJ);
        
        } catch(PDOException $e){
            //ERRO 538
            //MENSAGEM: Erro ao buscar quantidade de checkins

            header('Ed-Return-Message: Erro ao buscar quantidade de checkins', true, 538);	
            echo '[]';

            die();
        }

        $local->qt_checkin = $qt_checkin->qt_checkin;

        echo "{\"Local\":" . json_encode($local) . "}";
    }

    $conn = null;
	
}

function listaMatches($id_usuario)
{
    $sql = "SELECT MATCHES.id_match, MATCHES.id_usuario2 AS id_usuario, USUARIO.nome AS nome_usuario, USUARIO.id_facebook AS facebook_usuario, USUARIO.id_qb, USUARIO.email AS email_usuario
            FROM MATCHES JOIN USUARIO ON MATCHES.id_usuario2 = USUARIO.id_usuario
            WHERE MATCHES.id_usuario1 = :id_usuario
                    AND DT_BLOCK IS NULL

            UNION ALL

            SELECT MATCHES.id_match, MATCHES.id_usuario1 AS id_usuario, USUARIO.nome AS nome_usuario, USUARIO.id_facebook AS facebook_usuario, USUARIO.id_qb, USUARIO.email AS email_usuario
            FROM MATCHES JOIN USUARIO ON MATCHES.id_usuario1 = USUARIO.id_usuario
            WHERE MATCHES.id_usuario2 = :id_usuario
                    AND DT_BLOCK IS NULL";
    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_usuario",$id_usuario);
        $stmt->execute();

        $matches = $stmt->fetchAll(PDO::FETCH_OBJ);

    } catch(PDOException $e){

        //ERRO 539
        //MENSAGEM: Erro ao buscar matches

        header('Ed-Return-Message: Erro ao buscar matches', true, 539);	
        echo '[]';

        die();
    }
    
    echo "{\"Matches\":" . json_encode($matches) . "}";

    $conn = null;
}

function unMatch()
{
    $request = \Slim\Slim::getInstance()->request();
    $unmatch = json_decode($request->getBody());
    
    try{
        
        $unmatch->apaga_chat = CallAPIQB("DELETE","https://api.quickblox.com/chat/Dialog/" . $unmatch->id_chat . ".json","","QB-Token: " . $unmatch->qbtoken);
            
    } catch(PDOException $e){

        //ERRO 544
        //MENSAGEM: Erro ao apagar chat no QB

        header('Ed-Return-Message: Erro ao apagar chat no QB', true, 544);	
        echo '[]';

        die();
    }
    
    $sql = "SELECT id_usuario FROM USUARIO
            WHERE id_facebook = :id_facebook_usuario";

    try{
            $conn = getConn();
            $stmt = $conn->prepare($sql);
            $stmt->bindParam("id_facebook_usuario",$unmatch->facebook_usuario);
            $stmt->execute();
            
            $usuario1 = $stmt->fetch(PDO::FETCH_OBJ);

    } catch(PDOException $e){

        //ERRO 545
        //MENSAGEM: Erro ao buscar ID do usuario 1

        header('Ed-Return-Message: Erro ao buscar ID do usuario 1', true, 546);	
        echo '[]';

        die();
    }
    
    $sql = "SELECT id_usuario FROM USUARIO
            WHERE id_facebook = :id_facebook_usuario2";

    try{
            $conn = getConn();
            $stmt = $conn->prepare($sql);
            $stmt->bindParam("id_facebook_usuario2",$unmatch->facebook_usuario2);
            $stmt->execute();
            
            $usuario2 = $stmt->fetch(PDO::FETCH_OBJ);

    } catch(PDOException $e){

        //ERRO 545
        //MENSAGEM: Erro ao buscar ID do usuario 2

        header('Ed-Return-Message: Erro ao buscar ID do usuario 2', true, 545);	
        echo '[]';

        die();
    }
    
    $sql = "UPDATE MATCHES SET dt_block = NOW() 
            WHERE (id_usuario1 = :id_usuario1 AND id_usuario2 = :id_usuario2)
              OR  (id_usuario1 = :id_usuario2 AND id_usuario2 = :id_usuario1)";

    try{
            $stmt = $conn->prepare($sql);
            $stmt->bindParam("id_usuario1",$usuario1->id_usuario);
            $stmt->bindParam("id_usuario2",$usuario2->id_usuario);
            $stmt->execute();

    } catch(PDOException $e){

        //ERRO 540
        //MENSAGEM: Erro ao desfazer match

        header('Ed-Return-Message: Erro ao desfazer match', true, 540);	
        echo '[]';

        die();
    }
    
    $unmatch->id_output = 1;
    $unmatch->desc_output = "Descombinação realizada.";
    
    echo "{\"Match\":" . json_encode($unmatch) . "}";
    
    $conn = null;
}

//Funções que chamam a Interface
function listaTodosUsuariosQuickblox()
{
	$request = \Slim\Slim::getInstance()->request();
	$usuario = json_decode($request->getBody());
	
	try{

            $token = $usuario->token;

            echo CallAPIQB("GET","http://api.quickblox.com/users.json",false,"QB-Token: ".$token);
                
	} catch(PDOException $e){
		
            //ERRO 541
            //MENSAGEM: Erro ao enviar requisicao para o QB

            header('Ed-Return-Message: Erro ao enviar requisicao para o QB', true, 541);	
            echo '[]';

            die();
	}
}

//Interface com API QuickBlox
function CallAPIQB($method, $url, $data, $qbtoken)
{
    header('Content-Type: application/json');
    $curl = curl_init();

    switch ($method)
    {
        case "POST":
            curl_setopt($curl, CURLOPT_POST, 1);

            if ($data)
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            break;
        case "PUT":
            curl_setopt($curl, CURLOPT_PUT, 1);
            break;
        case "DELETE":
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
        default:
            if ($data)
                $url = sprintf("%s?%s", $url, http_build_query($data));
    }
    
    curl_setopt($curl, CURLOPT_HTTPHEADER, array($qbtoken));

    //Autenticação se necessário:
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($curl, CURLOPT_USERPWD, "username:password");

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    return curl_exec($curl);
}

function apagaUsuario()
{
    $request = \Slim\Slim::getInstance()->request();
    $usuario = json_decode($request->getBody());

    $sql = "UPDATE USUARIO SET dt_exclusao = NOW() 
            WHERE id_facebook = :id_facebook";

    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_facebook",$usuario->facebook_usuario);
        $stmt->execute();

    } catch(PDOException $e){

        //ERRO 542
        //MENSAGEM: Erro ao apagar usuario

        header('Ed-Return-Message: Erro ao apagar usuario', true, 542);	
        echo '[]';

        die();
    }
    
    if($stmt->rowCount()){
    
        echo "{\"Usuario\":{\"id_output\":\"1\",\"desc_output\":\"Usuario apagado.\"}}";
    
    }
    else{
        //ERRO 542
        //MENSAGEM: Erro ao apagar usuario

        header('Ed-Return-Message: Erro ao apagar usuario', true, 542);	
        echo '[]';

        die();
    }
    
    $conn = null;
}