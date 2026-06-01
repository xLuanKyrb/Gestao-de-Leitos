<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Cabeçalhos HTTP
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Arquivo do banco de dados SQLite
$dbFile = __DIR__ . '/pacientes.db';

// Variável global para a conexão PDO
$pdo = null;

/**
 * Função utilitária para enviar resposta JSON e encerrar a execução.
 */
function output($data, $success = true, $message = null, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit();
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // 1. CRIAÇÃO DA TABELA BASE
    // Alteração 1: Adicionei obs_clinica aqui para novas instalações
    $pdo->exec("CREATE TABLE IF NOT EXISTS pacientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        leito TEXT NOT NULL,
        local TEXT NOT NULL,
        nascimento TEXT,
        atendimento TEXT,
        aguardando TEXT,
        entrada TEXT,
        pendencias TEXT,
        medico TEXT,
        conduta TEXT,
        dieta TEXT,
        hospital TEXT,
        observacao TEXT,
        obs_clinica TEXT, -- <--- CAMPO NOVO ADICIONADO
        aguardando_vaga TEXT,
        news INTEGER,
        pews INTEGER,
        braden INTEGER,
        isis INTEGER,
        status TEXT DEFAULT 'ocupado',
        tipo_paciente TEXT,
        humpty INTEGER,
        morse INTEGER,
        meows TEXT,
        iris_adulto INTEGER,
        iris_ped INTEGER,
        braden_adulto INTEGER,
        braden_ped INTEGER,
        glasgow_adulto TEXT,
        glasgow_ped TEXT,
        dor_adulto TEXT,
        dor_ped TEXT,
        diagnostico TEXT
    )");
    
    // 2. TABELA DE CID
    $pdo->exec("CREATE TABLE IF NOT EXISTS cid10 (
        id INTEGER PRIMARY KEY,
        codigo TEXT UNIQUE NOT NULL,
        descricao TEXT NOT NULL
    )");
    
    // 3. ATUALIZAÇÃO DE COLUNAS (Migration)
    // Alteração 2: Adicionei obs_clinica aqui para atualizar seu banco existente
    $colunasNovas = [
        'saida' => 'TEXT',
        'obs_clinica' => 'TEXT',
        'diagnostico' => 'TEXT',
        'tipo_paciente' => 'TEXT',
        'humpty' => 'INTEGER',
        'morse' => 'INTEGER',
        'meows' => 'TEXT',
        'iris_adulto' => 'INTEGER',
        'iris_ped' => 'INTEGER',
        'braden_adulto' => 'INTEGER',
        'braden_ped' => 'INTEGER',
        'glasgow_adulto' => 'TEXT',
        'glasgow_ped' => 'TEXT',
        'dor_adulto' => 'TEXT',
        'dor_ped' => 'TEXT'
    ];

    $stmtCols = $pdo->query("PRAGMA table_info(pacientes)");
    $colsExistentes = [];
    while ($row = $stmtCols->fetch()) {
        $colsExistentes[] = $row['name'];
    }

    foreach ($colunasNovas as $col => $tipo) {
        if (!in_array($col, $colsExistentes)) {
            try {
                $pdo->exec("ALTER TABLE pacientes ADD COLUMN $col $tipo");
            } catch (Exception $e) {
                // Coluna já existe ou erro ignorável
            }
        }
    }

    // 4. TABELA AUDITORIA
    $pdo->exec("CREATE TABLE IF NOT EXISTS auditoria (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT,
        leito TEXT,
        local TEXT,
        entrada TEXT,
        saida TEXT,
        acao TEXT,
        hospital TEXT,
        timestamp TEXT
    )");

    // 5. HISTÓRICO DE LEITOS
    $pdo->exec("CREATE TABLE IF NOT EXISTS historico_leitos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        leito TEXT,
        local TEXT,
        status TEXT,
        data_hora TEXT
    )");

} catch (Exception $e) {
    output(null, false, "Erro no Banco: " . $e->getMessage(), 500);
}

// Captura método e inputs
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? $_GET;
$action = $input['action'] ?? $_GET['action'] ?? null;


