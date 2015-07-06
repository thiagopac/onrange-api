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
$app->get('/checkin/listaUsuariosCheckinWidget/:id_local','listaUsuariosCheckinWidget'); //traz os facebook_usuarios com checkin corrente no local informado para o widget
$app->get('/checkin/verificaCheckinUsuario/:id_usuario','verificaCheckinUsuario'); //retorna o Local onde o usuario possui checkin corrente
$app->get('/match/listaMatches/:id_usuario','listaMatches'); //traz uma lista com todos os matches validos do usuario informado
$app->get('/match/listaChats/:id_usuario','listaChats'); //traz um json do QuickBlox com todos os dados dos chats do usu�rio
$app->get('/promo/listaPromosUsuario/:id_usuario','listaPromosUsuario'); //traz uma lista com todos as promos do usuario informado
$app->get('/promo/verificapromolocal/:id_local','verificaPromoLocal'); //retorna o id do promo referente ao Local, caso exista
$app->get('/promo/verificapromosnaolidos/:id_usuario','verificaPromosNaoLidos'); //retorna 1 caso haja promos nao lidos na caixa de entrada, caso contrario retorna 0
$app->get('/configuracao/verificaconfiguracoes','verificaConfiguracoes'); //seta variaveis globais com configuracoes a serem usadas pelo app
$app->get('/promo/adicionapromocheckin/:id_promo/:id_usuario','adicionaPromoCheckin'); //adiciona à caixa de entrada um Promo relacionado ao checkin do Usuario

//POST METHODS
$app->post('/local/adicionalocal','adicionaLocal'); //cria novo local
$app->post('/usuario/adicionausuario','adicionaUsuario'); //cria novo usuario

//CRIADO PARA TESTES, APAGAR
$app->post('/usuario/adicionausuario2','adicionaUsuario2'); //cria novo usuario
//

$app->post('/checkin/adicionacheckin','adicionaCheckin'); //faz checkin
$app->post('/like/adicionalike','adicionaLike'); //da like em alguem, em algum local
$app->post('/usuario/login','loginUsuario'); //faz login de usuario
$app->post('/erro/adicionaerroqb','adicionaErroQB'); //no caso de um erro no cadastro do usuario no QB, adiciona este registro à tabela
$app->post('/push/enviapush','enviaPush'); //envia push através do QuickBlox

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
	//return new PDO('mysql:host=mysql.hostinger.com.br;dbname=u138894269_onrng','u138894269_onrng','onrange8375',
	return new PDO('mysql:host=localhost;dbname=onrange-homologacao','root','0nr4ng364638375m1r0',
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
	//gambiarra se chegar nulo ou zerado do android
// 	if ($latitude_atual == null || $latitude_atual == 0 || $longitude_atual == null || $longitude_atual == 0) {
// 		$latitude_atual = "-19.919128";
// 		$longitude_atual = "-43.938628"; 
// 	}
	
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
					SELECT LOCAL.id_local, LOCAL.nome, LOCAL.latitude, LOCAL.longitude, CHECKINS_CORRENTES.qt_checkin, LOCAL.id_tipo_local, CASE WHEN PROMO.promo_checkin = 1 THEN 1 ELSE 0 END AS destaque
					FROM LOCAL JOIN CHECKINS_CORRENTES ON LOCAL.id_local = CHECKINS_CORRENTES.id_local
						 LEFT JOIN PROMO ON (LOCAL.id_local = PROMO.id_local AND PROMO.dt_fim_lote IS NULL AND NOW() between PROMO.dt_inicio AND PROMO.dt_fim)
					WHERE
					(CHECKINS_CORRENTES.qt_checkin > 0 OR PROMO.promo_checkin = 1)
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
					SELECT LOCAL.id_local, LOCAL.nome, LOCAL.latitude, LOCAL.longitude, CHECKINS_CORRENTES.qt_checkin, LOCAL.id_tipo_local, CASE WHEN PROMO.promo_checkin = 1 THEN 1 ELSE 0 END AS destaque
					FROM LOCAL JOIN CHECKINS_CORRENTES ON LOCAL.id_local = CHECKINS_CORRENTES.id_local
    					 LEFT JOIN PROMO ON (LOCAL.id_local = PROMO.id_local AND PROMO.dt_fim_lote IS NULL AND NOW() between PROMO.dt_inicio AND PROMO.dt_fim)
					WHERE
					(CHECKINS_CORRENTES.qt_checkin > 0 OR PROMO.promo_checkin = 1)
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
					SELECT LOCAL.id_local, LOCAL.nome, LOCAL.latitude, LOCAL.longitude, CHECKINS_CORRENTES.qt_checkin, LOCAL.id_tipo_local, CASE WHEN PROMO.promo_checkin IS NULL OR PROMO.promo_checkin = 0 THEN 0 ELSE 1 END AS destaque
					FROM LOCAL JOIN CHECKINS_CORRENTES ON LOCAL.id_local = CHECKINS_CORRENTES.id_local
				         LEFT JOIN PROMO ON (LOCAL.id_local = PROMO.id_local AND PROMO.dt_fim_lote IS NULL AND NOW() between PROMO.dt_inicio AND PROMO.dt_fim)
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
            
            header('HTTP/1.1 502 Erro na listagem de locais');
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

        header('HTTP/1.1 557 Erro ao buscar ultimo local criado pelo usuario');
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

            header('HTTP/1.1 503 Erro ao adicionar novo local');
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

            header('HTTP/1.1 504 Erro ao adicionar novo local em checkins correntes');
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

            header('HTTP/1.1 505 Erro ao verificar checkin corrente do usuario');
            echo '[]';

            die();

            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';

        }	

        $checkin = $stmt->fetch(PDO::FETCH_OBJ);

        if($checkin){ //Se existe checkin previo, faz o checkout

            $sql = "UPDATE CHECKIN SET dt_checkout = NOW(), id_tipo_checkout = 3 WHERE id_checkin = :id_checkin";

            try{
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam("id_checkin",$checkin->id_checkin);
                    $stmt->execute();

            } catch(PDOException $e){
                    //ERRO 519
                    //MENSAGEM: Erro ao realizar checkout no local anterior

                    header('HTTP/1.1 519 Erro ao realizar checkout no local anterior');
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

                    header('HTTP/1.1 506 Erro ao decrementar tabela de checkins correntes');
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

                header('HTTP/1.1 535 Erro ao expirar os likes do usuario');	
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

            header('HTTP/1.1 507 Erro ao fazer checkin no local criado');
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

            header('HTTP/1.1 520 Erro ao incrementar tabela de checkins correntes');
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

        header('HTTP/1.1 558 Ultimo local criado abaixo do tempo minimo');
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
		$stmt->bindParam("sobrenome_usuario",$usuario->sobrenome_usuario);
		$stmt->bindParam("sexo_usuario",$usuario->sexo_usuario);
		$stmt->bindParam("facebook_usuario",$usuario->facebook_usuario);
		$stmt->bindParam("quickblox_usuario",$usuario->quickblox_usuario);
		$stmt->bindParam("email_usuario",$usuario->email_usuario);
		$stmt->bindParam("aniversario_usuario",$usuario->aniversario_usuario);
		$stmt->bindParam("cidade_usuario",$usuario->cidade_usuario);
		$stmt->bindParam("pais_usuario",$usuario->pais_usuario);
		
		//SE NOME DO USUÁRIO TEM MENOS QUE 3 CARACTERES, DEVEMOS CONCATENAR UM CARACTERE INVISÍVEL PARA CADASTRAR NO QUICKBLOX, SENÃO RETORNA ERRO {"errors":{"full_name":["is invalid","is too short (minimum is 3 characters)"]}}
		
		if (strlen($usuario->nome_usuario)<3) {
			//$usuario->nome_usuario = $usuario->nome_usuario."%C2%A0";
			$usuario->nome_usuario = $usuario->nome_usuario." ";
		}
		$stmt->bindParam("nome_usuario",$usuario->nome_usuario);
		
		$stmt->execute();
		
		ApiAppSessionCreate($usuario->facebook_usuario, $usuario->email_usuario, $usuario->nome_usuario);
	} catch(PDOException $e){
		
		//ERRO 509
		//MENSAGEM: Erro ao adicionar novo usuario

		header('HTTP/1.1 509 Erro ao adicionar novo usuario');
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

function adicionaUsuario2()
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

// 		$stmt->execute();

		ApiAppSessionCreate($usuario->facebook_usuario, $usuario->email_usuario, $usuario->nome_usuario);
	} catch(PDOException $e){

		//ERRO 509
		//MENSAGEM: Erro ao adicionar novo usuario

		header('HTTP/1.1 509 Erro ao adicionar novo usuario');
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

            header('HTTP/1.1 513 Erro ao buscar checkins');
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
			
			$sql = "UPDATE CHECKIN SET dt_checkout = NOW(), id_tipo_checkout = 3 WHERE id_checkin = :id_checkin";
			try{
			$stmt = $conn->prepare($sql);
			$stmt->bindParam("id_checkin",$checkin_vigente->id_checkin);
			$stmt->execute();
			} catch(PDOException $e){
              //ERRO 514
              //MENSAGEM: Erro ao fazer checkout pre-checkin

              header('HTTP/1.1 514 Erro ao fazer checkout pre-checkin');
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

              header('HTTP/1.1 515 Erro ao decrementar tabela de checkins correntes');
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

                header('HTTP/1.1 535 Erro ao expirar os likes do usuario');	
                echo '[]';

                die();
                }
		}
		// Se o ultimo checkin foi realizado ha menos de X minutos, retorna mensagem de erro.
		else{
             //ERRO 516
             //MENSAGEM: Checkin anterior com tempo inferior ao minimo estabelecido.

             header('HTTP/1.1 516 Checkin anterior com tempo inferior ao minimo estabelecido');
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

            header('HTTP/1.1 517 Erro ao fazer checkin');
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

            header('HTTP/1.1 518 Erro ao incrementar tabela de checkins correntes');
            echo '[]';

            die();

            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	}

	$checkin->id_output = "1";
	$checkin->desc_output = "Checkin realizado com sucesso.";
        
    //$checkin->t_checkin = $app->t_checkin;
    
    //Verifica se o local possui checkin premiado E se o usuario ainda nao tem este Promo em sua caixa de entrada
    
    $sql = "SELECT id_promo FROM PROMO
    		WHERE id_local = :id_local 
    		  AND promo_checkin = 1
    		  AND NOW() between dt_inicio AND dt_fim
    		  AND :id_usuario NOT IN (SELECT id_usuario FROM PROMO_CODIGO_USUARIO WHERE PROMO_CODIGO_USUARIO.id_promo = PROMO.id_promo AND id_usuario IS NOT NULL)";
    try{
    	$stmt = $conn->prepare($sql);
    	$stmt->bindParam("id_local",$checkin->id_local);
    	$stmt->bindParam("id_usuario",$checkin->id_usuario);
    	$stmt->execute();
    	
    	$promo_checkin = $stmt->fetch(PDO::FETCH_OBJ);
    	
    } catch(PDOException $e){
    
        //ERRO 537
        //MENSAGEM: Erro ao buscar local

        header('HTTP/1.1 537 Erro ao buscar local');	
        echo '[]';

        die();
    }
    
    //Se o local possui promo de checkin e o usuario nao tem o Promo, adiciona o promo para o usuario
    if($promo_checkin){
    	
	   	$checkin->promo_checkin = adicionaPromoCheckin($promo_checkin->id_promo,$checkin->id_usuario);
    }
    
 	echo json_encode($checkin);
	
	$conn = null;
	
}

