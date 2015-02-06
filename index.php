<?php
require 'Slim/Slim.php';
\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim();
$app->response()->header('Content-Type', 'application/json;charset=utf-8');

//GET METHODS
$app->get('/', function () { echo "{\"Erro\":\"diretório raiz\"}"; }); //erro no raiz
$app->get('/local/listatodoslocais','listaTodosLocais'); //traz todos locais
$app->get('/local/listaLocaisRange/:latitude_atual/:longitude_atual/:range/:order_by','listaLocaisRange'); //traz os locais dentro do range definido pelo usuario, baseando-se no local atual
$app->get('/checkin/listaUsuariosCheckin/:id_local/:sexo/:id_usuario','listaUsuariosCheckin'); //traz os usuarios com checkin corrente no local informado
$app->get('/checkin/verificaCheckinUsuario/:id_usuario','verificaCheckinUsuario'); //retorna o Local onde o usuario possui checkin corrente
$app->get('/match/listaMatches/:id_usuario','listaMatches'); //traz uma lista com todos os matches validos do usuario informado
$app->get('/promo/listaPromosUsuario/:id_usuario','listaPromosUsuario'); //traz uma lista com todos as promos do usuario informado
$app->get('/promo/verificapromolocal/:id_local','verificaPromoLocal'); //retorna o id do promo referente ao Local, caso exista
$app->get('/promo/verificapromosnaolidos/:id_usuario','verificaPromosNaoLidos'); //retorna 1 caso haja promos nao lidos na caixa de entrada, caso contrario retorna 0
$app->get('/configuracao/verificaconfiguracoes','verificaConfiguracoes'); //seta variaveis globais com configuracoes a serem usadas pelo app

//POST METHODS
$app->post('/local/adicionalocal','adicionaLocal'); //cria novo local
$app->post('/usuario/adicionausuario','adicionaUsuario'); //cria novo usuario
$app->post('/checkin/adicionacheckin','adicionaCheckin'); //faz checkin
$app->post('/like/adicionalike','adicionaLike'); //da like em alguem, em algum local
$app->post('/usuario/login','loginUsuario'); //faz login de usuario
$app->post('/promo/adicionapromocheckin','adicionaPromoCheckin'); //adiciona à caixa de entrada um Promo relacionado ao checkin do Usuario
$app->post('/erro/adicionaerroqb','adicionaErroQB'); //no caso de um erro no cadastro do usuario no QB, adiciona este registro à tabela

