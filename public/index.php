<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Repository\UserRepository;
use App\Validator\UserValidator;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Views\PhpRenderer;

session_start();

$userRepo = new UserRepository(__DIR__ . '/../assets/users.json');

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

$app->get('/', function ($request, $response) use ($router) {
    $params = ['usersLink' => $router->urlFor('users.index')];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('index');

$app->get('/users', function ($request, $response) use ($userRepo) {
    $messages = $this->get('flash')->getMessages();
    $term = mb_strtolower(trim($request->getQueryParam('term')));
    $users = $userRepo->all();

    $filteredUsers = $term === ''
        ? $users
        : array_filter($users, fn($user) => str_contains(mb_strtolower($user['nickname']), $term));

    $params = ['users' => $filteredUsers, 'term' => $term, 'flash' => $messages];
    return $this->get('renderer')->render($response, 'users/index.phtml', $params);
})->setName('users.index');

$app->get('/users/new', function ($request, $response) {
    $params = [
        'user' => [
            'nickname' => '',
            'email' => ''
        ],
        'errors' => []
    ];
    return $this->get('renderer')->render($response, 'users/new.phtml', $params);
})->setName('users.create');

$app->post('/users', function ($request, $response) use ($userRepo, $router) {
    $user = $request->getParsedBodyParam('user');
    $validator = new UserValidator();
    $errors = $validator->validate($user);

    if (count($errors) === 0) {
        $userRepo->create($user);
        $this->get('flash')->addMessage('success', 'User was added successfully');
        $url = $router->urlFor('users.index');
        return $response->withRedirect($url, 302);
    }

    $params = [
        'user' => $user,
        'errors' => $errors
    ];
    return $this->get('renderer')->render($response->withStatus(422), 'users/new.phtml', $params);
})->setName('users.store');

$app->get('/users/{id}', function ($request, $response, array $args) use ($userRepo) {
    $id = $args['id'];

    $user = $userRepo->findById($id);
    if (!$user) {
        return $response->write('User not found!')->withStatus(404);
    }

    $params = ['user' => $user];
    return $this->get('renderer')->render($response, 'users/show.phtml', $params);
})->setName('users.show');

$app->get('/users/{id}/edit', function ($request, $response, array $args) use ($userRepo) {
    $id = $args['id'];
    $user = $userRepo->findById($id);
    if (!$user) {
        return $response->write('User not found!')->withStatus(404);
    }

    $params = [
        'user' => $user,
        'errors' => []
    ];
    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
})->setName('users.edit');

$app->patch('/users/{id}', function ($request, $response, array $args) use ($userRepo, $router) {
    $id = $args['id'];
    $data = $request->getParsedBodyParam('user');
    $validator = new UserValidator;
    $errors = $validator->validate($data);

    if (count($errors) === 0) {
        $userRepo->update($id, $data);
        $url = $router->urlFor('users.index');
        $this->get('flash')->addMessage('success', 'User was updated successfully');
        return $response->withRedirect($url, 302);
    }

    $user = $userRepo->findById($id);
    if (!$user) {
        return $response->write('User not found!')->withStatus(404);
    }
    $params = [
        'user' => $user,
        'errors' => $errors
    ];
    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
})->setName('users.update');

$app->get('/users/{id}/delete', function ($request, $response, array $args) use ($userRepo) {
    $id = $args['id'];
    $user = $userRepo->findById($id);
    if (!$user) {
        return $response->write('User not found!')->withStatus(404);
    }

    $params = [
        'user' => $user
    ];
    return $this->get('renderer')->render($response, 'users/delete.phtml', $params);
});

$app->delete('/users/{id}', function ($request, $response, array $args) use ($userRepo, $router) {
    $id = $args['id'];
    $userRepo->destroy($id);

    $this->get('flash')->addMessage('success', 'User was deleted successfully');
    return $response->withRedirect($router->urlFor('users.index'), 302);
});

$app->run();
