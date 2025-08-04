<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;

$users = ['mike', 'Mishel', 'adel', 'keks', 'kamila'];

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) use ($router) {
    $params = ['usersLink' => $router->urlFor('users.index')];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('index');

$app->get('/users', function ($request, $response) use ($users) {
    $term = mb_strtolower(trim($request->getQueryParam('term')));
    $filteredUsers = $term === ''
        ? $users
        : array_filter($users, fn($user) => str_contains(mb_strtolower($user), $term));

    $params = ['users' => $filteredUsers, 'term' => $term];
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

$app->post('/users', function ($request, $response) {
    $user = $request->getParsedBodyParam('user');

    $errors = [];
    if (empty($user['nickname'])) {
        $errors['nickname'] = "Field 'nickname' is required";
    }
    if (empty($user['email'])) {
        $errors['email'] = "Field 'email' is required";
    }

    if (count($errors) === 0) {
        $file = __DIR__ . '/../assets/users.json';
        if (!file_exists($file)) {
            die("Файл не существует");
        }
        $users = json_decode(file_get_contents($file), true, flags: JSON_OBJECT_AS_ARRAY);

        $id = 1;
        if (isset($users)) {
            $lastUser = end($users);
            $id += $lastUser['id'];
        }
        $user['id'] = $id;

        $users[] = $user;

        $result = file_put_contents($file, json_encode($users));
        if ($result === false) {
            die("Ошибка записи в файл");
        }

        return $response->withRedirect('/users', 302);
    }

    $params = [
        'user' => $user,
        'errors' => $errors
    ];
    return $this->get('renderer')->render($response, 'users/new.phtml', $params)->withStatus(422);
})->setName('users.store');

$app->get('/users/{id}', function ($request, $response, array $args) {
    $params = ['id' => $args['id'], 'nickname' => 'user-' . $args['id']];

    return $this->get('renderer')->render($response, 'users/show.phtml', $params);
})->setName('users.show');

$app->run();
