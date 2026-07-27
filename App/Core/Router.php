<?php

namespace App\Core;

use App\Controllers\GameController;
use Config\Config;

class Router
{
    private array $routes = [];

    public function __construct()
    {
        $this->initializeRoutes();
    }

    private function initializeRoutes(): void
    {
        $adminPath = '/' . trim(Config::get('Admin.Path', 'admin'), '/');

        $this->routes = [
            'GET' => [
                '/' => [GameController::class, 'index'],
                '/script.js' => [GameController::class, 'script'],
                '/style.css' => [GameController::class, 'style'],
                '/favicon.svg' => [GameController::class, 'favicon'],
                '/shared.js' => [GameController::class, 'shared'],
                '/admin.js' => [GameController::class, 'adminScript'],
                '/admin.css' => [GameController::class, 'adminStyle'],
                '/WhoisAI' => [GameController::class, 'WhoisAIIndex'],
                '/whoisai_style.css' => [GameController::class, 'WhoisAIStyle'],
                '/whoisai_script.js' => [GameController::class, 'WhoisAIScript'],
                '/api/generate-code' => [GameController::class, 'generateCode'],
                '/api/player-stats' => [GameController::class, 'playerStats'],
                '/api/chat-history' => [GameController::class, 'chatHistoryList'],
                '/api/chat-history/detail' => [GameController::class, 'chatHistoryDetail'],
            ],
            'POST' => [
                '/api/save-chat-history' => [GameController::class, 'saveChatHistory'],
                '/api/upload-userdata' => [GameController::class, 'uploadUserData'],
            ],
        ];

        // 动态添加管理员路由
        if ($adminPath !== '/') {
            $this->routes['GET'][$adminPath] = [GameController::class, 'adminPage'];
            $this->routes['POST'][$adminPath . '/api/login'] = [GameController::class, 'adminLogin'];
        }
    }

    public function dispatch(Request $request, Response $response): void
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        // 查找匹配的路由
        $handler = $this->findRoute($method, $path);

        if ($handler) {
            $this->executeHandler($handler, $request, $response);
        } else {
            $response->setStatusCode(404);
            $response->setContent('Not Found');
            $response->send();
        }
    }

    private function findRoute(string $method, string $path): ?array
    {
        if (!isset($this->routes[$method])) {
            return null;
        }

        foreach ($this->routes[$method] as $route => $handler) {
            if ($this->matchRoute($route, $path)) {
                return $handler;
            }
        }

        return null;
    }

    private function matchRoute(string $route, string $path): bool
    {
        // 简单的路由匹配
        $routePattern = preg_replace('/\{[^}]+\}/', '[^/]+', $route);
        $routePattern = "#^{$routePattern}$#";

        return preg_match($routePattern, $path) === 1;
    }

    private function executeHandler(array $handler, Request $request, Response $response): void
    {
        [$className, $methodName] = $handler;

        if (class_exists($className) && method_exists($className, $methodName)) {
            $controller = new $className();
            $controller->$methodName($request, $response);
        } else {
            throw new \Exception("Handler {$className}::{$methodName} not found");
        }
    }
}