function adicionaLike()
{
    $request = \Slim\Slim::getInstance()->request();
    $like = json_decode($request->getBody());
    
    //Verifica se por alguma inconsistência dos aplicativos, já existe um match dos dois usuários
    
    $sql = "SELECT 1 FROM MATCHES WHERE ((id_usuario1 = :id_usuario1 AND id_usuario2 = :id_usuario2) 
    		OR (id_usuario1 = :id_usuario2 AND id_usuario2 = :id_usuario1)) AND DT_BLOCK IS NULL";
    try{
    	$conn = getConn();
    	$stmt = $conn->prepare($sql);
    	$stmt->bindParam("id_usuario1",$like->id_usuario1);
    	$stmt->bindParam("id_usuario2",$like->id_usuario2);
    	$stmt->execute();
    
    } catch(PDOException $e){
    
    	//ERRO 525
        //MENSAGEM: Erro ao verificar se houve match

        header('HTTP/1.1 525 Erro ao verificar se houve match');
        echo '[]';

        die();
    
    	//echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
    }
    
    //Se não existe match entre os dois, prossegue a rotina.
    if(!$stmt->fetchObject()){

	    //Verifica se o usuario destino do like ainda tem um checkin valido
	
	    $sql = "SELECT 1 FROM CHECKIN WHERE id_usuario = :id_usuario2 AND id_local = :id_local AND DT_CHECKOUT IS NULL";
	    try{
	            $stmt = $conn->prepare($sql);
	            $stmt->bindParam("id_usuario2",$like->id_usuario2);
	            $stmt->bindParam("id_local",$like->id_local);
	            $stmt->execute();
	
	    } catch(PDOException $e){
	
	        //ERRO 521
	        //MENSAGEM: Erro ao buscar checkin do usuario de destino
	
	        header('HTTP/1.1 521 Erro ao buscar checkin do usuario de destino');
	        echo '[]';
	
	        die();
	
	        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	    }
	
	    //Se o usuario de destino fez o checkout
	    if(!$stmt->fetchObject()){ 
	
	        //ERRO 522
	        //MENSAGEM: Usuario de destino realizou checkout
	
	    	header('HTTP/1.1 522 Usuario de destino realizou checkout');
	    	
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
	
	            header('HTTP/1.1 523 Erro ao verificar se ja existe like');
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
	
	                header('HTTP/1.1 524 Erro ao curtir');
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
	
	                header('HTTP/1.1 525 Erro ao verificar se houve match');
	                echo '[]';
	
	                die();
	
	                //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	            }
	
	            //Retorna match = 0 se não houver retorno do select
	
	            if(!$stmt->fetchObject())
	                    $like->match = "0";
	            else{	//--------------------------######## MATCH ########--------------------------//
	
	                    
						
						// Busca os id_facebook dos usuarios
	
	                    try{
	                    $sql = "SELECT id_facebook FROM USUARIO WHERE id_usuario = :id_usuario1";
	                    $stmt = $conn->prepare($sql);
	                    $stmt->bindParam("id_usuario1",$like->id_usuario1);
	                    $stmt->execute();
	                    $usuario1 = $stmt->fetch(PDO::FETCH_OBJ);
	
	                    } catch(PDOException $e){
	
	                        //ERRO 526
	                        //MENSAGEM: Erro ao buscar facebook_usuario do usuario 1
	
	                        header('HTTP/1.1 526 Erro ao buscar facebook_usuario do usuario 1');
	                        echo '[]';
	
	                        die();
	
	                        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	                    }
	
	                    try{
	                    $sql = "SELECT id_facebook FROM USUARIO WHERE id_usuario = :id_usuario2";
	                    $stmt = $conn->prepare($sql);
	                    $stmt->bindParam("id_usuario2",$like->id_usuario2);
	                    $stmt->execute();
	                    $usuario2 = $stmt->fetch(PDO::FETCH_OBJ);
	
	                    } catch(PDOException $e){
	
	                        //ERRO 527
	                        //MENSAGEM: Erro ao buscar facebook_usuario do usuario 2
	
	                        header('HTTP/1.1 527 Erro ao buscar facebook_usuario do usuario 2');
	                        echo '[]';
	
	                        die();
	
	                        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	                    }
	
	                    //######## CHAT ########//
	                    
	                    try{
	                    	//segue com a API com o fluxo para criar chat
	                    	ApiAppAndUserSessionCreate($usuario1->id_facebook, $usuario2->id_facebook, "DIALOG_CREATE", null, null, null);
	                    } catch(PDOException $e){
	
	                        //ERRO 543
	                        //MENSAGEM: Erro ao criar chat no QB
	
	                        header('HTTP/1.1 543 Erro ao criar chat no QB');	
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
	
	                        header('HTTP/1.1 528 Erro ao criar match');
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
	
	                        header('HTTP/1.1 527 Erro ao buscar ID do QB do usuario 2');
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
	
	                header('HTTP/1.1 529 Erro ao descurtir');
	                echo '[]';
	
	                die();
	
	                //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	            }
	
	            $like->id_output = "4";
	            $like->desc_output = "Deslike realizado com sucesso.";
	
	        }
	
	        echo json_encode($like);
	       
	    }	
    } 
    $conn = null;
}

