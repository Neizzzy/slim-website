<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Car;
use App\CarRepository;
use App\Validator\CarValidator;
use App\Validator\UserValidator;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Routing\RouteCollectorProxy;
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

$container->set(\PDO::class, function () {
    $conn = new \PDO('sqlite:database.sqlite');
    $conn->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    return $conn;
});

$initFilePath = implode('/', [dirname(__DIR__), 'init.sql']);
$initSql = file_get_contents($initFilePath);
$container->get(\PDO::class)->exec($initSql);

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

$app->group('/users', function (RouteCollectorProxy $users) use ($router) {
    $users->get('', function ($request, $response) {
        $messages = $this->get('flash')->getMessages();
        $term = $request->getQueryParam('term', '');
        $users = getUsers($request) ?? [];

        $userResult = searchUsersByName($users, $term);

        $params = ['users' => $userResult, 'term' => $term, 'flash' => $messages];
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

    $users->post('', function ($request, $response) use ($router) {
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
    })->setName('users.store');

    $users->get('/{id}', function ($request, $response, array $args) {
        $id = $args['id'];
        $user = getUsers($request)[$id];
        if (!$user) {
            return $response->write('User not found!')->withStatus(404);
        }

        $params = ['user' => $user];
        return $this->get('renderer')->render($response, 'users/show.phtml', $params);
    })->setName('users.show');

    $users->get('/{id}/edit', function ($request, $response, array $args) {
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
    })->setName('users.edit');

    $users->patch('/{id}', function ($request, $response, array $args) use ($router) {
        $id = $args['id'];
        $userData = $request->getParsedBodyParam('user');

        $users = getUsers($request);
        $user = $users[$id];
        if (!$user) {
            return $response->write('User not found!')->withStatus(404);
        }

        $validator = new UserValidator();
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
    })->setName('users.update');

    $users->get('/{id}/delete', function ($request, $response, array $args) {
        $id = $args['id'];
        $user = getUsers($request)[$id];
        if (!$user) {
            return $response->write('User not found!')->withStatus(404);
        }

        $params = [
            'user' => $user
        ];
        return $this->get('renderer')->render($response, 'users/delete.phtml', $params);
    })->setName('users.delete');

    $users->delete('/{id}', function ($request, $response, array $args) use ($router) {
        $id = $args['id'];
        $users = getUsers($request);
        unset($users[$id]);

        $encodedUsers = json_encode($users);
        $this->get('flash')->addMessage('success', 'User was deleted successfully');

        return $response->withHeader('Set-Cookie', "users={$encodedUsers};path=/")
            ->withRedirect($router->urlFor('users.index'), 302);
    })->setName('users.destroy');
})->add($authMiddleware);

$app->group('/cars', function (RouteCollectorProxy $cars) use ($router) {
    $cars->get('', function ($request, $response) {
        $messages = $this->get('flash')->getMessages();
        $cars = $this->get(CarRepository::class)->all();

        $params = ['cars' => $cars, 'flash' => $messages];
        return $this->get('renderer')->render($response, 'cars/index.phtml', $params);
    })->setName('cars.index');

    $cars->get('/new', function ($request, $response) {
        $params = [
            'car' => [
                'make' => '',
                'model' => ''
            ],
            'errors' => []
        ];
        return $this->get('renderer')->render($response, 'cars/new.phtml', $params);
    })->setName('cars.create');

    $cars->post('', function ($request, $response) use ($router) {
        $carData = $request->getParsedBodyParam('car');
        $validator = new CarValidator();
        $errors = $validator->validate($carData);

        if (count($errors) === 0) {
            $car = Car::fromArray($carData);
            $this->get(CarRepository::class)->save($car);
            $this->get('flash')->addMessage('success', 'Car was added successfully');

            return $response->withRedirect($router->urlFor('cars.index'), 302);
        }

        $params = [
            'car' => $carData,
            'errors' => $errors
        ];
        return $this->get('renderer')->render($response->withStatus(422), 'cars/new.phtml', $params);
    })->setName('cars.store');

    $cars->get('/{id}', function ($request, $response, array $args) {
        $id = $args['id'];
        $car = $this->get(CarRepository::class)->find($id);
        if (!$car) {
            return $response->write('Car not found!')->withStatus(404);
        }

        $params = ['car' => $car];
        return $this->get('renderer')->render($response, 'cars/show.phtml', $params);
    })->setName('cars.show');

    $cars->get('/{id}/edit', function ($request, $response, array $args) {
        $id = $args['id'];
        $car = $this->get(CarRepository::class)->find($id);
        if (!$car) {
            return $response->write('Car not found!')->withStatus(404);
        }

        $params = [
            'car' => $car,
            'errors' => []
        ];
        return $this->get('renderer')->render($response, 'cars/edit.phtml', $params);
    })->setName('cars.edit');

    $cars->patch('/{id}', function ($request, $response, array $args) use ($router) {
        $id = $args['id'];
        $carData = $request->getParsedBodyParam('car');

        $car = $this->get(CarRepository::class)->find($id);
        if (!$car) {
            return $response->write('Car not found!')->withStatus(404);
        }

        $validator = new CarValidator();
        $errors = $validator->validate($carData);

        if (count($errors) === 0) {
            $car->setMake($carData['make']);
            $car->setModel($carData['model']);
            $this->get(CarRepository::class)->save($car);

            $this->get('flash')->addMessage('success', 'Car was updated successfully');

            return $response->withRedirect($router->urlFor('cars.index'), 302);
        }

        $params = [
            'car' => $car,
            'errors' => $errors
        ];
        return $this->get('renderer')->render($response->withStatus(422), 'cars/edit.phtml', $params);
    })->setName('cars.update');

    $cars->get('/{id}/delete', function ($request, $response, array $args) {
        $id = $args['id'];
        $car = $this->get(CarRepository::class)->find($id);
        if (!$car) {
            return $response->write('Car not found!')->withStatus(404);
        }

        $params = [
            'car' => $car
        ];
        return $this->get('renderer')->render($response, 'cars/delete.phtml', $params);
    })->setName('cars.delete');

    $cars->delete('/{id}', function ($request, $response, array $args) use ($router) {
        $id = $args['id'];
        $this->get(CarRepository::class)->delete($id);

        $this->get('flash')->addMessage('success', 'Car was deleted successfully');

        return $response->withRedirect($router->urlFor('cars.index'), 302);
    })->setName('cars.destroy');
})->add($authMiddleware);

$app->run();
