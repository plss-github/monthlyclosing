<?php

use GlpiPlugin\Monthlyclosing\Window;

Session::checkRight(Window::$rightname, READ);

$window = new Window();

if (isset($_POST['add'])) {
    $window->check(-1, CREATE, $_POST);
    $newId = $window->add($_POST);
    if ($newId) {
        Html::redirect(Window::getFormURLWithID($newId));
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $window->check($_POST['id'], UPDATE);
    $window->update($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    $window->check($_POST['id'], DELETE);
    $window->delete($_POST);
    $window->redirectToList();
} elseif (isset($_POST['purge'])) {
    $window->check($_POST['id'], PURGE);
    $window->delete($_POST, true);
    $window->redirectToList();
}

$ID = (int) ($_GET['id'] ?? 0);
if ($ID > 0) {
    $window->check($ID, READ);
} else {
    $window->check(-1, CREATE);
}

Html::header(
    Window::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'management',
    Window::class
);

$window->display(['id' => $ID]);

Html::footer();