function loginUsuario()
{
    $request = \Slim\Slim::getInstance()->request();
    $usuario = json_decode($request->getBody());

    //Verifica dados

    $sql = "SELECT id_usuario, id_facebook AS facebook_usuario, id_qb AS quickblox_usuario, nome AS nome_usuario, sobrenome AS sobrenome_usuario, sexo AS sexo_usuario, dt_usuario, dt_exclusao, dt_bloqueio, email AS email_usuario, aniversario AS aniversario_usuario, cidade AS cidade_usuario, pais AS pais_usuario, idioma AS idioma_usuario, logout 
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

        header('HTTP/1.1 530 Erro ao buscar usuario');	
        echo '[]';

        die();
    }

    //Se o usuario foi encontrado
    if($registro_usuario){

        //Verificando se usuario foi bloqueado logicamente atraves do preenchimento do campo DT_BLOQUEIO
        if($registro_usuario->dt_bloqueio != null){

            //ERRO 501
            //MENSAGEM: Usuario bloqueado

            header('HTTP/1.1 501 Usuario bloqueado');	
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

                    header('HTTP/1.1 546 Erro ao remover data de exclusao do usuario');	
                    echo '[]';

                    die();
                }
            }
            
            //Adequa data de nascimento
            
            $aniversario_usuario = date("Y-m-d", strtotime($registro_usuario->aniversario_usuario));
            
            $usuario->aniversario_usuario = $aniversario_usuario;
			
            //Verifica se houve alteracao das informacoes pessoais
            
            if(!isset($usuario->nome_usuario)) $usuario->nome_usuario = NULL;
            if(!isset($usuario->sobrenome_usuario)) $usuario->sobrenome_usuario = NULL;
            if(!isset($usuario->sexo_usuario)) $usuario->sexo_usuario = NULL;
            if(!isset($usuario->email_usuario)) $usuario->email_usuario = NULL;
            if(!isset($usuario->aniversario_usuario)) $usuario->aniversario_usuario = NULL;
            if(!isset($usuario->cidade_usuario)) $usuario->cidade_usuario = NULL;
            if(!isset($usuario->pais_usuario)) $usuario->pais_usuario = NULL;
            if(!isset($usuario->idioma_usuario)) $usuario->idioma_usuario = NULL;
            if(!isset($usuario->logout)) $usuario->logout = 0;
            
            
            $registro_nome_usuario = $registro_usuario->nome_usuario;
            $nome_usuario = $usuario->nome_usuario;
            $registro_sobrenome_usuario = $registro_usuario->sobrenome_usuario;
            $sobrenome_usuario = $usuario->sobrenome_usuario;
            $registro_sexo_usuario = $registro_usuario->sexo_usuario;
            $sexo_usuario = $usuario->sexo_usuario;
            $registro_email_usuario = $registro_usuario->email_usuario;
            $email_usuario = $usuario->email_usuario;
            $registro_aniversario_usuario = $registro_usuario->aniversario_usuario;
            $aniversario_usuario = $usuario->aniversario_usuario;
            $registro_cidade_usuario = $registro_usuario->cidade_usuario;
            $cidade_usuario = $usuario->cidade_usuario;
            $registro_pais_usuario = $registro_usuario->pais_usuario;
            $pais_usuario = $usuario->pais_usuario;
            $registro_idioma_usuario =  $registro_usuario->idioma_usuario;
            $idioma_usuario = $usuario->idioma_usuario;
            $registro_logout =  $registro_usuario->logout;
            $logout = $usuario->logout;
            
 

            if($registro_nome_usuario != $nome_usuario || $registro_sobrenome_usuario != $sobrenome_usuario || $registro_sexo_usuario != $sexo_usuario || $registro_email_usuario != $email_usuario || $registro_aniversario_usuario != $aniversario_usuario || $registro_cidade_usuario != $cidade_usuario || $registro_pais_usuario != $pais_usuario || $registro_idioma_usuario != $idioma_usuario || $registro_logout != $logout){
            //Se houve alteracao em algum dos dados, atualiza o registro do usuario na base do Onrange

                $sql = "UPDATE USUARIO SET nome = :nome_usuario, sobrenome = :sobrenome_usuario, sexo = :sexo_usuario, email = :email_usuario, aniversario = :aniversario_usuario, cidade = :cidade_usuario, pais = :pais_usuario, idioma = :idioma_usuario, logout = :logout WHERE id_usuario = :id_usuario";
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
                        $stmt->bindParam("logout",$usuario->logout);
                        $stmt->execute();
                } catch(PDOException $e){

                        //ERRO 511
                        //MENSAGEM: Erro ao autalizar usuario

                        header('HTTP/1.1 511 Erro ao autalizar usuario');
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

        header('HTTP/1.1 500 Usuario inexistente');	
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

        header('HTTP/1.1 531 Erro na listagem de usuarios');	
        echo '[]';

        die();
    }
}

function listaUsuariosCheckinWidget($id_local)
{
		$sql = "SELECT USUARIO.id_facebook as facebook_usuario
                FROM USUARIO INNER JOIN CHECKIN
                        ON USUARIO.id_usuario = CHECKIN.id_usuario
                WHERE CHECKIN.id_local = :id_local
                        AND CHECKIN.dt_checkout IS NULL
                ORDER BY CHECKIN.dt_checkin ASC";
	try{
		$conn = getConn();
		$stmt = $conn->prepare($sql);
		$stmt->bindParam("id_local",$id_local);
		$stmt->execute();
		$usuarios = $stmt->fetchAll(PDO::FETCH_OBJ);

		echo json_encode($usuarios);

		$conn = null;

	} catch(PDOException $e){

		//ERRO 531
		//MENSAGEM: Erro na listagem de usuarios

		header('HTTP/1.1 531 Erro na listagem de usuarios');
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

        header('HTTP/1.1 532 Erro ao buscar checkin');	
        echo '[]';

        die();
    }	

    $existe_checkin = $stmt->fetch(PDO::FETCH_OBJ);

    //Verifica se existe checkin corrente para o usuario. Se sim, faz o checkout.

    if($existe_checkin){

        $sql = "UPDATE CHECKIN SET dt_checkout = NOW(), id_tipo_checkout = 1 WHERE id_checkin = :id_checkin";

        try{
                $stmt = $conn->prepare($sql);
                $stmt->bindParam("id_checkin",$existe_checkin->id_checkin);
                $stmt->execute();

        } catch(PDOException $e){

            //ERRO 533
            //MENSAGEM: Erro ao fazer checkout
            
        	//echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';

            header('HTTP/1.1 533 Erro ao fazer checkout');	
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

            header('HTTP/1.1 534 Erro ao fazer checkout');	
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

            header('HTTP/1.1 535 Erro ao expirar os likes do usuario');	
            echo '[]';

            die();
        }

        echo "{\"id_output\":\"1\",\"desc_output\":\"Checkout realizado.\"}";		
    }
    else{
        //ERRO 536
        //MENSAGEM: Nao existe checkin corrente para o usuario

        header('HTTP/1.1 536 Nao existe checkin corrente para o usuario');	
        echo '[]';

        die();
    }
	
    $conn = null;
}

function verificaCheckinUsuario($id_usuario)
{
    $sql = "SELECT LOCAL.id_local, LOCAL.nome, LOCAL.latitude, LOCAL.longitude, CASE WHEN PROMO.promo_checkin IS NULL OR PROMO.promo_checkin = 0 THEN 0 ELSE 1 END AS destaque
            FROM LOCAL JOIN CHECKIN ON LOCAL.ID_LOCAL = CHECKIN.ID_LOCAL
    		     LEFT JOIN PROMO ON (LOCAL.id_local = PROMO.id_local AND NOW() between PROMO.dt_inicio AND PROMO.dt_fim AND PROMO.dt_fim_lote IS NULL)
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

        header('HTTP/1.1 537 Erro ao buscar local');	
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

            header('HTTP/1.1 538 Erro ao buscar quantidade de checkins');	
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

        header('HTTP/1.1 539 Erro ao buscar matches');	
        echo '[]';

        die();
    }
    
    echo json_encode($matches);

    $conn = null;
}

function listaChats($id_usuario)
{
	$sql = "SELECT USUARIO.id_facebook AS facebook_usuario
            FROM USUARIO
            WHERE USUARIO.id_usuario = :id_usuario";
	try{
		$conn = getConn();
		$stmt = $conn->prepare($sql);
		$stmt->bindParam("id_usuario",$id_usuario);
		$stmt->execute();

		$chats = $stmt->fetch(PDO::FETCH_OBJ);
		
		//INATIVADO POIS N�O ESTAMOS USANDO ESSE M�TODO PARA TRAZER CHAT
		//ApiAppAndUserSessionCreate($chats->facebook_usuario, null, "DIALOGS_RETRIEVE", null, null, null);
		
	} catch(PDOException $e){

		//ERRO 539
		//MENSAGEM: Erro ao buscar matches

		header('HTTP/1.1 539 Erro ao buscar chats');
		echo '[]';

		die();
	}

	$conn = null;
}

function unMatch()
{
    $request = \Slim\Slim::getInstance()->request();
    $unmatch = json_decode($request->getBody());
    
    $FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/parametros'.date('Y-m-d').".txt";
    //$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
    
    //$PARAMETROS .= "id_chat: {$unmatch->id_chat}\r\n";
    //$PARAMETROS .= "qbtoken: {$unmatch->qbtoken}\r\n";
    //fwrite($FILE_LOG, $PARAMETROS);
    //fclose($FILE_LOG);
    
    try{
        ApiAppAndUserSessionCreate($unmatch->facebook_usuario, null, "DIALOG_DELETE", $unmatch->id_chat, null, null);
        ApiAppAndUserSessionCreate($unmatch->facebook_usuario2, null, "DIALOG_DELETE", $unmatch->id_chat, null, null);
        //$unmatch->apaga_chat = CallAPIQB("DELETE","https://api.quickblox.com/chat/Dialog/" . $unmatch->id_chat . ".json","","QB-Token: " . $unmatch->qbtoken);
    } catch(PDOException $e){

        //ERRO 544
        //MENSAGEM: Erro ao apagar chat no QB

        header('HTTP/1.1 544 Erro ao apagar chat no QB');	
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

        header('HTTP/1.1 XXX Erro ao buscar ID do usuario 1');	
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

        header('HTTP/1.1 XXX Erro ao buscar ID do usuario 2');	
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

        header('HTTP/1.1 XXX Erro ao desfazer match');	
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

        header('HTTP/1.1 XXX Erro ao desfazer likes');	
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

            header('HTTP/1.1 541 Erro ao enviar requisicao para o QB');	
            echo '[]';

            die();
	}
}

