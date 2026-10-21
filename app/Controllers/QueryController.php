<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Models\QueryExecutor;

use function App\Models\effective_limit;

final class QueryController
{
    public function __construct(
        private readonly Session $session,
        private readonly QueryExecutor $executor,
        private readonly Config $config,
    ) {
    }

    public function execute(Request $request): Response
    {
        $connection = $this->session->connection();
        $this->session->release();

        $in = $request->json();
        $limit = effective_limit((int) ($in['limit'] ?? 0), $this->config->int('max_rows', 10000));
        set_time_limit($this->config->int('query_timeout', 120) + 30);

        $result = $this->executor->execute(
            $connection,
            (string) ($in['database'] ?? ''),
            (string) ($in['sql'] ?? ''),
            $limit,
        );

        return Response::json($result->toArray());
    }
}
