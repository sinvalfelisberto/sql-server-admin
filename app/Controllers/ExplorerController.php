<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Models\CatalogRepository;
use App\Models\ObjectScripter;
use PDOException;

use function App\Models\pdo_error;

/** Object Explorer API: databases, objects and columns. */
final class ExplorerController
{
    public function __construct(
        private readonly Session $session,
        private readonly CatalogRepository $catalog,
        private readonly ObjectScripter $scripter,
    ) {
    }

    public function databases(Request $request): Response
    {
        return $this->respond(function (): array {
            $connection = $this->session->connection();
            $this->session->release();

            return ['current' => $connection->database] + $this->catalog->serverInfo($connection);
        });
    }

    public function objects(Request $request): Response
    {
        return $this->respond(function () use ($request): array {
            $connection = $this->session->connection();
            $this->session->release();

            return ['objects' => $this->catalog->objects($connection, (string) ($request->json()['database'] ?? ''))];
        });
    }

    public function columns(Request $request): Response
    {
        return $this->respond(function () use ($request): array {
            $connection = $this->session->connection();
            $this->session->release();
            $in = $request->json();

            return ['columns' => $this->catalog->columns(
                $connection,
                (string) ($in['database'] ?? ''),
                (string) ($in['schema'] ?? ''),
                (string) ($in['name'] ?? ''),
                (string) ($in['type'] ?? ''),
            )];
        });
    }

    /** DDL script for a table (CREATE TABLE) or the definition of a view/procedure/function. */
    public function ddl(Request $request): Response
    {
        return $this->respond(function () use ($request): array {
            $connection = $this->session->connection();
            $this->session->release();
            $in = $request->json();

            return ['ddl' => $this->scripter->script(
                $connection,
                (string) ($in['database'] ?? ''),
                (string) ($in['schema'] ?? ''),
                (string) ($in['name'] ?? ''),
                (string) ($in['type'] ?? ''),
            )];
        });
    }

    /** Database creation script (collation and active options). */
    public function databaseDdl(Request $request): Response
    {
        return $this->respond(function () use ($request): array {
            $connection = $this->session->connection();
            $this->session->release();

            return ['ddl' => $this->scripter->databaseScript($connection, (string) ($request->json()['database'] ?? ''))];
        });
    }

    /** Approximate rows, space, indexes and foreign keys of a table/view. */
    public function tableInfo(Request $request): Response
    {
        return $this->respond(function () use ($request): array {
            $connection = $this->session->connection();
            $this->session->release();
            $in = $request->json();

            return $this->catalog->tableInfo(
                $connection,
                (string) ($in['database'] ?? ''),
                (string) ($in['schema'] ?? ''),
                (string) ($in['name'] ?? ''),
            );
        });
    }

    /** Runs $work and turns database failures into the API error envelope. */
    private function respond(callable $work): Response
    {
        try {
            return Response::json(['ok' => true] + $work());
        } catch (PDOException $e) {
            return Response::json(['ok' => false, 'error' => pdo_error($e)]);
        }
    }
}