//Cria sess�o do aplicativo e somente dele, sem nenhum usu�rio. Serve geralmente para cadastrar usu�rios no QB, ou outras fun��es que n�o necessitam da valida��o de usu�rio na sess�o
function ApiAppSessionCreate($facebook_usuario, $email, $nome)
{
	require 'config.php';

	// Credenciais do aplicativo
	$APPLICATION_ID = 10625;
	$AUTH_KEY = "rrTrFYFOECqjTAe";
	$AUTH_SECRET = "hM5vAmpBYYGV-p5";

	// API endpoint
	$QB_API_ENDPOINT = "https://api.quickblox.com";
	$QB_PATH_SESSION = "session.json";

	if($log == 1){//vari�vel definida no config.php
		//criando ou abrindo o log de cURL para escrita
		$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/api_app_session_create-'.date('Y-m-d').".txt";
		$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	}

	// Gerando a assinatura
	$nonce = rand();
	$timestamp = time();
	$signature_string = "application_id=".$APPLICATION_ID."&auth_key=".$AUTH_KEY."&nonce=".$nonce."&timestamp=".$timestamp;

	//echo "stringForSignature: " . $signature_string . "<br><br>";
	$signature = hash_hmac('sha1', $signature_string , $AUTH_SECRET);

	// Criando corpo da requisi��o
	$post_body = http_build_query(array(
			'application_id' => $APPLICATION_ID,
			'auth_key' => $AUTH_KEY,
			'timestamp' => $timestamp,
			'nonce' => $nonce,
			'signature' => $signature
	));

	// $post_body = "application_id=" . $APPLICATION_ID . "&auth_key=" . $AUTH_KEY . "&timestamp=" . $timestamp . "&nonce=" . $nonce . "&signature=" . $signature;

	//echo "postBody: " . $post_body . "<br><br>";
	// Configurando cURL
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, $QB_API_ENDPOINT . '/' . $QB_PATH_SESSION); // Caminho completo � https://api.quickblox.com/session.json
	curl_setopt($curl, CURLOPT_POST, true); // Usar POST
	curl_setopt($curl, CURLOPT_POSTFIELDS, $post_body); // Encapsulando o body
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Recebendo a resposta
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //retirar em produ��o se n�o estiver funcionando pois ignora SSL
	//curl_setopt($curl, CURLOPT_VERBOSE, 1); //liga o verbose para mostrar resultado em browser
	curl_setopt($curl, CURLOPT_TIMEOUT, 40); //timeout com boa demora, pra n�o termos problemas com requsi��es expiriadas mto r�pido

	if($log == 1){
		curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG); //output em todo o fluxo cURL
	}


	$PARAMETROS = "Body: {$post_body}\r\n\r\n";

	if($log == 1){
		fwrite($FILE_LOG, $PARAMETROS);
	}

	// Enviar request e pegar resposta
	$response = curl_exec($curl);
	//var_dump($response);
	$responseJson = json_decode($response, true);
	$token = $responseJson['session']['token'];

	// Checando resposta e escrevendo em log
	if ($response) {
		$respostaLog = "\r\n\r\nSucesso: ".$response . "\r\n\r\n";
	} else {
		$error = curl_error($curl). '(' .curl_errno($curl). ')';
		$respostaLog = "\r\n\r\nErro: ".$error . "\r\n\r\n";
	}

	if($log == 1){
		fwrite($FILE_LOG, $respostaLog);

		$LOG_TXT = "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";
			
		fwrite($FILE_LOG, $LOG_TXT);

		fclose($FILE_LOG);
	}

	// Fechando conex�o
	curl_close($curl);

	//redirecionando o fluxo para a fun��o de cria��o de usu�rio do aplicativo no QB
	ApiUserSignUp($token, $facebook_usuario, $email, $nome);
}

//Cria sess�o do aplicativo e tamb�m de um usu�rio j� existente. Serve para tarefas onde � necess�rio j� ter usu�rio no QB.
function ApiAppAndUserSessionCreate($facebook_usuario1, $facebook_usuario2, $action, $chat, $destinatarios, $mensagem)
{
	
/*  -------------------  IMPORTANTE --------------------------

 $action � um par�metro que define qual fluxo a API ir� tomar, abaixo devem ser listado os actions criados para serem usados na API

 Lista dos poss�veis actions (favor listar abaixo um novo action se for criado com seu fluxo)

 - DIALOG_CREATE (este action � para o fluxo criar um novo chat. Ele segue para ApiUserRetrieve, para descobrir o ID_QB do outro usu�rio e depois para ApiDialogMessageSend que cria novo chat enviando uma mensagem
 - DIALOG_DELETE (este action � para o fluxo apagar um chat. Ele segue para ApiDialogDelete e apaga o chat para o usu�rio 
 - DIALOGS_RETRIEVE --INATIVADO (este action � para o fluxo de trazer todos os chats de um usu�rio. Ele segue para ApiDialogsRetrieve, retorna o JSON do QuickBlox e destr�i a sess�o em seguida
 - PUSH_SEND (este action é para o fluxo de enviar push para os usuários. Ele segue para ApiPushSend, envia os pushes e destrói a sessão
*/
	
	require 'config.php';
	
	// Credenciais do aplicativo
	$APPLICATION_ID = 10625;
	$AUTH_KEY = "rrTrFYFOECqjTAe";
	$AUTH_SECRET = "hM5vAmpBYYGV-p5";

	$user = $facebook_usuario1;

	// API endpoint
	$QB_API_ENDPOINT = "https://api.quickblox.com";
	$QB_PATH_SESSION = "session.json";

	if($log == 1){//vari�vel definida no config.php
		//criando ou abrindo o log de cURL para escrita
		$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/api_app_and_user_session_create-'.date('Y-m-d').".txt";
		$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	}

	// Gerando a assinatura
	$nonce = rand();
	$timestamp = time();
	$signature_string = "application_id=".$APPLICATION_ID."&auth_key=".$AUTH_KEY."&nonce=".$nonce."&timestamp=".$timestamp."&user[login]=".$user."&user[password]=".$user;

	//echo "stringForSignature: " . $signature_string . "<br><br>";
	$signature = hash_hmac('sha1', $signature_string , $AUTH_SECRET);

	// Criando corpo da requisi��o
	$post_body = http_build_query(array(
			'application_id' => $APPLICATION_ID,
			'auth_key' => $AUTH_KEY,
			'timestamp' => $timestamp,
			'nonce' => $nonce,
			'signature' => $signature,
			'user[login]' => $user,
			'user[password]' => $user
	));

	// $post_body = "application_id=" . $APPLICATION_ID . "&auth_key=" . $AUTH_KEY . "&timestamp=" . $timestamp . "&nonce=" . $nonce . "&signature=" . $signature . "&user[login]=" . $user . "&user[password]=" . $user;

	//echo "postBody: " . $post_body . "<br><br>";
	// Configurando cURL
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, $QB_API_ENDPOINT . '/' . $QB_PATH_SESSION); // Caminho completo � https://api.quickblox.com/session.json
	curl_setopt($curl, CURLOPT_POST, true); // Usar POST
	curl_setopt($curl, CURLOPT_POSTFIELDS, $post_body); // Encapsulando o body
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Recebendo a resposta
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //retirar em produ��o se n�o estiver funcionando pois ignora SSL
	//curl_setopt($curl, CURLOPT_VERBOSE, 1); //liga o verbose para mostrar resultado em browser
	curl_setopt($curl, CURLOPT_TIMEOUT, 40); //timeout com boa demora, pra n�o termos problemas com requsi��es expiriadas mto r�pido

	if($log == 1){
		curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG); //output em todo o fluxo cURL
	}


	$PARAMETROS  = "Body: {$post_body}\r\n";
	$PARAMETROS .= "Action: {$action}\r\n";
	$PARAMETROS .= "Facebook_usuario1: {$facebook_usuario1}\r\n";
	$PARAMETROS .= "Facebook_usuario2: {$facebook_usuario2}\r\n\r\n";
	

	if($log == 1){
		fwrite($FILE_LOG, $PARAMETROS);
	}

	// Enviar request e pegar resposta
	$response = curl_exec($curl);
	//var_dump($response);
	$responseJson = json_decode($response, true);
	$token = $responseJson['session']['token'];

	// Checando resposta e escrevendo em log
	if ($response) {
		$respostaLog = "\r\n\r\nSucesso: ".$response . "\r\n\r\n";
	} else {
		$error = curl_error($curl). '(' .curl_errno($curl). ')';
		$respostaLog = "\r\n\r\nErro: ".$error . "\r\n\r\n";
	}

	if($log == 1){
		fwrite($FILE_LOG, $respostaLog);

		$LOG_TXT = "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";
			
		fwrite($FILE_LOG, $LOG_TXT);

		fclose($FILE_LOG);
	}

	// Fechando conex�o
	curl_close($curl);

	
	//N�O ESTOU PASSANDO PELO METODO ApiUserSignIn POIS ESTE AQUI J� DEIXA A SESS�O COM PRIVIL�GIOS DE USU�RI DO APLICATIVO
	//redirecionando o fluxo para a fun��o de autentica��o de usu�rio do aplicativo
	//ApiUserSignIn($token, $user, $facebook_usuario2);
	
	if ($action == "DIALOG_CREATE"){
		$action = null;
		//redirecionando o fluxo para buscar o ID do outro participante do chat
		ApiUserRetrieve($token, $facebook_usuario2);
	}else if($action == "DIALOG_DELETE"){
		$action = null;
		//redirecionando o fluxo para apagar o chat
		ApiDialogDelete($token, $chat);
	}else if($action == "PUSH_SEND"){
		$action = null;
		//redirecionando o fluxo para apagar o chat
		ApiPushSend($token, $destinatarios, $mensagem);
	}
}

