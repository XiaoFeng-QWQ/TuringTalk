<?php

namespace App\Core;

use App\Controllers\GameController;
use App\Config\Config;

/**
 * 路由器
 */
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
                '/WhoisAI' => [GameController::class, 'WhoisAIIndex'],
                '/lobby' => [GameController::class, 'lobbyIndex'],
                '/gomoku' => [GameController::class, 'gomokuIndex'],
                '/weekly-report' => [GameController::class, 'weeklyReportIndex'],
                '/api/generate-player-id' => [GameController::class, 'generatePlayerId'],
                '/api/player-stats' => [GameController::class, 'playerStats'],
                '/api/chat-history' => [GameController::class, 'chatHistoryList'],
                '/api/chat-history/detail' => [GameController::class, 'chatHistoryDetail'],
                '/api/player-profile' => [GameController::class, 'playerProfile'],
                '/api/player-collections' => [GameController::class, 'playerCollections'],
                '/api/collection/detail' => [GameController::class, 'collectionDetail'],
                '/api/collection/by-token' => [GameController::class, 'collectionByToken'],
                '/api/player-messages' => [GameController::class, 'getMyMessages'],
                '/api/sticker/list' => [GameController::class, 'listStickers'],
                '/api/weekly-report' => [GameController::class, 'weeklyReport'],
                '/player/{nickname}' => [GameController::class, 'index'],
                '/collection/{token}' => [GameController::class, 'viewPublicCollection'],
            ],
            'POST' => [
                '/api/upload-userdata' => [GameController::class, 'uploadUserData'],
                '/api/player-message/hide' => [GameController::class, 'hideMessage'],
                '/api/player-message/settings' => [GameController::class, 'updateMessageSettings'],
                '/api/chat-history/collect' => [GameController::class, 'setCollection'],
                '/api/collection/like' => [GameController::class, 'likeCollection'],
                '/api/sticker/upload' => [GameController::class, 'uploadSticker'],
                '/api/sticker/delete' => [GameController::class, 'deleteSticker'],
            ],
        ];

        // 从数组批量注册静态资源
        foreach (GameController::STATIC_RESOURCES as $url => $_) {
            $this->routes['GET'][$url] = [GameController::class, 'serveStatic'];
        }

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
