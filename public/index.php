<?php

// Подключение автозагрузки через composer
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use DI\Container;

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->add(new MethodOverrideMiddleware());
$app->addErrorMiddleware(true, true, true);


$users = [];
$dataFile = __DIR__ . '/../data/users_data.json';
if (file_exists($dataFile)) {
    $users = json_decode(file_get_contents($dataFile), true) ?? [];
}

$app->get('/users', function ($request, $response) use ($users, $app) {
    $term = $request->getQueryParam('term');
    if ($term === null) {
        $filteredUsers = $users;
    } else {
        $filteredUsers = array_filter($users, fn($user) => str_contains(strtolower($user['name']), strtolower($term)));
    }

    $params = [
        'users' => $filteredUsers,
        'term' => $term,
        'router' => $app->getRouteCollector()->getRouteParser(),
        'flash' => $this->get('flash')->getMessages()
    ];
    return $this->get('renderer')->render($response, "users/users.phtml", $params);
})->setName('users.index');

$app->get('/users/new', function ($request, $response) use ($app) {
    $params = [
        'user' => ['name' => '', 'email' => ''],
        'errors' => [],
        'router' => $app->getRouteCollector()->getRouteParser()
    ];
    return $this->get('renderer')->render($response, "users/new.phtml", $params);
})->setName('users.new');

$app->get('/users/{id}', function ($request, $response, $args) use ($users, $app) {
    $id = (int)$args['id'];
    $user = array_filter($users, fn($user) => $user['id'] === $id);
    if (empty($user)) {
        return $response->withStatus(404);
    }
    $params = [
        'user' => array_shift($user),
        'errors' => [],
        'router' => $app->getRouteCollector()->getRouteParser()
    ];
    return $this->get('renderer')->render($response, "users/show.phtml", $params);
})->setName('users.id');

$app->post('/users', function ($request, $response) use ($users, $app) {
    $userData = $request->getParsedBodyParam('user');
    $errors = [];

    // Валидация имени
    if (mb_strlen($userData['name'] ?? '') < 4) {
        $errors['name'] = 'Name must be at least 4 characters long';
    }

    // Валидация email
    if (empty($userData['email']) || !filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Email must be valid';
    } else {
        // Проверка уникальности email
        foreach ($users as $existingUser) {
            if (strcasecmp($existingUser['email'], $userData['email']) === 0) {
                $errors['email'] = 'Email must be unique';
                break;
            }
        }
    }

    if (empty($errors)) {
        // Находим максимальный ID
        $maxId = 0;
        foreach ($users as $existingUser) {
            if ($existingUser['id'] > $maxId) {
                $maxId = $existingUser['id'];
            }
        }

        $newUser = [
            'id' => $maxId + 1,
            'name' => $userData['name'],
            'email' => $userData['email']
        ];
        $users[] = $newUser;
        file_put_contents(__DIR__ . '/../data/users_data.json', json_encode($users, JSON_PRETTY_PRINT));

        $this->get('flash')->addMessage('success', 'Пользователь был успешно создан!');
        return $response->withRedirect('/users', 302);
    }

    $params = [
        'user' => $userData,
        'errors' => $errors,
        'router' => $app->getRouteCollector()->getRouteParser()
    ];
    return $this->get('renderer')->render($response->withStatus(422), "users/new.phtml", $params);
})->setName('users.create');

$app->get('/users/{id}/edit', function ($request, $response, $args) use ($users, $app) {
    $id = (int)$args['id'];
    $user = array_filter($users, fn($user) => $user['id'] === $id);
    if (empty($user)) {
        return $response->withStatus(404);
    }
    $params = [
        'user' => array_shift($user),
        'errors' => [],
        'router' => $app->getRouteCollector()->getRouteParser()
    ];
    return $this->get('renderer')->render($response, "users/edit.phtml", $params);
})->setName('users.edit');

// ИСПРАВЛЕННЫЙ PATCH-ОБРАБОТЧИК - правильный порядок аргументов!
$app->patch('/users/{id}', function ($request, $response, $args) use ($users, $app) {
    $id = (int)$args['id'];
    $userData = $request->getParsedBodyParam('user');
    $errors = [];
    $userIndex = null;

    // Находим пользователя
    foreach ($users as $index => $existingUser) {
        if ($existingUser['id'] === $id) {
            $userIndex = $index;
            break;
        }
    }

    if ($userIndex === null) {
        return $response->withStatus(404);
    }

    // Валидация имени
    if (mb_strlen($userData['name'] ?? '') < 4) {
        $errors['name'] = 'Name must be at least 4 characters long';
    }

    // Валидация email
    if (empty($userData['email']) || !filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Email must be valid';
    } else {
        // Проверка уникальности email (исключая текущего пользователя)
        foreach ($users as $existingUser) {
            if ($existingUser['id'] !== $id && strcasecmp($existingUser['email'], $userData['email']) === 0) {
                $errors['email'] = 'Email must be unique';
                break;
            }
        }
    }

    if (empty($errors)) {
        $users[$userIndex]['name'] = $userData['name'];
        $users[$userIndex]['email'] = $userData['email'];
        file_put_contents(__DIR__ . '/../data/users_data.json', json_encode($users, JSON_PRETTY_PRINT));

        $this->get('flash')->addMessage('success', 'Пользователь был успешно обновлен!');
        return $response->withRedirect('/users', 302);
    }

    $params = [
        'user' => array_merge(['id' => $id], $userData),
        'errors' => $errors,
        'router' => $app->getRouteCollector()->getRouteParser()
    ];
    return $this->get('renderer')->render($response->withStatus(422), "users/edit.phtml", $params);
})->setName('users.update');

$app->delete('/users/{id}', function ($request, $response, $args) use ($users, $app) {
    $id = (int)$args['id'];
    $userIndex = null;

    // Находим пользователя
    foreach ($users as $index => $existingUser) {
        if ($existingUser['id'] === $id) {
            $userIndex = $index;
            break;
        }
    }

    if ($userIndex === null) {
        return $response->withStatus(404);
    }

    array_splice($users, $userIndex, 1);
    file_put_contents(__DIR__ . '/../data/users_data.json', json_encode($users, JSON_PRETTY_PRINT));

    $this->get('flash')->addMessage('success', 'Пользователь был успешно удален!');
    return $response->withRedirect('/users', 302);
})->setName('users.delete');

$app->run();