//PUT METHODS
$app->put('/checkin/fazcheckout','fazCheckout'); //cancela o checkin vigente do usuario
$app->put('/match/unmatch','unMatch'); //cancela o Match com o usuario informado
$app->put('/usuario/exclui','apagaUsuario'); //apaga usuario
$app->put('/promo/marcapromovisualizado','marcaPromoVisualizado'); //marca um Promo como visualizado na caixa de entrada do usuario
$app->put('/promo/exclui','apagaPromoUsuario'); //apaga um Promo da caixa de entrada de um usuario

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
		
		echo json_encode($locais);
		
		$conn = null;
		
	} catch(PDOException $e){
        echo '{"descricao":"'. $e->getMessage() .'"}';
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
	
	//Verifica qual selecao deve ser aplicada, se por checkins ou por distancia
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
    }elseif($order_by=="topcheckin"){
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
				ORDER BY qt_checkin DESC
    			LIMIT 20";
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
		echo json_encode($locais);
		
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

    //Verifica se o usuario inseriu um local dentro do tempo minimo definido nas configuracoes

    $sql = "SELECT TIME_TO_SEC(TIMEDIFF(NOW(),dt_local))/60 as minutos_ultimo_local FROM LOCAL WHERE id_usuario = :id_usuario ORDER BY dt_local DESC LIMIT 1";
    try{
            $conn = getConn();
            $stmt = $conn->prepare($sql);
            $stmt->bindParam("id_usuario",$local->id_usuario);
            $stmt->execute();
    } catch(PDOException $e){

        //ERRO 557
        //MENSAGEM: Erro ao buscar ultimo local criado pelo usuario

        header('Ed-Return-Message: Erro ao buscar ultimo local criado pelo usuario', true, 557);
        echo '[]';

        die();

        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
    }

	$ultimo_local = $stmt->fetch(PDO::FETCH_OBJ);
	
    $configuracoes = verificaConfiguracoes();

	//Se o usuario nunca criou local, ou se o ultimo local criado pelo usuario foi criado ha mais tempo que o minimo nas configuracoes
    if(!$ultimo_local || ($ultimo_local->minutos_ultimo_local > $configuracoes->t_local)){

        //Insere o novo local

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
            
            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';

            die();

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

        //Verifica se ha checkin corrente

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

        if($checkin){ //Se existe checkin previo, faz o checkout

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
            
            //Expira todos os likes dados pelo usuario

            $sql = "UPDATE LIKES SET dt_expiracao = NOW() WHERE id_usuario1 = :id_usuario AND dt_expiracao IS NULL";

            try{

                $stmt = $conn->prepare($sql);
                $stmt->bindParam("id_usuario",$local->id_usuario);
                $stmt->execute();

            } catch(PDOException $e){

                //ERRO 535
                //MENSAGEM: Erro ao expirar os likes do usuario

                header('Ed-Return-Message: Erro ao expirar os likes do usuario', true, 535);	
                echo '[]';

                die();
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

        echo json_encode($local);
    }
    else{
        //ERRO 558
        //MENSAGEM: Ultimo local criado abaixo do tempo minimo

        header('Ed-Return-Message: Ultimo local criado abaixo do tempo minimo', true, 558);
        echo '[]';

        die();
    }

    $conn = null;
	
}

function adicionaUsuario()
{
	$request = \Slim\Slim::getInstance()->request();
	$usuario = json_decode($request->getBody());
	
	$sql = "INSERT INTO USUARIO (nome, sobrenome, sexo, id_facebook, id_qb, email, dt_usuario, aniversario, cidade, pais) VALUES (:nome_usuario, :sobrenome_usuario, :sexo_usuario, :facebook_usuario, :quickblox_usuario, :email_usuario, NOW(), :aniversario_usuario, :cidade_usuario, :pais_usuario)";
	try{
		$conn = getConn();
		$stmt = $conn->prepare($sql);
		$stmt->bindParam("nome_usuario",$usuario->nome_usuario);
		$stmt->bindParam("sobrenome_usuario",$usuario->sobrenome_usuario);
		$stmt->bindParam("sexo_usuario",$usuario->sexo_usuario);
		$stmt->bindParam("facebook_usuario",$usuario->facebook_usuario);
		$stmt->bindParam("quickblox_usuario",$usuario->quickblox_usuario);
		$stmt->bindParam("email_usuario",$usuario->email_usuario);
		$stmt->bindParam("aniversario_usuario",$usuario->aniversario_usuario);
		$stmt->bindParam("cidade_usuario",$usuario->cidade_usuario);
		$stmt->bindParam("pais_usuario",$usuario->pais_usuario);
		$stmt->execute();
	} catch(PDOException $e){
		
		//ERRO 509
		//MENSAGEM: Erro ao adicionar novo usuario

		header('Ed-Return-Message: Erro ao adicionar novo usuario', true, 509);
		echo '[]';

		die();

		//echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	}
		
	$usuario->id_usuario = $conn->lastInsertId();
	
	$usuario->id_output = "1";
	$usuario->desc_output = "Usuario criado com sucesso.";
				
	echo json_encode($usuario);
	
	$conn = null;
}

function adicionaCheckin()
{
	$request = \Slim\Slim::getInstance()->request();
	$checkin = json_decode($request->getBody());

	//Verifica se o usuario ja tem algum checkin corrente
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
	
	if($checkin_vigente){		//Se ha checkin vigente para o usuario
	
		//retorna checkin_vigente = 1, o id e o local do checkin vigente
			
		$checkin->checkin_vigente = "1";
		$checkin->id_checkin_anterior = $checkin_vigente->id_checkin;
		$checkin->id_local_anterior = $checkin_vigente->id_local;
	
		// Verifica se o último checkin foi realizado fora do tempo minimo (valor setado na variavel global).
		
		$configuracoes = verificaConfiguracoes();
                
                if($checkin_vigente->minutos_ultimo_checkin > $configuracoes->t_checkin){		
		
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
                        
                        //Expira todos os likes dados pelo usuario

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
		}
		// Se o ultimo checkin foi realizado ha menos de 5 minutos, retorna mensagem de erro.
		else{
                        //ERRO 516
                        //MENSAGEM: Checkin anterior em menos de 5 minutos.

                        header('Ed-Return-Message: Checkin anterior em menos de 5 minutos', true, 516);
                        echo '[]';

                        die();

		}
	}
	else{ //Se o usuario não tem checkin vigente
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
        
        $checkin->t_checkin = $app->t_checkin;
	
	echo json_encode($checkin);
	
	$conn = null;
	
}

function adicionaLike()
{
    $request = \Slim\Slim::getInstance()->request();
    $like = json_decode($request->getBody());

    //Verifica se o usuario destino do like ainda tem um checkin valido

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

    //Se o usuario de destino fez o checkout
    if(!$stmt->fetchObject()){ 

        //ERRO 522
        //MENSAGEM: Usuario de destino realizou checkout

        header('Ed-Return-Message: Usuario de destino realizou checkout', true, 522);
        echo '[]';

        die();

        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';

    }
    else{ //Se o checkin do usuario destino ainda e valido
	
        //Verifica se o usuario ja foi curtido ou não

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

        //Se ja não existe like valido
        if(!$like_existente){

            //Da o like

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

            //Verifica se o outro usuario ja deu o like tambem, e se o mesmo ainda e valido
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

                    /*
					
					// Busca os IDs do QB dos usuarios

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

                    // Por algum motivo obscuro o QB se perde caso mandemos uma requisicao de um chat ja existente, mas com os IDs na ordem inversa. Desta forma, mandamos sempre na mesma ordem.

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

					*/
					
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
					
					//Busca o id_qb do usuario de destino para abertura do chat pelo app
					
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

                    $like->quickblox_usuario = $usuario2->id_qb;
            }

            $like->id_output = "1";
            $like->desc_output = "Like realizado com sucesso.";

        //Se ja ha o like valido, da deslike
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

        echo json_encode($like);
       
    }	
    
    $conn = null;
}

function loginUsuario()
{
    $request = \Slim\Slim::getInstance()->request();
    $usuario = json_decode($request->getBody());

    //Verifica dados

    $sql = "SELECT id_usuario, id_facebook AS facebook_usuario, id_qb AS quickblox_usuario, nome AS nome_usuario, sobrenome AS sobrenome_usuario, sexo AS sexo_usuario, dt_usuario, dt_exclusao, dt_bloqueio, email AS email_usuario, cidade AS cidade_usuario, pais AS pais_usuario, idioma AS idioma_usuario
            FROM USUARIO WHERE id_facebook = :id_facebook";
    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_facebook",$usuario->facebook_usuario);
        $stmt->execute();

        $registro_usuario = $stmt->fetch(PDO::FETCH_OBJ);

    } catch(PDOException $e){

        //ERRO 530
        //MENSAGEM: Erro ao buscar usuario

        header('Ed-Return-Message: Erro ao buscar usuario', true, 530);	
        echo '[]';

        die();
    }

    //Se o usuario foi encontrado
    if($registro_usuario){

        //Verificando se usuario foi bloqueado logicamente atraves do preenchimento do campo DT_BLOQUEIO
        if($registro_usuario->dt_bloqueio != null){

            //ERRO 501
            //MENSAGEM: Usuario bloqueado

            header('Ed-Return-Message: Usuario bloqueado', true, 501);	
            echo '[]';

            die();	

        } 
        else{
            if($registro_usuario->dt_exclusao != null){ //Verificando se usuario foi excluido logicamente atraves do preenchimento do campo DT_EXCLUSAO

                //Seta NULL no campo DT_EXCLUSAO, pois o usuario deseja retornar ao aplicativo

                $sql = "UPDATE USUARIO SET dt_exclusao = NULL WHERE id_usuario = :id_usuario";

                try{
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam("id_usuario",$registro_usuario->id_usuario);
                    $stmt->execute();

                } catch(PDOException $e){

                    //ERRO 546
                    //MENSAGEM: Erro ao remover data de exclusao do usuario

                    header('Ed-Return-Message: Erro ao remover data de exclusao do usuario', true, 546);	
                    echo '[]';

                    die();
                }
            }
            
            //Adequa data de nascimento
            
            $usuario->aniversario_usuario = date("Y-m-d", strtotime($usuario->aniversario_usuario));
			
            //Verifica se houve alteracao das informacoes pessoais

            if($registro_usuario->nome != $usuario->nome_usuario || $registro_usuario->sobrenome != $usuario->sobrenome_usuario || $registro_usuario->sexo != $usuario->sexo_usuario || $registro_usuario->email != $usuario->email_usuario || $registro_usuario->aniversario != $usuario->aniversario_usuario || $registro_usuario->cidade != $usuario->cidade_usuario || $registro_usuario->pais != $usuario->pais_usuario || $registro_usuario->idioma != $usuario->idioma_usuario){
            //Se houve alteracao em algum dos dados, atualiza o registro do usuario na base do Onrange

                $sql = "UPDATE USUARIO SET nome = :nome_usuario, sobrenome = :sobrenome_usuario, sexo = :sexo_usuario, email = :email_usuario, aniversario = :aniversario_usuario, cidade = :cidade_usuario, pais = :pais_usuario, idioma = :idioma_usuario WHERE id_usuario = :id_usuario";
                try{
                        $stmt = $conn->prepare($sql);
                        $stmt->bindParam("id_usuario",$registro_usuario->id_usuario);
                        $stmt->bindParam("nome_usuario",$usuario->nome_usuario);
                        $stmt->bindParam("sobrenome_usuario",$usuario->sobrenome_usuario);
                        $stmt->bindParam("sexo_usuario",$usuario->sexo_usuario);
                        $stmt->bindParam("email_usuario",$usuario->email_usuario);
                        $stmt->bindParam("aniversario_usuario",$usuario->aniversario_usuario);
                        $stmt->bindParam("cidade_usuario",$usuario->cidade_usuario);
                        $stmt->bindParam("pais_usuario",$usuario->pais_usuario);
                        $stmt->bindParam("idioma_usuario",$usuario->idioma_usuario);
                        $stmt->execute();
                } catch(PDOException $e){

                        //ERRO 511
                        //MENSAGEM: Erro ao autalizar usuario

                        header('Ed-Return-Message: Erro ao autalizar usuario', true, 511);
                        echo '[]';

                        die();

                        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';

                }
            }
            //Login realizado com sucesso. Retorna o objeto com os dados do usuario

            $usuario->id_usuario = $registro_usuario->id_usuario;
            $usuario->quickblox_usuario = $registro_usuario->quickblox_usuario;
            
            echo json_encode($usuario);

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

        echo json_encode($usuarios);

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

    //Verifica se existe checkin corrente para o usuario. Se sim, faz o checkout.

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

        //Expira todos os likes dados pelo usuario

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

        echo "{\"id_output\":\"1\",\"desc_output\":\"Checkout realizado.\"}";		
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
    $sql = "SELECT LOCAL.id_local, LOCAL.nome, LOCAL.latitude, LOCAL.longitude, LOCAL.destaque
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
        echo "{\"id_local\":\"0\",\"nome\":\"0\"}";
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

        echo json_encode($local);
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
    
    echo json_encode($matches);

    $conn = null;
}

function unMatch()
{
    $request = \Slim\Slim::getInstance()->request();
    $unmatch = json_decode($request->getBody());
    
    $FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/parametros'.date('Y-m-d').".txt";
    $FILE_LOG = fopen($FILE_LOG_DIR, "a+");
    
    $PARAMETROS .= "id_chat: {$unmatch->id_chat}\r\n";
    $PARAMETROS .= "qbtoken: {$unmatch->qbtoken}\r\n";
    fwrite($FILE_LOG, $PARAMETROS);
    fclose($FILE_LOG);
    
    try{
        
        $unmatch->apaga_chat = CallAPIQB("DELETE","https://api.quickblox.com/chat/Dialog/" . $unmatch->id_chat . ".json","","QB-Token: " . $unmatch->qbtoken);
            
    } catch(PDOException $e){

        //ERRO 544
        //MENSAGEM: Erro ao apagar chat no QB

        header('Ed-Return-Message: Erro ao apagar chat no QB', true, 544);	
        echo '[]';

        die();
    }
    

    /*
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
            WHERE ((id_usuario1 = :id_usuario1 AND id_usuario2 = :id_usuario2)
              OR  (id_usuario1 = :id_usuario2 AND id_usuario2 = :id_usuario1))
              AND dt_block IS NOT NULL";

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
    
    //Expira os likes dos envolvidos
    $sql = "UPDATE LIKES SET dt_expiracao = NOW() 
            WHERE ((id_usuario1 = :id_usuario1 AND id_usuario2 = :id_usuario2)
              OR  (id_usuario1 = :id_usuario2 AND id_usuario2 = :id_usuario1))
              AND dt_expiracao IS NOT NULL";

    try{
            $stmt = $conn->prepare($sql);
            $stmt->bindParam("id_usuario1",$usuario1->id_usuario);
            $stmt->bindParam("id_usuario2",$usuario2->id_usuario);
            $stmt->execute();

    } catch(PDOException $e){

        //ERRO 560
        //MENSAGEM: Erro ao desfazer likes

        header('Ed-Return-Message: Erro ao desfazer likes', true, 560);	
        echo '[]';

        die();
    }
    
    */
    
    $unmatch->id_output = 1;
    $unmatch->desc_output = "Descombinacao realizada.";
    
    echo json_encode($unmatch);
    
    $conn = null;
}

//Funcoes que chamam a Interface
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
	header('QB-Token: '. $qbtoken);
	
	$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/'.date('Y-m-d').".txt";
	$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	
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

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_TIMEOUT, 40);
    curl_setopt($curl, CURLOPT_VERBOSE, 1);
    curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG);
    
    $PARAMETROS .= "Method: {$method}\r\n";
    $PARAMETROS .= "URL: {$url}\r\n";
    $PARAMETROS .= "{$qbtoken}\r\n\r\n";
     
    fwrite($FILE_LOG, $PARAMETROS);
    
    $response = curl_exec($curl);
    
    $LOG_TXT .= "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";
    
    fwrite($FILE_LOG, $LOG_TXT);
    
    fclose($FILE_LOG);
    
    return $response;
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
    
        echo "{\"id_output\":\"1\",\"desc_output\":\"Usuario apagado.\"}";
    
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

function listaPromosUsuario($id_usuario)
{
    $sql = "SELECT PROMO.id_promo, LOCAL.nome AS local, PROMO.nome, PROMO.descricao, PROMO.dt_inicio, PROMO.dt_fim, PROMO.lote, PROMO.dt_disponibilizacao, PROMO.dt_promo,
            PROMO_USUARIO_CODIGO.codigo_promo, PROMO_USUARIO_CODIGO.id_codigo_promo, PROMO_USUARIO.dt_utilizacao, PROMO_USUARIO.dt_visualizacao
            FROM PROMO JOIN LOCAL ON PROMO.id_local = LOCAL.id_local
                       JOIN PROMO_USUARIO_CODIGO ON PROMO.id_promo = PROMO_USUARIO_CODIGO.id_promo
                       JOIN PROMO_USUARIO ON PROMO_USUARIO_CODIGO.id_codigo_promo = PROMO_USUARIO.id_codigo_promo
            WHERE PROMO_USUARIO.id_usuario = :id_usuario
                AND PROMO_USUARIO.dt_exclusao IS NULL
                    ORDER BY PROMO.dt_inicio DESC";
    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_usuario",$id_usuario);
        $stmt->execute();

        $promos = $stmt->fetchAll(PDO::FETCH_OBJ);

    } catch(PDOException $e){

        //ERRO 547
        //MENSAGEM: Erro ao buscar promos

        header('Ed-Return-Message: Erro ao buscar promos', true, 547);	
        echo '[]';

        die();
    }
    
    echo json_encode($promos);

    $conn = null;
}

function marcaPromoVisualizado()
{
    $request = \Slim\Slim::getInstance()->request();
    $promo = json_decode($request->getBody());

    $sql = "UPDATE PROMO_USUARIO SET dt_visualizacao = NOW() 
            WHERE id_codigo_promo = :id_codigo_promo";

    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_codigo_promo",$promo->id_codigo_promo);
        $stmt->execute();

    } catch(PDOException $e){

        //ERRO 548
        //MENSAGEM: Erro ao marcar promo visualizado

        header('Ed-Return-Message: Erro ao marcar promo visualizado', true, 548);	
        echo '[]';

        die();
    }
    
    if($stmt->rowCount()){
    
        echo "{\"id_output\":\"1\",\"desc_output\":\"Promo marcado como visualizado.\"}";
    
    }
    else{
        //ERRO 548
        //MENSAGEM: Erro ao marcar promo visualizado

        header('Ed-Return-Message: Erro ao marcar promo visualizado', true, 548);	
        echo '[]';

        die();
    }
    
    $conn = null;
}

function apagaPromoUsuario()
{
    $request = \Slim\Slim::getInstance()->request();
    $promo = json_decode($request->getBody());

    $sql = "UPDATE PROMO_USUARIO SET dt_exclusao = NOW() 
            WHERE id_promo = :id_promo
              AND id_usuario = :id_usuario";

    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_promo",$promo->id_promo);
        $stmt->bindParam("id_usuario",$promo->id_usuario);
        $stmt->execute();

    } catch(PDOException $e){

        //ERRO 549
        //MENSAGEM: Erro ao apagar promo

        header('Ed-Return-Message: Erro ao apagar promo', true, 549);	
        echo '[]';

        die();
    }
    
    if($stmt->rowCount()){
    
        echo "{\"id_output\":\"1\",\"desc_output\":\"Promo apagado com sucesso.\"}";
    
    }
    else{
        //ERRO 549
        //MENSAGEM: Erro ao apagar promo

        header('Ed-Return-Message: Erro ao apagar promo', true, 549);	
        echo '[]';

        die();
    }
    
    $conn = null;
}

