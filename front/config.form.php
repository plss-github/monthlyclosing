<?php

use GlpiPlugin\Monthlyclosing\Config;

// Verifica se o perfil ativo tem acesso à configuração do plugin
if (!Config::canCurrentProfileConfigure()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

if (isset($_POST['update'])) {
    Config::saveConfig($_POST);
    Html::back();
}

Html::header(
    Config::getTypeName(),
    $_SERVER['PHP_SELF'],
    'config',
    Config::class
);

Config::showConfigForm();

Html::footer();
