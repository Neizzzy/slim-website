<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Validator\UserValidator;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Views\PhpRenderer;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

session_start();

const AUTH_EMAIL = 'admin@neizzzy.ru';

function searchUsersByName(array $users, string $term): ?array
{
    $lowerCaseTerm = mb_strtolower(trim($term));
    return array_filter($users, function ($user) use ($lowerCaseTerm) {
        $lowerCaseName = mb_strtolower($user['nickname']);
        return str_contains($lowerCaseName, $lowerCaseTerm);
    });
}

function getUsers($request)
{
    return json_decode($request->getCookieParam('users') ?? '', true);
}

$container = new Container();
$container->set('renderer', function () {
    return new PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);

$router = $app->getRouteCollector()->getRouteParser();

$authMiddleware = function (Request $request, RequestHandler $handler) use ($router, $app) {
    if (!isset($_SESSION['isAuth'])) {
        $flash = $app->getContainer()->get('flash');
        $flash->addMessage('error', 'Access denied');
        $response = new \Slim\Psr7\Response();

        return $response->withHeader('Location', $router->urlFor('index'))->withStatus(302);
    }

    return $handler->handle($request);
};

$app->get('/', function ($request, $response) use ($router) {
    $messages = $this->get('flash')->getMessages();
    $params = [
        'flash' => $messages,
        'isAuth' => $_SESSION['isAuth'] ?? false
    ];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('index');

$app->post('/login', function ($request, $response) use ($router) {
    $email = $request->getParsedBodyParam('email');

    if ($email === AUTH_EMAIL) {
        $_SESSION['isAuth'] = true;
        $this->get('flash')->addMessage('success', 'Successfully authorized');
    } else {
        $this->get('flash')->addMessage('error', 'Access Denied');
    }

    return $response->withRedirect($router->urlFor('index'), 302);
});

$app->delete('/logout', function ($request, $response) use ($router) {
    $_SESSION = [];
    session_destroy();

    return $response->withRedirect($router->urlFor('index'), 302);
});

$app->get('/users', function ($request, $response) {
    $messages = $this->get('flash')->getMessages();
    $term = $request->getQueryParam('term', '');
    $users = getUsers($request) ?? [];

    $userResult = searchUsersByName($users, $term);

    $params = ['users' => $userResult, 'term' => $term, 'flash' => $messages];
    return $this->get('renderer')->render($response, 'users/index.phtml', $params);
})->setName('users.index')->add($authMiddleware);

$app->get('/users/new', function ($request, $response) {
    $params = [
        'user' => [
            'nickname' => '',
            'email' => ''
        ],
        'errors' => []
    ];
    return $this->get('renderer')->render($response, 'users/new.phtml', $params);
})->setName('users.create')->add($authMiddleware);

$app->post('/users', function ($request, $response) use ($router) {
    $userData = $request->getParsedBodyParam('user');
    $validator = new UserValidator();
    $errors = $validator->validate($userData);

    if (count($errors) === 0) {
        $users = getUsers($request);
        $id = 1;
        if (isset($users)) {
            $lastUser = end($users);
            $id += $lastUser['id'];
        }
        $userData['id'] = $id;
        $users[$id] = $userData;

        $encodedUsers = json_encode($users);
        $this->get('flash')->addMessage('success', 'User was added successfully');

        return $response->withHeader('Set-Cookie', "users={$encodedUsers};path=/")
            ->withRedirect($router->urlFor('users.index'), 302);
    }

    $params = [
        'user' => $userData,
        'errors' => $errors
    ];
    return $this->get('renderer')->render($response->withStatus(422), 'users/new.phtml', $params);
})->setName('users.store')->add($authMiddleware);

$app->get('/users/{id}', function ($request, $response, array $args) {
    $id = $args['id'];
    $user = getUsers($request)[$id];
    if (!$user) {
        return $response->write('User not found!')->withStatus(404);
    }

    $params = ['user' => $user];
    return $this->get('renderer')->render($response, 'users/show.phtml', $params);
})->setName('users.show')->add($authMiddleware);

$app->get('/users/{id}/edit', function ($request, $response, array $args) {
    $id = $args['id'];
    $user = getUsers($request)[$id];
    if (!$user) {
        return $response->write('User not found!')->withStatus(404);
    }

    $params = [
        'user' => $user,
        'errors' => []
    ];
    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
})->setName('users.edit')->add($authMiddleware);

$app->patch('/users/{id}', function ($request, $response, array $args) use ($router) {
    $id = $args['id'];
    $userData = $request->getParsedBodyParam('user');

    $users = getUsers($request);
    $user = $users[$id];
    if (!$user) {
        return $response->write('User not found!')->withStatus(404);
    }

    $validator = new UserValidator;
    $errors = $validator->validate($userData);

    if (count($errors) === 0) {
        $user['nickname'] = $userData['nickname'];
        $user['email'] = $userData['email'];
        $users[$id] = $user;

        $encodedUsers = json_encode($users);
        $this->get('flash')->addMessage('success', 'User was updated successfully');

        return $response->withHeader('Set-Cookie', "users={$encodedUsers};path=/")
            ->withRedirect($router->urlFor('users.index'), 302);
    }

    $userData['id'] = $id;
    $params = [
        'user' => $userData,
        'errors' => $errors
    ];
    return $this->get('renderer')->render($response->withStatus(422), 'users/edit.phtml', $params);
})->setName('users.update')->add($authMiddleware);

$app->get('/users/{id}/delete', function ($request, $response, array $args) {
    $id = $args['id'];
    $user = getUsers($request)[$id];
    if (!$user) {
        return $response->write('User not found!')->withStatus(404);
    }

    $params = [
        'user' => $user
    ];
    return $this->get('renderer')->render($response, 'users/delete.phtml', $params);
})->setName('users.delete')->add($authMiddleware);

$app->delete('/users/{id}', function ($request, $response, array $args) use ($router) {
    $id = $args['id'];
    $users = getUsers($request);
    unset($users[$id]);

    $encodedUsers = json_encode($users);
    $this->get('flash')->addMessage('success', 'User was deleted successfully');

    return $response->withHeader('Set-Cookie', "users={$encodedUsers};path=/")
        ->withRedirect($router->urlFor('users.index'), 302);
})->setName('users.destroy')->add($authMiddleware);

$app->run();