function adicionaPromoCheckin()
{
    $request = \Slim\Slim::getInstance()->request();
    $promo_usuario = json_decode($request->getBody());

    //Verifica se ainda ha lote disponivel para o promo

    $sql = "SELECT id_codigo_promo FROM PROMO_USUARIO_CODIGO"
            . " WHERE id_promo = :id_promo AND dt_utilizacao IS NULL"
            . " LIMIT 1";

    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_promo",$promo_usuario->id_promo);
        $stmt->execute();

    } catch(PDOException $e){

        //ERRO 550
        //MENSAGEM: Erro ao verificar codigos de promo disponiveis

        header('Ed-Return-Message: Erro ao verificar codigos de promo disponiveis', true, 550);
        echo '[]';

        die();

        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';

    }	

    $codigo_disponivel = $stmt->fetch(PDO::FETCH_OBJ);

    if($codigo_disponivel){ //Se existe codigo disponivel

        //Insere na tabela de promocoes e usuarios

        $sql = "INSERT INTO PROMO_USUARIO (id_usuario, id_codigo_promo) VALUES (:id_usuario, :id_codigo_promo)";
        try{
                $conn = getConn();
                $stmt = $conn->prepare($sql);
                $stmt->bindParam("id_usuario",$promo_usuario->id_usuario);
                $stmt->bindParam("id_codigo_promo",$codigo_disponivel->id_codigo_promo);
                $stmt->execute();
                $promo_usuario->id_promo_usuario = $conn->lastInsertId();

                $promo_usuario->id_output = "1";
                $promo_usuario->desc_output = "Promo adicionado com sucesso.";

        } catch(PDOException $e){

            //ERRO 551
            //MENSAGEM: Erro ao adicionar promo ao usuario

            header('Ed-Return-Message: Erro ao adicionar promo ao usuario', true, 551);
            echo '[]';

            die();

            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
        }

        //Marca o codigo como utilizado

        $sql = "UPDATE PROMO_USUARIO_CODIGO SET dt_utilizacao = NOW() 
                WHERE id_codigo_promo = :id_codigo_promo";

        try{
            $conn = getConn();
            $stmt = $conn->prepare($sql);
            $stmt->bindParam("id_codigo_promo",$codigo_disponivel->id_codigo_promo);
            $stmt->execute();

        } catch(PDOException $e){

            //ERRO 553
            //MENSAGEM: Erro ao marcar codigo como utilizado

            header('Ed-Return-Message: Erro ao marcar codigo como utilizado', true, 553);	
            echo '[]';

            die();
        }

        if(!$stmt->rowCount()){

            //ERRO 553
            //MENSAGEM: Erro ao marcar codigo como utilizado

            header('Ed-Return-Message: Erro ao marcar codigo como utilizado', true, 553);	
            echo '[]';

            die();
        }
    }
    else{

        //ERRO 552
        //MENSAGEM: Nao ha lote disponivel

        header('Ed-Return-Message: Nao ha lote disponivel', true, 552);
        echo '[]';

        die();

    }


    // Retorna o objeto do Promo criado

    echo json_encode($promo_usuario);

    $conn = null;
	
}

