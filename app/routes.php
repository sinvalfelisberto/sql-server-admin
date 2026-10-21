<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\EditorController;
use App\Controllers\ExplorerController;
use App\Controllers\QueryController;

/**
 * Rotas como dados: [método, caminho, [Controller, ação], [middlewares em ordem]].
 */
return [
    ['GET',  '/',              [EditorController::class, 'index'],     ['auth.page']],
    ['GET',  '/login',         [AuthController::class, 'showLogin'],   ['guest']],
    ['POST', '/login',         [AuthController::class, 'login'],       ['guest', 'csrf']],

    ['POST', '/api/logout',    [AuthController::class, 'logout'],      ['auth.api', 'csrf']],
    ['POST', '/api/databases', [ExplorerController::class, 'databases'], ['auth.api', 'csrf']],
    ['POST', '/api/objects',   [ExplorerController::class, 'objects'],   ['auth.api', 'csrf']],
    ['POST', '/api/columns',   [ExplorerController::class, 'columns'],   ['auth.api', 'csrf']],
    ['POST', '/api/ddl',        [ExplorerController::class, 'ddl'],         ['auth.api', 'csrf']],
    ['POST', '/api/db-ddl',     [ExplorerController::class, 'databaseDdl'], ['auth.api', 'csrf']],
    ['POST', '/api/table-info', [ExplorerController::class, 'tableInfo'],   ['auth.api', 'csrf']],
    ['POST', '/api/execute',   [QueryController::class, 'execute'],      ['auth.api', 'csrf']],
];
