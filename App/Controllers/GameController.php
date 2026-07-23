<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

class GameController
{
    public function index(Request $request, Response $response): void
    {
        $html = file_get_contents(__DIR__ . '/../../index.html');
        $response->setContent($html);
        $response->send();
    }
}