function ApiUserSignUp($token, $facebook_usuario, $email, $nome){
	
	require 'config.php';
	
	//CONVERTE A STRING UNICODE PARA UTF-8
	$nomeFix = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
		return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
	}, $nome);
	//SE NOME DO USUÁRIO TEM MENOS QUE 3 CARACTERES, DEVEMOS CONCATENAR UM CARACTERE INVISÍVEL PARA CADASTRAR NO QUICKBLOX, SENÃO RETORNA ERRO {"errors":{"full_name":["is invalid","is too short (minimum is 3 characters)"]}}
	if (strlen($nomeFix)<= 3) {
		// 		$nome = $nome."%C2%A0";
		$nomeFix = "{$nome} ";
	}else{
		$nomeFix = $nome;
	}
	
	//transforma os dados que chegaram de par�metro nos dados que ser�o necess�rios para enviar a requisi��o
	$login = $facebook_usuario;
	$password = $facebook_usuario;
	$full_name = $nomeFix;
	$facebook_id = $facebook_usuario;
	
	// API endpoint
	$QB_API_ENDPOINT = "http://api.quickblox.com";
	$QB_PATH_SESSION = "users.json";
	
	if($log == 1){
		//criando ou abrindo o log de cURL para escrita
		$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/api_user_signup-'.date('Y-m-d').".txt";
		$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	}
	
	// Criando corpo da requisi��o
	$post_body = http_build_query(array('user'=>array(
			'login' => $login,
			'password' => $password,
			'email' => $email,
			'facebook_id' => $facebook_id,
			'full_name' => $full_name
	)));
	
	// Configurando cURL
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_POST, true); // Usar POST
	curl_setopt($curl, CURLOPT_HTTPHEADER,array('Content-Type: application/json')); //setando par�metro no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_HTTPHEADER,array('QuickBlox-REST-API-Version: 0.1.0')); //setando par�metro no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_HTTPHEADER, array("QB-Token: {$token}")); //setando QB-Token no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_URL, $QB_API_ENDPOINT . '/' . $QB_PATH_SESSION); // Caminho completo � https://api.quickblox.com/session.json
	curl_setopt($curl, CURLOPT_POSTFIELDS, $post_body); // Encapsulando o body
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Recebendo a resposta
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //retirar em produ��o se n�o estiver funcionando pois ignora SSL
	//curl_setopt($curl, CURLOPT_VERBOSE, 1); //liga o verbose para mostrar resultado em browser
	curl_setopt($curl, CURLOPT_TIMEOUT, 40); //timeout com boa demora, pra n�o termos problemas com requsi��es expiriadas mto r�pido
	
	if($log == 1){
		curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG); //definindo arquivo de log pro fluxo cURL
	}
	
	$PARAMETROS  = "QB-Token: {$token}\r\n";
	$PARAMETROS .= "Login: {$login}\r\n";
	$PARAMETROS .= "Password: {$password}\r\n";
	$PARAMETROS .= "Full name: {$full_name}\r\n";
	$PARAMETROS .= "Facebook id: {$facebook_id}\r\n\r\n";
	
	if($log == 1){
	fwrite($FILE_LOG, $PARAMETROS);
	}
	
	// Enviar request e pegar resposta
	$response = curl_exec($curl);
	
	// Checando resposta e escrevendo em log
	if ($response) {
		$respostaLog = "\r\n\r\nResposta: {$response}\r\n\r\n";
		
		//TRANSFORMA A RESPOSTA DO QB QUE É JSON EM UM ARRAY
		$array_resposta_qb = json_decode($response,true);
		
		//SE USER NÃO FOR NULO, FOI CRIADO USUÁRIO COM SUCESSO, ENTÃO PEGAMOS O ID E FAZEMOS O UPDATE
		if (isset($array_resposta_qb['user'])) {
	
			$id_qb_criado = $array_resposta_qb['user']['id'];
		
		}//SE ERRORS NÃO FOR NULO, RETORNOU UM ERRO E NÃO DEVE SER FEITO O UPDATE
		else if (isset($array_resposta_qb['errors'])) {
			//SE CAIU AQUI DEU ERRO MAS NÃO É PRA FAZER NADA, POIS O ERRO JÁ ESTÁ SENDO GRAVADO
		}
	
	}else{
		$error = curl_error($curl). '(' .curl_errno($curl). ')';
				$respostaLog = "\r\n\r\nErro: {$error}\r\n\r\n";
	}
	
	if($log == 1){
		fwrite($FILE_LOG, $respostaLog);
		
		$LOG_TXT = "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";
			
		fwrite($FILE_LOG, $LOG_TXT);
		
		fclose($FILE_LOG);
	}
	
	// Fechando conex�o
	curl_close($curl);
	
	//redirecionando o fluxo para a fun��o de destrui��o da sess�o do aplicativo, sem necessitar da destrui��o da sess�o de usu�rio
	ApiSessionDestroy($token);
}

//Se o fluxo veio de uma sess�o com user (ApiAppAndUserCreate) n�o precisa passar pela fun��o abaixo, pois a sess�o j� tem privil�gios de usu�rio do aplicativo
function ApiUserSignIn($token, $user, $facebook_usuario2){

	require 'config.php';
	
	// Credenciais de usu�rio do aplicativo
	
	// API endpoint
	$QB_API_ENDPOINT = "http://api.quickblox.com";
	$QB_PATH_SESSION = "login.json";
	
	if($log == 1){//vari�vel definida no config.php
		//criando ou abrindo o log de cURL para escrita
		$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/api_user_signin-'.date('Y-m-d').".txt";
		$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	}
	
	// Criando corpo da requisi��o
	$post_body = http_build_query(array(
			'login' => $user,
			'password' => $user
	));
		
	// Configurando cURL
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_POST, true); // Usar POST
	curl_setopt($curl, CURLOPT_HTTPHEADER,array('QuickBlox-REST-API-Version: 0.1.0')); //setando par�metro no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_HTTPHEADER, array("QB-Token: {$token}")); //setando QB-Token no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_URL, $QB_API_ENDPOINT . '/' . $QB_PATH_SESSION); // Caminho completo � https://api.quickblox.com/session.json
	curl_setopt($curl, CURLOPT_POSTFIELDS, $post_body); // Encapsulando o body
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Recebendo a resposta
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //retirar em produ��o se n�o estiver funcionando pois ignora SSL
	//curl_setopt($curl, CURLOPT_VERBOSE, 1); //liga o verbose para mostrar resultado em browser
	curl_setopt($curl, CURLOPT_TIMEOUT, 40); //timeout com boa demora, pra n�o termos problemas com requsi��es expiriadas mto r�pido
	
	if($log == 1){
		curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG); //definindo arquivo de log pro fluxo cURL
	}
	
	$PARAMETROS = "QB-Token: {$token}\r\n\r\n";
	
	if($log == 1){
		fwrite($FILE_LOG, $PARAMETROS);
	}
	
	// Enviar request e pegar resposta
	$response = curl_exec($curl);
	//var_dump($response);
	
	// Checando resposta e escrevendo em log
	if ($response) {
		$respostaLog = "\r\n\r\nSucesso: ".$response . "\r\n\r\n";
	}else{
		$error = curl_error($curl). '(' .curl_errno($curl). ')';
		$respostaLog = "\r\n\r\nErro: {$error}\r\n\r\n";
	}
	
	if($log == 1){
		fwrite($FILE_LOG, $respostaLog);
	
		$LOG_TXT = "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";
			
		fwrite($FILE_LOG, $LOG_TXT);
	
		fclose($FILE_LOG);
	}
	
	// Fechando conex�o
	curl_close($curl);
	

	//redirecionando o fluxo para buscar o ID do outro participante do chat
	ApiUserRetrieve($token, $facebook_usuario2);
}

