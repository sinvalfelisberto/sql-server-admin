<?php
declare(strict_types=1);

namespace App\Core;

use App\Controllers\AuthController;
use App\Controllers\EditorController;
use App\Controllers\ExplorerController;
use App\Controllers\QueryController;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Models\CatalogRepository;
use App\Models\ConnectionFactory;
use App\Models\ObjectScripter;
use App\Models\QueryExecutor;
use Throwable;

use function App\Support\e;

/** Composition root: monta as dependências e despacha a requisição. */
final class Application
{
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Referrer-Policy'        => 'same-origin',
    ];

    public function __construct(private readonly string $basePath, private readonly Config $config)
    {
    }

    public function run(Request $request): void
    {
        $this->handle($request)->send();
    }

    public function handle(Request $request): Response
    {
        $container = $this->container($request);

        try {
            $container->get(Session::class)->start(
                $this->config->int('session_timeout', 3600),
                $request->secure,
            );
            $response = $container->get(Router::class)->dispatch($request);
        } catch (Throwable $e) {
            error_log($e::class . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
            $response = str_starts_with($request->path, '/api/')
                ? Response::json(['ok' => false, 'error' => ['message' => 'Erro interno do servidor.']], 500)
                : Response::html('Erro interno do servidor.', 500);
        }

        return array_reduce(
            array_keys(self::SECURITY_HEADERS),
            static fn (Response $r, string $name): Response => $r->withHeader($name, self::SECURITY_HEADERS[$name]),
            $response,
        );
    }

    private function container(Request $request): Container
    {
        $c = new Container();
        $config = $this->config;

        $c->set(Config::class, static fn () => $config);
        $c->set(Session::class, static fn () => new Session());
        $c->set(View::class, fn () => new View("{$this->basePath}/app/Views", [
            'e'       => e(...),
            'url'     => $request->url(...),
            'appName' => $config->get('app_name'),
            'version' => $config->get('version'),
        ]));

        $c->set(ConnectionFactory::class, static fn (Container $c) => new ConnectionFactory($c->get(Config::class)));
        $c->set(CatalogRepository::class, static fn (Container $c) => new CatalogRepository($c->get(ConnectionFactory::class)));
        $c->set(QueryExecutor::class, static fn (Container $c) => new QueryExecutor($c->get(ConnectionFactory::class), $c->get(Config::class)));

        $c->set(AuthController::class, static fn (Container $c) => new AuthController(
            $c->get(View::class), $c->get(Session::class), $c->get(Config::class), $c->get(ConnectionFactory::class),
        ));
        $c->set(EditorController::class, static fn (Container $c) => new EditorController($c->get(View::class), $c->get(Session::class)));
        $c->set(ObjectScripter::class, static fn (Container $c) => new ObjectScripter($c->get(ConnectionFactory::class), $c->get(CatalogRepository::class)));
        $c->set(ExplorerController::class, static fn (Container $c) => new ExplorerController(
            $c->get(Session::class), $c->get(CatalogRepository::class), $c->get(ObjectScripter::class),
        ));
        $c->set(QueryController::class, static fn (Container $c) => new QueryController($c->get(Session::class), $c->get(QueryExecutor::class), $c->get(Config::class)));

        $c->set(Router::class, function (Container $c): Router {
            $session = $c->get(Session::class);

            return new Router(
                require "{$this->basePath}/app/routes.php",
                [
                    'auth.page' => Middleware::authPage($session),
                    'auth.api'  => Middleware::authApi($session),
                    'guest'     => Middleware::guest($session),
                    'csrf'      => Middleware::csrf($session),
                ],
                $c,
            );
        });

        return $c;
    }
}
