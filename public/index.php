<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Repository\UserRepository;
use App\Validator\UserValidator;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Routing\RouteCollectorProxy;
use Slim\Views\PhpRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

session_start();

const AUTH_EMAIL = 'admin@neizzzy.ru';

function searchUsersByName(array $users, string $term): ?array
{
    $lowerCaseTerm = mb_strtolower(trim($term));
    return array_filter($users, function ($user) use ($lowerCaseTerm) {
        $lowerCaseName = mb_strtolower($user['name']);
        return str_contains($lowerCaseName, $lowerCaseTerm);
    });
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

        return $response->withHeader('Location', $router->urlFor('index'))
            ->withStatus(302);
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

$app->group('', function (RouteCollectorProxy $group) use ($router) {
    $group->group('/users', function (RouteCollectorProxy $users) use ($router) {
        $userRepo = new UserRepository(__DIR__ . '/../assets/users.json');

        $users->get('', function ($request, $response) use ($userRepo) {
            $messages = $this->get('flash')->getMessages();
            $term = mb_strtolower(trim($request->getQueryParam('term')));
            $users = $userRepo->all();

            $filteredUsers = $term === ''
                ? $users
                : array_filter($users, fn($user) => str_contains(mb_strtolower($user['nickname']), $term));

            $params = ['users' => $filteredUsers, 'term' => $term, 'flash' => $messages];
            return $this->get('renderer')->render($response, 'users/index.phtml', $params);
        })->setName('users.index');

        $users->get('/new', function ($request, $response) {
            $params = [
                'user' => [
                    'nickname' => '',
                    'email' => ''
                ],
                'errors' => []
            ];
            return $this->get('renderer')->render($response, 'users/new.phtml', $params);
        })->setName('users.create');

        $users->post('', function ($request, $response) use ($userRepo, $router) {
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

        $users->get('/{id}', function ($request, $response, array $args) use ($userRepo) {
            $id = $args['id'];

            $user = $userRepo->findById($id);
            if (!$user) {
                return $response->write('User not found!')->withStatus(404);
            }

            $params = ['user' => $user];
            return $this->get('renderer')->render($response, 'users/show.phtml', $params);
        })->setName('users.show');

        $users->get('/{id}/edit', function ($request, $response, array $args) use ($userRepo) {
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

        $users->patch('/{id}', function ($request, $response, array $args) use ($userRepo, $router) {
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

        $users->get('/{id}/delete', function ($request, $response, array $args) use ($userRepo) {
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

        $users->delete('/{id}', function ($request, $response, array $args) use ($userRepo, $router) {
            $id = $args['id'];
            $userRepo->destroy($id);

            $this->get('flash')->addMessage('success', 'User was deleted successfully');
            return $response->withRedirect($router->urlFor('users.index'), 302);
        });
    });

    //Такие же обработчики для cars
})->add($authMiddleware);

$app->run();