function ApiUserRetrieve($token, $facebook_usuario2){
	
	require 'config.php';
	
	// API endpoint
	$QB_API_ENDPOINT = "http://api.quickblox.com/users";
	$QB_PATH_SESSION = "by_login.json?login={$facebook_usuario2}";
	
	if($log == 1){
		//criando ou abrindo o log de cURL para escrita
		$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/api_user_retrieve-'.date('Y-m-d').".txt";
		$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	}
	
	// Configurando cURL
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_HTTPHEADER,array('QuickBlox-REST-API-Version: 0.1.0')); //setando par�metro no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_HTTPHEADER, array("QB-Token: {$token}")); //setando QB-Token no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_URL, $QB_API_ENDPOINT . '/' . $QB_PATH_SESSION); // Caminho completo � https://api.quickblox.com/session.json
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Recebendo a resposta
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //retirar em produ��o se n�o estiver funcionando pois ignora SSL
	//curl_setopt($curl, CURLOPT_VERBOSE, 1); //liga o verbose para mostrar resultado em browser
	curl_setopt($curl, CURLOPT_TIMEOUT, 40); //timeout com boa demora, pra n�o termos problemas com requsi��es expiriadas mto r�pido
	
	if($log == 1){
		curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG); //definindo arquivo de log pro fluxo cURL
	}
	
	$PARAMETROS = "QB-Token: {$token}\r\n\r\n";
	
	if($log == 1){
		fwrite($FILE_LOG, $PARAMETROS);
	}
	
	// Enviar request e pegar resposta
	$response = curl_exec($curl);
	//var_dump($response);
	$responseJson = json_decode($response, true);
	$occupant = $responseJson['user']['id'];
	
	if($log == 1){
		//escreve no log o ID_QB do usu�rio
		$PARAMETROS = "\r\n\r\nOccupant: {$occupant}\r\n";
		fwrite($FILE_LOG, $PARAMETROS);
	}
	
	// Checando resposta e escrevendo em log
	if ($response) {
		$respostaLog = "\r\n\r\nResposta: {$response}\r\n\r\n";
	}else{
		$error = curl_error($curl). '(' .curl_errno($curl). ')';
		$respostaLog = "\r\n\r\nErro: {$error}\r\n\r\n";
	}
	
	if($log == 1){
		fwrite($FILE_LOG, $respostaLog);
	
		$LOG_TXT = "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";
			
		fwrite($FILE_LOG, $LOG_TXT);
	
		fclose($FILE_LOG);
	}
	
	// Fechando conex�o
	curl_close($curl);
	
	//redirecionando o fluxo para a fun��o de cria��o de di�logo de chat que j� envia a primeira mensagem
	ApiDialogMessageSend($token, $occupant);
}

function ApiDialogsRetrieve($token, $facebook_usuario){

	require 'config.php';
	header('Content-Type: application/json');

	// API endpoint
	$QB_API_ENDPOINT = "https://api.quickblox.com/chat";
	$QB_PATH_SESSION = "Dialog.json";

	if($log == 1){
		//criando ou abrindo o log de cURL para escrita
		$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/dialogs_retrieve-'.date('Y-m-d').".txt";
		$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	}

	// Configurando cURL
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_HTTPHEADER, array("QB-Token: {$token}")); //setando QB-Token no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_URL, $QB_API_ENDPOINT . '/' . $QB_PATH_SESSION); // Caminho completo � https://api.quickblox.com/session.json
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Recebendo a resposta
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //retirar em produ��o se n�o estiver funcionando pois ignora SSL
	//curl_setopt($curl, CURLOPT_VERBOSE, 1); //liga o verbose para mostrar resultado em browser
	curl_setopt($curl, CURLOPT_TIMEOUT, 40); //timeout com boa demora, pra n�o termos problemas com requsi��es expiriadas mto r�pido

	if($log == 1){
		curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG); //definindo arquivo de log pro fluxo cURL
	}

	$PARAMETROS = "QB-Token: {$token}\r\n\r\n";

	if($log == 1){
		fwrite($FILE_LOG, $PARAMETROS);
	}

	// Enviar request e pegar resposta
	echo $response = curl_exec($curl);
	$responseJson = json_decode($response, true);

	// Checando resposta e escrevendo em log
	if ($response) {
		$respostaLog = "\r\n\r\nResposta: {$response}\r\n\r\n";
	}else{
		$error = curl_error($curl). '(' .curl_errno($curl). ')';
		$respostaLog = "\r\n\r\nErro: {$error}\r\n\r\n";
	}

	if($log == 1){
		fwrite($FILE_LOG, $respostaLog);

		$LOG_TXT = "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";
			
		fwrite($FILE_LOG, $LOG_TXT);

		fclose($FILE_LOG);
	}

	// Fechando conex�o
	curl_close($curl);

	//redirecionando o fluxo para a fun��o de cria��o de di�logo de chat que j� envia a primeira mensagem
	ApiSessionDestroy($token);
}

function ApiDialogCreate($token, $occupant){
	//Essa fun��o apenas cria um CHAT em BRANCO, a fun��o ApiDialogMessageSend cria CHAT e envia uma MENSAGEM
	
	require 'config.php';
	
	// API endpoint
	$QB_API_ENDPOINT = "http://api.quickblox.com/chat";
	$QB_PATH_SESSION = "Dialog.json";
	$DIALOG_TYPE = 3;
	
	if($log == 1){//vari�vel definida no config.php
		//criando ou abrindo o log de cURL para escrita
		$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/dialog_create-'.date('Y-m-d').".txt";
		$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	}
	
	// Criando corpo da requisi��o
	$post_body = http_build_query(array(
			'type' => $DIALOG_TYPE,
			'occupants_ids' => $occupant
	));
	
	// Configurando cURL
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_POST, true); // Usar POST
	curl_setopt($curl, CURLOPT_HTTPHEADER,array('Content-Type: application/json')); //setando par�metro no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_HTTPHEADER, array("QB-Token: {$token}")); //setando QB-Token no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_URL, $QB_API_ENDPOINT . '/' . $QB_PATH_SESSION); // Caminho completo � https://api.quickblox.com/session.json
	curl_setopt($curl, CURLOPT_POSTFIELDS, $post_body); // Encapsulando o body
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Recebendo a resposta
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //retirar em produ��o se n�o estiver funcionando pois ignora SSL
	//curl_setopt($curl, CURLOPT_VERBOSE, 1); //liga o verbose para mostrar resultado em browser
	curl_setopt($curl, CURLOPT_TIMEOUT, 40); //timeout com boa demora, pra n�o termos problemas com requsi��es expiriadas mto r�pido
	
	if($log == 1){
		curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG); //definindo arquivo de log pro fluxo cURL
	}
	
	$PARAMETROS = "QB-Token: {$token}\r\n";
	$PARAMETROS .= "Occupant: {$occupant}\r\n\r\n";
	
	if($log == 1){
	fwrite($FILE_LOG, $PARAMETROS);
	}
	
	// Enviar request e pegar resposta
	$response = curl_exec($curl);
	
	// Checando resposta e escrevendo em log
	if ($response) {
	$respostaLog = "\r\n\r\nResposta: {$response}\r\n\r\n";
	}else{
	$error = curl_error($curl). '(' .curl_errno($curl). ')';
			$respostaLog = "\r\n\r\nErro: {$error}\r\n\r\n";
	}
	
	if($log == 1){
	fwrite($FILE_LOG, $respostaLog);
	
	$LOG_TXT = "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";
		
		fwrite($FILE_LOG, $LOG_TXT);
	
			fclose($FILE_LOG);
	}
	
	// Fechando conex�o
	curl_close($curl);
	
	//redirecionando o fluxo para a fun��o de destrui��o da sess�o do aplicativo, sem necessitar da destrui��o da sess�o de usu�rio
	ApiSessionDestroy($token);
}

function ApiDialogMessageSend($token, $occupant){
	//Essa fun��o CRIA UM CHAT e j� envia uma mensagem pra ele
	
	require 'config.php';
	
	// API endpoint
	$QB_API_ENDPOINT = "https://api.quickblox.com/chat";
	$QB_PATH_SESSION = "Message.json";
	
	if($log == 1){
		//criando ou abrindo o log de cURL para escrita
		$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/dialog_message_send-'.date('Y-m-d').".txt";
		$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	}
	
	// Criando corpo da requisi��o
	$post_body = http_build_query(array(
			'message' => 'Combinamos!',
			'recipient_id' => $occupant,
			'send_to_chat' => 1
	));
	
	// Configurando cURL
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_POST, true); // Usar POST
	curl_setopt($curl, CURLOPT_HTTPHEADER,array('Content-Type: application/json')); //setando par�metro no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_HTTPHEADER, array("QB-Token: {$token}")); //setando QB-Token no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_URL, $QB_API_ENDPOINT . '/' . $QB_PATH_SESSION); // Caminho completo � https://api.quickblox.com/session.json
	curl_setopt($curl, CURLOPT_POSTFIELDS, $post_body); // Encapsulando o body
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Recebendo a resposta
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //retirar em produ��o se n�o estiver funcionando pois ignora SSL
	//curl_setopt($curl, CURLOPT_VERBOSE, 1); //liga o verbose para mostrar resultado em browser
	curl_setopt($curl, CURLOPT_TIMEOUT, 40); //timeout com boa demora, pra n�o termos problemas com requsi��es expiriadas mto r�pido
	
	if($log == 1){
		curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG); //definindo arquivo de log pro fluxo cURL
	}
	
	$PARAMETROS = "QB-Token: {$token}\r\n";
	$PARAMETROS .= "Occupant: {$occupant}\r\n\r\n";
	
	if($log == 1){
	fwrite($FILE_LOG, $PARAMETROS);
	}
	
	// Enviar request e pegar resposta
	$response = curl_exec($curl);
	
	// Checando resposta e escrevendo em log
	if ($response) {
	$respostaLog = "\r\n\r\nResposta: {$response}\r\n\r\n";
	}else{
	$error = curl_error($curl). '(' .curl_errno($curl). ')';
			$respostaLog = "\r\n\r\nErro: {$error}\r\n\r\n";
	}
	
	if($log == 1){
	fwrite($FILE_LOG, $respostaLog);
	
	$LOG_TXT = "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";
		
		fwrite($FILE_LOG, $LOG_TXT);
	
			fclose($FILE_LOG);
	}
	
	// Fechando conex�o
	curl_close($curl);
	
	//redirecionando o fluxo para a fun��o de destrui��o da sess�o do aplicativo, sem necessitar da destrui��o da sess�o de usu�rio
	ApiSessionDestroy($token);
}

