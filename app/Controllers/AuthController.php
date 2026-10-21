<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Models\Connection;
use App\Models\ConnectionFactory;
use App\Support\Result;
use PDOException;

use function App\Models\pdo_error;

final class AuthController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Config $config,
        private readonly ConnectionFactory $factory,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        return $this->form(
            error: '',
            server: (string) $this->config->get('default_server', ''),
            user: '',
            trust: (bool) $this->config->get('trust_server_certificate', true),
        );
    }

    public function login(Request $request): Response
    {
        $trust = isset($request->post['trust']);

        return Connection::fromLogin($request->post, (array) $this->config->get('allowed_servers', []), $trust)
            ->flatMap($this->verify(...))
            ->fold(
                fn (string $error): Response => $this->form(
                    $error,
                    (string) ($request->post['server'] ?? ''),
                    (string) ($request->post['user'] ?? ''),
                    $trust,
                ),
                function (Connection $connection) use ($request): Response {
                    $this->session->login($connection);

                    return Response::redirect($request->url());
                },
            );
    }

    public function logout(Request $request): Response
    {
        $this->session->destroy();

        return Response::json(['ok' => true]);
    }

    /** Testa as credenciais de verdade e descobre o banco padrão do login. */
    private function verify(Connection $connection): Result
    {
        try {
            $pdo = $this->factory->open($connection);
            $database = (string) $pdo->query('SELECT DB_NAME()')->fetchColumn();

            return Result::ok($connection->withDatabase($database));
        } catch (PDOException $e) {
            usleep(500_000); // desencoraja tentativas em sequência

            return Result::err(pdo_error($e)['message']);
        }
    }

    private function form(string $error, string $server, string $user, bool $trust): Response
    {
        return Response::html($this->view->render('login', [
            'title'     => 'Login',
            'bodyClass' => 'login-body',
            'csrf'      => $this->session->csrfToken(),
            'error'     => $error,
            'server'    => $server,
            'user'      => $user,
            'trust'     => $trust,
        ]));
    }
}
