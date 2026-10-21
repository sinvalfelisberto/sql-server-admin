<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;

final class EditorController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('editor', [
            'title'      => '',
            'bodyClass'  => '',
            'csrf'       => $this->session->csrfToken(),
            'connection' => $this->session->connection(),
            'scripts'    => ['assets/js/xlsx.js', 'assets/js/app.js'],
        ]));
    }
}