function ApiPushSend($token, $destinatarios, $mensagem){
	//Essa fun��o CRIA UM CHAT e j� envia uma mensagem pra ele

	require 'config.php';

	// API endpoint
	$QB_API_ENDPOINT = "http://api.quickblox.com";
	$QB_PATH_SESSION = "events.json";

	if($log == 1){
		//criando ou abrindo o log de cURL para escrita
		$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/api_push_send-'.date('Y-m-d').".txt";
		$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	}

	// Criando corpo da requisi��o
	$post_body = http_build_query(array('event'=>array(
				'notification_type' => "push",
				'environment' => "production",
				'user'=>array('ids' => $destinatarios),
				'message' => base64_encode($mensagem)
				)));

	// Configurando cURL
	// Configurando cURL
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_POST, true); // Usar POST
	curl_setopt($curl, CURLOPT_HTTPHEADER,array('QuickBlox-REST-API-Version: 0.1.0')); //setando parâmetro no header de acordo com especificação QuickBlox
	curl_setopt($curl, CURLOPT_HTTPHEADER,array('Content-Type: application/json')); //setando parâmetro no header de acordo com especificação QuickBlox
	curl_setopt($curl, CURLOPT_HTTPHEADER, array("QB-Token: {$token}")); //setando QB-Token no header de acordo com especificação QuickBlox
	curl_setopt($curl, CURLOPT_URL, $QB_API_ENDPOINT . '/' . $QB_PATH_SESSION); // Caminho completo é https://api.quickblox.com/session.json
	curl_setopt($curl, CURLOPT_POSTFIELDS, $post_body); // Encapsulando o body
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Recebendo a resposta
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //retirar em produção se não estiver funcionando pois ignora SSL
	//curl_setopt($curl, CURLOPT_VERBOSE, 1); //liga o verbose, pra eu poder logar o fluxo cURL
	curl_setopt($curl, CURLOPT_TIMEOUT, 40); //timeout com boa demora, pra não termos problemas com requsições expiriadas mto rápido
	
	if($log == 1){
		curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG); //definindo arquivo de log pro fluxo cURL
	}

	$PARAMETROS  = "QB-Token: {$token}\r\n";
	$PARAMETROS .= "Mensagem: {$mensagem}\r\n";
	$PARAMETROS .= "Destinatarios: {$destinatarios}\r\n\r\n";

	if($log == 1){
		fwrite($FILE_LOG, $PARAMETROS);
	}

	// Enviar request e pegar resposta
	$response = curl_exec($curl);

	// Checando resposta e escrevendo em log
	if ($response) {
		$respostaLog = "\r\n\r\nResposta: {$response}\r\n\r\n";
	}else{
		$error = curl_error($curl). '(' .curl_errno($curl). ')';
		$respostaLog = "\r\n\r\nErro: {$error}\r\n\r\n";
	}

	if($log == 1){
		fwrite($FILE_LOG, $respostaLog);

		$LOG_TXT = "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";

		fwrite($FILE_LOG, $LOG_TXT);

		fclose($FILE_LOG);
	}

	// Fechando conex�o
	curl_close($curl);

	//redirecionando o fluxo para a fun��o de destrui��o da sess�o do aplicativo, sem necessitar da destrui��o da sess�o de usu�rio
	ApiSessionDestroy($token);
}

function ApiDialogDelete($token, $chat){
	
	// API endpoint
	$QB_API_ENDPOINT = "https://api.quickblox.com/chat/Dialog";
	$QB_PATH_SESSION = "{$chat}.json";
	
	//criando ou abrindo o log de cURL para escrita
	$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/dialog_delete-'.date('Y-m-d').".txt";
	$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	
	// Configurando cURL
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE'); // Usar metodo DELETE
	curl_setopt($curl, CURLOPT_HTTPHEADER,array('Content-Type: application/json')); //setando par�metro no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_HTTPHEADER, array("QB-Token: {$token}")); //setando QB-Token no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_URL, $QB_API_ENDPOINT . '/' . $QB_PATH_SESSION); // Caminho completo � https://api.quickblox.com/session.json
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Recebendo a resposta
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //retirar em produ��o se n�o estiver funcionando pois ignora SSL
	//curl_setopt($curl, CURLOPT_VERBOSE, 1); //liga o verbose para mostrar resultado em browser
	curl_setopt($curl, CURLOPT_TIMEOUT, 40); //timeout com boa demora, pra n�o termos problemas com requsi��es expiriadas mto r�pido
	curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG); //definindo arquivo de log pro fluxo cURL
	
	$PARAMETROS  = "QB-Token: {$token}\r\n";
	$PARAMETROS .= "Chat: {$chat}\r\n\r\n";
	
	fwrite($FILE_LOG, $PARAMETROS);
	
	// Enviar request e pegar resposta
	$response = curl_exec($curl);
	//var_dump($response);
	
	// Checando resposta e escrevendo em log
	if ($response) {
		$respostaLog = "\r\n\r\nResposta (se vazio, foi sucesso): {$response}\r\n\r\n";
	}else{
		$error = curl_error($curl). '(' .curl_errno($curl). ')';
		$respostaLog = "\r\n\r\nErro: {$error}\r\n\r\n";
	}
	fwrite($FILE_LOG, $respostaLog);
	
	$LOG_TXT = "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";
	
	fwrite($FILE_LOG, $LOG_TXT);
	
	fclose($FILE_LOG);
	
	// Fechando conex�o
	curl_close($curl);
	
	ApiSessionDestroy($token);
}

function ApiSessionDestroy($token){
	
	require 'config.php';
	
	// API endpoint
	$QB_API_ENDPOINT = "http://api.quickblox.com";
	$QB_PATH_SESSION = "session.json";
	
	if($log == 1){//vari�vel definida no config.php
		//criando ou abrindo o log de cURL para escrita
		$FILE_LOG_DIR = dirname($_SERVER['SCRIPT_FILENAME']).'/log/api_session_destroy-'.date('Y-m-d').".txt";
		$FILE_LOG = fopen($FILE_LOG_DIR, "a+");
	}
	
	// Configurando cURL
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE'); // Usar metodo DELETE
	curl_setopt($curl, CURLOPT_HTTPHEADER,array('QuickBlox-REST-API-Version: 0.1.0')); //setando par�metro no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_HTTPHEADER, array("QB-Token: {$token}")); //setando QB-Token no header de acordo com especifica��o QuickBlox
	curl_setopt($curl, CURLOPT_URL, $QB_API_ENDPOINT . '/' . $QB_PATH_SESSION); // Caminho completo � https://api.quickblox.com/session.json
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Recebendo a resposta
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //retirar em produ��o se n�o estiver funcionando pois ignora SSL
	//curl_setopt($curl, CURLOPT_VERBOSE, 1); //liga o verbose para mostrar resultado em browser
	curl_setopt($curl, CURLOPT_TIMEOUT, 40); //timeout com boa demora, pra n�o termos problemas com requsi��es expiriadas mto r�pido
	
	if($log == 1){
		curl_setopt($curl, CURLOPT_STDERR,$FILE_LOG); //definindo arquivo de log pro fluxo cURL
	}
	
	$PARAMETROS = "QB-Token: {$token}\r\n\r\n";
	
	if($log == 1){
		fwrite($FILE_LOG, $PARAMETROS);
	}
	
	// Enviar request e pegar resposta
	$response = curl_exec($curl);
	//var_dump($response);
	
	// Checando resposta e escrevendo em log
	if ($response) {
		$respostaLog = "\r\n\r\nResposta (se vazio, foi sucesso): {$response}\r\n\r\n";
	}else{
		$error = curl_error($curl). '(' .curl_errno($curl). ')';
		$respostaLog = "\r\n\r\nErro: {$error}\r\n\r\n";
	}
	
	if($log == 1){
		fwrite($FILE_LOG, $respostaLog);
	
		$LOG_TXT = "\r\n-----------------------------------------------------------------------------------------\r\n\r\n";
			
		fwrite($FILE_LOG, $LOG_TXT);
	
		fclose($FILE_LOG);
	}
	
	// Fechando conex�o
	curl_close($curl);
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

        header('HTTP/1.1 542 Erro ao apagar usuario');	
        echo '[]';

        die();
    }
    
    if($stmt->rowCount()){
    
        echo "[]";
    
    }
    else{
        //ERRO 542
        //MENSAGEM: Erro ao apagar usuario

        header('HTTP/1.1 542 Erro ao apagar usuario');	
        echo '[]';

        die();
    }
    
    $conn = null;
}