// =======================================================
// ROTAS PRINCIPAIS (SWITCH)
// =======================================================
switch ($action) {

    case 'list':
        if ($method !== 'GET') output(null, false, "Método não permitido.", 405);

        // Mantendo sua estrutura de setores
        $setores = [
            'Isolamento Adulto'     => 1,
            'Isolamento Pediátrico' => 1,
            'Observação Masculina'  => 5,
            'Observação Pediátrica' => 5,
            'Observação Feminina'   => 5,
            'Sala de Decisão'       => 5,
            'Medicação'             => 5,
			'Emergência'            => 5,
            'Corredor / Extra'      => 0,
        ];
        
        $stmt = $pdo->query("SELECT * FROM pacientes WHERE status = 'ocupado' ORDER BY local, leito ASC");
        
        $ocupados = [];
        $extras = [];
    
        while ($row = $stmt->fetch()) {
            if (strpos($row['leito'], 'Extra') !== false || $row['local'] == 'Corredor / Extra') {
                $extras[] = $row;
            } else {
                $leitoNum = trim(str_replace(['(Extra)', 'Extra'], '', $row['leito']));
                $chave = $row['local'] . '-' . $leitoNum;
                $ocupados[$chave] = $row;
            }
        }
    
        $listaFinal = [];
    
        foreach ($setores as $nomeSetor => $qtd) {
            for ($i = 1; $i <= $qtd; $i++) {
                $chave = $nomeSetor . '-' . $i;
    
                if (isset($ocupados[$chave])) {
                    $listaFinal[] = $ocupados[$chave];
                } else {
                    $listaFinal[] = [
                        'id' => null,
                        'leito' => (string)$i,
                        'local' => $nomeSetor,
                        'nome' => null,
                        'status' => 'livre',
                        'aguardando' => 'Vago'
                    ];
                }
            }
        }
    
        usort($extras, function($a, $b) {
            return strtotime($a['entrada']) <=> strtotime($b['entrada']);
        });
        $listaFinal = array_merge($listaFinal, $extras);
    
        output($listaFinal);
        break;

    // ... dentro do switch($action) ...

    case 'delete': // Agora funciona como "Dar Alta"
        if ($method !== 'POST' && $method !== 'GET') output(null, false, "Método não permitido.", 405);
        
        $id = intval($input['id'] ?? $_GET['id'] ?? 0); 
        
        if ($id > 0) {
            // Busca dados atuais
            $stmtInfo = $pdo->prepare("SELECT * FROM pacientes WHERE id = :id");
            $stmtInfo->execute([':id' => $id]);
            $p = $stmtInfo->fetch();
    
            if ($p) {
                date_default_timezone_set('America/Sao_Paulo');
                $saida = (new DateTime())->format('Y-m-d H:i:s');
    
                // 1. Registra na Auditoria (Mantém como backup de segurança)
                $pdo->prepare("INSERT INTO auditoria (nome, leito, local, entrada, saida, acao, hospital, timestamp) VALUES (:nome, :leito, :local, :entrada, :saida, 'saida', :hospital, :timestamp)")
                    ->execute([':nome' => $p['nome'], ':leito' => $p['leito'], ':local' => $p['local'], ':entrada' => $p['entrada'], ':saida' => $saida, ':hospital' => $p['hospital'] ?? '', ':timestamp' => $saida]);
    
                // 2. Libera o leito no histórico
                $pdo->prepare("INSERT INTO historico_leitos (leito, local, status, data_hora) VALUES (:leito, :local, 'liberado', :data_hora)")
                    ->execute([':leito' => $p['leito'], ':local' => $p['local'], ':data_hora' => $saida]);
    
                // 3. ATENÇÃO: NÃO DELETA MAIS. APENAS ATUALIZA O STATUS.
                // Isso preserva todo o histórico clínico para o relatório.
                $sqlAlta = "UPDATE pacientes SET status = 'alta', saida = :saida WHERE id = :id";
                $pdo->prepare($sqlAlta)->execute([':saida' => $saida, ':id' => $id]);
                
                output(null, true, "Paciente recebeu alta e foi arquivado no histórico.");
            }
        }
        output(null, false, 'ID inválido ou paciente não encontrado', 404);
        break;

    case 'rotatividade':
        if ($method !== 'GET') output(null, false, "Método não permitido.", 405);
        
        $inicio = $_GET["inicio"] ?? date("Y-m-d", strtotime("-30 days"));
        $fim = $_GET["fim"] ?? date("Y-m-d");

        // Busca tanto quem entrou nesse período quanto quem saiu nesse período
        // Traz todos os dados (*) para preencher o relatório completo
        $sql = "SELECT * FROM pacientes 
                WHERE (DATE(entrada) BETWEEN :inicio AND :fim) 
                   OR (DATE(saida) BETWEEN :inicio AND :fim)
                ORDER BY entrada DESC";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([":inicio" => $inicio, ":fim" => $fim]);
        output($stmt->fetchAll());
        break;
        
    case 'add':
        if ($method !== 'POST') output(null, false, "Método não permitido.", 405);
        
        $nome = trim($input['nome'] ?? '');
        $leito = $input['leito'] ?? '';
        $local = $input['local'] ?? '';
    
        if (!$nome || !$leito || !$local) {
            output(null, false, 'Preencha nome, leito e local', 400);
        }
    
        date_default_timezone_set('America/Sao_Paulo');
        $entrada = (new DateTime())->format('Y-m-d H:i:s');
    
        // Alteração 3: Adicionei obs_clinica no INSERT e nos VALUES
        $sql = "INSERT INTO pacientes (
            nome, leito, local, nascimento, atendimento, aguardando, entrada, pendencias, medico, conduta, dieta, hospital, 
            observacao, obs_clinica, aguardando_vaga,
            news, pews, braden, isis, diagnostico,
            tipo_paciente, humpty, morse, meows, iris_adulto, iris_ped, braden_adulto, braden_ped, glasgow_adulto, glasgow_ped, dor_adulto, dor_ped,
            status
        ) VALUES (
            :nome, :leito, :local, :nascimento, :atendimento, :aguardando, :entrada, :pendencias, :medico, :conduta, :dieta, :hospital, 
            :observacao, :obs_clinica, :aguardando_vaga,
            :news, :pews, :braden, :isis, :diagnostico,
            :tipo_paciente, :humpty, :morse, :meows, :iris_adulto, :iris_ped, :braden_adulto, :braden_ped, :glasgow_adulto, :glasgow_ped, :dor_adulto, :dor_ped,
            'ocupado'
        )";
    
        try {
            $stmt = $pdo->prepare($sql);
            
            $val = function($k) use ($input) { return isset($input[$k]) && $input[$k] !== '' ? $input[$k] : null; };
    
            $stmt->execute([
                ':nome' => $nome,
                ':leito' => $leito,
                ':local' => $local,
                ':nascimento' => $val('nascimento'),
                ':atendimento' => $val('atendimento'),
                ':aguardando' => $val('aguardando'),
                ':entrada' => $entrada,
                ':pendencias' => $val('pendencias'),
                ':medico' => $val('medico'),
                ':conduta' => $val('conduta'),
                ':dieta' => $val('dieta'),
                ':hospital' => $val('hospital'),
                ':observacao' => $val('observacao'),
                ':obs_clinica' => $val('obs_clinica'), // <--- MAPEADO AQUI
                ':aguardando_vaga' => $val('aguardando_vaga'),
                
                ':diagnostico' => $val('diagnostico'),

                ':news' => $val('news'),
                ':pews' => $val('pews'),
                ':braden' => $val('braden_adulto') ?? $val('braden_ped'), 
                ':isis' => $val('iris_adulto') ?? $val('iris_ped'),
    
                ':tipo_paciente' => $val('tipo_paciente'),
                ':humpty' => $val('humpty'),
                ':morse' => $val('morse'),
                ':meows' => $val('meows'),
                ':iris_adulto' => $val('iris_adulto'),
                ':iris_ped' => $val('iris_ped'),
                ':braden_adulto' => $val('braden_adulto'),
                ':braden_ped' => $val('braden_ped'),
                ':glasgow_adulto' => $val('glasgow_adulto'),
                ':glasgow_ped' => $val('glasgow_ped'),
                ':dor_adulto' => $val('dor_adulto'),
                ':dor_ped' => $val('dor_ped')
            ]);
    
            $lastId = $pdo->lastInsertId();

            // Auditoria
            $pdo->prepare("INSERT INTO auditoria (nome, leito, local, entrada, acao, hospital, timestamp) VALUES (:nome, :leito, :local, :entrada, 'entrada', :hospital, :timestamp)")
                ->execute([':nome'=>$nome, ':leito'=>$leito, ':local'=>$local, ':entrada'=>$entrada, ':hospital'=>$val('hospital'), ':timestamp'=>$entrada]);
    
            // Histórico
            $pdo->prepare("INSERT INTO historico_leitos (leito, local, status, data_hora) VALUES (:leito, :local, 'ocupado', :data_hora)")
                ->execute([':leito'=>$leito, ':local'=>$local, ':data_hora'=>$entrada]);
    
            output(['id' => $lastId], true, 'Paciente adicionado');
    
        } catch (PDOException $e) {
            output(null, false, "Erro ao salvar: " . $e->getMessage(), 500);
        }
        break;

    case 'update':
        if ($method !== 'POST') output(null, false, "Método não permitido.", 405);

        $id = intval($input['id'] ?? 0);
        if ($id <= 0) { 
            output(null, false, 'ID inválido', 400);
        }
    
        // Alteração 4: Adicionei obs_clinica no UPDATE
        $sql = "UPDATE pacientes SET
            nome = :nome, leito = :leito, local = :local, nascimento = :nascimento, atendimento = :atendimento,
            aguardando = :aguardando, pendencias = :pendencias, medico = :medico, conduta = :conduta,
            dieta = :dieta, hospital = :hospital, 
            observacao = :observacao, 
            obs_clinica = :obs_clinica, -- <--- ADICIONADO NO SET
            aguardando_vaga = :aguardando_vaga,
            news = :news, pews = :pews, braden = :braden, isis = :isis, diagnostico = :diagnostico,
            tipo_paciente = :tipo_paciente, humpty = :humpty, morse = :morse, meows = :meows,
            iris_adulto = :iris_adulto, iris_ped = :iris_ped, braden_adulto = :braden_adulto, braden_ped = :braden_ped,
            glasgow_adulto = :glasgow_adulto, glasgow_ped = :glasgow_ped, dor_adulto = :dor_adulto, dor_ped = :dor_ped
            WHERE id = :id";
    
        try {
            $stmt = $pdo->prepare($sql);
            $val = function($k) use ($input) { return isset($input[$k]) && $input[$k] !== '' ? $input[$k] : null; };
    
            $stmt->execute([
                ':id' => $id,
                ':nome' => $input['nome'] ?? '',
                ':leito' => $input['leito'] ?? '',
                ':local' => $input['local'] ?? '',
                ':nascimento' => $val('nascimento'),
                ':atendimento' => $val('atendimento'),
                ':aguardando' => $val('aguardando'),
                ':pendencias' => $val('pendencias'),
                ':medico' => $val('medico'),
                ':conduta' => $val('conduta'),
                ':dieta' => $val('dieta'),
                ':hospital' => $val('hospital'),
                ':observacao' => $val('observacao'),
                ':obs_clinica' => $val('obs_clinica'), // <--- MAPEADO AQUI
                ':aguardando_vaga' => $val('aguardando_vaga'),
                ':diagnostico' => $val('diagnostico'),
                ':news' => $val('news'),
                ':pews' => $val('pews'),
                ':braden' => $val('braden_adulto') ?? $val('braden_ped'),
                ':isis' => $val('iris_adulto') ?? $val('iris_ped'),
                ':tipo_paciente' => $val('tipo_paciente'),
                ':humpty' => $val('humpty'),
                ':morse' => $val('morse'),
                ':meows' => $val('meows'),
                ':iris_adulto' => $val('iris_adulto'),
                ':iris_ped' => $val('iris_ped'),
                ':braden_adulto' => $val('braden_adulto'),
                ':braden_ped' => $val('braden_ped'),
                ':glasgow_adulto' => $val('glasgow_adulto'),
                ':glasgow_ped' => $val('glasgow_ped'),
                ':dor_adulto' => $val('dor_adulto'),
                ':dor_ped' => $val('dor_ped')
            ]);
    
            output(null, true, 'Atualizado');
        } catch (PDOException $e) {
            output(null, false, $e->getMessage(), 500);
        }
        break;

    case 'delete':
        if ($method !== 'POST' && $method !== 'GET') output(null, false, "Método não permitido.", 405);
        
        $id = intval($input['id'] ?? $_GET['id'] ?? 0); 
        
        if ($id > 0) {
            $stmtInfo = $pdo->prepare("SELECT * FROM pacientes WHERE id = :id");
            $stmtInfo->execute([':id' => $id]);
            $p = $stmtInfo->fetch();
    
            if ($p) {
                date_default_timezone_set('America/Sao_Paulo');
                $saida = (new DateTime())->format('Y-m-d H:i:s');
    
                $pdo->prepare("INSERT INTO auditoria (nome, leito, local, entrada, saida, acao, hospital, timestamp) VALUES (:nome, :leito, :local, :entrada, :saida, 'saida', :hospital, :timestamp)")
                    ->execute([':nome' => $p['nome'], ':leito' => $p['leito'], ':local' => $p['local'], ':entrada' => $p['entrada'], ':saida' => $saida, ':hospital' => $p['hospital'] ?? '', ':timestamp' => $saida]);
    
                $pdo->prepare("INSERT INTO historico_leitos (leito, local, status, data_hora) VALUES (:leito, :local, 'liberado', :data_hora)")
                    ->execute([':leito' => $p['leito'], ':local' => $p['local'], ':data_hora' => $saida]);
    
                $pdo->prepare("DELETE FROM pacientes WHERE id = :id")->execute([':id' => $id]);
                output(null, true);
            }
        }
        output(null, false, 'ID inválido ou paciente não encontrado', 404);
        break;

    case 'search_cid':
        if ($method !== 'GET') output(null, false, "Método não permitido.", 405);

        $termo = $_GET['termo'] ?? null;
        if (!isset($termo) || empty($termo)) {
            output(null, false, 'Termo de busca ausente.', 400);
        }

        $termoLike = '%' . trim($termo) . '%';
        
        $sql = "SELECT codigo, descricao FROM cid10 WHERE 
            codigo LIKE :termoCodigo OR descricao LIKE :termoDescricao 
            ORDER BY 
                CASE 
                    WHEN codigo = :termoExato THEN 0 
                    WHEN codigo LIKE :termoCodigoInicial THEN 1
                    ELSE 2
                END, 
            codigo ASC 
            LIMIT 20";

        $stmt = $pdo->prepare($sql);
        
        $termoCodigoInicial = trim($termo) . '%';
        $termoExato = trim($termo);

        $stmt->bindParam(':termoCodigo', $termoLike);
        $stmt->bindParam(':termoDescricao', $termoLike);
        $stmt->bindParam(':termoCodigoInicial', $termoCodigoInicial);
        $stmt->bindParam(':termoExato', $termoExato);
        
        try {
            $stmt->execute();
            $resultados = $stmt->fetchAll();
            output($resultados, true);
        } catch(PDOException $e) {
            output(null, false, 'Erro na busca de CID: ' . $e->getMessage(), 500);
        }
        break;

    case 'relatorio':
        if ($method !== 'GET') output(null, false, "Método não permitido.", 405);
        $stmt = $pdo->query("SELECT * FROM auditoria ORDER BY timestamp DESC LIMIT 100");
        output($stmt->fetchAll());
        break;
        
    case 'rotatividade':
        if ($method !== 'GET') output(null, false, "Método não permitido.", 405);
        $inicio = $_GET["inicio"] ?? date("Y-m-d", strtotime("-1 day"));
        $fim = $_GET["fim"] ?? date("Y-m-d");

        $stmt = $pdo->prepare("SELECT * FROM pacientes WHERE DATE(entrada) >= :inicio AND DATE(entrada) <= :fim");
        $stmt->execute([":inicio" => $inicio, ":fim" => $fim]);
        output($stmt->fetchAll());
        break;

    default:
        output(null, false, 'Ação inválida ou ausente.', 400);
}
?>