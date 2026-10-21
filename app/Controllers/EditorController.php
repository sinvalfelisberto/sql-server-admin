<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;

use function App\Models\csv_separator;

final class EditorController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request): Response
    {
        $separator = csv_separator((string) $this->config->get('csv_separator', ';'));

        return Response::html($this->view->render('editor', [
            'title'      => '',
            'bodyClass'  => '',
            'csrf'       => $this->session->csrfToken(),
            'connection' => $this->session->connection(),
            'scripts'    => ['assets/js/xlsx.js', 'assets/js/app.js'],
            'csvSeparator' => $separator === "\t" ? 'tab' : $separator,
        ]));
    }
}