function listaPromosUsuario($id_usuario)
{
    $sql = "SELECT PROMO.id_promo, LOCAL.nome AS local, PROMO.nome, PROMO.descricao, DATE_FORMAT(PROMO.dt_inicio,'%d/%m/%Y') as dt_inicio, DATE_FORMAT(PROMO.dt_fim,'%d/%m/%Y') as dt_fim, PROMO.lote, DATE_FORMAT(PROMO.dt_promo,'%d/%m/%Y') as dt_promo,
            PROMO_CODIGO_USUARIO.promo_codigo AS codigo_promo, PROMO_CODIGO_USUARIO.id_promo_codigo_usuario AS id_codigo_promo, PROMO_CODIGO_USUARIO.dt_visualizacao
            FROM PROMO JOIN LOCAL ON PROMO.id_local = LOCAL.id_local
                       JOIN PROMO_CODIGO_USUARIO ON PROMO.id_promo = PROMO_CODIGO_USUARIO.id_promo
            WHERE PROMO_CODIGO_USUARIO.id_usuario = :id_usuario
                AND PROMO_CODIGO_USUARIO.dt_exclusao IS NULL
                    ORDER BY PROMO_CODIGO_USUARIO.dt_usuario DESC";
    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_usuario",$id_usuario);
        $stmt->execute();

        $promos = $stmt->fetchAll(PDO::FETCH_OBJ);

    } catch(PDOException $e){

        //ERRO 547
        //MENSAGEM: Erro ao buscar promos

        header('HTTP/1.1 547 Erro ao buscar promos');	
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

    $sql = "UPDATE PROMO_CODIGO_USUARIO SET dt_visualizacao = NOW() 
            WHERE id_promo_codigo_usuario = :id_codigo_promo";

    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_codigo_promo",$promo->id_codigo_promo);
        $stmt->execute();

    } catch(PDOException $e){

        //ERRO 548
        //MENSAGEM: Erro ao marcar promo visualizado

        header('HTTP/1.1 548 Erro ao marcar promo visualizado');	
        echo '[]';

        die();
    }
    
    if($stmt->rowCount()){
    
        echo "{\"id_output\":\"1\",\"desc_output\":\"Promo marcado como visualizado.\"}";
    
    }
    else{
        //ERRO 548
        //MENSAGEM: Erro ao marcar promo visualizado

        header('HTTP/1.1 548 Erro ao marcar promo visualizado');	
        echo '[]';

        die();
    }
    
    $conn = null;
}

function apagaPromoUsuario()
{
    $request = \Slim\Slim::getInstance()->request();
    $promo = json_decode($request->getBody());

    $sql = "UPDATE PROMO_CODIGO_USUARIO SET dt_exclusao = NOW() 
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

        header('HTTP/1.1 549 Erro ao apagar promo');	
        echo '[]';

        die();
    }
    
    if($stmt->rowCount()){
    
        echo "{\"id_output\":\"1\",\"desc_output\":\"Promo apagado com sucesso.\"}";
    
    }
    else{
        //ERRO 549
        //MENSAGEM: Erro ao apagar promo

        header('HTTP/1.1 549 Erro ao apagar promo');	
        echo '[]';

        die();
    }
    
    $conn = null;
}

function adicionaPromoCheckin($id_promo,$id_usuario)
{
    $retorno = true;
    
    //$lote_indisponivel = false;
	
    //$request = \Slim\Slim::getInstance()->request();
    //$promo_usuario = json_decode($request->getBody());

    //Verifica se ainda ha lote disponivel para o promo

    $sql = "SELECT MIN(id_promo_codigo_usuario) AS id_promo_codigo_usuario, COUNT(id_promo_codigo_usuario) AS qtd_codigos
    		FROM PROMO_CODIGO_USUARIO 
    		WHERE id_promo = $id_promo 
    		  AND dt_exclusao IS NULL
    		  AND id_usuario IS NULL";

    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->execute();

    } catch(PDOException $e){

        //ERRO 550
        //MENSAGEM: Erro ao verificar codigos de promo disponiveis

        //header('HTTP/1.1 XXX Erro ao verificar codigos de promo disponiveis');
        //echo '[]';

        //die();

        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
        
     	$retorno = false;

    }	

    $codigo_disponivel = $stmt->fetch(PDO::FETCH_OBJ);

    if($codigo_disponivel){ //Se existe codigo disponivel

        //Atualiza a tabela de promocoes, codigos e usuarios

        $sql = "UPDATE PROMO_CODIGO_USUARIO SET id_usuario = $id_usuario, dt_usuario = NOW()  
                WHERE id_promo_codigo_usuario = :id_promo_codigo_usuario";
        
        try{
                $stmt = $conn->prepare($sql);
                $stmt->bindParam("id_promo_codigo_usuario",$codigo_disponivel->id_promo_codigo_usuario);
                $stmt->execute();
                
        } catch(PDOException $e){

            //ERRO 551
            //MENSAGEM: Erro ao adicionar promo ao usuario

            //header('HTTP/1.1 XXX Erro ao adicionar promo ao usuario');
            //echo '[]';

            //die();

            //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
            
        	$retorno = false;
        }

        if(!$stmt->rowCount()){

            //ERRO 553
            //MENSAGEM: Erro ao marcar codigo como utilizado

            //header('HTTP/1.1 XXX Erro ao marcar codigo como utilizado');	
            //echo '[]';

            //die();
            
        	$retorno = false;
        }
        
        // Se este foi o ultimo codigo utilizado, atualiza a tabela de promos para expirar o promo.
        
        if($retorno && $codigo_disponivel->qtd_codigos <= 1){
        	 
        	$sql = "UPDATE PROMO SET dt_fim_lote = NOW()
        	        WHERE id_promo = $id_promo";
        	 
        	try{
        		$stmt = $conn->prepare($sql);
        		$stmt->execute();
        		 
        	} catch(PDOException $e){
        		 
        		//echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
        		 
        		$retorno = false;
        	}
        }
    }
    else{

        //ERRO 552
        //MENSAGEM: Nao ha lote disponivel

        //header('HTTP/1.1 XXX Nao ha lote disponivel');
        //echo '[]';

        //die();
        
    	//$lote_indisponivel = true;
    	
    	$retorno = false;

    }

    // Retorno

	$conn = null;
	
	return $retorno;
	
}

function verificaPromoLocal($id_local)
{
    $sql = "SELECT id_promo, nome, descricao 
            FROM PROMO
            WHERE PROMO.id_local = :id_local
              AND NOW() BETWEEN dt_inicio AND dt_fim
              AND promo_checkin = 1
    		  AND dt_fim_lote IS NULL";
    try{
        $conn = getConn();
        $stmt = $conn->prepare($sql);
        $stmt->bindParam("id_local",$id_local);
        $stmt->execute();

        $promo = $stmt->fetch(PDO::FETCH_OBJ);
        
    } catch(PDOException $e){
        //ERRO 554
        //MENSAGEM: Erro ao verificar promo

        header('HTTP/1.1 554 Erro ao verificar promo');	
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
    $sql = "SELECT 1 from PROMO_CODIGO_USUARIO "
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

        header('HTTP/1.1 555 Erro ao verificar promos');	
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

        header('HTTP/1.1 556 Erro ao verificar configuracoes');	
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

        header('HTTP/1.1 559 Erro ao adicionar erro do QB');
        echo '[]';

        die();

        //echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
    }

    echo json_encode($erroQB);

    $conn = null;
}

function enviaPush()
{
	$request = \Slim\Slim::getInstance()->request();
	$pushObj = json_decode($request->getBody());

	$sql = "SELECT ID_QB AS quickblox_usuario FROM USUARIO WHERE ID_FACEBOOK IN ({$pushObj->destinatarios})";
	
	try{
		$conn = getConn();
		$stmt = $conn->prepare($sql);
		$stmt->execute();
		
		//echo $pushObj->destinatarios;
		
		$destinatariosResult = $stmt->fetchAll(PDO::FETCH_OBJ);
		
		$destinatariosArray = array();
		
		for ($i = 0; $i < count($destinatariosResult); $i++) {
			$destinatariosArray[] = $destinatariosResult[$i]->quickblox_usuario;
		}
		
		$destinatarios = implode (",", $destinatariosArray);
		
		//sempre fazer login com o usuário Onrange Mobile onrangemobile@gmail.com
		ApiAppAndUserSessionCreate("100009466217846", null, "PUSH_SEND", null, $destinatarios, $pushObj->mensagem);
	} catch(PDOException $e){

		//ERRO 559
		//MENSAGEM: Erro ao adicionar erro do QB

		header('HTTP/1.1 559 Erro ao adicionar erro do QB');
		echo '[]';

		die();

		//echo '{"Erro":{"descricao":"'. $e->getMessage() .'"}}';
	}

	$conn = null;
}