function verificaPromoLocal($id_local)
{
    $sql = "SELECT id_promo, nome, descricao 
            FROM PROMO
            WHERE PROMO.id_local = :id_local
              AND NOW() BETWEEN dt_inicio AND dt_fim
              AND promo_checkin = 1";
    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_local",$id_local);
        $stmt->execute();

        $promo = $stmt->fetch(PDO::FETCH_OBJ);
        
    } catch(PDOException $e){
        //ERRO 554
        //MENSAGEM: Erro ao verificar promo

        header('Ed-Return-Message: Erro ao verificar promo', true, 554);	
        echo '[]';

        die();
    }

    if(!$promo){
        echo "{\"id_promo\":\"0\"}";
    }else{
        echo json_encode($promo);
    }

    $conn = null;
	
}

function verificaPromosNaoLidos($id_usuario)
{
    $sql = "SELECT 1 from PROMO_USUARIO "
            . "WHERE id_usuario = :id_usuario "
            . "AND dt_visualizacao IS NULL "
            . "LIMIT 1";
    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_usuario",$id_usuario);
        $stmt->execute();

        $naolidos = $stmt->fetch(PDO::FETCH_OBJ);
        
    } catch(PDOException $e){
        //ERRO 555
        //MENSAGEM: Erro ao verificar promos

        header('Ed-Return-Message: Erro ao verificar promos', true, 555);	
        echo '[]';

        die();
    }

    if($naolidos){
        echo "{\"nao_lido\":\"1\"}";
    }else{        
        echo "{\"nao_lido\":\"0\"}";
    }

    $conn = null;
	
}

