<?php

use GlpiPlugin\Monthlyclosing\Window;

Session::checkRight(Window::$rightname, READ);

$window = new Window();

if (isset($_POST['delete'])) {
    $window->check($_POST['id'], DELETE);
    $window->delete($_POST);
    $window->redirectToList();
} elseif (isset($_POST['restore'])) {
    $window->check($_POST['id'], DELETE);
    $window->restore($_POST);
    $window->redirectToList();
} elseif (isset($_POST['purge'])) {
    $window->check($_POST['id'], PURGE);
    $window->delete($_POST, true);
    $window->redirectToList();
}

Html::header(
    Window::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'management',
    Window::class
);

Search::show(Window::class);

Html::footer();