function verificaConfiguracoes()
{
    $sql = "SELECT t_checkin, t_local from CONFIGURACAO";
    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->execute();

        $configuracoes = $stmt->fetch(PDO::FETCH_OBJ);
        
    } catch(PDOException $e){
        //ERRO 556
        //MENSAGEM: Erro ao verificar configuracoes

        header('Ed-Return-Message: Erro ao verificar configuracoes', true, 556);	
        echo '[]';

        die();
    }
    
    $conn = null;
    
    return $configuracoes;
	
}

function adicionaErroQB()
{
    $request = \Slim\Slim::getInstance()->request();
    $erroQB = json_decode($request->getBody());

    $sql = "INSERT INTO ERRO_QB (id_facebook, erro, funcao, plataforma) VALUES (:facebook_usuario, :erro, :funcao, :plataforma)";
    try{
        $conn = getConn();    
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("facebook_usuario",$erroQB->facebook_usuario);
        $stmt->bindParam("erro",$erroQB->erro);
		$stmt->bindParam("funcao",$erroQB->funcao);
		$stmt->bindParam("plataforma",$erroQB->plataforma);
        $stmt->execute();
    } catch(PDOException $e){

        //ERRO 559
        //MENSAGEM: Erro ao adicionar erro do QB

        header('Ed-Return-Message: Erro ao adicionar erro do QB', true, 559);
        echo '[]';

        die();

        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
    }

    echo json_encode($erroQB);

    $conn = null;
}